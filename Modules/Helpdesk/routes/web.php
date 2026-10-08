<?php

use Illuminate\Support\Facades\Route;
use Modules\Helpdesk\Http\Controllers\TicketController;

Route::middleware(['auth', 'workspace', 'onboarded', 'module:helpdesk'])->group(function () {
    Route::post('/tickets/{ticket}/triage', [TicketController::class, 'triage'])->name('tickets.triage');
    Route::post('/tickets/{ticket}/replies', [TicketController::class, 'reply'])->name('tickets.replies.store');
    Route::resource('tickets', TicketController::class);
});
