<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\V1\AppointmentResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Modules\Appointments\Models\Appointment;

/** Read-only: booking checks staff and room clashes in the app. */
class AppointmentController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Appointment::class);

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);

        $appointments = Appointment::query()
            ->status($request->string('status')->toString() ?: null)
            ->forStaff($request->input('staff_id'))
            ->forContact($request->input('contact_id'))
            ->when($request->filled('from') || $request->filled('to'), fn ($query) => $query->between(
                CarbonImmutable::parse($request->input('from', '2000-01-01'))->startOfDay(),
                CarbonImmutable::parse($request->input('to', '2100-01-01'))->endOfDay(),
            ))
            ->orderBy('starts_at')->paginate($this->perPage($request))->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        $this->authorize('view', $appointment);

        return new AppointmentResource($appointment);
    }
}
