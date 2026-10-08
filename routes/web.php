<?php

use App\Http\Controllers\Apps\AppController;
use App\Http\Controllers\Apps\PosController;
use App\Http\Controllers\Apps\RecordController;
use App\Http\Controllers\Apps\RecordWorkflowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/pricing', [HomeController::class, 'pricing'])->name('pricing');

// Invitations can be opened by guests (they are asked to sign in / register first).
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept.store');

Route::middleware(['auth', 'workspace'])->group(function () {
    // Setup wizard
    Route::get('/onboarding', [OnboardingController::class, 'start'])->name('onboarding.start');
    Route::get('/onboarding/{step}', [OnboardingController::class, 'show'])->whereNumber('step')->name('onboarding.step');
    Route::post('/onboarding/{step}', [OnboardingController::class, 'store'])->whereNumber('step')->name('onboarding.store');

    // Workspaces
    Route::post('/workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');
    Route::post('/workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('workspaces.switch');

    Route::middleware('onboarded')->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
        Route::get('/search', SearchController::class)->name('search');

        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');

        // Blueprint apps: every data-driven catalogue module runs on these generic screens.
        Route::get('/apps', [AppController::class, 'index'])->name('apps.index');
        // The point-of-sale till sits beside the generic screens (it needs Invoicing items and payments).
        Route::prefix('apps/pos/till')->middleware('module:pos')->name('apps.pos.')->group(function () {
            Route::get('/', [PosController::class, 'till'])->name('till');
            Route::post('/', [PosController::class, 'sell'])->name('sell');
        });
        Route::prefix('apps/{blueprint}')->middleware('blueprint')->group(function () {
            Route::get('/', [AppController::class, 'show'])->name('apps.show');
            Route::get('/reports', [RecordWorkflowController::class, 'reports'])->name('apps.reports');

            Route::prefix('{entity}')->name('apps.records.')->group(function () {
                Route::get('/', [RecordController::class, 'index'])->name('index');
                Route::get('/create', [RecordController::class, 'create'])->name('create');
                Route::get('/export', [RecordController::class, 'export'])->name('export');
                Route::post('/', [RecordController::class, 'store'])->name('store');
                Route::get('/{record}', [RecordController::class, 'show'])->whereNumber('record')->name('show');
                Route::get('/{record}/edit', [RecordController::class, 'edit'])->whereNumber('record')->name('edit');
                Route::put('/{record}', [RecordController::class, 'update'])->whereNumber('record')->name('update');
                Route::post('/{record}/status', [RecordController::class, 'status'])->whereNumber('record')->name('status');
                Route::post('/{record}/comments', [RecordController::class, 'comment'])->whereNumber('record')->name('comments.store');
                Route::delete('/{record}', [RecordController::class, 'destroy'])->whereNumber('record')->name('destroy');
                Route::post('/{record}/bill', [RecordWorkflowController::class, 'bill'])->whereNumber('record')->name('bill');
                Route::post('/{record}/payments', [RecordWorkflowController::class, 'pay'])->whereNumber('record')->name('payments.store');
                Route::post('/{record}/actions/{action}', [RecordWorkflowController::class, 'action'])->whereNumber('record')->name('action');
                Route::get('/{record}/print/{document}', [RecordWorkflowController::class, 'document'])->whereNumber('record')->name('document');
            });
        });

        Route::prefix('settings')->name('settings.')->middleware('can:manage-workspace')->group(function () {
            Route::get('/workspace', [WorkspaceSettingsController::class, 'edit'])->name('workspace.edit');
            Route::put('/workspace', [WorkspaceSettingsController::class, 'update'])->name('workspace.update');

            Route::get('/members', [MemberController::class, 'index'])->name('members.index');
            Route::post('/members/invite', [MemberController::class, 'invite'])->name('members.invite');
            Route::patch('/members/{user}', [MemberController::class, 'update'])->name('members.update');
            Route::delete('/members/{user}', [MemberController::class, 'destroy'])->name('members.destroy');
            Route::delete('/invitations/{invitation}', [MemberController::class, 'destroyInvitation'])->name('members.invitations.destroy');

            Route::get('/branches', [BranchController::class, 'index'])->name('branches.index');
            Route::post('/branches', [BranchController::class, 'store'])->name('branches.store');
            Route::put('/branches/{branch}', [BranchController::class, 'update'])->name('branches.update');
            Route::delete('/branches/{branch}', [BranchController::class, 'destroy'])->name('branches.destroy');

            Route::get('/modules', [ModuleController::class, 'index'])->name('modules.index');
            Route::post('/modules/{module:key}/enable', [ModuleController::class, 'enable'])->name('modules.enable');
            Route::delete('/modules/{module:key}', [ModuleController::class, 'disable'])->name('modules.disable');

            Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
            Route::post('/billing/subscribe/{plan:key}', [BillingController::class, 'subscribe'])->name('billing.subscribe');
            Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
        });
    });
});
