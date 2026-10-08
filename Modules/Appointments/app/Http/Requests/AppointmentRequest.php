<?php

namespace Modules\Appointments\Http\Requests;

use App\Support\CustomFields;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Appointments\Models\Appointment;
use Modules\Appointments\Models\Service;

class AppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $workspaceId = $this->user()->current_workspace_id;

        return [
            'contact_id' => ['required', Rule::exists('contacts', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('workspace_id', $workspaceId)->whereNull('deleted_at')],
            'staff_id' => ['nullable', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspaceId)],
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('workspace_id', $workspaceId)],
            'title' => ['nullable', 'string', 'max:160'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'status' => ['nullable', Rule::in(Appointment::ACTIVE_STATUSES)],
            'price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'allow_overlap' => ['nullable', 'boolean'],
        ] + CustomFields::rules('appointment', $this, $this->route('appointment'));
    }

    /** @return array<string, string> */
    public function attributes(): array
    {
        return ['contact_id' => 'customer', 'service_id' => 'service', 'staff_id' => 'staff member', 'duration_minutes' => 'duration'] + CustomFields::attributes('appointment');
    }

    /** Reject double-booking a staff member unless the user explicitly allows the overlap. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->filled('staff_id') || $this->boolean('allow_overlap')) {
                    return;
                }

                [$startsAt, $endsAt] = $this->window();
                $current = $this->route('appointment');
                $clash = Appointment::conflictsFor((int) $this->input('staff_id'), $startsAt, $endsAt, $current?->id)
                    ->with('contact')->orderBy('starts_at')->first();

                if ($clash) {
                    $validator->errors()->add('time', sprintf(
                        'This staff member already has %s with %s from %s to %s. Pick another time or tick "Allow overlap".',
                        $clash->displayTitle(), $clash->contact?->displayName() ?? 'a customer',
                        $clash->starts_at->format('H:i'), $clash->ends_at->format('H:i'),
                    ));
                }
            },
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function window(): array
    {
        $startsAt = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->input('date').' '.$this->input('time'));

        return [$startsAt, $startsAt->addMinutes((int) $this->input('duration_minutes'))];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $data = $this->validated();
        [$startsAt, $endsAt] = $this->window();

        $price = $data['price'] ?? null;
        if ($price === null && ! empty($data['service_id'])) {
            $price = Service::query()->find($data['service_id'])?->price;
        }

        return [
            'contact_id' => $data['contact_id'],
            'service_id' => $data['service_id'] ?? null,
            'staff_id' => $data['staff_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'title' => $data['title'] ?? null,
            'starts_at' => $startsAt->format('Y-m-d H:i:s'),
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'price' => $price,
            'notes' => $data['notes'] ?? null,
        ] + (isset($data['status']) ? ['status' => $data['status']] : []) + CustomFields::payload('appointment', $this, $this->route('appointment'));
    }
}
