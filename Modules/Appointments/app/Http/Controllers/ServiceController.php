<?php

namespace Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Appointments\Http\Requests\ServiceRequest;
use Modules\Appointments\Models\Service;

class ServiceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Service::class);

        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => (string) $request->query('status', 'active'),
        ];

        $services = Service::query()->withCount('appointments')->search($filters['q'])
            ->when($filters['status'] === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($filters['status'] === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('appointments::services.index', [
            'services' => $services,
            'filters' => $filters,
            'colors' => Service::COLORS,
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $this->authorize('create', Service::class);

        $service = Service::create($request->payload());

        return redirect()->route('services.index')->with('flash', ['type' => 'success', 'message' => $service->name.' was added.']);
    }

    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        $service->update($request->payload());

        return redirect()->route('services.index')->with('flash', ['type' => 'success', 'message' => $service->name.' was updated.']);
    }

    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        $service->delete();

        return redirect()->route('services.index')->with('flash', ['type' => 'success', 'message' => $service->name.' was deleted. Existing bookings keep their details.']);
    }
}
