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

/** People: staffing agency, overtime and advances, exits, org chart and health & safety. */
class PeopleAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_staffing_bills_hours_above_the_pay_rate_and_tracks_who_is_placed(): void
    {
        $app = 'staffing-outsourcing-agency-placements';
        [$owner, $workspace] = $this->appWorkspace($app);
        $thabo = $this->record($workspace, $app, 'workers', 'Thabo', 'available', ['pay_rate' => 50, 'skills' => 'Cleaning']);
        $place = fn (string $title, float $rate) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'placements']), ['title' => $title, 'status' => 'active', 'assignee_id' => $owner->id, 'occurs_on' => today()->toDateString(), 'data' => ['worker' => $thabo->id, 'bill_rate' => $rate, 'site' => 'Head office']]);
        $place('Cleaner', 40)->assertSessionHasErrors(['data.bill_rate' => 'The bill rate must be more than Thabo\'s pay rate of '.$this->money(50).'.']);
        $place('Cleaner', 80)->assertSessionHasNoErrors();
        $this->assertSame('placed', $thabo->fresh()->status);
        $place('Porter', 90)->assertSessionHasErrors(['data.worker' => 'Thabo is already placed as Cleaner.']);
        $placement = Record::query()->where('entity', 'placements')->firstOrFail();

        $hours = fn () => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'timesheets']), ['title' => 'Week', 'status' => 'submitted', 'occurs_on' => today()->toDateString(), 'data' => ['placement' => $placement->id, 'hours' => 40]]);
        $hours()->assertSessionHasNoErrors();
        $hours()->assertSessionHasErrors(['data.placement' => 'This placement already has a timesheet for the week of '.today()->startOfWeek()->format('d M Y').'.']);
        $week = Record::query()->where('entity', 'timesheets')->firstOrFail();
        $this->assertEquals(3200, $week->amount);
        $this->assertEquals(2000, $week->value('payout'));
        $this->assertEquals(1200, $week->value('_margin'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting to bill')->assertSee($this->money(3200));
        $this->actingAs($owner)->post($week->url().'/actions/bill')->assertSessionHas('flash.message', 'Billed '.$this->money(3200).' for 40 hours.');
        $this->actingAs($owner)->post($week->url().'/actions/pay')->assertSessionHas('flash.message', 'Paid Thabo '.$this->money(2000).'.');

        $this->actingAs($owner)->get($placement->url())->assertOk()->assertSee($this->money(1200).' (38%)');
        $this->actingAs($owner)->post($placement->url().'/actions/end')->assertSessionHas('flash.message', 'Thabo\'s placement ended. They are available again.');
        $this->assertSame('available', $thabo->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Margin by placement');
    }

    public function test_overtime_claims_allowances_and_advances_follow_their_rules(): void
    {
        $app = 'overtime';
        [$owner, $workspace] = $this->appWorkspace($app);
        $jane = $this->member($workspace, 'Jane Doe');
        $claim = fn (array $attributes, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'claims']), ['title' => 'Stock take', 'status' => 'submitted', 'amount' => 450, 'occurs_on' => today()->toDateString(), ...$attributes, 'data' => ['employee' => $jane->id, 'hours' => 3, 'rate' => '1.5x', ...$data]]);
        $claim(['occurs_on' => today()->addDay()->toDateString()])->assertSessionHasErrors(['occurs_on' => 'Overtime can only be claimed once it is worked.']);
        $claim([], ['hours' => 30])->assertSessionHasErrors(['data.hours' => 'Give between 0 and 24 hours.']);
        $claim([])->assertSessionHasNoErrors();
        $claim([])->assertSessionHasErrors(['occurs_on' => 'Jane Doe already claimed overtime for '.today()->format('d M Y').'.']);
        $stockTake = Record::query()->where('entity', 'claims')->firstOrFail();
        $this->actingAs($owner)->post($stockTake->url().'/actions/approve')->assertSessionHas('flash.message', 'Jane Doe\'s 3 hours approved.');
        $this->actingAs($owner)->post($stockTake->url().'/actions/pay')->assertSessionHas('flash.message', 'Paid Jane Doe '.$this->money(450).' overtime.');

        $housing = $this->record($workspace, $app, 'allowances', 'Housing', 'active', ['employee' => $jane->id, 'type' => 'housing'], ['amount' => 1500, 'occurs_on' => today()->subYear(), 'due_on' => today()->addMonth()]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'allowances']), ['title' => 'Housing top-up', 'status' => 'active', 'amount' => 500, 'data' => ['employee' => $jane->id, 'type' => 'housing']])
            ->assertSessionHasErrors(['data.type' => 'Jane Doe already gets a housing allowance.']);
        Record::query()->whereKey($housing->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('ended', $housing->fresh()->status);

        $advance = fn (float $instalment) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'advances']), ['title' => 'School fees', 'status' => 'requested', 'amount' => 2000, 'occurs_on' => today()->toDateString(), 'data' => ['employee' => $jane->id, 'instalment' => $instalment]]);
        $advance(3000)->assertSessionHasErrors(['data.instalment' => 'The monthly deduction cannot be more than the amount.']);
        $advance(500)->assertSessionHasNoErrors();
        $advance(500)->assertSessionHasErrors(['data.employee' => 'Jane Doe still owes '.$this->money(2000).' on an earlier advance.']);
        $fees = Record::query()->where('entity', 'advances')->firstOrFail();
        $this->assertEquals(2000, $fees->value('balance'));
        $this->actingAs($owner)->post($fees->url().'/actions/approve')->assertSessionHas('flash.message', 'Jane Doe\'s advance of '.$this->money(2000).' approved, repaid over 4 months.');
        $this->actingAs($owner)->post($fees->url().'/actions/deduct', ['amount' => 2500])->assertSessionHasErrors(['amount' => 'Only '.$this->money(2000).' is left to repay.']);
        $this->actingAs($owner)->post($fees->url().'/actions/deduct', ['amount' => 500])->assertSessionHas('flash.message', 'Deducted '.$this->money(500).' from Jane Doe\'s advance; '.$this->money(1500).' left.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Owed on advances')->assertSee($this->money(1500));
        $this->actingAs($owner)->post($fees->url().'/actions/deduct', ['amount' => 1500])->assertSessionHas('flash.message', 'Deducted '.$this->money(1500).' from Jane Doe\'s advance; it is repaid.');
        $this->assertSame('repaid', $fees->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Overtime by employee')->assertSee('Loan book')->assertSee('School fees');
    }

    public function test_exits_clear_only_once_every_checklist_item_is_done(): void
    {
        $app = 'exit-offboarding-clearance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $exit = fn (string $status, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exits']), ['title' => 'Peter Moyo', 'status' => $status, 'occurs_on' => today()->toDateString(), 'data' => ['reason' => 'resignation', 'last_day' => today()->addMonth()->toDateString(), ...$data]]);
        $exit('notice_given', ['last_day' => today()->subDay()->toDateString()])->assertSessionHasErrors(['data.last_day' => 'The last day cannot be before notice was given.']);
        $exit('cleared', [])->assertSessionHasErrors(['status' => 'Still outstanding: company assets, IT access, finance.']);
        $exit('notice_given', [])->assertSessionHasNoErrors();
        $peter = Record::query()->where('entity', 'exits')->firstOrFail();
        $this->assertEquals(today()->diffInDays(today()->addMonth()), $peter->value('_notice_days'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Leaving')->assertSee('Waiting on company assets, IT access, finance');

        $this->actingAs($owner)->post($peter->url().'/actions/checklist', ['assets_returned' => '1'])
            ->assertSessionHas('flash.message', 'Clearance for Peter Moyo: 1 of 3 done; still waiting on IT access, finance.');
        $this->assertSame('clearing', $peter->fresh()->status);
        $this->actingAs($owner)->post($peter->url().'/actions/checklist', ['it_access_removed' => '1', 'finance_cleared' => '1'])->assertSessionHas('flash.message', 'Peter Moyo is cleared.');
        $this->assertSame('cleared', $peter->fresh()->status);
        $this->actingAs($owner)->post($peter->url().'/actions/final_pay', ['amount' => 8000])->assertSessionHas('flash.message', 'Peter Moyo\'s final pay of '.$this->money(8000).' is done.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Exits by reason')->assertSee($this->money(8000));
    }

    public function test_org_chart_blocks_reporting_loops_and_flags_succession_gaps(): void
    {
        $app = 'org-chart-succession-planning';
        [$owner, $workspace] = $this->appWorkspace($app);
        $owner->update(['name' => 'Sam Phiri']);
        $ceo = $this->record($workspace, $app, 'positions', 'CEO', 'filled', ['holder' => $owner->id, 'department' => 'Executive']);
        $cfo = $this->record($workspace, $app, 'positions', 'CFO', 'filled', ['reports_to' => $ceo->id, 'department' => 'Finance']);
        $manager = $this->record($workspace, $app, 'positions', 'Finance manager', 'filled', ['reports_to' => $cfo->id, 'department' => 'Finance']);
        $this->assertSame('vacant', $manager->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'positions', $ceo->id]), ['title' => 'CEO', 'status' => 'filled', 'data' => ['holder' => $owner->id, 'reports_to' => $manager->id]])
            ->assertSessionHasErrors(['data.reports_to' => 'That would make CEO report to itself.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'positions']), ['title' => 'COO', 'status' => 'vacant', 'data' => ['holder' => $owner->id]])
            ->assertSessionHasErrors(['data.holder' => 'Sam Phiri already holds CEO.']);

        $this->record($workspace, $app, 'successors', 'Jane Doe', 'ready_now', ['position' => $cfo->id]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'successors']), ['title' => ' jane doe', 'status' => 'ready_1_2_years', 'data' => ['position' => $cfo->id]])
            ->assertSessionHasErrors(['title' => 'Jane Doe is already a successor for this position.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('No successor ready (2 of 3)')->assertSee('Finance manager');
        $this->actingAs($owner)->get($cfo->url())->assertOk()->assertSee('Direct reports')->assertSee('Reports to CFO')->assertSee('Finance manager');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Bench strength')->assertSee('Sam Phiri');
    }

    public function test_health_and_safety_counts_days_without_injury_and_replaces_ppe(): void
    {
        $app = 'health-safety';
        [$owner, $workspace] = $this->appWorkspace($app);
        $incident = fn (string $status, array $attributes, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'incidents']), ['title' => 'Slipped in store', 'status' => $status, 'occurs_on' => today()->toDateString(), ...$attributes, 'data' => ['type' => 'injury', 'injured_person' => 'Peter', ...$data]]);
        $incident('reported', ['occurs_on' => today()->addDay()->toDateString()], [])->assertSessionHasErrors(['occurs_on' => 'An incident cannot be reported before it happens.']);
        $incident('reported', [], ['injured_person' => ''])->assertSessionHasErrors(['data.injured_person' => 'Name the injured person.']);
        $incident('closed', [], [])->assertSessionHasErrors(['data.corrective_action' => 'Write down the corrective action before closing the incident.']);

        $slip = $this->record($workspace, $app, 'incidents', 'Slipped in store', 'reported', ['type' => 'injury', 'severity' => 'lost_time', 'injured_person' => 'Peter'], ['occurs_on' => today()->subDays(10)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Days without a lost-time injury')->assertSee('Open incidents');
        $this->actingAs($owner)->post($slip->url().'/actions/investigate')->assertSessionHas('flash.message', 'Slipped in store is under investigation.');
        $this->actingAs($owner)->post($slip->url().'/actions/close')->assertSessionHasErrors(['corrective_action' => 'Write down the corrective action before closing the incident.']);
        $this->actingAs($owner)->post($slip->url().'/actions/close', ['corrective_action' => 'Anti-slip mats laid'])->assertSessionHas('flash.message', 'Slipped in store closed after 10 days.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'ppe']), ['title' => 'Gloves', 'status' => 'issued', 'data' => ['employee' => $owner->id, 'quantity' => 0]])
            ->assertSessionHasErrors(['data.quantity' => 'Issue at least one.']);
        $boots = $this->record($workspace, $app, 'ppe', 'Safety boots', 'issued', ['employee' => $owner->id, 'size' => '9', 'quantity' => 1], ['occurs_on' => today()->subDays(180), 'due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('PPE due for replacement')->assertSee('Safety boots');
        $this->actingAs($owner)->post($boots->url().'/actions/replace')->assertSessionHas('flash.message', 'Safety boots replaced; next replacement by '.today()->addDays(185)->format('d M Y').'.');
        $this->assertSame('replaced', $boots->fresh()->status);
        $this->assertSame(1, Record::query()->where('entity', 'ppe')->where('status', 'issued')->count());

        $talk = $this->record($workspace, $app, 'talks', 'Ladder safety', 'planned', [], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($talk->url().'/actions/held', ['attendees' => "Jane, Peter\nSam"])->assertSessionHas('flash.message', 'Ladder safety held with 3 attendees.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Incidents by type')->assertSee('Injury');
    }

    private function member(Workspace $workspace, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $workspace->members()->attach($user->id, ['role' => 'member', 'joined_at' => now()]);

        return $user;
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
