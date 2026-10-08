<?php

namespace App\Ussd;

use App\Blueprints\Blueprint;
use App\Blueprints\BlueprintRegistry;
use App\Blueprints\Entity;
use App\Blueprints\Field;
use App\Models\Record;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Sms\PhoneNumber;
use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The feature-phone menu reached by dialling the workspace's USSD code. Gateways such as
 * Africa's Talking post every key press of a session as one "*"-joined string, so the menu is
 * stateless: each request replays the whole path (PIN, choice, answers…) and replies with
 * "CON …" to ask for more or "END …" to hang up.
 */
class UssdMenu
{
    /** Longest screen most handsets show in one USSD message. */
    public const MAX_LENGTH = 182;

    /** Roles that may add and change records from a phone; viewers can only look things up. */
    public const WRITE_ROLES = ['owner', 'admin', 'manager', 'member'];

    /** Wrong PINs allowed per number before it is locked for a while. */
    public const MAX_PIN_ATTEMPTS = 5;

    public const LOCK_SECONDS = 900;

    public function __construct(protected BlueprintRegistry $blueprints, protected WorkspaceContext $context) {}

    /** Answer one gateway request for a session's accumulated input. */
    public function handle(Workspace $workspace, string $phone, string $text): string
    {
        $membership = $this->membershipFor($workspace, $phone);
        if (! $membership) {
            return $this->end('This number is not linked to anyone in '.$workspace->name.'. Add it to your Zonseo profile first.');
        }
        if (! $membership->ussd_pin) {
            return $this->end('Set your phone PIN in Zonseo under Phone access first.');
        }

        $inputs = $text === '' ? [] : array_map('trim', explode('*', $text));
        if ($inputs === []) {
            return $this->con(Str::limit($workspace->name, 40)."\nEnter your phone PIN");
        }

        $lockKey = 'ussd-pin:'.$membership->id;
        if (RateLimiter::tooManyAttempts($lockKey, self::MAX_PIN_ATTEMPTS)) {
            return $this->end('Too many wrong PINs. Try again in '.max(1, (int) ceil(RateLimiter::availableIn($lockKey) / 60)).' minutes.');
        }
        if (! Hash::check($inputs[0], $membership->ussd_pin)) {
            RateLimiter::hit($lockKey, self::LOCK_SECONDS);

            return $this->end('Wrong PIN.');
        }
        RateLimiter::clear($lockKey);

        $user = $membership->user;
        $previousUser = Auth::user();
        Auth::setUser($user);

        try {
            return $this->context->run($workspace, fn () => $this->mainMenu($membership, array_slice($inputs, 1)));
        } finally {
            $previousUser ? Auth::setUser($previousUser) : Auth::forgetUser();
        }
    }

    /** The workspace member whose profile phone matches the caller's number. */
    public function membershipFor(Workspace $workspace, string $phone): ?WorkspaceMembership
    {
        $caller = PhoneNumber::normalize($phone, $workspace->country_code);
        if (! $caller) {
            return null;
        }

        return WorkspaceMembership::query()->where('workspace_id', $workspace->id)->with('user')->get()
            ->first(fn (WorkspaceMembership $membership) => $membership->user
                && PhoneNumber::normalize($membership->user->phone, $workspace->country_code) === $caller);
    }

    /** @param  list<string>  $inputs */
    protected function mainMenu(WorkspaceMembership $membership, array $inputs): string
    {
        $user = $membership->user;
        $choice = array_shift($inputs);

        return match ($choice) {
            null => $this->con('Hi '.Str::before($user->name, ' ')."\n1. My open items\n2. Log new\n3. Find by number"),
            '1' => $this->myItems($membership, $inputs),
            '2' => $this->logNew($membership, $inputs),
            '3' => $this->findByNumber($membership, $inputs),
            default => $this->invalid(),
        };
    }

    /** @param  list<string>  $inputs */
    protected function myItems(WorkspaceMembership $membership, array $inputs): string
    {
        $userId = $membership->user_id;
        $records = Record::query()->whereIn('blueprint', array_keys($this->apps()))
            ->whereNotIn('status', Record::DONE_STATUSES)
            ->where(fn ($query) => $query->where('assignee_id', $userId)
                ->orWhere(fn ($query) => $query->whereNull('assignee_id')->where('created_by', $userId)))
            ->latest('updated_at')->latest('id')->limit(5)->get();

        if ($records->isEmpty()) {
            return $this->end('Nothing open for you right now.');
        }

        $choice = array_shift($inputs);
        if ($choice === null) {
            return $this->con("Your open items\n".$records->values()->map(fn (Record $record, int $index) => ($index + 1).'. '.$this->recordLine($record))->implode("\n"));
        }

        $record = $records->values()->get((int) $choice - 1);

        return $record && ctype_digit($choice) ? $this->recordMenu($membership, $record, $inputs) : $this->invalid();
    }

    /** @param  list<string>  $inputs */
    protected function findByNumber(WorkspaceMembership $membership, array $inputs): string
    {
        $term = array_shift($inputs);
        if ($term === null || $term === '') {
            return $this->con('Enter the record number, e.g. EXP-0012 or just 12');
        }

        $matches = $this->lookup($term);
        if ($matches->isEmpty()) {
            return $this->end('No record found for '.Str::limit($term, 20).'.');
        }
        if ($matches->count() > 1) {
            return $this->end('Several records match: '.$matches->take(4)->pluck('number')->implode(', ').'. Dial again and enter the full number.');
        }

        return $this->recordMenu($membership, $matches->first(), $inputs);
    }

    /**
     * Records with that exact number, or whose number ends in those digits.
     *
     * @return Collection<int, Record>
     */
    public function lookup(string $term): Collection
    {
        $query = Record::query()->whereIn('blueprint', array_keys($this->apps()));
        $term = strtoupper(trim($term));

        $exact = (clone $query)->where('number', $term)->get();
        if ($exact->isNotEmpty() || ! ctype_digit($term) || (int) $term === 0) {
            return $exact;
        }

        $wanted = (int) $term;

        return $query->where('number', 'like', '%'.$wanted)->latest('id')->limit(20)->get()
            ->filter(fn (Record $record) => preg_match('/(\d+)$/', $record->number, $match) && (int) $match[1] === $wanted)
            ->values();
    }

    /** @param  list<string>  $inputs */
    protected function recordMenu(WorkspaceMembership $membership, Record $record, array $inputs): string
    {
        $entity = $record->definition();
        $details = $record->number.' '.Str::limit($record->title, 40)."\nStatus: ".$record->statusLabel();
        if ($record->amount !== null) {
            $details .= "\n".($entity->amountLabel ?? 'Amount').': '.number_format((float) $record->amount, 2).' '.$record->currency;
        }
        if ($record->due_on) {
            $details .= "\n".($entity->dueLabel ?? 'Due').': '.$record->due_on->format('d M Y');
        }

        if (! $this->canWrite($membership)) {
            return $this->end($details);
        }

        $choice = array_shift($inputs);
        $statuses = collect($entity->statuses)->except($record->status);

        if ($choice === null) {
            return $this->con($details."\n".($statuses->isNotEmpty() ? "1. Change status\n" : '').'2. Add note');
        }

        if ($choice === '1' && $statuses->isNotEmpty()) {
            $pick = array_shift($inputs);
            if ($pick === null) {
                return $this->con("Move to\n".$statuses->values()->map(fn (string $label, int $index) => ($index + 1).'. '.$label)->implode("\n"));
            }
            $status = ctype_digit($pick) ? $statuses->keys()->get((int) $pick - 1) : null;
            if ($status === null) {
                return $this->invalid();
            }
            $record->update(['status' => $status]);

            return $this->end($record->number.' is now '.$record->statusLabel().'.');
        }

        if ($choice === '2') {
            $note = array_shift($inputs);
            if ($note === null || $note === '') {
                return $this->con('Type your note');
            }
            $record->addComment(Str::limit($note, 5000, ''), $membership->user, true);

            return $this->end('Note added to '.$record->number.'.');
        }

        return $this->invalid();
    }

    /** @param  list<string>  $inputs */
    protected function logNew(WorkspaceMembership $membership, array $inputs): string
    {
        if (! $this->canWrite($membership)) {
            return $this->end('Your role can only look records up.');
        }

        $entities = $this->loggableEntities();
        if ($entities === []) {
            return $this->end('None of your apps can take new records by phone.');
        }

        $choice = array_shift($inputs);
        if ($choice === null) {
            return $this->con("Log what?\n".collect($entities)->map(fn (Entity $entity, int $index) => ($index + 1).'. '.$entity->label)->implode("\n"));
        }
        $entity = ctype_digit($choice) ? ($entities[(int) $choice - 1] ?? null) : null;
        if (! $entity) {
            return $this->invalid();
        }

        $steps = $this->steps($entity);
        $values = [];
        foreach ($inputs as $index => $answer) {
            $step = $steps[$index] ?? null;
            if (! $step) {
                return $this->invalid();
            }
            [$value, $error] = $this->answer($entity, $step, $answer);
            if ($error !== null) {
                return $this->end($error.' Please dial again.');
            }
            $values[$step] = $value;
        }

        if (count($values) < count($steps)) {
            return $this->con($this->prompt($entity, $steps[count($values)]));
        }

        return $this->create($membership, $entity, $values);
    }

    /** @param  array<string, mixed>  $values */
    protected function create(WorkspaceMembership $membership, Entity $entity, array $values): string
    {
        $workspace = $this->context->getOrFail();
        $data = [];
        foreach ($entity->fields as $field) {
            $data[$field->key] = $field->cast($values['data.'.$field->key] ?? null);
        }

        $payload = [
            'title' => $values['title'],
            'status' => $entity->defaultStatus(),
            'branch_id' => $membership->branch_id,
            'amount' => $entity->hasAmount() ? ($values['amount'] ?? null) : null,
            'currency' => $entity->hasAmount() ? $workspace->currency_code : null,
            'occurs_on' => $entity->hasDate() ? today()->toDateString() : null,
            'data' => $data,
        ];

        $logic = $this->blueprints->get($entity->blueprintKey)?->logic();
        foreach ($logic?->validate($entity, $payload, null) ?? [] as $message) {
            return $this->end(Str::limit($message, 140).' Please add it in Zonseo instead.');
        }

        $record = Record::create($payload + ['blueprint' => $entity->blueprintKey, 'entity' => $entity->key, 'created_by' => $membership->user_id]);

        return $this->end('Saved '.$entity->label.' '.$record->number.'. Thank you.');
    }

    /**
     * The questions asked when logging a record: its title, the amount when it has one, then every required field.
     *
     * @return list<string>
     */
    protected function steps(Entity $entity): array
    {
        $steps = ['title'];
        if ($entity->hasAmount()) {
            $steps[] = 'amount';
        }
        foreach ($entity->fields as $field) {
            if ($field->required) {
                $steps[] = 'data.'.$field->key;
            }
        }

        return $steps;
    }

    protected function prompt(Entity $entity, string $step): string
    {
        if ($step === 'title') {
            return 'Enter '.Str::lower($entity->titleLabel);
        }
        if ($step === 'amount') {
            return 'Enter '.Str::lower($entity->amountLabel ?? 'amount').' ('.$this->context->getOrFail()->currency_code.')';
        }

        $field = $entity->field(Str::after($step, 'data.'));

        return match ($field->type) {
            'select' => $field->label."\n".collect(array_values($field->options))->map(fn (string $label, int $index) => ($index + 1).'. '.$label)->implode("\n"),
            'checkbox' => $field->label."\n1. Yes\n2. No",
            'date', 'datetime' => $field->label.': enter as DDMMYYYY, or 0 for today',
            'time' => $field->label.': enter as HHMM',
            default => 'Enter '.Str::lower($field->label),
        };
    }

    /**
     * Turn a typed answer into the stored value.
     *
     * @return array{0: mixed, 1: ?string} the value and an error message when it is not valid
     */
    protected function answer(Entity $entity, string $step, string $answer): array
    {
        if ($step === 'title') {
            return $this->validated($answer, ['required', 'string', 'max:255'], $entity->titleLabel);
        }
        if ($step === 'amount') {
            return $this->validated($answer, ['required', 'numeric', 'min:0', 'max:999999999999'], $entity->amountLabel ?? 'Amount');
        }

        /** @var Field $field */
        $field = $entity->field(Str::after($step, 'data.'));
        $value = match ($field->type) {
            'select' => ctype_digit($answer) ? (array_keys($field->options)[(int) $answer - 1] ?? null) : null,
            'checkbox' => match ($answer) {
                '1' => true,
                '2' => false,
                default => null,
            },
            'date', 'datetime' => $this->date($answer),
            'time' => preg_match('/^([01]\d|2[0-3])([0-5]\d)$/', $answer, $match) ? $match[1].':'.$match[2] : null,
            default => $answer,
        };
        if ($value === null) {
            return [null, 'That is not a valid '.Str::lower($field->label).'.'];
        }

        return $this->validated($value, $field->rules(), $field->label);
    }

    protected function date(string $answer): ?string
    {
        if ($answer === '0') {
            return today()->toDateString();
        }
        if (! preg_match('/^\d{8}$/', $answer)) {
            return null;
        }
        $date = Carbon::createFromFormat('!dmY', $answer);

        return $date && $date->format('dmY') === $answer ? $date->toDateString() : null;
    }

    /**
     * @param  array<int, mixed>  $rules
     * @return array{0: mixed, 1: ?string}
     */
    protected function validated(mixed $value, array $rules, string $label): array
    {
        $validator = Validator::make(['value' => $value], ['value' => array_merge(['required'], array_values(array_filter($rules, fn (mixed $rule) => $rule !== 'nullable')))], [], ['value' => Str::lower($label)]);

        return $validator->fails() ? [null, $validator->errors()->first('value')] : [$value, null];
    }

    /**
     * Main lists of switched-on apps that can be filled in from a keypad: no required links to
     * other records or people, and short enough option lists to fit on one screen.
     *
     * @return list<Entity>
     */
    public function loggableEntities(): array
    {
        return collect($this->apps())
            ->map(fn (Blueprint $app) => $app->primaryEntity())
            ->filter(fn (Entity $entity) => collect($entity->fields)->every(fn (Field $field) => ! $field->required
                || (! in_array($field->type, ['record', 'user'], true) && count($field->options) <= 9)))
            ->take(9)->values()->all();
    }

    /** @return array<string, Blueprint> */
    protected function apps(): array
    {
        $workspace = $this->context->getOrFail();

        return array_filter($this->blueprints->all(), fn (Blueprint $app) => $workspace->hasModule($app->key));
    }

    protected function canWrite(WorkspaceMembership $membership): bool
    {
        return in_array($membership->role, self::WRITE_ROLES, true);
    }

    protected function recordLine(Record $record): string
    {
        return $record->number.' '.Str::limit($record->title, 18).' ('.$record->statusLabel().')';
    }

    protected function invalid(): string
    {
        return $this->end('Invalid choice. Please dial again.');
    }

    protected function con(string $message): string
    {
        return 'CON '.Str::limit($message, self::MAX_LENGTH, '');
    }

    protected function end(string $message): string
    {
        return 'END '.Str::limit($message, self::MAX_LENGTH, '');
    }
}
