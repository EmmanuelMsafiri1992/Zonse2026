<?php

namespace Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Modules\Appointments\Http\Requests\AppointmentRequest;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;
use Modules\Appointments\Support\BookingSettings;
use Modules\Contacts\Models\Contact;

class AppointmentController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Appointment::class);

        $filters = $this->filters($request);
        $now = Appointment::localNow();

        $query = Appointment::query()->with(['contact', 'service', 'staff', 'branch'])
            ->search($filters['q'])->status($filters['status'])->forStaff($filters['staff'])->forContact($filters['contact']);

        $query = match ($filters['range']) {
            'today' => $query->onDay($now)->orderBy('starts_at'),
            'past' => $query->where('ends_at', '<', $now->format('Y-m-d H:i:s'))->orderByDesc('starts_at'),
            'all' => $query->orderByDesc('starts_at'),
            default => $query->where('ends_at', '>=', $now->format('Y-m-d H:i:s'))->orderBy('starts_at'),
        };

        $monthStart = $now->startOfMonth();
        $monthEnd = $now->endOfMonth();

        return view('appointments::appointments.index', [
            'appointments' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
            'statuses' => Appointment::STATUSES,
            'staff' => $this->staffOptions(),
            'stats' => [
                'today' => Appointment::query()->active()->onDay($now)->count(),
                'week' => Appointment::query()->active()->between($now, $now->addDays(7))->count(),
                'completed' => Appointment::query()->where('status', 'completed')->between($monthStart, $monthEnd)->count(),
                'no_show' => Appointment::query()->where('status', 'no_show')->between($monthStart, $monthEnd)->count(),
            ],
        ]);
    }

    public function calendar(Request $request, WorkspaceContext $context): View
    {
        $this->authorize('viewAny', Appointment::class);

        $settings = BookingSettings::for($context->getOrFail());
        $anchor = $request->query('week') ? CarbonImmutable::parse($request->query('week')) : Appointment::localNow();
        $weekStart = $anchor->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $weekEnd = $weekStart->addDays(7);
        $staffId = $request->query('staff') ?: null;

        $appointments = Appointment::query()->with(['contact', 'service', 'staff'])
            ->forStaff($staffId)->between($weekStart, $weekEnd)->orderBy('starts_at')->get();

        $days = collect(range(0, 6))->map(fn (int $offset) => $weekStart->addDays($offset))->map(fn (CarbonImmutable $day) => [
            'date' => $day,
            'isToday' => $day->isSameDay(Appointment::localNow()),
            'isWorking' => in_array($day->dayOfWeekIso, $settings['working_days'], true),
            'appointments' => $appointments->filter(fn (Appointment $a) => $a->starts_at->format('Y-m-d') === $day->format('Y-m-d'))->values(),
        ]);

        return view('appointments::appointments.calendar', [
            'days' => $days,
            'weekStart' => $weekStart,
            'weekEnd' => $weekEnd->subDay(),
            'previousWeek' => $weekStart->subWeek()->format('Y-m-d'),
            'nextWeek' => $weekStart->addWeek()->format('Y-m-d'),
            'staff' => $this->staffOptions(),
            'staffId' => $staffId,
            'settings' => $settings,
        ]);
    }

    public function create(Request $request, WorkspaceContext $context): View
    {
        $this->authorize('create', Appointment::class);

        $settings = BookingSettings::for($context->getOrFail());
        $start = $request->query('date')
            ? CarbonImmutable::parse($request->query('date').' '.($request->query('time') ?: $settings['day_start']))
            : Appointment::localNow()->addHour()->setMinute(0)->setSecond(0);

        $appointment = new Appointment([
            'contact_id' => $request->query('contact'),
            'staff_id' => $request->query('staff'),
            'status' => $settings['auto_confirm'] ? 'confirmed' : 'scheduled',
            'starts_at' => $start,
            'ends_at' => $start->addMinutes($settings['default_duration']),
        ]);

        return view('appointments::appointments.form', $this->formData($appointment));
    }

    public function store(AppointmentRequest $request): RedirectResponse
    {
        $this->authorize('create', Appointment::class);

        $appointment = Appointment::create($request->payload());

        return redirect()->route('appointments.show', $appointment)
            ->with('flash', ['type' => 'success', 'message' => 'Booked '.$appointment->displayTitle().' for '.$appointment->starts_at->format('D d M, H:i').'.']);
    }

    public function show(Appointment $appointment): View
    {
        $this->authorize('view', $appointment);

        $appointment->load(['contact', 'service', 'staff', 'branch', 'creator', 'comments.user']);

        $history = Appointment::query()->with('service')->forContact($appointment->contact_id)
            ->where('id', '!=', $appointment->id)->orderByDesc('starts_at')->limit(5)->get();

        return view('appointments::appointments.show', [
            'appointment' => $appointment,
            'history' => $history,
            'transitions' => $appointment->allowedTransitions(),
        ]);
    }

    public function edit(Appointment $appointment): View
    {
        $this->authorize('update', $appointment);

        return view('appointments::appointments.form', $this->formData($appointment));
    }

    public function update(AppointmentRequest $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $appointment->update($request->payload());

        return redirect()->route('appointments.show', $appointment)->with('flash', ['type' => 'success', 'message' => 'Appointment updated.']);
    }

    public function status(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('changeStatus', $appointment);

        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(Appointment::STATUSES))],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $appointment->transitionTo($data['status'], $data['reason'] ?? null);

        return back()->with('flash', ['type' => 'success', 'message' => 'Appointment marked '.strtolower($appointment->statusLabel()).'.']);
    }

    public function destroy(Appointment $appointment): RedirectResponse
    {
        $this->authorize('delete', $appointment);

        $appointment->delete();

        return redirect()->route('appointments.index')->with('flash', ['type' => 'success', 'message' => 'Appointment deleted.']);
    }

    public function comment(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorize('update', $appointment);

        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $appointment->addComment($data['body'], $request->user(), true);

        return back()->with('flash', ['type' => 'success', 'message' => 'Note added.']);
    }

    /** @return array{q: string, status: ?string, staff: ?string, contact: ?string, range: string} */
    protected function filters(Request $request): array
    {
        $range = (string) $request->query('range', '');
        if ($range === '') {
            $range = $request->query('contact') || $request->query('q') ? 'all' : 'upcoming';
        }

        return [
            'q' => trim((string) $request->query('q', '')),
            'status' => $request->query('status') ?: null,
            'staff' => $request->query('staff') ?: null,
            'contact' => $request->query('contact') ?: null,
            'range' => in_array($range, ['today', 'upcoming', 'past', 'all'], true) ? $range : 'upcoming',
        ];
    }

    /** @return Collection<int, string> */
    protected function staffOptions(): Collection
    {
        return app(WorkspaceContext::class)->getOrFail()->members()->orderBy('name')->get()->pluck('name', 'id');
    }

    /** @return array<string, mixed> */
    protected function formData(Appointment $appointment): array
    {
        $contacts = Contact::query()->active()->orderBy('name')->get()
            ->mapWithKeys(fn (Contact $c) => [$c->id => $c->displayName().($c->phone ? ' · '.$c->phone : '')]);

        $services = Service::query()->active()->orderBy('name')->get();

        return [
            'appointment' => $appointment,
            'contacts' => $contacts,
            'services' => $services,
            'serviceOptions' => $services->mapWithKeys(fn (Service $s) => [$s->id => $s->name.' · '.$s->durationLabel()]),
            'staff' => $this->staffOptions(),
            'branches' => Branch::query()->orderBy('name')->pluck('name', 'id'),
            'statuses' => array_intersect_key(Appointment::STATUSES, array_flip(Appointment::ACTIVE_STATUSES)),
            'settings' => BookingSettings::for(app(WorkspaceContext::class)->getOrFail()),
        ];
    }
}
