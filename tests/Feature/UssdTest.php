<?php

namespace Tests\Feature;

use App\Blueprints\BlueprintRegistry;
use App\Models\Record;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Tenancy\WorkspaceContext;
use App\Ussd\UssdMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Feature-phone access over USSD: recognising callers, PINs, logging and updating records, and the settings page. */
class UssdTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(bool $enabled = true): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['expenses'], $owner);
        $workspace->putSetting('ussd', ['enabled' => $enabled, 'token' => Str::random(40), 'service_code' => '*384*123#']);
        $this->withPhone($owner, $workspace, '077 123 4567');

        return [$owner->fresh(), $workspace->fresh()];
    }

    protected function withPhone(User $user, Workspace $workspace, string $phone, ?string $pin = '2468'): void
    {
        $user->update(['phone' => $phone]);
        WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $user->id)
            ->update(['ussd_pin' => $pin ? Hash::make($pin) : null]);
    }

    protected function dial(Workspace $workspace, string $text, string $phone = '+263771234567'): string
    {
        return $this->post(route('ussd.callback', $workspace->setting('ussd.token')), [
            'sessionId' => 'ATUid_1', 'serviceCode' => '*384*123#', 'networkCode' => '64801', 'phoneNumber' => $phone, 'text' => $text,
        ])->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8')->getContent();
    }

    protected function expense(Workspace $workspace, User $user, array $attributes = []): Record
    {
        return Record::factory()->ofEntity('expenses', 'expenses', ['category' => 'fuel'])
            ->create(['workspace_id' => $workspace->id, 'title' => 'Diesel for truck', 'status' => 'pending', 'amount' => 40, 'currency' => 'USD', 'created_by' => $user->id, ...$attributes]);
    }

    /** The menu position of the expenses list and of an option in its category field. */
    protected function expenseChoice(Workspace $workspace): int
    {
        $entities = app(WorkspaceContext::class)->run($workspace, fn () => app(UssdMenu::class)->loggableEntities());

        return collect($entities)->search(fn ($entity) => $entity->blueprintKey === 'expenses') + 1;
    }

    public function test_a_caller_logs_an_expense_by_answering_each_question(): void
    {
        [$owner, $workspace] = $this->workspace();
        $category = app(BlueprintRegistry::class)->entity('expenses', 'expenses')->field('category');
        $categoryChoice = array_search('fuel', array_keys($category->options), true) + 1;
        $list = $this->expenseChoice($workspace);

        $this->assertSame('CON '.$workspace->name."\nEnter your phone PIN", $this->dial($workspace, ''));
        $this->assertStringContainsString('1. My open items', $this->dial($workspace, '2468'));
        $this->assertStringContainsString($list.'. Expense', $this->dial($workspace, '2468*2'));
        $this->assertStringStartsWith('CON Enter', $this->dial($workspace, "2468*2*{$list}"));
        $this->assertStringContainsString('(USD)', $this->dial($workspace, "2468*2*{$list}*Fuel for generator"));
        $this->assertStringContainsString('1. ', $this->dial($workspace, "2468*2*{$list}*Fuel for generator*25.50"));

        $reply = $this->dial($workspace, "2468*2*{$list}*Fuel for generator*25.50*{$categoryChoice}");

        $record = Record::allWorkspaces()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame('END Saved Expense '.$record->number.'. Thank you.', $reply);
        $this->assertSame('Fuel for generator', $record->title);
        $this->assertEquals(25.5, $record->amount);
        $this->assertSame('USD', $record->currency);
        $this->assertSame('fuel', $record->data['category']);
        $this->assertSame($owner->id, $record->created_by);
        $this->assertTrue($record->occurs_on->isToday());
        $this->assertSame($record->definition()->defaultStatus(), $record->status);
    }

    public function test_invalid_answers_end_the_session_without_saving(): void
    {
        [, $workspace] = $this->workspace();
        $list = $this->expenseChoice($workspace);

        $this->assertStringStartsWith('END', $this->dial($workspace, "2468*2*{$list}*Fuel*lots"));
        $this->assertSame('END That is not a valid category. Please dial again.', $this->dial($workspace, "2468*2*{$list}*Fuel*10*99"));
        $this->assertSame('END Invalid choice. Please dial again.', $this->dial($workspace, '2468*7'));
        $this->assertSame(0, Record::allWorkspaces()->count());
    }

    public function test_unknown_numbers_and_members_without_a_pin_are_turned_away(): void
    {
        [, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);
        $this->withPhone($member, $workspace, '0779999999', null);

        $this->assertStringStartsWith('END This number is not linked to anyone', $this->dial($workspace, '', '+263770000000'));
        $this->assertSame('END Set your phone PIN in Zonseo under Phone access first.', $this->dial($workspace, '', '+263779999999'));
    }

    public function test_wrong_pins_lock_the_number_after_five_tries(): void
    {
        [, $workspace] = $this->workspace();

        for ($try = 0; $try < UssdMenu::MAX_PIN_ATTEMPTS; $try++) {
            $this->assertSame('END Wrong PIN.', $this->dial($workspace, '1357'));
        }

        $this->assertStringStartsWith('END Too many wrong PINs', $this->dial($workspace, '2468'));
    }

    public function test_the_service_must_be_switched_on_and_the_token_must_match(): void
    {
        [, $workspace] = $this->workspace(enabled: false);

        $this->assertStringStartsWith('END Phone access is switched off', $this->dial($workspace, ''));
        $this->post(route('ussd.callback', Str::random(40)), ['phoneNumber' => '+263771234567', 'text' => ''])->assertNotFound();
        $this->post(route('ussd.callback', 'short'), ['phoneNumber' => '+263771234567', 'text' => ''])->assertNotFound();
    }

    public function test_my_open_items_can_be_moved_to_a_new_status_and_noted(): void
    {
        [$owner, $workspace] = $this->workspace();
        $expense = $this->expense($workspace, $owner);
        $this->expense($workspace, $owner, ['title' => 'Old lunch', 'status' => 'rejected']);

        $list = $this->dial($workspace, '2468*1');
        $this->assertStringContainsString('1. '.$expense->number.' Diesel for truck', $list);
        $this->assertStringNotContainsString('Old lunch', $list);
        $this->assertStringContainsString("Status: Pending\nAmount: 40.00 USD", $this->dial($workspace, '2468*1*1'));

        $statuses = collect($expense->definition()->statuses)->except('pending');
        $choice = $statuses->keys()->search('approved') + 1;
        $this->assertStringContainsString($choice.'. Approved', $this->dial($workspace, '2468*1*1*1'));
        $this->assertSame('END '.$expense->number.' is now Approved.', $this->dial($workspace, "2468*1*1*1*{$choice}"));
        $this->assertSame('approved', $expense->fresh()->status);

        $this->assertSame('CON Type your note', $this->dial($workspace, '2468*3*'.$expense->number.'*2'));
        $this->assertSame('END Note added to '.$expense->number.'.', $this->dial($workspace, '2468*3*'.$expense->number.'*2*Receipt in the van'));
        $this->assertSame('Receipt in the van', $expense->comments()->sole()->body);
        $this->assertSame($owner->id, $expense->comments()->sole()->user_id);
    }

    public function test_records_are_found_by_full_number_or_trailing_digits_within_the_workspace_only(): void
    {
        [$owner, $workspace] = $this->workspace();
        $expense = $this->expense($workspace, $owner);
        [$otherOwner, $other] = $this->workspace();
        $foreign = $this->expense($other, $otherOwner, ['title' => 'Secret spend']);
        $digits = (string) (int) Str::afterLast($expense->number, '-');

        $this->assertStringContainsString('Diesel for truck', $this->dial($workspace, '2468*3*'.strtolower($expense->number)));
        $this->assertStringContainsString('Diesel for truck', $this->dial($workspace, '2468*3*'.$digits));
        $this->assertStringStartsWith('END No record found', $this->dial($workspace, '2468*3*NOPE-1'));

        $foreign->update(['number' => 'EXP-7777']);
        $this->assertStringStartsWith('END No record found', $this->dial($workspace, '2468*3*EXP-7777'));
    }

    public function test_viewers_can_look_up_but_not_change_or_log(): void
    {
        [$owner, $workspace] = $this->workspace();
        $expense = $this->expense($workspace, $owner);
        $viewer = $this->memberOf($workspace, 'viewer');
        $this->withPhone($viewer, $workspace, '0772222222');

        $this->assertSame('END Your role can only look records up.', $this->dial($workspace, '2468*2', '+263772222222'));
        $reply = $this->dial($workspace, '2468*3*'.$expense->number, '+263772222222');
        $this->assertStringStartsWith('END '.$expense->number, $reply);
        $this->assertStringNotContainsString('Change status', $reply);
        $this->assertSame($reply, $this->dial($workspace, '2468*3*'.$expense->number.'*1*1', '+263772222222'));
        $this->assertSame('pending', $expense->fresh()->status);
    }

    public function test_a_member_of_another_workspace_cannot_use_this_workspaces_number(): void
    {
        [, $workspace] = $this->workspace();
        [$stranger, $other] = $this->workspace();
        $this->withPhone($stranger, $other, '0773333333');

        $this->assertStringStartsWith('END This number is not linked', $this->dial($workspace, '2468', '+263773333333'));
    }

    public function test_members_set_their_own_pin_with_their_password(): void
    {
        [, $workspace] = $this->workspace();
        $member = $this->memberOf($workspace);
        $member->update(['phone' => '0774444444']);

        $this->actingAs($member)->get(route('ussd.index'))->assertOk()->assertSee('+263774444444')->assertSee('*384*123#')->assertDontSee('Callback URL');

        $this->put(route('ussd.pin.update'), ['pin' => '8642', 'pin_confirmation' => '8642', 'current_password' => 'wrong'])->assertSessionHasErrors('current_password');
        $this->put(route('ussd.pin.update'), ['pin' => '1234', 'pin_confirmation' => '1234', 'current_password' => 'password'])->assertSessionHasErrors('pin');
        $this->put(route('ussd.pin.update'), ['pin' => '8642', 'pin_confirmation' => '8642', 'current_password' => 'password'])->assertSessionHasNoErrors();

        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $member->id)->sole();
        $this->assertTrue(Hash::check('8642', $membership->ussd_pin));
        $this->assertStringContainsString('1. My open items', $this->dial($workspace, '8642', '+263774444444'));

        $this->delete(route('ussd.pin.destroy'))->assertRedirect();
        $this->assertStringStartsWith('END Set your phone PIN', $this->dial($workspace, '8642', '+263774444444'));
    }

    public function test_only_admins_configure_the_service_and_use_the_simulator(): void
    {
        [$owner] = $this->ownerWithWorkspace();
        $workspace = $owner->currentWorkspace;
        $workspace->enableModules(['expenses'], $owner);
        $this->withPhone($owner, $workspace, '0771234567');
        $member = $this->memberOf($workspace);

        $this->actingAs($member)->put(route('settings.ussd.update'), ['enabled' => 1])->assertForbidden();
        $this->post(route('settings.ussd.simulate'), ['phone' => '0771234567'])->assertForbidden();

        $this->actingAs($owner)->put(route('settings.ussd.update'), ['enabled' => 1, 'service_code' => 'call me'])->assertSessionHasErrors('service_code');
        $this->put(route('settings.ussd.update'), ['enabled' => 1, 'service_code' => '*384*55#'])->assertSessionHasNoErrors();
        $token = $workspace->fresh()->setting('ussd.token');
        $this->assertSame(40, strlen($token));
        $this->get(route('ussd.index'))->assertOk()->assertSee(route('ussd.callback', $token))->assertSee('Expenses');

        $this->postJson(route('settings.ussd.simulate'), ['phone' => '0771234567', 'text' => '2468'])
            ->assertOk()->assertJsonPath('reply', fn (string $reply) => str_contains($reply, '1. My open items'));

        $this->post(route('settings.ussd.token'))->assertRedirect();
        $this->assertNotSame($token, $workspace->fresh()->setting('ussd.token'));
        $this->post(route('ussd.callback', $token), ['phoneNumber' => '+263771234567', 'text' => ''])->assertNotFound();
    }
}
