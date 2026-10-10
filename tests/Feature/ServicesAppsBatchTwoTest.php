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

/** Services: cleaning, consultancy, car wash, tailoring, print shop and pet grooming. */
class ServicesAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_cleaning_jobs_book_the_next_visit_and_missed_visits_are_marked(): void
    {
        $app = 'cleaning-home-services';
        [$owner, $workspace] = $this->appWorkspace($app);
        $phiri = $this->record($workspace, $app, 'customers', 'Mrs Phiri', 'active', ['address' => '12 Leopards Hill', 'frequency' => 'weekly']);
        $lungu = $this->record($workspace, $app, 'customers', 'Mr Lungu', 'paused', ['address' => '4 Chalala', 'frequency' => 'monthly']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Clean', 'status' => 'scheduled', 'data' => ['customer' => $lungu->id, 'service' => 'standard_clean']])
            ->assertSessionHasErrors(['data.customer' => 'Mr Lungu is paused.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Clean', 'status' => 'done', 'data' => ['customer' => $phiri->id, 'service' => 'standard_clean']])
            ->assertSessionHasErrors(['data.hours' => 'Give the hours worked.']);

        $clean = $this->record($workspace, $app, 'jobs', 'Weekly clean', 'scheduled', ['customer' => $phiri->id, 'service' => 'standard_clean', 'start_time' => '08:00'], ['occurs_on' => today(), 'assignee_id' => $owner->id, 'amount' => 300]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Windows', 'status' => 'scheduled', 'occurs_on' => today()->toDateString(), 'assignee_id' => $owner->id, 'data' => ['customer' => $phiri->id, 'service' => 'windows', 'start_time' => '08:00']])
            ->assertSessionHasErrors(['data.start_time' => 'This cleaner already has Weekly clean at 08:00.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Today\'s jobs')->assertSee('Weekly clean');

        $this->actingAs($owner)->post($clean->url().'/actions/start')->assertSessionHas('flash.message', 'Weekly clean started.');
        $this->actingAs($owner)->post($clean->url().'/actions/done')->assertSessionHasErrors(['hours' => 'Give the hours worked.']);
        $this->actingAs($owner)->post($clean->url().'/actions/done', ['hours' => 3])->assertSessionHas('flash.message', 'Weekly clean done; next visit booked for '.today()->addDays(7)->format('d M Y').'.');
        $next = Record::query()->where('entity', 'jobs')->where('status', 'scheduled')->sole();
        $this->assertEquals(300, $next->amount);
        $this->actingAs($owner)->get($phiri->url())->assertOk()->assertSee('Next visit')->assertSee(today()->addDays(7)->format('d M Y'));

        $this->travel(8)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $next->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by cleaner')->assertSee($owner->name)->assertSee($this->money(300));
    }

    public function test_consultancy_deliverables_need_client_sign_off_before_the_engagement_closes(): void
    {
        $app = 'consultancy-client-portals';
        [$owner, $workspace] = $this->appWorkspace($app);
        $engagement = $this->record($workspace, $app, 'engagements', 'Strategy review', 'active', ['scope' => 'Review', 'billing' => 'fixed_fee'], ['amount' => 50000, 'occurs_on' => today()]);
        $old = $this->record($workspace, $app, 'engagements', 'Old work', 'completed', ['scope' => 'Done']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliverables']), ['title' => 'Memo', 'status' => 'not_started', 'data' => ['engagement' => $old->id]])
            ->assertSessionHasErrors(['data.engagement' => 'Old work is completed.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliverables']), ['title' => 'Memo', 'status' => 'with_client', 'data' => ['engagement' => $engagement->id]])
            ->assertSessionHasErrors(['data.file_url' => 'Add the file link before sending it to the client.']);

        $report = $this->record($workspace, $app, 'deliverables', 'Report', 'in_progress', ['engagement' => $engagement->id]);
        $deck = $this->record($workspace, $app, 'deliverables', 'Deck', 'not_started', ['engagement' => $engagement->id]);
        $this->actingAs($owner)->post($report->url().'/actions/send')->assertSessionHasErrors(['file_url' => 'Add the file link before sending it to the client.']);
        $this->actingAs($owner)->post($report->url().'/actions/send', ['file_url' => 'https://example.com/report'])->assertSessionHas('flash.message', 'Report sent to the client.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting on clients')->assertSee('Report');
        $this->actingAs($owner)->post($report->url().'/actions/reject')->assertSessionHasErrors(['client_feedback' => 'Record the client\'s feedback.']);
        $this->actingAs($owner)->post($report->url().'/actions/reject', ['client_feedback' => 'Add costs'])->assertSessionHas('flash.message', 'Report rejected by the client.');
        $this->actingAs($owner)->post($report->url().'/actions/work')->assertSessionHas('flash.message', 'Report in progress.');
        $this->actingAs($owner)->post($report->url().'/actions/send', ['file_url' => 'https://example.com/report-2']);
        $this->actingAs($owner)->post($report->url().'/actions/approve')->assertSessionHas('flash.message', 'Report approved; 1 still to approve on Strategy review.');
        $this->actingAs($owner)->post($engagement->url().'/actions/complete')->assertSessionHasErrors(['status' => 'Strategy review still has 1 deliverable not approved.']);

        $this->actingAs($owner)->post($deck->url().'/actions/work');
        $this->actingAs($owner)->post($deck->url().'/actions/send', ['file_url' => 'https://example.com/deck']);
        $this->actingAs($owner)->post($deck->url().'/actions/approve')->assertSessionHas('flash.message', 'Deck approved; every deliverable on Strategy review is approved.');
        $this->actingAs($owner)->get($engagement->url())->assertOk()->assertSee('Deliverables: 2 of 2 approved');
        $this->actingAs($owner)->post($engagement->url().'/actions/complete')->assertSessionHas('flash.message', 'Strategy review completed.');

        $other = $this->record($workspace, $app, 'engagements', 'Audit', 'active', ['scope' => 'Audit']);
        $this->record($workspace, $app, 'deliverables', 'Findings memo', 'not_started', ['engagement' => $other->id], ['due_on' => today()->subDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Overdue deliverables')->assertSee('Findings memo');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Engagements')->assertSee('Strategy review')->assertSee($this->money(50000));
    }

    public function test_car_washes_charge_the_package_and_earn_washer_commission(): void
    {
        $app = 'car-wash';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'packages']), ['title' => 'Quick', 'status' => 'active', 'data' => ['vehicle_size' => 'sedan', 'price' => 50, 'commission' => 60]])
            ->assertSessionHasErrors(['data.commission' => 'The commission can\'t be more than the price.']);
        $valet = $this->record($workspace, $app, 'packages', 'Full valet SUV', 'active', ['vehicle_size' => 'suv', 'price' => 200, 'commission' => 40]);
        $retired = $this->record($workspace, $app, 'packages', 'Old wash', 'retired', ['vehicle_size' => 'sedan', 'price' => 30]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'washes']), ['title' => 'ABC 1', 'status' => 'waiting', 'data' => ['package' => $retired->id]])
            ->assertSessionHasErrors(['data.package' => 'Old wash is retired.']);

        $wash = $this->record($workspace, $app, 'washes', 'abz  123', 'waiting', ['package' => $valet->id]);
        $this->assertSame('ABZ 123', $wash->title);
        $this->assertEquals(200, $wash->amount);
        $this->assertEquals(40, $wash->value('_commission'));
        $this->actingAs($owner)->post($wash->url().'/actions/start')->assertSessionHas('flash.message', 'ABZ 123 is being washed by '.$owner->name.'.');
        $this->actingAs($owner)->post($wash->url().'/actions/done')->assertSessionHas('flash.message', 'ABZ 123 is done.');
        $this->actingAs($owner)->post($wash->url().'/actions/pay', ['payment' => 'mobile_money'])->assertSessionHas('flash.message', $this->money(200).' paid for ABZ 123 by mobile money.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Taken')->assertSee($this->money(200));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Washer commissions')->assertSee($owner->name)->assertSee($this->money(40))->assertSee('Full valet SUV');
    }

    public function test_tailoring_orders_need_current_measurements_and_move_through_fittings(): void
    {
        $app = 'tailoring';
        [$owner, $workspace] = $this->appWorkspace($app);
        $oldCard = $this->record($workspace, $app, 'measurements', 'Grace', 'current', ['chest' => 90]);
        $card = $this->record($workspace, $app, 'measurements', 'Grace', 'current', ['chest' => 92]);
        $this->assertSame('outdated', $oldCard->fresh()->status);
        $this->assertSame('current', $card->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Suit', 'status' => 'received', 'data' => ['type' => 'new_garment']])
            ->assertSessionHasErrors(['data.measurements' => 'A new garment needs a measurement card.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Suit', 'status' => 'received', 'data' => ['type' => 'new_garment', 'measurements' => $oldCard->id]])
            ->assertSessionHasErrors(['data.measurements' => 'Grace\'s measurements are outdated; take new ones first.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Hem', 'status' => 'received', 'amount' => 500, 'data' => ['type' => 'alteration', 'deposit' => 600]])
            ->assertSessionHasErrors(['data.deposit' => 'The deposit can\'t be more than the price.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Hem', 'status' => 'received', 'due_on' => today()->addDays(2)->toDateString(), 'data' => ['type' => 'alteration', 'fitting_date' => today()->addDays(5)->toDateString()]])
            ->assertSessionHasErrors(['data.fitting_date' => 'The fitting must be on or before the ready-by date.']);

        $suit = $this->record($workspace, $app, 'orders', 'Suit', 'received', ['type' => 'new_garment', 'measurements' => $card->id, 'deposit' => 200, 'fitting_date' => today()->addDays(3)->toDateString()], ['amount' => 1000, 'due_on' => today()->addDays(7)]);
        $this->actingAs($owner)->post($suit->url().'/actions/next')->assertSessionHas('flash.message', 'Suit is being cut.');
        $this->actingAs($owner)->post($suit->url().'/actions/next')->assertSessionHas('flash.message', 'Suit is being sewn.');
        $this->actingAs($owner)->post($suit->url().'/actions/next')->assertSessionHas('flash.message', 'Suit is ready for fitting on '.today()->addDays(3)->format('d M Y').'.');
        $this->actingAs($owner)->post($suit->url().'/actions/next')->assertSessionHas('flash.message', 'Suit is ready for collection.');
        $this->actingAs($owner)->get($suit->url())->assertOk()->assertSee('Balance')->assertSee($this->money(800));
        $this->actingAs($owner)->post($suit->url().'/actions/collect')->assertSessionHas('flash.message', 'Suit collected; '.$this->money(800).' balance taken.');
        $this->actingAs($owner)->get($card->url())->assertOk()->assertSee('Orders on this card')->assertSee('Suit');

        $this->record($workspace, $app, 'orders', 'Dress', 'cutting', ['type' => 'new_garment', 'measurements' => $card->id, 'fitting_date' => today()->addDays(2)->toDateString()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Fittings in the next 7 days')->assertSee('Dress');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Orders by type')->assertSee('New garment');
    }

    public function test_print_jobs_need_an_approved_proof_and_a_deposit_before_printing(): void
    {
        $app = 'printing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Cards', 'status' => 'quote', 'data' => ['product' => 'business_cards', 'quantity' => 0]])
            ->assertSessionHasErrors(['data.quantity' => 'The quantity must be at least 1.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'jobs']), ['title' => 'Cards', 'status' => 'printing', 'data' => ['product' => 'business_cards', 'quantity' => 100, 'artwork_url' => 'https://example.com/a']])
            ->assertSessionHasErrors(['data.proof_approved' => 'The customer must approve the proof first.']);

        $flyers = $this->record($workspace, $app, 'jobs', 'Flyers', 'quote', ['product' => 'flyers', 'quantity' => 500], ['amount' => 1000, 'due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->post($flyers->url().'/actions/accept')->assertSessionHas('flash.message', 'Flyers: quote accepted, on to artwork.');
        $this->actingAs($owner)->post($flyers->url().'/actions/send_proof')->assertSessionHasErrors(['artwork_url' => 'Add the artwork link first.']);
        $this->actingAs($owner)->post($flyers->url().'/actions/send_proof', ['artwork_url' => 'https://example.com/flyer-1'])->assertSessionHas('flash.message', 'Proof for Flyers sent.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting on proof approval');
        $this->actingAs($owner)->post($flyers->url().'/actions/reject')->assertSessionHas('flash.message', 'Flyers is back in artwork.');
        $this->actingAs($owner)->post($flyers->url().'/actions/send_proof', ['artwork_url' => 'https://example.com/flyer-2']);
        $this->actingAs($owner)->post($flyers->url().'/actions/approve')->assertSessionHas('flash.message', 'Proof for Flyers approved.');
        $this->actingAs($owner)->post($flyers->url().'/actions/print', ['deposit' => 300])->assertSessionHasErrors(['deposit' => 'Take a deposit of at least '.$this->money(500).' before printing.']);
        $this->actingAs($owner)->post($flyers->url().'/actions/print', ['deposit' => 500])->assertSessionHas('flash.message', 'Flyers is printing.');
        $this->actingAs($owner)->post($flyers->url().'/actions/finish')->assertSessionHas('flash.message', 'Flyers is in finishing.');
        $this->actingAs($owner)->post($flyers->url().'/actions/ready')->assertSessionHas('flash.message', 'Flyers is ready.');
        $this->actingAs($owner)->post($flyers->url().'/actions/collect')->assertSessionHas('flash.message', 'Flyers collected; '.$this->money(500).' balance taken.');

        $this->record($workspace, $app, 'jobs', 'Shop banner', 'artwork', ['product' => 'banners', 'quantity' => 1], ['due_on' => today()->addDay()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Due in the next 3 days')->assertSee('Shop banner');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Jobs by product')->assertSee('Banners')->assertSee($this->money(1000));
    }

    public function test_pet_stays_need_valid_vaccinations_and_visits_are_tracked(): void
    {
        $app = 'pet-grooming';
        [$owner, $workspace] = $this->appWorkspace($app);
        $rex = $this->record($workspace, $app, 'pets', 'Rex', 'active', ['species' => 'dog', 'vaccinations_until' => today()->addDays(5)->toDateString()]);
        $tom = $this->record($workspace, $app, 'pets', 'Old Tom', 'inactive', ['species' => 'cat']);
        $bella = $this->record($workspace, $app, 'pets', 'Bella', 'active', ['species' => 'dog']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), ['title' => 'Groom', 'status' => 'booked', 'data' => ['pet' => $tom->id, 'type' => 'full_groom']])
            ->assertSessionHasErrors(['data.pet' => 'Old Tom is inactive.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), ['title' => 'Stay', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'due_on' => today()->toDateString(), 'data' => ['pet' => $rex->id, 'type' => 'boarding']])
            ->assertSessionHasErrors(['due_on' => 'A boarding stay needs a collect-by date after the drop-off date.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), ['title' => 'Stay', 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'due_on' => today()->addDays(10)->toDateString(), 'data' => ['pet' => $rex->id, 'type' => 'boarding']])
            ->assertSessionHasErrors(['data.pet' => 'Rex\'s vaccinations run out on '.today()->addDays(5)->format('d M Y').'.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'appointments']), ['title' => 'Day', 'status' => 'booked', 'data' => ['pet' => $bella->id, 'type' => 'daycare']])
            ->assertSessionHasErrors(['data.pet' => 'Bella has no vaccination record.']);

        $groom = $this->record($workspace, $app, 'appointments', 'Full groom', 'booked', ['pet' => $rex->id, 'type' => 'full_groom'], ['amount' => 250]);
        $this->actingAs($owner)->post($groom->url().'/actions/check_in')->assertSessionHas('flash.message', 'Rex checked in.');
        $this->actingAs($owner)->post($groom->url().'/actions/start')->assertSessionHas('flash.message', 'Rex is being looked after.');
        $this->actingAs($owner)->post($groom->url().'/actions/ready')->assertSessionHas('flash.message', 'Rex is ready to go home.');
        $this->actingAs($owner)->post($groom->url().'/actions/collect')->assertSessionHas('flash.message', 'Rex collected.');

        $stay = $this->record($workspace, $app, 'appointments', 'Weekend boarding', 'booked', ['pet' => $rex->id, 'type' => 'boarding'], ['due_on' => today()->addDays(3)]);
        $this->actingAs($owner)->post($stay->url().'/actions/check_in')->assertSessionHas('flash.message', 'Rex checked in.');
        $this->record($workspace, $app, 'appointments', 'Bath', 'booked', ['pet' => $bella->id, 'type' => 'bath_brush', 'drop_off' => '09:00'], ['occurs_on' => today()]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Pets on site')->assertSee('Arriving today')->assertSee('Bella');
        $this->actingAs($owner)->get($rex->url())->assertOk()->assertSee('Visits')->assertSee('Full groom')->assertSee('Weekend boarding');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Visits by service')->assertSee('Full groom')->assertSee($this->money(250));
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
