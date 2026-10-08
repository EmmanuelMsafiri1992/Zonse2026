<?php

namespace Modules\Invoicing\Policies;

use App\Models\User;
use App\Policies\WorkspacePolicy;
use Modules\Invoicing\Models\Invoice;

class InvoicePolicy extends WorkspacePolicy
{
    /** Recording and removing payments follows the same rules as editing. */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $this->update($user, $invoice) && $invoice->status !== 'cancelled';
    }
}
