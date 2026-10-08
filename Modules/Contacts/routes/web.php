<?php

use Illuminate\Support\Facades\Route;
use Modules\Contacts\Http\Controllers\ContactController;

Route::middleware(['auth', 'workspace', 'onboarded', 'module:contacts'])->group(function () {
    Route::get('/contacts/export', [ContactController::class, 'export'])->name('contacts.export');
    Route::post('/contacts/{contact}/comments', [ContactController::class, 'comment'])->name('contacts.comments.store');
    Route::resource('contacts', ContactController::class);
});
