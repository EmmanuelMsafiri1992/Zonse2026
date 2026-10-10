<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The third logistics batch: fuel stations, LPG cylinders, removals and towing. */
class LogisticsAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_fuel_station_keeps_tank_stock_and_balances_shifts(): void
    {
        $app = 'fuel-station-management-pumps';
        [$owner, $workspace] = $this->appWorkspace($app);
        $tank = $this->record($workspace, $app, 'tanks', 'Diesel 1', 'active', ['product' => 'diesel', 'capacity' => 10000]);

        $dip = fn (float $litres) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'dips']), ['title' => 'Morning dip', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['tank' => $tank->id, 'litres' => $litres]]);
        $dip(12000)->assertSessionHasErrors(['data.litres' => 'Diesel 1 only holds 10,000 litres.']);
        $dip(3000)->assertSessionHasNoErrors();

        $deliver = fn (float $litres) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), ['title' => 'DN-1', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'amount' => 9000, 'data' => ['tank' => $tank->id, 'litres' => $litres]]);
        $deliver(8000)->assertSessionHasErrors(['data.litres' => 'That would overfill Diesel 1; it has room for 7,000 litres.']);
        $deliver(6000)->assertSessionHasNoErrors();

        $dip(8500)->assertSessionHasNoErrors();
        $evening = Record::query()->where('entity', 'dips')->latest('id')->firstOrFail();
        $this->assertEquals(9000, $evening->value('_expected'));
        $this->assertEquals(500, $evening->value('_drawdown'));

        $shift = fn (string $status) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'shifts']), ['title' => 'Pump 1 morning', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['attendant' => $owner->id, 'opening_meter' => 1000]]);
        $shift('closed')->assertSessionHasErrors('status');
        $shift('open')->assertSessionHasNoErrors();
        $shift('open')->assertSessionHasErrors('data.attendant');
        $open = Record::query()->where('entity', 'shifts')->firstOrFail();

        $this->actingAs($owner)->post($open->url().'/actions/close', ['closing_meter' => 900, 'price' => 2, 'cash' => 0])->assertSessionHasErrors('closing_meter');
        $this->actingAs($owner)->post($open->url().'/actions/close', ['closing_meter' => 1400, 'price' => 2, 'cash' => 700, 'card_and_mobile' => 50])
            ->assertSessionHas('flash.message', 'Pump 1 morning closed: 400.00 litres, '.$this->money(800).' expected, '.$this->money(50).' short.');
        $open->refresh();
        $this->assertSame('short', $open->status);
        $this->assertEquals(400, $open->value('litres_sold'));

        $delivery = Record::query()->where('entity', 'deliveries')->firstOrFail();
        $this->actingAs($owner)->post($delivery->url().'/actions/dispute', ['litres_found' => 5800, 'reason' => 'Short on the dipstick'])->assertSessionHas('flash.message', 'DN-1 disputed: 200 litres short.');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Tank levels')->assertSee('85%');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Wet stock')->assertSee('100.00');
    }

    public function test_gas_sales_hand_over_full_cylinders_and_take_back_empties(): void
    {
        $app = 'lpg-gas-cylinder-distribution';
        [$owner, $workspace] = $this->appWorkspace($app);
        $customer = Contact::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Chipo Banda']);
        $cylinder = fn (string $serial, string $status, string $testDue) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'cylinders']), ['title' => $serial, 'status' => $status, 'data' => ['size' => '9kg', 'test_due' => $testDue]]);
        $cylinder('abc-1', 'full', today()->addYear()->toDateString())->assertSessionHasNoErrors();
        $cylinder('ABC 1', 'full', today()->addYear()->toDateString())->assertSessionHasErrors('title');
        $cylinder('C2', 'full', today()->addYear()->toDateString())->assertSessionHasNoErrors();
        $cylinder('C3', 'full', today()->subDay()->toDateString())->assertSessionHasErrors('data.test_due');
        $cylinder('C3', 'empty', today()->subDay()->toDateString())->assertSessionHasNoErrors();
        $held = $this->record($workspace, $app, 'cylinders', 'X9', 'with_customer', ['size' => '9kg'], ['contact_id' => $customer->id]);
        $this->assertSame('ABC1', Record::query()->where('entity', 'cylinders')->orderBy('id')->firstOrFail()->title);

        $sell = fn (string $type, int $quantity) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), ['title' => 'Counter', 'status' => 'completed', 'contact_id' => $customer->id, 'amount' => 60, 'occurs_on' => today()->toDateString(), 'data' => ['type' => $type, 'size' => '9kg', 'quantity' => $quantity]]);
        $sell('exchange', 3)->assertSessionHasErrors(['data.quantity' => 'Only 2 full 9kg cylinders in stock.']);
        $sell('exchange', 2)->assertSessionHasNoErrors();
        $this->assertSame(2, Record::query()->where('entity', 'cylinders')->where('status', 'with_customer')->where('contact_id', $customer->id)->count());
        $this->assertSame('empty', $held->fresh()->status);
        $sell('new_cylinder', 1)->assertSessionHasErrors('data.quantity');

        $sale = Record::query()->where('entity', 'sales')->firstOrFail();
        $this->actingAs($owner)->post($sale->url().'/actions/cancel', ['reason' => 'Wrong size'])->assertSessionHas('flash.message', 'Counter cancelled; cylinders put back.');
        $this->assertSame(2, Record::query()->where('entity', 'cylinders')->where('status', 'full')->count());
        $this->assertSame('with_customer', $held->fresh()->status);

        $c3 = Record::query()->where('title', 'C3')->firstOrFail();
        $this->actingAs($owner)->post($c3->url().'/actions/fill')->assertSessionHasErrors('test_due');
        $this->actingAs($owner)->post($c3->url().'/actions/tested', ['next_due' => today()->addYears(5)->toDateString()]);
        $this->actingAs($owner)->post($c3->url().'/actions/fill')->assertSessionHas('flash.message', 'C3 filled.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Cylinder stock');
    }

    public function test_removals_survey_quote_and_book_a_truck_once_a_day(): void
    {
        $app = 'moving-removals-company';
        [$owner, $workspace] = $this->appWorkspace($app);
        $move = ['title' => 'Banda family', 'status' => 'enquiry', 'data' => ['from_address' => '12 Lake Rd', 'to_address' => '4 Hill St']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'moves']), [...$move, 'data' => ['from_address' => '12 Lake Rd', 'to_address' => '12 lake rd.']])->assertSessionHasErrors('data.to_address');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'moves']), $move)->assertSessionHasNoErrors();
        $banda = Record::query()->where('entity', 'moves')->firstOrFail();
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'moves', $banda->id]), [...$move, 'status' => 'quoted'])->assertSessionHasErrors(['data.volume', 'amount']);

        $this->actingAs($owner)->post($banda->url().'/actions/survey', ['volume' => 50, 'inventory' => "Sofa\nBed\nFridge"])->assertSessionHas('flash.message', 'Banda family surveyed at 50.0 m³; crew of 4 needed.');
        $this->assertEquals(3, $banda->fresh()->value('_items'));
        $this->actingAs($owner)->post($banda->url().'/actions/quote', ['rate' => 20, 'declared_value' => 10000])
            ->assertSessionHas('flash.message', 'Banda family quoted at '.$this->money(1150).' including '.$this->money(150).' cover.');
        $this->assertEquals(1150, $banda->fresh()->amount);

        $date = today()->addDays(3)->toDateString();
        $this->record($workspace, $app, 'moves', 'Phiri office', 'booked', ['from_address' => 'A', 'to_address' => 'B', 'volume' => 20, 'crew_size' => 2, 'truck' => 'TRK-1'], ['occurs_on' => $date, 'amount' => 500]);
        $this->actingAs($owner)->post($banda->url().'/actions/book', ['date' => $date, 'truck' => 'TRK-2', 'crew_size' => 2])->assertSessionHasErrors('crew_size');
        $this->actingAs($owner)->post($banda->url().'/actions/book', ['date' => $date, 'truck' => 'trk 1', 'crew_size' => 4])->assertSessionHasErrors(['truck' => 'trk 1 is on Phiri office that day.']);
        $this->actingAs($owner)->post($banda->url().'/actions/book', ['date' => $date, 'truck' => 'TRK-2', 'crew_size' => 4])->assertSessionHasNoErrors();
        $this->assertSame('booked', $banda->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Moves this week')->assertSee('Banda family');
        $this->actingAs($owner)->post($banda->url().'/actions/start');
        $this->actingAs($owner)->post($banda->url().'/actions/complete', ['damages' => ''])->assertSessionHas('flash.message', 'Banda family completed.');

        $phiri = Record::query()->where('title', 'Phiri office')->firstOrFail();
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'moves', $phiri->id]), ['title' => 'Phiri office', 'status' => 'cancelled', 'data' => $phiri->data])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($phiri->url().'/actions/cancel', ['reason' => 'Lease fell through'])->assertSessionHas('flash.message', 'Phiri office cancelled: Lease fell through.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Enquiries to moves');
    }

    public function test_towing_dispatches_free_drivers_and_charges_by_distance(): void
    {
        $app = 'towing-roadside-assistance-dispatch';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'callouts']), [
            'title' => 'Hilux, gearbox', 'status' => 'received', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'tow', 'location' => 'M1 north', 'registration' => 'abc 123', 'insurer' => 'AA'],
        ])->assertSessionHasNoErrors();
        $hilux = Record::query()->where('entity', 'callouts')->firstOrFail();
        $this->assertSame('ABC123', $hilux->value('registration'));

        $this->actingAs($owner)->post($hilux->url().'/actions/dispatch')->assertSessionHasErrors('assignee_id');
        $hilux->update(['assignee_id' => $owner->id]);
        $this->actingAs($owner)->post($hilux->url().'/actions/dispatch')->assertSessionHas('flash.message', 'Hilux, gearbox dispatched to '.$owner->name.'.');

        $flat = $this->record($workspace, $app, 'callouts', 'Corolla, battery', 'received', ['type' => 'jump_start', 'location' => 'Mall'], ['assignee_id' => $owner->id]);
        $this->actingAs($owner)->post($flat->url().'/actions/dispatch')->assertSessionHasErrors(['assignee_id' => 'That driver is on Hilux, gearbox.']);

        $this->travel(25)->minutes();
        $this->actingAs($owner)->post($hilux->url().'/actions/arrive')->assertSessionHas('flash.message', 'Hilux, gearbox: on scene after 25 minutes.');
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'callouts', $hilux->id]), ['title' => 'Hilux, gearbox', 'status' => 'completed', 'assignee_id' => $owner->id, 'data' => $hilux->fresh()->data])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post($hilux->url().'/actions/tow', ['destination' => 'City Motors']);
        $this->actingAs($owner)->post($hilux->url().'/actions/complete', ['distance' => 12])->assertSessionHas('flash.message', 'Hilux, gearbox completed: '.$this->money(90).' to bill AA.');
        $this->assertEquals(90, $hilux->fresh()->amount);

        $this->actingAs($owner)->post($flat->url().'/actions/dispatch')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post($flat->url().'/actions/arrive');
        $this->actingAs($owner)->post($flat->url().'/actions/complete')->assertSessionHas('flash.message', 'Corolla, battery completed: '.$this->money(30).'.');

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Call-outs by type')->assertSee('Jump start')->assertSee('25 min');
    }

    private function money(float $amount): string
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
