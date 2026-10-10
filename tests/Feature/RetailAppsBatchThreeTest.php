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

/** Retail: thrift consignment, classifieds, job board, handyman marketplace and WhatsApp catalogue. */
class RetailAppsBatchThreeTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_consigned_items_split_the_sale_and_pay_the_consignor(): void
    {
        $app = 'second-hand-consignment-thrift';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'consignors']), ['title' => 'Mwila', 'status' => 'active', 'data' => ['split_percent' => 120]])
            ->assertSessionHasErrors(['data.split_percent' => 'The consignor share must be between 0 and 100%.']);
        $mwila = $this->record($workspace, $app, 'consignors', 'Mwila', 'active', ['split_percent' => 60]);
        $gone = $this->record($workspace, $app, 'consignors', 'Old supplier', 'inactive', ['split_percent' => 50]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'items']), ['title' => 'Coat', 'status' => 'received', 'data' => ['consignor' => $gone->id, 'price' => 100]])
            ->assertSessionHasErrors(['data.consignor' => 'Old supplier is inactive; reactivate them to take new items.']);

        $jacket = $this->record($workspace, $app, 'items', 'Denim jacket', 'received', ['consignor' => $mwila->id, 'price' => 200], ['occurs_on' => today()]);
        $this->assertTrue($jacket->due_on->isSameDay(today()->addDays(60)));
        $this->actingAs($owner)->post($jacket->url().'/actions/list')->assertSessionHas('flash.message', 'Denim jacket is on the floor at '.$this->money(200).'.');
        $this->actingAs($owner)->post($jacket->url().'/actions/sell', ['amount' => 150])->assertSessionHas('flash.message', 'Denim jacket sold for '.$this->money(150).'; '.$this->money(90).' owed to Mwila.');
        $this->assertEquals(60, $jacket->fresh()->value('_shop_share'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'items', $jacket->id]), ['title' => 'Denim jacket', 'status' => 'returned', 'data' => ['consignor' => $mwila->id, 'price' => 200]])
            ->assertSessionHasErrors(['status' => 'This item is sold; it cannot be returned.']);

        $this->record($workspace, $app, 'items', 'Handbag', 'sold', ['consignor' => $mwila->id, 'price' => 100]);
        $old = $this->record($workspace, $app, 'items', 'Lamp', 'listed', ['consignor' => $mwila->id, 'price' => 50], ['occurs_on' => today()->subDays(70)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Past their return date')->assertSee('Lamp')->assertSee($this->money(150));
        $this->actingAs($owner)->get($mwila->url())->assertOk()->assertSee('Owed '.$this->money(150));
        $this->actingAs($owner)->post($mwila->url().'/actions/pay_all')->assertSessionHas('flash.message', 'Paid Mwila '.$this->money(150).' for 2 items.');
        $this->assertTrue((bool) $jacket->fresh()->value('paid_out'));
        $this->actingAs($owner)->post($old->url().'/actions/donate')->assertSessionHas('flash.message', 'Lamp donated.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Consignment sales by month')->assertSee($this->money(250));
    }

    public function test_classifieds_are_moderated_run_for_their_package_and_expire(): void
    {
        $app = 'classifieds-directory-listings';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'listings']), ['title' => 'Car', 'status' => 'pending', 'data' => ['category' => 'vehicles', 'description' => 'Nice car']])
            ->assertSessionHasErrors(['data.description' => 'Describe the listing in at least 20 characters.', 'data.phone' => 'Give a phone number or website so people can reach the advertiser.']);

        $listing = $this->record($workspace, $app, 'listings', 'Toyota Corolla 2015', 'pending', ['category' => 'vehicles', 'description' => 'One owner, full service history.', 'phone' => '0977000000', 'package' => 'featured']);
        $this->assertEquals(150, $listing->amount);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('To moderate')->assertSee('Toyota Corolla 2015');
        $this->actingAs($owner)->post($listing->url().'/actions/approve')->assertSessionHas('flash.message', 'Toyota Corolla 2015 is featured until '.today()->addDays(30)->format('d M Y').'.');
        $this->actingAs($owner)->post($listing->url().'/actions/renew')->assertSessionHas('flash.message', 'Toyota Corolla 2015 renewed until '.today()->addDays(60)->format('d M Y').'; renewal fee '.$this->money(150).'.');
        $this->assertEquals(300, $listing->fresh()->amount);

        $stale = $this->record($workspace, $app, 'listings', 'Room to let', 'live', ['category' => 'property', 'description' => 'Self-contained room near town.', 'phone' => '0966000000'], ['occurs_on' => today()->subDays(14)]);
        $this->assertSame('live', $stale->status);
        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $stale->fresh()->status);
        $this->actingAs($owner)->post($stale->url().'/actions/renew')->assertSessionHas('flash.message', 'Room to let renewed until '.today()->addDays(14)->format('d M Y').'.');
        $this->assertSame('live', $stale->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Listings by category')->assertSee('Vehicles')->assertSee($this->money(300));
    }

    public function test_job_postings_take_applications_until_someone_is_hired(): void
    {
        $app = 'job-board-freelance-marketplace';
        [$owner, $workspace] = $this->appWorkspace($app);
        $job = $this->record($workspace, $app, 'jobs', 'Bookkeeper', 'open', ['employer' => 'Zambeef', 'type' => 'full_time', 'description' => 'Keep the books.'], ['occurs_on' => today(), 'amount' => 75]);
        $this->assertTrue($job->due_on->isSameDay(today()->addDays(30)));
        $apply = fn (string $name, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'applications']), ['title' => $name, 'status' => 'received', 'data' => ['job' => $job->id, 'email' => strtolower($name).'@example.com', ...$data]]);

        $apply('Chanda')->assertSessionHasNoErrors();
        $apply('Chanda')->assertSessionHasErrors(['data.email' => 'chanda@example.com has already applied for Bookkeeper.']);
        $apply('Bupe', ['bid' => 500])->assertSessionHasErrors(['data.bid' => 'Bids are only for contract and freelance work.']);
        $chanda = Record::query()->where('entity', 'applications')->firstOrFail();
        $this->actingAs($owner)->get($job->url())->assertOk()->assertSee('1 application')->assertSee('Chanda');
        $this->actingAs($owner)->post($chanda->url().'/actions/advance')->assertSessionHas('flash.message', 'Chanda shortlisted for Bookkeeper.');
        $this->actingAs($owner)->post($chanda->url().'/actions/advance')->assertSessionHas('flash.message', 'Chanda invited to interview for Bookkeeper.');
        $this->actingAs($owner)->post($chanda->url().'/actions/advance')->assertSessionHas('flash.message', 'Chanda hired for Bookkeeper; the posting is filled.');
        $this->assertSame('filled', $job->fresh()->status);
        $apply('Mutale')->assertSessionHasErrors(['data.job' => 'Bookkeeper is filled and not taking applications.']);

        $gig = $this->record($workspace, $app, 'jobs', 'Logo design', 'open', ['employer' => 'Café', 'type' => 'freelance_gig', 'description' => 'A logo.'], ['occurs_on' => today(), 'due_on' => today()]);
        $this->travel(1)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $gig->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->post($gig->url().'/actions/publish')->assertSessionHas('flash.message', 'Logo design is open until '.today()->format('d M Y').'.');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Postings and applications')->assertSee('Freelance gig')->assertSee($this->money(75));
    }

    public function test_handyman_requests_match_the_best_provider_and_feed_their_rating(): void
    {
        $app = 'service-marketplace-handymen';
        [$owner, $workspace] = $this->appWorkspace($app);
        $applicant = $this->record($workspace, $app, 'providers', 'New plumber', 'applied', ['trade' => 'plumber', 'phone' => '0977111111', 'commission_percent' => 10]);
        $good = $this->record($workspace, $app, 'providers', 'Banda Plumbing', 'active', ['trade' => 'plumber', 'phone' => '0977222222', 'rating' => 4.5, 'commission_percent' => 10]);
        $this->record($workspace, $app, 'providers', 'Okay Plumbing', 'active', ['trade' => 'plumber', 'phone' => '0977333333', 'rating' => 3, 'commission_percent' => 15]);
        $sparky = $this->record($workspace, $app, 'providers', 'Sparky', 'active', ['trade' => 'electrician', 'phone' => '0977444444', 'commission_percent' => 10]);
        $request = fn (array $data, string $status = 'new') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Leaking geyser', 'status' => $status, 'data' => ['trade' => 'plumber', 'address' => 'Plot 4, Kabulonga', ...$data]]);

        $request(['provider' => $applicant->id])->assertSessionHasErrors(['data.provider' => 'New plumber is applied, not active.']);
        $request(['provider' => $sparky->id])->assertSessionHasErrors(['data.provider' => 'Sparky works as an electrician, not a plumber.']);
        $request([], 'booked')->assertSessionHasErrors(['data.provider' => 'Assign a provider first.']);
        $request(['customer_rating' => 5])->assertSessionHasErrors(['data.customer_rating' => 'Only completed jobs can be rated.']);
        $request([])->assertSessionHasNoErrors();
        $job = Record::query()->where('entity', 'requests')->firstOrFail();
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Requests to match')->assertSee('Leaking geyser');

        $this->actingAs($owner)->post($job->url().'/actions/match')->assertSessionHas('flash.message', 'Matched with Banda Plumbing (rated 4.5).');
        $this->actingAs($owner)->post($job->url().'/actions/quote', ['amount' => 800])->assertSessionHas('flash.message', 'Quoted '.$this->money(800).'.');
        $this->actingAs($owner)->post($job->url().'/actions/complete', ['customer_rating' => 9])->assertSessionHasErrors('customer_rating');
        $this->actingAs($owner)->post($job->url().'/actions/complete', ['customer_rating' => 3])->assertSessionHas('flash.message', 'Job done for '.$this->money(800).'; commission '.$this->money(80).'.');
        $this->assertEquals(3, $good->fresh()->value('rating'));

        $this->actingAs($owner)->post($applicant->url().'/actions/vet')->assertSessionHas('flash.message', 'New plumber is vetted.');
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'requests']), ['title' => 'Rewire', 'status' => 'new', 'data' => ['trade' => 'painter', 'address' => 'Plot 9']]);
        $paint = Record::query()->where('entity', 'requests')->where('title', 'Rewire')->firstOrFail();
        $this->actingAs($owner)->post($paint->url().'/actions/match')->assertSessionHasErrors(['data.provider' => 'No active painter is available; vet or activate one.']);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Completed jobs by trade')->assertSee('Plumber')->assertSee($this->money(80));
    }

    public function test_chat_orders_price_from_the_catalogue_and_hold_stock(): void
    {
        $app = 'social-commerce-whatsapp-catalog';
        [$owner, $workspace] = $this->appWorkspace($app);
        $dress = $this->record($workspace, $app, 'products', 'Chitenge dress', 'active', ['price' => 350, 'stock' => 3]);
        $bag = $this->record($workspace, $app, 'products', 'Beaded bag', 'active', ['price' => 120, 'stock' => 0]);
        $this->assertSame('sold_out', $bag->status);
        $order = fn (string $items, string $status = 'enquiry', array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'orders']), ['title' => 'Natasha', 'status' => $status, 'data' => ['channel' => 'whatsapp', 'phone' => '0977000000', 'items' => $items, ...$data]]);

        $order('Something nice')->assertSessionHasErrors(['amount' => 'Give the order total, or list items as "2 x Product".']);
        $order('4 x Chitenge dress', 'confirmed')->assertSessionHasErrors(['data.items' => 'Only 3 of Chitenge dress left.']);
        $order("2 x chitenge dress\nGift wrap")->assertSessionHasNoErrors();
        $chat = Record::query()->where('entity', 'orders')->firstOrFail();
        $this->assertEquals(700, $chat->amount);
        $this->assertEquals(3, $dress->fresh()->value('stock'));

        $this->actingAs($owner)->post($chat->url().'/actions/advance')->assertSessionHas('flash.message', 'Order for Natasha is confirmed ('.$this->money(700).').');
        $this->assertEquals(1, $dress->fresh()->value('stock'));
        $this->actingAs($owner)->post($chat->url().'/actions/advance', ['proof_of_payment' => ''])->assertSessionHasErrors(['proof_of_payment' => 'Attach the proof of payment.']);
        $this->actingAs($owner)->post($chat->url().'/actions/advance', ['proof_of_payment' => 'https://example.com/pop.jpg'])->assertSessionHas('flash.message', 'Order for Natasha is paid.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('To dispatch')->assertSee('Beaded bag');
        $this->actingAs($owner)->post($chat->url().'/actions/advance', ['delivery_address' => ''])->assertSessionHasErrors(['delivery_address' => 'Give the delivery address.']);
        $this->actingAs($owner)->post($chat->url().'/actions/cancel')->assertSessionHas('flash.message', 'Order for Natasha cancelled; stock put back.');
        $this->assertEquals(3, $dress->fresh()->value('stock'));
        $this->assertSame('active', $dress->fresh()->status);

        $this->record($workspace, $app, 'orders', 'Lombe', 'confirmed', ['channel' => 'instagram', 'phone' => '0966', 'items' => '3 x Chitenge dress']);
        $this->assertSame('sold_out', $dress->fresh()->status);
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Orders by channel')->assertSee('Whatsapp')->assertSee('Instagram');
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
