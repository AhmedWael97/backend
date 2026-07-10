<?php

use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\ProfileController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\TwoFactorController;
use App\Http\Controllers\Analytics\CompanyController;
use App\Http\Controllers\Payment\PaymobController;
use App\Http\Controllers\Analytics\CustomEventsController;
use App\Http\Controllers\Analytics\CustomEventsStoreController;
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
use App\Http\Controllers\Analytics\BotStatsController;
use App\Http\Controllers\Analytics\CampaignsController;
use App\Http\Controllers\Analytics\AdSpendController;
use App\Http\Controllers\Analytics\RetentionController;
use App\Http\Controllers\Analytics\ExperimentController;
use App\Http\Controllers\Analytics\PortfolioController;
use App\Http\Controllers\Analytics\LtvController;
use App\Http\Controllers\Tools\SeoRankController;
use App\Http\Controllers\Growth\LeadController;
use App\Http\Controllers\Growth\OutreachController;
use App\Http\Controllers\Analytics\EngagedVisitorsController;
use App\Http\Controllers\Analytics\SummaryController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\GeoCurrencyController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UpgradeTicketController;
use App\Http\Controllers\Admin\AdminUpgradeTicketController;
use App\Http\Controllers\Domain\DomainController;
use App\Http\Controllers\Domain\PipelineManagementController;
use App\Http\Controllers\Domain\SnippetController;
use App\Http\Controllers\Tracker\CorsPreflightController;
use App\Http\Controllers\Tracker\OptoutController;
use App\Http\Controllers\Tracker\TrackController;
use App\Http\Controllers\Ux\UxScoreController;
use App\Http\Controllers\Ux\UxIssuesController;
use App\Http\Controllers\Ux\UxHeatmapController;
use App\Http\Controllers\Ux\UxHeatmapScreenshotController;
use App\Http\Controllers\Ux\UxErrorsController;
use App\Http\Controllers\Ux\UxScrollDepthController;
use App\Http\Controllers\Ux\UxWebVitalsController;
use App\Http\Controllers\Ux\UxPerformanceController;
use App\Http\Controllers\Replay\ReplayIngestController;
use App\Http\Controllers\Replay\ReplayController;
use App\Http\Controllers\Ai\AiController;
use App\Http\Controllers\Ai\ChatbotController;
use App\Http\Controllers\Tools\SitemapController;
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
use App\Http\Controllers\Tools\SeoCheckerController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1 versioning — all routes below are accessible as /api/v1/...
| The prefix is applied once here so every route name and URL gains /v1/.
| api.key middleware validates the shared frontend public/secret key pair.
| Non-versioned tracker paths (/api/track/*, /api/collect/*) are handled
| at the nginx level by rewriting them to /api/v1/... before reaching PHP.
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->middleware('api.key')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Public utility routes
    |--------------------------------------------------------------------------
    */
    Route::get('health', HealthController::class)->name('health');
    Route::get('theme', [ThemeController::class, 'show'])->name('theme');

    // SEO checker — auth required, rate-limited to prevent abuse
    Route::post('tools/seo-check', [SeoCheckerController::class, 'check'])
        ->name('tools.seo-check')
        ->middleware(['auth:sanctum', 'throttle:20,1']);

    // SEO site crawler — crawls all internal links (up to 20 pages)
    Route::post('tools/seo-crawl', [SeoCheckerController::class, 'crawl'])
        ->name('tools.seo-crawl')
        ->middleware(['auth:sanctum', 'throttle:5,1']);

    // Sitemap Creator — AI + analytics enriched sitemap generation
    Route::middleware(['auth:sanctum'])->prefix('tools/sitemap')->group(function () {
        Route::post('generate', [SitemapController::class, 'generate'])
            ->middleware('throttle:3,60')
            ->name('tools.sitemap.generate');
        Route::get('history', [SitemapController::class, 'history'])
            ->name('tools.sitemap.history');
        Route::get('{job}', [SitemapController::class, 'status'])
            ->name('tools.sitemap.status');
        Route::get('{job}/download', [SitemapController::class, 'download'])
            ->middleware('throttle:20,1')
            ->name('tools.sitemap.download');
    });

    // Paymob webhook — public endpoint (Paymob server-to-server, HMAC-verified)
    Route::post('billing/paymob/webhook', [PaymobController::class, 'webhook'])
        ->name('billing.paymob.webhook')
        ->withoutMiddleware('api.key')
        ->middleware('throttle:60,1');

    // A/B experiments config — public (the eye-ab.js tracker fetches running tests).
    Route::get('experiments/active', [ExperimentController::class, 'active'])
        ->name('experiments.active')->withoutMiddleware('api.key')->middleware('throttle:600,1');
    Route::options('experiments/active', CorsPreflightController::class)->withoutMiddleware('api.key');

    // Outreach unsubscribe (clicked from email) + Mailgun events — public.
    Route::get('outreach/unsubscribe/{token}', [OutreachController::class, 'unsubscribe'])
        ->name('outreach.unsubscribe')->withoutMiddleware('api.key');

    // Generic email unsubscribe (signed URL) — onboarding/check-up/campaigns.
    Route::get('email/unsubscribe', [\App\Http\Controllers\EmailController::class, 'unsubscribe'])
        ->name('email.unsubscribe')->withoutMiddleware('api.key');
    Route::post('outreach/mailgun-webhook', [OutreachController::class, 'mailgunWebhook'])
        ->name('outreach.mailgun')->withoutMiddleware('api.key')->middleware('throttle:120,1');

    // Display/billing currency from visitor IP (Egypt → EGP, else USD) — public,
    // used by both the marketing pricing page and the in-app billing page.
    Route::get('geo/currency', GeoCurrencyController::class)
        ->name('geo.currency')->withoutMiddleware('api.key')->middleware('throttle:120,1');

    // Public plans for the marketing pricing page (only is_public plans).
    Route::get('plans', [\App\Http\Controllers\PublicPlanController::class, 'index'])
        ->name('plans.public')->withoutMiddleware('api.key')->middleware('throttle:120,1');

    // Public blog (published posts) for the marketing site.
    Route::get('blog', [\App\Http\Controllers\PublicBlogController::class, 'index'])
        ->name('blog.index')->withoutMiddleware('api.key')->middleware('throttle:120,1');
    Route::get('blog/{slug}', [\App\Http\Controllers\PublicBlogController::class, 'show'])
        ->name('blog.show')->withoutMiddleware('api.key')->middleware('throttle:120,1');

    /*
    |--------------------------------------------------------------------------
    | Tracker endpoints (public, no auth)
    |--------------------------------------------------------------------------
    */
    // No Laravel throttle on ingestion: TikTok in-app browsers share IPs, so a
    // per-IP limit (429) was dropping legitimate campaign events. Protect at the
    // edge (nginx/Cloudflare) if abuse appears; per-token quota still applies.
    Route::post('track', TrackController::class)->name('track');
    Route::post('track/optout', OptoutController::class)->name('track.optout');
    Route::post('track/replay', ReplayIngestController::class)->name('track.replay');
    // CORS preflight for all tracker sub-paths
    Route::options('track', CorsPreflightController::class);
    Route::options('track/replay', CorsPreflightController::class);
    Route::options('track/optout', CorsPreflightController::class);
    // Alias: /collect/* → same controllers (supports trackers installed with data-api ending in /collect)
    Route::post('collect', TrackController::class);
    Route::post('collect/optout', OptoutController::class);
    Route::post('collect/replay', ReplayIngestController::class);
    Route::options('collect', CorsPreflightController::class);
    Route::options('collect/replay', CorsPreflightController::class);
    Route::options('collect/optout', CorsPreflightController::class);

    /*
    |--------------------------------------------------------------------------
    | Public auth routes
    |--------------------------------------------------------------------------
    */
    // Public contact form
    Route::post('contact', [\App\Http\Controllers\ContactController::class, 'store'])
        ->name('contact')
        ->middleware('throttle:10,1');

    // Public support chat for logged-out website visitors
    Route::prefix('support/guest')->name('support.guest.')->group(function () {
        Route::post('chat', [\App\Http\Controllers\GuestSupportChatController::class, 'start'])
            ->name('start')->middleware('throttle:5,1');
        Route::get('chat/{token}', [\App\Http\Controllers\GuestSupportChatController::class, 'show'])
            ->name('show')->middleware('throttle:120,1');
        Route::post('chat/{token}/messages', [\App\Http\Controllers\GuestSupportChatController::class, 'send'])
            ->name('send')->middleware('throttle:30,1');
    });

    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('register', RegisterController::class)->name('register');
        Route::post('login', LoginController::class)->name('login')->middleware('throttle:10,1');
        Route::get('google/redirect', [\App\Http\Controllers\Auth\GoogleController::class, 'redirect'])
            ->name('google.redirect')
            ->withoutMiddleware('api.key');
        Route::get('google/callback', [\App\Http\Controllers\Auth\GoogleController::class, 'callback'])
            ->name('google.callback')
            ->withoutMiddleware('api.key');
        Route::post('google/one-tap', [\App\Http\Controllers\Auth\GoogleController::class, 'oneTap'])
            ->name('google.one-tap')
            ->middleware('throttle:20,1');

        // 2FA challenge (pre-auth — no sanctum guard yet)
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

    // One-click unsubscribe (signed URL, no auth required). The controller
    // reads `type`/`user` from the query string (part of what the signature
    // covers) — there is no path token, despite the old route implying one.
    Route::get('notifications/unsubscribe', [NotificationPreferenceController::class, 'unsubscribe'])
        ->name('notifications.unsubscribe');

    // Public shared report
    Route::get('public/report/{token}', [SharedReportController::class, 'publicView'])
        ->name('public.report');

    // Phase 2 stub — public chatbot widget message endpoint
    Route::post('chatbot/widget/{token}/message', fn() => response()->json(['feature' => 'disabled', 'phase' => 2], 503))
        ->name('chatbot.widget.message')
        ->middleware('throttle:60,1');

    /*
    |--------------------------------------------------------------------------
    | Authenticated routes
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {

        // Experience feedback (asked once, post-signup)
        Route::get('feedback/status', [\App\Http\Controllers\FeedbackController::class, 'status'])->name('feedback.status');
        Route::post('feedback', [\App\Http\Controllers\FeedbackController::class, 'store'])->name('feedback.store');

        // Cross-site portfolio (user-scoped, spans all the user's domains)
        Route::prefix('portfolio')->name('portfolio.')->middleware('subscribed')->group(function () {
            Route::get('overview', [PortfolioController::class, 'overview'])->name('overview');
            Route::get('triage', [PortfolioController::class, 'triage'])->name('triage');
            Route::get('insights', [PortfolioController::class, 'insights'])->name('insights');
        });

        // Bulk-apply recommended alert rules to all the user's domains.
        Route::post('alert-rules/apply-defaults', [AlertRuleController::class, 'applyDefaults'])->name('alert-rules.apply-defaults');

        // Growth — leads CRM + compliant AI outreach (user-scoped).
        Route::prefix('leads')->name('leads.')->middleware('subscribed')->group(function () {
            Route::get('/', [LeadController::class, 'index'])->name('index');
            Route::post('/', [LeadController::class, 'store'])->name('store');
            Route::post('import', [LeadController::class, 'import'])->name('import');
            Route::post('warm', [LeadController::class, 'warm'])->name('warm');
            Route::put('{id}', [LeadController::class, 'update'])->name('update');
            Route::delete('{id}', [LeadController::class, 'destroy'])->name('destroy');
        });
        Route::post('outreach/draft', [OutreachController::class, 'draft'])->name('outreach.draft');
        Route::post('outreach/send', [OutreachController::class, 'send'])->name('outreach.send');

        // Auth session
        Route::get('auth/me', [ProfileController::class, 'show'])->name('auth.me');
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

        // Organization (agency/team) — NOT behind the `subscribed` gate: creating
        // an org is how a user gets the Agency plan, and team management must stay
        // reachable. Authorization is enforced per-action inside the controller.
        Route::prefix('organization')->name('organization.')->group(function () {
            Route::get('/', [OrganizationController::class, 'show'])->name('show');
            Route::post('/', [OrganizationController::class, 'store'])->name('store');
            Route::post('invitations', [OrganizationController::class, 'invite'])->name('invite');
            Route::post('invitations/{token}/accept', [OrganizationController::class, 'acceptInvite'])->name('invite.accept');
            Route::delete('invitations/{id}', [OrganizationController::class, 'cancelInvite'])->name('invite.cancel');
            Route::post('members/{userId}/domains', [OrganizationController::class, 'assignDomains'])->name('members.domains');
            Route::delete('members/{userId}', [OrganizationController::class, 'removeMember'])->name('members.remove');
        });

        // Plan-upgrade tickets (manual upgrade path) — NOT behind `subscribed`,
        // so a trial-expired user can still request an upgrade.
        Route::prefix('upgrade-tickets')->name('upgrade-tickets.')->group(function () {
            Route::get('/', [UpgradeTicketController::class, 'index'])->name('index');
            Route::post('/', [UpgradeTicketController::class, 'store'])->name('store')->middleware('throttle:20,1');
            Route::get('{id}', [UpgradeTicketController::class, 'show'])->name('show');
            Route::post('{id}/messages', [UpgradeTicketController::class, 'reply'])->name('reply')->middleware('throttle:60,1');
        });

        // Live customer-service chat (not behind `subscribed` — support must stay reachable)
        Route::prefix('support')->name('support.')->group(function () {
            Route::get('chat', [\App\Http\Controllers\SupportChatController::class, 'show'])->name('chat.show');
            Route::post('chat/messages', [\App\Http\Controllers\SupportChatController::class, 'send'])
                ->name('chat.send')->middleware('throttle:60,1');
        });

        // Billing
        Route::prefix('billing')->name('billing.')->group(function () {
            Route::get('/', [BillingController::class, 'show'])->name('show');
            Route::post('subscribe', [BillingController::class, 'subscribe'])->name('subscribe');
            Route::post('cancel', [BillingController::class, 'cancel'])->name('cancel');
            // Paymob — initiate payment (returns hosted iframe URL)
            Route::post('paymob/initiate', [PaymobController::class, 'initiate'])
                ->name('billing.paymob.initiate')
                ->middleware('throttle:10,1');
        });

        // GDPR
        Route::prefix('gdpr')->name('gdpr.')->group(function () {
            Route::delete('visitor', [GdprController::class, 'deleteVisitor'])->name('visitor.delete');
            Route::get('optout-status', [GdprController::class, 'optoutStatus'])->name('optout-status');
        });

        // Exports
        Route::prefix('exports')->name('exports.')->middleware('subscribed')->group(function () {
            Route::get('/', [ExportController::class, 'index'])->name('index');
            Route::post('/', [ExportController::class, 'store'])->name('store');
            Route::get('{id}', [ExportController::class, 'show'])->name('show');
            Route::get('{id}/download', [ExportController::class, 'download'])->name('download');
        });

        // Shared reports
        Route::prefix('shared-reports')->name('shared-reports.')->group(function () {
            Route::get('/', [SharedReportController::class, 'listAll'])->name('list');
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
        Route::prefix('domains')->name('domains.')->middleware('subscribed')->group(function () {
            Route::get('/', [DomainController::class, 'index'])->name('index');
            Route::post('/', [DomainController::class, 'store'])->name('store');
            Route::get('/{domain}', [DomainController::class, 'show'])->name('show');
            Route::patch('/{domain}', [DomainController::class, 'update'])->name('update');
            Route::delete('/{domain}', [DomainController::class, 'destroy'])->name('destroy');

            // Script token management
            Route::post('/{domain}/rotate-token', [DomainController::class, 'rotateToken'])->name('rotate-token');
            Route::post('/{domain}/verify-script', [DomainController::class, 'verifyScript'])->name('verify-script');
            Route::get('/{domain}/verify', [DomainController::class, 'verifyScript'])->name('verify');

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
                Route::post('custom-events/store', CustomEventsStoreController::class)->name('custom-events.store');
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
        Route::prefix('analytics/{domainId}')->name('analytics2.')->middleware('subscribed')->group(function () {
            Route::get('insights', [\App\Http\Controllers\Analytics\InsightController::class, 'index'])->name('insights');
            Route::post('insights/feedback', [\App\Http\Controllers\Analytics\InsightController::class, 'feedback'])->name('insights.feedback');
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
        Route::prefix('analytics/{domain}')->name('analyticsById.')->middleware('subscribed')->group(function () {
            Route::get('stats', StatsController::class)->name('stats');
            Route::get('devices', DevicesController::class)->name('devices');
            Route::get('referrers', ReferrersController::class)->name('referrers');
            Route::get('realtime', RealtimeController::class)->name('realtime');
            Route::get('pages', PagesController::class)->name('pages');
            Route::get('geo', GeoController::class)->name('geo');
            Route::get('custom-events', CustomEventsController::class)->name('custom-events');
            Route::get('funnels/{pipeline}', PipelineController::class)->name('funnels');
        });

        Route::prefix('analytics/{domainId}')->name('analyticsVisitors.')->middleware('subscribed')->group(function () {
            Route::get('overview', OverviewController::class)->name('overview');
            Route::get('compare', \App\Http\Controllers\Analytics\CompareController::class)->name('compare');
            Route::get('forms', \App\Http\Controllers\Analytics\FormsController::class)->name('forms');
            Route::post('forms/analyze', [\App\Http\Controllers\Analytics\FormsController::class, 'analyze'])->name('forms.analyze');
            Route::get('visitors', [VisitorController::class, 'index'])->name('visitors');
            Route::get('visitors/{visitorId}', [VisitorController::class, 'show'])->name('visitors.show');
            Route::get('campaigns', CampaignsController::class)->name('campaigns');
            Route::get('retention', RetentionController::class)->name('retention');
            Route::get('ltv', LtvController::class)->name('ltv');
            Route::get('seo-rank', [SeoRankController::class, 'index'])->name('seo-rank.index');
            Route::post('seo-rank/keywords', [SeoRankController::class, 'storeKeyword'])->name('seo-rank.keywords.store');
            Route::post('seo-rank/import', [SeoRankController::class, 'import'])->name('seo-rank.import');
            Route::delete('seo-rank/keywords/{id}', [SeoRankController::class, 'destroyKeyword'])->name('seo-rank.keywords.destroy');
            Route::get('experiments', [ExperimentController::class, 'index'])->name('experiments.index');
            Route::post('experiments', [ExperimentController::class, 'store'])->name('experiments.store');
            // GrowthBook-backed experiments (registered before the {id} routes).
            Route::get('experiments/growthbook/status', [ExperimentController::class, 'growthbookStatus'])->name('experiments.gb.status');
            Route::get('experiments/growthbook', [ExperimentController::class, 'growthbookList'])->name('experiments.gb.list');
            Route::get('experiments/growthbook/{id}/results', [ExperimentController::class, 'growthbookResults'])->name('experiments.gb.results');
            Route::get('experiments/convert/status', [ExperimentController::class, 'convertStatus'])->name('experiments.convert.status');
            Route::get('experiments/convert', [ExperimentController::class, 'convertList'])->name('experiments.convert.list');
            Route::get('experiments/convert/{id}/results', [ExperimentController::class, 'convertResults'])->name('experiments.convert.results');
            Route::get('experiments/{id}/results', [ExperimentController::class, 'results'])->name('experiments.results');
            Route::put('experiments/{id}', [ExperimentController::class, 'update'])->name('experiments.update');
            Route::delete('experiments/{id}', [ExperimentController::class, 'destroy'])->name('experiments.destroy');
            Route::get('ad-spend', [AdSpendController::class, 'index'])->name('ad-spend.index');
            Route::post('ad-spend', [AdSpendController::class, 'store'])->name('ad-spend.store');
            Route::post('ad-spend/import', [AdSpendController::class, 'import'])->name('ad-spend.import');
            Route::delete('ad-spend/{id}', [AdSpendController::class, 'destroy'])->name('ad-spend.destroy');
            Route::get('engaged-visitors', EngagedVisitorsController::class)->name('engaged-visitors');
            Route::get('summary', SummaryController::class)->name('summary');
            Route::get('bot-stats', BotStatsController::class)->name('bot-stats');
            Route::get('usage', \App\Http\Controllers\Analytics\UsageController::class)->name('usage');
        });

        /*
        |--------------------------------------------------------------------------
        | UX Intelligence
        |--------------------------------------------------------------------------
        */
        Route::prefix('ux/{domainId}')->name('ux.')->middleware('subscribed')->group(function () {
            Route::get('score', UxScoreController::class)->name('score');
            Route::get('issues', UxIssuesController::class)->name('issues');
            Route::get('heatmap', UxHeatmapController::class)->name('heatmap');
            Route::get('heatmap/screenshot', UxHeatmapScreenshotController::class)->name('heatmap.screenshot');
            Route::get('errors', UxErrorsController::class)->name('errors');
            Route::get('scroll-depth', UxScrollDepthController::class)->name('scroll-depth');
            Route::get('web-vitals', UxWebVitalsController::class)->name('web-vitals');
            Route::get('performance', UxPerformanceController::class)->name('performance');
        });

        /*
        |--------------------------------------------------------------------------
        | AI
        |--------------------------------------------------------------------------
        */
        Route::prefix('ai/{domainId}')->name('ai.')->middleware('subscribed')->group(function () {
            Route::get('segments', [AiController::class, 'segments'])->name('segments');
            Route::get('suggestions', [AiController::class, 'suggestions'])->name('suggestions');
            Route::get('report', [AiController::class, 'report'])->name('report');
            Route::get('reports', [AiController::class, 'reports'])->name('reports');
            Route::post('analyze', [AiController::class, 'analyze'])->name('analyze');
            Route::get('quota', [AiController::class, 'quotaStatus'])->name('quota');
        });
        Route::patch('ai/suggestions/{id}/dismiss', [AiController::class, 'dismissSuggestion'])
            ->name('ai.suggestions.dismiss');
        Route::get('ai/token-packs', [AiController::class, 'tokenPacks'])->name('ai.token-packs');
        Route::post('ai/tokens/purchase', [AiController::class, 'purchaseTokens'])->name('ai.tokens.purchase');

        /*
        |--------------------------------------------------------------------------
        | Session Replay (enabled via data-replay="true" on the tracker snippet)
        |--------------------------------------------------------------------------
        */
        Route::prefix('replay/{domainId}')->name('replay.')->middleware('subscribed')->group(function () {
            Route::get('sessions', [ReplayController::class, 'sessions'])->name('sessions');
            Route::get('funnel-drops', [ReplayController::class, 'funnelDrops'])->name('funnel-drops');
            Route::get('sessions/{sessionId}', [ReplayController::class, 'events'])->name('events');
            Route::get('sessions/{sessionId}/markers', [ReplayController::class, 'markers'])->name('markers');
            Route::delete('sessions/{sessionId}', [ReplayController::class, 'destroy'])->name('destroy');
        });

        /*
        |--------------------------------------------------------------------------
        | AI Assistant Chatbot
        |--------------------------------------------------------------------------
        */
        Route::prefix('chatbot/{domainId}')->name('chatbot.')->middleware('subscribed')->group(function () {
            Route::get('sessions', [ChatbotController::class, 'sessions'])->name('sessions');
            Route::post('sessions', [ChatbotController::class, 'startSession'])->name('sessions.start');
            Route::get('sessions/{sessionId}', [ChatbotController::class, 'showSession'])->name('sessions.show');
            Route::post('sessions/{sessionId}/message', [ChatbotController::class, 'sendMessage'])->name('sessions.message');
            Route::delete('sessions/{sessionId}', [ChatbotController::class, 'deleteSession'])->name('sessions.delete');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Superadmin routes
    |--------------------------------------------------------------------------
    */
    Route::middleware(['auth:sanctum', 'superadmin'])->prefix('admin')->name('admin.')->group(function () {

        Route::get('stats', AdminStatsController::class)->name('stats');

        // Experience feedback results
        Route::get('feedback', [\App\Http\Controllers\Admin\AdminFeedbackController::class, 'index'])->name('feedback');

        // Live customer-service chats
        Route::prefix('support-chats')->name('support-chats.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminSupportChatController::class, 'index'])->name('index');
            Route::get('{id}', [\App\Http\Controllers\Admin\AdminSupportChatController::class, 'show'])->name('show');
            Route::post('{id}/messages', [\App\Http\Controllers\Admin\AdminSupportChatController::class, 'reply'])->name('reply');
            Route::post('{id}/close', [\App\Http\Controllers\Admin\AdminSupportChatController::class, 'close'])->name('close');
        });

        // Contact-form messages
        Route::prefix('contact-messages')->name('contact-messages.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminContactController::class, 'index'])->name('index');
            Route::post('{id}/read', [\App\Http\Controllers\Admin\AdminContactController::class, 'markRead'])->name('read');
            Route::delete('{id}', [\App\Http\Controllers\Admin\AdminContactController::class, 'destroy'])->name('destroy');
        });

        // Email campaigns (broadcast to a user segment)
        Route::get('email/audiences', [\App\Http\Controllers\Admin\AdminEmailController::class, 'audiences'])->name('email.audiences');
        Route::post('email/send', [\App\Http\Controllers\Admin\AdminEmailController::class, 'send'])->name('email.send');

        // Blog CMS
        Route::prefix('blog')->name('blog.')->group(function () {
            Route::get('/', [\App\Http\Controllers\Admin\AdminBlogController::class, 'index'])->name('index');
            Route::post('/', [\App\Http\Controllers\Admin\AdminBlogController::class, 'store'])->name('store');
            Route::get('{id}', [\App\Http\Controllers\Admin\AdminBlogController::class, 'show'])->name('show');
            Route::post('{id}', [\App\Http\Controllers\Admin\AdminBlogController::class, 'update'])->name('update');
            Route::delete('{id}', [\App\Http\Controllers\Admin\AdminBlogController::class, 'destroy'])->name('destroy');
        });

        // Users
        Route::prefix('users')->name('users.')->group(function () {
            Route::get('/', [AdminUserController::class, 'index'])->name('index');
            Route::post('/', [AdminUserController::class, 'store'])->name('store');
            Route::get('{id}', [AdminUserController::class, 'show'])->name('show');
            Route::patch('{id}', [AdminUserController::class, 'update'])->name('update');
            Route::post('{id}/block', [AdminUserController::class, 'block'])->name('block');
            Route::post('{id}/unblock', [AdminUserController::class, 'unblock'])->name('unblock');
            Route::post('{id}/impersonate', [AdminUserController::class, 'impersonate'])->name('impersonate');
            Route::post('{id}/verify-email', [AdminUserController::class, 'verifyEmail'])->name('verify-email');
            Route::post('{id}/disable-2fa', [AdminUserController::class, 'disable2fa'])->name('disable-2fa');
            Route::post('{id}/toggle-admin', [AdminUserController::class, 'toggleAdmin'])->name('toggle-admin');
            Route::post('{id}/grant-tokens', [AdminUserController::class, 'grantTokens'])->name('grant-tokens');
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
            Route::post('paymob/test', [PaymobController::class, 'test'])->name('paymob.test');
            Route::put('{id}', [AdminPaymentMethodController::class, 'update'])->name('update');
            Route::delete('{id}', [AdminPaymentMethodController::class, 'destroy'])->name('destroy');
        });

        // Plan-upgrade tickets (admin: reply + apply plan)
        Route::prefix('upgrade-tickets')->name('upgrade-tickets.')->group(function () {
            Route::get('/', [AdminUpgradeTicketController::class, 'index'])->name('index');
            Route::get('{id}', [AdminUpgradeTicketController::class, 'show'])->name('show');
            Route::post('{id}/messages', [AdminUpgradeTicketController::class, 'reply'])->name('reply');
            Route::post('{id}/resolve', [AdminUpgradeTicketController::class, 'resolve'])->name('resolve');
        });

        // Subscriptions
        Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
            Route::get('/', [AdminSubscriptionController::class, 'index'])->name('index');
            Route::get('{id}', [AdminSubscriptionController::class, 'show'])->name('show');
            Route::post('{id}/upgrade', [AdminSubscriptionController::class, 'upgrade'])->name('upgrade');
            Route::post('{id}/cancel', [AdminSubscriptionController::class, 'cancel'])->name('cancel');
            Route::post('{id}/pause', [AdminSubscriptionController::class, 'pause'])->name('pause');
            Route::post('{id}/resume', [AdminSubscriptionController::class, 'resume'])->name('resume');
            Route::post('{id}/extend', [AdminSubscriptionController::class, 'extend'])->name('extend');
        });

        // Payments
        Route::prefix('payments')->name('payments.')->group(function () {
            Route::get('/', [AdminPaymentController::class, 'index'])->name('index');
            Route::get('{id}', [AdminPaymentController::class, 'show'])->name('show');
            Route::post('{id}/approve', [AdminPaymentController::class, 'approve'])->name('approve');
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

}); // end Route::prefix('v1')
