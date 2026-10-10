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

/** Services: IT services, hosting billing, translation, recruitment agency, music studio and tattoo. */
class ServicesAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_managed_it_devices_have_unique_serials_and_credentials_rotate(): void
    {
        $app = 'it-services';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'clients']), ['title' => 'Acme', 'status' => 'managed', 'data' => ['users_covered' => 0]])
            ->assertSessionHasErrors(['data.users_covered' => 'Give the number of users covered.']);
        $acme = $this->record($workspace, $app, 'clients', 'Acme', 'onboarding', ['agreement' => 'fully_managed', 'renewal_date' => today()->addDays(10)->toDateString()]);
        $this->actingAs($owner)->post($acme->url().'/actions/go_live')->assertSessionHasErrors('users_covered');
        $acme->update(['data' => [...$acme->data, 'users_covered' => 12]]);
        $this->actingAs($owner)->post($acme->url().'/actions/go_live')->assertSessionHas('flash.message', 'Acme is now managed.');

        $old = $this->record($workspace, $app, 'clients', 'Old Co', 'ended');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'devices']), ['title' => 'PC', 'status' => 'active', 'data' => ['client' => $old->id, 'type' => 'desktop']])
            ->assertSessionHasErrors(['data.client' => 'Old Co has ended.']);
        $laptop = $this->record($workspace, $app, 'devices', 'Laptop 1', 'active', ['client' => $acme->id, 'type' => 'laptop', 'serial_number' => ' abc123 ', 'warranty_until' => today()->addDays(20)->toDateString()]);
        $this->assertSame('ABC123', $laptop->value('serial_number'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'devices']), ['title' => 'Laptop 2', 'status' => 'active', 'data' => ['client' => $acme->id, 'type' => 'laptop', 'serial_number' => 'Abc123']])
            ->assertSessionHasErrors(['data.serial_number' => 'Serial number ABC123 is already on Laptop 1.']);
        $this->actingAs($owner)->post($laptop->url().'/actions/repair')->assertSessionHas('flash.message', 'Laptop 1 sent for repair under warranty.');

        $note = $this->record($workspace, $app, 'credentials', 'Firewall admin', 'current', ['client' => $acme->id, 'username' => 'admin']);
        $this->actingAs($owner)->post($note->url().'/actions/rotate')->assertSessionHas('flash.message', 'Firewall admin rotated; a new current note is open.');
        $this->assertSame('rotated', $note->fresh()->status);
        $this->assertSame(1, Record::query()->where('entity', 'credentials')->where('status', 'current')->count());

        $this->actingAs($owner)->get($acme->url())->assertOk()->assertSee('Estate')->assertSee('Warranties ending in 60 days')->assertSee('Laptop 1');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Renewals in the next 30 days')->assertSee('Acme');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Managed clients')->assertSee('Fully managed');
    }

    public function test_domains_renew_or_expire_and_overdue_hosting_is_suspended(): void
    {
        $app = 'hosting-billing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'domains']), ['title' => 'not a domain', 'status' => 'active'])
            ->assertSessionHasErrors(['title' => 'Give a domain name like example.com.']);
        $example = $this->record($workspace, $app, 'domains', 'HTTPS://www.Example.com/', 'active', [], ['occurs_on' => today(), 'due_on' => null]);
        $this->assertSame('example.com', $example->title);
        $this->assertTrue($example->due_on->isSameDay(today()->addYear()));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'domains']), ['title' => 'www.example.com', 'status' => 'active'])
            ->assertSessionHasErrors(['title' => 'example.com is already listed.']);

        $renewing = $this->record($workspace, $app, 'domains', 'renew.co', 'active', ['auto_renew' => true], ['due_on' => today()->subDay()]);
        $lapsing = $this->record($workspace, $app, 'domains', 'lapse.co', 'active', ['auto_renew' => false], ['due_on' => today()->subDay()]);
        $site = $this->record($workspace, $app, 'hosting', 'site.co', 'active', ['plan' => 'shared'], ['due_on' => today()->subDays(10), 'amount' => 150]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertTrue($renewing->fresh()->due_on->isSameDay(today()->subDay()->addYear()));
        $this->assertSame('expired', $lapsing->fresh()->status);
        $this->assertSame('suspended', $site->fresh()->status);

        $this->actingAs($owner)->post($lapsing->url().'/actions/renew', ['years' => 2])
            ->assertSessionHas('flash.message', 'lapse.co renewed until '.today()->addYears(2)->format('d M Y').'.');
        $this->actingAs($owner)->post($site->url().'/actions/paid', ['months' => 1])
            ->assertSessionHas('flash.message', 'site.co paid until '.today()->subDays(10)->addMonthNoOverflow()->format('d M Y').' and reactivated.');
        $this->assertSame('active', $site->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Domains expiring in 30 days')->assertSee('Hosting overdue');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hosting by plan')->assertSee('Shared')->assertSee('Domain renewals by month');
    }

    public function test_translation_is_priced_by_the_word_and_sworn_work_is_proofread(): void
    {
        $app = 'translation';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Letter', 'status' => 'assigned', 'data' => ['type' => 'translation', 'source_language' => 'English', 'target_language' => 'english']])
            ->assertSessionHasErrors(['data.target_language' => 'The target language must differ from the source.', 'data.translator' => 'Give the translator.']);

        $contract = $this->record($workspace, $app, 'jobs', 'Contract', 'quoted', ['type' => 'sworn_translation', 'source_language' => 'English', 'target_language' => 'French', 'word_count' => 1000, 'rate' => 0.5], ['occurs_on' => today(), 'due_on' => today()->subDays(2)]);
        $this->assertEquals(500, $contract->amount);
        $this->actingAs($owner)->post($contract->url().'/actions/assign', ['translator' => $owner->id])->assertSessionHas('flash.message', 'Contract assigned to '.$owner->name.'.');
        $this->actingAs($owner)->post($contract->url().'/actions/start')->assertSessionHas('flash.message', 'Contract in translation.');
        $this->actingAs($owner)->post($contract->url().'/actions/proofread')->assertSessionHas('flash.message', 'Contract sent to proofreading.');
        $this->actingAs($owner)->post($contract->url().'/actions/deliver')->assertSessionHas('flash.message', 'Contract delivered, 2 days late.');

        $this->record($workspace, $app, 'jobs', 'Website', 'translating', ['type' => 'translation', 'source_language' => 'English', 'target_language' => 'Nyanja', 'word_count' => 2000, 'rate' => 0.25, 'translator' => $owner->id], ['occurs_on' => today(), 'due_on' => today()->addDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due in the next 3 days')->assertSee('Website')->assertSee('2,000');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Work by translator')->assertSee($owner->name)->assertSee('3,000')->assertSee('English → French');
    }

    public function test_placing_a_candidate_earns_the_fee_and_fills_the_vacancy(): void
    {
        $app = 'recruitment-agency';
        [$owner, $workspace] = $this->appWorkspace($app);
        $accountant = $this->record($workspace, $app, 'vacancies', 'Accountant', 'open', ['fee_percent' => 10, 'location' => 'Lusaka']);
        $driver = $this->record($workspace, $app, 'vacancies', 'Driver', 'filled');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), ['title' => 'Zed', 'status' => 'new', 'data' => ['vacancy' => $driver->id]])
            ->assertSessionHasErrors(['data.vacancy' => 'Driver is filled.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'candidates']), ['title' => 'Zed', 'status' => 'placed'])
            ->assertSessionHasErrors(['data.vacancy' => 'Give the vacancy the candidate was placed in.', 'data.expected_salary' => 'Give the agreed salary.']);

        $ann = $this->record($workspace, $app, 'candidates', 'Ann', 'offer', ['vacancy' => $accountant->id, 'expected_salary' => 100000], ['assignee_id' => $owner->id]);
        $ben = $this->record($workspace, $app, 'candidates', 'Ben', 'interview', ['vacancy' => $accountant->id]);
        $cara = $this->record($workspace, $app, 'candidates', 'Cara', 'new', ['vacancy' => $accountant->id]);
        $this->actingAs($owner)->post($cara->url().'/actions/advance')->assertSessionHas('flash.message', 'Cara moved to screened.');
        $this->actingAs($owner)->get($accountant->url())->assertOk()->assertSee('Pipeline')->assertSee('Ann');

        $this->actingAs($owner)->post($ann->url().'/actions/place', ['salary' => 120000])->assertSessionHas('flash.message', 'Ann placed; fee '.$this->money(12000).'.');
        $this->assertEquals(12000, $ann->fresh()->amount);
        $this->assertSame('filled', $accountant->fresh()->status);
        $this->assertSame('rejected', $ben->fresh()->status);
        $this->assertSame('rejected', $cara->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Fees this month')->assertSee($this->money(12000));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Placements by consultant')->assertSee($owner->name);
    }

    public function test_studio_rooms_and_engineers_cannot_be_double_booked(): void
    {
        $app = 'music-studio';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), ['title' => 'Band B', 'status' => 'booked', 'data' => ['type' => 'recording']])
            ->assertSessionHasErrors(['data.room' => 'Give the studio room.', 'data.start_time' => 'Give the start time.', 'data.hours' => 'Give the length of the session in hours.']);

        $bandA = $this->record($workspace, $app, 'sessions', 'Band A', 'booked', ['room' => 'Studio A', 'type' => 'recording', 'start_time' => '10:00', 'hours' => 2, 'engineer' => $owner->id], ['occurs_on' => today(), 'amount' => 200]);
        $session = fn (string $room, string $start) => ['title' => 'Band B', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['room' => $room, 'type' => 'mixing', 'start_time' => $start, 'hours' => 1, 'engineer' => $owner->id]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), $session('Studio A', '11:00'))
            ->assertSessionHasErrors(['data.start_time' => 'Studio A is booked for Band A from 10:00.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), $session('Studio B', '11:30'))
            ->assertSessionHasErrors(['data.engineer' => 'The engineer is with Band A from 10:00.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sessions']), $session('Studio B', '12:00'))->assertSessionHasNoErrors();

        $this->actingAs($owner)->post($bandA->url().'/actions/start')->assertSessionHas('flash.message', 'Band A is in session.');
        $this->actingAs($owner)->post($bandA->url().'/actions/finish', ['hours' => 3])->assertSessionHas('flash.message', 'Band A\'s session finished after 3 hours.');
        $this->assertEquals(300, $bandA->fresh()->amount);

        $album = $this->record($workspace, $app, 'projects', 'Album', 'pre_production', ['tracks' => 10]);
        $this->actingAs($owner)->post($album->url().'/actions/advance')->assertSessionHas('flash.message', 'Album moved to tracking.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Sessions today')->assertSee('Band B')->assertSee('In production');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Hours by room')->assertSee('Studio A')->assertSee($this->money(300));
    }

    public function test_tattoos_need_a_deposit_and_signed_forms_before_work_starts(): void
    {
        $app = 'tattoo';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), ['title' => 'Joe', 'status' => 'booked', 'data' => ['type' => 'tattoo']])
            ->assertSessionHasErrors(['data.deposit' => 'Take a deposit to book a tattoo.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bookings']), ['title' => 'Joe', 'status' => 'booked', 'amount' => 300, 'data' => ['type' => 'tattoo', 'deposit' => 500]])
            ->assertSessionHasErrors(['data.deposit' => 'The deposit is more than the price.']);

        $mia = $this->record($workspace, $app, 'bookings', 'Mia', 'consultation', ['type' => 'tattoo', 'artist' => $owner->id], ['amount' => 1000]);
        $this->actingAs($owner)->post($mia->url().'/actions/book', ['date' => today()->toDateString(), 'deposit' => 0])->assertSessionHasErrors('deposit');
        $this->actingAs($owner)->post($mia->url().'/actions/book', ['date' => today()->toDateString(), 'deposit' => 200])->assertSessionHas('flash.message', 'Mia booked for '.today()->format('d M Y').'.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Forms missing')->assertSee($this->money(200));
        $this->actingAs($owner)->post($mia->url().'/actions/start')->assertSessionHasErrors(['status' => 'The consent form must be signed before work starts.']);
        $mia->refresh()->update(['data' => [...$mia->data, 'consent_signed' => true]]);
        $this->actingAs($owner)->post($mia->url().'/actions/start')->assertSessionHasErrors(['status' => 'Verify the client\'s age before work starts.']);
        $mia->refresh()->update(['data' => [...$mia->data, 'over_18_verified' => true]]);
        $this->actingAs($owner)->post($mia->url().'/actions/start')->assertSessionHas('flash.message', 'Work on Mia started.');
        $this->actingAs($owner)->post($mia->url().'/actions/finish')->assertSessionHas('flash.message', 'Mia finished; '.$this->money(800).' to pay after the deposit.');

        $this->actingAs($owner)->post($mia->url().'/actions/touch_up')
            ->assertSessionHas('flash.message', 'Touch-up for Mia booked for '.today()->addWeeks(6)->format('d M Y').'.');
        $touchUp = Record::query()->where('entity', 'bookings')->whereKeyNot($mia->id)->sole();
        $this->assertSame('touch_up', $touchUp->value('type'));
        $this->assertSame('booked', $touchUp->status);
        $this->assertSame('touch_up', $mia->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Work by artist')->assertSee($owner->name)->assertSee($this->money(1000));
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
