<?php

namespace App\Payments;

use App\Models\AiWallet;
use App\Models\Checkout;

/**
 * The packs of Zonseob AI tokens people can buy, and crediting a pack to the buyer's wallet once
 * a checkout for it is paid. Packs come from ZONSEOB_AI_PACKS as "key=tokens=usd" pairs.
 */
class TokenPayments
{
    public const DEFAULT_PACKS = 'starter=250000=5,standard=1000000=15,large=5000000=60';

    /** @return array<string, array{key: string, name: string, tokens: int, usd: float}> */
    public function packs(): array
    {
        $packs = self::parsePacks((string) config('zonseo.ai.packs', ''));

        return $packs ?: self::parsePacks(self::DEFAULT_PACKS);
    }

    /** @return array{key: string, name: string, tokens: int, usd: float}|null */
    public function pack(string $key): ?array
    {
        return $this->packs()[$key] ?? null;
    }

    /** @return array<string, array{key: string, name: string, tokens: int, usd: float}> */
    public static function parsePacks(string $list): array
    {
        $packs = [];
        foreach (preg_split('/[,\n]+/', $list) ?: [] as $item) {
            [$key, $tokens, $usd] = array_pad(array_map('trim', explode('=', $item, 3)), 3, '');
            $key = strtolower((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($key)));
            if ($key === '' || ! ctype_digit($tokens) || (int) $tokens <= 0 || ! is_numeric($usd) || (float) $usd <= 0) {
                continue;
            }
            $packs[$key] = ['key' => $key, 'name' => ucfirst(str_replace('-', ' ', $key)), 'tokens' => (int) $tokens, 'usd' => round((float) $usd, 2)];
        }

        return $packs;
    }

    /** Add the tokens of a paid checkout to the buyer's wallet. */
    public function apply(Checkout $checkout): void
    {
        $tokens = (int) ($checkout->subject['tokens'] ?? 0);
        if ($tokens <= 0 || ! $checkout->user) {
            return;
        }

        AiWallet::for($checkout->user)->credit($tokens);
    }
}
