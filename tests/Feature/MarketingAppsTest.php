<?php

namespace Tests\Feature;

use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Money;
use App\Tenancy\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\Contacts\Models\Contact;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** The first marketing batch: email, SMS & WhatsApp, social media, websites, promo codes and SEO. */
class MarketingAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_email_campaigns_go_to_active_lists_and_count_their_results(): void
    {
        $app = 'email-marketing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $list = $this->record($workspace, $app, 'lists', 'Newsletter', 'active', ['subscribers' => 1200]);
        $old = $this->record($workspace, $app, 'lists', 'Old customers', 'archived', ['subscribers' => 50]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'lists']), ['title' => ' newsletter', 'status' => 'active', 'data' => ['subscribers' => 10]])
            ->assertSessionHasErrors(['title' => 'There is already a list called newsletter.']);

        $campaign = fn (string $status, Record $to, string $body, array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'campaigns']), ['title' => 'October news', 'status' => $status, 'data' => ['list' => $to->id, 'body' => $body], ...$extra]);
        $campaign('scheduled', $list, 'Hello', ['occurs_on' => today()->addDay()->toDateString()])->assertSessionHasErrors(['data.body' => 'Add {unsubscribe} so people can opt out.']);
        $campaign('scheduled', $old, 'Hello {unsubscribe}', ['occurs_on' => today()->addDay()->toDateString()])->assertSessionHasErrors(['data.list' => 'Old customers is archived.']);
        $campaign('sent', $list, 'Hello {unsubscribe}')->assertSessionHasErrors('status');
        $campaign('draft', $list, 'Hello {unsubscribe}')->assertSessionHasNoErrors();
        $draft = Record::query()->where('entity', 'campaigns')->firstOrFail();

        $this->actingAs($owner)->post($draft->url().'/actions/schedule', ['send_at' => today()->subDay()->toDateString()])->assertSessionHasErrors('send_at');
        $this->actingAs($owner)->post($draft->url().'/actions/send')->assertSessionHas('flash.message', 'October news sent to 1,200 subscribers on Newsletter.');
        $draft->refresh();
        $this->assertSame('sent', $draft->status);
        $this->assertEquals(1200, $draft->value('_recipients'));

        $this->actingAs($owner)->post($draft->url().'/actions/results', ['opens' => 1300, 'clicks' => 10])->assertSessionHasErrors('opens');
        $this->actingAs($owner)->post($draft->url().'/actions/results', ['opens' => 300, 'clicks' => 48])->assertSessionHas('flash.message', 'October news: 25.0% opened, 4.0% clicked.');

        $due = $this->record($workspace, $app, 'campaigns', 'Weekend sale', 'scheduled', ['list' => $list->id, 'body' => 'Sale {unsubscribe}'], ['occurs_on' => today()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('sent', $due->fresh()->status);
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'lists', $list->id]), ['title' => 'Newsletter', 'status' => 'archived', 'data' => ['subscribers' => 1200]])->assertSessionHasNoErrors();

        $sequence = fn (string $steps) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'sequences']), ['title' => 'Welcome', 'status' => 'active', 'data' => ['trigger' => 'signed_up', 'steps' => $steps]]);
        $sequence("Day 0: Welcome\nDay 3 tips")->assertSessionHasErrors(['data.steps' => 'Line 2 should read like "Day 3: Tips to get started".']);
        $sequence("Day 3: Tips\nDay 1: Hello")->assertSessionHasErrors(['data.steps' => 'Line 2 goes back to day 1; keep the emails in day order.']);
        $sequence("Day 0: Welcome\n\nDay 7: Your first week")->assertSessionHasNoErrors();
        $welcome = Record::query()->where('entity', 'sequences')->firstOrFail();
        $this->assertEquals(2, $welcome->value('_emails'));
        $this->assertEquals(7, $welcome->value('_span_days'));

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open rate');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Campaign results')->assertSee('25.0%');
    }

    public function test_sms_campaigns_count_message_parts_and_need_approved_templates(): void
    {
        $app = 'sms-marketing';
        [$owner, $workspace] = $this->appWorkspace($app);
        $template = fn (string $title, string $channel, string $body, string $status = 'approved') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'templates']), ['title' => $title, 'status' => $status, 'data' => ['channel' => $channel, 'body' => $body]]);

        $template('Black Friday text', 'sms', str_repeat('Big savings this Friday. ', 7))->assertSessionHasNoErrors();
        $template('Emoji', 'sms', 'Sale today 🎉')->assertSessionHasNoErrors();
        $template('Bad variables', 'whatsapp', 'Hi {{1}}, order {{3}} is ready.')->assertSessionHasErrors(['data.body' => 'Number the variables {{1}}, {{2}} and so on with no gaps.']);
        $template('Order ready', 'whatsapp', 'Hi {{1}}, order {{2}} is ready.')->assertSessionHasNoErrors();
        [$sms, $emoji, $whatsapp] = Record::query()->where('entity', 'templates')->orderBy('id')->get()->all();
        $this->assertSame(['GSM-7', 174, 2], [$sms->value('_encoding'), $sms->value('_characters'), $sms->value('_parts')]);
        $this->assertSame(['Unicode', 1], [$emoji->value('_encoding'), $emoji->value('_parts')]);

        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'templates', $whatsapp->id]), ['title' => 'Order ready', 'status' => 'approved', 'data' => ['channel' => 'whatsapp', 'body' => 'Hello {{1}}, order {{2}} is ready.']])->assertSessionHasNoErrors();
        $this->assertSame('draft', $whatsapp->fresh()->status);

        $campaign = fn (string $channel, ?Record $using, string $status = 'draft') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'campaigns']), ['title' => 'Black Friday', 'status' => $status, 'occurs_on' => today()->addDay()->toDateString(), 'data' => ['channel' => $channel, 'template' => $using?->id, 'audience' => 'All customers', 'recipients' => 500]]);
        $campaign('whatsapp', null, 'scheduled')->assertSessionHasErrors(['data.template' => 'WhatsApp campaigns must use an approved template.']);
        $campaign('whatsapp', $whatsapp, 'scheduled')->assertSessionHasErrors(['data.template' => 'Order ready is not approved.']);
        $campaign('sms', $whatsapp, 'scheduled')->assertSessionHasErrors(['data.template' => 'Order ready is a WhatsApp template.']);
        $campaign('sms', $sms)->assertSessionHasNoErrors();
        $draft = Record::query()->where('entity', 'campaigns')->firstOrFail();

        $this->actingAs($owner)->post($draft->url().'/actions/send')->assertSessionHas('flash.message', 'Black Friday sending to 500 numbers: 1,000 SMS (2 parts each).');
        $this->actingAs($owner)->post($draft->url().'/actions/results', ['delivered' => 600, 'cost' => 19])->assertSessionHasErrors('delivered');
        $this->actingAs($owner)->post($draft->url().'/actions/results', ['delivered' => 475, 'cost' => 19])->assertSessionHas('flash.message', 'Black Friday: 95.0% delivered, '.$this->money(0.04).' per delivered message.');
        $this->assertSame('sent', $draft->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Delivery rate');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Delivery by channel')->assertSee('95.0%');
    }

    public function test_social_posts_follow_their_networks_and_inbox_replies_are_timed(): void
    {
        $app = 'social-media-scheduling-inbox';
        [$owner, $workspace] = $this->appWorkspace($app);
        $post = fn (string $status, string $networks, ?string $media = null, ?string $on = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'posts']), ['title' => 'Launch day', 'status' => $status, 'occurs_on' => $on, 'data' => ['networks' => $networks, 'media_url' => $media]]);

        $post('draft', 'FB, Myspace')->assertSessionHasErrors(['data.networks' => "We don't post to Myspace; use Facebook, Instagram, X, LinkedIn or TikTok."]);
        $post('scheduled', 'Facebook and IG', null, today()->addDay()->toDateString())->assertSessionHasErrors(['data.media_url' => 'Instagram needs an image or video.']);
        $post('scheduled', 'Facebook', null, today()->subDay()->toDateString())->assertSessionHasErrors(['occurs_on' => 'The publish date has passed.']);
        $post('published', 'Facebook')->assertSessionHasErrors('status');
        $post('scheduled', 'fb & insta', 'https://example.com/launch.jpg', today()->addDay()->toDateString())->assertSessionHasNoErrors();
        $this->assertSame('Facebook, Instagram', Record::query()->where('entity', 'posts')->firstOrFail()->value('networks'));

        $post('draft', 'twitter')->assertSessionHasNoErrors();
        $draft = Record::query()->where('entity', 'posts')->latest('id')->firstOrFail();
        $this->actingAs($owner)->post($draft->url().'/actions/publish')->assertSessionHas('flash.message', 'Launch day published to X.');
        $this->actingAs($owner)->post($draft->url().'/actions/results', ['reach' => 2000, 'engagement' => 90])->assertSessionHas('flash.message', 'Launch day: 4.5% engagement.');

        $due = $this->record($workspace, $app, 'posts', 'Weekend offer', 'scheduled', ['networks' => 'LinkedIn'], ['occurs_on' => today()]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('published', $due->fresh()->status);

        $message = fn (string $status, ?string $reply = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'messages']), ['title' => 'Do you deliver?', 'status' => $status, 'data' => ['network' => 'instagram', 'from_handle' => '@chipo', 'type' => 'direct_message', 'reply' => $reply]]);
        $message('replied')->assertSessionHasErrors(['data.reply' => 'Write the reply.']);
        $message('new')->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Waiting for a reply')->assertSee('@chipo');

        $inbox = Record::query()->where('entity', 'messages')->firstOrFail();
        $this->actingAs($owner)->post($inbox->url().'/actions/reply', ['reply' => 'Yes, across Lusaka.'])->assertSessionHas('flash.message', 'Replied to @chipo after 0 min.');
        $this->assertSame('replied', $inbox->fresh()->status);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Inbox by network')->assertSee('4.5%');
    }

    public function test_website_pages_need_unique_slugs_and_submissions_convert_to_customers(): void
    {
        $app = 'website-builder';
        [$owner, $workspace] = $this->appWorkspace($app);
        $page = fn (string $title, string $slug, string $type, string $status, ?string $seo = null) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'pages']), ['title' => $title, 'status' => $status, 'data' => ['slug' => $slug, 'type' => $type, 'content' => 'Welcome', 'seo_description' => $seo, 'views' => 400]]);

        $page('About', 'About Us!', 'page', 'published')->assertSessionHasNoErrors();
        $about = Record::query()->where('entity', 'pages')->firstOrFail();
        $this->assertSame('about-us', $about->value('slug'));
        $this->assertNotNull($about->occurs_on);
        $page('Team', 'about us', 'page', 'draft')->assertSessionHasErrors(['data.slug' => '/about-us is already used by About.']);
        $page('Offer', 'offer', 'landing_page', 'published')->assertSessionHasErrors(['data.seo_description' => 'Write an SEO description before publishing.']);
        $page('Offer', 'offer', 'landing_page', 'published', str_repeat('a', 170))->assertSessionHasErrors(['data.seo_description' => 'Keep it to 160 characters so it shows in full; it is 170.']);
        $page('Draft', 'draft', 'page', 'draft')->assertSessionHasNoErrors();
        $hidden = Record::query()->where('entity', 'pages')->latest('id')->firstOrFail();

        $lead = fn (Record $from, array $data, string $title = 'Chipo Banda') => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'leads']), ['title' => $title, 'status' => 'new', 'data' => ['page' => $from->id, ...$data]]);
        $lead($hidden, ['email' => 'a@example.com'])->assertSessionHasErrors(['data.page' => 'Draft is not published.']);
        $lead($about, ['message' => 'Hi'])->assertSessionHasErrors(['data.email' => 'Take an email or phone number so someone can answer.']);
        $lead($about, ['email' => 'Chipo@Example.com'])->assertSessionHasNoErrors();
        $lead($about, ['email' => 'chipo@example.com'])->assertSessionHasErrors('data.email');
        $lead($about, ['email' => 'bot@example.com', 'message' => 'http://a.test http://b.test www.c.test'], 'Cheap pills')->assertSessionHasNoErrors();

        [$chipo, $bot] = Record::query()->where('entity', 'leads')->orderBy('id')->get()->all();
        $this->assertSame('chipo@example.com', $chipo->value('email'));
        $this->assertSame('spam', $bot->status);

        $this->actingAs($owner)->post($chipo->url().'/actions/convert')->assertSessionHas('flash.message', 'Chipo Banda converted; Chipo Banda is now a customer.');
        $chipo->refresh();
        $this->assertSame('converted', $chipo->status);
        $this->assertSame('customer', Contact::query()->findOrFail($chipo->contact_id)->type);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Submissions by page')->assertSee('0.3%');
    }

    public function test_promo_codes_take_their_discount_and_run_out(): void
    {
        $app = 'event-marketing-promo-codes';
        [$owner, $workspace] = $this->appWorkspace($app);
        $promotion = fn (string $status, string $starts, string $ends) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'promotions']), ['title' => 'Back to school', 'status' => $status, 'occurs_on' => $starts, 'due_on' => $ends, 'data' => ['budget' => 100]]);
        $promotion('planned', today()->toDateString(), today()->subDay()->toDateString())->assertSessionHasErrors(['due_on' => 'The promotion cannot end before it starts.']);
        $promotion('live', today()->addDay()->toDateString(), today()->addDays(10)->toDateString())->assertSessionHasErrors('status');
        $promotion('live', today()->toDateString(), today()->addDays(10)->toDateString())->assertSessionHasNoErrors();
        $school = Record::query()->where('entity', 'promotions')->firstOrFail();

        $code = fn (string $title, string $type, float $value, array $extra = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'codes']), ['title' => $title, 'status' => 'active', ...$extra, 'data' => ['promotion' => $school->id, 'discount_type' => $type, 'discount_value' => $value, 'max_uses' => 2]]);
        $code('save 10', 'percent', 150)->assertSessionHasErrors(['data.discount_value' => 'A percentage discount is between 1 and 100.']);
        $code('save 10', 'percent', 10, ['due_on' => today()->addDays(20)->toDateString()])->assertSessionHasErrors('due_on');
        $code(' save 10 ', 'percent', 10)->assertSessionHasNoErrors();
        $code('Save-10', 'fixed_amount', 5)->assertSessionHasErrors(['title' => 'SAVE10 is already a code.']);
        $save = Record::query()->where('entity', 'codes')->firstOrFail();
        $this->assertSame('SAVE10', $save->title);
        $this->assertSame(today()->addDays(10)->toDateString(), $save->due_on->toDateString());

        $this->actingAs($owner)->post($save->url().'/actions/redeem', ['order_value' => 200])->assertSessionHas('flash.message', 'SAVE10 applied: '.$this->money(20).' off '.$this->money(200).'.');
        $this->actingAs($owner)->post($save->url().'/actions/redeem', ['order_value' => 100])->assertSessionHas('flash.message', 'SAVE10 applied: '.$this->money(10).' off '.$this->money(100).'. That was the last use.');
        $this->assertSame('exhausted', $save->fresh()->status);
        $this->assertEquals(270, $school->fresh()->amount);

        $finished = $this->record($workspace, $app, 'promotions', 'Easter', 'live', [], ['occurs_on' => today()->subDays(10), 'due_on' => today()->subDay()]);
        $easter = $this->record($workspace, $app, 'codes', 'EASTER', 'active', ['promotion' => $finished->id, 'discount_type' => 'fixed_amount', 'discount_value' => 5]);
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('ended', $finished->fresh()->status);
        $this->assertSame('expired', $easter->fresh()->status);

        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Live promotions')->assertSee('Back to school');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Return on budget')->assertSee('2.7×');
    }

    public function test_seo_keywords_track_their_movement_and_short_codes_stay_unique(): void
    {
        $app = 'seo-analytics-dashboard';
        [$owner, $workspace] = $this->appWorkspace($app);
        $keyword = fn (string $title, $position) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'keywords']), ['title' => $title, 'status' => 'tracking', 'data' => ['position' => $position, 'monthly_searches' => 1000]]);
        $keyword('Best  CMS', 8)->assertSessionHasNoErrors();
        $keyword('BEST cms', 3)->assertSessionHasErrors(['title' => '"best cms" is already tracked.']);
        $keyword('Website builder', 0)->assertSessionHasErrors('data.position');
        $cms = Record::query()->where('entity', 'keywords')->firstOrFail();
        $this->assertSame('best cms', $cms->title);

        $this->actingAs($owner)->post($cms->url().'/actions/check', ['position' => 5])->assertSessionHas('flash.message', '"best cms" up 3 to #5.');
        $cms->refresh();
        $this->assertEquals(8, $cms->value('previous_position'));
        $this->assertCount(2, $cms->value('_history'));
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Biggest movers')->assertSee('up 3 to #5')->assertSee('70');
        $this->actingAs($owner)->post($cms->url().'/actions/check', ['position' => null])->assertSessionHas('flash.message', '"best cms" dropped out of the rankings from #5.');

        $link = fn (string $code) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'links']), ['title' => 'Spring flyer', 'status' => 'active', 'data' => ['destination' => 'https://example.com/spring', 'short_code' => $code, 'clicks' => 40]]);
        $link('Spring Sale!')->assertSessionHasNoErrors();
        $link('spring-sale')->assertSessionHasErrors(['data.short_code' => 'spring-sale is already taken.']);
        $link('ab')->assertSessionHasErrors('data.short_code');
        $spring = Record::query()->where('entity', 'links')->firstOrFail();
        $this->assertSame('spring-sale', $spring->value('short_code'));
        $this->actingAs($owner)->put(route('apps.records.update', [$app, 'links', $spring->id]), ['title' => 'Spring flyer', 'status' => 'active', 'data' => ['destination' => 'https://example.com/spring', 'short_code' => 'spring-sale', 'clicks' => 10]])
            ->assertSessionHasErrors(['data.clicks' => 'Clicks only go up; it already has 40.']);

        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Ranking spread')->assertSee('Not ranking');
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
