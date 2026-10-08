<?php

namespace Tests\Feature;

use App\Models\SmsMessage;
use App\Models\User;
use App\Models\Workspace;
use App\Sms\PhoneNumber;
use App\Sms\Providers\AfricasTalkingProvider;
use App\Sms\Providers\TwilioProvider;
use App\Sms\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Invoicing\Models\Invoice;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

class SmsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    /** @var array{status: int, body: array<string, mixed>} what the fake Twilio answers; tests change it */
    protected array $twilio = ['status' => 201, 'body' => ['sid' => 'SM123']];

    /** @var array<string, mixed> what the fake Africa's Talking answers */
    protected array $africasTalking = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Http::preventStrayRequests();
        Http::fake([
            TwilioProvider::API.'/*' => fn () => Http::response($this->twilio['body'], $this->twilio['status']),
            AfricasTalkingProvider::API => fn () => Http::response($this->africasTalking),
        ]);
    }

    /** @return array{0: User, 1: Workspace, 2: Contact} */
    protected function smsWorkspace(string $provider = 'test', array $notify = []): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['invoicing', 'appointments'], $owner);
        $workspace->update(['name' => 'Sunrise Clinic', 'country_code' => 'ZW']);
        $contact = Contact::factory()->for($workspace)->create(['name' => 'Tendai Moyo', 'kind' => 'person', 'company_name' => null, 'type' => 'customer', 'mobile' => '077 123 4567', 'phone' => null, 'country_code' => 'ZW']);

        app(SmsService::class)->configure($workspace, $provider, [
            'twilio' => ['account_sid' => 'AC123', 'auth_token' => 'secret-token', 'from' => '+15005550006'],
            'africastalking' => ['username' => 'zonseo', 'api_key' => 'at-key'],
        ], $notify);

        return [$owner, $workspace->fresh(), $contact];
    }

    public function test_phone_numbers_are_normalised_to_international_format(): void
    {
        $this->assertSame('+263771234567', PhoneNumber::normalize('077 123 4567', 'ZW'));
        $this->assertSame('+263771234567', PhoneNumber::normalize('+263 77 123 4567', 'ZW'));
        $this->assertSame('+263771234567', PhoneNumber::normalize('00263771234567', 'KE'));
        $this->assertSame('+254712345678', PhoneNumber::normalize('0712 345 678', 'KE'));
        $this->assertNull(PhoneNumber::normalize('12', 'ZW'));
        $this->assertNull(PhoneNumber::normalize(null, 'ZW'));
        $this->assertNull(PhoneNumber::normalize('0771234567', null));
    }

    public function test_message_parts_follow_the_gsm_and_unicode_limits(): void
    {
        $this->assertSame(1, SmsMessage::segmentsFor(str_repeat('a', 160)));
        $this->assertSame(2, SmsMessage::segmentsFor(str_repeat('a', 161)));
        $this->assertSame(1, SmsMessage::segmentsFor(str_repeat('é', 70)));
        $this->assertSame(2, SmsMessage::segmentsFor(str_repeat('ü', 71).'😀'));
    }

    public function test_the_owner_saves_the_provider_with_encrypted_secrets_and_blank_secrets_keep_their_value(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->put(route('settings.sms.update'), [
            'provider' => 'twilio',
            'twilio' => ['account_sid' => 'AC999', 'auth_token' => 'tok-abc', 'from' => '+15005550006'],
            'notify' => ['receipts' => 1, 'overdue' => 0],
        ])->assertRedirect()->assertSessionHas('flash.type', 'success');

        $workspace->refresh();
        $this->assertSame('twilio', $workspace->setting('sms.provider'));
        $this->assertNotSame('tok-abc', $workspace->setting('sms.twilio.auth_token'));
        $this->assertSame('tok-abc', Crypt::decryptString($workspace->setting('sms.twilio.auth_token')));
        $this->assertTrue($workspace->setting('sms.notify.receipts'));
        $this->assertFalse($workspace->setting('sms.notify.overdue'));

        $this->actingAs($owner)->put(route('settings.sms.update'), [
            'provider' => 'twilio',
            'twilio' => ['account_sid' => 'AC999', 'auth_token' => '', 'from' => '+15005550006'],
        ])->assertRedirect();
        $this->assertSame('tok-abc', app(SmsService::class)->credentials($workspace->fresh(), new TwilioProvider)['auth_token']);

        $this->actingAs($owner)->get(route('settings.sms.edit'))->assertOk()->assertSee('••••-abc')->assertDontSee('tok-abc');
    }

    public function test_a_provider_without_credentials_is_saved_but_not_active(): void
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();

        $this->actingAs($owner)->put(route('settings.sms.update'), ['provider' => 'infobip'])
            ->assertSessionHas('flash.type', 'warning');

        $this->assertFalse(app(SmsService::class)->enabled($workspace->fresh()));
    }

    public function test_members_cannot_manage_or_send_sms(): void
    {
        [, $workspace] = $this->smsWorkspace();
        $member = $this->memberOf($workspace, 'member');

        $this->actingAs($member)->get(route('sms.index'))->assertForbidden();
        $this->actingAs($member)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => 'Hi'])->assertForbidden();
        $this->actingAs($member)->put(route('settings.sms.update'), ['provider' => 'test'])->assertForbidden();
        $this->assertSame(0, SmsMessage::allWorkspaces()->count());
    }

    public function test_test_mode_logs_the_message_without_calling_a_provider(): void
    {
        [$owner, $workspace] = $this->smsWorkspace('test');

        $this->actingAs($owner)->post(route('settings.sms.test'), ['to' => '0771234567'])
            ->assertSessionHas('flash.type', 'success');

        $message = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('+263771234567', $message->to);
        $this->assertSame('sent', $message->status);
        $this->assertSame('test', $message->purpose);
        $this->assertStringStartsWith('test-', $message->provider_message_id);
        $this->assertSame($workspace->id, $message->workspace_id);
        Http::assertNothingSent();
    }

    public function test_twilio_sends_with_basic_auth_and_records_the_message_id(): void
    {
        [$owner] = $this->smsWorkspace('twilio');

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => 'Your results are ready.'])
            ->assertSessionHas('flash.type', 'success');

        Http::assertSent(fn (HttpRequest $request) => $request->url() === TwilioProvider::API.'/Accounts/AC123/Messages.json'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC123:secret-token'))
            && $request['To'] === '+263771234567' && $request['From'] === '+15005550006' && $request['Body'] === 'Your results are ready.');

        $message = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame('SM123', $message->provider_message_id);
        $this->assertSame($owner->id, $message->sent_by);
    }

    public function test_a_provider_refusal_marks_the_message_failed_with_the_reason(): void
    {
        [$owner] = $this->smsWorkspace('twilio');
        $this->twilio = ['status' => 400, 'body' => ['message' => "The 'To' number is not a valid phone number."]];

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => 'Hello'])->assertRedirect();

        $message = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('not a valid phone number', $message->error);
    }

    public function test_a_provider_outage_fails_the_message_instead_of_breaking_the_page(): void
    {
        [$owner] = $this->smsWorkspace('twilio');
        $this->twilio = ['status' => 503, 'body' => ['message' => 'Service unavailable']];

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => 'Hello'])->assertRedirect();

        $this->assertSame('failed', SmsMessage::allWorkspaces()->sole()->status);
    }

    public function test_africas_talking_rejections_per_recipient_are_reported(): void
    {
        [$owner] = $this->smsWorkspace('africastalking');
        $this->africasTalking = ['SMSMessageData' => ['Message' => 'Sent to 0/1', 'Recipients' => [['statusCode' => 403, 'status' => 'InvalidPhoneNumber', 'number' => '+263771234567']]]];

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => 'Hello'])->assertRedirect();

        $message = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('InvalidPhoneNumber', $message->error);
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('apiKey', 'at-key') && $request['username'] === 'zonseo');
    }

    public function test_an_invalid_number_is_rejected_before_anything_is_logged(): void
    {
        [$owner] = $this->smsWorkspace();

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '12', 'body' => 'Hello'])->assertSessionHasErrors('to');
        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'number', 'to' => '0771234567', 'body' => str_repeat('a', SmsService::MAX_LENGTH + 1)])->assertSessionHasErrors('body');
        $this->assertSame(0, SmsMessage::allWorkspaces()->count());
    }

    public function test_bulk_messages_reach_each_number_in_the_group_once(): void
    {
        [$owner, $workspace, $tendai] = $this->smsWorkspace();
        Contact::factory()->for($workspace)->create(['type' => 'customer', 'mobile' => null, 'phone' => '0772 000 111', 'country_code' => 'ZW']);
        Contact::factory()->for($workspace)->create(['type' => 'customer', 'mobile' => '+263771234567', 'phone' => null]);
        Contact::factory()->for($workspace)->create(['type' => 'customer', 'mobile' => null, 'phone' => null]);
        Contact::factory()->for($workspace)->create(['type' => 'supplier', 'mobile' => '0773 999 888', 'phone' => null, 'country_code' => 'ZW']);
        $other = $this->smsWorkspace()[1];
        Contact::factory()->for($other)->create(['type' => 'customer', 'mobile' => '0774 555 666', 'country_code' => 'ZW']);

        $this->actingAs($owner)->post(route('sms.store'), ['audience' => 'customers', 'body' => 'We are open on Saturday.'])
            ->assertRedirect(route('sms.index'))->assertSessionHas('flash.message', '2 messages queued.');

        $messages = SmsMessage::allWorkspaces()->orderBy('id')->get();
        $this->assertSame(['+263771234567', '+263772000111'], $messages->pluck('to')->all());
        $this->assertSame($tendai->id, $messages->first()->contact_id);
        $this->assertSame(['bulk'], $messages->pluck('purpose')->unique()->values()->all());
        $this->assertCount(1, $messages->pluck('batch')->unique());
        $this->assertTrue($messages->every(fn (SmsMessage $message) => $message->workspace_id === $workspace->id && $message->status === 'sent'));

        $this->actingAs($owner)->get(route('sms.index'))->assertOk()->assertSee('Tendai Moyo')->assertSee('We are open on Saturday.');
    }

    public function test_sending_an_invoice_by_sms_includes_the_pay_link_and_marks_it_sent(): void
    {
        [$owner, $workspace, $contact] = $this->smsWorkspace();
        $invoice = Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 40, 'tax_rate' => 0],
        ])->create(['contact_id' => $contact->id, 'currency_code' => 'USD']);
        $this->assertSame('draft', $invoice->status);

        $this->actingAs($owner)->get(route('invoices.show', $invoice))->assertOk()->assertSee('Send by SMS');
        $this->actingAs($owner)->post(route('invoices.sms', $invoice))->assertSessionHas('flash.type', 'success');

        $message = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('invoice', $message->purpose);
        $this->assertTrue($message->subject->is($invoice));
        $this->assertStringStartsWith('Sunrise Clinic: Invoice '.$invoice->number, $message->body);
        $this->assertStringContainsString($invoice->publicUrl(), $message->body);
        $this->assertSame('sent', $invoice->fresh()->status);
    }

    public function test_a_payment_texts_a_receipt_only_when_receipts_are_switched_on(): void
    {
        [$owner, $workspace, $contact] = $this->smsWorkspace('test', ['receipts' => true]);
        $invoice = Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Consultation', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0],
        ])->sent()->create(['contact_id' => $contact->id, 'currency_code' => 'USD']);

        $this->actingAs($owner)->post(route('invoices.payments.store', $invoice), ['amount' => 60, 'paid_on' => today()->format('Y-m-d'), 'method' => 'cash'])->assertRedirect();

        $receipt = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('receipt', $receipt->purpose);
        $this->assertStringContainsString('We received', $receipt->body);
        $this->assertStringContainsString('Balance:', $receipt->body);

        app(SmsService::class)->configure($workspace, 'test', [], ['receipts' => false]);
        $this->actingAs($owner)->post(route('invoices.payments.store', $invoice), ['amount' => 40, 'paid_on' => today()->format('Y-m-d'), 'method' => 'cash'])->assertRedirect();
        $this->assertSame(1, SmsMessage::allWorkspaces()->count());
    }

    public function test_overdue_reminders_go_weekly_and_stop_after_three(): void
    {
        [, $workspace, $contact] = $this->smsWorkspace('test', ['overdue' => true]);
        $invoice = Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Rent', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0],
        ])->overdue()->create(['contact_id' => $contact->id, 'currency_code' => 'USD']);

        $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();
        $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();
        $this->assertSame(1, SmsMessage::allWorkspaces()->where('purpose', 'overdue')->count());
        $this->assertStringContainsString('Reminder: invoice '.$invoice->number, SmsMessage::allWorkspaces()->sole()->body);

        for ($week = 1; $week <= 3; $week++) {
            $this->travel(8)->days();
            $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();
        }
        $this->assertSame(3, SmsMessage::allWorkspaces()->where('purpose', 'overdue')->count());
    }

    public function test_appointment_reminders_go_once_the_day_before(): void
    {
        [, $workspace, $contact] = $this->smsWorkspace('test', ['appointments' => true]);
        $tomorrow = now($workspace->timezone ?: config('app.timezone'))->addDay()->setTime(10, 30);
        Appointment::factory()->for($workspace)->create(['contact_id' => $contact->id, 'status' => 'scheduled', 'title' => 'Check-up', 'starts_at' => $tomorrow, 'ends_at' => $tomorrow->copy()->addHour()]);
        Appointment::factory()->for($workspace)->create(['contact_id' => $contact->id, 'status' => 'cancelled', 'starts_at' => $tomorrow, 'ends_at' => $tomorrow->copy()->addHour()]);
        Appointment::factory()->for($workspace)->create(['contact_id' => $contact->id, 'status' => 'scheduled', 'starts_at' => $tomorrow->copy()->addDays(2), 'ends_at' => $tomorrow->copy()->addDays(2)->addHour()]);

        $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();
        $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();

        $reminder = SmsMessage::allWorkspaces()->sole();
        $this->assertSame('appointment', $reminder->purpose);
        $this->assertStringContainsString('Check-up tomorrow', $reminder->body);
        $this->assertStringContainsString('10:30', $reminder->body);
    }

    public function test_reminders_are_skipped_when_the_automation_is_off(): void
    {
        [, $workspace, $contact] = $this->smsWorkspace('test');
        Invoice::factory()->for($workspace)->withLines([
            ['description' => 'Rent', 'quantity' => 1, 'unit_price' => 300, 'tax_rate' => 0],
        ])->overdue()->create(['contact_id' => $contact->id]);

        $this->artisan('zonseo:send-sms-reminders')->assertSuccessful();

        $this->assertSame(0, SmsMessage::allWorkspaces()->count());
    }
}
