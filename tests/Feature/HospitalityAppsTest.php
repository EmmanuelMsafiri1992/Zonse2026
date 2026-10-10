<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class HospitalityAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_hotel_prices_stays_prevents_double_bookings_and_turns_rooms_round(): void
    {
        $app = 'hotel';
        [$owner, $workspace] = $this->appWorkspace($app);

        $room = $this->record($workspace, $app, 'rooms', '101', 'vacant_clean', ['type' => 'double', 'rate' => 80, 'max_guests' => 2]);
        $stay = fn (string $guest, int $from, int $to, array $data = [], string $status = 'confirmed') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'reservations']), [
            'title' => $guest, 'status' => $status, 'occurs_on' => today()->addDays($from)->toDateString(), 'due_on' => today()->addDays($to)->toDateString(), 'data' => ['room' => $room->id, 'guests' => 2, ...$data],
        ]);

        $stay('Banda', 0, 0)->assertSessionHasErrors('due_on');
        $stay('Banda', 0, 2, ['guests' => 3])->assertSessionHasErrors('data.guests');
        $stay('Banda', 0, 3, ['deposit' => 50])->assertSessionHasNoErrors();
        $banda = Record::query()->where('entity', 'reservations')->firstOrFail();
        $this->assertEquals(240, $banda->amount);
        $this->assertSame(190.0, (float) $banda->value('_balance'));
        $stay('Phiri', 2, 4)->assertSessionHasErrors('data.room');
        $stay('Phiri', 3, 5)->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Front desk today')->assertSee('Banda');
        $this->actingAs($owner)->post($banda->url().'/actions/check_in')->assertSessionHas('flash.message', 'Banda checked into 101 until '.today()->addDays(3)->format('d M Y').'.');
        $this->assertSame('occupied', $room->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'rooms', $room->id]), ['title' => '101', 'status' => 'out_of_order', 'data' => ['type' => 'double', 'rate' => 80]])->assertSessionHasErrors('status');

        $this->actingAs($owner)->post($banda->url().'/actions/check_out')->assertSessionHas('flash.message', 'Banda checked out after 1 night(s); '.$this->money(30).' to settle. Checkout clean booked.');
        $this->assertSame('vacant_dirty', $room->fresh()->status);
        $clean = Record::query()->where('entity', 'housekeeping')->firstOrFail();
        $this->assertSame('checkout_clean', $clean->value('type'));
        $this->actingAs($owner)->post($clean->url().'/actions/finish')->assertSessionHas('flash.message', 'Checkout clean · 101 done; the room is ready.');
        $this->assertSame('vacant_clean', $room->fresh()->status);

        $late = $this->record($workspace, $app, 'reservations', 'Late guest', 'confirmed', ['guests' => 1], ['occurs_on' => today()->subDays(2), 'due_on' => today()->addDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('no_show', $late->fresh()->status);

        $roomGone = $this->record($workspace, $app, 'reservations', 'Room gone', 'enquiry', ['guests' => 1, 'room' => 999999], ['occurs_on' => today()->addDays(3), 'due_on' => today()->addDays(4)]);
        $this->actingAs($owner)->post($roomGone->url().'/actions/confirm')->assertSessionHasErrors(['room' => 'The room on this reservation no longer exists; edit it to choose another.']);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Occupancy by month')->assertSee('Cancellations and no-shows')->assertSee('Late guest');
    }

    public function test_restaurant_prices_orders_from_the_menu_and_tracks_tables_through_service(): void
    {
        $app = 'restaurant';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->record($workspace, $app, 'menu', 'Beef burger', 'available', ['category' => 'main', 'price' => 12]);
        $this->record($workspace, $app, 'menu', 'Coke', 'available', ['category' => 'drink', 'price' => 2.5]);
        $this->record($workspace, $app, 'menu', 'Cheesecake', 'sold_out', ['category' => 'dessert', 'price' => 6]);
        $table = $this->record($workspace, $app, 'tables', 'T1', 'free', ['seats' => 4]);

        $order = fn (string $items, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), [
            'title' => 'Order', 'status' => 'open', 'data' => ['type' => 'dine_in', 'table' => $table->id, 'items' => $items, ...$data],
        ]);
        $order('2 x Beef burger', ['table' => null])->assertSessionHasErrors('data.table');
        $order("1 x Cheesecake\nPizza")->assertSessionHasErrors('data.items');
        $order("2 x Beef burger\n3 Coke")->assertSessionHasNoErrors();
        $ticket = Record::query()->where('entity', 'orders')->firstOrFail();
        $this->assertEquals(31.5, $ticket->amount);
        $this->assertSame('occupied', $table->fresh()->status);

        $this->actingAs($owner)->post($ticket->url().'/actions/send')->assertSessionHas('flash.message', $ticket->number.' sent to the kitchen: 2 x Beef burger, 3 x Coke.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Kitchen queue')->assertSee('2 x Beef burger');
        $this->actingAs($owner)->post($ticket->url().'/actions/ready')->assertSessionHas('flash.message', $ticket->number.' is ready after 0 min.');
        $this->actingAs($owner)->post($ticket->url().'/actions/serve');
        $this->actingAs($owner)->post($ticket->url().'/actions/pay', [])->assertSessionHasErrors('method');
        $this->actingAs($owner)->post($ticket->url().'/actions/pay', ['method' => 'card', 'tip' => 3])->assertSessionHas('flash.message', $ticket->number.' paid: '.$this->money(31.5).' by card plus '.$this->money(3).' tip.');
        $this->assertSame('dirty', $table->fresh()->status);
        $this->actingAs($owner)->post($table->url().'/actions/clear')->assertSessionHas('flash.message', 'T1 is free.');

        $booking = fn (string $time, int $party) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'reservations']), [
            'title' => 'Mwale', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['time' => $time, 'party_size' => $party, 'table' => $table->id],
        ]);
        $booking('19:00', 6)->assertSessionHasErrors('data.party_size');
        $booking('19:00', 4)->assertSessionHasNoErrors();
        $booking('20:00', 2)->assertSessionHasErrors('data.time');
        $booking('21:00', 2)->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Dishes sold')->assertSee('Beef burger');
    }

    public function test_food_delivery_times_each_step_and_holds_riders_to_the_cash_they_collect(): void
    {
        $app = 'food-delivery';
        [$owner, $workspace] = $this->appWorkspace($app);
        $rider = $this->memberOf($workspace, 'member');

        $order = $this->record($workspace, $app, 'orders', 'Tamara', 'received', ['items' => '2 pizzas', 'address' => 'Area 47', 'phone' => '0999', 'delivery_fee' => 5, 'payment' => 'cash_on_delivery'], ['amount' => 40]);
        $this->assertSame(45.0, (float) $order->value('_to_collect'));

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'orders', $order->id]), [
            'title' => 'Tamara', 'status' => 'out_for_delivery', 'amount' => 40, 'data' => ['items' => '2 pizzas', 'address' => 'Area 47', 'phone' => '0999'],
        ])->assertSessionHasErrors('data.rider');

        $this->actingAs($owner)->post($order->url().'/actions/prepare');
        $this->actingAs($owner)->post($order->url().'/actions/ready')->assertSessionHas('flash.message', $order->number.' is ready after 0 min.');
        $this->actingAs($owner)->post($order->url().'/actions/dispatch', ['rider' => $rider->id])->assertSessionHas('flash.message', $order->number.' is out with '.$rider->name.'; collect '.$this->money(45).'.');

        $second = $this->record($workspace, $app, 'orders', 'Joseph', 'ready', ['items' => 'Chips', 'address' => 'Area 10', 'phone' => '0888', 'payment' => 'paid_online'], ['amount' => 8]);
        $this->actingAs($owner)->post($second->url().'/actions/dispatch', ['rider' => $rider->id])->assertSessionHasErrors('rider');

        $this->actingAs($owner)->post($order->url().'/actions/deliver', ['collected' => 40])->assertSessionHasErrors('collected');
        $this->actingAs($owner)->post($order->url().'/actions/deliver', ['collected' => 45])->assertSessionHas('flash.message', $order->number.' delivered in 0 min.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Cash with riders')->assertSee($rider->name);
        $this->actingAs($owner)->post($order->url().'/actions/bank')->assertSessionHas('flash.message', $this->money(45).' for '.$order->number.' handed in.');

        $this->actingAs($owner)->post($second->url().'/actions/dispatch', ['rider' => $rider->id])->assertSessionHas('flash.message', $second->number.' is out with '.$rider->name.'; already paid.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'orders', $second->id]), [
            'title' => 'Joseph', 'status' => 'preparing', 'amount' => 8, 'data' => ['items' => 'Chips', 'address' => 'Area 10', 'phone' => '0888', 'rider' => $rider->id],
        ])->assertSessionHasErrors('status');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Riders')->assertSee($rider->name);
    }

    public function test_tours_fill_departures_up_to_the_group_size_and_cancel_bookings_with_the_departure(): void
    {
        $app = 'tours';
        [$owner, $workspace] = $this->appWorkspace($app);

        $tour = $this->record($workspace, $app, 'tours', 'Lake Malawi weekend', 'active', ['itinerary' => 'Day 1…', 'price_per_person' => 150, 'max_group' => 4]);
        $departure = $this->record($workspace, $app, 'departures', 'Lake 1', 'scheduled', ['tour' => $tour->id, 'guide' => $owner->id], ['occurs_on' => today()->addDays(5), 'due_on' => today()->addDays(7)]);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'departures']), [
            'title' => 'Lake 2', 'status' => 'scheduled', 'occurs_on' => today()->addDays(6)->toDateString(), 'due_on' => today()->addDays(8)->toDateString(), 'data' => ['tour' => $tour->id, 'guide' => $owner->id],
        ])->assertSessionHasErrors('data.guide');

        $book = fn (string $lead, int $travellers) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => $lead, 'status' => 'booked', 'data' => ['departure' => $departure->id, 'travellers' => $travellers],
        ]);
        $book('Kumwenda', 3)->assertSessionHasNoErrors();
        $this->assertEquals(450, Record::query()->where('entity', 'bookings')->firstOrFail()->amount);
        $this->assertSame(3, $departure->fresh()->value('seats_booked'));
        $book('Gondwe', 2)->assertSessionHasErrors('data.travellers');
        $book('Gondwe', 1)->assertSessionHasNoErrors();
        $this->assertSame(0, $departure->fresh()->value('_seats_left'));

        $this->actingAs($owner)->get($departure->fresh()->url())->assertOk()->assertSee('Manifest')->assertSee('Kumwenda');
        $this->actingAs($owner)->post($departure->url().'/actions/cancel')->assertSessionHas('flash.message', 'Lake 1 cancelled; 2 booking(s) cancelled.');
        $this->assertSame(0, Record::query()->where('entity', 'bookings')->where('status', '!=', 'cancelled')->count());
        $this->assertSame(0, $departure->fresh()->value('seats_booked'));

        $soon = $this->record($workspace, $app, 'departures', 'Lake 3', 'scheduled', ['tour' => $tour->id], ['occurs_on' => today(), 'due_on' => today()->addDay()]);
        $this->actingAs($owner)->post($soon->url().'/actions/confirm')->assertSessionHasErrors('status');
        $this->record($workspace, $app, 'bookings', 'Nyirenda', 'paid', ['departure' => $soon->id, 'travellers' => 2]);
        $this->actingAs($owner)->post($soon->url().'/actions/confirm')->assertSessionHas('flash.message', 'Lake 3 confirmed with 2 traveller(s).');
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('running', $soon->fresh()->status);
        $this->travel(2)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('completed', $soon->fresh()->status);
        $this->travelBack();

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Departures by tour')->assertSee('Lake Malawi weekend');
    }

    public function test_car_rental_blocks_overlaps_and_charges_late_days_and_fuel_on_return(): void
    {
        $app = 'car-rental';
        [$owner, $workspace] = $this->appWorkspace($app);

        $car = $this->record($workspace, $app, 'cars', 'Toyota Corolla', 'available', ['registration' => 'BT 1234', 'category' => 'sedan', 'daily_rate' => 50, 'mileage' => 10000]);
        $rent = fn (string $customer, int $from, int $to) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rentals']), [
            'title' => $customer, 'status' => 'reserved', 'occurs_on' => today()->addDays($from)->toDateString(), 'due_on' => today()->addDays($to)->toDateString(), 'data' => ['car' => $car->id, 'licence_number' => 'L1'],
        ]);

        $rent('Chirwa', 0, 3)->assertSessionHasNoErrors();
        $rental = Record::query()->where('entity', 'rentals')->firstOrFail();
        $this->assertEquals(150, $rental->amount);
        $this->assertSame('reserved', $car->fresh()->status);
        $rent('Mbewe', 2, 4)->assertSessionHasErrors('data.car');

        $this->actingAs($owner)->post($rental->url().'/actions/hand_over', ['mileage_out' => 9000, 'fuel_out' => 'full'])->assertSessionHasErrors('mileage_out');
        $this->actingAs($owner)->post($rental->url().'/actions/hand_over', ['mileage_out' => 10000, 'fuel_out' => 'full'])
            ->assertSessionHas('flash.message', 'Toyota Corolla handed to Chirwa at 10000 km; due back '.today()->addDays(3)->format('d M Y').'.');
        $this->assertSame('rented', $car->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'cars', $car->id]), ['title' => 'Toyota Corolla', 'status' => 'maintenance', 'data' => ['registration' => 'BT 1234', 'category' => 'sedan']])->assertSessionHasErrors('status');

        $this->travel(5)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertTrue($rental->fresh()->value('_overdue'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due back')->assertSee('Chirwa');
        $this->actingAs($owner)->post($rental->url().'/actions/return', ['mileage_in' => 9999, 'fuel_in' => 'full'])->assertSessionHasErrors('mileage_in');
        $this->actingAs($owner)->post($rental->url().'/actions/return', ['mileage_in' => 10640, 'fuel_in' => '1_2', 'damage_notes' => 'Scratch on bumper'])
            ->assertSessionHas('flash.message', 'Toyota Corolla returned after 640 km, 2 day(s) late, 2 quarter tank(s) short; total '.$this->money(300).'.');
        $car = $car->fresh();
        $this->assertSame(10640, (int) $car->value('mileage'));
        $this->assertSame('available', $car->status);

        $this->actingAs($owner)->post($car->url().'/actions/service')->assertSessionHas('flash.message', 'Toyota Corolla sent for service.');
        $this->actingAs($owner)->post($car->url().'/actions/serviced', ['mileage' => 10000])->assertSessionHasErrors('mileage');
        $this->actingAs($owner)->post($car->url().'/actions/serviced', ['mileage' => 10650])->assertSessionHas('flash.message', 'Toyota Corolla is back from service.');
        $this->assertSame('available', $car->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Utilisation by car')->assertSee('Scratch on bumper');
        $this->travelBack();
    }

    public function test_bus_booking_sells_each_seat_once_and_closes_boarding_on_departure(): void
    {
        $app = 'bus-booking';
        [$owner, $workspace] = $this->appWorkspace($app);

        $trip = $this->record($workspace, $app, 'trips', 'Blantyre – Lilongwe', 'scheduled', ['bus' => 'MZ 4411', 'departure_time' => '07:00', 'seats' => 3, 'fare' => 20], ['occurs_on' => today()]);
        $sell = fn (string $passenger, string $seat) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tickets']), [
            'title' => $passenger, 'status' => 'booked', 'data' => ['trip' => $trip->id, 'seat_number' => $seat],
        ]);

        $sell('Alinafe', '4')->assertSessionHasErrors('data.seat_number');
        $sell('Alinafe', '1')->assertSessionHasNoErrors();
        $sell('Bright', '1')->assertSessionHasErrors('data.seat_number');
        $sell('Bright', '2')->assertSessionHasNoErrors();
        $sell('Chisomo', '3')->assertSessionHasNoErrors();
        $this->assertEquals(20, Record::query()->where('entity', 'tickets')->firstOrFail()->amount);
        $this->assertSame(3, $trip->fresh()->value('seats_sold'));

        [$alinafe, $bright] = Record::query()->where('entity', 'tickets')->orderBy('id')->get()->all();
        $this->actingAs($owner)->post($alinafe->url().'/actions/pay')->assertSessionHas('flash.message', 'Seat 1 on Blantyre – Lilongwe paid: '.$this->money(20).'.');
        $this->actingAs($owner)->post($alinafe->url().'/actions/board')->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($bright->url().'/actions/pay');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee("Today's trips")->assertSee('3/3');
        $this->actingAs($owner)->post($trip->url().'/actions/board');
        $this->actingAs($owner)->post($alinafe->url().'/actions/board')->assertSessionHas('flash.message', 'Alinafe boarded, seat 1.');
        $this->actingAs($owner)->post($trip->url().'/actions/depart')->assertSessionHas('flash.message', 'Blantyre – Lilongwe departed with 1 on board; 1 no-show(s), 1 unpaid booking(s) released.');
        $this->assertSame('no_show', $bright->fresh()->status);
        $this->assertEquals(20, $trip->fresh()->value('_takings'));
        $sell('Dalitso', '3')->assertSessionHasErrors('data.trip');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Load by route')->assertSee('50%');
    }

    public function test_bar_totals_tabs_from_their_rounds_and_charges_the_vip_minimum_spend(): void
    {
        $app = 'bar';
        [$owner, $workspace] = $this->appWorkspace($app);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tabs']), [
            'title' => 'Table 4', 'status' => 'open', 'data' => ['items' => '2 x Castle'],
        ])->assertSessionHasErrors('data.items');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tabs']), [
            'title' => 'Table 4', 'status' => 'open', 'data' => ['items' => '2 x Castle @ 25', 'bartender' => $owner->id],
        ])->assertSessionHasNoErrors();
        $tab = Record::query()->where('entity', 'tabs')->firstOrFail();
        $this->assertEquals(50, $tab->amount);

        $this->actingAs($owner)->post($tab->url().'/actions/round', ['round' => 'Gin & tonic'])->assertSessionHasErrors('round');
        $this->actingAs($owner)->post($tab->url().'/actions/round', ['round' => "3 x Gin & tonic @ 40\nChips @ 15"])->assertSessionHas('flash.message', 'Round of 4 added; the tab is '.$this->money(185).'.');
        $this->actingAs($owner)->post($tab->url().'/actions/close', [])->assertSessionHasErrors('payment');
        $this->actingAs($owner)->post($tab->url().'/actions/close', ['payment' => 'card'])->assertSessionHas('flash.message', 'Table 4 closed: '.$this->money(185).' by card.');

        $runner = $this->record($workspace, $app, 'tabs', 'Bar stool 2', 'open', ['items' => '4 x Castle @ 25']);
        $this->actingAs($owner)->post($runner->url().'/actions/walk_out')->assertSessionHas('flash.message', 'Bar stool 2 left '.$this->money(100).' unpaid.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Unpaid tabs')->assertSee('Bar stool 2');

        $vip = $this->record($workspace, $app, 'bookings', 'Kalua party', 'booked', ['section' => 'VIP booth', 'party_size' => 8, 'minimum_spend' => 1000], ['occurs_on' => today(), 'amount' => 300]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Other party', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['section' => 'vip booth'],
        ])->assertSessionHasErrors('data.section');
        $this->actingAs($owner)->post($vip->url().'/actions/arrive');
        $this->actingAs($owner)->post($vip->url().'/actions/settle', ['spent' => 800])
            ->assertSessionHas('flash.message', 'Kalua party spent '.$this->money(800).', '.$this->money(200).' short of the minimum; '.$this->money(700).' to pay after the deposit.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Best sellers')->assertSee('Gin &amp; tonic', false)->assertSee('VIP minimum spend');
    }

    private function money(float|int $amount): string
    {
        return Money::format($amount);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $attributes
     */
    private function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create([
            'workspace_id' => $workspace->id,
            'title' => $title,
            'status' => $status,
            ...$attributes,
        ]);
    }
}
