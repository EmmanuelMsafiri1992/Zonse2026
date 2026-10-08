<?php

use Illuminate\Support\Facades\Route;
use Modules\Appointments\Http\Controllers\AppointmentController;
use Modules\Appointments\Http\Controllers\AppointmentsSettingsController;
use Modules\Appointments\Http\Controllers\ServiceController;

Route::middleware(['auth', 'workspace', 'onboarded', 'module:appointments'])->group(function () {
    Route::get('/appointments/calendar', [AppointmentController::class, 'calendar'])->name('appointments.calendar');
    Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'status'])->name('appointments.status');
    Route::post('/appointments/{appointment}/comments', [AppointmentController::class, 'comment'])->name('appointments.comments.store');
    Route::resource('appointments', AppointmentController::class);

    Route::resource('services', ServiceController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::prefix('settings/appointments')->name('settings.appointments.')->middleware('can:manage-workspace')->group(function () {
        Route::get('/', [AppointmentsSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [AppointmentsSettingsController::class, 'update'])->name('update');
    });
});
