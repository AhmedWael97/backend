# Plan: EYE — AI Visitor Tracking SaaS

## Stack
- Backend: Laravel 12 (PHP 8.3)
- Frontend: Next.js 14 (App Router, React)
- **UI:** Tailwind CSS v3 (utility-first styling; `darkMode: 'class'`; `rtl:` / `ltr:` directional variants) + shadcn/ui component library
- **i18n:** `next-intl` — Arabic (`ar`, RTL) and English (`en`, LTR); locale persisted per user; messages in `frontend/messages/{locale}.json`
- Tracking Script: Vanilla JS (minified)
- DB: PostgreSQL (relational) + ClickHouse (events) + Redis (cache/queues)
- AI: Anthropic Claude API
- Realtime: Laravel Reverb (WebSockets)
- Queue monitoring: Laravel Horizon
- Error monitoring: Sentry (backend + frontend)
- Connection pooling: PgBouncer (PostgreSQL)
- **Transactional email: Laravel Mail + configurable driver** (Mailgun / Postmark / Amazon SES / SMTP — set via `MAIL_MAILER` env var; Mailgun recommended for production)
- **Testing:** PHPUnit/Pest (backend feature + unit tests), Jest (tracker unit tests), Playwright (E2E critical user flows)
- **CI/CD:** GitHub Actions — lint → test → build → deploy to VPS via SSH; staging environment with `.env.staging`
- Deployment: Docker Compose on self-hosted VPS
- **IP-to-Company Enrichment (B2B):** IPinfo Business API — resolves visitor IP to company name, domain, industry, and headcount server-side at ingestion; result cached in Redis 24h per IP hash; gated behind Pro plan; requires `IPINFO_TOKEN` env var
- **Session Replay (Pro/Business):** rrweb — open-source DOM recording and replay library; loaded as a separate lazy-loaded `eye-replay.min.js` module; never bundled with the core tracker

---

## Project Structure

```
eye/
├── backend/           # Laravel API
├── frontend/          # Next.js
├── tracker/           # Vanilla JS tracking script source
├── docker/            # Nginx, ClickHouse configs
└── docker-compose.yml
```

---

## Database Schema

### PostgreSQL

**Users & Auth**
- users: id, name, email, email_verified_at, password, api_key, role(user|superadmin), status(active|blocked|suspended), timezone(default: UTC), locale(ar|en, default: en), appearance(light|dark|system, default: system), onboarding(json: {domain_added, script_installed, first_event_received, funnel_created}), totp_secret(nullable), totp_enabled(bool default false), totp_last_used_at(nullable), created_at
- password_reset_tokens: email, token, created_at  *(Laravel built-in)*
- totp_backup_codes: id, user_id, code_hash, used_at, created_at  *(8 one-time recovery codes; hashed with bcrypt; shown once on 2FA enable)*
- impersonation_logs: id, admin_id, target_user_id, started_at, ended_at

**Plans & Billing**
- plans: id, name, slug, description, price_monthly, price_yearly, features(json), limits(json: max_domains, max_events_per_day_per_domain, max_events_per_month_per_domain, max_analysis_runs_per_domain_per_month, ai_analysis_interval_hours, data_retention_days, chatbot_enabled, website_chatbot_enabled), is_active, is_public, sort_order, created_at
- payment_methods: id, name, type(stripe|paypal|manual|bank_transfer), config(json), is_active, created_at
- subscriptions: id, user_id, plan_id, payment_method_id, status(active|cancelled|expired|paused), current_period_start, current_period_end, cancelled_at, notes, created_at
- payments: id, user_id, subscription_id, plan_id, payment_method_id, amount, currency, status(pending|paid|failed|refunded), reference, metadata(json), paid_at, created_at

**Domains & Pipelines**
- domains: id, user_id, domain, script_token, previous_script_token, token_rotated_at, script_verified_at, settings(json), active, created_at
  - `previous_script_token` — kept valid for 1 hour after rotation (grace period); null if no recent rotation
  - `script_verified_at` — set on first event received; null if script not yet installed
- domain_exclusions: id, domain_id, type(ip|cookie|user_agent), value, created_at  *(events from matching IPs/user-agents silently dropped on ingestion)*
- pipelines: id, domain_id, name, description, created_at
- pipeline_steps: id, pipeline_id, name, url_pattern, order, created_at

**AI & Analytics**
- ai_reports: id, domain_id, type, content(json), generated_at
- ai_suggestions: id, domain_id, text, category(audience|marketing|ux|conversion), priority(high|medium|low), is_dismissed, created_at
- audience_segments: id, domain_id, name, description, rules(json), visitor_count, color, created_at

**UX Intelligence**
- ux_issues: id, domain_id, session_id, visitor_id, type(js_error|dead_click|rage_click|broken_link|hesitation|form_abandon), url, element_selector, message, stack_trace, metadata(json), created_at
- ux_scores: id, domain_id, score(0-100), breakdown(json: load_speed, error_rate, rage_click_rate, funnel_completion, avg_session_duration), calculated_at

**Visitor Identity**
- visitor_identities: id, domain_id, visitor_id, external_id, traits(json), first_identified_at, updated_at

**Company Enrichment (B2B)**
- company_enrichments: id, ip_hash(varchar — SHA-256 of raw IP, privacy-preserving cache key), company_name, company_domain, industry, employee_range, country, raw(json), enriched_at
  - Populated by `EnrichCompanyJob` on first event per unique IP per day; calls IPinfo Business API
  - Cached in Redis as `enrich:{ip_hash}` (TTL 24h); cache hit skips the job entirely
  - `company_name` denormalized back to ClickHouse `sessions.company_name` via async ALTER UPDATE mutation once enrichment resolves
  - Active only when `IPINFO_TOKEN` env var is configured and the domain owner's plan includes the B2B intelligence feature

**Session Replay (Phase 2 — schema only, feature disabled in Phase 1)**
- session_replays: id, domain_id, session_id, visitor_id, start_url, duration_seconds, event_count, size_bytes, status(recording|complete|pruned), recorded_at
  - Created when `domains.settings.replay_enabled = true` and user plan includes replay
  - Pruned by `CleanupExpiredEventsCommand` per plan `data_retention_days`; cascade-deleted on GDPR visitor deletion request

**Webhooks**
- webhooks: id, domain_id, url, secret, events(json array), is_active, last_triggered_at, created_at
- webhook_deliveries: id, webhook_id, event_type, payload(json), status(pending|delivered|failed), attempts, next_retry_at, created_at

**Shared Reports & Saved Views**
- shared_reports: id, domain_id, user_id, token, label, allowed_pages(json), expires_at, created_at
- saved_views: id, user_id, domain_id, name, filters(json), created_at

**AI Assistant Chatbot (Phase 2 — schema only, feature disabled)**
- chatbot_sessions: id, user_id, domain_id, context_snapshot(json: segments, suggestions, top_pages, top_countries, funnel_data), created_at
- chatbot_messages: id, session_id, role(user|assistant), content, tokens_used, created_at

**Website Visitor Chatbot (Phase 2 — schema only, feature disabled)**
- website_chatbot_configs: id, domain_id, is_enabled, bot_name, welcome_message, color, position(bottom-right|bottom-left), system_prompt, knowledge_base(json), created_at
- website_chatbot_conversations: id, domain_id, visitor_id, session_id, status(open|closed), created_at
- website_chatbot_messages: id, conversation_id, role(visitor|bot), content, created_at

**Super Admin**
- audit_logs: id, admin_id, action(user.block|user.unblock|user.edit|impersonate.start|impersonate.end|plan.create|plan.edit|plan.delete|subscription.change|subscription.cancel|payment.refund|domain.delete), target_type, target_id, before(json), after(json), ip, user_agent, created_at
- theme_settings: id, key(varchar unique), value(json), updated_by(admin user_id), updated_at
  - Seeded keys: `brand_primary`, `brand_secondary`, `brand_accent`, `platform_name`, `logo_light_url`, `logo_dark_url`, `default_locale(ar|en)`, `default_appearance(light|dark|system)`, `font_arabic`, `font_latin`, `border_radius(sharp|rounded|pill)`, `sidebar_style(expanded|collapsed|floating)`
  - Entire row set cached in Redis as `theme_settings` (TTL 1h); invalidated on every admin save
  - Served to the Next.js app root via a public `GET /api/theme` endpoint (no auth); used to inject CSS custom properties at app boot

**Notifications & Alerts**
- alert_rules: id, domain_id, type(traffic_drop|error_spike|quota_warning|score_drop), threshold(json), channel(in_app|email|both), is_active, created_at
- notifications: id, user_id, domain_id, type(alert|quota_warning|export_ready|script_detected|welcome|subscription_changed|weekly_digest), title, body, action_url, channel(in_app|email|both), email_sent_at, read_at, created_at
- notification_preferences: id, user_id, type(alert|quota_warning|export_ready|script_detected|subscription_changed|weekly_digest), in_app(bool default true), email(bool default true), created_at, updated_at
  - One row per user per notification type; seeded with defaults on registration
  - `weekly_digest` email opt-in controls the weekly summary email

**GDPR / Privacy**
- visitor_optouts: id, domain_id, visitor_id, opted_out_at  *(visitors who clicked opt-out)*
- data_deletion_requests: id, domain_id, visitor_id, requested_at, processed_at, status(pending|done)

**Plan Quota Enforcement (Redis)**
- Key pattern: `quota:{domain_token}:events:{YYYY-MM-DD}` — daily counter incremented per event
- Key pattern: `quota:{domain_id}:analysis:{YYYY-MM}` — monthly AI analysis run counter
- Both keys checked BEFORE processing; if over limit, event silently dropped (200 returned to tracker, nothing stored)
- Key pattern: `analytics:{domainId}:{md5(params)}` — serialized ClickHouse aggregation result; TTL 5 minutes; auto-invalidated via `cache:clear-domain:{domainId}` tag flush when a new event is ingested for that domain

**Exports**
- export_jobs: id, user_id, domain_id, type(visitors|events|funnel|ai), format(csv|excel), status(pending|processing|done|failed), file_path, created_at

### ClickHouse
- events: domain_id, session_id, visitor_id, event_type, url, country, city, device, browser, os, referrer, utm_source, utm_medium, utm_campaign, utm_term, utm_content, metadata(json), created_at
- sessions: domain_id, session_id, visitor_id, duration_seconds, page_count, country, device, entry_url, exit_url, utm_source, utm_medium, utm_campaign, company_name(Nullable(String)), started_at
- pipeline_events: domain_id, session_id, pipeline_id, step_id, status(entered/completed/dropped), event_time
- ux_events: domain_id, session_id, visitor_id, type(rage_click|dead_click|hesitation|form_abandon|js_error|broken_link), url, element_selector, details(json), created_at
- custom_events: domain_id, session_id, visitor_id, name, properties(json), url, created_at  *(MergeTree, partitioned by toYYYYMM(created_at))*
- replay_events: domain_id, session_id, event_index(UInt32), rrweb_type(UInt8), data(json), timestamp — MergeTree, ordered by (domain_id, session_id, event_index); TTL governed by plan `data_retention_days`; **Phase 2 only — table created by migration but not written to in Phase 1**

---

## API Endpoints (Laravel)

### Auth
- POST /api/auth/register  — sends email verification link
- POST /api/auth/login  — **rate limited: 10 req/min per IP**; if 2FA enabled returns `{two_factor: true}` instead of token
- POST /api/auth/logout
- GET /api/auth/me
- POST /api/auth/email/verify/{id}/{hash}  — verify email address (signed URL)
- POST /api/auth/email/resend  — resend verification email; **rate limited: 3 req/10min per user**
- POST /api/auth/forgot-password  — send reset link to email; **rate limited: 5 req/15min per IP**
- POST /api/auth/reset-password  — consume token + set new password
- POST /api/auth/two-factor/enable  — generates TOTP secret + provisioning URI + QR code data URL; stores encrypted secret but does NOT set `totp_enabled` yet
- POST /api/auth/two-factor/confirm  — verifies a TOTP code against the pending secret; if valid, sets `totp_enabled = true` + returns 8 backup codes (hashed and stored)
- DELETE /api/auth/two-factor/disable  — requires current TOTP code + password confirmation; clears `totp_secret` + backup codes
- POST /api/auth/two-factor/challenge  — **public, short-lived session** — accepts TOTP code or backup code; completes login and returns auth token; **rate limited: 5 attempts before 15-min lockout per IP**

### Domains
- GET /api/domains
- POST /api/domains
- DELETE /api/domains/{id}
- GET /api/domains/{id}/script  — returns script tag HTML + token
- GET /api/domains/{id}/verify  — returns verification status (script_verified_at, last event time)
- POST /api/domains/{id}/rotate-token  — generates new script_token; old token valid 1h via previous_script_token

### Tracking (public, no auth)
- POST /api/track  — receives events from JS script; **rate limited: 300 req/min per IP** (separate from per-domain quota); returns 200 silently on limit
- POST /api/track/optout  — sets opt-out record for a visitor_id + domain (no personal data stored)

### Health
- GET /api/health  — **public, no auth**; queries PostgreSQL, Redis, and ClickHouse each with a trivial statement; returns `{status: "ok", checks: {db: true, redis: true, clickhouse: true}, latency_ms: {db: N, redis: N, clickhouse: N}}`; used by Docker healthchecks, Nginx upstream probes, and external uptime monitors; returns 503 if any check fails

### Theme (public)
- GET /api/theme  — **public, no auth**; returns all `theme_settings` rows as a flat key→value map; cached in Redis (TTL 1h); used by Next.js root layout to inject CSS custom properties and default locale/appearance for unauthenticated pages

### Analytics
- GET /api/analytics/{domainId}/overview  — all timestamps converted to user's timezone; supports `?compare=previous_period&period=30d` to return two date-range datasets for delta % comparison
- GET /api/analytics/{domainId}/realtime
- GET /api/analytics/{domainId}/visitors
- GET /api/analytics/{domainId}/pages  — supports `?compare=previous_period` for side-by-side period comparison
- GET /api/analytics/{domainId}/countries
- GET /api/analytics/{domainId}/devices
- GET /api/analytics/{domainId}/funnels/{pipelineId}
- GET /api/analytics/{domainId}/custom-events  — list custom event names with counts, trends, and top property key→value breakdown
- GET /api/analytics/{domainId}/identities  — paginated list of identified visitors (external_id, traits, session count, last seen)

### Notifications & Alerts
- GET /api/notifications  — list unread + recent notifications (supports `?channel=in_app` filter)
- PATCH /api/notifications/{id}/read
- PATCH /api/notifications/read-all
- DELETE /api/notifications/{id}  — dismiss / delete a single notification
- DELETE /api/notifications  — clear all read notifications
- GET /api/notification-preferences  — return all per-type preference rows for the authenticated user
- PATCH /api/notification-preferences  — bulk update (body: array of `{type, in_app, email}`)
- GET /api/alert-rules/{domainId}
- POST /api/alert-rules/{domainId}
- PUT /api/alert-rules/{id}
- DELETE /api/alert-rules/{id}

### GDPR
- DELETE /api/gdpr/visitor  — delete all data for a visitor_id across a domain (body: domain_id, visitor_id)
- GET /api/gdpr/optout-status  — check if a visitor_id is opted out for a domain

### Domain Exclusions
- GET /api/domains/{id}/exclusions  — list IP / user-agent exclusion rules for a domain
- POST /api/domains/{id}/exclusions  — add rule (body: type, value)
- DELETE /api/domains/{id}/exclusions/{exclusionId}

### Webhooks
- GET /api/webhooks/{domainId}  — list webhooks for a domain
- POST /api/webhooks/{domainId}  — create webhook (url, events[], secret)
- PUT /api/webhooks/{id}  — update webhook
- DELETE /api/webhooks/{id}  — delete webhook
- POST /api/webhooks/{id}/test  — send a test payload; returns delivery result synchronously

### Shared Reports
- POST /api/shared-reports  — create shared link (body: domain_id, label, allowed_pages?, expires_at?)
- GET /api/shared-reports/{domainId}  — list all active shared links for a domain
- DELETE /api/shared-reports/{id}  — revoke a shared link
- GET /api/public/report/{token}  — **public, no auth** — returns analytics snapshot for the token's allowed_pages scope; verifies token validity + expiry

### Saved Views
- GET /api/saved-views/{domainId}  — list saved views for a domain
- POST /api/saved-views/{domainId}  — save current filter set (body: name, filters JSON)
- DELETE /api/saved-views/{id}  — delete a saved view

### Onboarding
- GET /api/onboarding  — returns completion status for each step for the authenticated user
- PATCH /api/onboarding/{step}  — mark a step as done (step: domain_added | script_installed | first_event_received | funnel_created)

### User Preferences
- PATCH /api/profile/preferences  — update `locale` (ar|en) and/or `appearance` (light|dark|system) for the authenticated user; changes take effect on next page load

### Pipelines
- GET/POST /api/domains/{id}/pipelines
- PUT/DELETE /api/pipelines/{id}
- POST/GET /api/pipelines/{id}/steps

### AI
- GET /api/ai/{domainId}/segments
- GET /api/ai/{domainId}/suggestions
- POST /api/ai/{domainId}/analyze  — trigger manual re-analysis (checks monthly quota)
- PATCH /api/ai/suggestions/{id}/dismiss
- POST /api/ai/{domainId}/chat  — [PHASE 2] AI marketing assistant chat; returns 503 {feature: "disabled"} in Phase 1
- DELETE /api/ai/{domainId}/chat/{sessionId}  — [PHASE 2] clear chat session

### UX Intelligence
- GET /api/ux/{domainId}/score — happiness score + breakdown
- GET /api/ux/{domainId}/issues — paginated issues list (filter by type, url, date)
- GET /api/ux/{domainId}/heatmap — rage-click/dead-click coordinates per page URL
- GET /api/ux/{domainId}/errors — JS error log grouped by message + affected visitor count

### Company Enrichment (B2B)
- GET /api/analytics/{domainId}/companies — paginated list of companies resolved from visitor IPs; columns: company name, domain, industry, employee range, visitor count, session count, last seen; filter by date range and industry; **Pro plan required — returns 403 with upgrade prompt on Free plan**
- GET /api/analytics/{domainId}/companies/{companyDomain} — all sessions attributed to a specific company domain; visitor list with session counts and last-seen timestamps

### Session Replay [PHASE 2 — endpoints stubbed, return 503 {feature: "disabled"} in Phase 1]
- GET /api/replay/{domainId}/sessions — list of sessions with replay recordings; filter by date, URL, visitor_id; sorted by recorded_at desc
- GET /api/replay/{domainId}/sessions/{sessionId} — replay metadata (duration, event count, start URL) + paginated event stream
- DELETE /api/replay/{domainId}/sessions/{sessionId} — delete a replay recording (GDPR right to erasure; cascades to ClickHouse `replay_events` rows)

### Website Visitor Chatbot [PHASE 2 — endpoints stubbed, return 503 {feature: "disabled"} in Phase 1]
- GET /api/chatbot/{domainId}/config
- PUT /api/chatbot/{domainId}/config
- GET /api/chatbot/{domainId}/conversations
- GET /api/chatbot/{domainId}/conversations/{id}
- POST /api/chatbot/widget/{token}/message  — public endpoint called by embedded widget

### Export
- POST /api/exports — create export job (body: domain_id, type, format, filters)
- GET /api/exports/{id} — poll job status + get download URL when done
- GET /api/exports/{id}/download — stream the file

### Super Admin (prefix: /api/admin, guard: superadmin)

**Users**
- GET /api/admin/users — list all users (filters: plan, status, search)
- GET /api/admin/users/{id} — user detail with subscription + domains
- PATCH /api/admin/users/{id} — edit user (name, email, role, status)
- POST /api/admin/users/{id}/block
- POST /api/admin/users/{id}/unblock
- POST /api/admin/users/{id}/impersonate — returns short-lived impersonation token
- DELETE /api/admin/impersonate — end impersonation session

**Plans**
- GET/POST /api/admin/plans
- GET/PUT/DELETE /api/admin/plans/{id}
- PATCH /api/admin/plans/{id}/toggle-visibility

**Payment Methods**
- GET/POST /api/admin/payment-methods
- PUT/DELETE /api/admin/payment-methods/{id}

**Subscriptions**
- GET /api/admin/subscriptions — list all (filters: status, plan, user)
- GET /api/admin/subscriptions/{id}
- POST /api/admin/subscriptions/{id}/upgrade — change plan
- POST /api/admin/subscriptions/{id}/cancel
- POST /api/admin/subscriptions/{id}/pause
- POST /api/admin/subscriptions/{id}/resume
- POST /api/admin/users/{id}/subscriptions — manually assign plan to user

**Payments**
- GET /api/admin/payments — list all (filters: status, method, date range)
- GET /api/admin/payments/{id}
- POST /api/admin/payments/{id}/refund

**Domains**
- GET /api/admin/domains — all domains across all users
- DELETE /api/admin/domains/{id}

**Audit Log**
- GET /api/admin/audit-log  — paginated list (filter by admin_id, action, date range); returns before/after diff for edits

**Theme Settings**
- GET /api/admin/theme  — return all theme setting rows (same as public endpoint but authenticated; also returns logo upload URLs)
- PUT /api/admin/theme  — bulk update theme settings (body: key→value map); validates color format (hex), URL format for logos, enum values; flushes Redis `theme_settings` cache on save
- POST /api/admin/theme/logo  — upload light or dark logo; stored to `storage/public/logos/`; returns public URL written to `logo_light_url` or `logo_dark_url` setting

**Stats**
- GET /api/admin/stats — global metrics: total users, MRR, active subscriptions, total events ingested, top plans

---

## Frontend Pages (Next.js App Router)

### Public
- / — Landing page: hero, features walkthrough, pricing cards, CTA
- /auth/login + /auth/register
- /auth/forgot-password + /auth/reset-password — password reset flow
- /auth/verify-email — email verification notice + resend button
- /pricing — full pricing comparison table

### User Dashboard (guarded by auth + email verified + active subscription)
- **Global domain selector** — sticky top-bar dropdown showing all user domains; selected domain stored in cookie; all dashboard pages scoped to the selected domain
- /dashboard — overview: KPI cards (visitors, sessions, avg time, bounce rate) + area chart (30d trend) + world map (countries) + donut chart (devices) + top pages table
- /dashboard/realtime — live active visitor bubble counter, real-time event feed via WebSocket
- /dashboard/visitors — paginated visitor table with filters; click row → session detail drawer with timeline of events, scroll map, time-on-page, path; **company badge** shown on each row when IP-to-company enrichment has resolved (Pro plan)
- /dashboard/analytics — tabbed: Traffic Sources (bar chart) | UTM Campaigns (table) | Browsers/OS/Devices (grouped bar) | Referrers (table) | Hourly heatmap; **comparison mode**: date range picker has a “Compare to previous period” toggle — when enabled, KPI cards show delta chips (+/– % change) and line charts show two overlapping series (current vs previous); **saved views dropdown** — load or save named filter presets
- /dashboard/funnels — funnel builder UI (drag-and-drop steps), funnel bar chart with drop-off % per step, conversion rate card
- /dashboard/ai — AI Insights hub:
  - Audience Segments: color-coded cards with segment name, size, behavioral profile description, top traits list
  - Marketing Playbook: AI-generated actionable tricks per segment (e.g. "35% of your users are mobile-first from Egypt — run short-video Instagram ads in Arabic during 8–10 PM")
  - Decision Suggestions: prioritized list (High/Medium/Low) with category badges (UX | Conversion | Audience | Marketing), dismiss button
  - Re-analyze button (triggers manual job), last analyzed timestamp, monthly quota indicator
  - **AI Marketing Assistant Chat** [PHASE 2 — shown with "Coming Soon" overlay in Phase 1]: floating chat panel pre-loaded with domain context (segments, funnel data, top pages, countries); user asks natural language questions like "which segment should I target with paid ads this week?" and the assistant responds with data-grounded advice; full conversation history persisted per session
- /dashboard/ux — UX Intelligence (Experience Health):
  - Happiness Score card (0–100) with color gauge, trend sparkline, and breakdown sub-scores (error rate, rage-click rate, funnel completion %, avg session duration, load signal)
  - **Web Vitals card**: LCP / INP / CLS median values with Google threshold colour coding (Good/Needs Improvement/Poor); trend over last 7d; percentile distribution mini chart
  - Stuck Points: ranked list of pages where visitors hesitate or abandon, with session count and avg time-stuck
  - Rage Clicks & Dead Clicks: heatmap overlay per page URL showing where users click frantically or click elements with no response
  - Error Radar: JS error log grouped by message; columns — error message, affected visitors, affected sessions, first seen, last seen, example URL; click to expand stack trace
  - Form Abandonment: which forms users start but don't complete, with field-level drop-off if available
  - Broken Links detected by the tracker (404 navigation events)
- /dashboard/website-chatbot — Website Visitor Chatbot settings [PHASE 2 — full "Coming Soon" gate in Phase 1; route exists but renders upgrade/coming-soon page]
- /dashboard/custom-events — lists all custom `window.EYE.track()` event names with count trends; click an event name to view a property breakdown table (top values per property key); date range filter
- /dashboard/identities — paginated table of identified visitors (external_id, traits summary badge, session count, first/last seen); click row → full session list; search/filter by external_id or trait
- /dashboard/companies — **Pro plan feature** — IP-to-company intelligence: list of companies detected from visitor IPs (name, domain, industry, employee range, visitor count, session count, last seen); click row → all sessions from that company; "Upgrade to Pro" gate shown on Free plan
- /dashboard/replay — **Phase 2 — "Coming Soon" page in Phase 1**: session replay list (thumbnail, duration, start URL, date); click entry → full rrweb player with playback controls, speed selector (0.5×–4×), event timeline sidebar showing clicks/navigations/rages/errors; inputs displayed as masked `••••••••`; filter by URL, visitor, date range; GDPR delete button per recording
- /dashboard/shared-reports — manage shared read-only report links: create form (label, optional page scope, optional expiry), shareable URL with one-click copy, revoke button; table of active links with creation date and expiry
- /dashboard/exports — choose report type (visitors/events/funnel/AI), date range, format (CSV or Excel), trigger download; job status tracker
- /settings/domains — domain list, add domain modal, script tag snippet with one-click copy, delete domain
  - Each domain card shows: **installation status badge** ("Script Active ✓ — last ping 3m ago" | "⚠ Not Detected — no events yet") derived from `script_verified_at`
  - **Rotate Token** button with confirmation dialog (warns that old token stays valid 1 hour)
  - Per-domain daily quota usage progress bar
- /settings/billing — current plan card + usage progress bars (events/day per domain + monthly total), payment history table, upgrade/downgrade plan modal
- /settings/profile — name, email, password change, **timezone selector** (IANA timezone list), **locale selector** (Arabic / English with RTL/LTR preview), **appearance toggle** (Light / Dark / System — sends `PATCH /api/profile/preferences`), API key (reveal + regenerate)
- /settings/alerts — alert rule builder per domain: traffic drop (threshold %), error spike (threshold %), quota warning (% of limit), score drop; channel toggle (in-app / email)
- /settings/webhooks — webhook list per domain; add/edit modal (URL, events checkboxes, HMAC secret reveal + regenerate); test delivery button with response status indicator; delivery log with attempt history, status badge, response code; toggle enable/disable per webhook
- /tools/utm-builder — pure frontend utility (zero backend calls): form fields for base URL + utm_source / utm_medium / utm_campaign / utm_term / utm_content → assembled URL previewed live with one-click copy button; optional client-side QR code rendering
- **Onboarding wizard** — multi-step modal overlay rendered in dashboard root layout; automatically shown on first login and hidden once all `onboarding` JSON flags are set; four steps: Add Domain → Install Script → Receive First Event → Create a Funnel; each step has a call-to-action button and a deep-link to the relevant page; dismissed permanently when all steps complete

### Public (no auth required)
- /report/{token} — read-only shared analytics view: fetches via `GET /api/public/report/{token}`; displays stripped-down KPI cards + area chart + pages table for the allowed_pages scope; no editable controls; EYE branding footer with “Powered by EYE” link; shows expiry date if set

### Super Admin Panel (`/admin`, separate layout, role-guarded)
- /admin — Global dashboard: MRR card, total users, active subscriptions, total events ingested today, new signups chart (30d), revenue chart (30d), top plans pie chart
- /admin/users — Searchable/filterable user table (status, plan, signup date); columns: name, email, plan badge, status badge, domains count, last active, actions
- /admin/users/{id} — User detail: profile info form (editable), subscription history timeline, domains list with analytics quick-view links, payment history, block/unblock/impersonate actions
- /admin/plans — Plan cards grid: name, price, limits summary, active toggle, sort order drag; add/edit plan modal with full features + limits JSON editor
- /admin/payment-methods — List of configured payment methods, add/edit/enable/disable each
- /admin/subscriptions — Full subscription list with filters (status, plan, user search); inline actions: upgrade, cancel, pause, resume; assign plan to user modal
- /admin/payments — Payments ledger table: user, plan, amount, method, status badge, date; refund action; CSV export
- /admin/domains — All domains across all users, searchable by domain or user; view analytics (impersonates context) or delete
- /admin/horizon — **Laravel Horizon dashboard** embedded via iframe (queue job throughput, failed jobs count, wait times per queue; link to retry failed jobs)
- /admin — Impersonation banner: sticky top bar shown when admin is viewing as a user, showing target user name + "Exit Impersonation" button
- /admin/theme — **Full platform theme configuration panel**:
  - **Brand Colors section**: primary / secondary / accent / destructive color pickers (hex input + swatch); live preview of buttons, badges, and cards in sidebar
  - **Logo section**: upload light-mode logo and dark-mode logo separately (PNG/SVG, max 500KB); preview rendered against white and dark backgrounds side by side
  - **Platform Identity**: platform name field (shown in browser tab, email templates, landing header); support email address
  - **Typography section**: Latin font selector (Inter, Geist, Poppins) + Arabic font selector (Noto Naskh Arabic, Tajawal, Cairo); live text preview in both scripts
  - **Border Radius preset**: Sharp (0px) / Rounded (8px) / Pill (9999px) — radio button group with visual card preview
  - **Sidebar Style**: Expanded (always visible) / Collapsed (icon-only default, hover expands) / Floating (overlay) — radio group with mini layout illustration
  - **Default Locale for new users**: Arabic / English radio
  - **Default Appearance for new users**: Light / Dark / System radio
  - **Email branding**: "Apply theme colors to email templates" toggle — when on, `WeeklyDigestMail` and other Mailables use `brand_primary` for CTA button color
  - **Live Preview panel**: split-screen panel showing the dashboard header, a KPI card, and a button in the current saved theme; updates live as user edits any field before saving
  - **Save button** with confirmation; **Reset to defaults** link

---

## Tracking Script (tracker/src/eye.js)

### Performance Requirements (Critical)
The script must have zero perceptible impact on the host website's performance:
- Loaded with `async` + `defer` attributes — never blocks HTML parsing or rendering
- Script tag self-injects via `document.createElement('script')` with `async = true`
- Target size: **< 4KB gzipped** — no external libraries, no polyfills for modern browsers
- All events collected in an in-memory **batch queue**; flushed every 4 seconds OR when queue reaches 10 events OR on page unload
- Flush uses **BeaconAPI** (`navigator.sendBeacon`) — non-blocking, survives page unload; falls back to async XHR only when BeaconAPI unavailable
- Non-critical processing (scroll depth calc, idle detection) deferred via `requestIdleCallback` with 100ms timeout fallback
- No DOM queries on the critical path — all selectors resolved lazily
- No synchronous `localStorage` reads on initial load — deferred to first idle frame
- CORS-safe: tracker endpoint returns permissive CORS headers so XHR fallback works cross-origin

### Events Collected (Phase 1)
- `pageview` — fired on load and on SPA route change (History API pushState/replaceState monkey-patch + popstate); includes utm_* params parsed from URL query string
- `click` — elements with `data-eye-track` attribute only (opt-in, prevents noise)
- `scroll_depth` — batched milestone events at 25%, 50%, 75%, 100% of page height
- `time_on_page` — sent on `visibilitychange` (hidden) and `beforeunload`
- `js_error` — captured via `window.onerror` and `window.addEventListener('unhandledrejection')`: message, file, line, col, stack (truncated to 500 chars)
- `rage_click` — 3+ clicks within 600ms in a 30px radius detected client-side, sent as single event with coordinates + element
- `dead_click` — click on element with no resulting DOM mutation detected within 300ms (via MutationObserver)
- `form_abandon` — user focuses a form field then navigates away without submitting
- `broken_link` — click on `<a href>` that results in a 404 (detected via fetch preflight or navigation response code)
- `pipeline_step` — URL matches a configured pipeline step pattern
- `custom` — fired by `window.EYE.track('event_name', { key: value })`; added to the batch queue immediately; stored in ClickHouse `custom_events` table; name must be a non-empty string ≤ 64 chars
- `web_vitals` — captured via `PerformanceObserver` (type: `largest-contentful-paint`, `layout-shift`, `event`); reports **LCP**, **INP**, and **CLS** as a single batched event with numeric values; adds ~350 bytes gzipped; stored in ClickHouse `custom_events` with name `web_vitals` and properties `{lcp, inp, cls, rating}`; collected once per page load after `requestIdleCallback`

### GDPR + Privacy (built into Phase 1 script)
- **Do Not Track check:** on script init, if `navigator.doNotTrack === '1'` AND `data-respect-dnt="true"` is set on the script tag, abort all collection silently
- **Opt-out cookie check:** on script init, check for `_eye_optout` cookie; if present, abort all collection and skip initialisation entirely
- **Opt-out trigger:** if user's site calls `window.EYE.optout()`, script POSTs to `/api/track/optout`, sets `_eye_optout` cookie (1 year), stops all future collection in this browser
- **Self-exclusion:** `window.EYE.exclude()` — sets `_eye_exclude` cookie locally; halts all collection without any server request; intended for site owners to exclude their own browsing from analytics data
- **Visitor identity:** `window.EYE.identify(externalId, traits)` — sends an identify event containing external user ID and optional traits object (e.g. `{ plan: 'pro', country: 'EG' }`); stored server-side to `visitor_identities` table linked to the current `visitor_id`; safe to call multiple times (upsert logic)
- **Bot detection (on init):** checks `navigator.webdriver === true`, suspicious UA substrings (HeadlessChrome, Puppeteer, Playwright, PhantomJS), and abnormal timing signals; if bot detected, sets internal `__eye_bot = true` — all subsequent events are dropped silently with no network request
- No PII collected — visitor IDs are random UUIDs with no link to IP or email; IP is resolved server-side to country/city only and then discarded

### Phase 2 Stubs (compiled into script but feature-gated by server config flag)
- `chatbot_widget` — injected floating chat widget when `chatbot_enabled: true` returned from config endpoint; widget JS is a **separate lazy-loaded chunk** (never loaded in Phase 1)
- `replay_module` — `eye-replay.min.js` lazy-loaded rrweb wrapper; dynamically imported only when `replay_enabled: true` is returned in the domain config response; **never loaded in Phase 1**; all `<input>`, `<textarea>`, `<select>` values replaced with `*` before serialisation (input masking on by default; opt-out per element via `data-eye-no-mask` attribute); adds ~50KB gzipped as a separate network request; has zero impact on core `eye.min.js` size

### Script Configuration (via data attributes on the `<script>` tag)
```html
<script
  src="https://yourdomain.com/tracker/eye.min.js"
  data-token="YOUR_TOKEN"
  data-api="https://yourdomain.com/api/track"
  async defer
></script>
```


---

## Theming, i18n & Dark Mode

### Tailwind CSS Configuration
- `tailwind.config.js` uses `darkMode: 'class'` — dark mode toggled by adding the `dark` class to `<html>`, controlled by the `ThemeProvider` component
- All directional spacing/layout uses Tailwind's built-in **RTL/LTR variants** (`rtl:ml-4 ltr:mr-4`, `rtl:text-right ltr:text-left`, `rtl:flex-row-reverse`) — no extra RTL plugin required (Tailwind v3.3+ includes these natively)
- CSS custom properties injected at the root from `theme_settings` (fetched server-side via `GET /api/theme` at app boot):
  ```css
  :root {
    --color-primary: #hex;
    --color-secondary: #hex;
    --color-accent: #hex;
    --border-radius: 0.5rem;  /* sharp=0 / rounded=0.5rem / pill=9999px */
  }
  ```
- `tailwind.config.js` `theme.extend.colors` maps `primary`, `secondary`, `accent` to `var(--color-primary)` etc. — all shadcn/ui components inherit these automatically
- `globals.css` defines the root variables with fallback defaults; overridden at runtime on page load by the `ThemeBootstrap` server component

### Typography & Fonts (via `next/font`)
| Locale | Font | Usage |
|---|---|---|
| Arabic (`ar`) | **Noto Naskh Arabic** or **Tajawal** (Google Fonts, variable) | All text when locale = ar |
| English (`en`) | **Inter** (variable) | All text when locale = en |
- Font class applied to `<html>` alongside `dir` attribute; both sourced from `next/font/google` with `display: 'swap'` and `preload: true`
- When locale is `ar`, Arabic font is used for Arabic text AND for any Latin characters appearing in Arabic UI (e.g. numbers, code snippets); ensures consistent vertical rhythm

### Two-Language Setup (`next-intl`)
- **Routing**: Next.js App Router with `next-intl` locale prefix strategy — `/ar/dashboard`, `/en/dashboard`; root `/` redirects to user's stored `locale` (or browser `Accept-Language` header for unauthenticated pages)
- **Message files**: `frontend/messages/ar.json` and `frontend/messages/en.json`; every UI string referenced by key (e.g. `t('dashboard.visitors')`)
- **`<html>` attributes**: `lang="ar" dir="rtl"` or `lang="en" dir="ltr"` set by Next.js root layout based on active locale — all CSS logical properties and Tailwind RTL variants respond to `dir` automatically
- **Numbers & dates**: formatted via `Intl.NumberFormat` and `Intl.DateTimeFormat` with active locale; Arabic uses Eastern Arabic-Indic numerals by default (configurable)
- **RTL scroll & transforms**: all charts (Recharts), dropdowns (shadcn), modals, drawers, and tooltips automatically mirror in RTL — verified via Playwright E2E in Arabic locale
- **Admin-provided strings** (platform name, email template content, plan descriptions): stored with both `name_ar` and `name_en` columns where translatable; API returns the appropriate value based on request `Accept-Language` header or authenticated user locale

### Dark / Light / System Mode
- `ThemeProvider` component (React context) wraps the entire app; reads `users.appearance` from the `/me` response on login; for unauthenticated pages reads `theme_settings.default_appearance`
- Adds/removes the `dark` class on `<html>` instantly on mount without flash (CSS `prefers-color-scheme` media query used as SSR initial value to prevent FOUC)
- User can toggle appearance from any page via the mode switcher in the top-right header bar (sun/moon/monitor icon group); change sent immediately to `PATCH /api/profile/preferences`
- Tailwind dark variant (`dark:bg-zinc-900`, `dark:text-zinc-100`) used throughout; shadcn/ui's built-in dark mode tokens respected

### Translatable Admin-Managed Fields
The following DB columns carry dual-language values to support RTL rendering in Arabic:
- `plans`: add `name_ar`, `description_ar` alongside `name`, `description`
- `payment_methods`: add `name_ar`
- Email templates: `WeeklyDigestMail` subject line switches per user locale

---

## AI Classification Job (Laravel)

- Interval configurable per plan (e.g. Free=24h, Pro=6h, Business=1h) — read from plan limits
- Before dispatching, check monthly analysis quota from Redis: `quota:{domain_id}:analysis:{YYYY-MM}` — if exceeded, skip and log
- Fetches 30-day aggregated data from ClickHouse: top pages, countries, devices, session durations, scroll depths, funnel drop-off per step, referrer sources, peak hours, UX issue counts, happiness score
- Builds structured JSON prompt sent to Anthropic Claude with clear output schema request
- Claude returns structured JSON:
  - `segments[]`: name, description, size_percent, traits[], color
  - `marketing_playbook[]`: segment_name, tactic, channel, timing, example_copy
  - `suggestions[]`: text, category, priority
  - `ux_insights[]`: issue_type, affected_percent, recommendation
  - `summary`: 3-sentence plain-language overview
- Results saved to ai_reports, audience_segments, ai_suggestions; domain `last_analyzed_at` updated; Redis analysis counter incremented
- Frontend fetches latest report; shows "last analyzed X ago" + manual re-analyze button + monthly quota badge (e.g. "3 / 10 analyses used this month")

## UX Intelligence Engine (Laravel)

- `ComputeUxScoreJob` — runs after every `AnalyzeDomainJob` and also on-demand
- Queries ClickHouse `ux_events` for the last 30 days: rage click rate, dead click rate, JS error rate, form abandon rate, broken link count
- Combines with session data: avg duration, bounce rate, funnel completion rate
- Computes weighted happiness score (0–100) and saves to PostgreSQL `ux_scores`
- Identifies "stuck points": pages where median session duration is high but scroll depth is low (user scrolls little and stays long = confused or stuck)
- Results surfaced via `/api/ux/{domainId}/*` endpoints

## Export System (Laravel)

- User POSTs an export job (domain, type, format, optional date range + filters)
- `ProcessExportJob` dispatched to queue
- Job queries ClickHouse / PostgreSQL, builds file using Laravel Excel (xlsx) or native CSV streaming
- File stored to `storage/exports/{user_id}/` with unique name
- Job status updated to `done` with `file_path`
- Frontend polls `/api/exports/{id}` until done, then triggers download
- Files auto-deleted after 24h via scheduled cleanup command

## Data Retention & Cleanup (Laravel Scheduled Commands)

- `CleanupExpiredEventsCommand` — runs nightly at 02:00 UTC; for each domain, reads `data_retention_days` from the user's active plan; deletes ClickHouse rows older than that window using `ALTER TABLE events DELETE WHERE domain_id = ? AND created_at < now() - INTERVAL ? DAY`; same for `sessions`, `ux_events`, `pipeline_events`
- `CleanupExportFilesCommand` — runs hourly; deletes `storage/exports/` files where `export_jobs.created_at` < 24h ago
- `CleanupExpiredTokensCommand` — runs hourly; sets `previous_script_token = NULL` on domains where `token_rotated_at` > 1h ago
- `ProcessDataDeletionRequestsCommand` — runs every 15 minutes; dispatches `DeleteVisitorDataJob` for each pending `data_deletion_requests` row
- `SendWeeklyDigestCommand` — runs every Monday at 08:00 UTC; for each user with `weekly_digest` email preference enabled, dispatches `SendWeeklyDigestJob`

---

## Notification Engine (Laravel)

All notifications use Laravel's built-in `Notification` system with two channels: `DatabaseChannel` (in-app) and `MailChannel` (email). Channel delivery per notification type is gated by the user's `notification_preferences` rows.

### Notification Types & Triggers

| Type | Trigger | In-App | Email |
|---|---|---|---|
| `welcome` | User registers | ✓ (instant) | ✓ (welcome email) |
| `script_detected` | First tracking event received for a domain | ✓ | ✓ |
| `alert` | `CheckAlertRulesJob` threshold breached | ✓ | ✓ (if rule channel includes email) |
| `quota_warning` | Daily event counter hits 80% of plan limit | ✓ | ✓ |
| `export_ready` | `ProcessExportJob` completes | ✓ | ✓ (includes download link) |
| `subscription_changed` | Plan upgraded / downgraded / cancelled by admin | ✓ | ✓ |
| `weekly_digest` | Scheduled Monday 08:00 UTC | — | ✓ (opt-in only) |

### Delivery Architecture
- All notification dispatches go through the `notifications` queue (separate Horizon queue lane, never starves tracking)
- **In-app channel**: writes a row to `notifications` table; `email_sent_at` remains null
- **Email channel**: sends Mailable via configured driver; updates `email_sent_at` on the `notifications` row after successful dispatch
- **Real-time push**: after `notifications` row insert, broadcast `NotificationCreatedEvent` on private `user.{id}` channel via Laravel Reverb → frontend bell counter increments live without polling
- Each Mailable uses a single branded Blade layout (`resources/views/emails/layout.blade.php`): EYE logo, responsive HTML, plain-text fallback, unsubscribe footer link

### Email Templates (Blade Mailables)
- `WelcomeMail` — greeting, "get started" CTA button → /dashboard
- `ScriptDetectedMail` — domain name, confirmation that tracking is live, link to analytics
- `AlertMail` — alert rule type + threshold + current value, link to relevant dashboard section
- `QuotaWarningMail` — domain name, current % used, upgrade CTA + link to /settings/billing
- `ExportReadyMail` — report type, file format, direct download link (time-limited signed URL), expiry notice (24h)
- `SubscriptionChangedMail` — old plan → new plan, effective date, billing implications
- `WeeklyDigestMail` — top KPIs for the week (visitors, sessions, top page, top country, happiness score delta), link to full dashboard; generated from ClickHouse per user's active domains

### User Preferences
- On registration, seed one `notification_preferences` row per type with default `in_app = true, email = true` (except `weekly_digest` which defaults to `email = false`)
- User can toggle each channel per type from `/settings/notifications`
- Alert rules retain their own `channel` field on `alert_rules` table as an override for the per-type preference (allows per-rule granularity)
- Unsubscribe link in every email sets `email = false` for that notification type via a signed one-click route: `GET /api/notifications/unsubscribe/{token}`

---

## Test Suite

### Backend — PHPUnit / Pest
**Feature tests** (full HTTP stack, uses an in-memory SQLite + ClickHouse test DB or Docker test containers):
- Auth: register, login, 2FA enable+confirm+challenge+disable, forgot-password, reset-password, email verification
- Domains: CRUD, token rotation, grace-period validation, script verification, exclusions CRUD
- Tracking ingestion: event routing (events/ux_events/custom_events), quota enforcement, bot filtering, exclusion drop, identify upsert, webhook dispatch trigger
- Analytics: overview, pages, visitors, countries, devices; timezone conversion; comparison mode response shape; caching (assert Redis key set)
- Notifications: `NotificationService::send()` dispatches correct jobs per preference; database channel inserts row; email channel queues mailable
- Alert rules: `CheckAlertRulesJob` fires notification when threshold breached; skips when below threshold
- Webhooks: CRUD; delivery job signs payload correctly; retry logic on non-2xx; test-delivery endpoint
- Shared reports: token generation; public route returns data; expired token returns 403; page-scope enforcement
- AI: `AnalyzeDomainJob` quota check skips when exceeded; Claude response parsed to correct table rows
- Export: `ProcessExportJob` produces valid CSV/XLSX; `export_ready` notification dispatched on completion
- Admin: impersonation token scoping; audit log row written on each mutating admin action; plan CRUD

**Unit tests:**
- `NotificationService` preference gating logic
- Redis quota INCR + TTL logic
- Analytics cache key generation + invalidation
- Token rotation grace-period expiry calculation
- HMAC-SHA256 webhook signature generation
- Bot UA pattern matching

### Frontend — Jest
- `window.EYE.track()`: validates name length; enqueues event; rejects empty name
- `window.EYE.identify()`: enqueues identify event with correct shape
- `window.EYE.optout()`: sets `_eye_optout` cookie; stops further sends
- `window.EYE.exclude()`: sets `_eye_exclude` cookie; stops further sends without POSTing
- Bot detection: `navigator.webdriver = true` sets `__eye_bot = true` and blocks all sends
- Batch queue: events accumulate; flush fires after 4s; flush fires at 10 events; beacon send called once per flush

### E2E — Playwright
Critical user flows tested against the running Docker stack:
1. Register → receive email → verify → land on onboarding wizard → complete all 4 steps → wizard gone
2. Add domain → copy script → embed on test HTML page → confirm pageview in dashboard within 10s
3. Enable 2FA → log out → log back in → complete 2FA challenge → access dashboard
4. Trigger export → poll until `done` → download file → confirm non-empty
5. Create funnel → simulate partial completion → confirm drop-off in funnel chart
6. Create shared report link → open in incognito → confirm read-only view loads + no auth redirect
7. Super admin impersonation: log in as admin → impersonate user → view their data → exit impersonation

---

## Docker Services
- nginx (reverse proxy)
- php-fpm (Laravel)
- node (Next.js)
- postgresql
- pgbouncer (connection pooler — sits between php-fpm and postgresql)
- clickhouse
- redis
- laravel-queue (worker — `php artisan queue:work`)
- laravel-horizon (queue dashboard — `php artisan horizon`)
- laravel-reverb (websockets)

---

## Implementation Phases

### Phase 1 — Project Scaffolding
1. Initialize monorepo folder structure: `eye/backend/`, `eye/frontend/`, `eye/tracker/`, `eye/docker/`
2. Create `docker-compose.yml` with all 8 services
3. Create `eye/docker/nginx/default.conf` routing `/api/*` to Laravel, `/tracker/*` to static files, everything else to Next.js

### Phase 2 — Backend Foundation
4. Scaffold Laravel 11 project in `backend/`, configure Sanctum (SPA auth), configure Sentry Laravel SDK
5. PostgreSQL migrations: all tables (users with timezone + email_verified_at + locale + appearance + onboarding json + totp columns, totp_backup_codes, plans with data_retention_days + name_ar + description_ar, payment_methods with name_ar, theme_settings seeded with 12 default keys, domain_exclusions, pipelines, pipeline_steps, ai_reports, ai_suggestions, audience_segments, visitor_identities, company_enrichments, session_replays, webhooks, webhook_deliveries, shared_reports, saved_views, export_jobs, impersonation_logs, audit_logs, alert_rules, notifications with email_sent_at + action_url + channel, notification_preferences, visitor_optouts, data_deletion_requests)
6. ClickHouse migrations: events (with utm_* columns), sessions (with utm_* + `company_name` Nullable(String)), pipeline_events, ux_events, custom_events, replay_events — all tables MergeTree engine, partitioned by toYYYYMM(created_at); `replay_events` additionally ordered by (domain_id, session_id, event_index)
7. Seeders: default plans (Free: 1 domain / 10k events/day / 30d retention; Pro: 5 domains / 100k events/day / 90d retention; Business: unlimited domains / 1M events/day / 365d retention), default payment methods, **theme_settings default rows** (brand_primary `#6366f1`, brand_secondary `#8b5cf6`, platform_name `EYE`, default_locale `en`, default_appearance `system`, font_latin `Inter`, font_arabic `Tajawal`, border_radius `rounded`, sidebar_style `expanded`)
8. Auth API: register (send verification email + seed notification_preferences defaults + dispatch `WelcomeNotification`) + login (2FA check) + logout + /me + email verify + resend verify + forgot-password + reset-password + all 2FA endpoints (enable, confirm, disable, challenge, backup-code regenerate)

### Phase 3 — Core User APIs
9. Domains API: CRUD + `script_token` generation (bin2hex(random_bytes(32))) + `/verify` endpoint + `/rotate-token` (stores old token in `previous_script_token`, sets `token_rotated_at`)
10. Token validation middleware: accepts both `script_token` AND `previous_script_token` if `token_rotated_at` < 1h ago
11. Script generator endpoint: returns `<script>` tag HTML with token + pipeline config
11a. **Health check endpoint** `GET /api/health`: queries PostgreSQL via `DB::select('SELECT 1')`, Redis via `Redis::ping()`, ClickHouse via lightweight `SELECT 1`; returns structured JSON with per-service latency; add as Docker `HEALTHCHECK` for `php-fpm` service; configure in Nginx upstream for graceful failover
11b. **Rate limiting middleware**: apply `throttle:300,1` to `POST /api/track`; apply `throttle:10,1` to `POST /api/auth/login`; apply `throttle:5,15` to `POST /api/auth/forgot-password`; apply `throttle:5,15` to `POST /api/auth/two-factor/challenge`; use Redis as the rate-limit store (Laravel default when Redis is configured)
12. Tracking ingestion `POST /api/track`:
    - Validate token (primary + grace-period previous) → resolve domain → check opt-out (`visitor_optouts` or `_eye_optout` header) → check daily event quota from Redis — if over limit, return 200 silently
    - **Bot filtering:** match incoming User-Agent against headless browser pattern list (HeadlessChrome, Puppeteer, Playwright, PhantomJS, SlimerJS); if matched, return 200 silently without storing anything
    - **Exclusion check:** load `domain_exclusions` for the domain (Redis-cached, TTL 60s); if request IP or User-Agent value matches any rule, return 200 silently
    - Increment Redis quota counter (TTL 25h); set `script_verified_at` on domain if null (first ever event)
    - Geo-resolve IP via GeoLite2 → extract country/city → discard raw IP
    - Parse utm_* from metadata if present
    - Route `custom` events to ClickHouse `custom_events`; UX events to `ux_events`; all others to `events`
    - If event type is `identify`, upsert `visitor_identities` (external_id + traits) linked to visitor_id
    - Insert async via queue → return 204 immediately
    - After successful insert, check active webhooks for domain matching the event type → dispatch `WebhookDeliveryJob` if any found
13. `POST /api/track/optout`: validate token → upsert `visitor_optouts` → return 200
14. Analytics query endpoints: all ClickHouse queries apply user `timezone` offset; overview, realtime (Redis counters), visitors, pages, countries, devices; **wrap each aggregation in a Redis cache layer** — compute cache key as `analytics:{domainId}:{md5(serialized params)}`, TTL 5 min, invalidated when a new event is ingested for the domain (tag-based flush)
15. Pipeline CRUD + funnel analytics endpoint
16. UX Intelligence endpoints: score, issues, heatmap, errors
17. Notifications & Preferences API: list, mark-read, mark-all-read, delete, clear-read; GET/PATCH `/api/notification-preferences`; one-click unsubscribe route `GET /api/notifications/unsubscribe/{token}` (signed, sets email=false for a type); Alert rules CRUD
18. GDPR endpoints: visitor deletion (queues `DeleteVisitorDataJob`), optout-status check
18a. User preferences endpoint: `PATCH /api/profile/preferences` — accepts `locale` and/or `appearance`; updates `users` row; returns updated `/me` payload
18b. Public theme endpoint: `GET /api/theme` — reads from Redis cache (`theme_settings`, TTL 1h); on miss, queries `theme_settings` table and caches; returns flat key→value JSON map
18c. Admin theme API: `GET /api/admin/theme`, `PUT /api/admin/theme` (validates and saves settings, flushes Redis cache), `POST /api/admin/theme/logo` (stores uploaded file, returns public URL)
19. Export system: `POST /api/exports` → dispatch `ProcessExportJob` → poll status → download
20. Domain exclusions API: full CRUD for `domain_exclusions`; on write, invalidate Redis exclusion cache for that domain (TTL 60s on read to avoid DB hit per tracking request)
21. Visitor identity API: `GET /api/analytics/{domainId}/identities` — paginated, searchable by external_id; detail view shows all sessions linked to that identity
21a. **Company enrichment pipeline**: after geo-resolve step in tracking ingestion, compute `ip_hash = sha256(raw_ip)`; check Redis `enrich:{ip_hash}`; on cache hit, attach `company_name` directly to the session record; on cache miss, store session without `company_name` and dispatch `EnrichCompanyJob` (on `ai` queue, fire-and-forget); the job calls IPinfo Business API, writes `company_enrichments` row, caches result in Redis (TTL 24h), and back-fills `sessions.company_name` via ClickHouse `ALTER TABLE sessions UPDATE`; build `GET /api/analytics/{domainId}/companies` and `/companies/{companyDomain}` query endpoints; feature active only when `IPINFO_TOKEN` env var is set
22. Custom events analytics API: `GET /api/analytics/{domainId}/custom-events` — aggregate from ClickHouse `custom_events`; returns per-name totals + trend + top property values
23. Webhooks CRUD + delivery: `GET/POST /api/webhooks/{domainId}`, `PUT/DELETE /api/webhooks/{id}`, `POST /api/webhooks/{id}/test`; `WebhookDeliveryJob` signs payload with HMAC-SHA256 (using webhook secret), stores each attempt in `webhook_deliveries`, retries up to 5× with exponential backoff on non-2xx responses
24. Shared reports API: `POST/GET /api/shared-reports`, `DELETE /api/shared-reports/{id}`; `GET /api/public/report/{token}` — public route with no auth guard; validates token exists + not expired + page scope; returns analytics snapshot
25. Saved views API: `GET/POST /api/saved-views/{domainId}`, `DELETE /api/saved-views/{id}`; views scoped to user + domain
26. Onboarding API: `GET /api/onboarding` returns `users.onboarding` JSON; `PATCH /api/onboarding/{step}` sets the matching key to true; ingestion pipeline auto-marks `script_installed` + `first_event_received` on first real event

### Phase 4 — AI Layer
20. Anthropic Claude HTTP client integration in Laravel service class
21. `AnalyzeDomainJob`: check monthly quota → fetch ClickHouse aggregates → build structured prompt → call Claude → parse JSON response → save segments/suggestions/report → dispatch `ComputeUxScoreJob` → dispatch `CheckAlertRulesJob`
22. `ComputeUxScoreJob`: query ClickHouse ux_events + sessions → compute happiness score + stuck points → save to `ux_scores`
23. `CheckAlertRulesJob`: evaluate each alert_rule for the domain against latest metrics; if threshold breached, call the shared `NotificationService::send(user, type, data)` helper — this checks `notification_preferences` and dispatches `DatabaseChannel` (in-app row insert + `NotificationCreatedEvent` broadcast) and/or `MailChannel` (queued `AlertMail`) according to preferences
24. `DeleteVisitorDataJob`: delete visitor's rows from ClickHouse `events`, `sessions`, `ux_events`, `pipeline_events` using visitor_id; update `data_deletion_requests` to done
25. Scheduled command: check `last_analyzed_at` against plan interval, dispatch `AnalyzeDomainJob` for eligible domains
26. AI endpoints: GET segments, GET suggestions, POST analyze (manual, checks quota), PATCH dismiss suggestion
27. Stub Phase 2 chat endpoint: `POST /api/ai/{domainId}/chat` → 503 `{"feature":"disabled","phase":2}`
27a. Stub Phase 2 session replay endpoints: `GET /api/replay/{domainId}/sessions`, `GET /api/replay/{domainId}/sessions/{sessionId}`, `DELETE /api/replay/{domainId}/sessions/{sessionId}` → all return 503 `{"feature":"disabled","phase":2}`
28. Build `NotificationService` helper class: `send(User $user, string $type, array $data)` — reads `notification_preferences` for user+type, dispatches `InAppNotificationJob` if `in_app=true` (inserts row + broadcasts `NotificationCreatedEvent`), dispatches the matching Mailable job if `email=true`; all dispatched on `notifications` queue
29. Build all Mailable classes: `WelcomeMail`, `ScriptDetectedMail`, `AlertMail`, `QuotaWarningMail`, `ExportReadyMail`, `SubscriptionChangedMail`, `WeeklyDigestMail`; each uses shared Blade layout with EYE branding + plain-text alt + unsubscribe footer link
30. Dispatch `QuotaWarningNotification` from tracking ingestion when Redis counter crosses 80% of plan day limit
31. Dispatch `ExportReadyNotification` from `ProcessExportJob` on completion
32. Dispatch `SubscriptionChangedNotification` from subscription admin endpoints on plan change/cancel
33. `SendWeeklyDigestCommand` (scheduled Monday 08:00 UTC): queries each user who has `weekly_digest` email preference enabled, dispatches `SendWeeklyDigestJob` which pulls the week’s KPIs from ClickHouse per active domain and sends `WeeklyDigestMail`

### Phase 5 — Super Admin APIs
28. `superadmin` middleware (role check)
29. Users admin API: list, detail, edit, block/unblock
30. Impersonation: `POST /api/admin/users/{id}/impersonate` → store record → return short-lived token scoped to target user; `DELETE /api/admin/impersonate` to end
31. Plans admin API: full CRUD + visibility toggle (limits JSON includes all quota fields + data_retention_days + chatbot flags)
32. Payment methods admin API: full CRUD
33. Subscriptions admin API: list, detail, upgrade, cancel, pause, resume, manual assign
34. Payments admin API: list, detail, refund action
35. Domains admin API: list all, delete; include per-domain quota usage from Redis
36. Global stats endpoint: MRR, user counts, event counts, top plans, total UX issues detected today
37. Configure Laravel Horizon (queues: default, tracking, ai, exports, notifications); expose `/horizon` route restricted to superadmin role
38. **Admin audit logging middleware**: attach to all `/api/admin/*` mutating routes (POST/PATCH/PUT/DELETE); after response, write an `audit_logs` row with admin_id, action enum, before/after JSON snapshots, client IP, user-agent; impersonation start/end also log via dedicated events

### Phase 6 — Tracking Script
38. Write `tracker/src/eye.js` — vanilla JS, no dependencies, target < 4KB gzipped:
    - On init: check `navigator.doNotTrack === '1'` (if `data-respect-dnt` set) → check `_eye_optout` cookie → abort if either present
    - Async/deferred load — never blocks render
    - In-memory event **batch queue**, flushed every 4s or at 10 events or on unload
    - BeaconAPI send → async XHR fallback
    - Visitor ID (localStorage) and Session ID (sessionStorage) set on first idle frame via `requestIdleCallback`
    - Pageview on load + SPA route detection (pushState/replaceState monkey-patch + popstate); auto-parse utm_* from URL query string
    - Scroll depth milestones (25/50/75/100%) computed in idle callback
    - Time-on-page via `visibilitychange` + `beforeunload`
    - `window.onerror` + `unhandledrejection` → js_error event (stack truncated to 500 chars)
    - Rage click detection: track click timestamps in 30px radius ring buffer; emit if 3+ within 600ms
    - Dead click detection: MutationObserver post-click; emit if no DOM change within 300ms
    - Form abandon: focus tracking + navigation-away detection
    - URL pattern matching for pipeline steps
    - `window.EYE.optout()` public API method → POST optout + set cookie
    - `window.EYE.exclude()` public API method → set `_eye_exclude` cookie + halt collection locally (no server POST)
    - `window.EYE.identify(externalId, traits)` public API method → enqueue identify event with external ID and traits object → dispatched via batch queue like any other event
    - `window.EYE.track(name, properties)` public API method → enqueue custom event to batch queue; validates name is non-empty string ≤ 64 chars; properties is an optional plain object
    - **Bot detection on init:** check `navigator.webdriver`, suspicious UA strings (HeadlessChrome, Puppeteer, Playwright, PhantomJS); if any signal detected, set `__eye_bot = true` — all event sends short-circuit silently
    - **Core Web Vitals collection:** attach a `PerformanceObserver` for `largest-contentful-paint`, `layout-shift`, and `event` entry types; accumulate CLS across the session; compute INP from all user interactions; after `requestIdleCallback`, emit a single `web_vitals` custom event with `{lcp, inp, cls, rating}` properties; adds ~350 bytes gzipped; no-op on unsupported browsers
    - Phase 2 feature flag: if config response includes `chatbot_enabled: true`, dynamically import `eye-chat.min.js` — never loaded in Phase 1
39. Build/minify → `tracker/dist/eye.min.js` served by Nginx; `tracker/dist/eye-chat.min.js` for Phase 2 widget (built but not loaded)

### Phase 7 — Frontend: User Dashboard
40. Scaffold Next.js 14 App Router in `frontend/`:
    - **Tailwind CSS v3**: `tailwind.config.js` with `darkMode: 'class'`, RTL variants enabled, CSS variable color tokens (`primary`, `secondary`, `accent`, `border-radius`) mapped in `theme.extend`
    - **shadcn/ui** component library; **Recharts** for charts; configure **Sentry** Next.js SDK
    - **`next-intl`**: `i18n.ts` routing config with `locales: ['ar', 'en']`, `defaultLocale: 'en'`; locale from URL prefix (`/ar/`, `/en/`); `frontend/messages/ar.json` + `frontend/messages/en.json` translation files with keys for every UI string
    - **`ThemeBootstrap` server component** at root layout: fetches `GET /api/theme` (or Redis-cached result) at build/request time; injects CSS custom properties into `<style>` tag in `<head>`
    - **`ThemeProvider` client component**: reads `users.appearance` from `/me` after login; applies/removes `dark` class on `<html>` without FOUC (uses `prefers-color-scheme` as SSR fallback)
    - **Root layout**: sets `<html lang={locale} dir={locale === 'ar' ? 'rtl' : 'ltr'}>` + applies font class (`font-arabic` or `font-latin`) via `next/font/google`
    - **Header mode switcher**: sun/moon/monitor icon group in top-right of every authenticated layout; instant toggle + persists via `PATCH /api/profile/preferences`
41. Auth pages: /auth/login, /auth/register, /auth/forgot-password, /auth/reset-password, /auth/verify-email
42. **2FA challenge page** `/auth/two-factor-challenge`: shown after successful password login when `totp_enabled = true`; accepts 6-digit TOTP code or 8-character backup code; submits to `POST /api/auth/two-factor/challenge`; shows lockout message after 5 failed attempts
42. Middleware: protect /dashboard and /settings routes (redirect to login if unauthenticated, to /auth/verify-email if unverified); redirect /admin to admin layout guard
43. Global domain selector component in main dashboard layout (dropdown, persists in cookie)
44. Notification bell in header: badge with unread count, dropdown list of recent notifications (title, body, relative time, action-link), mark-read on click, "Mark all read" button; **real-time updates via Reverb** (`user.{id}` private channel) — bell counter increments and a toast popup appears in the bottom-right corner whenever a new notification arrives, without any polling
45. Dashboard overview `/dashboard`: KPI cards + AreaChart (Recharts) + world map (react-simple-maps) + device donut + top pages table; all dates rendered in user timezone
46. Realtime page `/dashboard/realtime`: active visitor counter (WebSocket), scrolling event feed
47. Visitors page `/dashboard/visitors`: sortable/filterable table + session detail side drawer (event timeline, scroll depth bar, page path)
48. Analytics page `/dashboard/analytics`: tabbed — Traffic Sources bar, UTM table (source/medium/campaign), Browser/OS grouped bars, Referrers, hourly heatmap grid
49. Funnel builder `/dashboard/funnels`: drag-and-drop step editor (dnd-kit), funnel bar chart, drop-off % per step
50. AI Insights `/dashboard/ai`: segment cards, marketing playbook accordion, suggestions list, monthly quota badge, Phase 2 chat overlay
51. UX Intelligence `/dashboard/ux`: happiness score gauge, stuck points, rage/dead click heatmap, error radar, form abandonment, broken links
52. Website Chatbot `/dashboard/website-chatbot`: coming-soon gate
53. Exports `/dashboard/exports`: report type selector, date range picker, format toggle, submit → poll → download
54. Domain settings `/settings/domains`: domain cards with installation status badge, rotate-token button (with 1h grace warning), script snippet, quota bar
55. Billing `/settings/billing`: plan card, usage bars, payment history, upgrade modal
56. Profile `/settings/profile`: name/email, password change, **timezone selector** (IANA list via `Intl.supportedValuesOf('timeZone')`), API key reveal + regenerate
57. Security `/settings/security`: 2FA section — if disabled, shows QR code setup flow (scan with authenticator app, enter 6-digit code to confirm); if enabled, shows status + backup codes (reveal/regenerate) + disable button (requires code + password); session list (active sessions, revoke individual)
57. Alerts `/settings/alerts`: rule builder per domain (type, threshold, channel), rule list with enable/disable toggle
58. Notifications `/settings/notifications`: preference matrix table — rows are notification types (Alert, Quota Warning, Export Ready, Script Detected, Subscription Changed, Weekly Digest), columns are channels (In-App toggle, Email toggle); save button; "Send test email" button per row to verify email delivery; unsubscribe note in footer
59. Landing page `/`
60. Custom events page `/dashboard/custom-events`: fetch from `GET /api/analytics/{domainId}/custom-events`; render event name list with count + trend sparkline; click → property breakdown table with top key:value pairs
61. Identities page `/dashboard/identities`: paginated identified visitor table; search by external_id; session count badge; click row → session detail drawer
62. Shared reports page `/dashboard/shared-reports`: create link form (label + optional page scope + optional expiry datepicker); table of active links; copy-to-clipboard button; revoke with confirmation dialog
63. UTM builder `/tools/utm-builder`: all client-side; derive assembled URL as user fills fields; live preview string; copy button; client-side QR code using a lightweight canvas-based library
64. Comparison mode integration: update `/dashboard/analytics` and `/dashboard` overview — add “Compare to” toggle in date picker; when enabled, fetch two datasets and render delta chips on KPI cards and dual-line overlay on area chart
65. Saved views: add views dropdown to `/dashboard/analytics` and `/dashboard/visitors` — load list from `GET /api/saved-views/{domainId}`, save current filters via `POST`, delete via icon button
66. Onboarding wizard: `OnboardingWizard` component in dashboard root layout; reads status from `GET /api/onboarding`; renders 4-step card modal with progress indicator; hides permanently once all steps complete; step CTAs deep-link to /settings/domains, /settings/domains (script), /dashboard, /dashboard/funnels
67. Webhooks settings `/settings/webhooks`: webhook list card per domain; add/edit sheet modal (URL input, events multi-checkbox, secret input with show/hide); test button → show modal with response status and body; delivery log table with attempt count + last status badge
68. Public report route `/report/[token]`: unauthenticated page; calls `GET /api/public/report/{token}` server-side; renders stripped dashboard view (KPI cards + area chart + pages table); EYE branded header; 404 if token invalid or expired

### Phase 8 — Frontend: Super Admin Panel
59. Separate `/admin` layout with its own sidebar + impersonation sticky banner component
60. `/admin` global dashboard: MRR, users, subscriptions KPIs + revenue area chart + new signups bar chart + top plans pie
61. `/admin/users`: searchable DataTable, status badge + plan badge, actions dropdown (view, edit, block, impersonate)
62. `/admin/users/{id}`: editable profile form, subscription timeline, domains list with per-domain quota usage bars, payment history, action buttons
63. `/admin/plans`: plan cards grid with drag-to-reorder, add/edit modal including all quota fields + data_retention_days + chatbot toggles + `name_ar`/`description_ar` Arabic translation fields
64. `/admin/payment-methods`: method cards, add/edit/toggle enable modal (with `name_ar` field)
65. `/admin/subscriptions`: full table with inline upgrade/cancel/pause/resume, assign-plan-to-user modal
66. `/admin/payments`: ledger table, refund button with confirmation dialog, CSV export
67. `/admin/domains`: all-domains table with per-domain quota usage, view analytics, delete confirmation
68. `/admin/horizon`: Laravel Horizon dashboard embedded in iframe (queue health, failed jobs, throughput)
69. `/admin/audit-log`: paginated table of all admin actions; columns — admin name, action badge, target (type + id + name), timestamp, IP; click row → before/after JSON diff modal; filter by action type and date range
70. `/admin/theme`: full theme configuration panel — brand colors (color pickers), logo upload (light + dark), platform name, font selectors (Latin + Arabic), border radius preset, sidebar style, default locale, default appearance, email branding toggle; live preview panel; save + reset-to-defaults

### Phase 9 — Real-Time (WebSockets)
69. Configure Laravel Reverb; broadcast `VisitorActiveEvent` and `PageviewEvent` on tracking ingestion
70. Broadcast `NotificationCreatedEvent` on `user.{id}` private channel when a new in-app notification is inserted; frontend bell increments and toast popup fires
71. Frontend subscribes to `domain.{token}` private channel on realtime + dashboard pages; subscribes to `user.{id}` private channel globally in dashboard layout for live notifications

### Phase 10 — Deployment
72. Production `docker-compose.prod.yml` with resource limits, healthchecks (using `GET /api/health`), restart policies; includes pgbouncer service
73. Nginx SSL config (Let's Encrypt / Certbot container)
74. `.env.example` for backend and frontend (includes SENTRY_DSN for both, MAIL_MAILER, MAIL_HOST, MAILGUN_DOMAIN, MAILGUN_SECRET, MAIL_FROM_ADDRESS, MAIL_FROM_NAME)
75. **Staging environment**: separate `docker-compose.staging.yml` + `.env.staging`; GitHub Actions deploys to staging on every merge to `main`, to production on every tag `v*`

### Phase 11 — CI/CD Pipeline
76. Create `.github/workflows/ci.yml`: on pull_request → checkout → PHP 8.3 setup → `composer install` → `php artisan test --parallel` (Pest feature + unit tests) → Node setup → `npm ci` in frontend → `npm run lint` → `npm run build` → Jest unit tests → Playwright E2E against a Docker test stack
77. Create `.github/workflows/deploy.yml`: on push to `main` → SSH to staging VPS → `git pull` → `docker compose build && docker compose up -d` → `php artisan migrate --force` → health check probe; on tag `v*` → same steps against production VPS
78. Add `Makefile` with shortcuts: `make test`, `make build`, `make deploy-staging`, `make deploy-prod`

---

## Verification Checklist

**Auth**
- [ ] Register → receive verification email → click link → confirm email_verified_at set
- [ ] Try to access /dashboard without verifying email → redirect to /auth/verify-email
- [ ] Use forgot-password flow → receive email → reset password → login with new password

**Two-Factor Authentication**
- [ ] Enable 2FA from `/settings/security` → confirm QR code renders → enter TOTP code → confirm `totp_enabled = true` in DB
- [ ] Confirm 8 backup codes shown once on enable; confirm codes are hashed in `totp_backup_codes` table
- [ ] Log out → log back in with correct password → confirm 2FA challenge page appears
- [ ] Submit correct TOTP code on challenge page → confirm login completes and lands on dashboard
- [ ] Submit 5 wrong codes → confirm account lock message + 15-min block on further attempts
- [ ] Use a backup code → confirm login succeeds → confirm that code is marked `used_at` and cannot be reused
- [ ] Disable 2FA with correct code + password → confirm `totp_enabled = false` + `totp_backup_codes` rows deleted

**Rate Limiting**
- [ ] Hit `POST /api/auth/login` 11 times in 1 minute from same IP → confirm 11th returns 429
- [ ] Hit `POST /api/auth/forgot-password` 6 times in 15 minutes → confirm 6th returns 429
- [ ] Flood `POST /api/track` > 300 req/min from same IP → confirm 200 returned silently (no 429 to tracker)
- [ ] Hit `POST /api/auth/two-factor/challenge` 6 times with wrong codes → confirm lockout

**Health Check**
- [ ] `GET /api/health` returns `{status: "ok"}` with all three checks true when all services running
- [ ] Stop PostgreSQL container → `GET /api/health` returns 503 with `{db: false}`
- [ ] Confirm Docker `HEALTHCHECK` marks container unhealthy when `/api/health` returns 503

**GDPR & Privacy**
- [ ] Set `navigator.doNotTrack = '1'` in browser + `data-respect-dnt` on script → confirm zero events sent
- [ ] Call `window.EYE.optout()` → confirm `_eye_optout` cookie set + POST to optout endpoint + events stop
- [ ] Load page with `_eye_optout` cookie already present → confirm script initialisation aborted, no events sent
- [ ] Call `DELETE /api/gdpr/visitor` → confirm visitor rows deleted from all ClickHouse tables

**Tracking**
- [ ] Embed `eye.min.js` on a test HTML page → confirm pageview appears in ClickHouse with utm_* columns populated
- [ ] Measure Lighthouse performance score before and after embedding — confirm no score degradation
- [ ] Confirm script size is < 4KB gzipped via `gzip -c eye.min.js | wc -c`
- [ ] Navigate between pages on SPA test → confirm route-change pageview events fire
- [ ] Scroll to 50% → confirm scroll depth event logged
- [ ] Trigger `window.onerror` manually → confirm js_error event appears in UX events
- [ ] Click same spot 4 times rapidly → confirm rage_click event fires
- [ ] Click non-interactive element → confirm dead_click event fires after 300ms
- [ ] Leave page → confirm time-on-page event fires via BeaconAPI
- [ ] Load page in Chrome, navigate to a content-heavy section, wait for idle → confirm `web_vitals` event appears in ClickHouse `custom_events` with `lcp`, `inp`, `cls`, `rating` properties
- [ ] View Web Vitals card on `/dashboard/ux` → confirm LCP/INP/CLS values shown with correct Google threshold colour (Good/NI/Poor)

**Domain Switcher & Installation Verification**
- [ ] Add two domains → domain selector shows both → switching changes all dashboard data
- [ ] New domain shows "Not Detected" installation status → send first event → status changes to "Script Active"
- [ ] Rotate token → confirm old token still works during 1h grace period → expires after 1h
- [ ] Previous_script_token cleared by scheduled command after 1h

**Data Retention**
- [ ] Set Free plan `data_retention_days = 30` → seed events older than 30 days → run cleanup command → confirm old events deleted from ClickHouse
- [ ] Confirm events within retention window are not deleted

**Alerts**
- [ ] Create traffic-drop alert rule → simulate traffic dropping to zero → confirm in-app notification appears
- [ ] Create error-spike alert rule → inject JS errors beyond threshold → confirm notification fired

**Notification System (In-App + Email)**
- [ ] Register a new user → confirm welcome in-app notification appears in bell dropdown
- [ ] Register a new user → confirm welcome email received at the registered address
- [ ] Send the first tracking event for a domain → confirm `script_detected` notification appears in bell and email received
- [ ] Trigger a domain event quota at 80% → confirm `quota_warning` in-app + email dispatched
- [ ] Complete an export job → confirm `export_ready` in-app notification fires + email with download link received
- [ ] Change a user’s plan in admin panel → confirm `subscription_changed` in-app + email received by user
- [ ] Trigger a `CheckAlertRulesJob` threshold breach with rule `channel = email` → confirm `AlertMail` received
- [ ] Open `/settings/notifications` → toggle off email for `export_ready` → complete an export → confirm in-app fires but NO email sent
- [ ] Toggle off in-app for `quota_warning` → hit 80% quota → confirm email received but NO in-app notification row created
- [ ] Click unsubscribe link in any email → confirm that notification type’s `email` preference flips to false for the user
- [ ] Enable `weekly_digest` email preference → advance system clock to Monday 08:00 UTC → run `SendWeeklyDigestCommand` → confirm digest email received with correct KPI data
- [ ] Leave `weekly_digest` email preference disabled (default) → run command → confirm no email sent
- [ ] Send test email from `/settings/notifications` → confirm test message arrives at user’s inbox
- [ ] Open two browser tabs on dashboard → trigger a new notification server-side → confirm bell counter increments in real-time on both tabs via Reverb broadcast
- [ ] Mark all notifications read → bell badge disappears; refresh page → badge stays gone

**UX Intelligence**
- [ ] Seed UX events → trigger `ComputeUxScoreJob` → verify happiness score appears on /dashboard/ux
- [ ] Verify stuck points list identifies the correct high-dwell low-scroll pages
- [ ] Verify error radar shows grouped JS errors with stack trace expand

**User Flow**
- [ ] Register → add domain → copy script → paste on test page → see data populate in dashboard
- [ ] Open /dashboard/realtime in browser → open test page in another tab → confirm live count increments
- [ ] Configure a 3-step funnel → simulate partial completion → check drop-off chart
- [ ] Trigger manual AI analysis → verify segments, marketing playbook, UX insights, and suggestions all appear
- [ ] Exceed daily event quota → verify subsequent events silently dropped (200 returned, nothing stored)
- [ ] Export visitors to CSV and Excel → verify both files download with correct data
- [ ] Change timezone in profile settings → verify hourly heatmap shifts to match new timezone

**Phase 2 Stubs**
- [ ] Visit /dashboard/ai chat section → confirm "Coming Soon" overlay renders, input is disabled
- [ ] Visit /dashboard/website-chatbot → confirm coming-soon page renders
- [ ] Call `POST /api/ai/{domainId}/chat` → confirm 503 `{"feature":"disabled","phase":2}` response
- [ ] Verify `eye-chat.min.js` is NOT loaded on any page in Phase 1

**Billing & Quotas**
- [ ] Assign Free plan to a user → verify per-domain daily event limit enforced via Redis
- [ ] Upgrade to Pro via admin panel → verify new limits reflected immediately
- [ ] Exhaust monthly AI analysis quota → verify job skips and returns quota error to manual trigger

**Custom Events API**
- [ ] Call `window.EYE.track('button_click', { id: 'cta' })` on a test page → confirm row appears in ClickHouse `custom_events` with correct name and properties
- [ ] Verify custom event appears in `/dashboard/custom-events` with count and property breakdown

**Bot Filtering**
- [ ] Send a tracking request with `HeadlessChrome` in the User-Agent → confirm 200 returned but event NOT stored in ClickHouse
- [ ] Verify legitimate browser events continue to be stored after bot filter is applied

**IP / User-Agent Exclusion**
- [ ] Add an IP exclusion rule for your own IP → send events from that IP → confirm silently dropped (200 returned, nothing in ClickHouse)
- [ ] Remove rule → confirm events from same IP are tracked again

**Visitor Identity**
- [ ] Call `window.EYE.identify('user-123', { plan: 'pro' })` → confirm `visitor_identities` row created in PostgreSQL with correct external_id and traits
- [ ] Verify identified visitor appears in `/dashboard/identities` with correct session count

**Company Enrichment (B2B)**
- [ ] Send a tracking event from a known business ISP IP → confirm `EnrichCompanyJob` dispatched → `company_enrichments` row created with company name and industry
- [ ] Send a second event from the same IP on the same day → confirm Redis cache hit, no new API call and no duplicate DB row
- [ ] Visit `/dashboard/companies` on a Pro plan → confirm company list shows detected companies with visitor and session counts
- [ ] Visit `/dashboard/companies` on a Free plan → confirm 403 / upgrade gate is displayed
- [ ] View a visitor row in the session list → confirm company name badge appears when enrichment has resolved

**Session Replay (Phase 2 stub checks)**
- [ ] Confirm `eye-replay.min.js` is NOT loaded or requested in Phase 1 (check DevTools Network panel)
- [ ] Call `GET /api/replay/{domainId}/sessions` → confirm 503 `{"feature":"disabled","phase":2}` response
- [ ] Visit `/dashboard/replay` → confirm "Coming Soon" overlay renders with no recording list

**Date Range Comparison**
- [ ] Enable compare toggle on dashboard overview → confirm KPI cards show delta % chips
- [ ] Confirm area chart renders two overlapping series (current + previous period)

**Onboarding Wizard**
- [ ] Register a new user → visit /dashboard → confirm wizard modal appears with all 4 steps
- [ ] Complete all four steps → confirm wizard disappears and does not reappear on reload
- [ ] Mark a step via `PATCH /api/onboarding/{step}` → confirm `users.onboarding` JSON updated in DB

**Webhooks**
- [ ] Add a webhook → trigger a pageview event → confirm entry in `webhook_deliveries` with status `delivered` and 2xx response code
- [ ] Set webhook URL to a non-existent endpoint → confirm retries appear in delivery log with `failed` status and incrementing attempt count

**Shared Reports**
- [ ] Create a shared report link for a domain → open the URL in an incognito window → confirm read-only analytics view loads without login
- [ ] Set expiry date → wait for expiry → confirm link returns expired/404 error

**UTM Builder**
- [ ] Fill in all UTM fields → confirm assembled URL updates live with correct encoding
- [ ] Click copy button → paste → confirm correct URL with all utm_* params appended

**Saved Views**
- [ ] Save a filter set on `/dashboard/analytics` → reload page → select saved view from dropdown → confirm filters are restored exactly

**Super Admin**
- [ ] Log in as super admin → view global stats dashboard
- [ ] Impersonate a user → confirm banner appears → see their analytics
- [ ] Exit impersonation → confirm session returns to admin account
- [ ] Block a user → confirm they cannot log in
- [ ] Edit plan `data_retention_days` → verify cleanup command respects new value
- [ ] Open /admin/horizon → confirm queue dashboard loads with job stats
- [ ] Cancel a subscription → verify user access restricted
- [ ] Block a user → open `/admin/audit-log` → confirm `user.block` entry appears with admin name, target user, IP, and timestamp
- [ ] Refund a payment → confirm `payment.refund` audit log entry with before/after amounts visible in diff modal

**Analytics Caching**
- [ ] Load `/dashboard` overview → inspect Redis → confirm `analytics:{domainId}:{hash}` key exists with TTL ≈ 5 min
- [ ] Load same page again → confirm ClickHouse query not executed (cache hit); response time < 50ms
- [ ] Send a new tracking event for the domain → confirm cache key invalidated → next request re-queries ClickHouse

**Test Suite & CI**
- [ ] Run `php artisan test` → confirm all Pest feature + unit tests pass
- [ ] Run `npx jest` in tracker/ → confirm all batch queue and public API tests pass
- [ ] Run `npx playwright test` → confirm all 7 E2E flows pass against Docker stack
- [ ] Open a pull request on GitHub → confirm CI workflow runs all three test suites and blocks merge on failure
- [ ] Push a `v*` tag → confirm deploy workflow runs and production health check returns 200

**Dark / Light Mode**
- [ ] Log in → click Dark mode toggle in header → confirm `dark` class applied to `<html>` instantly, all pages render dark theme
- [ ] Refresh page → confirm dark mode persists (stored in `users.appearance`)
- [ ] Set appearance to System → change OS to dark mode → confirm app follows without reload
- [ ] Switch to Light mode → confirm all charts, cards, modals render correctly in light theme
- [ ] Unauthenticated landing page `/` → confirm it uses `theme_settings.default_appearance`

**Arabic / English (RTL/LTR)**
- [ ] Switch locale to Arabic in `/settings/profile` → confirm page reloads at `/ar/` prefix, `dir="rtl"` set on `<html>`, Arabic font loaded
- [ ] Confirm sidebar, breadcrumbs, and dropdown menus mirror correctly in RTL
- [ ] Confirm all Recharts bar/area charts render with reversed axes in RTL
- [ ] Confirm data tables flip column order appropriately in RTL
- [ ] Confirm all shadcn/ui modals, drawers, and tooltips open from the correct side in RTL
- [ ] Switch back to English → confirm `dir="ltr"` restored and Inter font loads
- [ ] Confirm number formatting uses Arabic-Indic numerals in `ar` locale and Western in `en`
- [ ] Confirm date strings localized (e.g. month names in Arabic)
- [ ] Run Playwright E2E suite in Arabic locale → all 7 flows pass

**Theme Configuration (Super Admin)**
- [ ] Log in as super admin → open `/admin/theme` → change `brand_primary` to a new hex color → confirm live preview panel updates immediately
- [ ] Save theme → reload any user-facing page → confirm primary color reflected in buttons, badges, and links
- [ ] Upload a dark-mode logo → confirm it appears in the dark-mode header across all pages
- [ ] Change `border_radius` to Sharp → save → confirm all cards and buttons lose border radius
- [ ] Change `default_locale` to Arabic → register a new user → confirm new user's default locale is Arabic
- [ ] Change `platform_name` → confirm updated in browser tab title and email template headers
- [ ] Change Arabic font to Cairo → switch to Arabic locale → confirm Cairo font loaded
- [ ] Click "Reset to defaults" → confirm all theme settings revert to seeded values

---

## Architecture Decisions
- **ClickHouse** for events (not TimescaleDB) — better aggregation performance at scale without extensions
- **Laravel Reverb** (native) over Pusher — no third-party cost on self-hosted
- **Sanctum SPA auth** over JWT packages — first-party, secure, cookie-based
- **BeaconAPI + batch queue** in tracker — zero blocking impact on host website; events buffered and flushed in the background
- **Silent quota enforcement** — when a domain exceeds its daily event cap, the tracker endpoint returns 200 with no body; no error is ever exposed to the end user's website visitors
- **Per-domain quotas in Redis** — fast atomic INCR operations, no PostgreSQL writes on the hot tracking path
- **Phase 2 features compiled but gated** — chatbot widget code exists in the build from day one, dynamically imported only when server config says so; prevents Phase 2 from blocking Phase 1
- **UX score is computed, not streamed** — happiness score re-computed as a batch job to keep ClickHouse query load predictable
- **PgBouncer** between php-fpm and PostgreSQL — prevents connection exhaustion under concurrent request load; php-fpm opens many short-lived connections that PgBouncer pools efficiently
- **Timezone per user, UTC in storage** — all ClickHouse data stored in UTC; API queries convert to user timezone on read using `toTimeZone(created_at, ?)` ClickHouse function; this avoids data migration when users change their timezone
- **Data retention enforced via ClickHouse mutations** — `ALTER TABLE ... DELETE` (lightweight delete mutation in MergeTree) run nightly; retention window per plan stored in `plans.limits.data_retention_days`
- **Token rotation grace period** — `previous_script_token` column + 1h TTL prevents site outage when a user rotates their script token before re-deploying the new snippet
- **No raw IP stored** — IP resolved to country/city via GeoLite2 server-side, then discarded; aligns with GDPR minimisation principle
- **Laravel Horizon** for queue visibility — separate queue channels per concern (tracking, ai, exports, notifications) to prevent AI jobs from starving tracking ingestion
- **Sentry** for error monitoring in both Laravel and Next.js — silent job failures and client-side errors surfaced to the developer without impacting the user
- **Laravel Notification system** for dual-channel delivery — `DatabaseChannel` and `MailChannel` are native Laravel channels; a shared `NotificationService` helper gates delivery per `notification_preferences`; prevents notification logic from scattering across unrelated jobs and controllers
- **Notifications on a dedicated queue lane** (`notifications` in Horizon) — email sending and in-app insert never compete with high-priority tracking or AI jobs; slow SMTP delivery cannot delay analytics ingestion
- **`notification_preferences` seeded on registration** — every user has a preference row for every type from day one; avoids null-checks in notification dispatch code; simpler than a JSON column on `users` (each row is independently queryable)
- **One-click unsubscribe via signed URL** — complies with CAN-SPAM / RFC 8058 `List-Unsubscribe-Post`; signed with `URL::temporarySignedRoute` so no auth session is needed; only sets one type’s email flag to false, not a global unsubscribe
- **`email_sent_at` on `notifications` row** — enables deduplication logic (never send the same notification email twice) and surfaces email delivery status to admin
- **Real-time bell via Reverb** (not polling) — `NotificationCreatedEvent` dispatched after DB row insert; broadcast on the user’s private channel (`user.{id}`); frontend listens globally in layout; no `/api/notifications` polling needed
- **Weekly digest as a scheduled Mailable** — off by default (`email=false`) so new users are not surprised; opt-in in preferences; generated from ClickHouse aggregates, not pre-cached, so it is always fresh at send time
- **Alert rule `channel` as a per-rule override** — some users want email only for critical alerts and in-app for all others; the `alert_rules.channel` field overrides the user-level `notification_preferences` for alert-type notifications specifically
- **Bot/spam filtering in ingestion** (not in tracker alone) — server-side UA matching is more reliable and harder to bypass; tracker-side `__eye_bot` flag provides an additional early exit for headless automation tools
- **Webhook HMAC-SHA256 signing** — every delivery includes an `X-Eye-Signature` header (`sha256=<hex>`); consuming services verify the signature before processing; prevents spoofed webhook payloads
- **Exclusion list cached in Redis** (60s TTL) — `domain_exclusions` table queried at most once per minute per domain rather than on every tracking request; hot path stays sub-millisecond
- **Bot UA list hardcoded in config** — avoids a DB round-trip for filtering; list updated via config file or environment variable, not a DB table; tunable without a code deploy
- **Custom events in a separate ClickHouse table** — keeps the main `events` table lean for standard analytics; custom events carry arbitrary property schemas not suitable for top-level columns
- **Shared report token** is a random 32-byte hex string (same entropy as script tokens); no session required to view; expiry enforced server-side; `allowed_pages` scope prevents accidental full-data exposure
- **UTM builder is frontend-only** — no backend needed; assembled URL is pure string construction; QR code rendered client-side via canvas; reduces server load and simplifies deployment
- **Comparison mode** runs two separate ClickHouse aggregations server-side and merges results into a single response; delta % = (current − previous) / previous × 100; avoids complex window functions
- **Excluded from MVP (Phase 1):** AI marketing assistant chat, website visitor chatbot, multi-user teams per domain, public external API, white-label mode
- **TOTP 2FA via `pragmarx/google2fa-laravel`** — generates TOTP secrets server-side; QR code data URL generated with `chillerlan/php-qrcode`; encrypted at rest using Laravel's `Crypt` facade before storing `totp_secret`; backup codes are bcrypt-hashed, shown once, regeneratable
- **Rate limiting backed by Redis** — Laravel’s built-in `throttle` middleware configured with Redis store (`CACHE_DRIVER=redis`); separate limiters per concern prevent one noisy endpoint from consuming the global bucket; tracking endpoint returns 200 silently (not 429) to avoid leaking limit info to host websites
- **Health check is infrastructure-first** — `GET /api/health` is always the first endpoint implemented; Docker `HEALTHCHECK` and Nginx upstream health probes reference it; never requires auth; has a 2-second timeout per dependency check to avoid hanging under load
- **Analytics cache is tag-scoped** — all cache entries for a domain share the tag `analytics:domain:{domainId}`; a single `Cache::tags(...)->flush()` call on event ingest invalidates all variants (different date ranges, filters, tabs) in one operation without knowing the specific keys
- **Web Vitals via `PerformanceObserver`** (not `web-vitals` npm package) — the tracker is vanilla JS with no dependencies; the observer API is natively available in all Chromium/Firefox/Safari versions that support INP; unsupported browsers silently skip without error; adds ~350 bytes gzipped; LCP/INP/CLS thresholds from Google’s public spec (LCP ≤ 2.5s Good, ≤ 4s NI; INP ≤ 200ms Good, ≤ 500ms NI; CLS ≤ 0.1 Good, ≤ 0.25 NI)
- **Audit log is append-only** — no UPDATE or DELETE ever issued against `audit_logs`; rows are written by middleware after the response, not inside the controller transaction, so a failed audit write never rolls back a legitimate admin operation; Sentry captures audit write failures silently
- **CI runs against a full Docker test stack** — GitHub Actions spins up the same `docker-compose.yml` with test-scoped env; Playwright tests run against the real stack, not mocks; this prevents “works in test, broken in prod” for ClickHouse query syntax and CORS behavior
- **Deploy workflow is tag-gated for production** — every merge to `main` deploys to staging automatically; production deploys only on `v*` tags, requiring an explicit decision; this prevents accidental production pushes while keeping staging always up-to-date
- **Tailwind `darkMode: 'class'`** over `media` strategy — user preference overrides OS setting; `dark` class toggled by `ThemeProvider` on `<html>`; SSR initial render uses `prefers-color-scheme` media query to avoid flash of unstyled content (FOUC); stored persistently in `users.appearance`
- **Tailwind native RTL variants** (`rtl:`, `ltr:`) over a third-party RTL plugin — Tailwind v3.3+ ships these built-in; all directional styles written as `rtl:ml-2 ltr:mr-2`; no CSS duplication; `dir` attribute on `<html>` is the sole switch
- **`next-intl`** over `react-i18next` or `next-translate` — first-class App Router support with server components; locale routing via URL prefix (`/ar/`, `/en/`); server-side `t()` function in layouts with no client hydration cost; strongly typed message keys via TypeScript inference
- **Font per locale via `next/font/google`** — Inter for Latin, Tajawal (default) / Noto Naskh / Cairo for Arabic; each font loaded as a CSS variable (`--font-latin`, `--font-arabic`); Tailwind's `fontFamily` tokens reference these variables; switching locale swaps font class on `<html>` without a separate stylesheet
- **`theme_settings` as a key-value table** (not a single JSON column) — each setting is individually updatable without full-row replacement; each key queryable as a cache sub-key; easy to add new theme options without a migration
- **CSS variables for brand colors** (not Tailwind JIT arbitrary values) — CSS variables can be updated at runtime by injecting a `<style>` block server-side from `theme_settings`; changing a color in admin requires no rebuild; Tailwind color tokens reference `var(--color-primary)` so all components inherit the change automatically
- **Theme settings cached in Redis (TTL 1h)** — the public `GET /api/theme` endpoint is called on every Next.js root layout render; caching keeps it sub-millisecond; admin save flushes the key immediately so changes propagate within 1h to non-cached clients and instantly to fresh sessions
- **Dual-language fields on plans and payment methods** (`name_ar`, `description_ar`) — stored in PostgreSQL alongside the English versions; API returns the locale-matched value based on `Accept-Language` or authenticated user locale; no separate translation table needed for these simple fields
- **All analytics definitions are query-time, not stream-processing triggers** — funnels, saved views, custom event tracking, comparison mode, and audience segments are stored as query metadata (definitions) in PostgreSQL; every request executes them live against the full ClickHouse `events` history; a funnel created today instantly shows historical data back to account creation with no activation step; there is no "start collecting from now" mode; this is possible because ClickHouse aggregation over partitioned MergeTree is fast enough to serve any date range in < 200ms
- **IP-to-company enrichment is async and non-blocking** — `EnrichCompanyJob` is dispatched after the tracking response is already returned (fire-and-forget on the `ai` queue); it adds zero latency to the hot ingestion path; raw IP is never persisted (SHA-256 hash used as the Redis cache key — irreversible, satisfies GDPR data minimisation); if the IPinfo API is down the session is stored without `company_name` and enrichment is retried on the next event from the same IP; company-level data (name, domain, industry) is not personal data under GDPR (it identifies a business entity, not an individual)
- **Session replay as a separate lazy-loaded module** (`eye-replay.min.js`, rrweb wrapper) — never bundled with the core tracker; loaded dynamically only when `replay_enabled: true` is returned in the domain config; core `eye.min.js` stays under 4KB; replay adds ~50KB gzipped as a distinct network request; input masking is on by default (all form field values replaced with `*` before rrweb serialisation) to prevent PII capture; replay events stored in ClickHouse `replay_events` with sequential `event_index` for efficient ordered playback streaming

---

## Open Questions
1. **IP Geolocation:** MaxMind GeoLite2 (free, self-hosted, no rate limit) confirmed — requires registering for a license key; add to deployment docs. **IP-to-company enrichment** uses IPinfo Business API (`IPINFO_TOKEN` env var) — which plan tier unlocks the B2B company analytics feature: Pro or Business only?
2. **Script hosting:** Serve `eye.min.js` directly from VPS at `/tracker/eye.min.js` or push to CDN (Cloudflare R2) for worldwide latency? CDN recommended for script performance.
3. **AI cost control:** Only run `AnalyzeDomainJob` for domains with ≥ 100 visitors in the last 7 days?
4. **Payments gateway:** Implement real Stripe processing for subscriptions now, or keep billing as admin-assigned only for MVP?
5. **Chart library:** Recharts (lightweight, composable) confirmed? Alternative is Tremor (higher-level pre-built charts).
6. **Impersonation security:** Should impersonation tokens be IP-pinned for extra safety?
7. **Happiness score weights:** Suggested default: error_rate 30%, rage_click_rate 20%, funnel_completion 25%, avg_session_duration 15%, bounce_rate 10% — confirm or adjust?
8. **Phase 2 timeline:** What is the target date for the AI marketing chatbot and website visitor chatbot features?
9. **Email provider:** Laravel Mail with configurable driver via `MAIL_MAILER` env var. Recommended: **Mailgun** (reliable, generous free tier, good deliverability). Postmark and Amazon SES are supported alternatives. SMTP relay works for self-hosted. Configure `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` to match the product domain.
10. **GDPR jurisdiction:** Is the product primarily targeting EU customers? If yes, a cookie consent banner on end-user websites should be part of the tracker's documentation/guidance.



wsl -d Ubuntu -e sh -c "cd /mnt/h/coupons/githubs/eye && docker compose up -d"


Service	URL
Frontend (Next.js)	http://localhost:3000
Backend API (via Nginx)	http://localhost/api
Horizon (queue dashboard)	http://localhost/horizon
WebSockets (Reverb)	ws://localhost:8080