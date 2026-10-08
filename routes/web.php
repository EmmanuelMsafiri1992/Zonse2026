<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\Apps\AppController;
use App\Http\Controllers\Apps\PosController;
use App\Http\Controllers\Apps\RecordController;
use App\Http\Controllers\Apps\RecordWorkflowController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentCaptureController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\Portal\PortalAuthController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicPageController;
use App\Http\Controllers\PublicSigningController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\ApiKeyController;
use App\Http\Controllers\Settings\ApprovalRuleController;
use App\Http\Controllers\Settings\AssistantSettingsController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\AutomationController;
use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BranchController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\CustomFieldController;
use App\Http\Controllers\Settings\DataExportController;
use App\Http\Controllers\Settings\MemberController;
use App\Http\Controllers\Settings\ModuleController;
use App\Http\Controllers\Settings\OcrSettingsController;
use App\Http\Controllers\Settings\PartnerController;
use App\Http\Controllers\Settings\PortalSettingsController;
use App\Http\Controllers\Settings\PublicPageSettingsController;
use App\Http\Controllers\Settings\SmsSettingsController;
use App\Http\Controllers\Settings\WebhookController;
use App\Http\Controllers\Settings\WorkspaceSettingsController;
use App\Http\Controllers\SignatureRequestController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\SocialLoginController;
use App\Http\Controllers\UssdCallbackController;
use App\Http\Controllers\UssdController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/pricing', [HomeController::class, 'pricing'])->name('pricing');

// Invitations can be opened by guests (they are asked to sign in / register first).
Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.accept');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])->middleware('auth')->name('invitations.accept.store');

// Signing links emailed to people outside the workspace; the token is the key.
Route::prefix('sign/{token}')->name('signing.')->middleware('throttle:30,1')->group(function () {
    Route::get('/', [PublicSigningController::class, 'show'])->name('show');
    Route::get('/document', [PublicSigningController::class, 'document'])->name('document');
    Route::get('/certificate', [PublicSigningController::class, 'certificate'])->name('certificate');
    Route::post('/', [PublicSigningController::class, 'sign'])->middleware('throttle:10,1')->name('sign');
    Route::post('/decline', [PublicSigningController::class, 'decline'])->middleware('throttle:10,1')->name('decline');
});

// Client portal: contacts sign in with a one-time emailed link (no password) and see only their own records.
Route::prefix('portal/{workspace:slug}')->name('portal.')->middleware('throttle:60,1')->group(function () {
    Route::get('/login', [PortalAuthController::class, 'show'])->name('login');
    Route::post('/login', [PortalAuthController::class, 'send'])->middleware('throttle:portal-link')->name('login.send');
    Route::get('/enter/{token}', [PortalAuthController::class, 'enter'])->middleware('throttle:20,1')->name('enter');
    Route::post('/logout', [PortalAuthController::class, 'logout'])->name('logout');

    Route::middleware('portal')->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');
        Route::get('/invoices', [PortalController::class, 'invoices'])->name('invoices');
        Route::get('/appointments', [PortalController::class, 'appointments'])->name('appointments');
        Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancelAppointment'])->whereNumber('appointment')->name('appointments.cancel');
        Route::get('/requests', [PortalController::class, 'requests'])->name('requests');
        Route::post('/requests', [PortalController::class, 'storeRequest'])->middleware('throttle:10,1')->name('requests.store');
        Route::get('/requests/{ticket}', [PortalController::class, 'showRequest'])->whereNumber('ticket')->name('requests.show');
        Route::post('/requests/{ticket}/reply', [PortalController::class, 'replyToRequest'])->whereNumber('ticket')->middleware('throttle:20,1')->name('requests.reply');
        Route::get('/documents', [PortalController::class, 'documents'])->name('documents');
        Route::get('/records', [PortalController::class, 'records'])->name('records');
        Route::get('/profile', [PortalController::class, 'profile'])->name('profile');
        Route::put('/profile', [PortalController::class, 'updateProfile'])->name('profile.update');
    });
});

// Public page (link in bio): visitors book, order or pay without an account.
Route::prefix('p/{workspace:slug}')->name('public.')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [PublicPageController::class, 'show'])->name('show');
    Route::get('/book', [PublicPageController::class, 'booking'])->name('booking');
    Route::post('/book', [PublicPageController::class, 'storeBooking'])->middleware('throttle:public-form')->name('booking.store');
    Route::get('/booking/{uuid}', [PublicPageController::class, 'showBooking'])->whereUuid('uuid')->name('booking.show');
    Route::post('/booking/{uuid}/cancel', [PublicPageController::class, 'cancelBooking'])->whereUuid('uuid')->middleware('throttle:public-form')->name('booking.cancel');
    Route::get('/order', [PublicPageController::class, 'order'])->name('order');
    Route::post('/order', [PublicPageController::class, 'storeOrder'])->middleware('throttle:public-form')->name('order.store');
    Route::get('/pay', [PublicPageController::class, 'payment'])->name('payment');
    Route::post('/pay', [PublicPageController::class, 'storePayment'])->middleware('throttle:public-form')->name('payment.store');
    Route::get('/received/{uuid}', [PublicPageController::class, 'received'])->whereUuid('uuid')->name('received');
});

// Called by the USSD gateway for every screen a feature phone shows; the token picks the workspace.
Route::post('/webhooks/ussd/{token}', UssdCallbackController::class)->middleware('throttle:120,1')->name('ussd.callback');

// Sign in with Google or Microsoft (guests), or link one to the signed-in user's profile.
Route::prefix('auth/{provider}')->name('sso.')->whereIn('provider', ['google', 'microsoft'])->middleware('throttle:20,1')->group(function () {
    Route::get('/redirect', [SocialLoginController::class, 'redirect'])->name('redirect');
    Route::get('/callback', [SocialLoginController::class, 'callback'])->name('callback');
});

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
        // Plain page visit through password confirmation, so the user lands back on the profile ready to add a passkey.
        Route::get('/profile/passkeys/confirm', fn () => redirect()->route('profile.edit'))->middleware('password.confirm')->name('profile.passkeys.confirm');
        Route::delete('/profile/sign-in/{provider}', [SocialLoginController::class, 'destroy'])->whereIn('provider', ['google', 'microsoft'])->name('sso.destroy');
        Route::put('/profile/notifications', [NotificationController::class, 'updatePreferences'])->name('profile.notifications.update');

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('/notifications/read', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::get('/notifications/{notification}', [NotificationController::class, 'open'])->whereUuid('notification')->name('notifications.open');
        Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification')->name('notifications.destroy');

        Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('/approvals', [ApprovalController::class, 'store'])->middleware('throttle:30,1')->name('approvals.store');
        Route::post('/approvals/{approvalRequest}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');
        Route::post('/approvals/{approvalRequest}/reject', [ApprovalController::class, 'reject'])->name('approvals.reject');
        Route::post('/approvals/{approvalRequest}/withdraw', [ApprovalController::class, 'withdraw'])->name('approvals.withdraw');

        Route::get('/signatures', [SignatureRequestController::class, 'index'])->name('signatures.index');
        Route::get('/signatures/create', [SignatureRequestController::class, 'create'])->name('signatures.create');
        Route::post('/signatures', [SignatureRequestController::class, 'store'])->middleware('throttle:20,1')->name('signatures.store');
        Route::get('/signatures/{signatureRequest}', [SignatureRequestController::class, 'show'])->name('signatures.show');
        Route::get('/signatures/{signatureRequest}/document', [SignatureRequestController::class, 'document'])->name('signatures.document');
        Route::get('/signatures/{signatureRequest}/certificate', [SignatureRequestController::class, 'certificate'])->name('signatures.certificate');
        Route::post('/signatures/{signatureRequest}/remind', [SignatureRequestController::class, 'remind'])->middleware('throttle:5,1')->name('signatures.remind');
        Route::post('/signatures/{signatureRequest}/cancel', [SignatureRequestController::class, 'cancel'])->name('signatures.cancel');

        Route::get('/captures', [DocumentCaptureController::class, 'index'])->name('captures.index');
        Route::post('/captures', [DocumentCaptureController::class, 'store'])->middleware('throttle:20,1')->name('captures.store');
        Route::get('/captures/{capture}', [DocumentCaptureController::class, 'show'])->name('captures.show');
        Route::put('/captures/{capture}', [DocumentCaptureController::class, 'update'])->name('captures.update');
        Route::get('/captures/{capture}/file', [DocumentCaptureController::class, 'file'])->name('captures.file');
        Route::post('/captures/{capture}/retry', [DocumentCaptureController::class, 'retry'])->middleware('throttle:10,1')->name('captures.retry');
        Route::delete('/captures/{capture}', [DocumentCaptureController::class, 'destroy'])->name('captures.destroy');

        Route::get('/assistant', [AssistantController::class, 'index'])->name('assistant.index');
        Route::post('/assistant', [AssistantController::class, 'store'])->middleware('throttle:20,1')->name('assistant.store');
        Route::get('/assistant/{conversation}', [AssistantController::class, 'show'])->name('assistant.show');
        Route::post('/assistant/{conversation}/messages', [AssistantController::class, 'ask'])->middleware('throttle:20,1')->name('assistant.ask');
        Route::get('/assistant/{conversation}/status', [AssistantController::class, 'status'])->name('assistant.status');
        Route::post('/assistant/{conversation}/retry', [AssistantController::class, 'retry'])->middleware('throttle:10,1')->name('assistant.retry');
        Route::delete('/assistant/{conversation}', [AssistantController::class, 'destroy'])->name('assistant.destroy');

        Route::middleware('can:access-workspace')->group(function () {
            Route::get('/phone-access', [UssdController::class, 'index'])->name('ussd.index');
            Route::put('/phone-access/pin', [UssdController::class, 'updatePin'])->middleware('throttle:10,1')->name('ussd.pin.update');
            Route::delete('/phone-access/pin', [UssdController::class, 'destroyPin'])->name('ussd.pin.destroy');
        });

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

            Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
            Route::get('/audit/export', [AuditLogController::class, 'export'])->name('audit.export');
            Route::get('/data-export', [DataExportController::class, 'index'])->name('data-export.index');
            Route::post('/data-export', [DataExportController::class, 'store'])->middleware('throttle:3,10')->name('data-export.store');

            Route::get('/api', [ApiKeyController::class, 'index'])->name('api.index');
            Route::post('/api/keys', [ApiKeyController::class, 'store'])->middleware('throttle:10,1')->name('api.keys.store');
            Route::delete('/api/keys/{token}', [ApiKeyController::class, 'destroy'])->whereNumber('token')->name('api.keys.destroy');
            Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
            Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('webhooks.show');
            Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('webhooks.update');
            Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
            Route::post('/webhooks/{webhook}/test', [WebhookController::class, 'test'])->middleware('throttle:10,1')->name('webhooks.test');
            Route::post('/webhooks/{webhook}/secret', [WebhookController::class, 'rotateSecret'])->name('webhooks.secret');
            Route::post('/webhooks/{webhook}/deliveries/{delivery}/resend', [WebhookController::class, 'redeliver'])->middleware('throttle:10,1')->name('webhooks.redeliver');

            Route::resource('automations', AutomationController::class)->except('show');
            Route::post('/automations/{automation}/toggle', [AutomationController::class, 'toggle'])->name('automations.toggle');
            Route::resource('custom-fields', CustomFieldController::class)->except('show');
            Route::resource('approval-rules', ApprovalRuleController::class)->except('show');
            Route::post('/custom-fields/{custom_field}/move', [CustomFieldController::class, 'move'])->name('custom-fields.move');

            Route::get('/branding', [BrandingController::class, 'edit'])->name('branding.edit');
            Route::put('/branding', [BrandingController::class, 'update'])->name('branding.update');
            Route::put('/branding/domain', [BrandingController::class, 'updateDomain'])->name('branding.domain.update');
            Route::post('/branding/domain/verify', [BrandingController::class, 'verifyDomain'])->middleware('throttle:10,1')->name('branding.domain.verify');
            Route::delete('/branding/domain', [BrandingController::class, 'destroyDomain'])->name('branding.domain.destroy');

            Route::get('/partners', [PartnerController::class, 'index'])->name('partners.index');
            Route::post('/partners', [PartnerController::class, 'enable'])->name('partners.enable');
            Route::put('/partners', [PartnerController::class, 'update'])->name('partners.update');
            Route::delete('/partners', [PartnerController::class, 'disable'])->name('partners.disable');
            Route::post('/partners/clients', [PartnerController::class, 'storeClient'])->name('partners.clients.store');

            Route::get('/portal', [PortalSettingsController::class, 'index'])->name('portal.index');
            Route::put('/portal', [PortalSettingsController::class, 'update'])->name('portal.update');
            Route::post('/portal/access', [PortalSettingsController::class, 'invite'])->middleware('throttle:30,1')->name('portal.invite');
            Route::post('/portal/access/{access}/link', [PortalSettingsController::class, 'resend'])->middleware('throttle:10,1')->name('portal.resend');
            Route::post('/portal/access/{access}/toggle', [PortalSettingsController::class, 'toggle'])->name('portal.toggle');
            Route::delete('/portal/access/{access}', [PortalSettingsController::class, 'destroy'])->name('portal.destroy');

            Route::get('/public-page', [PublicPageSettingsController::class, 'edit'])->name('public-page.edit');
            Route::put('/public-page', [PublicPageSettingsController::class, 'update'])->name('public-page.update');

            Route::get('/sms', [SmsSettingsController::class, 'edit'])->name('sms.edit');
            Route::put('/sms', [SmsSettingsController::class, 'update'])->name('sms.update');
            Route::post('/sms/test', [SmsSettingsController::class, 'test'])->middleware('throttle:5,1')->name('sms.test');

            Route::get('/document-capture', [OcrSettingsController::class, 'edit'])->name('ocr.edit');
            Route::put('/document-capture', [OcrSettingsController::class, 'update'])->name('ocr.update');

            Route::get('/assistant', [AssistantSettingsController::class, 'edit'])->name('assistant.edit');
            Route::put('/assistant', [AssistantSettingsController::class, 'update'])->name('assistant.update');

            Route::put('/phone-access', [UssdController::class, 'updateSettings'])->name('ussd.update');
            Route::post('/phone-access/token', [UssdController::class, 'rotateToken'])->name('ussd.token');
            Route::post('/phone-access/simulate', [UssdController::class, 'simulate'])->middleware('throttle:60,1')->name('ussd.simulate');
        });

        Route::middleware('can:manage-workspace')->group(function () {
            Route::get('/sms', [SmsController::class, 'index'])->name('sms.index');
            Route::post('/sms', [SmsController::class, 'store'])->middleware('throttle:10,1')->name('sms.store');
        });
    });
});
