<?php

use App\Http\Controllers\Api\V1\AppointmentController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\TicketController;
use Illuminate\Support\Facades\Route;

// Version 1. Keys are made under Settings > API & webhooks; "read" keys may only use GET.
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'api.workspace', 'throttle:api'])->group(function () {
    Route::middleware('abilities:read')->group(function () {
        Route::get('me', MeController::class)->name('me');

        Route::middleware('module:contacts')->group(function () {
            Route::apiResource('contacts', ContactController::class)->only(['index', 'show']);
            Route::apiResource('contacts', ContactController::class)->only(['store', 'update', 'destroy'])->middleware('abilities:write');
        });

        Route::middleware('module:tasks')->group(function () {
            Route::apiResource('tasks', TaskController::class)->only(['index', 'show']);
            Route::apiResource('tasks', TaskController::class)->only(['store', 'update', 'destroy'])->middleware('abilities:write');
        });

        Route::middleware('module:helpdesk')->group(function () {
            Route::apiResource('tickets', TicketController::class)->only(['index', 'show']);
            Route::apiResource('tickets', TicketController::class)->only(['store', 'update'])->middleware('abilities:write');
        });

        Route::middleware('module:invoicing')->group(function () {
            Route::get('payments', [InvoiceController::class, 'payments'])->name('payments.index');
            Route::apiResource('invoices', InvoiceController::class)->only(['index', 'show']);
        });

        Route::middleware('module:appointments')->group(function () {
            Route::apiResource('appointments', AppointmentController::class)->only(['index', 'show']);
        });
    });
});
