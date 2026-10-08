<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Audit;
use App\Tenancy\WorkspaceContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    public function __construct(protected WorkspaceContext $context) {}

    public function index(Request $request): View
    {
        $workspace = $this->context->getOrFail();

        return view('settings.audit', [
            'activities' => $this->filtered($request)->with('causer')->latest('id')->paginate(50)->withQueryString(),
            'categories' => Audit::CATEGORIES,
            'members' => $workspace->members()->orderBy('name')->pluck('name', 'users.id'),
            'filters' => $request->only(['q', 'category', 'user', 'from', 'to']),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->filtered($request)->with('causer')->latest('id');
        Audit::log('data', 'audit-exported', 'Exported the audit log');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['When', 'Category', 'Event', 'Description', 'By', 'Item', 'IP address']);
            $query->chunk(500, function ($activities) use ($out) {
                foreach ($activities as $activity) {
                    fputcsv($out, [
                        $activity->created_at->toDateTimeString(),
                        Audit::categoryLabel($activity->log_name),
                        $activity->event,
                        $activity->description,
                        $activity->causer?->name ?? 'System',
                        $activity->properties['label'] ?? '',
                        $activity->properties['ip'] ?? '',
                    ]);
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return Builder<Activity> */
    protected function filtered(Request $request): Builder
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', Rule::in(array_keys(Audit::CATEGORIES))],
            'user' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        return Activity::query()
            ->where('workspace_id', $this->context->id())
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->where('description', 'like', '%'.$term.'%'))
            ->when($filters['category'] ?? null, fn (Builder $q, string $category) => $category === 'default'
                ? $q->where(fn (Builder $w) => $w->whereNull('log_name')->orWhere('log_name', 'default'))
                : $q->where('log_name', $category))
            ->when($filters['user'] ?? null, fn (Builder $q, int $user) => $q->where('causer_id', $user)->where('causer_type', (new User)->getMorphClass()))
            ->when($filters['from'] ?? null, fn (Builder $q, string $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()));
    }
}
