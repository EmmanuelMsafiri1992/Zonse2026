@extends('layouts.app')
@section('title', $appointment->exists ? 'Edit booking' : 'New booking')
@section('content')
    <x-page-header :title="$appointment->exists ? 'Edit booking' : 'New booking'"
                   :sub="$appointment->exists ? $appointment->displayTitle().' · '.$appointment->starts_at->format('D d M Y, H:i') : 'Pick a customer, a service and a time. We will warn you about clashes.'"
                   :crumbs="['Appointments' => route('appointments.index'), $appointment->exists ? 'Edit' : 'New']" />

    @php
        $serviceMap = $services->mapWithKeys(fn ($s) => [$s->id => ['duration' => $s->duration_minutes, 'price' => $s->price]]);
        $initialDuration = old('duration_minutes', $appointment->exists || $appointment->starts_at ? $appointment->durationMinutes() : $settings['default_duration']);
    @endphp
    <form method="POST" action="{{ $appointment->exists ? route('appointments.update', $appointment) : route('appointments.store') }}"
          x-data="{
              services: {{ \Illuminate\Support\Js::from($serviceMap) }},
              serviceId: '{{ old('service_id', $appointment->service_id) }}',
              duration: {{ (int) $initialDuration }},
              price: '{{ old('price', $appointment->price) }}',
              time: '{{ old('time', $appointment->starts_at?->format('H:i')) }}',
              endsAt() {
                  const [h, m] = (this.time || '00:00').split(':').map(Number);
                  const t = h * 60 + m + Number(this.duration || 0);
                  return String(Math.floor(t / 60) % 24).padStart(2, '0') + ':' + String(t % 60).padStart(2, '0');
              },
              applyService() {
                  const s = this.services[this.serviceId];
                  if (s) { this.duration = s.duration; this.price = s.price; }
              }
          }">
        @csrf
        @if($appointment->exists) @method('PUT') @endif

        <div class="row g-3">
            <div class="col-lg-8">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Who and what</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.select name="contact_id" label="Customer" :options="$contacts" :value="$appointment->contact_id" placeholder="Choose a customer…" required />
                                @if($contacts->isEmpty())<div class="form-text mt-n2 mb-3">No contacts yet. <a href="{{ route('contacts.create') }}">Add a contact</a> first.</div>@endif
                            </div>
                            <div class="col-md-6">
                                <x-form.select name="service_id" label="Service" :options="$serviceOptions" :value="$appointment->service_id" placeholder="No specific service" x-model="serviceId" @change="applyService()" />
                                @if($services->isEmpty())<div class="form-text mt-n2 mb-3">Tip: add your <a href="{{ route('services.index') }}">services</a> to auto-fill duration and price.</div>@endif
                            </div>
                        </div>
                        <x-form.input name="title" label="Title (optional)" :value="$appointment->title" placeholder="Shown instead of the service name, e.g. Follow-up check" />
                        <div class="row">
                            <div class="col-md-6"><x-form.select name="staff_id" label="With" :options="$staff" :value="$appointment->staff_id" placeholder="Anyone available" /></div>
                            <div class="col-md-6"><x-form.select name="branch_id" label="Branch" :options="$branches" :value="$appointment->branch_id" placeholder="—" /></div>
                        </div>
                    </div>
                </div>

                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">When</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4"><x-form.input name="date" type="date" label="Date" :value="$appointment->starts_at?->format('Y-m-d')" required /></div>
                            <div class="col-md-4"><x-form.input name="time" type="time" label="Start time" :value="$appointment->starts_at?->format('H:i')" :step="$settings['slot_minutes'] * 60" x-model="time" required /></div>
                            <div class="col-md-4"><x-form.input name="duration_minutes" type="number" min="5" max="1440" :step="5" label="Duration (minutes)" :value="$initialDuration" x-model.number="duration" required /></div>
                        </div>
                        <div class="fs-8 text-muted mb-3">Opening hours {{ $settings['day_start'] }} – {{ $settings['day_end'] }}. Ends at <strong x-text="endsAt()"></strong>.</div>
                        <x-form.check name="allow_overlap" label="Allow overlap with another booking for the same staff member" :checked="(bool) old('allow_overlap', false)" help="Leave this off and we will stop you double-booking someone." />
                    </div>
                </div>
                <x-custom-fields.inputs entity="appointment" :record="$appointment" />
            </div>

            <div class="col-lg-4">
                <div class="card mb-3">
                    <div class="card-header"><h5 class="card-title">Details</h5></div>
                    <div class="card-body">
                        @if(! $appointment->exists || $appointment->isActive())
                            <x-form.select name="status" label="Status" :options="$statuses" :value="$appointment->status" required />
                        @endif
                        <x-form.input name="price" type="number" step="0.01" min="0" label="Price ({{ $workspace->currency_code }})" :value="$appointment->price" x-model="price" help="Leave empty to use the service price." />
                        <x-form.textarea name="notes" label="Notes" :value="$appointment->notes" rows="4" placeholder="Anything the team should know before the visit." />
                        @if($settings['booking_note'])<div class="alert alert-info fs-8 mb-0">{{ $settings['booking_note'] }}</div>@endif
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1"><x-icon name="check" /> {{ $appointment->exists ? 'Save changes' : 'Book it' }}</button>
                    <a href="{{ $appointment->exists ? route('appointments.show', $appointment) : route('appointments.calendar') }}" class="btn btn-white">Cancel</a>
                </div>
            </div>
        </div>
    </form>
@endsection
