<?php

namespace App\Support;

use App\Jobs\RunAutomation;
use App\Models\Automation;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AutomationEmail;
use App\Sms\SmsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Modules\Appointments\Models\Appointment;
use Modules\Contacts\Models\Contact;
use Modules\Helpdesk\Models\Ticket;
use Modules\Invoicing\Models\Invoice;
use Modules\Invoicing\Models\Payment;
use Modules\Tasks\Models\Task;
use RuntimeException;

/**
 * The automation engine: picks the rules a business event sets off, checks their conditions and
 * carries out their actions. Changes made by an automation never set off further automations.
 */
class Automations
{
    /** Rules a workspace may hold. */
    public const MAX_AUTOMATIONS = 50;

    public const MAX_CONDITIONS = 5;

    public const MAX_ACTIONS = 5;

    /** @var array<string, string> */
    public const TRIGGERS = Webhooks::EVENTS;

    /** @var array<string, class-string<Model>> */
    public const SUBJECTS = [
        'contact' => Contact::class,
        'task' => Task::class,
        'ticket' => Ticket::class,
        'invoice' => Invoice::class,
        'payment' => Payment::class,
        'appointment' => Appointment::class,
    ];

    /**
     * Fields a condition can test, per kind of record.
     *
     * @var array<string, array<string, array{label: string, type: string, options?: array<string, string>}>>
     */
    public const FIELDS = [
        'contact' => [
            'type' => ['label' => 'Type', 'type' => 'choice', 'options' => Contact::TYPES],
            'name' => ['label' => 'Name', 'type' => 'text'],
            'company_name' => ['label' => 'Company', 'type' => 'text'],
            'email' => ['label' => 'Email', 'type' => 'text'],
            'city' => ['label' => 'City', 'type' => 'text'],
            'tags' => ['label' => 'Tags', 'type' => 'text'],
        ],
        'task' => [
            'title' => ['label' => 'Title', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'choice', 'options' => Task::STATUSES],
            'priority' => ['label' => 'Priority', 'type' => 'choice', 'options' => Task::PRIORITIES],
            'assignee_id' => ['label' => 'Assigned to', 'type' => 'person'],
        ],
        'ticket' => [
            'subject' => ['label' => 'Subject', 'type' => 'text'],
            'status' => ['label' => 'Status', 'type' => 'choice', 'options' => Ticket::STATUSES],
            'priority' => ['label' => 'Priority', 'type' => 'choice', 'options' => Ticket::PRIORITIES],
            'channel' => ['label' => 'Came in by', 'type' => 'choice', 'options' => Ticket::CHANNELS],
            'category' => ['label' => 'Category', 'type' => 'text'],
            'assignee_id' => ['label' => 'Assigned to', 'type' => 'person'],
        ],
        'invoice' => [
            'status' => ['label' => 'Status', 'type' => 'choice', 'options' => Invoice::STATUSES],
            'total' => ['label' => 'Total', 'type' => 'number'],
            'balance' => ['label' => 'Balance due', 'type' => 'number'],
            'currency_code' => ['label' => 'Currency', 'type' => 'text'],
        ],
        'payment' => [
            'amount' => ['label' => 'Amount', 'type' => 'number'],
            'method' => ['label' => 'Method', 'type' => 'choice', 'options' => Payment::METHODS],
            'currency_code' => ['label' => 'Currency', 'type' => 'text'],
        ],
        'appointment' => [
            'status' => ['label' => 'Status', 'type' => 'choice', 'options' => Appointment::STATUSES],
            'price' => ['label' => 'Price', 'type' => 'number'],
            'staff_id' => ['label' => 'Staff member', 'type' => 'person'],
        ],
    ];

    /** @var array<string, string> */
    public const OPERATORS = [
        'equals' => 'is',
        'not_equals' => 'is not',
        'contains' => 'contains',
        'not_contains' => 'does not contain',
        'greater_than' => 'is more than',
        'less_than' => 'is less than',
        'is_empty' => 'is empty',
        'is_not_empty' => 'is not empty',
        'changed' => 'was just changed',
    ];

    /** Operators that need no value. */
    public const VALUELESS = ['is_empty', 'is_not_empty', 'changed'];

    /** @var array<string, array{label: string, icon: string}> */
    public const ACTIONS = [
        'notify' => ['label' => 'Alert people', 'icon' => 'bell'],
        'create_task' => ['label' => 'Create a follow-up task', 'icon' => 'list-checks'],
        'send_email' => ['label' => 'Email the customer', 'icon' => 'mail'],
        'send_sms' => ['label' => 'Text the customer', 'icon' => 'message-square'],
        'update_record' => ['label' => 'Change the record', 'icon' => 'pencil'],
    ];

    /** @var array<string, string> */
    public const RECIPIENTS = ['assignee' => 'The person it is assigned to', 'admins' => 'Workspace owners and admins', 'user' => 'A particular person'];

    /**
     * Fields an "update_record" action may set, per kind of record.
     *
     * @var array<string, list<string>>
     */
    public const UPDATABLE = [
        'contact' => ['type'],
        'task' => ['status', 'priority'],
        'ticket' => ['status', 'priority'],
        'appointment' => ['status'],
    ];

    /** True while an automation's actions run, so their own changes do not set off more automations. */
    protected static bool $running = false;

    /**
     * Queue every active automation in the record's workspace that listens for the event and whose
     * conditions hold.
     *
     * @param  list<string>  $changed  attributes the triggering save changed
     * @return int how many automations were queued
     */
    public static function trigger(string $event, Model $model, array $changed = []): int
    {
        $workspaceId = $model->workspace_id ?? null;
        if (self::$running || ! $workspaceId || ! isset(self::TRIGGERS[$event])) {
            return 0;
        }

        $automations = Automation::forWorkspace($workspaceId)->where('trigger', $event)->where('is_active', true)->get();
        if ($automations->isEmpty()) {
            return 0;
        }

        $data = Webhooks::data($model);
        $queued = 0;
        foreach ($automations as $automation) {
            if (self::matches($automation->conditions ?? [], $data, $changed)) {
                RunAutomation::dispatch($automation, $event, $model->getMorphClass(), $model->getKey(), $data)->afterCommit();
                $queued++;
            }
        }

        return $queued;
    }

    /** Run a callback with automations held off, for the engine's own changes. */
    public static function quietly(callable $callback): mixed
    {
        $before = self::$running;
        self::$running = true;

        try {
            return $callback();
        } finally {
            self::$running = $before;
        }
    }

    public static function subjectKey(string $event): string
    {
        return explode('.', $event)[0];
    }

    /**
     * @param  list<array{field: string, operator: string, value?: ?string}>  $conditions
     * @param  array<string, mixed>  $data
     * @param  list<string>  $changed
     */
    public static function matches(array $conditions, array $data, array $changed = []): bool
    {
        foreach ($conditions as $condition) {
            if (! self::check($condition, $data, $changed)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{field: string, operator: string, value?: ?string}  $condition
     * @param  array<string, mixed>  $data
     * @param  list<string>  $changed
     */
    protected static function check(array $condition, array $data, array $changed): bool
    {
        $field = $condition['field'];
        $actual = $data[$field] ?? null;
        $expected = mb_strtolower(trim((string) ($condition['value'] ?? '')));
        $values = collect(is_array($actual) ? $actual : [$actual])
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => mb_strtolower(is_bool($value) ? ($value ? '1' : '0') : (string) $value));

        return match ($condition['operator']) {
            'equals' => $values->contains($expected),
            'not_equals' => ! $values->contains($expected),
            'contains' => $values->contains(fn (string $value) => $expected !== '' && str_contains($value, $expected)),
            'not_contains' => ! $values->contains(fn (string $value) => $expected !== '' && str_contains($value, $expected)),
            'greater_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'less_than' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'is_empty' => $values->isEmpty(),
            'is_not_empty' => $values->isNotEmpty(),
            'changed' => in_array($field, $changed, true),
            default => false,
        };
    }

    /**
     * Carry out one action and describe what happened.
     *
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>  $data
     *
     * @throws RuntimeException when the action could not be carried out
     */
    public static function perform(Automation $automation, array $action, Model $subject, array $data, Workspace $workspace): string
    {
        $variables = self::variables($subject, $data, $workspace);

        return match ($action['type'] ?? null) {
            'notify' => self::notify($action, $subject, $variables, $workspace),
            'create_task' => self::createTask($automation, $action, $subject, $variables, $workspace),
            'send_email' => self::sendEmail($action, $subject, $variables, $workspace),
            'send_sms' => self::sendSms($action, $subject, $variables),
            'update_record' => self::updateRecord($action, $subject),
            default => throw new RuntimeException('Unknown action.'),
        };
    }

    /**
     * Fill {{placeholders}} from the record.
     *
     * @param  array<string, string>  $variables
     */
    public static function render(?string $template, array $variables): string
    {
        return trim(preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/i', fn (array $match) => $variables[strtolower($match[1])] ?? '', (string) $template));
    }

    /**
     * Placeholders a message may use for a trigger.
     *
     * @return list<string>
     */
    public static function placeholders(string $event): array
    {
        $fields = array_keys(self::FIELDS[self::subjectKey($event)] ?? []);
        $extra = ['contact_name', 'workspace_name', 'link'];
        $own = match (self::subjectKey($event)) {
            'contact' => ['name', 'phone'],
            'invoice', 'payment', 'ticket' => ['number'],
            'appointment' => ['title', 'starts_at'],
            default => [],
        };

        return array_values(array_unique(array_merge($own, array_diff($fields, ['assignee_id', 'staff_id', 'tags']), $extra)));
    }

    /** The customer a record is about, if any. */
    public static function contactFor(Model $subject): ?Contact
    {
        if ($subject instanceof Contact) {
            return $subject;
        }

        $contactId = $subject->getAttribute('contact_id');

        return $contactId ? Contact::forWorkspace($subject->workspace_id)->find($contactId) : null;
    }

    /** A short name for the record, for the run log. */
    public static function label(Model $subject, array $data): string
    {
        $name = $data['name'] ?? $data['title'] ?? $data['subject'] ?? $data['number'] ?? null;

        return mb_substr((string) ($name ?? class_basename($subject).' #'.$subject->getKey()), 0, 200);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    protected static function variables(Model $subject, array $data, Workspace $workspace): array
    {
        $contact = self::contactFor($subject);
        $variables = collect($data)->filter(fn ($value) => is_scalar($value))->map(fn ($value) => (string) $value)->all();
        $variables['contact_name'] = $contact?->name ?? (string) $subject->getAttribute('requester_name');
        $variables['workspace_name'] = $workspace->name;
        $variables['link'] = method_exists($subject, 'activityUrl') ? (string) $subject->activityUrl() : '';

        return $variables;
    }

    /** @return Collection<int, User> */
    protected static function recipients(array $action, Model $subject, Workspace $workspace): Collection
    {
        return match ($action['to'] ?? null) {
            'admins' => Notifier::admins($workspace),
            'assignee' => User::whereKey(array_filter([$subject->getAttribute('assignee_id') ?? $subject->getAttribute('staff_id')]))->get(),
            'user' => User::whereKey($action['user_id'] ?? 0)->get(),
            default => collect(),
        };
    }

    /** @param array<string, string> $variables */
    protected static function notify(array $action, Model $subject, array $variables, Workspace $workspace): string
    {
        $people = self::recipients($action, $subject, $workspace);
        if ($people->isEmpty()) {
            throw new RuntimeException('Nobody to alert: '.mb_strtolower(self::RECIPIENTS[$action['to'] ?? ''] ?? 'no one chosen').' is not set.');
        }

        $told = Notifier::send(
            $people, 'automations', self::render($action['message'] ?? '', $variables) ?: 'An automation ran',
            null, $variables['link'] ?: null, 'zap', $workspace, actor: new User,
        );

        return 'Alerted '.$told.' '.str('person')->plural($told);
    }

    /** @param array<string, string> $variables */
    protected static function createTask(Automation $automation, array $action, Model $subject, array $variables, Workspace $workspace): string
    {
        if (! $workspace->hasModule('tasks')) {
            throw new RuntimeException('The Tasks app is switched off, so no task was made.');
        }

        $assigneeId = match ($action['assignee'] ?? '') {
            'assignee' => $subject->getAttribute('assignee_id') ?? $subject->getAttribute('staff_id'),
            'user' => $action['user_id'] ?? null,
            default => null,
        };
        if ($assigneeId && ! User::find($assigneeId)?->belongsToWorkspace($workspace)) {
            $assigneeId = null;
        }

        $days = $action['due_in_days'] ?? null;
        $task = Task::create([
            'workspace_id' => $workspace->id,
            'title' => mb_substr(self::render($action['title'] ?? '', $variables) ?: 'Follow up', 0, 200),
            'description' => self::render($action['description'] ?? '', $variables) ?: null,
            'priority' => array_key_exists($action['priority'] ?? '', Task::PRIORITIES) ? $action['priority'] : 'normal',
            'due_date' => $days === null || $days === '' ? null : today()->addDays((int) $days),
            'assignee_id' => $assigneeId,
            'contact_id' => self::contactFor($subject)?->id,
            'taskable_type' => $subject instanceof Task ? null : $subject->getMorphClass(),
            'taskable_id' => $subject instanceof Task ? null : $subject->getKey(),
            'created_by' => $automation->created_by,
        ]);

        return 'Created the task "'.$task->title.'"';
    }

    /** @param array<string, string> $variables */
    protected static function sendEmail(array $action, Model $subject, array $variables, Workspace $workspace): string
    {
        $email = self::contactFor($subject)?->email ?: $subject->getAttribute('requester_email');
        if (! $email || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('No email sent: the customer has no email address.');
        }

        Notification::route('mail', $email)->notify(new AutomationEmail(
            $workspace, self::render($action['subject'] ?? '', $variables) ?: 'A message from '.$workspace->name, self::render($action['body'] ?? '', $variables),
        ));

        return 'Emailed '.$email;
    }

    /** @param array<string, string> $variables */
    protected static function sendSms(array $action, Model $subject, array $variables): string
    {
        $contact = self::contactFor($subject);
        $message = $contact ? app(SmsService::class)->sendToContact($contact, self::render($action['message'] ?? '', $variables), [
            'purpose' => 'automation', 'subject' => $subject, 'sent_by' => null,
        ]) : null;

        if (! $message) {
            throw new RuntimeException('No text sent: text messages are off, or the customer has no usable phone number.');
        }

        return 'Texted '.$message->to;
    }

    protected static function updateRecord(array $action, Model $subject): string
    {
        $key = array_search($subject::class, self::SUBJECTS, true);
        $field = $action['field'] ?? '';
        $options = self::FIELDS[$key][$field]['options'] ?? [];
        if (! in_array($field, self::UPDATABLE[$key] ?? [], true) || ! array_key_exists($action['value'] ?? '', $options)) {
            throw new RuntimeException('That change cannot be made to this record.');
        }

        $subject->update([$field => $action['value']]);

        return 'Set '.mb_strtolower(self::FIELDS[$key][$field]['label']).' to '.$options[$action['value']];
    }
}
