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

/** Sales: live chat, loyalty, feedback, contracts, affiliates, proposals and commissions. */
class SalesAppsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
    }

    public function test_bot_replies_answer_chats_by_keyword_and_idle_chats_resolve(): void
    {
        $app = 'live-chat';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->record($workspace, $app, 'replies', 'Opening hours', 'active', ['keywords' => 'Hours, open', 'answer' => 'We open 8 to 5.']);
        $this->record($workspace, $app, 'replies', 'Complaint', 'active', ['keywords' => 'complaint', 'answer' => 'Sorry! A person will help you.', 'hand_over' => true]);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'replies']), ['title' => 'Times', 'status' => 'active', 'data' => ['keywords' => 'times, HOURS', 'answer' => 'Same.']])
            ->assertSessionHasErrors(['data.keywords' => '"hours" is already a keyword of the reply "Opening hours".']);

        $hours = $this->record($workspace, $app, 'conversations', 'Question', 'open', ['channel' => 'whatsapp', 'transcript' => 'What are your hours?']);
        $this->assertSame('waiting', $hours->status);
        $this->assertStringContainsString('Bot: We open 8 to 5.', $hours->value('transcript'));
        $angry = $this->record($workspace, $app, 'conversations', 'I have a complaint', 'open', ['channel' => 'website']);
        $this->assertSame('open', $angry->status);
        $this->actingAs($owner)->get($angry->url())->assertOk()->assertSee('Answered by the bot')->assertSee('handed over');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Open with nobody on them')->assertSee('I have a complaint');

        $this->actingAs($owner)->post($angry->url().'/actions/wait')->assertSessionHas('flash.message', 'I have a complaint is waiting on the customer; it resolves by itself after 7 quiet days.');
        $this->travel(8)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('resolved', $angry->fresh()->status);
        $this->assertSame('resolved', $hours->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Chats by channel')->assertSee('Whatsapp');
    }

    public function test_loyalty_points_build_a_balance_and_a_tier(): void
    {
        $app = 'loyalty';
        [$owner, $workspace] = $this->appWorkspace($app);
        $member = $this->record($workspace, $app, 'members', 'Thandiwe', 'active', ['card_number' => 'L1', 'points' => 100]);
        $sleeper = $this->record($workspace, $app, 'members', 'Sleeper', 'inactive');
        $move = fn (Record $who, string $type, int $points, float $spend = 0) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'transactions']), ['title' => 'Till', 'status' => 'posted', 'amount' => $spend, 'data' => ['member' => $who->id, 'type' => $type, 'points' => $points]]);

        $move($sleeper, 'earned', 10)->assertSessionHasErrors(['data.member' => 'Sleeper is inactive and cannot earn points.']);
        $move($member, 'earned', 0, 4500)->assertSessionHasNoErrors();
        $this->assertEquals(550, $member->fresh()->value('points'));
        $this->assertSame('silver', $member->fresh()->value('tier'));
        $move($member, 'redeemed', 600)->assertSessionHasErrors(['data.points' => 'Thandiwe only has 550 points.']);
        $move($member, 'redeemed', 200)->assertSessionHasNoErrors();
        $this->assertEquals(350, $member->fresh()->value('points'));
        $this->assertSame('silver', $member->fresh()->value('tier'));

        $redeem = Record::query()->where('entity', 'transactions')->get()->first(fn (Record $movement) => $movement->value('type') === 'redeemed');
        $this->actingAs($owner)->post($redeem->url().'/actions/reverse')->assertSessionHas('flash.message', 'Redeemed points reversed; Thandiwe now has 550 points.');
        $this->actingAs($owner)->get($member->url())->assertOk()->assertSee('550 points · Silver');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Top members')->assertSee('Thandiwe');
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Points by month')->assertSee('450')->assertSee($this->money(4500));
    }

    public function test_feedback_scores_roll_up_into_nps(): void
    {
        $app = 'feedback';
        [$owner, $workspace] = $this->appWorkspace($app);
        $survey = $this->record($workspace, $app, 'surveys', 'After-sale survey', 'draft', ['questions' => 'How likely are you to recommend us?'], ['due_on' => today()->addDays(10)]);
        $respond = fn (string $who, int $score) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'responses']), ['title' => $who, 'status' => 'new', 'data' => ['survey' => $survey->id, 'source' => 'survey', 'score' => $score]]);

        $respond('Early', 9)->assertSessionHasErrors(['data.survey' => 'Survey After-sale survey is draft and not taking responses.']);
        $this->actingAs($owner)->post($survey->url().'/actions/launch')->assertSessionHas('flash.message', 'After-sale survey is live until '.today()->addDays(10)->format('d M Y').'.');
        $respond('Ann', 11)->assertSessionHasErrors(['data.score' => 'Scores run from 0 to 10.']);
        $respond('Ann', 10)->assertSessionHasNoErrors();
        $respond('Ben', 9)->assertSessionHasNoErrors();
        $respond('Cat', 8)->assertSessionHasNoErrors();
        $respond('Dan', 3)->assertSessionHasNoErrors();
        $this->assertEquals(4, $survey->fresh()->value('responses'));
        $this->actingAs($owner)->get($survey->url())->assertOk()->assertSee('NPS')->assertSee('25');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Unhappy customers to answer')->assertSee('Dan');

        $dan = Record::query()->where('entity', 'responses')->where('title', 'Dan')->firstOrFail();
        $this->actingAs($owner)->post($dan->url().'/actions/reply', ['reply' => ''])->assertSessionHasErrors(['reply' => 'Write the reply.']);
        $this->actingAs($owner)->post($dan->url().'/actions/reply', ['reply' => 'Sorry, we will call you.'])->assertSessionHas('flash.message', 'Reply saved for Dan.');
        $this->assertSame('actioned', $dan->fresh()->status);

        $this->travel(11)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('closed', $survey->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('NPS by month')->assertSee('Feedback by source')->assertSee('7.5');
    }

    public function test_contracts_need_a_signature_and_renew_or_expire(): void
    {
        $app = 'contracts';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'contracts']), ['title' => 'Supply deal', 'status' => 'active', 'occurs_on' => today()->toDateString(), 'due_on' => today()->subDay()->toDateString(), 'data' => ['type' => 'supply', 'signed_on' => today()->addDay()->toDateString()]])
            ->assertSessionHasErrors(['due_on' => 'The contract must end after it starts.', 'data.signatory' => 'Give the name of the person who signed.', 'data.signed_on' => 'The signing date can\'t be in the future.']);

        $deal = $this->record($workspace, $app, 'contracts', 'Supply deal', 'sent', ['type' => 'supply'], ['occurs_on' => today(), 'due_on' => today()->addDays(30), 'amount' => 12000]);
        $this->actingAs($owner)->post($deal->url().'/actions/sign', ['signatory' => '', 'signed_on' => today()->toDateString()])->assertSessionHasErrors(['signatory' => 'Give the name of the person who signed.']);
        $this->actingAs($owner)->post($deal->url().'/actions/sign', ['signatory' => 'M. Phiri', 'signed_on' => today()->toDateString()])->assertSessionHas('flash.message', 'Supply deal signed by M. Phiri; active until '.today()->addDays(30)->format('d M Y').'.');
        $this->assertSame('active', $deal->fresh()->status);
        $renewing = $this->record($workspace, $app, 'contracts', 'Cleaning service', 'active', ['type' => 'service', 'signatory' => 'B', 'signed_on' => today()->toDateString(), 'auto_renew' => true], ['occurs_on' => today(), 'due_on' => today()->addDays(30)]);
        $obligation = $this->record($workspace, $app, 'obligations', 'Deliver first load', 'pending', ['contract' => $deal->id, 'owner' => 'us'], ['due_on' => today()->addDays(5)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Ending in the next 60 days')->assertSee('Supply deal')->assertSee('Auto-renews');

        $this->travel(31)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $deal->fresh()->status);
        $this->assertSame('missed', $obligation->fresh()->status);
        $this->assertSame('active', $renewing->fresh()->status);
        $this->assertTrue($renewing->fresh()->due_on->isSameDay(today()->addDays(30)));
        $this->assertEquals(1, $renewing->fresh()->value('_renewals'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'obligations']), ['title' => 'Late item', 'status' => 'pending', 'data' => ['contract' => $deal->id, 'owner' => 'them']])
            ->assertSessionHasErrors(['data.contract' => 'Supply deal is expired.']);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Contracts by type')->assertSee('Supply');
    }

    public function test_referrals_earn_the_affiliates_rate_and_get_paid(): void
    {
        $app = 'affiliate-referral-management';
        [$owner, $workspace] = $this->appWorkspace($app);
        $ali = $this->record($workspace, $app, 'affiliates', 'Ali', 'active', ['code' => 'ali10', 'commission_rate' => 10]);
        $this->assertSame('ALI10', $ali->value('code'));
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'affiliates']), ['title' => 'Copycat', 'status' => 'active', 'data' => ['code' => 'Ali10', 'commission_rate' => 60]])
            ->assertSessionHasErrors(['data.code' => 'Referral code ALI10 is already taken.', 'data.commission_rate' => 'Commission must be between 0 and 50%.']);
        $paused = $this->record($workspace, $app, 'affiliates', 'Paused Pat', 'paused', ['code' => 'PAT']);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'referrals']), ['title' => 'New shop', 'status' => 'pending', 'data' => ['affiliate' => $paused->id]])
            ->assertSessionHasErrors(['data.affiliate' => 'Paused Pat is paused and cannot take new referrals.']);

        $one = $this->record($workspace, $app, 'referrals', 'Kafue Lodge', 'pending', ['affiliate' => $ali->id]);
        $this->actingAs($owner)->post($one->url().'/actions/convert', ['sale_value' => ''])->assertSessionHasErrors(['sale_value' => 'Give the sale value.']);
        $this->actingAs($owner)->post($one->url().'/actions/convert', ['sale_value' => 2000])->assertSessionHas('flash.message', 'Kafue Lodge converted; '.$this->money(200).' commission to Ali.');
        $ali->update(['data' => [...$ali->data, 'commission_rate' => 20]]);
        $one->fresh()->save();
        $this->assertEquals(200, $one->fresh()->amount);
        $this->record($workspace, $app, 'referrals', 'Lusaka Deli', 'converted', ['affiliate' => $ali->id, 'sale_value' => 500]);
        $this->actingAs($owner)->get($ali->url())->assertOk()->assertSee('Owed '.$this->money(300));
        $this->actingAs($owner)->post($ali->fresh()->url().'/actions/pay_all')->assertSessionHas('flash.message', 'Paid Ali '.$this->money(300).' for 2 referrals.');
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Top affiliates')->assertSee($this->money(2500));
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Referrals by affiliate')->assertSee($this->money(300));
    }

    public function test_proposals_are_priced_sent_and_expire(): void
    {
        $app = 'proposals-sales-documents-builder';
        [$owner, $workspace] = $this->appWorkspace($app);
        $template = $this->record($workspace, $app, 'templates', 'Website build', 'active', ['body' => 'Design and build a five-page website.']);
        $this->actingAs($owner)->post($template->url().'/actions/use', ['title' => 'Website for Zed Foods'])->assertSessionHas('flash.message', 'Draft proposal Website for Zed Foods started from Website build.');
        $proposal = Record::query()->where('entity', 'proposals')->firstOrFail();
        $this->assertSame('Design and build a five-page website.', $proposal->value('scope'));

        $this->actingAs($owner)->post($proposal->url().'/actions/send')->assertSessionHasErrors(['amount' => 'Price the proposal before it goes out.']);
        $proposal->update(['amount' => 15000]);
        $this->actingAs($owner)->post($proposal->url().'/actions/send')->assertSessionHas('flash.message', 'Website for Zed Foods sent; valid until '.today()->addDays(30)->format('d M Y').'.');
        $this->actingAs($owner)->post($proposal->url().'/actions/viewed')->assertSessionHas('flash.message', 'Website for Zed Foods was viewed.');
        $this->actingAs($owner)->post($proposal->url().'/actions/accept')->assertSessionHas('flash.message', 'Website for Zed Foods accepted for '.$this->money(15000).'.');

        $slow = $this->record($workspace, $app, 'proposals', 'Logo refresh', 'sent', ['scope' => 'New logo.'], ['amount' => 3000, 'occurs_on' => today(), 'due_on' => today()->addDays(3)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Expiring in 7 days')->assertSee('Logo refresh');
        $this->travel(4)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('expired', $slow->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Proposals by month')->assertSee('50%')->assertSee($this->money(15000));
    }

    public function test_commissions_count_toward_the_reps_target(): void
    {
        $app = 'sales-commissions-targets';
        [$owner, $workspace] = $this->appWorkspace($app);
        $this->actingAs($owner)->post(route('apps.records.store', [$app, 'targets']), ['title' => 'This month', 'status' => 'active', 'amount' => 10000, 'occurs_on' => today()->toDateString(), 'data' => ['rep' => $owner->id]])
            ->assertSessionHasErrors(['due_on' => 'Give the period start and end.']);
        $target = $this->record($workspace, $app, 'targets', 'This month', 'active', ['rep' => $owner->id], ['amount' => 10000, 'occurs_on' => today(), 'due_on' => today()->addDays(20)]);
        $sell = fn (string $deal, float $value, array $data = []) => $this->actingAs($owner)->post(route('apps.records.store', [$app, 'commissions']), ['title' => $deal, 'status' => 'earned', 'occurs_on' => today()->toDateString(), 'data' => ['rep' => $owner->id, 'sale_value' => $value, ...$data]]);

        $sell('Big deal', 6000, ['rate' => 150])->assertSessionHasErrors(['data.rate' => 'The rate must be between 0 and 100%.']);
        $sell('Big deal', 6000)->assertSessionHasNoErrors();
        $deal = Record::query()->where('entity', 'commissions')->firstOrFail();
        $this->assertEquals(300, $deal->amount);
        $this->assertEquals(6000, $target->fresh()->value('achieved'));
        $this->assertSame('active', $target->fresh()->status);
        $this->actingAs($owner)->get($target->url())->assertOk()->assertSee('60%')->assertSee($this->money(4000));
        $sell('Second deal', 4000, ['rate' => 10])->assertSessionHasNoErrors();
        $this->assertSame('achieved', $target->fresh()->status);

        $this->actingAs($owner)->post($deal->url().'/actions/approve')->assertSessionHas('flash.message', $this->money(300).' commission on Big deal approved.');
        $this->actingAs($owner)->post($deal->url().'/actions/pay')->assertSessionHas('flash.message', $this->money(300).' commission on Big deal paid.');
        $short = $this->record($workspace, $app, 'targets', 'Stretch', 'active', ['rep' => $owner->id], ['amount' => 50000, 'occurs_on' => today(), 'due_on' => today()->addDays(2)]);
        $this->actingAs($owner)->get(route('apps.show', $app))->assertOk()->assertSee('Targets this period')->assertSee('20%');
        $this->travel(3)->days();
        Artisan::call('zonseo:run-app-schedules', ['--app' => $app]);
        $this->assertSame('missed', $short->fresh()->status);
        $this->travelBack();
        $this->actingAs($owner)->get(route('apps.reports', $app))->assertOk()->assertSee('Commissions by rep')->assertSee($owner->name)->assertSee($this->money(700));
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
