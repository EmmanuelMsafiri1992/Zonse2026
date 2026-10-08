<?php

use Illuminate\Support\Facades\Route;
use Modules\Tasks\Http\Controllers\TaskController;

Route::middleware(['auth', 'workspace', 'onboarded', 'module:tasks'])->group(function () {
    Route::get('/tasks/board', [TaskController::class, 'board'])->name('tasks.board');
    Route::post('/tasks/{task}/status', [TaskController::class, 'status'])->name('tasks.status');
    Route::post('/tasks/{task}/comments', [TaskController::class, 'comment'])->name('tasks.comments.store');
    Route::resource('tasks', TaskController::class);
});
