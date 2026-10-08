<?php

use Illuminate\Support\Facades\Route;
use Modules\Invoicing\Http\Controllers\InvoiceController;
use Modules\Invoicing\Http\Controllers\InvoicingSettingsController;
use Modules\Invoicing\Http\Controllers\ItemController;
use Modules\Invoicing\Http\Controllers\PaymentController;
use Modules\Invoicing\Http\Controllers\PublicDocumentController;
use Modules\Invoicing\Http\Controllers\QuoteController;

Route::middleware(['auth', 'workspace', 'onboarded', 'module:invoicing'])->group(function () {
    Route::get('/invoices/{invoice}/print', [InvoiceController::class, 'print'])->name('invoices.print');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('/invoices/{invoice}/comments', [InvoiceController::class, 'comment'])->name('invoices.comments.store');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
    Route::resource('invoices', InvoiceController::class);

    Route::get('/payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');

    Route::resource('items', ItemController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::prefix('settings/invoicing')->name('settings.invoicing.')->middleware('can:manage-workspace')->group(function () {
        Route::get('/', [InvoicingSettingsController::class, 'edit'])->name('edit');
        Route::put('/', [InvoicingSettingsController::class, 'update'])->name('update');
        Route::post('/tax-rates', [InvoicingSettingsController::class, 'storeTaxRate'])->name('tax-rates.store');
        Route::put('/tax-rates/{taxRate}', [InvoicingSettingsController::class, 'updateTaxRate'])->name('tax-rates.update');
        Route::delete('/tax-rates/{taxRate}', [InvoicingSettingsController::class, 'destroyTaxRate'])->name('tax-rates.destroy');
    });
});

Route::middleware(['auth', 'workspace', 'onboarded', 'module:quotes'])->group(function () {
    Route::get('/quotes/{quote}/print', [QuoteController::class, 'print'])->name('quotes.print');
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
    Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
    Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('quotes.reject');
    Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convert'])->name('quotes.convert');
    Route::post('/quotes/{quote}/comments', [QuoteController::class, 'comment'])->name('quotes.comments.store');
    Route::resource('quotes', QuoteController::class);
});

Route::get('/i/{uuid}', [PublicDocumentController::class, 'invoice'])->name('invoices.public');
Route::get('/q/{uuid}', [PublicDocumentController::class, 'quote'])->name('quotes.public');
