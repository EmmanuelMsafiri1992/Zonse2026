<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Everyone who has signed up to Zonseob, newest first, for the people who run it: who they are,
 * the workspace they set up, its plan and whether that plan is on trial, paid or lapsed.
 */
class ClientsController extends Controller
{
    /** @var array<string, string> */
    public const FILTERS = [
        'new' => 'New this week',
        'trial' => 'On trial',
        'paying' => 'Paying',
        'unfinished' => 'Setup not finished',
    ];

    public function index(Request $request): View
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : null;
        $search = trim((string) $request->query('q'));

        $clients = $this->clients()
            ->with(['ownedWorkspaces' => fn ($query) => $query->with(['subscription.plan', 'owner'])->withCount('members')])
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhereHas('ownedWorkspaces', fn (Builder $query) => $query->where('name', 'like', "%{$search}%"))))
            ->when($filter, fn (Builder $query) => $this->applyFilter($query, $filter))
            ->latest()
            ->paginate(40)
            ->withQueryString();

        return view('admin.clients', [
            'clients' => $clients,
            'filter' => $filter,
            'search' => $search,
            'totals' => [
                'all' => $this->clients()->count(),
                'today' => $this->clients()->where('created_at', '>=', now()->startOfDay())->count(),
                'new' => $this->applyFilter($this->clients(), 'new')->count(),
                'trial' => $this->applyFilter($this->clients(), 'trial')->count(),
                'paying' => $this->applyFilter($this->clients(), 'paying')->count(),
            ],
        ]);
    }

    /** @return Builder<User> */
    protected function clients(): Builder
    {
        return User::query()->where(fn (Builder $query) => $query->where('is_super_admin', false)->orWhereNull('is_super_admin'));
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    protected function applyFilter(Builder $query, string $filter): Builder
    {
        return match ($filter) {
            'new' => $query->where('created_at', '>=', now()->subDays(7)),
            'trial' => $query->whereHas('ownedWorkspaces.subscription', fn (Builder $query) => $query
                ->where('status', 'trialing')
                ->where('trial_ends_at', '>', now())),
            'paying' => $query->whereHas('ownedWorkspaces.subscription', fn (Builder $query) => $query
                ->where('status', 'active')
                ->where('current_period_end', '>', now())
                ->whereHas('plan', fn (Builder $query) => $query->where('price_monthly', '>', 0)->orWhere('price_yearly', '>', 0))),
            'unfinished' => $query->where(fn (Builder $query) => $query
                ->doesntHave('ownedWorkspaces')
                ->orWhereHas('ownedWorkspaces', fn (Builder $query) => $query->whereNull('onboarded_at')->where('onboarding_step', '>', 0))),
            default => $query,
        };
    }
}
