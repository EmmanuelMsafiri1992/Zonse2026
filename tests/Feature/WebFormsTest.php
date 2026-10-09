<?php

namespace Tests\Feature;

use App\Models\CustomField;
use App\Models\FormSubmission;
use App\Models\Record;
use App\Models\User;
use App\Models\WebForm;
use App\Models\Workspace;
use App\Notifications\WorkspaceAlert;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Tests\Concerns\InteractsWithWorkspaces;
use Tests\TestCase;

/** Public forms: building them, sharing them and turning visitors' answers into records. */
class WebFormsTest extends TestCase
{
    use InteractsWithWorkspaces;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCatalogue();
        Notification::fake();
    }

    /** @return array{0: User, 1: Workspace} */
    protected function workspace(): array
    {
        [$owner, $workspace] = $this->ownerWithWorkspace();
        $workspace->enableModules(['contacts', 'helpdesk', 'clinic'], $owner);

        return [$owner, $workspace->fresh()];
    }

    /** @param array<string, mixed> $attributes */
    protected function form(Workspace $workspace, array $attributes = []): WebForm
    {
        return WebForm::factory()->create(['workspace_id' => $workspace->id] + $attributes);
    }

    /** A token saying the visitor opened the form a minute ago. */
    protected function started(int $secondsAgo = 60): string
    {
        return Crypt::encryptString((string) now()->subSeconds($secondsAgo)->getTimestamp());
    }

    public function test_an_admin_can_build_a_form_and_share_it(): void
    {
        [$owner] = $this->workspace();

        $this->actingAs($owner)->get(route('forms.index'))->assertOk()->assertSee('No forms yet');
        $this->get(route('dashboard'))->assertSee(route('forms.index'), false);
        $this->get(route('forms.create'))->assertOk()->assertSee('Helpdesk tickets')->assertSee('Patients');

        $this->post(route('forms.store'), ['name' => 'Newsletter', 'target' => 'contact'])->assertRedirect();
        $form = WebForm::query()->firstOrFail();
        $this->assertSame(['name', 'email', 'phone', 'notes'], array_column($form->fields, 'key'));
        $this->assertSame('Newsletter', $form->title);

        $this->get(route('forms.edit', $form))->assertOk()->assertSee('Questions')->assertSee('Company');

        $this->put(route('forms.update', $form), [
            'name' => 'Newsletter sign-up', 'title' => 'Join our list', 'intro' => 'Offers first.', 'success_message' => 'You are in!', 'is_active' => '1',
            'fields' => json_encode([
                ['key' => 'email', 'label' => 'Your email', 'required' => true, 'help' => 'We never share it'],
                ['key' => 'email', 'label' => 'Twice', 'required' => false],
                ['key' => 'password', 'label' => 'Sneaky', 'required' => true],
                ['key' => 'company_name', 'label' => '', 'required' => false],
            ]),
        ])->assertRedirect(route('forms.show', $form));

        $form->refresh();
        $this->assertSame([
            ['key' => 'email', 'label' => 'Your email', 'required' => true, 'help' => 'We never share it'],
            ['key' => 'company_name', 'label' => 'Company', 'required' => false, 'help' => null],
            ['key' => 'name', 'label' => 'Full name', 'required' => true, 'help' => null],
        ], $form->fields);

        $this->get(route('forms.show', $form))->assertOk()
            ->assertSee($form->publicUrl())->assertSee('&lt;iframe', false)->assertSee('No answers yet');
        $this->get(route('forms.index'))->assertSee('Newsletter sign-up')->assertSee('Taking answers');

        $this->delete(route('forms.destroy', $form))->assertRedirect(route('forms.index'));
        $this->assertModelMissing($form);
    }

    public function test_a_form_can_only_create_what_the_workspace_has_switched_on(): void
    {
        [$owner] = $this->workspace();

        foreach (['task', 'appointment', 'clinic.visits', 'school.pupils', 'nowhere'] as $target) {
            $this->actingAs($owner)->post(route('forms.store'), ['name' => 'Test', 'target' => $target])->assertSessionHasErrors('target');
        }
        $this->assertSame(0, WebForm::query()->count());
    }

    public function test_a_contact_form_validates_and_creates_or_updates_the_person(): void
    {
        [$owner, $workspace] = $this->workspace();
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'contact', 'label' => 'Town', 'key' => 'town', 'type' => 'text']);
        $form = $this->form($workspace, ['fields' => [
            ['key' => 'name', 'label' => 'Full name', 'required' => true, 'help' => null],
            ['key' => 'email', 'label' => 'Email address', 'required' => true, 'help' => null],
            ['key' => 'custom.town', 'label' => 'Where do you live?', 'required' => true, 'help' => null],
        ]]);

        $this->get($form->publicUrl())->assertOk()
            ->assertSee('Join our mailing list')->assertSee('Where do you live?')->assertSee('name="custom__town"', false)
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $answers = ['name' => 'Tendai Moyo', 'email' => 'Tendai@Example.com', 'custom__town' => 'Gweru'];
        $this->post(route('forms.public.store', $form->uuid), $answers + ['started' => $this->started(1)])->assertSessionHasErrors('started');
        $this->post(route('forms.public.store', $form->uuid), $answers + ['started' => 'forged'])->assertSessionHasErrors('started');
        $this->post(route('forms.public.store', $form->uuid), $answers + ['started' => $this->started(), 'website' => 'spam.example'])->assertSessionHasErrors('website');
        $this->post(route('forms.public.store', $form->uuid), ['name' => 'Tendai Moyo', 'started' => $this->started()])->assertSessionHasErrors(['email', 'custom__town']);
        $this->assertSame(0, Contact::query()->count());

        $this->post(route('forms.public.store', $form->uuid), $answers + ['started' => $this->started()])
            ->assertRedirect(route('forms.public.thanks', $form->uuid));
        $this->get(route('forms.public.thanks', $form->uuid))->assertOk()->assertSee('Thanks, you are on the list.');

        $contact = Contact::query()->firstOrFail();
        $this->assertSame(['Tendai Moyo', 'tendai@example.com', 'Gweru'], [$contact->name, $contact->email, $contact->customField('town')]);
        $submission = FormSubmission::query()->firstOrFail();
        $this->assertTrue($submission->subject->is($contact));
        $this->assertSame([
            ['label' => 'Full name', 'value' => 'Tendai Moyo'],
            ['label' => 'Email address', 'value' => 'Tendai@Example.com'],
            ['label' => 'Where do you live?', 'value' => 'Gweru'],
        ], $submission->data);
        $this->assertSame('Full name: Tendai Moyo · Email address: Tendai@Example.com · Where do you live?: Gweru', $submission->summary());
        $this->assertSame(1, $form->fresh()->submissions_count);
        Notification::assertSentTo($owner, WorkspaceAlert::class);

        $this->post(route('forms.public.store', $form->uuid), ['name' => 'T. Moyo', 'email' => 'tendai@example.com', 'custom__town' => 'Harare', 'started' => $this->started()])->assertRedirect();
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame('Harare', $contact->fresh()->customField('town'));
        $this->assertSame(2, $form->fresh()->submissions_count);

        $this->actingAs($owner)->get(route('forms.show', $form))->assertOk()->assertSee('Harare')->assertSee(route('contacts.show', $contact));
    }

    public function test_a_request_form_opens_a_helpdesk_ticket(): void
    {
        [, $workspace] = $this->workspace();
        $form = $this->form($workspace, ['target' => 'ticket', 'fields' => [
            ['key' => 'requester_name', 'label' => 'Name', 'required' => false, 'help' => null],
            ['key' => 'requester_email', 'label' => 'Email', 'required' => true, 'help' => null],
            ['key' => 'subject', 'label' => 'Subject', 'required' => true, 'help' => null],
            ['key' => 'body', 'label' => 'Details', 'required' => true, 'help' => null],
        ]]);

        $this->post(route('forms.public.store', $form->uuid), [
            'requester_name' => 'Rudo', 'requester_email' => 'rudo@example.com', 'subject' => 'Broken tap', 'body' => 'Kitchen tap leaks.', 'started' => $this->started(),
        ])->assertRedirect();

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(['Broken tap', 'web', 'rudo@example.com', 'Rudo'], [$ticket->subject, $ticket->channel, $ticket->requester_email, $ticket->requester_name]);
        $this->assertSame('rudo@example.com', $ticket->contact?->email);
    }

    public function test_an_app_form_creates_a_record_with_its_fields_and_contact(): void
    {
        [, $workspace] = $this->workspace();
        CustomField::factory()->create(['workspace_id' => $workspace->id, 'entity' => 'clinic.patients', 'label' => 'Referred by', 'key' => 'referred_by', 'type' => 'text']);
        $form = $this->form($workspace, ['target' => 'clinic.patients', 'fields' => [
            ['key' => 'title', 'label' => 'Patient name', 'required' => true, 'help' => null],
            ['key' => 'data.date_of_birth', 'label' => 'Date of birth', 'required' => true, 'help' => null],
            ['key' => 'data.sex', 'label' => 'Sex', 'required' => false, 'help' => null],
            ['key' => 'contact.email', 'label' => 'Email', 'required' => true, 'help' => null],
            ['key' => 'custom.referred_by', 'label' => 'Who referred you?', 'required' => false, 'help' => null],
        ]]);

        $this->get($form->publicUrl())->assertOk()->assertSee('Female');
        $this->post(route('forms.public.store', $form->uuid), ['title' => 'Farai', 'data__date_of_birth' => 'soon', 'data__sex' => 'robot', 'contact__email' => 'farai@example.com', 'started' => $this->started()])
            ->assertSessionHasErrors(['data__date_of_birth', 'data__sex']);

        $this->post(route('forms.public.store', $form->uuid), [
            'title' => 'Farai Chikore', 'data__date_of_birth' => '1991-02-03', 'data__sex' => 'male', 'contact__email' => 'farai@example.com', 'custom__referred_by' => 'Dr Ncube', 'started' => $this->started(),
        ])->assertRedirect();

        $patient = Record::query()->ofEntity('clinic', 'patients')->firstOrFail();
        $this->assertSame(['Farai Chikore', '1991-02-03', 'male', 'Dr Ncube'], [$patient->title, $patient->value('date_of_birth'), $patient->value('sex'), $patient->customField('referred_by')]);
        $this->assertSame('farai@example.com', $patient->contact?->email);
        $this->assertSame('PT-0001', $patient->number);
    }

    public function test_closed_forms_and_unknown_links_take_no_answers(): void
    {
        [, $workspace] = $this->workspace();
        $closed = $this->form($workspace, ['is_active' => false]);
        $answers = ['name' => 'Tendai', 'email' => 'tendai@example.com', 'started' => $this->started()];

        $this->get($closed->publicUrl())->assertOk()->assertSee('not taking answers');
        $this->post(route('forms.public.store', $closed->uuid), $answers)->assertNotFound();

        $app = $this->form($workspace, ['target' => 'clinic.patients', 'fields' => [['key' => 'title', 'label' => 'Name', 'required' => true, 'help' => null]]]);
        $workspace->disableModule('clinic');
        $this->post(route('forms.public.store', $app->uuid), ['title' => 'X', 'started' => $this->started()])->assertNotFound();

        $this->get(route('forms.public.show', '9b1deb4d-3b7d-4bad-9bdd-2b0d7b3dcb6d'))->assertNotFound();

        $open = $this->form($workspace);
        $workspace->forceFill(['is_active' => false])->save();
        $this->get($open->publicUrl())->assertNotFound();
        $this->assertSame(0, FormSubmission::query()->count());
    }

    public function test_the_embedded_form_can_sit_inside_another_website(): void
    {
        [, $workspace] = $this->workspace();
        $form = $this->form($workspace);

        $this->get(route('forms.public.show', ['uuid' => $form->uuid, 'embed' => 1]))->assertOk()
            ->assertHeader('Content-Security-Policy', 'frame-ancestors *')
            ->assertDontSee('Powered by');

        $this->post(route('forms.public.store', ['uuid' => $form->uuid, 'embed' => 1]), ['name' => 'Tendai', 'email' => 'tendai@example.com', 'started' => $this->started()])
            ->assertRedirect(route('forms.public.thanks', ['uuid' => $form->uuid, 'embed' => 1]));
    }

    public function test_forms_stay_inside_their_workspace_and_members_cannot_manage_them(): void
    {
        [, $workspace] = $this->workspace();
        [$otherOwner] = $this->workspace();
        $form = $this->form($workspace);
        $member = $this->memberOf($workspace);

        $this->actingAs($otherOwner)->get(route('forms.show', $form))->assertNotFound();
        $this->actingAs($otherOwner)->get(route('forms.index'))->assertDontSee($form->name);
        $this->actingAs($member)->get(route('forms.index'))->assertForbidden();
        $this->actingAs($member)->get(route('dashboard'))->assertDontSee(route('forms.index'), false);
    }
}
