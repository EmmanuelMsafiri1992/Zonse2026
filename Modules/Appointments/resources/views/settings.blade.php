@extends('layouts.app')
@section('title', 'Booking settings')
@section('content')
    <x-page-header title="Booking settings" sub="Opening hours, slot length and how new bookings start out." :crumbs="['Settings' => route('settings.workspace.edit'), 'Bookings']" />

    <form method="POST" action="{{ route('settings.appointments.update') }}" class="row g-3">
        @csrf @method('PUT')
        <div class="col-lg-7">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Opening hours</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="day_start" type="time" label="Day starts" :value="$settings['day_start']" required /></div>
                        <div class="col-md-6"><x-form.input name="day_end" type="time" label="Day ends" :value="$settings['day_end']" required /></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">Working days</label>
                        @error('working_days')<div class="text-danger fs-8 mb-1">{{ $message }}</div>@enderror
                        <div class="d-flex flex-wrap gap-3">
                            @foreach($weekdays as $number => $label)
                                <label class="form-check mb-0"><input type="checkbox" name="working_days[]" value="{{ $number }}" class="form-check-input" @checked(in_array($number, old('working_days', $settings['working_days'])))> <span class="form-check-label">{{ $label }}</span></label>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card mb-3">
                <div class="card-header"><h5 class="card-title">Defaults</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6"><x-form.input name="slot_minutes" type="number" min="5" max="240" step="5" label="Slot length (minutes)" :value="$settings['slot_minutes']" required help="The time picker steps by this." /></div>
                        <div class="col-md-6"><x-form.input name="default_duration" type="number" min="5" max="1440" step="5" label="Default duration" :value="$settings['default_duration']" required help="Used when no service is picked." /></div>
                    </div>
                    <x-form.check name="auto_confirm" label="New bookings start as confirmed" :checked="(bool) old('auto_confirm', $settings['auto_confirm'])" help="Turn off if you phone customers to confirm first." switch />
                    <x-form.textarea name="booking_note" label="Reminder shown on the booking form" :value="$settings['booking_note']" rows="2" placeholder="e.g. Ask for the medical aid number." />
                </div>
                <div class="card-footer d-flex justify-content-end"><button class="btn btn-primary"><x-icon name="check" /> Save settings</button></div>
            </div>
        </div>
    </form>
@endsection
