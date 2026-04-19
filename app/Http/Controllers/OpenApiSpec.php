<?php

namespace App\Http\Controllers;

/**
 * ============================================================
 *  Global API Info & Security
 * ============================================================
 *
 * @OA\Info(
 *   title="Eye Analytics API",
 *   version="1.0.0",
 *   description="REST API for the Eye Analytics platform. All endpoints are prefixed with `/api/v1`.
 *
 * ## Authentication
 * Protected endpoints require a Sanctum bearer token obtained from `POST /api/v1/auth/login`.
 * Pass it as: `Authorization: Bearer {token}`
 *
 * ## App-level API Keys
 * Every request must also carry the shared frontend key pair:
 * - `X-Public-Key: pk_xxxx`
 * - `X-Secret-Key: sk_xxxx`
 * Generate these once with `php artisan app:generate-api-keys`.",
 *   @OA\Contact(email="support@eye-analytics.com")
 * )
 *
 * @OA\Server(url="/", description="Current environment")
 *
 * @OA\SecurityScheme(
 *   securityScheme="sanctum",
 *   type="http",
 *   scheme="bearer",
 *   bearerFormat="Token",
 *   description="Bearer token — obtain via POST /api/v1/auth/login"
 * )
 *
 * @OA\Tag(name="Health",                 description="Service health check")
 * @OA\Tag(name="Auth",                   description="Register, login, logout, password reset, 2FA")
 * @OA\Tag(name="Profile",                description="User profile, password, sessions, API key")
 * @OA\Tag(name="Onboarding",             description="Onboarding step tracking")
 * @OA\Tag(name="Notifications",          description="In-app notifications")
 * @OA\Tag(name="Notification Preferences", description="Per-type notification preferences")
 * @OA\Tag(name="Billing",                description="Subscriptions, plans, payment history")
 * @OA\Tag(name="GDPR",                   description="Visitor data deletion and opt-out")
 * @OA\Tag(name="Exports",                description="Data export jobs (CSV / Excel)")
 * @OA\Tag(name="Shared Reports",         description="Publicly shareable analytics report links")
 * @OA\Tag(name="Saved Views",            description="Saved analytics filter views")
 * @OA\Tag(name="Domains",                description="Website / domain management")
 * @OA\Tag(name="Analytics",              description="Stats, pages, referrers, devices, geo, funnels")
 * @OA\Tag(name="Visitors",               description="Visitor identity and company enrichment")
 * @OA\Tag(name="Realtime",               description="Live visitor data")
 * @OA\Tag(name="Webhooks",               description="Domain webhook management")
 * @OA\Tag(name="Alert Rules",            description="Threshold-based alert rules")
 * @OA\Tag(name="Pipelines",              description="Conversion pipeline / funnel management")
 * @OA\Tag(name="UX Intelligence",        description="UX scores, issues, heatmaps, JS errors")
 * @OA\Tag(name="AI",                     description="AI segments, suggestions, analysis")
 * @OA\Tag(name="Session Replay",         description="Session replay (Phase 2 — disabled)")
 * @OA\Tag(name="Tracker",                description="Public tracking ingestion endpoints")
 * @OA\Tag(name="Admin",                  description="Superadmin — users, plans, billing, audit")
 *
 * ============================================================
 *  Health
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/theme",
 *   summary="Get the active white-label theme settings",
 *   tags={"Health"},
 *   @OA\Response(response=200, description="Theme object")
 * )
 *
 * ============================================================
 *  Tracker (public, no auth)
 * ============================================================
 *
 * @OA\Post(
 *   path="/api/v1/track",
 *   summary="Ingest a tracking event from the tracker script",
 *   tags={"Tracker"},
 *   @OA\RequestBody(required=true, @OA\JsonContent(@OA\Property(property="payload", type="object"))),
 *   @OA\Response(response=200, description="Event accepted")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/track/optout",
 *   summary="Opt a visitor out of tracking",
 *   tags={"Tracker"},
 *   @OA\Response(response=200, description="Opt-out recorded")
 * )
 *
 * ============================================================
 *  Auth — public
 * ============================================================
 *
 * @OA\Post(
 *   path="/api/v1/auth/two-factor/verify",
 *   summary="Complete a 2FA login challenge",
 *   tags={"Auth"},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"challenge","code"},
 *       @OA\Property(property="challenge", type="string"),
 *       @OA\Property(property="code",      type="string", example="123456")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Authenticated — returns token"),
 *   @OA\Response(response=422, description="Invalid or expired code")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/auth/forgot-password",
 *   summary="Send a password reset link",
 *   tags={"Auth"},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(required={"email"}, @OA\Property(property="email", type="string", format="email"))
 *   ),
 *   @OA\Response(response=200, description="Reset link sent")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/auth/reset-password",
 *   summary="Reset password using a token from the email link",
 *   tags={"Auth"},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"token","email","password","password_confirmation"},
 *       @OA\Property(property="token",                 type="string"),
 *       @OA\Property(property="email",                 type="string", format="email"),
 *       @OA\Property(property="password",              type="string", format="password"),
 *       @OA\Property(property="password_confirmation", type="string", format="password")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Password reset")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/auth/email/verify/{id}/{hash}",
 *   summary="Verify email address via signed link",
 *   tags={"Auth"},
 *   @OA\Parameter(name="id",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="hash", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Email verified")
 * )
 *
 * ============================================================
 *  Auth — authenticated
 * ============================================================
 *
 * @OA\Post(
 *   path="/api/v1/auth/logout",
 *   summary="Revoke the current access token",
 *   tags={"Auth"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Logged out")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/auth/email/resend-verification",
 *   summary="Resend the email verification link",
 *   tags={"Auth"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Verification email sent")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/auth/two-factor/setup",
 *   summary="Generate a new TOTP secret and QR code",
 *   tags={"Auth"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="TOTP secret and QR code PNG data URI")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/auth/two-factor/enable",
 *   summary="Confirm TOTP setup and enable 2FA",
 *   tags={"Auth"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(required=true, @OA\JsonContent(required={"code"}, @OA\Property(property="code", type="string"))),
 *   @OA\Response(response=200, description="2FA enabled — backup codes returned")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/auth/two-factor/disable",
 *   summary="Disable 2FA",
 *   tags={"Auth"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="2FA disabled")
 * )
 *
 * ============================================================
 *  Profile
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/profile",
 *   summary="Get current user profile",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="User object")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/profile",
 *   summary="Update profile (name, locale, timezone, appearance)",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     @OA\JsonContent(
 *       @OA\Property(property="name",       type="string"),
 *       @OA\Property(property="locale",     type="string"),
 *       @OA\Property(property="timezone",   type="string"),
 *       @OA\Property(property="appearance", type="string", enum={"light","dark","system"})
 *     )
 *   ),
 *   @OA\Response(response=200, description="Profile updated")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/profile/password",
 *   summary="Change password (revokes all other sessions)",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"current_password","password","password_confirmation"},
 *       @OA\Property(property="current_password",      type="string", format="password"),
 *       @OA\Property(property="password",              type="string", format="password"),
 *       @OA\Property(property="password_confirmation", type="string", format="password")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Password changed — new token returned")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/profile/api-key/regenerate",
 *   summary="Regenerate the user's legacy API key",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="New API key")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/profile/api-key",
 *   summary="Get the user's current legacy API key",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="API key value")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/profile/sessions",
 *   summary="List all active Sanctum sessions",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="List of tokens")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/profile/sessions/{tokenId}",
 *   summary="Revoke a specific session token",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="tokenId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Session revoked")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/profile",
 *   summary="Delete account permanently",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Account deleted")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/profile/preferences",
 *   summary="Update notification / display preferences",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Preferences updated")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/profile/two-factor/status",
 *   summary="Get 2FA status for the current user",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="2FA status")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/profile/two-factor/enable",
 *   summary="Enable 2FA (profile context)",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="2FA enabled")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/profile/two-factor/confirm",
 *   summary="Confirm and activate 2FA (profile context)",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="2FA confirmed")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/profile/two-factor/disable",
 *   summary="Disable 2FA (profile context)",
 *   tags={"Profile"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="2FA disabled")
 * )
 *
 * ============================================================
 *  Onboarding
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/onboarding",
 *   summary="Get onboarding step completion status",
 *   tags={"Onboarding"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Step status map")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/onboarding/{step}",
 *   summary="Mark an onboarding step as complete",
 *   tags={"Onboarding"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="step", in="path", required=true,
 *     @OA\Schema(type="string", enum={"domain_added","script_installed","first_event_received","funnel_created"})
 *   ),
 *   @OA\Response(response=200, description="Step marked complete")
 * )
 *
 * ============================================================
 *  Notifications
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/notifications",
 *   summary="List in-app notifications",
 *   tags={"Notifications"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="channel", in="query", @OA\Schema(type="string", enum={"email","in_app","both"})),
 *   @OA\Response(response=200, description="Notifications list with unread count")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/notifications/{id}/read",
 *   summary="Mark a notification as read",
 *   tags={"Notifications"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Marked as read")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/notifications/read-all",
 *   summary="Mark all notifications as read",
 *   tags={"Notifications"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="All marked as read")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/notifications/{id}",
 *   summary="Delete a notification",
 *   tags={"Notifications"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Deleted")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/notifications",
 *   summary="Clear all read notifications",
 *   tags={"Notifications"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Read notifications cleared")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/notifications/unsubscribe/{token}",
 *   summary="One-click email unsubscribe via signed URL",
 *   tags={"Notifications"},
 *   @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Parameter(name="type",  in="query", required=true, @OA\Schema(type="string")),
 *   @OA\Parameter(name="user",  in="query", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Unsubscribed")
 * )
 *
 * ============================================================
 *  Notification Preferences
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/notification-preferences",
 *   summary="Get notification preferences",
 *   tags={"Notification Preferences"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="List of per-type preferences")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/notification-preferences",
 *   summary="Update notification preferences",
 *   tags={"Notification Preferences"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       type="array",
 *       @OA\Items(
 *         @OA\Property(property="type",   type="string"),
 *         @OA\Property(property="in_app", type="boolean"),
 *         @OA\Property(property="email",  type="boolean")
 *       )
 *     )
 *   ),
 *   @OA\Response(response=200, description="Preferences updated")
 * )
 *
 * ============================================================
 *  Billing
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/billing",
 *   summary="Get subscription, usage, limits, payments and available plans",
 *   tags={"Billing"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Billing overview")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/billing/subscribe",
 *   summary="Subscribe to a plan",
 *   tags={"Billing"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"plan_id"},
 *       @OA\Property(property="plan_id", type="integer")
 *     )
 *   ),
 *   @OA\Response(response=200, description="Subscribed")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/billing/cancel",
 *   summary="Cancel the current subscription",
 *   tags={"Billing"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Subscription cancelled")
 * )
 *
 * ============================================================
 *  GDPR
 * ============================================================
 *
 * @OA\Delete(
 *   path="/api/v1/gdpr/visitor",
 *   summary="Queue deletion of all data for a visitor",
 *   tags={"GDPR"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"domain_id","visitor_id"},
 *       @OA\Property(property="domain_id",  type="integer"),
 *       @OA\Property(property="visitor_id", type="string")
 *     )
 *   ),
 *   @OA\Response(response=202, description="Deletion queued")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/gdpr/optout-status",
 *   summary="Check if a visitor has opted out of tracking",
 *   tags={"GDPR"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain_id",  in="query", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="visitor_id", in="query", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Opt-out status")
 * )
 *
 * ============================================================
 *  Exports
 * ============================================================
 *
 * @OA\Post(
 *   path="/api/v1/exports",
 *   summary="Queue a data export job",
 *   tags={"Exports"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"domain_id","type","format"},
 *       @OA\Property(property="domain_id", type="integer"),
 *       @OA\Property(property="type",      type="string", enum={"visitors","events","funnel","ai"}),
 *       @OA\Property(property="format",    type="string", enum={"csv","excel"}),
 *       @OA\Property(property="filters",   type="object", nullable=true)
 *     )
 *   ),
 *   @OA\Response(response=202, description="Export job queued")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/exports/{id}",
 *   summary="Get export job status",
 *   tags={"Exports"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Export job details")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/exports/{id}/download",
 *   summary="Download the completed export file",
 *   tags={"Exports"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="File download")
 * )
 *
 * ============================================================
 *  Shared Reports
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/shared-reports/{domainId}",
 *   summary="List shared report links for a domain",
 *   tags={"Shared Reports"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Shared reports list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/shared-reports",
 *   summary="Create a shared report link",
 *   tags={"Shared Reports"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"domain_id","label"},
 *       @OA\Property(property="domain_id",     type="integer"),
 *       @OA\Property(property="label",         type="string"),
 *       @OA\Property(property="allowed_pages", type="array", @OA\Items(type="string"), nullable=true),
 *       @OA\Property(property="expires_at",    type="string", format="date-time", nullable=true)
 *     )
 *   ),
 *   @OA\Response(response=201, description="Shared report created")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/shared-reports/{id}",
 *   summary="Delete a shared report link",
 *   tags={"Shared Reports"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Deleted")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/public/report/{token}",
 *   summary="View a public shared report (no auth required)",
 *   tags={"Shared Reports"},
 *   @OA\Parameter(name="token", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Report data")
 * )
 *
 * ============================================================
 *  Saved Views
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/saved-views",
 *   summary="List saved views for a domain",
 *   tags={"Saved Views"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Saved views list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/saved-views",
 *   summary="Create a saved view",
 *   tags={"Saved Views"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=201, description="Saved view created")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/saved-views/{view}",
 *   summary="Delete a saved view",
 *   tags={"Saved Views"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="view",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Deleted")
 * )
 *
 * ============================================================
 *  Domains
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains",
 *   summary="List all domains for the authenticated user",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Domain list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains",
 *   summary="Add a new domain",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"domain"},
 *       @OA\Property(property="domain",   type="string", example="example.com"),
 *       @OA\Property(property="settings", type="object", nullable=true)
 *     )
 *   ),
 *   @OA\Response(response=201, description="Domain created"),
 *   @OA\Response(response=422, description="Duplicate domain or plan limit reached")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}",
 *   summary="Get a domain",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Domain resource")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/domains/{domain}",
 *   summary="Update domain settings",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Domain updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}",
 *   summary="Delete a domain and all its data",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Domain deleted")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/rotate-token",
 *   summary="Rotate the tracker script token",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="New token returned")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/verify-script",
 *   summary="Check if the tracker script is installed on the domain",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Script detection result")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/snippet",
 *   summary="Get the embeddable tracker script snippet",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Script snippet HTML")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/exclusions",
 *   summary="List IP/path exclusions for a domain",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Exclusions list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/exclusions",
 *   summary="Add an exclusion rule",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=201, description="Exclusion added")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/exclusions/{exclusion}",
 *   summary="Remove an exclusion rule",
 *   tags={"Domains"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",    in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="exclusion", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Exclusion removed")
 * )
 *
 * ============================================================
 *  Analytics (domain-scoped)
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/stats",
 *   summary="Get aggregated stats (sessions, pageviews, bounce rate, duration)",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",      in="path",  required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",       in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",         in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="granularity", in="query", @OA\Schema(type="string", enum={"hour","day","week","month"})),
 *   @OA\Response(response=200, description="Stats data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/pages",
 *   summary="Top pages by pageviews",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",  in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",    in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Pages data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/referrers",
 *   summary="Top referrer sources",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",  in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",    in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Referrers data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/devices",
 *   summary="Breakdown by device, browser, OS",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",  in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",    in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Devices data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/geo",
 *   summary="Visitors by country and city",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",  in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",    in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Geo data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/custom-events",
 *   summary="Custom event breakdown",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="start",  in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Parameter(name="end",    in="query", required=true, @OA\Schema(type="string", format="date")),
 *   @OA\Response(response=200, description="Custom events data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/realtime",
 *   summary="Active visitors right now",
 *   tags={"Realtime"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Active visitor count and timestamp")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/analytics/pipelines/{pipeline}/funnel",
 *   summary="Funnel conversion data for a pipeline",
 *   tags={"Analytics"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Funnel steps with conversion rates")
 * )
 *
 * ============================================================
 *  Visitors & Company Enrichment
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/overview",
 *   summary="Visitor overview (sessions, new vs returning)",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Visitor overview")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/visitors",
 *   summary="Paginated visitor list with identity info",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Visitors list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/visitors/{visitorId}",
 *   summary="Get a single visitor's session history",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="visitorId", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Visitor detail")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/identities",
 *   summary="List identified visitors (CRM-style)",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Identities list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/identities/{externalId}",
 *   summary="Get a single identified visitor",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="externalId", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Identity detail")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/companies",
 *   summary="List enriched company records",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Companies list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/analytics/{domainId}/companies/{companyDomain}",
 *   summary="Get enrichment data for a company",
 *   tags={"Visitors"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId",      in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="companyDomain", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Company enrichment detail")
 * )
 *
 * ============================================================
 *  Webhooks
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/webhooks",
 *   summary="List webhooks for a domain",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Webhooks list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/webhooks",
 *   summary="Create a webhook",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\JsonContent(
 *       required={"url","events"},
 *       @OA\Property(property="url",       type="string", format="uri"),
 *       @OA\Property(property="events",    type="array", @OA\Items(type="string")),
 *       @OA\Property(property="secret",    type="string", nullable=true),
 *       @OA\Property(property="is_active", type="boolean")
 *     )
 *   ),
 *   @OA\Response(response=201, description="Webhook created")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/domains/{domain}/webhooks/{webhook}",
 *   summary="Update a webhook",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="webhook", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Webhook updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/webhooks/{webhook}",
 *   summary="Delete a webhook",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="webhook", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Webhook deleted")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/webhooks/{webhook}/test",
 *   summary="Send a test event to a webhook",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="webhook", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Test delivery result")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/webhooks/{webhook}/logs",
 *   summary="Get recent delivery logs for a webhook",
 *   tags={"Webhooks"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="webhook", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Delivery logs")
 * )
 *
 * ============================================================
 *  Alert Rules
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/alert-rules",
 *   summary="List alert rules for a domain",
 *   tags={"Alert Rules"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Alert rules list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/alert-rules",
 *   summary="Create an alert rule",
 *   tags={"Alert Rules"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=201, description="Alert rule created")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/domains/{domain}/alert-rules/{rule}",
 *   summary="Update an alert rule",
 *   tags={"Alert Rules"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="rule",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Alert rule updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/alert-rules/{rule}",
 *   summary="Delete an alert rule",
 *   tags={"Alert Rules"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="rule",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Alert rule deleted")
 * )
 *
 * ============================================================
 *  Pipelines (Conversion Funnels)
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/domains/{domain}/pipelines",
 *   summary="List pipelines for a domain",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Pipelines list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/pipelines",
 *   summary="Create a pipeline",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=201, description="Pipeline created")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/domains/{domain}/pipelines/{pipeline}",
 *   summary="Update a pipeline",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Pipeline updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/pipelines/{pipeline}",
 *   summary="Delete a pipeline",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Pipeline deleted")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/pipelines/{pipeline}/steps",
 *   summary="Add a step to a pipeline",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=201, description="Step added")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/domains/{domain}/pipelines/{pipeline}/steps/{step}",
 *   summary="Remove a step from a pipeline",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="step",     in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Step removed")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/domains/{domain}/pipelines/{pipeline}/reorder",
 *   summary="Reorder pipeline steps",
 *   tags={"Pipelines"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domain",   in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="pipeline", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Steps reordered")
 * )
 *
 * ============================================================
 *  UX Intelligence
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/ux/{domainId}/score",
 *   summary="Get the UX score for a domain",
 *   tags={"UX Intelligence"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="UX score")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/ux/{domainId}/issues",
 *   summary="List UX issues detected for a domain",
 *   tags={"UX Intelligence"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="UX issues list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/ux/{domainId}/heatmap",
 *   summary="Get heatmap click/scroll data",
 *   tags={"UX Intelligence"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="page",     in="query", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Heatmap data")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/ux/{domainId}/errors",
 *   summary="Get JS error log for a domain",
 *   tags={"UX Intelligence"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="JS errors list")
 * )
 *
 * ============================================================
 *  AI
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/ai/{domainId}/segments",
 *   summary="List AI-generated audience segments",
 *   tags={"AI"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Segments list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/ai/{domainId}/suggestions",
 *   summary="List active AI suggestions",
 *   tags={"AI"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Suggestions list (high → low priority)")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/ai/{domainId}/analyze",
 *   summary="Trigger an AI analysis for a domain",
 *   tags={"AI"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=202, description="Analysis queued"),
 *   @OA\Response(response=429, description="Monthly quota reached")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/ai/{domainId}/quota",
 *   summary="Get AI analysis quota usage for the current month",
 *   tags={"AI"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Quota usage and limit")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/ai/suggestions/{id}/dismiss",
 *   summary="Dismiss an AI suggestion",
 *   tags={"AI"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Suggestion dismissed")
 * )
 *
 * ============================================================
 *  Session Replay (Phase 2 — returns 503)
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/replay/{domainId}/sessions",
 *   summary="[Phase 2] List session replays",
 *   tags={"Session Replay"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=503, description="Feature not yet available")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/replay/{domainId}/sessions/{sessionId}",
 *   summary="[Phase 2] Get a session replay",
 *   tags={"Session Replay"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="domainId",  in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Parameter(name="sessionId", in="path", required=true, @OA\Schema(type="string")),
 *   @OA\Response(response=503, description="Feature not yet available")
 * )
 *
 * ============================================================
 *  Admin — Users
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/stats",
 *   summary="Platform-wide statistics",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Admin stats")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/users",
 *   summary="List all users (paginated)",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
 *   @OA\Parameter(name="status", in="query", @OA\Schema(type="string", enum={"active","blocked","suspended"})),
 *   @OA\Parameter(name="plan",   in="query", @OA\Schema(type="string")),
 *   @OA\Response(response=200, description="Paginated users")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/users/{id}",
 *   summary="Get a user with subscription and domains",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="User detail")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/admin/users/{id}",
 *   summary="Update a user (name, email, role, status)",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="User updated")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/users/{id}/block",
 *   summary="Block a user",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="User blocked")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/users/{id}/unblock",
 *   summary="Unblock a user",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="User unblocked")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/users/{id}/impersonate",
 *   summary="Impersonate a user (get their token)",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Impersonation token")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/admin/impersonate",
 *   summary="End impersonation session",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Impersonation ended")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/users/{id}/disable-2fa",
 *   summary="Force-disable 2FA for a user",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="2FA disabled")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/admin/users/{id}",
 *   summary="Permanently delete a user",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="User deleted")
 * )
 *
 * ============================================================
 *  Admin — Plans
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/plans",
 *   summary="List all plans",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Plans list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/plans",
 *   summary="Create a plan",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=201, description="Plan created")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/plans/{id}",
 *   summary="Get a plan",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Plan detail")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/admin/plans/{id}",
 *   summary="Update a plan",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Plan updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/admin/plans/{id}",
 *   summary="Delete a plan",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Plan deleted")
 * )
 *
 * @OA\Patch(
 *   path="/api/v1/admin/plans/{id}/toggle-visibility",
 *   summary="Toggle plan public visibility",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Visibility toggled")
 * )
 *
 * ============================================================
 *  Admin — Payment Methods
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/payment-methods",
 *   summary="List available payment methods",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Payment methods list")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/payment-methods",
 *   summary="Create a payment method",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=201, description="Payment method created")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/admin/payment-methods/{id}",
 *   summary="Update a payment method",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Payment method updated")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/admin/payment-methods/{id}",
 *   summary="Delete a payment method",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Payment method deleted")
 * )
 *
 * ============================================================
 *  Admin — Subscriptions
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/subscriptions",
 *   summary="List all subscriptions",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Subscriptions list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/subscriptions/{id}",
 *   summary="Get a subscription",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Subscription detail")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/subscriptions/{id}/upgrade",
 *   summary="Upgrade a subscription to a different plan",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Subscription upgraded")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/subscriptions/{id}/cancel",
 *   summary="Cancel a subscription",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Subscription cancelled")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/subscriptions/{id}/pause",
 *   summary="Pause a subscription",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Subscription paused")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/subscriptions/{id}/resume",
 *   summary="Resume a paused subscription",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Subscription resumed")
 * )
 *
 * ============================================================
 *  Admin — Payments
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/payments",
 *   summary="List all payments",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Payments list")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/payments/{id}",
 *   summary="Get a payment",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Payment detail")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/payments/{id}/refund",
 *   summary="Refund a payment",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Payment refunded")
 * )
 *
 * ============================================================
 *  Admin — Domains, Theme, Audit Log
 * ============================================================
 *
 * @OA\Get(
 *   path="/api/v1/admin/domains",
 *   summary="List all domains across all users",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Domains list")
 * )
 *
 * @OA\Delete(
 *   path="/api/v1/admin/domains/{id}",
 *   summary="Force-delete a domain",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Parameter(name="id", in="path", required=true, @OA\Schema(type="integer")),
 *   @OA\Response(response=200, description="Domain deleted")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/theme",
 *   summary="Get white-label theme settings",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Theme settings")
 * )
 *
 * @OA\Put(
 *   path="/api/v1/admin/theme",
 *   summary="Update theme settings",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Theme updated")
 * )
 *
 * @OA\Post(
 *   path="/api/v1/admin/theme/logo",
 *   summary="Upload a new logo",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\RequestBody(
 *     required=true,
 *     @OA\MediaType(mediaType="multipart/form-data",
 *       @OA\Schema(@OA\Property(property="logo", type="string", format="binary"))
 *     )
 *   ),
 *   @OA\Response(response=200, description="Logo uploaded")
 * )
 *
 * @OA\Get(
 *   path="/api/v1/admin/audit-log",
 *   summary="Get the admin audit log",
 *   tags={"Admin"},
 *   security={{"sanctum":{}}},
 *   @OA\Response(response=200, description="Audit log entries")
 * )
 */
class OpenApiSpec
{
    // This class holds only OpenAPI annotations — no runtime code.
}
