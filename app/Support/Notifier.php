<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkspaceAlert;
use App\Tenancy\WorkspaceContext;
use Illuminate\Support\Collection;

/**
 * Sends workspace alerts to people, honouring each person's in-app and email choices.
 * The person who caused the event is never told about their own action.
 */
class Notifier
{
    /**
     * Kinds of alert a person can switch on or off, with the defaults for each channel.
     *
     * @var array<string, array{label: string, hint: string, app: bool, email: bool}>
     */
    public const KINDS = [
        'assigned' => ['label' => 'Work assigned to me', 'hint' => 'Tasks, tickets, appointments and records given to you.', 'app' => true, 'email' => true],
        'comments' => ['label' => 'Notes on my work', 'hint' => 'Someone adds a note to something assigned to you or that you created.', 'app' => true, 'email' => false],
        'payments' => ['label' => 'Payments received', 'hint' => 'Money recorded against an invoice (admins only).', 'app' => true, 'email' => false],
        'team' => ['label' => 'Team changes', 'hint' => 'Someone accepts an invitation and joins the workspace (admins only).', 'app' => true, 'email' => false],
        'automations' => ['label' => 'Automation alerts', 'hint' => 'An automation set up under Settings › Automations names you.', 'app' => true, 'email' => false],
        'billing' => ['label' => 'Plan and trial reminders', 'hint' => 'Your free trial or subscription is about to end (owner only).', 'app' => true, 'email' => true],
    ];

    public const CHANNELS = ['app' => 'In the app', 'email' => 'By email'];

    /**
     * @param  User|iterable<User>|null  $recipients
     * @return int number of people told
     */
    public static function send(User|iterable|null $recipients, string $kind, string $title, ?string $body = null, ?string $url = null, string $icon = 'bell', ?Workspace $workspace = null, ?User $actor = null): int
    {
        $workspace ??= app(WorkspaceContext::class)->get();
        if (! $workspace) {
            return 0;
        }
        $actorId = $actor ? $actor->id : auth()->id();

        $people = Collection::wrap($recipients instanceof User ? [$recipients] : ($recipients ?? []))
            ->filter(fn ($user) => $user instanceof User && $user->id !== $actorId && $user->belongsToWorkspace($workspace))
            ->unique('id');

        foreach ($people as $user) {
            try {
                $user->notify(new WorkspaceAlert($workspace, $kind, $title, $body, $url, $icon));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $people->count();
    }

    /** Owners and admins of the workspace. */
    public static function admins(Workspace $workspace): Collection
    {
        return $workspace->members()->wherePivotIn('role', ['owner', 'admin'])->get();
    }

    public static function wants(User $user, string $kind, string $channel): bool
    {
        $default = self::KINDS[$kind][$channel] ?? ($channel === 'app');

        return (bool) data_get($user->notification_preferences, $kind.'.'.$channel, $default);
    }
}
