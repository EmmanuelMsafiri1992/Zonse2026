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

class HospitalityAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_catering_prices_menus_per_head_and_plans_the_kitchen(): void
    {
        $app = 'catering-event-food-orders';
        [$owner, $workspace] = $this->appWorkspace($app);
        $date = today()->addDays(10);
        $job = fn (array $data, string $status = 'enquiry') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'events']), [
            'title' => 'Banda wedding', 'status' => $status, 'occurs_on' => $date->toDateString(), 'data' => ['guests' => 100, 'service' => 'buffet', ...$data],
        ]);

        $job(['menu' => 'Beef @ abc'])->assertSessionHasErrors('data.menu');
        $job(['menu' => "Chicken stew @ 45\nRice @ 10\nSalad"])->assertSessionHasNoErrors();
        $wedding = Record::query()->where('entity', 'events')->firstOrFail();
        $this->assertEquals(5500, $wedding->amount);
        $this->assertSame(4, (int) $wedding->value('staff_needed'));
        $this->assertSame(105, (int) $wedding->value('_portions'));

        $this->actingAs($owner)->post($wedding->url().'/actions/quote')->assertSessionHas('flash.message', 'Quote for Banda wedding sent: '.$this->money(5500).' for 100 guests.');
        $update = fn (array $data, string $status) => $this->actingAs($owner)->put(route('apps.records.update', [$app, 'events', $wedding->id]), [
            'title' => 'Banda wedding', 'status' => $status, 'occurs_on' => $date->toDateString(), 'amount' => $wedding->fresh()->amount,
            'data' => ['guests' => 120, 'service' => 'buffet', 'menu' => "Chicken stew @ 45\nRice @ 10\nSalad", 'staff_needed' => 6, ...$data],
        ]);
        $update([], 'enquiry')->assertSessionHasErrors('status');
        $update([], 'quoted')->assertSessionHasNoErrors();
        $this->assertEquals(6600, $wedding->fresh()->amount);

        $this->actingAs($owner)->post($wedding->url().'/actions/confirm')->assertSessionHas('flash.message', 'Banda wedding confirmed for '.$date->format('d M Y').': cook 126 portions, 6 staff.');
        $this->actingAs($owner)->get($wedding->url())->assertOk()->assertSee('Production sheet')->assertSee('Chicken stew');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Coming up')->assertSee('Banda wedding');

        $stale = $this->record($workspace, $app, 'events', 'Old enquiry', 'quoted', ['guests' => 20, 'menu' => 'Samosas'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('cancelled', $stale->fresh()->status);
        $this->assertSame('confirmed', $wedding->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by month')->assertSee('Quotes won and lost');
    }

    public function test_venues_are_never_double_booked_and_bookings_are_confirmed_with_a_deposit(): void
    {
        $app = 'venue-hire';
        [$owner, $workspace] = $this->appWorkspace($app);
        $hall = $this->record($workspace, $app, 'spaces', 'Main hall', 'available', ['capacity' => 100, 'day_rate' => 2000]);
        $garden = $this->record($workspace, $app, 'spaces', 'Garden', 'maintenance', ['capacity' => 50]);
        $date = today()->addDays(5);
        $book = fn (string $title, array $data, ?string $on = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => $title, 'status' => 'provisional', 'occurs_on' => $on ?? $date->toDateString(), 'data' => ['space' => $hall->id, 'guests' => 80, ...$data],
        ]);

        $book('Phiri wedding', ['guests' => 150])->assertSessionHasErrors('data.guests');
        $book('Phiri wedding', ['start_time' => '10:00', 'end_time' => '16:00'])->assertSessionHasNoErrors();
        $wedding = Record::query()->where('entity', 'bookings')->firstOrFail();
        $this->assertEquals(2000, $wedding->amount);
        $book('Choir dinner', ['start_time' => '15:00', 'end_time' => '20:00'])->assertSessionHasErrors('data.space');
        $book('Choir dinner', ['start_time' => '16:00', 'end_time' => '22:00'])->assertSessionHasNoErrors();
        $book('Gala', [])->assertSessionHasErrors('data.space');
        $book('Gala', ['start_time' => '18:00', 'end_time' => '17:00'], today()->addDays(6)->toDateString())->assertSessionHasErrors('data.end_time');
        $book('Gala', ['space' => $garden->id], today()->addDays(6)->toDateString())->assertSessionHasErrors('data.space');

        $this->actingAs($owner)->post($wedding->url().'/actions/confirm', ['deposit' => 500])->assertSessionHas('flash.message', 'Phiri wedding confirmed with a '.$this->money(500).' deposit; '.$this->money(1500).' to pay.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'spaces', $hall->id]), ['title' => 'Main hall', 'status' => 'maintenance', 'data' => ['capacity' => 100, 'day_rate' => 2000]])->assertSessionHasErrors('status');
        $this->actingAs($owner)->get($hall->url())->assertOk()->assertSee('Diary')->assertSee('Choir dinner');

        $held = $this->record($workspace, $app, 'bookings', 'Old hold', 'provisional', ['space' => $hall->id], ['occurs_on' => today()->subDay()]);
        $done = $this->record($workspace, $app, 'bookings', 'Old party', 'confirmed', ['space' => $hall->id], ['occurs_on' => today()->subDays(2)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('cancelled', $held->fresh()->status);
        $this->assertSame('completed', $done->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Coming up')->assertSee('Phiri wedding');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Use by space')->assertSee('Main hall');
    }

    public function test_travel_agency_tracks_tickets_against_deadlines_and_visas_through_the_embassy(): void
    {
        $app = 'travel-agency';
        [$owner, $workspace] = $this->appWorkspace($app);
        $booking = fn (array $data, string $status = 'quoted', array $fields = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => 'Mwale', 'status' => $status, 'occurs_on' => today()->addDays(20)->toDateString(), 'due_on' => today()->addDays(2)->toDateString(), 'amount' => 1200,
            'data' => ['type' => 'flight', 'route' => 'LLW-JNB', 'supplier' => 'Airlink', 'commission' => 90, ...$data], ...$fields,
        ]);

        $booking([], 'booked')->assertSessionHasErrors('data.pnr');
        $booking(['commission' => 1500])->assertSessionHasErrors('data.commission');
        $booking([], 'quoted', ['due_on' => today()->addDays(30)->toDateString()])->assertSessionHasErrors('due_on');
        $booking([])->assertSessionHasNoErrors();
        $mwale = Record::query()->where('entity', 'bookings')->firstOrFail();
        $this->assertEquals(7.5, $mwale->value('_margin'));

        $this->actingAs($owner)->post($mwale->url().'/actions/book')->assertSessionHasErrors('pnr');
        $this->actingAs($owner)->post($mwale->url().'/actions/book', ['pnr' => 'ABC123'])->assertSessionHas('flash.message', 'Mwale booked (ABC123); ticket by '.today()->addDays(2)->format('d M Y').'.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Tickets to issue')->assertSee('ABC123');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'bookings', $mwale->id]), [
            'title' => 'Mwale', 'status' => 'quoted', 'occurs_on' => today()->addDays(20)->toDateString(), 'amount' => 1200, 'data' => ['type' => 'flight', 'route' => 'LLW-JNB', 'pnr' => 'ABC123'],
        ])->assertSessionHasErrors('status');

        $missed = $this->record($workspace, $app, 'bookings', 'Late payer', 'booked', ['type' => 'hotel', 'route' => 'Cape Town'], ['due_on' => today()->subDay(), 'occurs_on' => today()->addDays(5), 'amount' => 400]);
        $gone = $this->record($workspace, $app, 'bookings', 'Traveller', 'ticketed', ['type' => 'flight', 'route' => 'LLW-DXB', 'pnr' => 'XYZ'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('cancelled', $missed->fresh()->status);
        $this->assertSame('Ticketing deadline missed', $missed->fresh()->value('_cancel_reason'));
        $this->assertSame('travelled', $gone->fresh()->status);

        $this->actingAs($owner)->post($missed->url().'/actions/refund', ['refund' => 500])->assertSessionHasErrors('refund');
        $this->actingAs($owner)->post($missed->url().'/actions/refund', ['refund' => 350])->assertSessionHas('flash.message', $this->money(350).' refunded to Late payer.');

        $visa = fn (string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'visas']), [
            'title' => 'Mwale', 'status' => $status, 'due_on' => today()->addDays(7)->toDateString(), 'data' => ['country' => 'South Africa', ...$data],
        ]);
        $visa('submitted')->assertSessionHasErrors('data.passport_number');
        $visa('documents_pending', ['passport_number' => 'MA12345'])->assertSessionHasNoErrors();
        $application = Record::query()->where('entity', 'visas')->firstOrFail();
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Visas undecided before travel')->assertSee('South Africa');

        $this->actingAs($owner)->post($application->url().'/actions/submit', ['embassy_reference' => 'EMB-9'])->assertSessionHas('flash.message', "Mwale's South Africa visa submitted.");
        $this->assertSame('EMB-9', $application->fresh()->value('embassy_reference'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'visas', $application->id]), [
            'title' => 'Mwale', 'status' => 'collected', 'due_on' => today()->addDays(7)->toDateString(), 'data' => ['country' => 'South Africa', 'passport_number' => 'MA12345'],
        ])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($application->url().'/actions/approve')->assertSessionHas('flash.message', "Mwale's South Africa visa approved.");

        $this->actingAs($owner)->get(route('apps.reports', [$app, 'to' => today()->addMonths(2)->toDateString()]))->assertOk()->assertSee('Commission by supplier')->assertSee('Airlink')->assertSee('Visa outcomes');
    }

    public function test_safari_bookings_respect_capacity_by_slot_and_by_night_and_need_an_indemnity(): void
    {
        $app = 'safari-camping-activity-bookings';
        [$owner, $workspace] = $this->appWorkspace($app);
        $drive = $this->record($workspace, $app, 'activities', 'Morning game drive', 'available', ['type' => 'game_drive', 'capacity' => 6, 'price_per_person' => 50]);
        $camp = $this->record($workspace, $app, 'activities', 'Riverside camp', 'available', ['type' => 'campsite', 'capacity' => 10, 'price_per_person' => 20]);
        $rafting = $this->record($workspace, $app, 'activities', 'Rafting', 'closed', ['type' => 'rafting', 'capacity' => 8]);
        $book = fn (string $guest, Record $activity, int $guests, int $from, ?int $to = null, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), [
            'title' => $guest, 'status' => 'booked', 'occurs_on' => today()->addDays($from)->toDateString(), 'due_on' => $to === null ? null : today()->addDays($to)->toDateString(),
            'data' => ['activity' => $activity->id, 'guests' => $guests, ...$data],
        ]);

        $book('Banda', $drive, 4, 3, null, ['start_time' => '06:00'])->assertSessionHasNoErrors();
        $banda = Record::query()->where('entity', 'bookings')->firstOrFail();
        $this->assertEquals(200, $banda->amount);
        $book('Phiri', $drive, 3, 3, null, ['start_time' => '06:00'])->assertSessionHasErrors('data.guests');
        $book('Phiri', $drive, 3, 3, null, ['start_time' => '15:00'])->assertSessionHasNoErrors();

        $book('Tembo', $camp, 6, 3, 6)->assertSessionHasNoErrors();
        $tembo = Record::query()->where('title', 'Tembo')->firstOrFail();
        $this->assertEquals(360, $tembo->amount);
        $this->assertSame(3, (int) $tembo->value('_nights'));
        $book('Zulu', $camp, 5, 5, 7)->assertSessionHasErrors('data.guests');
        $book('Zulu', $camp, 5, 6, 8)->assertSessionHasNoErrors();
        $book('Moyo', $rafting, 2, 3)->assertSessionHasErrors('data.activity');
        $book('Moyo', $camp, 1, 3, 2)->assertSessionHasErrors('due_on');

        $this->actingAs($owner)->post($banda->url().'/actions/pay')->assertSessionHas('flash.message', 'Banda paid '.$this->money(200).'.');
        $this->actingAs($owner)->post($banda->url().'/actions/complete')->assertSessionHasErrors('indemnity_signed');
        $this->actingAs($owner)->post($banda->url().'/actions/indemnity')->assertSessionHas('flash.message', 'Indemnity signed for Banda.');
        $this->actingAs($owner)->post($banda->url().'/actions/complete')->assertSessionHas('flash.message', "Banda's booking completed.");

        $today = $this->record($workspace, $app, 'bookings', 'Walk-in', 'booked', ['activity' => $drive->id, 'guests' => 2], ['occurs_on' => today()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today&#039;s guests', false)->assertSee('Walk-in')->assertSee('Indemnity missing');

        $paid = $this->record($workspace, $app, 'bookings', 'Old paid', 'paid', ['activity' => $drive->id, 'guests' => 1], ['occurs_on' => today()->subDays(2)]);
        $unpaid = $this->record($workspace, $app, 'bookings', 'Old unpaid', 'booked', ['activity' => $drive->id, 'guests' => 1], ['occurs_on' => today()->subDays(2)]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame('cancelled', $unpaid->fresh()->status);
        $this->assertSame('booked', $today->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('By activity')->assertSee('Morning game drive');
    }

    public function test_bakery_confirms_cakes_with_lead_time_and_deposit_and_tracks_daily_bakes(): void
    {
        $app = 'bakery-confectionery-orders-custom';
        [$owner, $workspace] = $this->appWorkspace($app);
        $order = fn (string $status, array $fields = [], array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), [
            'title' => 'Chanda birthday', 'status' => $status, 'amount' => 300, 'due_on' => today()->addDays(5)->toDateString(),
            'data' => ['size' => '2 tiers', 'flavour' => 'Chocolate', ...$data], ...$fields,
        ]);

        $order('confirmed', [], ['deposit' => 50])->assertSessionHasErrors('data.deposit');
        $order('enquiry', ['due_on' => today()->subDay()->toDateString()])->assertSessionHasErrors('due_on');
        $order('enquiry', ['due_on' => today()->addDay()->toDateString()])->assertSessionHasNoErrors();
        $cake = Record::query()->where('entity', 'orders')->firstOrFail();

        $this->actingAs($owner)->post($cake->url().'/actions/confirm', ['deposit' => 150])->assertSessionHasErrors('due_on');
        $cake->update(['due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->post($cake->url().'/actions/confirm', ['deposit' => 100])->assertSessionHasErrors('deposit');
        $this->actingAs($owner)->post($cake->url().'/actions/confirm', ['deposit' => 150])->assertSessionHas('flash.message', 'Chanda birthday confirmed for '.today()->addDays(5)->format('d M Y').'; '.$this->money(150).' due on collection.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'orders', $cake->id]), [
            'title' => 'Chanda birthday', 'status' => 'enquiry', 'amount' => 300, 'due_on' => today()->addDays(5)->toDateString(), 'data' => ['size' => '2 tiers', 'deposit' => 150],
        ])->assertSessionHasErrors('status');

        foreach (['bake' => 'baking', 'decorate' => 'decorating', 'ready' => 'ready'] as $action => $status) {
            $this->actingAs($owner)->post($cake->url().'/actions/'.$action)->assertSessionHas('flash.message', 'Chanda birthday is '.$status.'.');
        }
        $this->actingAs($owner)->post($cake->url().'/actions/collect', ['paid' => 100])->assertSessionHasErrors('paid');
        $this->actingAs($owner)->post($cake->url().'/actions/collect', ['paid' => 150])->assertSessionHas('flash.message', 'Chanda birthday collected.');

        $forgotten = $this->record($workspace, $app, 'orders', 'Wedding cake', 'ready', ['size' => '3 tiers'], ['amount' => 900, 'due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertTrue((bool) $forgotten->fresh()->value('_late'));

        $batch = fn (array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'production']), ['title' => 'Bread', 'status' => 'baked', 'data' => ['quantity' => 50, ...$data]]);
        $batch(['sold' => 40, 'wasted' => 20])->assertSessionHasErrors('data.sold');
        $batch(['sold' => 30, 'wasted' => 5])->assertSessionHasNoErrors();
        $bread = Record::query()->where('entity', 'production')->firstOrFail();
        $this->assertSame(15, (int) $bread->value('_left'));
        $this->actingAs($owner)->post($bread->url().'/actions/sell', ['sold' => 50, 'wasted' => 5])->assertSessionHasErrors('sold');
        $this->actingAs($owner)->post($bread->url().'/actions/sell', ['sold' => 45, 'wasted' => 5])->assertSessionHas('flash.message', 'Bread: 0 left, sold out.');
        $this->assertSame('sold_out', $bread->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Cakes due')->assertSee('Not collected');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Production and waste')->assertSee('10%');
    }

    public function test_butchery_reads_scale_labels_and_sells_meat_from_the_oldest_carcass_first(): void
    {
        $app = 'butchery-with-scale-integration';
        [$owner, $workspace] = $this->appWorkspace($app);
        $first = $this->record($workspace, $app, 'carcasses', 'Steer 12', 'hanging', ['animal' => 'beef', 'weight' => 250], ['amount' => 3000]);
        $second = $this->record($workspace, $app, 'carcasses', 'Steer 13', 'hanging', ['animal' => 'beef', 'weight' => 130], ['amount' => 1500]);

        $this->actingAs($owner)->post($first->url().'/actions/cut', ['yield' => 300])->assertSessionHasErrors('yield');
        $this->actingAs($owner)->post($first->url().'/actions/cut', ['yield' => 200])->assertSessionHas('flash.message', 'Steer 12 cut: 200.0 kg (80% yield) at '.$this->money(15).' per kg.');
        $this->actingAs($owner)->post($second->url().'/actions/cut', ['yield' => 100])->assertSessionHasNoErrors();

        $sell = fn (string $cut, float $weight, float $price, ?string $ticket = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), [
            'title' => $cut, 'status' => 'completed', 'data' => ['weight' => $weight, 'price_per_kg' => $price, 'scale_ticket' => $ticket],
        ]);
        $label = $this->scaleLabel('12345', 1500);
        $sell('T-bone', 1.4, 120, $label)->assertSessionHasErrors('data.weight');
        $sell('T-bone', 1.5, 120, substr($label, 0, 12).((int) $label[12] + 1) % 10)->assertSessionHasErrors('data.scale_ticket');
        $sell('T-bone', 1.5, 120, $label)->assertSessionHasNoErrors();
        $tbone = Record::query()->where('entity', 'sales')->firstOrFail();
        $this->assertEquals(180, $tbone->amount);
        $this->assertSame('12345', $tbone->value('_plu'));
        $this->assertEquals(198.5, $first->fresh()->value('_remaining'));

        $sell('Mince', 199, 50)->assertSessionHasNoErrors();
        $this->assertSame('sold_out', $first->fresh()->status);
        $this->assertEquals(99.5, $second->fresh()->value('_remaining'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Meat in stock')->assertSee('99.5 kg');

        $mince = Record::query()->where('title', 'Mince')->firstOrFail();
        $this->actingAs($owner)->post($mince->url().'/actions/refund')->assertSessionHas('flash.message', $this->money(9950).' refunded; 199.000 kg back in stock.');
        $this->assertSame('cut', $first->fresh()->status);
        $this->assertEquals(198.5, $first->fresh()->value('_remaining'));

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Yield by animal')->assertSee('Sales by cut')->assertSee('T-bone');
    }

    /**
     * An EAN-13 scale label: 2, a flag, the PLU, the weight in grams and the check digit.
     */
    private function scaleLabel(string $plu, int $grams): string
    {
        $code = '20'.$plu.str_pad((string) $grams, 5, '0', STR_PAD_LEFT);
        $sum = 0;
        foreach (str_split($code) as $position => $digit) {
            $sum += (int) $digit * ($position % 2 ? 3 : 1);
        }

        return $code.((10 - $sum % 10) % 10);
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
