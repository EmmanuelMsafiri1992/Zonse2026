<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class GovernmentAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function appWorkspace(string $app): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules([$app], $owner);
        app(WorkspaceContext::class)->set($workspace);

        return [$owner, $workspace->fresh()];
    }

    /** @param  array<string, mixed>  $data  @param  array<string, mixed>  $attributes */
    protected function record(Workspace $workspace, string $app, string $entity, string $title, string $status, array $data = [], array $attributes = []): Record
    {
        return Record::factory()->ofEntity($app, $entity, $data)->create(['workspace_id' => $workspace->id, 'title' => $title, 'status' => $status, ...$attributes]);
    }

    public function test_occurrence_book_opens_dockets_books_exhibits_and_finalises_only_once_exhibits_are_released(): void
    {
        $app = 'police-occurrence-book-case';
        [$owner, $workspace] = $this->appWorkspace($app);
        $entry = $this->record($workspace, $app, 'occurrences', 'Housebreaking at Kabulonga', 'recorded', ['ob_number' => 'ob 14/10/2026', 'reported_by' => 'Mutale Banda', 'location' => 'Kabulonga', 'details' => 'Door forced, TV taken.'], ['occurs_on' => today()]);
        $this->assertSame('OB 14/10/2026', $entry->value('ob_number'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'occurrences']), [
            'title' => 'Same entry', 'status' => 'recorded', 'occurs_on' => today()->toDateString(), 'data' => ['ob_number' => 'OB 14/10/2026', 'details' => 'Duplicate'],
        ])->assertSessionHasErrors('data.ob_number');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'occurrences']), [
            'title' => 'Tomorrow', 'status' => 'recorded', 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['ob_number' => 'OB 15/10/2026', 'details' => 'Not yet'],
        ])->assertSessionHasErrors('occurs_on');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'occurrences', $entry->id, 'open_docket']), ['title' => 'Housebreaking and theft', 'docket_number' => 'cas 101/10/2026'])
            ->assertSessionHas('flash.message', 'Docket CAS 101/10/2026 opened from OB 14/10/2026.');
        $this->assertSame('docket_opened', $entry->fresh()->status);
        $docket = Record::query()->ofEntity($app, 'dockets')->firstOrFail();
        $this->assertSame('Mutale Banda', $docket->value('complainant'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'dockets', $docket->id, 'book_exhibit']), ['title' => 'Crowbar', 'exhibit_number' => 'e1', 'storage_location' => 'SAP 13 store'])
            ->assertSessionHas('flash.message', 'Crowbar booked in to SAP 13 store.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exhibits']), [
            'title' => 'Fingerprint lift', 'status' => 'booked_in', 'data' => ['docket' => $docket->id],
        ])->assertSessionHasErrors('data.storage_location');
        $this->assertSame(1, $docket->fresh()->value('_exhibits_held'));
        $crowbar = Record::query()->ofEntity($app, 'exhibits')->firstOrFail();
        $this->assertSame('E1', $crowbar->value('exhibit_number'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'dockets', $docket->id, 'to_prosecutor']))->assertSessionHas('flash.message', 'Docket CAS 101/10/2026 sent to the prosecutor.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'dockets', $docket->id, 'enrol']))->assertSessionHas('flash.message', 'Docket CAS 101/10/2026 enrolled in court.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exhibits', $crowbar->id, 'to_court']))->assertSessionHas('flash.message', 'Crowbar taken to court.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'dockets', $docket->id, 'finalise']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'exhibits', $crowbar->id, 'dispose']))->assertSessionHas('flash.message', 'Crowbar disposed of.');
        $this->assertNotNull($crowbar->fresh()->value('_released_on'));
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'dockets', $docket->id, 'finalise']))->assertSessionHas('flash.message', 'Docket CAS 101/10/2026 finalised.');
        $this->assertSame(0, $docket->fresh()->value('_exhibits_held'));
        $this->assertNull($docket->fresh()->value('_days_open'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'exhibits']), [
            'title' => 'Late exhibit', 'status' => 'booked_in', 'data' => ['docket' => $docket->id, 'storage_location' => 'SAP 13 store'],
        ])->assertSessionHasErrors('data.docket');

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Oldest open dockets');
        $this->actingAs($owner)->get($docket->url())->assertOk()->assertSee('Crowbar');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Exhibits held by store');
    }

    public function test_land_registry_transfers_titles_from_the_registered_owner_one_at_a_time_and_blocks_encumbered_titles(): void
    {
        $app = 'land-registry-title-deeds';
        [$owner, $workspace] = $this->appWorkspace($app);
        $title = $this->record($workspace, $app, 'titles', 'Erf 4521', 'registered', ['title_number' => 't 1203/2019', 'owner' => 'Grace Banda', 'extent' => 900, 'location' => 'Woodlands']);
        $this->assertSame('T 1203/2019', $title->value('title_number'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'titles']), [
            'title' => 'Erf 4522', 'status' => 'registered', 'data' => ['title_number' => 'T 1203/2019', 'owner' => 'Someone'],
        ])->assertSessionHasErrors('data.title_number');

        $transfer = ['title' => 'Sale of erf 4521', 'status' => 'lodged', 'amount' => 2500, 'data' => ['title' => $title->id, 'to_owner' => 'John Phiri', 'conveyancer' => 'Mwale & Co', 'consideration' => 850000]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'data' => [...$transfer['data'], 'from_owner' => 'Peter Zulu']])->assertSessionHasErrors('data.from_owner');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'data' => [...$transfer['data'], 'from_owner' => 'grace banda', 'to_owner' => 'Grace Banda']])->assertSessionHasErrors('data.to_owner');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'data' => [...$transfer['data'], 'from_owner' => 'Grace Banda']])->assertSessionHasNoErrors();
        $this->assertTrue($title->fresh()->value('_pending_transfer'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'title' => 'Second sale', 'data' => [...$transfer['data'], 'from_owner' => 'Grace Banda', 'to_owner' => 'Ruth Tembo']])->assertSessionHasErrors('data.title');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'titles', $title->id, 'encumber']), ['bonds' => 'Mortgage bond, Zanaco'])->assertSessionHasErrors('bonds');

        $sale = Record::query()->ofEntity($app, 'transfers')->firstOrFail();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'transfers', $sale->id, 'examine']))->assertSessionHas('flash.message', 'Sale of erf 4521 examined.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'transfers', $sale->id, 'register']))->assertSessionHas('flash.message', 'Title T 1203/2019 registered to John Phiri.');
        $title->refresh();
        $this->assertSame('John Phiri', $title->value('owner'));
        $this->assertSame(1, $title->value('_transfers'));
        $this->assertFalse($title->value('_pending_transfer'));
        $this->assertEquals(850000, $title->value('_last_price'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'titles', $title->id, 'encumber']), ['bonds' => 'Mortgage bond, Zanaco'])->assertSessionHas('flash.message', 'Bond registered over T 1203/2019.');
        $this->assertSame('encumbered', $title->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'title' => 'Resale', 'data' => [...$transfer['data'], 'from_owner' => 'John Phiri', 'to_owner' => 'Ruth Tembo']])->assertSessionHasErrors('data.title');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'titles', $title->id, 'cancel_bond']))->assertSessionHas('flash.message', 'Bond over T 1203/2019 cancelled.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transfers']), [...$transfer, 'title' => 'Resale', 'data' => [...$transfer['data'], 'from_owner' => 'John Phiri', 'to_owner' => 'Ruth Tembo']])->assertSessionHasNoErrors();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Transfers in progress')->assertSee('Resale');
        $this->actingAs($owner)->get($title->url())->assertOk()->assertSee('Transfer history');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Transfers by conveyancer');
    }

    public function test_civil_registry_numbers_registrations_flags_late_ones_and_counts_certificates_and_amendments(): void
    {
        $app = 'civil-registry';
        [$owner, $workspace] = $this->appWorkspace($app);
        $year = today()->format('Y');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Thandiwe Mulenga', 'status' => 'registered', 'occurs_on' => today()->toDateString(), 'amount' => 50, 'data' => ['type' => 'birth', 'event_date' => today()->subYears(2)->toDateString(), 'place' => 'Ndola'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Baby Chanda', 'status' => 'registered', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'birth', 'event_date' => today()->subWeek()->toDateString(), 'place' => 'Ndola'],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Not yet born', 'status' => 'registered', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'birth', 'event_date' => today()->addDay()->toDateString()],
        ])->assertSessionHasErrors('data.event_date');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'registrations']), [
            'title' => 'Copied number', 'status' => 'registered', 'occurs_on' => today()->toDateString(), 'data' => ['type' => 'death', 'event_date' => today()->toDateString(), 'registration_number' => 'b'.$year.'-00001'],
        ])->assertSessionHasErrors('data.registration_number');

        $late = Record::query()->ofEntity($app, 'registrations')->where('title', 'Thandiwe Mulenga')->firstOrFail();
        $prompt = Record::query()->ofEntity($app, 'registrations')->where('title', 'Baby Chanda')->firstOrFail();
        $this->assertSame('B'.$year.'-00001', $late->value('registration_number'));
        $this->assertSame('B'.$year.'-00002', $prompt->value('registration_number'));
        $this->assertTrue($late->value('_late'));
        $this->assertFalse($prompt->value('_late'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $late->id, 'issue_certificate']), ['copies' => 2])->assertSessionHas('flash.message', '2 certificates issued for B'.$year.'-00001.');
        $this->assertSame('certificate_issued', $late->fresh()->status);
        $this->assertSame(2, $late->fresh()->value('_certificates'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $late->id, 'amend']), ['title' => 'Thandiwe Mulenga', 'reason' => 'None'])->assertSessionHasErrors('title');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $late->id, 'amend']), ['title' => 'Thandiwe Grace Mulenga'])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $late->id, 'amend']), ['title' => 'Thandiwe Grace Mulenga', 'reason' => 'Middle name left out'])->assertSessionHas('flash.message', 'B'.$year.'-00001 amended.');
        $late->refresh();
        $this->assertSame('Thandiwe Grace Mulenga', $late->title);
        $this->assertSame('amended', $late->status);
        $this->assertSame(1, $late->value('_amendments'));
        $this->assertSame('Thandiwe Mulenga', $late->value('_amendment_log')[0]['from']);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $prompt->id, 'cancel']))->assertSessionHas('flash.message', 'Registration B'.$year.'-00002 cancelled.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'registrations', $prompt->id, 'issue_certificate']), ['copies' => 1])->assertNotFound();

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Latest registrations');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Vital statistics by month');
    }

    public function test_tenders_take_bids_while_open_close_by_themselves_and_award_the_best_compliant_bid(): void
    {
        $app = 'e-procurement-tender-portal';
        [$owner, $workspace] = $this->appWorkspace($app);
        $tender = $this->record($workspace, $app, 'tenders', 'Rehabilitate Lumumba Road', 'draft', ['tender_number' => 'ten/01/2026', 'category' => 'Roads', 'budget' => 1000000]);
        $this->assertSame('TEN/01/2026', $tender->value('tender_number'));

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'tenders']), [
            'title' => 'Twin', 'status' => 'draft', 'data' => ['tender_number' => 'TEN/01/2026'],
        ])->assertSessionHasErrors('data.tender_number');

        $bid = fn (string $bidder, array $scores, float $price, bool $tax) => ['title' => $bidder, 'status' => 'received', 'amount' => $price, 'data' => ['tender' => $tender->id, ...$scores, 'tax_compliant' => $tax ? '1' : '0']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Early Bird', ['technical_score' => 50], 500000, true))->assertSessionHasErrors('data.tender');

        $closes = today()->addDays(21);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $tender->id, 'publish']), ['due_on' => $closes->toDateString()])
            ->assertSessionHas('flash.message', 'Tender TEN/01/2026 published; closes '.$closes->format('d M Y').'.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Overscored', ['technical_score' => 80], 500000, true))->assertSessionHasErrors('data.technical_score');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Alpha Builders', ['technical_score' => 60, 'price_score' => 15, 'preference_points' => 8], 900000, true))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Beta Construction', ['technical_score' => 65, 'price_score' => 18, 'preference_points' => 5], 850000, false))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Gamma Roads', ['technical_score' => 50, 'price_score' => 20, 'preference_points' => 10], 700000, true))->assertSessionHasNoErrors();

        $alpha = Record::query()->ofEntity($app, 'bids')->where('title', 'Alpha Builders')->firstOrFail();
        $beta = Record::query()->ofEntity($app, 'bids')->where('title', 'Beta Construction')->firstOrFail();
        $gamma = Record::query()->ofEntity($app, 'bids')->where('title', 'Gamma Roads')->firstOrFail();
        $this->assertEquals(83, $alpha->value('_total_score'));
        $this->assertSame(3, $tender->fresh()->value('_bids'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bids', $beta->id, 'compliant']))->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bids', $beta->id, 'non_compliant']), ['reason' => 'No tax clearance'])->assertSessionHas('flash.message', 'Beta Construction is non-compliant: No tax clearance.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bids', $alpha->id, 'compliant']))->assertSessionHas('flash.message', 'Alpha Builders is compliant.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'bids', $gamma->id, 'compliant']))->assertSessionHas('flash.message', 'Gamma Roads is compliant.');
        $this->assertEquals(700000, $tender->fresh()->value('_lowest_price'));

        $tender->fresh()->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $tender->fresh()->status);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'bids']), $bid('Too Late Ltd', ['technical_score' => 70], 600000, true))->assertSessionHasErrors('data.tender');

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $tender->id, 'award']))->assertNotFound();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $tender->id, 'evaluate']))->assertSessionHas('flash.message', 'Evaluating tender TEN/01/2026.');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'tenders', $tender->id, 'award']))->assertSessionHas('flash.message', 'Tender TEN/01/2026 awarded to Alpha Builders with 83 points.');
        $this->assertSame('awarded', $alpha->fresh()->status);
        $this->assertSame('unsuccessful', $gamma->fresh()->status);
        $this->assertSame('non_compliant', $beta->fresh()->status);
        $this->assertSame('Alpha Builders', $tender->fresh()->value('_winner'));
        $this->assertEquals(900000, $tender->fresh()->value('_award_value'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Closing soon');
        $this->actingAs($owner)->get($tender->url())->assertOk()->assertSee('Bid ranking');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Bidders');
    }

    public function test_vehicle_licences_expire_fines_turn_into_warrants_and_warrants_block_renewal(): void
    {
        $app = 'traffic-fines-vehicle-licensing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $car = $this->record($workspace, $app, 'vehicles', 'abc  123 gp', 'licensed', ['owner' => 'Bwalya Mumba', 'make_model' => 'Toyota Corolla', 'licence_expiry' => today()->subDay()->toDateString()], ['amount' => 450]);
        $this->assertSame('ABC 123 GP', $car->title);
        $this->assertSame('expired', $car->status);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'vehicles']), [
            'title' => 'Abc 123 gp', 'status' => 'licensed', 'data' => ['owner' => 'Someone', 'licence_expiry' => today()->addYear()->toDateString()],
        ])->assertSessionHasErrors('title');

        $fine = ['status' => 'issued', 'occurs_on' => today()->subDays(40)->toDateString(), 'data' => ['vehicle' => $car->id, 'offence' => 'speeding', 'location' => 'Great East Road']];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fines']), [...$fine, 'title' => 'N-0001'])->assertSessionHasErrors('amount');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fines']), [...$fine, 'title' => 'N-0001', 'amount' => 600])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'fines']), [...$fine, 'title' => 'N-0002', 'amount' => 300, 'occurs_on' => today()->toDateString(), 'data' => [...$fine['data'], 'offence' => 'parking']])->assertSessionHasNoErrors();
        $old = Record::query()->ofEntity($app, 'fines')->where('title', 'N-0001')->firstOrFail();
        $recent = Record::query()->ofEntity($app, 'fines')->where('title', 'N-0002')->firstOrFail();
        $this->assertSame(today()->subDays(10)->toDateString(), $old->due_on->toDateString());
        $this->assertSame(2, $car->fresh()->value('_unpaid_fines'));
        $this->assertEquals(900, $car->fresh()->value('_unpaid_amount'));

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('warrant', $old->fresh()->status);
        $this->assertSame('issued', $recent->fresh()->status);
        $this->assertSame(1, $car->fresh()->value('_warrants'));

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'vehicles', $car->id, 'renew']), ['amount' => 450])->assertSessionHasErrors('status');
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fines', $old->id, 'pay']))->assertSessionHas('flash.message', 'Fine N-0001 paid.');
        $this->assertSame(0, $car->fresh()->value('_warrants'));
        $renewed = today()->addYear();
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'vehicles', $car->id, 'renew']), ['amount' => 450])->assertSessionHas('flash.message', 'ABC 123 GP licensed until '.$renewed->format('d M Y').'.');
        $this->assertSame('licensed', $car->fresh()->status);

        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fines', $recent->id, 'contest']), ['reason' => 'Loading zone was signposted'])->assertSessionHas('flash.message', 'Fine N-0002 contested.');
        $payBy = today()->addDays(14);
        $this->actingAs($owner)->post(route('apps.records.action', [$app, 'fines', $recent->id, 'uphold']), ['due_on' => $payBy->toDateString()])->assertSessionHas('flash.message', 'Fine N-0002 upheld; pay by '.$payBy->format('d M Y').'.');
        $this->assertSame('issued', $recent->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Licences expiring');
        $this->actingAs($owner)->get($car->url())->assertOk()->assertSee('N-0002');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Fines by offence');
    }
}
