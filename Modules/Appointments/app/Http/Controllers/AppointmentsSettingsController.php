<?php

namespace Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Tenancy\WorkspaceContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Appointments\Support\BookingSettings;

/**
 * Working hours, slot length and confirmation defaults. Reachable by
 * workspace admins only (the routes sit behind can:manage-workspace).
 */
class AppointmentsSettingsController extends Controller
{
    public function edit(WorkspaceContext $context): View
    {
        return view('appointments::settings', [
            'settings' => BookingSettings::for($context->getOrFail()),
            'weekdays' => BookingSettings::weekdays(),
        ]);
    }

    public function update(Request $request, WorkspaceContext $context): RedirectResponse
    {
        $data = $request->validate([
            'slot_minutes' => ['required', 'integer', 'min:5', 'max:240'],
            'default_duration' => ['required', 'integer', 'min:5', 'max:1440'],
            'day_start' => ['required', 'date_format:H:i'],
            'day_end' => ['required', 'date_format:H:i', 'after:day_start'],
            'working_days' => ['required', 'array', 'min:1'],
            'working_days.*' => ['integer', 'between:1,7'],
            'auto_confirm' => ['nullable', 'boolean'],
            'booking_note' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['auto_confirm'] = $request->boolean('auto_confirm');
        $data['working_days'] = array_values(array_map('intval', $data['working_days']));

        BookingSettings::save($context->getOrFail(), $data);

        return back()->with('flash', ['type' => 'success', 'message' => 'Booking settings saved.']);
    }
}
