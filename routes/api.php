<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Analytics\CompanyController;
use App\Http\Controllers\Analytics\CustomEventsController;
use App\Http\Controllers\Analytics\DevicesController;
use App\Http\Controllers\Analytics\GeoController;
use App\Http\Controllers\Analytics\IdentityController;
use App\Http\Controllers\Analytics\PagesController;
use App\Http\Controllers\Analytics\OverviewController;
use App\Http\Controllers\Analytics\PipelineController;
use App\Http\Controllers\Analytics\RealtimeController;
use App\Http\Controllers\Analytics\ReferrersController;
use App\Http\Controllers\Analytics\StatsController;
use App\Http\Controllers\Analytics\VisitorController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\Domain\DomainController;
use App\Http\Controllers\Domain\PipelineManagementController;
use App\Http\Controllers\Domain\SnippetController;
use App\Http\Controllers\Tracker\CorsPreflightController;
use App\Http\Controllers\Tracker\OptoutController;
use App\Http\Controllers\Tracker\TrackController;
use App\Http\Controllers\Ux\UxScoreController;
use App\Http\Controllers\Ux\UxIssuesController;
use App\Http\Controllers\Ux\UxHeatmapController;
use App\Http\Controllers\Ux\UxErrorsController;
use App\Http\Controllers\Ai\AiController;
use App\Http\Controllers\Admin\AdminAuditLogController;
use App\Http\Controllers\Admin\AdminDomainController;
use App\Http\Controllers\Admin\AdminPaymentController;
use App\Http\Controllers\Admin\AdminPaymentMethodController;
use App\Http\Controllers\Admin\AdminPlanController;
use App\Http\Controllers\Admin\AdminStatsController;
use App\Http\Controllers\Admin\AdminSubscriptionController;
use App\Http\Controllers\Admin\AdminThemeController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\AlertRuleController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\GdprController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\SavedViewController;
use App\Http\Controllers\SharedReportController;
use App\Http\Controllers\ThemeController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public utility routes
|--------------------------------------------------------------------------
*/
Route::get('health', HealthController::class)->name('health');
Route::get('theme', [ThemeController::class, 'show'])->name('theme');

/*
|--------------------------------------------------------------------------
| Tracker endpoints (public, no auth)
|--------------------------------------------------------------------------
*/
Route::post('track', TrackController::class)->name('track')->middleware('throttle:300,1');
Route::post('track/optout', OptoutController::class)->name('track.optout');
Route::options('track', CorsPreflightController::class);

/*
|--------------------------------------------------------------------------
| Public auth routes
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('register', RegisterController::class)->name('register');
    Route::post('login', LoginController::class)->name('login')->middleware('throttle:10,1');

    // 2FA challenge (pre-auth â€” no sanctum guard yet)
    Route::post('two-factor/verify', [TwoFactorController::class, 'verifyChallenge'])
        ->name('two-factor.verify')
        ->middleware('throttle:5,15');

    // Password reset
    Route::post('forgot-password', [PasswordController::class, 'forgot'])
        ->name('password.forgot')
        ->middleware('throttle:5,15');
    Route::post('reset-password', [PasswordController::class, 'reset'])->name('password.reset');
});

// Email verification link
Route::get('auth/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->name('verification.verify');

// One-click unsubscribe (signed URL, no auth required)
Route::get('notifications/unsubscribe/{token}', [NotificationPreferenceController::class, 'unsubscribe'])
    ->name('notifications.unsubscribe');

// Public shared report
Route::get('public/report/{token}', [SharedReportController::class, 'publicView'])
    ->name('public.report');

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    // Auth session
    Route::post('auth/logout', LogoutController::class)->name('auth.logout');
    Route::post('auth/email/resend-verification', [EmailVerificationController::class, 'resend'])
        ->name('auth.verification.resend')
        ->middleware('throttle:3,10');

    // Profile & preferences
    Route::prefix('profile')->name('profile.')->group(function () {
        Route::get('/', [ProfileController::class, 'show'])->name('show');
        Route::patch('/', [ProfileController::class, 'update'])->name('update');
        Route::put('password', [ProfileController::class, 'changePassword'])->name('password');
        Route::post('password', [ProfileController::class, 'changePassword'])->name('password.post'); // POST alias
        Route::post('api-key/regenerate', [ProfileController::class, 'regenerateApiKey'])->name('api-key.regenerate');
        Route::get('api-key', [ProfileController::class, 'apiKey'])->name('api-key');
        Route::get('sessions', [ProfileController::class, 'sessions'])->name('sessions');
        Route::delete('sessions/{tokenId}', [ProfileController::class, 'revokeSession'])->name('sessions.revoke');
        Route::delete('/', [ProfileController::class, 'destroy'])->name('destroy');
        Route::patch('preferences', [ProfileController::class, 'preferences'])->name('preferences');

        // 2FA profile-friendly aliases
        Route::get('two-factor/status', [TwoFactorController::class, 'status'])->name('two-factor.status');
        Route::post('two-factor/enable', [TwoFactorController::class, 'profileEnable'])->name('two-factor.profile-enable');
        Route::post('two-factor/confirm', [TwoFactorController::class, 'profileConfirm'])->name('two-factor.profile-confirm');
        Route::post('two-factor/disable', [TwoFactorController::class, 'profileDisable'])->name('two-factor.profile-disable');
    });

    // 2FA management (authenticated)
    Route::prefix('auth/two-factor')->name('two-factor.')->group(function () {
        Route::post('setup', [TwoFactorController::class, 'setup'])->name('setup');
        Route::post('enable', [TwoFactorController::class, 'enable'])->name('enable');
        Route::delete('disable', [TwoFactorController::class, 'disable'])->name('disable');
    });

    // Onboarding
    Route::prefix('onboarding')->name('onboarding.')->group(function () {
        Route::get('/', [OnboardingController::class, 'show'])->name('show');
        Route::patch('{step}', [OnboardingController::class, 'markStep'])->name('step');
    });

    // Notifications
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::patch('{id}/read', [NotificationController::class, 'markRead'])->name('read');
        Route::patch('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
        Route::delete('{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::delete('/', [NotificationController::class, 'clearRead'])->name('clear-read');
    });

    // Notification preferences
    Route::prefix('notification-preferences')->name('notification-preferences.')->group(function () {
        Route::get('/', [NotificationPreferenceController::class, 'index'])->name('index');
        Route::patch('/', [NotificationPreferenceController::class, 'update'])->name('update');
        Route::put('/', [NotificationPreferenceController::class, 'update'])->name('update.put'); // PUT alias
    });

    // Billing
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('/', [BillingController::class, 'show'])->name('show');
        Route::post('subscribe', [BillingController::class, 'subscribe'])->name('subscribe');
        Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
    });

    // GDPR
    Route::prefix('gdpr')->name('gdpr.')->group(function () {
        Route::delete('visitor', [GdprController::class, 'deleteVisitor'])->name('visitor.delete');
        Route::get('optout-status', [GdprController::class, 'optoutStatus'])->name('optout-status');
    });

    // Exports
    Route::prefix('exports')->name('exports.')->group(function () {
        Route::post('/', [ExportController::class, 'store'])->name('store');
        Route::get('{id}', [ExportController::class, 'show'])->name('show');
        Route::get('{id}/download', [ExportController::class, 'download'])->name('download');
    });

    // Shared reports
    Route::prefix('shared-reports')->name('shared-reports.')->group(function () {
        Route::post('/', [SharedReportController::class, 'store'])->name('store');
        Route::get('{domainId}', [SharedReportController::class, 'index'])->name('index');
        Route::delete('{id}', [SharedReportController::class, 'destroy'])->name('destroy');
    });

    // Saved views â€” domain-scoped alias for frontend (/saved-views/{domainId})
    Route::get('saved-views/{domainId}', [SavedViewController::class, 'index'])->name('saved-views.index');
    Route::post('saved-views/{domainId}', [SavedViewController::class, 'store'])->name('saved-views.store');
    Route::delete('saved-views/item/{view}', [SavedViewController::class, 'destroy'])->name('saved-views.destroy');

    /*
    |--------------------------------------------------------------------------
    | Domain routes
    |--------------------------------------------------------------------------
    */
    Route::prefix('domains')->name('domains.')->group(function () {
        Route::get('/', [DomainController::class, 'index'])->name('index');
        Route::post('/', [DomainController::class, 'store'])->name('store');
        Route::get('/{domain}', [DomainController::class, 'show'])->name('show');
        Route::patch('/{domain}', [DomainController::class, 'update'])->name('update');
        Route::delete('/{domain}', [DomainController::class, 'destroy'])->name('destroy');

        // Script token management
        Route::post('/{domain}/rotate-token', [DomainController::class, 'rotateToken'])->name('rotate-token');
        Route::post('/{domain}/verify-script', [DomainController::class, 'verifyScript'])->name('verify-script');

        // Embed snippet
        Route::get('/{domain}/snippet', SnippetController::class)->name('snippet');

        // Exclusions
        Route::prefix('/{domain}/exclusions')->name('exclusions.')->group(function () {
            Route::get('/', [DomainController::class, 'listExclusions'])->name('index');
            Route::post('/', [DomainController::class, 'storeExclusion'])->name('store');
            Route::delete('/{exclusion}', [DomainController::class, 'destroyExclusion'])->name('destroy');
        });

        // Analytics (domain-scoped)
        Route::prefix('/{domain}/analytics')->name('analytics.')->group(function () {
            Route::get('stats', StatsController::class)->name('stats');
            Route::get('pages', PagesController::class)->name('pages');
            Route::get('referrers', ReferrersController::class)->name('referrers');
            Route::get('devices', DevicesController::class)->name('devices');
            Route::get('geo', GeoController::class)->name('geo');
            Route::get('custom-events', CustomEventsController::class)->name('custom-events');
            Route::get('pipelines/{pipeline}/funnel', PipelineController::class)->name('pipelines.funnel');
            Route::get('realtime', RealtimeController::class)->name('realtime');
        });

        // Webhooks (domain-scoped)
        Route::prefix('/{domain}/webhooks')->name('webhooks.')->group(function () {
            Route::get('/', [WebhookController::class, 'index'])->name('index');
            Route::post('/', [WebhookController::class, 'store'])->name('store');
            Route::put('{webhook}', [WebhookController::class, 'update'])->name('update');
            Route::patch('{webhook}', [WebhookController::class, 'update'])->name('update.patch');
            Route::delete('{webhook}', [WebhookController::class, 'destroy'])->name('destroy');
            Route::post('{webhook}/test', [WebhookController::class, 'test'])->name('test');
            Route::get('{webhook}/logs', [WebhookController::class, 'logs'])->name('logs');
        });

        // Saved views (domain-scoped)
        Route::prefix('/{domain}/saved-views')->name('saved-views.')->group(function () {
            Route::get('/', [SavedViewController::class, 'index'])->name('index');
            Route::post('/', [SavedViewController::class, 'store'])->name('store');
            Route::delete('{view}', [SavedViewController::class, 'destroy'])->name('destroy');
        });

        // Alert rules (domain-scoped)
        Route::prefix('/{domain}/alert-rules')->name('alert-rules.')->group(function () {
            Route::get('/', [AlertRuleController::class, 'index'])->name('index');
            Route::post('/', [AlertRuleController::class, 'store'])->name('store');
            Route::put('{rule}', [AlertRuleController::class, 'update'])->name('update');
            Route::patch('{rule}', [AlertRuleController::class, 'update'])->name('update.patch');
            Route::delete('{rule}', [AlertRuleController::class, 'destroy'])->name('destroy');
        });

        // Pipelines (domain-scoped)
        Route::prefix('/{domain}/pipelines')->name('pipelines.')->group(function () {
            Route::get('/', [PipelineManagementController::class, 'index'])->name('index');
            Route::post('/', [PipelineManagementController::class, 'store'])->name('store');
            Route::put('{pipeline}', [PipelineManagementController::class, 'update'])->name('update');
            Route::delete('{pipeline}', [PipelineManagementController::class, 'destroy'])->name('destroy');
            Route::post('{pipeline}/steps', [PipelineManagementController::class, 'addStep'])->name('steps.store');
            Route::delete('{pipeline}/steps/{step}', [PipelineManagementController::class, 'removeStep'])->name('steps.destroy');
            Route::post('{pipeline}/reorder', [PipelineManagementController::class, 'reorderSteps'])->name('reorder');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Analytics (alternative domain-by-id routes)
    |--------------------------------------------------------------------------
    */
    Route::prefix('analytics/{domainId}')->name('analytics2.')->group(function () {
        Route::get('identities', [IdentityController::class, 'index'])->name('identities');
        Route::get('identities/{externalId}', [IdentityController::class, 'show'])->name('identities.show');
        Route::get('companies', [CompanyController::class, 'index'])->name('companies');
        Route::get('companies/{companyDomain}', [CompanyController::class, 'show'])->name('companies.show');
    });

    /*
    |--------------------------------------------------------------------------
    | Analytics by domain ID â€” model-binding aliases for frontend
    | The {domain} param triggers implicit model binding (Domain::find($id))
    |--------------------------------------------------------------------------
    */
    Route::prefix('analytics/{domain}')->name('analyticsById.')->group(function () {
        Route::get('stats', StatsController::class)->name('stats');
        Route::get('devices', DevicesController::class)->name('devices');
        Route::get('referrers', ReferrersController::class)->name('referrers');
        Route::get('realtime', RealtimeController::class)->name('realtime');
        Route::get('pages', PagesController::class)->name('pages');
        Route::get('geo', GeoController::class)->name('geo');
        Route::get('custom-events', CustomEventsController::class)->name('custom-events');
        Route::get('funnels/{pipeline}', PipelineController::class)->name('funnels');
    });

    Route::prefix('analytics/{domainId}')->name('analyticsVisitors.')->group(function () {
        Route::get('overview', OverviewController::class)->name('overview');
        Route::get('visitors', [VisitorController::class, 'index'])->name('visitors');
        Route::get('visitors/{visitorId}', [VisitorController::class, 'show'])->name('visitors.show');
    });

    /*
    |--------------------------------------------------------------------------
    | UX Intelligence
    |--------------------------------------------------------------------------
    */
    Route::prefix('ux/{domainId}')->name('ux.')->group(function () {
        Route::get('score', UxScoreController::class)->name('score');
        Route::get('issues', UxIssuesController::class)->name('issues');
        Route::get('heatmap', UxHeatmapController::class)->name('heatmap');
        Route::get('errors', UxErrorsController::class)->name('errors');
    });

    /*
    |--------------------------------------------------------------------------
    | AI
    |--------------------------------------------------------------------------
    */
    Route::prefix('ai/{domainId}')->name('ai.')->group(function () {
        Route::get('segments', [AiController::class, 'segments'])->name('segments');
        Route::get('suggestions', [AiController::class, 'suggestions'])->name('suggestions');
        Route::post('analyze', [AiController::class, 'analyze'])->name('analyze');
        Route::get('quota', [AiController::class, 'quotaStatus'])->name('quota');
        Route::post('chat', [AiController::class, 'chat'])->name('chat'); // Phase 2 stub
    });
    Route::patch('ai/suggestions/{id}/dismiss', [AiController::class, 'dismissSuggestion'])
        ->name('ai.suggestions.dismiss');

    /*
    |--------------------------------------------------------------------------
    | Session Replay stubs (Phase 2)
    |--------------------------------------------------------------------------
    */
    Route::prefix('replay/{domainId}')->name('replay.')->group(function () {
        Route::get('sessions', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
        Route::get('sessions/{sessionId}', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
        Route::delete('sessions/{sessionId}', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
    });

    /*
    |--------------------------------------------------------------------------
    | Website Chatbot stubs (Phase 2)
    |--------------------------------------------------------------------------
    */
    Route::prefix('chatbot/{domainId}')->name('chatbot.')->group(function () {
        Route::get('config', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
        Route::put('config', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
        Route::get('conversations', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
        Route::get('conversations/{id}', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503));
    });
});

/*
|--------------------------------------------------------------------------
| Superadmin routes
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'superadmin'])->prefix('admin')->name('admin.')->group(function () {

    Route::get('stats', AdminStatsController::class)->name('stats');

    // Users
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('index');
        Route::get('{id}', [AdminUserController::class, 'show'])->name('show');
        Route::patch('{id}', [AdminUserController::class, 'update'])->name('update');
        Route::post('{id}/block', [AdminUserController::class, 'block'])->name('block');
        Route::post('{id}/unblock', [AdminUserController::class, 'unblock'])->name('unblock');
        Route::post('{id}/impersonate', [AdminUserController::class, 'impersonate'])->name('impersonate');
        Route::post('{id}/disable-2fa', [AdminUserController::class, 'disable2fa'])->name('disable-2fa');
        Route::post('{id}/toggle-admin', [AdminUserController::class, 'toggleAdmin'])->name('toggle-admin');
        Route::delete('{id}', [AdminUserController::class, 'destroy'])->name('destroy');
        Route::post('{id}/subscriptions', [AdminSubscriptionController::class, 'assign'])->name('subscriptions.assign');
    });
    Route::delete('impersonate', [AdminUserController::class, 'endImpersonation'])->name('impersonate.end');

    // Plans
    Route::prefix('plans')->name('plans.')->group(function () {
        Route::get('/', [AdminPlanController::class, 'index'])->name('index');
        Route::post('/', [AdminPlanController::class, 'store'])->name('store');
        Route::get('{id}', [AdminPlanController::class, 'show'])->name('show');
        Route::put('{id}', [AdminPlanController::class, 'update'])->name('update');
        Route::delete('{id}', [AdminPlanController::class, 'destroy'])->name('destroy');
        Route::patch('{id}/toggle-visibility', [AdminPlanController::class, 'toggleVisibility'])->name('toggle-visibility');
    });

    // Payment methods
    Route::prefix('payment-methods')->name('payment-methods.')->group(function () {
        Route::get('/', [AdminPaymentMethodController::class, 'index'])->name('index');
        Route::post('/', [AdminPaymentMethodController::class, 'store'])->name('store');
        Route::put('{id}', [AdminPaymentMethodController::class, 'update'])->name('update');
        Route::delete('{id}', [AdminPaymentMethodController::class, 'destroy'])->name('destroy');
    });

    // Subscriptions
    Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
        Route::get('/', [AdminSubscriptionController::class, 'index'])->name('index');
        Route::get('{id}', [AdminSubscriptionController::class, 'show'])->name('show');
        Route::post('{id}/upgrade', [AdminSubscriptionController::class, 'upgrade'])->name('upgrade');
        Route::post('{id}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('cancel');
        Route::post('{id}/pause', [AdminSubscriptionController::class, 'pause'])->name('pause');
        Route::post('{id}/resume', [AdminSubscriptionController::class, 'resume'])->name('resume');
    });

    // Payments
    Route::prefix('payments')->name('payments.')->group(function () {
        Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
        Route::get('{id}', [AdminPaymentController::class, 'show'])->name('show');
        Route::post('{id}/refund', [AdminPaymentController::class, 'refund'])->name('refund');
    });

    // Domains
    Route::prefix('domains')->name('domains.')->group(function () {
        Route::get('/', [AdminDomainController::class, 'index'])->name('index');
        Route::delete('{id}', [AdminDomainController::class, 'destroy'])->name('destroy');
    });

    // Theme
    Route::prefix('theme')->name('theme.')->group(function () {
        Route::get('/', [AdminThemeController::class, 'index'])->name('index');
        Route::put('/', [AdminThemeController::class, 'update'])->name('update');
        Route::post('logo', [AdminThemeController::class, 'uploadLogo'])->name('logo');
    });

    // Audit log
    Route::get('audit-log', AdminAuditLogController::class)->name('audit-log');
});
