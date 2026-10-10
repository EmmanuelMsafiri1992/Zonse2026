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

/** Property: repairs, listings and viewings, rent collection, estate levies, short stays, plot sales and home loans. */
class PropertyAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_repair_requests_get_a_fix_by_date_from_urgency_and_track_days_to_fix(): void
    {
        $app = 'maintenance-requests';
        [$owner, $workspace] = $this->appWorkspace($app);
        $leak = $this->record($workspace, $app, 'requests', 'Burst geyser', 'reported', ['location' => 'Flat 2', 'category' => 'plumbing', 'urgency' => 'urgent'], ['occurs_on' => today()->subDays(3)]);
        $this->assertTrue($leak->due_on->isSameDay(today()->subDays(2)));
        $this->record($workspace, $app, 'requests', 'Loose tile', 'reported', ['location' => 'Flat 4', 'urgency' => 'low']);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Light out', 'status' => 'assigned', 'data' => ['location' => 'Hall', 'urgency' => 'normal']])
            ->assertSessionHasErrors(['data.contractor' => 'Name the contractor or pick who is fixing it.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Past fix-by date (1)')->assertSee('Burst geyser');

        $this->actingAs($owner)->post($leak->url().'/actions/assign', ['contractor' => ''])->assertSessionHasErrors(['contractor' => 'Name the contractor or pick who is fixing it.']);
        $this->actingAs($owner)->post($leak->url().'/actions/assign', ['contractor' => 'Pipe Pros'])->assertSessionHas('flash.message', 'Burst geyser assigned to Pipe Pros; fix by '.today()->subDays(2)->format('d M').'.');
        $this->actingAs($owner)->post($leak->url().'/actions/done', ['amount' => 1800])->assertSessionHas('flash.message', 'Burst geyser fixed in 3 days, after the fix-by date.');
        $this->assertEquals(3, $leak->fresh()->value('_days_to_fix'));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Repairs by category')->assertSee('Plumbing')->assertSee($this->money(1800));
    }

    public function test_listings_need_a_price_and_viewings_need_a_free_slot_on_a_live_listing(): void
    {
        $app = 'property-listings';
        [$owner, $workspace] = $this->appWorkspace($app);
        $listing = fn (array $overrides) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'listings']), array_replace_recursive(['title' => 'Garden cottage', 'status' => 'on_market', 'amount' => 0, 'data' => ['deal' => 'rent', 'mandate' => 'open']], $overrides));
        $listing([])->assertSessionHasErrors(['amount' => 'Set the asking price before the listing goes on the market.']);
        $listing(['amount' => 9000, 'data' => ['mandate' => 'sole']])->assertSessionHasErrors(['due_on' => 'A sole mandate needs an expiry date.']);
        $listing(['amount' => 9000, 'status' => 'sold'])->assertSessionHasErrors(['status' => 'A rental is let, not sold.']);

        $house = $this->record($workspace, $app, 'listings', 'Family home', 'on_market', ['deal' => 'sale', 'mandate' => 'sole', 'size_m2' => 200], ['amount' => 2000000, 'due_on' => today()->addDays(10)]);
        $this->assertEquals(10000, $house->value('_per_m2'));
        $draft = $this->record($workspace, $app, 'listings', 'Shop front', 'draft', ['deal' => 'rent']);

        $viewing = fn (Record $listing, string $name) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'viewings']), ['title' => $name, 'status' => 'booked', 'occurs_on' => today()->toDateString(), 'data' => ['listing' => $listing->id, 'time' => '10:00']]);
        $viewing($draft, 'Ann')->assertSessionHasErrors(['data.listing' => 'Shop front is not on the market.']);
        $viewing($house, 'Ann')->assertSessionHasNoErrors();
        $viewing($house, 'Ben')->assertSessionHasErrors(['data.time' => 'Ann is already viewing at 10:00.']);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Viewings today')->assertSee('Ann')->assertSee('Mandates expiring')->assertSee('Family home');

        $this->actingAs($owner)->post($draft->url().'/actions/publish')->assertSessionHasErrors(['amount' => 'Set the asking price before the listing goes on the market.']);
        $this->actingAs($owner)->post($house->url().'/actions/sold')->assertSessionHas('flash.message', 'Family home is sold.');
        $this->assertSame('cancelled', Record::query()->where('entity', 'viewings')->where('title', 'Ann')->first()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Listings')->assertSee('Family home');
    }

    public function test_rent_charges_add_up_payments_and_fall_into_arrears(): void
    {
        $app = 'rent-collection';
        [$owner, $workspace] = $this->appWorkspace($app);
        $charge = $this->record($workspace, $app, 'charges', 'Thandi October', 'due', ['unit' => 'Flat 1', 'period' => 'October', 'rent' => 6000, 'utilities' => 500], ['due_on' => today()->addDays(5)]);
        $this->assertEquals(6500, (float) $charge->amount);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'charges']), ['title' => 'Again', 'status' => 'due', 'data' => ['unit' => 'flat 1', 'period' => 'October', 'rent' => 6000]])
            ->assertSessionHasErrors(['data.period' => 'flat 1 is already charged for October.']);

        $pay = fn (float $amount) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'payments']), ['title' => 'EFT', 'status' => 'received', 'amount' => $amount, 'data' => ['charge' => $charge->id, 'method' => 'bank']]);
        $pay(7000)->assertSessionHasErrors(['amount' => 'Only '.$this->money(6500).' is owed on Thandi October.']);
        $pay(4000)->assertSessionHasNoErrors();
        $this->assertSame('part_paid', $charge->fresh()->status);
        $this->assertEquals(4000, $charge->fresh()->value('paid'));

        $late = $this->record($workspace, $app, 'charges', 'Sipho September', 'due', ['unit' => 'Flat 2', 'period' => 'September', 'rent' => 5000]);
        $late->update(['due_on' => today()->subDay()]);
        $this->assertSame('in_arrears', $late->fresh()->status);
        Record::query()->whereKey($charge->id)->update(['due_on' => today()->subDay()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('in_arrears', $charge->fresh()->status);

        $pay(2500)->assertSessionHasNoErrors();
        $this->assertSame('paid', $charge->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('In arrears ('.$this->money(5000).')');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Collection by unit')->assertSee('Flat 1')->assertSee('100%');
    }

    public function test_estate_quotas_levies_and_matters_follow_the_rules(): void
    {
        $app = 'estate-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $nkosi = $this->record($workspace, $app, 'owners', 'Nkosi', 'current', ['unit' => 'Stand 1', 'participation_quota' => 60, 'levy' => 1500]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'owners']), ['title' => 'Botha', 'status' => 'current', 'data' => ['unit' => 'Stand 2', 'participation_quota' => 50]])
            ->assertSessionHasErrors(['data.participation_quota' => 'Quotas would add up to 110%; only 40% is left.']);

        $levy = fn (string $title, array $overrides = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'levies']), array_replace_recursive(['title' => $title, 'status' => 'billed', 'data' => ['owner' => $nkosi->id, 'type' => 'ordinary']], $overrides));
        $levy('October')->assertSessionHasNoErrors();
        $levy('october')->assertSessionHasErrors(['title' => 'This owner already has the ordinary levy for October.']);
        $october = Record::query()->where('entity', 'levies')->where('title', 'October')->first();
        $this->assertEquals(1500, (float) $october->amount);
        $this->assertTrue($october->due_on->isSameDay(today()->addDays(30)));
        $this->assertEquals(1500, $nkosi->fresh()->value('balance'));

        $levy('September', ['occurs_on' => today()->subDays(40)->toDateString(), 'due_on' => today()->subDays(10)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('in_arrears', $nkosi->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Owners in arrears')->assertSee($this->money(3000));
        $september = Record::query()->where('entity', 'levies')->where('title', 'September')->first();
        $this->actingAs($owner)->post($september->url().'/actions/pay')->assertSessionHas('flash.message', 'September levy paid; Nkosi owes '.$this->money(1500).'.');
        $this->assertSame('current', $nkosi->fresh()->status);

        $noise = $this->record($workspace, $app, 'matters', 'Loud parties', 'open', ['type' => 'complaint']);
        $this->actingAs($owner)->post($noise->url().'/actions/resolve', ['resolution' => ''])->assertSessionHasErrors(['resolution' => 'Write down the resolution.']);
        $this->actingAs($owner)->post($noise->url().'/actions/resolve', ['resolution' => 'Quiet hours from 22:00.'])->assertSessionHas('flash.message', 'Loud parties resolved.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Levies by type')->assertSee('Ordinary');
    }

    public function test_short_stays_cannot_overlap_and_need_a_clean_between_guests(): void
    {
        $app = 'short-stay-rental-airbnb-style';
        [$owner, $workspace] = $this->appWorkspace($app);
        $loft = $this->record($workspace, $app, 'listings', 'Sea loft', 'active', ['address' => '1 Beach Rd', 'nightly_rate' => 1200, 'cleaning_fee' => 300]);
        $cabin = $this->record($workspace, $app, 'listings', 'Cabin', 'blocked', ['address' => 'Hill top']);
        $book = fn (Record $listing, string $guest, int $in, int $out) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'stays']), ['title' => $guest, 'status' => 'booked', 'occurs_on' => today()->addDays($in)->toDateString(), 'due_on' => today()->addDays($out)->toDateString(), 'data' => ['listing' => $listing->id, 'guests' => 2]]);

        $book($cabin, 'Ann', 0, 2)->assertSessionHasErrors(['data.listing' => 'Cabin is blocked and cannot take bookings.']);
        $book($loft, 'Ann', 2, 2)->assertSessionHasErrors(['due_on' => 'Check-out must be after check-in.']);
        $book($loft, 'Ann', 0, 3)->assertSessionHasNoErrors();
        $book($loft, 'Ben', 2, 4)->assertSessionHasErrors(['occurs_on' => 'Sea loft is booked by Ann from '.today()->format('d M').' to '.today()->addDays(3)->format('d M').'.']);
        $book($loft, 'Ben', 3, 5)->assertSessionHasNoErrors();
        $ann = Record::query()->where('entity', 'stays')->where('title', 'Ann')->first();
        $ben = Record::query()->where('entity', 'stays')->where('title', 'Ben')->first();
        $this->assertEquals(3900, (float) $ann->amount);
        $this->assertEquals(3, $ann->value('_nights'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('arriving')->assertSee('Ann');

        $this->actingAs($owner)->post($ann->url().'/actions/check_in', ['access_code' => '4321'])->assertSessionHas('flash.message', 'Ann checked in; door code 4321.');
        $this->actingAs($owner)->post($ann->url().'/actions/check_out')->assertSessionHas('flash.message', 'Ann checked out. Sea loft needs a turnover clean.');
        $this->actingAs($owner)->post($ben->url().'/actions/check_in')->assertSessionHasErrors(['access_code' => 'The turnover clean after Ann is not done yet.']);
        $this->actingAs($owner)->post($ann->url().'/actions/clean')->assertSessionHas('flash.message', 'Sea loft is clean and ready.');
        $this->actingAs($owner)->post($ben->url().'/actions/check_in')->assertSessionHas('flash.message', 'Ben checked in.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Occupancy by listing')->assertSee('Sea loft');
    }

    public function test_plot_sales_track_instalments_and_move_the_plot_through_to_transfer(): void
    {
        $app = 'land-plot-sales';
        [$owner, $workspace] = $this->appWorkspace($app);
        $plot = $this->record($workspace, $app, 'plots', 'Stand 7', 'available', ['development' => 'Green Acres', 'price' => 100000]);
        $sell = fn (string $buyer, array $data) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), ['title' => $buyer, 'status' => 'reserved', 'data' => ['plot' => $plot->id, ...$data]]);
        $sell('Mia', ['deposit' => 150000])->assertSessionHasErrors(['data.paid_to_date' => 'Payments cannot be more than the price of '.$this->money(100000).'.']);
        $sell('Mia', ['deposit' => 20000, 'instalment' => 10000])->assertSessionHasNoErrors();
        $sale = Record::query()->where('entity', 'sales')->where('title', 'Mia')->first();
        $this->assertEquals(100000, (float) $sale->amount);
        $this->assertSame('paying', $sale->status);
        $this->assertEquals(8, $sale->value('_months_left'));
        $this->assertSame('sold', $plot->fresh()->status);
        $sell('Leo', [])->assertSessionHasErrors(['data.plot' => 'Stand 7 is already sold to Mia.']);

        $this->actingAs($owner)->post($sale->url().'/actions/receive', ['amount' => 90000])->assertSessionHasErrors(['amount' => 'Only '.$this->money(80000).' is still owed.']);
        $this->actingAs($owner)->post($sale->url().'/actions/receive', ['amount' => 30000])->assertSessionHas('flash.message', 'Received '.$this->money(30000).' from Mia; '.$this->money(50000).' to go.');
        $this->actingAs($owner)->post($sale->url().'/actions/receive', ['amount' => 50000])->assertSessionHas('flash.message', 'Received '.$this->money(50000).' from Mia; the plot is fully paid.');
        $this->assertSame('fully_paid', $sale->fresh()->status);
        $this->actingAs($owner)->post($sale->url().'/actions/transfer', ['title_deed' => ''])->assertSessionHasErrors(['title_deed' => 'Give the title deed number.']);
        $this->actingAs($owner)->post($sale->url().'/actions/transfer', ['title_deed' => 'T123/2026'])->assertSessionHas('flash.message', 'Stand 7 transferred to Mia under title deed T123/2026.');
        $this->assertSame('transferred', $plot->fresh()->status);
        $this->assertSame('T123/2026', $plot->fresh()->value('title_deed'));

        $corner = $this->record($workspace, $app, 'plots', 'Stand 8', 'available', ['development' => 'Green Acres', 'price' => 80000]);
        $reservation = $this->record($workspace, $app, 'sales', 'Leo', 'reserved', ['plot' => $corner->id]);
        $this->assertSame('reserved', $corner->fresh()->status);
        $this->actingAs($owner)->post($reservation->url().'/actions/cancel')->assertSessionHas('flash.message', 'Leo\'s sale cancelled; Stand 8 is available again.');
        $this->assertSame('available', $corner->fresh()->status);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('1 of 2');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Plots by development')->assertSee('Green Acres')->assertSee($this->money(100000));
    }

    public function test_home_loans_work_out_repayments_and_only_approve_affordable_loans(): void
    {
        $app = 'mortgage-home-loan-origination';
        [$owner, $workspace] = $this->appWorkspace($app);
        $apply = fn (array $overrides) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), array_replace_recursive(['title' => 'Zanele', 'status' => 'enquiry', 'data' => ['property_address' => '3 Oak St', 'purchase_price' => 1000000, 'deposit' => 100000, 'lender' => 'First Bank']], $overrides));
        $apply(['amount' => 950000])->assertSessionHasErrors(['amount' => 'The loan cannot be more than the price less the deposit ('.$this->money(900000).').']);
        $apply(['status' => 'submitted'])->assertSessionHasErrors(['data.interest_rate' => 'Give the interest rate and term before submitting.']);
        $apply(['status' => 'valuation', 'data' => ['interest_rate' => 12, 'term_years' => 20, 'household_income' => 20000]])->assertSessionHasNoErrors();

        $loan = Record::query()->where('entity', 'applications')->first();
        $this->assertEquals(900000, (float) $loan->amount);
        $this->assertEqualsWithDelta(9909.78, $loan->value('_repayment'), 0.05);
        $this->assertEquals(90, $loan->value('_ltv'));
        $this->assertEquals(49.5, $loan->value('_share'));
        $this->actingAs($owner)->post($loan->url().'/actions/advance')->assertSessionHasErrors(['data.household_income' => 'The repayment takes 49.5% of income; the most allowed is 30%.']);

        $loan->update(['data' => [...$loan->data, 'household_income' => 40000]]);
        $this->actingAs($owner)->post($loan->url().'/actions/advance')->assertSessionHas('flash.message', 'Zanele\'s application is now at approved.');
        $this->actingAs($owner)->get($loan->url())->assertOk()->assertSee('Affordability')->assertSee('24.8%');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Pipeline');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Applications by lender')->assertSee('First Bank')->assertSee('100%');
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
