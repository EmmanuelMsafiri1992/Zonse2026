<?php

namespace App\Listeners;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\PasswordUpdatedViaController;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Logs sign-ins and account security changes to the audit log of the user's
 * current workspace. Method names avoid the "handle" prefix so event discovery
 * does not register them a second time.
 */
class RecordSecurityEvents
{
    public function onLogin(Login $event): void
    {
        $this->record($event->user, 'login', 'Signed in');
    }

    public function onLogout(Logout $event): void
    {
        $this->record($event->user, 'logout', 'Signed out');
    }

    public function onFailed(Failed $event): void
    {
        $user = $event->user ?? (isset($event->credentials['email']) ? User::where('email', $event->credentials['email'])->first() : null);
        $this->record($user, 'login-failed', 'Failed sign-in attempt', causer: false);
    }

    public function onPasswordReset(PasswordReset $event): void
    {
        $this->record($event->user, 'password-reset', 'Reset their password by email link');
    }

    public function onPasswordUpdated(PasswordUpdatedViaController $event): void
    {
        $this->record($event->user, 'password-changed', 'Changed their password');
    }

    public function onTwoFactorConfirmed(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->record($event->user, '2fa-enabled', 'Turned on two-factor sign-in');
    }

    public function onTwoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->record($event->user, '2fa-disabled', 'Turned off two-factor sign-in');
    }

    public function onRecoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        $this->record($event->user, '2fa-codes', 'Generated new two-factor recovery codes');
    }

    public function onTwoFactorFailed(TwoFactorAuthenticationFailed $event): void
    {
        $this->record($event->user, '2fa-failed', 'Entered a wrong two-factor code', causer: false);
    }

    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Logout::class => 'onLogout',
            Failed::class => 'onFailed',
            PasswordReset::class => 'onPasswordReset',
            PasswordUpdatedViaController::class => 'onPasswordUpdated',
            TwoFactorAuthenticationConfirmed::class => 'onTwoFactorConfirmed',
            TwoFactorAuthenticationDisabled::class => 'onTwoFactorDisabled',
            RecoveryCodesGenerated::class => 'onRecoveryCodesGenerated',
            TwoFactorAuthenticationFailed::class => 'onTwoFactorFailed',
        ];
    }

    /** Failed attempts are logged against the account but not as performed by it. */
    protected function record(mixed $user, string $event, string $description, bool $causer = true): void
    {
        if (! $user instanceof User || ! $user->current_workspace_id) {
            return;
        }

        $workspace = Workspace::find($user->current_workspace_id);
        if (! $workspace) {
            return;
        }

        $activity = Audit::log('access', $event, $description, $user, ['email' => $user->email], $workspace);
        if ($activity) {
            $activity->forceFill($causer ? ['causer_type' => $user->getMorphClass(), 'causer_id' => $user->id] : ['causer_type' => null, 'causer_id' => null])->save();
        }
    }
}
