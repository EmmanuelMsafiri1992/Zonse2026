<?php

namespace Modules\Appointments\Policies;

use App\Models\User;
use App\Policies\WorkspacePolicy;
use Modules\Appointments\Models\Appointment;

class AppointmentPolicy extends WorkspacePolicy
{
    /** Confirming, completing, cancelling or marking a no-show follows the same rules as editing. */
    public function changeStatus(User $user, Appointment $appointment): bool
    {
        return $this->update($user, $appointment);
    }
}
