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

/** Personal: money, tasks and habits, household, freelancing, career, event planning, fitness and a small landlord. */
class PersonalAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_personal_finance_checks_categories_and_tracks_goals_and_debts(): void
    {
        $app = 'personal-finance';
        [$owner, $workspace] = $this->appWorkspace($app);
        $transaction = fn (string $type, string $category, float $amount) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transactions']), ['title' => ucfirst($category), 'status' => 'cleared', 'amount' => $amount, 'occurs_on' => today()->toDateString(), 'data' => ['type' => $type, 'category' => $category]]);
        $transaction('expense', 'salary', 100)->assertSessionHasErrors(['data.category' => 'Salary is income, not spending.']);
        $transaction('income', 'groceries', 100)->assertSessionHasErrors(['data.category' => 'Groceries is spending, not income.']);
        $transaction('income', 'salary', 10000)->assertSessionHasNoErrors();
        $transaction('expense', 'groceries', 2500)->assertSessionHasNoErrors();

        $car = $this->record($workspace, $app, 'goals', 'Car', 'saving', ['target' => 12000, 'saved' => 3000], ['due_on' => today()->addMonthsNoOverflow(3)]);
        $this->assertEquals(3000, $car->value('_monthly_needed'));
        $this->actingAs($owner)->post($car->url().'/actions/add', ['amount' => 9000])->assertSessionHas('flash.message', 'Added '.$this->money(9000).' to Car: '.$this->money(12000).' of '.$this->money(12000).' (100%).');
        $this->assertSame('reached', $car->fresh()->status);

        $card = $this->record($workspace, $app, 'debts', 'Store card', 'active', ['balance' => 1000, 'monthly_payment' => 400], ['due_on' => today()]);
        $this->actingAs($owner)->post($card->url().'/actions/pay', ['amount' => 1200])->assertSessionHasErrors(['amount' => 'Only '.$this->money(1000).' is owed.']);
        $this->actingAs($owner)->post($card->url().'/actions/pay', ['amount' => 400])->assertSessionHas('flash.message', 'Paid '.$this->money(400).' to Store card; '.$this->money(600).' still owed.');
        $this->assertTrue($card->fresh()->due_on->isSameDay(today()->addMonthNoOverflow()));
        $this->actingAs($owner)->post($card->url().'/actions/pay', ['amount' => 600])->assertSessionHas('flash.message', 'Paid '.$this->money(600).' to Store card; it is paid off.');
        $this->assertSame('paid_off', $card->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Left over')->assertSee($this->money(7500));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Spending by category')->assertSee('Groceries');
    }

    public function test_habit_streaks_grow_when_kept_and_reset_when_missed(): void
    {
        $app = 'personal-tasks';
        [$owner, $workspace] = $this->appWorkspace($app);
        $reading = $this->record($workspace, $app, 'habits', 'Read', 'active', ['frequency' => 'daily', 'streak' => 4, 'best_streak' => 4, '_last_done' => today()->subDay()->toDateString()]);
        $this->actingAs($owner)->post($reading->url().'/actions/check')->assertSessionHas('flash.message', 'Read: 5 in a row, your best yet.');
        $this->assertEquals(5, $reading->fresh()->value('best_streak'));

        $gym = $this->record($workspace, $app, 'habits', 'Gym', 'active', ['frequency' => 'daily', 'streak' => 3, 'best_streak' => 6, '_last_done' => today()->subDays(3)->toDateString()]);
        $this->actingAs($owner)->post($gym->url().'/actions/check')->assertSessionHas('flash.message', 'Gym: 1 in a row.');
        $weekly = $this->record($workspace, $app, 'habits', 'Call mum', 'active', ['frequency' => 'weekly', 'streak' => 2, '_last_done' => today()->subDays(10)->toDateString()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertEquals(0, $weekly->fresh()->value('streak'));
        $this->assertEquals(5, $reading->fresh()->value('streak'));

        $rent = $this->record($workspace, $app, 'todos', 'Pay rent', 'to_do', ['list' => 'personal'], ['due_on' => today()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due today or late')->assertSee('Pay rent');
        $this->actingAs($owner)->post($rent->url().'/actions/done')->assertSessionHas('flash.message', 'Pay rent done.');
        $this->assertSame(today()->toDateString(), $rent->fresh()->value('_done_on'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('To-dos by list')->assertSee('Personal');
    }

    public function test_household_chores_repeat_and_earn_pocket_money(): void
    {
        $app = 'family-household-management-chores';
        [$owner, $workspace] = $this->appWorkspace($app);
        $dishes = $this->record($workspace, $app, 'chores', 'Dishes', 'to_do', ['family_member' => 'Tom', 'frequency' => 'daily', 'reward' => 5], ['due_on' => today()]);
        $this->actingAs($owner)->post($dishes->url().'/actions/done')->assertSessionHas('flash.message', 'Dishes done by Tom, earning '.$this->money(5).'; next due '.today()->addDay()->format('D d M').'.');
        $this->assertSame(1, Record::query()->where('entity', 'chores')->where('status', 'to_do')->whereDate('due_on', today()->addDay()->toDateString())->count());

        $party = $this->record($workspace, $app, 'events', 'Birthday', 'upcoming', ['who' => 'Amy'], ['occurs_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('done', $party->fresh()->status);

        $milk = $this->record($workspace, $app, 'shopping', 'Milk', 'needed', ['quantity' => '2', 'shop' => 'Spar']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Shopping list (1)')->assertSee('Spar');
        $this->actingAs($owner)->post($milk->url().'/actions/bought', ['amount' => 30])->assertSessionHas('flash.message', 'Bought Milk for '.$this->money(30).'.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Chores by family member')->assertSee('Tom');
    }

    public function test_freelance_gigs_price_hours_and_are_paid_only_once_invoiced(): void
    {
        $app = 'freelancer-toolkit';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'gigs']), ['title' => 'Flyer', 'status' => 'paid', 'occurs_on' => today()->toDateString(), 'data' => ['rate_type' => 'fixed', 'rate' => 500]])
            ->assertSessionHasErrors(['status' => 'Send the invoice before marking the gig paid.']);
        $consulting = $this->record($workspace, $app, 'gigs', 'Consulting', 'booked', ['rate_type' => 'daily', 'rate' => 800, 'hours' => 12]);
        $this->assertEquals(1200, $consulting->amount);

        $logo = $this->record($workspace, $app, 'gigs', 'Logo', 'booked', ['rate_type' => 'hourly', 'rate' => 200, 'hours' => 0]);
        $this->actingAs($owner)->post($logo->url().'/actions/log', ['hours' => 5])->assertSessionHas('flash.message', 'Logged 5 hours on Logo; 5 in total, worth '.$this->money(1000).'.');
        $this->actingAs($owner)->post($logo->url().'/actions/deliver')->assertSessionHas('flash.message', 'Logo delivered. Send the invoice for '.$this->money(1000).'.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Not invoiced yet')->assertSee($this->money(1000));
        $this->actingAs($owner)->post($logo->url().'/actions/invoice')->assertSessionHas('flash.message', 'Invoice for '.$this->money(1000).' sent.');
        $this->actingAs($owner)->post($logo->url().'/actions/paid')->assertSessionHas('flash.message', 'Logo paid: '.$this->money(1000).'.');
        $this->actingAs($owner)->get($logo->url())->assertOk()->assertSee('Per hour')->assertSee($this->money(200));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Earnings by platform')->assertSee('Direct');
    }

    public function test_job_applications_move_from_applied_to_accepted(): void
    {
        $app = 'personal-cv-portfolio-site';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'experience']), ['title' => 'Clerk', 'status' => 'current', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subYear()->toDateString(), 'data' => ['type' => 'job', 'organisation' => 'Shoprite']])
            ->assertSessionHasErrors(['due_on' => 'The end date cannot be before the start date.']);
        $old = $this->record($workspace, $app, 'experience', 'Cashier', 'current', ['type' => 'job', 'organisation' => 'Spar'], ['occurs_on' => today()->subYears(3), 'due_on' => today()->subYear()]);
        $this->assertSame('past', $old->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), ['title' => 'Acme analyst', 'status' => 'applied', 'occurs_on' => today()->toDateString(), 'data' => ['source' => 'LinkedIn']])->assertSessionHasNoErrors();
        $acme = Record::query()->where('entity', 'applications')->firstOrFail();
        $this->assertTrue($acme->due_on->isSameDay(today()->addWeek()));
        $interview = today()->addDays(4);
        $this->actingAs($owner)->post($acme->url().'/actions/interview', ['due_on' => $interview->toDateString()])->assertSessionHas('flash.message', 'Interview with Acme analyst on '.$interview->format('D d M Y').'.');
        $this->actingAs($owner)->post($acme->url().'/actions/offer', ['salary' => 25000])->assertSessionHas('flash.message', 'Offer from Acme analyst at '.$this->money(25000).'.');
        $this->actingAs($owner)->post($acme->url().'/actions/accept')->assertSessionHas('flash.message', 'Congratulations, you accepted Acme analyst.');

        $piece = $this->record($workspace, $app, 'portfolio', 'Dashboard', 'draft');
        $this->actingAs($owner)->post($piece->url().'/actions/publish')->assertSessionHasErrors(['data.description' => 'Add a link or a description before publishing.']);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Applications by source')->assertSee('LinkedIn')->assertSee('100%');
    }

    public function test_event_planner_counts_guests_and_tracks_the_budget(): void
    {
        $app = 'wedding-event-planner';
        [$owner, $workspace] = $this->appWorkspace($app);
        $banda = $this->record($workspace, $app, 'guests', 'Banda family', 'invited', ['side' => 'groom', 'party_size' => 4]);
        $this->record($workspace, $app, 'guests', 'Aunt Rose', 'invited', ['side' => 'bride']);
        $this->actingAs($owner)->post($banda->url().'/actions/attending', ['party_size' => 4])->assertSessionHas('flash.message', 'Banda family is coming with 3 more. Headcount: 4.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'budget']), ['title' => 'Cake', 'status' => 'booked', 'data' => ['category' => 'cake', 'estimated' => 5000, 'deposit' => 6000]])
            ->assertSessionHasErrors(['data.deposit' => 'The deposit cannot be more than the cost of '.$this->money(5000).'.']);
        $venue = $this->record($workspace, $app, 'budget', 'Venue', 'booked', ['category' => 'venue', 'estimated' => 5000, 'deposit' => 1000]);
        $this->assertEquals(4000, $venue->value('_to_pay'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Awaiting reply')->assertSee($this->money(4000));
        $this->actingAs($owner)->post($venue->url().'/actions/pay', ['amount' => 5500])->assertSessionHas('flash.message', 'Venue paid: '.$this->money(5500).', '.$this->money(500).' over the estimate.');
        $this->assertEquals(0, $venue->fresh()->value('_to_pay'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Budget by category')->assertSee('Venue')->assertSee('Guests by side');
    }

    public function test_fitness_tracker_counts_active_minutes_and_flags_readings(): void
    {
        $app = 'health-fitness-tracker';
        [$owner, $workspace] = $this->appWorkspace($app);
        $check = fn (array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'measurements']), ['title' => 'Check-in', 'status' => 'logged', 'occurs_on' => today()->toDateString(), 'data' => $data]);
        $check(['blood_pressure' => '80/120'])->assertSessionHasErrors(['data.blood_pressure' => 'Write blood pressure as top/bottom, like 120/80.']);
        $this->record($workspace, $app, 'measurements', 'Check-in', 'logged', ['weight' => 80], ['occurs_on' => today()->subWeek()]);
        $check(['weight' => 78.5, 'blood_pressure' => '145/92'])->assertSessionHasNoErrors();
        $latest = Record::query()->where('entity', 'measurements')->latest('id')->firstOrFail();
        $this->assertEquals(-1.5, $latest->value('_weight_change'));
        $this->assertTrue($latest->value('_bp_high'));
        $this->actingAs($owner)->get($latest->url())->assertOk()->assertSee('-1.5 kg');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'workouts']), ['title' => 'Swim', 'status' => 'done', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['type' => 'swim', 'minutes' => 30]])
            ->assertSessionHasErrors(['occurs_on' => 'A workout in the future cannot be done yet.']);
        $run = $this->record($workspace, $app, 'workouts', 'Morning run', 'planned', ['type' => 'run'], ['occurs_on' => today()]);
        $this->actingAs($owner)->post($run->url().'/actions/done', ['minutes' => 30, 'distance' => 5])->assertSessionHas('flash.message', 'Morning run done: 30 minutes. 30 of 150 this week.');
        $this->assertEquals(6, $run->fresh()->value('_pace'));

        $this->record($workspace, $app, 'medications', 'Metformin', 'taking', ['dose' => '500mg', 'refill_date' => today()->addDays(3)->toDateString()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Refills due')->assertSee('Metformin')->assertSee('30 / 150');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Workouts by type')->assertSee('Run');
    }

    public function test_small_landlord_grades_rent_and_tracks_repairs(): void
    {
        $app = 'landlord-with-one-or';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'units']), ['title' => 'Flat 3', 'status' => 'let', 'data' => ['rent' => 2000]])
            ->assertSessionHasErrors(['data.tenant' => 'Name the tenant of a let unit.']);
        $flat = $this->record($workspace, $app, 'units', 'Flat 1', 'let', ['tenant' => 'Mary', 'rent' => 3000]);
        $this->record($workspace, $app, 'units', 'Flat 2', 'let', ['tenant' => 'Joe', 'rent' => 2500]);

        $rent = fn (float $amount, string $date) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'payments']), ['title' => today()->format('F'), 'status' => 'paid', 'amount' => $amount, 'occurs_on' => $date, 'data' => ['unit' => $flat->id, 'method' => 'bank']]);
        $rent(1000, today()->startOfMonth()->toDateString())->assertSessionHasNoErrors();
        $part = Record::query()->where('entity', 'payments')->latest('id')->firstOrFail();
        $this->assertSame('part_paid', $part->status);
        $this->assertEquals(2000, $part->value('_short'));
        $rent(3000, today()->subMonthNoOverflow()->startOfMonth()->addDays(9)->toDateString())->assertSessionHasNoErrors();
        $this->assertSame('late', Record::query()->where('entity', 'payments')->latest('id')->firstOrFail()->status);
        $rent(3000, today()->startOfMonth()->toDateString())->assertSessionHasNoErrors();
        $this->assertSame('paid', Record::query()->where('entity', 'payments')->latest('id')->firstOrFail()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'repairs']), ['title' => 'Leaking tap', 'status' => 'reported', 'amount' => 500, 'occurs_on' => today()->startOfMonth()->toDateString(), 'data' => ['unit' => $flat->id]])->assertSessionHasNoErrors();
        $this->assertSame('being_repaired', $flat->fresh()->status);
        Record::query()->where('entity', 'repairs')->firstOrFail()->update(['status' => 'fixed']);
        $this->assertSame('let', $flat->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Rent not in yet')->assertSee('Flat 2');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Income by unit')->assertSee('Flat 1');
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
