<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A person's Zonseob AI tokens. New wallets open with the welcome tokens from config so anyone
 * can try Claude before buying; packs bought through checkout are added on top. A balance is
 * never taken below zero: a long answer that overruns what is left is still delivered.
 */
class AiWallet extends Model
{
    protected $fillable = ['user_id', 'balance_tokens', 'welcome_tokens', 'purchased_tokens', 'used_tokens'];

    protected function casts(): array
    {
        return [
            'balance_tokens' => 'integer',
            'welcome_tokens' => 'integer',
            'purchased_tokens' => 'integer',
            'used_tokens' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The person's wallet, opened with the welcome tokens the first time it is needed. */
    public static function for(User $user): self
    {
        $welcome = max(0, (int) config('zonseo.ai.welcome_tokens', 0));

        return static::query()->firstOrCreate(['user_id' => $user->id], ['balance_tokens' => $welcome, 'welcome_tokens' => $welcome]);
    }

    /** Enough left to ask another question. */
    public function canAsk(): bool
    {
        return $this->balance_tokens >= (int) config('zonseo.ai.min_balance', 1000);
    }

    /** Add bought tokens. */
    public function credit(int $tokens): void
    {
        DB::transaction(function () use ($tokens) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->id);
            $locked->forceFill([
                'balance_tokens' => $locked->balance_tokens + $tokens,
                'purchased_tokens' => $locked->purchased_tokens + $tokens,
            ])->save();
            $this->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /** Take the cost of an answer, stopping at zero. Returns what was actually taken. */
    public function spend(int $tokens): int
    {
        return DB::transaction(function () use ($tokens) {
            $locked = static::query()->lockForUpdate()->findOrFail($this->id);
            $taken = min($tokens, $locked->balance_tokens);
            $locked->forceFill([
                'balance_tokens' => $locked->balance_tokens - $taken,
                'used_tokens' => $locked->used_tokens + $tokens,
            ])->save();
            $this->setRawAttributes($locked->getAttributes(), true);

            return $taken;
        });
    }
}
