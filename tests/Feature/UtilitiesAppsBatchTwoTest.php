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

/** Utilities: water tanker and borehole, pay TV and airtime reseller. */
class UtilitiesAppsBatchTwoTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_tankers_do_one_delivery_at_a_time_and_boreholes_move_forward_through_their_stages(): void
    {
        $app = 'borehole-water-delivery-tanker';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'deliveries']), ['title' => 'Big', 'status' => 'dispatched', 'data' => ['litres' => 50000, 'address' => 'Plot 1']])
            ->assertSessionHasErrors(['data.litres' => 'An order is for 1 to 40,000 litres; split bigger orders.', 'data.tanker' => 'Say which tanker is going.', 'data.driver' => 'Give the driver.']);
        $banda = $this->record($workspace, $app, 'deliveries', 'Banda', 'dispatched', ['litres' => 10000, 'address' => 'Plot 2', 'tanker' => 'T1', 'driver' => 'Joe'], ['amount' => 800]);
        $mwale = $this->record($workspace, $app, 'deliveries', 'Mwale', 'ordered', ['litres' => 5000, 'address' => 'Plot 3'], ['amount' => 400]);

        $this->actingAs($owner)->post($mwale->url().'/actions/dispatch', ['tanker' => ' t1 ', 'driver' => 'Ben'])->assertSessionHasErrors(['tanker' => 'Tanker t1 is already out delivering to Banda.']);
        $this->actingAs($owner)->post($banda->url().'/actions/deliver')->assertSessionHas('flash.message', '10,000 L delivered to Banda.');
        $this->assertSame(today()->toDateString(), $banda->fresh()->value('_delivered_on'));
        $this->actingAs($owner)->post($mwale->url().'/actions/dispatch', ['tanker' => 'T1', 'driver' => 'Ben'])->assertSessionHas('flash.message', 'Mwale\'s water dispatched on T1.');
        $this->actingAs($owner)->post($banda->url().'/actions/pay')->assertSessionHas('flash.message', 'Banda paid '.$this->money(800).'.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('To deliver')->assertSee('Mwale');
        $this->actingAs($owner)->post($mwale->url().'/actions/cancel')->assertSessionHas('flash.message', 'Mwale\'s order cancelled.');

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'boreholes']), ['title' => 'Rushed', 'status' => 'completed', 'data' => []])
            ->assertSessionHasErrors(['data.depth', 'data.yield', 'data.pump', 'data.water_quality' => 'Give the water quality result before completing.']);
        $hole = $this->record($workspace, $app, 'boreholes', 'Chongwe farm', 'drilling');
        $this->actingAs($owner)->post($hole->url().'/actions/advance')->assertSessionHasErrors(['depth' => 'Give the depth drilled before casing.']);
        $this->actingAs($owner)->post($hole->url().'/actions/advance', ['depth' => 60])->assertSessionHas('flash.message', 'Chongwe farm moved to cased.');
        $this->actingAs($owner)->post($hole->url().'/actions/advance', ['yield' => 2000])->assertSessionHasErrors(['pump' => 'Say which pump was installed.']);
        $this->actingAs($owner)->post($hole->url().'/actions/advance', ['yield' => 2000, 'pump' => 'Grundfos SQ'])->assertSessionHas('flash.message', 'Chongwe farm moved to pump installed.');
        $this->actingAs($owner)->post($hole->url().'/actions/advance', ['water_quality' => 'Potable'])->assertSessionHas('flash.message', 'Chongwe farm moved to completed.');
        $this->assertSame('completed', $hole->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Deliveries by tanker')->assertSee('T1')->assertSee('Completed boreholes')->assertSee('Potable');
    }

    public function test_tv_subscribers_lapse_after_their_renewal_date_and_installs_need_a_good_signal(): void
    {
        $app = 'cable-satellite-tv-subscriptions';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'subscribers']), ['title' => 'New', 'status' => 'active', 'data' => ['decoder_number' => '12AB', 'bouquet' => 'basic']])
            ->assertSessionHasErrors(['data.decoder_number' => 'A decoder or smartcard number is 8 to 14 digits.']);
        $ann = $this->record($workspace, $app, 'subscribers', 'Ann', 'active', ['decoder_number' => '4123 456 789', 'bouquet' => 'compact'], ['amount' => 300, 'occurs_on' => today()->subMonths(4)]);
        $bob = $this->record($workspace, $app, 'subscribers', 'Bob', 'active', ['decoder_number' => '5550001112', 'bouquet' => 'family'], ['amount' => 200, 'occurs_on' => today()->subMonthNoOverflow()->subDays(2)]);
        $this->assertSame('4123456789', $ann->fresh()->value('decoder_number'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'subscribers']), ['title' => 'Copy', 'status' => 'active', 'data' => ['decoder_number' => '4123-456-789', 'bouquet' => 'basic']])
            ->assertSessionHasErrors(['data.decoder_number' => 'This decoder belongs to Ann.']);

        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('disconnected', $ann->fresh()->status);
        $this->assertSame('suspended', $bob->fresh()->status);
        $this->actingAs($owner)->post($bob->url().'/actions/renew', ['months' => 2])
            ->assertSessionHas('flash.message', 'Bob renewed for 2 months, '.$this->money(400).', until '.today()->addMonthsNoOverflow(2)->format('d M Y').'.');
        $this->assertSame('active', $bob->fresh()->status);
        $this->actingAs($owner)->post($bob->url().'/actions/change', ['bouquet' => 'premium', 'price' => 450])->assertSessionHas('flash.message', 'Bob moved to Premium.');
        $this->assertEquals(450, $bob->fresh()->amount);

        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'installations']), ['title' => 'Plot 1', 'status' => 'installed', 'data' => ['signal_strength' => 30]])
            ->assertSessionHasErrors(['data.signal_strength' => 'An installation needs at least 60% signal; realign the dish or mark it failed.']);
        $job = $this->record($workspace, $app, 'installations', 'Plot 9', 'booked', ['subscriber' => $ann->id], ['amount' => 150]);
        $this->actingAs($owner)->post($job->url().'/actions/install', ['signal_strength' => 40])->assertSessionHasErrors(['signal_strength' => 'Signal of 40% is too weak; realign the dish or mark it failed.']);
        $this->actingAs($owner)->post($job->url().'/actions/install', ['signal_strength' => 78])->assertSessionHas('flash.message', 'Installed with 78% signal; Ann is active.');
        $this->assertSame('active', $ann->fresh()->status);
        $this->assertSame(today()->addMonthNoOverflow()->toDateString(), $ann->fresh()->due_on->toDateString());

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Renewals due in 7 days')->assertSee($this->money(750));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Subscribers by bouquet')->assertSee('Premium')->assertSee('78%');
    }

    public function test_sales_draw_on_the_providers_float_and_earn_commission_unless_reversed(): void
    {
        $app = 'telecom-airtime-bundle-reseller';
        [$owner, $workspace] = $this->appWorkspace($app);
        $topUp = $this->record($workspace, $app, 'float', 'MTN', 'requested', ['method' => 'bank'], ['amount' => 1000]);
        $this->actingAs($owner)->post($topUp->url().'/actions/receive')->assertSessionHas('flash.message', 'MTN float of '.$this->money(1000).' received; '.$this->money(1000).' now available.');

        $sale = fn (string $title, float $amount, array $data = []) => ['title' => $title, 'status' => 'successful', 'amount' => $amount, 'data' => ['product' => 'airtime', 'network' => 'MTN', ...$data]];
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), $sale('abc', 50))->assertSessionHasErrors(['title' => 'Give a valid phone number.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), $sale('0977123456', 1500))->assertSessionHasErrors(['amount' => 'Not enough MTN float: '.$this->money(1000).' left.']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), $sale('01234567890', 10, ['product' => 'electricity_token']))
            ->assertSessionHasErrors(['data.token_reference' => 'Give the token number for the electricity sale.']);

        $airtime = $this->record($workspace, $app, 'sales', '0977123456', 'successful', ['product' => 'airtime', 'network' => ' mtn '], ['amount' => 400]);
        $this->assertEquals(12, $airtime->fresh()->value('commission'));
        $this->assertSame('mtn', $airtime->fresh()->value('network'));
        $this->record($workspace, $app, 'sales', '0966123456', 'successful', ['product' => 'data_bundle', 'network' => 'MTN'], ['amount' => 100]);
        $failed = $this->record($workspace, $app, 'sales', '0955123456', 'failed', ['product' => 'airtime', 'network' => 'MTN', 'commission' => 5], ['amount' => 50]);
        $this->assertEquals(0, $failed->fresh()->value('commission'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sales']), $sale('0977000000', 600))->assertSessionHasErrors(['amount' => 'Not enough MTN float: '.$this->money(500).' left.']);

        $this->actingAs($owner)->post($airtime->url().'/actions/reverse')->assertSessionHas('flash.message', 'Sale to 0977123456 reversed; '.$this->money(400).' back on mtn float.');
        $this->assertEquals(0, $airtime->fresh()->value('commission'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Float balances')->assertSee($this->money(900));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Sales by product')->assertSee('Data bundle')->assertSee($this->money(4));
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
