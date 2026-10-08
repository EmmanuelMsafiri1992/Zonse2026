<?php

namespace App\Support;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The partner (reseller) program: referral codes, linking new workspaces to the partner
 * that brought them in, and the commission each paying client earns the partner.
 */
class Partners
{
    public const CODE_PATTERN = '/^[A-Z0-9]{8}$/';

    public function __construct(protected Branding $branding) {}

    public function findByCode(string $code): ?Workspace
    {
        $code = strtoupper(trim($code));
        if (! preg_match(self::CODE_PATTERN, $code)) {
            return null;
        }

        $partner = Workspace::query()->where('settings->partner->code', $code)->first();

        return $partner?->isPartner() ? $partner : null;
    }

    public function newCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (! preg_match(self::CODE_PATTERN, $code) || Workspace::query()->where('settings->partner->code', $code)->exists());

        return $code;
    }

    /** The partner a workspace being created now should belong to: from a referral link, else the partner domain it is created on. */
    public function attributionFor(Request $request): ?Workspace
    {
        $code = $request->session()->get('partner_code');
        if (is_string($code) && ($partner = $this->findByCode($code))) {
            return $partner;
        }

        $host = $this->branding->workspaceForHost($request->getHost());

        return $host?->isPartner() ? $host : null;
    }

    public function commissionPercent(): float
    {
        return (float) config('zonseo.partners.commission_percent', 20);
    }

    /**
     * One row per client with what they pay a month and the partner's share.
     *
     * @return Collection<int, array{workspace: Workspace, plan: ?string, status: string, monthly: float, commission: float, currency: string}>
     */
    public function clientRows(Workspace $partner): Collection
    {
        $percent = $this->commissionPercent();

        return $partner->clients()->with(['subscription.plan', 'owner'])->orderBy('name')->get()
            ->map(function (Workspace $client) use ($percent) {
                $subscription = $client->subscription;
                $paying = $subscription && $subscription->status === 'active' && (float) $subscription->amount > 0;
                $monthly = $paying ? round((float) $subscription->amount / ($subscription->billing_cycle === 'yearly' ? 12 : 1), 2) : 0.0;

                return [
                    'workspace' => $client,
                    'plan' => $subscription?->plan?->name,
                    'status' => $subscription ? ($paying ? 'paying' : $subscription->status) : 'setting up',
                    'monthly' => $monthly,
                    'commission' => round($monthly * $percent / 100, 2),
                    'currency' => $subscription->currency ?? 'USD',
                ];
            });
    }
}
