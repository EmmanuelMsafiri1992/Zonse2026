<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Property: valuations, cleaning schedules, co-working, self-storage, parking and the tenant portal. */
class PropertyAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_valuations_are_issued_only_with_a_value_and_report(): void
    {
        $app = 'property-valuation';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'valuations']), ['title' => '5 Elm Rd', 'status' => 'issued', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['purpose' => 'sale']])
            ->assertSessionHasErrors(['occurs_on' => 'The inspection date cannot be in the future.', 'data.market_value' => 'Give the market value before issuing.', 'data.report_url' => 'Link the report before issuing.']);

        $elm = $this->record($workspace, $app, 'valuations', '5 Elm Rd', 'instructed', ['purpose' => 'mortgage', 'building_size' => 200, 'erf_size' => 600], ['amount' => 3500]);
        $this->assertTrue($elm->due_on->isSameDay(today()->addDays(7)));
        $this->actingAs($owner)->post($elm->url().'/actions/inspect')->assertSessionHas('flash.message', '5 Elm Rd inspected; the report is due '.today()->addDays(7)->format('d M').'.');
        $this->actingAs($owner)->post($elm->url().'/actions/issue', ['market_value' => 2400000])->assertSessionHasErrors(['report_url' => 'Link the report before issuing.']);
        $this->actingAs($owner)->post($elm->url().'/actions/issue', ['market_value' => 2400000, 'report_url' => 'https://example.com/elm.pdf'])->assertSessionHas('flash.message', '5 Elm Rd valued at '.$this->money(2400000).'.');
        $this->assertEquals(12000, $elm->fresh()->value('_per_m2'));
        $this->assertSame(today()->toDateString(), $elm->fresh()->value('_issued_on'));

        $this->record($workspace, $app, 'valuations', '9 Oak Ave', 'inspected', ['purpose' => 'insurance'], ['occurs_on' => today()->subDays(5), 'due_on' => today()->subDays(2)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Reports overdue')->assertSee('9 Oak Ave')->assertSee('2 days late');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Valuations by purpose')->assertSee('Mortgage')->assertSee($this->money(3500));
    }

    public function test_cleaning_checks_keep_areas_on_schedule_and_issues_need_a_fix(): void
    {
        $app = 'facility-management-cleaning-schedules';
        [$owner, $workspace] = $this->appWorkspace($app);
        $lobby = $this->record($workspace, $app, 'areas', 'Lobby', 'active', ['frequency' => 'daily', 'checklist' => 'Floors, glass']);
        $store = $this->record($workspace, $app, 'areas', 'Store room', 'closed', ['frequency' => 'weekly', 'checklist' => 'Sweep']);
        $parking = $this->record($workspace, $app, 'areas', 'Car park', 'active', ['frequency' => 'weekly', 'checklist' => 'Litter']);
        $check = fn (Record $area, string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'checks']), ['title' => $area->title, 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['area' => $area->id, 'cleaner' => $owner->id, 'time' => '08:00', ...$data]]);

        $check($store, 'done')->assertSessionHasErrors(['data.area' => 'Store room is closed.']);
        $check($lobby, 'issue')->assertSessionHasErrors(['data.issues' => 'Say what the issue is.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Cleaning overdue')->assertSee('never cleaned');

        $check($lobby, 'issue', ['issues' => 'Broken tile'])->assertSessionHasNoErrors();
        $this->assertSame(today()->toDateString(), $lobby->fresh()->value('_last_cleaned'));
        $this->record($workspace, $app, 'checks', 'Car park', 'done', ['area' => $parking->id, 'cleaner' => $owner->id], ['occurs_on' => today()->subDays(10)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('1 open issue')->assertSee('last '.today()->subDays(10)->format('d M'));

        $issue = Record::query()->where('entity', 'checks')->where('status', 'issue')->first();
        $this->actingAs($owner)->post($issue->url().'/actions/fixed', ['fix' => ''])->assertSessionHasErrors(['fix' => 'Say what was done to fix it.']);
        $this->actingAs($owner)->post($issue->url().'/actions/fixed', ['fix' => 'Tile replaced'])->assertSessionHas('flash.message', 'Issue at Lobby fixed.');
        $this->assertSame("Broken tile\nFixed: Tile replaced", $issue->fresh()->value('issues'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Checks by area')->assertSee('Car park')->assertSee($owner->name);
    }

    public function test_coworking_bookings_never_double_book_a_desk(): void
    {
        $app = 'coworking';
        [$owner, $workspace] = $this->appWorkspace($app);
        $ana = $this->record($workspace, $app, 'members', 'Ana', 'active', ['plan' => 'hot_desk'], ['amount' => 1500]);
        $bo = $this->record($workspace, $app, 'members', 'Bo', 'paused', ['plan' => 'dedicated_desk'], ['amount' => 2500]);
        $book = fn (string $space, ?Record $member, string $start, string $end, int $day = 0) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), ['title' => $space, 'status' => 'booked', 'occurs_on' => today()->addDays($day)->toDateString(), 'data' => ['member' => $member?->id, 'start_time' => $start, 'end_time' => $end]]);

        $book('Desk 1', $bo, '09:00', '12:00')->assertSessionHasErrors(['data.member' => 'Bo\'s membership is paused.']);
        $book('Desk 1', $ana, '12:00', '09:00')->assertSessionHasErrors(['data.end_time' => 'The booking must end after it starts.']);
        $book('Desk 1', $ana, '09:00', '12:00')->assertSessionHasNoErrors();
        $book('desk 1', null, '11:00', '13:00')->assertSessionHasErrors(['data.start_time' => 'Desk 1 is booked from 09:00 to 12:00.']);
        $book('Desk 1', null, '12:00', '14:00')->assertSessionHasNoErrors();
        $book('Board room', $ana, '10:00', '11:00', 1)->assertSessionHasNoErrors();
        $morning = Record::query()->where('entity', 'bookings')->where('title', 'Desk 1')->whereNotNull('data->member')->first();
        $this->assertEquals(3, $morning->value('_hours'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today&#039;s bookings', false)->assertSee($this->money(1500));

        $this->actingAs($owner)->post($morning->url().'/actions/check_in')->assertSessionHas('flash.message', 'Desk 1 checked in.');
        $this->actingAs($owner)->post($ana->url().'/actions/cancel')->assertSessionHas('flash.message', 'Ana\'s membership cancelled with 1 upcoming booking.');
        $this->assertSame('cancelled', Record::query()->where('entity', 'bookings')->where('title', 'Board room')->first()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Use by desk or room')->assertSee('Desk 1');
    }

    public function test_storage_rentals_get_gate_codes_and_are_overlocked_when_behind(): void
    {
        $app = 'self-storage-units';
        [$owner, $workspace] = $this->appWorkspace($app);
        $small = $this->record($workspace, $app, 'units', 'A1', 'vacant', ['size' => 'small', 'monthly_rate' => 500]);
        $garage = $this->record($workspace, $app, 'units', 'B2', 'vacant', ['size' => 'garage', 'monthly_rate' => 300]);
        $rent = fn (Record $unit, string $customer, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'rentals']), ['title' => $customer, 'status' => 'active', 'occurs_on' => today()->toDateString(), 'data' => ['unit' => $unit->id, ...$data]]);

        $rent($small, 'Kim')->assertSessionHasNoErrors();
        $kim = Record::query()->where('entity', 'rentals')->where('title', 'Kim')->first();
        $this->assertEquals(500, (float) $kim->amount);
        $this->assertTrue($kim->due_on->isSameDay(today()->addMonthNoOverflow()));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $kim->value('gate_code'));
        $this->assertSame('occupied', $small->fresh()->status);
        $rent($small, 'Lee')->assertSessionHasErrors(['data.unit' => 'This unit is rented to Kim.']);
        $rent($garage, 'Lee', ['gate_code' => $kim->value('gate_code')])->assertSessionHasErrors(['data.gate_code' => 'Another customer already uses gate code '.$kim->value('gate_code').'.']);

        $lee = $this->record($workspace, $app, 'rentals', 'Lee', 'active', ['unit' => $garage->id], ['occurs_on' => today()->subMonthsNoOverflow(2)]);
        $this->assertSame('in_arrears', $lee->status);
        $this->assertSame('overlocked', $garage->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overlocked')->assertSee('Lee')->assertSee('100%');

        Record::query()->whereKey($kim->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('in_arrears', $kim->fresh()->status);

        $this->actingAs($owner)->post($lee->url().'/actions/pay', ['months' => 2])->assertSessionHas('flash.message', 'Lee paid '.$this->money(600).' for 2 months; paid until '.today()->subMonthsNoOverflow(2)->addMonthNoOverflow()->addMonthsNoOverflow(2)->format('d M Y').' and the overlock can come off.');
        $this->assertSame('occupied', $garage->fresh()->status);
        $code = $kim->value('gate_code');
        $this->actingAs($owner)->post($kim->url().'/actions/end')->assertSessionHas('flash.message', 'Kim moved out; gate code '.$code.' no longer works.');
        $this->assertSame('vacant', $small->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Occupancy by unit size')->assertSee('Garage');
    }

    public function test_parking_permits_park_free_and_visitors_pay(): void
    {
        $app = 'parking-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $permit = fn (string $holder, string $registration) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'permits']), ['title' => $holder, 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addMonth()->toDateString(), 'data' => ['registration' => $registration, 'type' => 'monthly']]);
        $permit('Sam', 'ca 123-456')->assertSessionHasNoErrors();
        $this->assertSame('CA123456', Record::query()->where('entity', 'permits')->first()->value('registration'));
        $permit('Tom', 'CA123456')->assertSessionHasErrors(['data.registration' => 'CA123456 already has a permit for Sam.']);

        $park = fn (string $registration, string $entry) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), ['title' => $registration, 'status' => 'parked', 'occurs_on' => today()->toDateString(), 'data' => ['entry_time' => $entry, 'zone' => 'P1']]);
        $park('ca123 456', '08:00')->assertSessionHasNoErrors();
        $park('CA123456', '09:00')->assertSessionHasErrors(['title' => 'CA123456 is already parked.']);
        $sam = Record::query()->where('entity', 'sessions')->where('title', 'CA123456')->first();
        $this->assertSame('Sam', $sam->value('_permit'));
        $this->assertEquals(0, (float) $sam->amount);
        $this->actingAs($owner)->post($sam->url().'/actions/exit', ['exit_time' => '07:00'])->assertSessionHasErrors(['exit_time' => 'The exit must be after the entry at 08:00.']);
        $this->actingAs($owner)->post($sam->url().'/actions/exit', ['exit_time' => '10:30'])->assertSessionHas('flash.message', 'CA123456 left after 2h 30, on permit.');
        $this->assertSame('exited', $sam->fresh()->status);

        $park('xyz 1', '09:00')->assertSessionHasNoErrors();
        $visitor = Record::query()->where('entity', 'sessions')->where('title', 'XYZ1')->first();
        $this->actingAs($owner)->post($visitor->url().'/actions/exit', ['exit_time' => '10:00'])->assertSessionHas('flash.message', 'XYZ1 left after 1h 00 without paying.');
        $this->assertSame('unpaid', $visitor->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Unpaid')->assertSee('XYZ1');
        $this->actingAs($owner)->post($visitor->url().'/actions/pay', ['amount' => 20])->assertSessionHas('flash.message', 'XYZ1 paid '.$this->money(20).'.');
        $this->assertSame('exited', $visitor->fresh()->status);

        $stale = $this->record($workspace, $app, 'sessions', 'OLD1', 'parked', ['entry_time' => '17:00'], ['occurs_on' => today()->subDay()]);
        $lapsed = $this->record($workspace, $app, 'permits', 'Una', 'active', ['registration' => 'GP1'], ['occurs_on' => today()->subMonth(), 'due_on' => today()->addDay()]);
        Record::query()->whereKey($lapsed->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('unpaid', $stale->fresh()->status);
        $this->assertSame('expired', $lapsed->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sessions by zone')->assertSee('P1')->assertSee($this->money(20));
    }

    public function test_tenant_portal_requests_are_answered_with_a_reply(): void
    {
        $app = 'tenant-portal';
        [$owner, $workspace] = $this->appWorkspace($app);
        $account = fn (string $name, string $status, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'accounts']), ['title' => $name, 'status' => $status, 'data' => ['unit' => 'Flat 1', ...$data]]);
        $account('Joe', 'active', [])->assertSessionHasErrors(['data.email' => 'Give an email or phone number before the account goes live.']);
        $account('Joe', 'active', ['email' => 'joe@example.com'])->assertSessionHasNoErrors();
        $account('Jo', 'invited', ['email' => 'JOE@example.com'])->assertSessionHasErrors(['data.email' => 'Joe already uses this email.']);
        $joe = Record::query()->where('entity', 'accounts')->where('title', 'Joe')->first();
        $old = $this->record($workspace, $app, 'accounts', 'Old tenant', 'disabled', ['unit' => 'Flat 9']);
        $this->actingAs($owner)->post($old->url().'/actions/activate')->assertSessionHasErrors(['email' => 'Give an email or phone number before the account goes live.']);

        $ask = fn (Record $from, string $status, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Leaking tap', 'status' => $status, 'data' => ['account' => $from->id, 'type' => 'maintenance', 'message' => 'Kitchen tap drips', ...$data]]);
        $ask($old, 'new')->assertSessionHasErrors(['data.account' => 'Old tenant\'s account is disabled.']);
        $ask($joe, 'done')->assertSessionHasErrors(['data.reply' => 'Write the reply before closing the request.']);

        $tap = $this->record($workspace, $app, 'requests', 'Leaking tap', 'new', ['account' => $joe->id, 'type' => 'maintenance', 'message' => 'Kitchen tap drips'], ['occurs_on' => today()->subDays(3)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for an answer')->assertSee('3 days');
        $this->actingAs($owner)->post($tap->url().'/actions/reply', ['reply' => ''])->assertSessionHasErrors(['reply' => 'Write the reply before closing the request.']);
        $this->actingAs($owner)->post($tap->url().'/actions/reply', ['reply' => 'Plumber booked for Monday.'])->assertSessionHas('flash.message', 'Leaking tap answered after 3 days.');
        $this->assertEquals(3, $tap->fresh()->value('_days_to_answer'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Requests by type')->assertSee('Maintenance');
    }

    private function money(float $amount): string
    {
        return Money::format($amount);
    }

    private function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

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
