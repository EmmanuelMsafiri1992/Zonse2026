<?php

namespace App\Support\Hardware;

use App\Models\Workspace;
use App\Support\Money;
use Illuminate\Support\Str;

/**
 * Charges a card sale on the linked card terminal before the till records it.
 *
 * Only the test terminal exists so far: it approves every amount except those ending in .51,
 * which it declines, so staff can practise both outcomes without moving money.
 */
class CardTerminal
{
    public function linked(Workspace $workspace): bool
    {
        return HardwareSettings::for($workspace)['card_terminal'] !== 'off';
    }

    /** @return array{approved: bool, approval_code: ?string, message: string} */
    public function charge(Workspace $workspace, float $amount, string $reference): array
    {
        $amount = Money::round($amount);
        if (! $this->linked($workspace) || $amount <= 0) {
            return ['approved' => false, 'approval_code' => null, 'message' => 'No card terminal is linked.'];
        }

        if ((int) round($amount * 100) % 100 === 51) {
            return ['approved' => false, 'approval_code' => null, 'message' => 'The card was declined (test terminal: amounts ending in .51 decline).'];
        }

        return ['approved' => true, 'approval_code' => 'TEST-'.Str::upper(Str::random(6)), 'message' => 'Approved by the test terminal for '.$reference.'.'];
    }
}
