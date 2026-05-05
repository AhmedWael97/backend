<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\AnalyzeDomainJob;
use App\Models\AiReport;
use App\Models\AiSuggestion;
use App\Models\AudienceSegment;
use App\Models\Domain;
use App\Models\Payment;
use App\Services\ClickHouseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    /** Token packs available for purchase (tokens => price in USD). */
    private const TOKEN_PACKS = [
        'starter' => ['tokens' => 10, 'price_usd' => 9, 'label' => 'Starter'],
        'growth' => ['tokens' => 30, 'price_usd' => 25, 'label' => 'Growth'],
        'pro' => ['tokens' => 100, 'price_usd' => 79, 'label' => 'Pro'],
    ];

    /** Minimum unique visitors in the last 30 days required for a meaningful AI report. */
    private const MIN_VISITORS = 1000;

    public function __construct(private readonly ClickHouseService $clickhouse)
    {
    }

    // ── Read endpoints ────────────────────────────────────────────────────────

    public function segments(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);
        return $this->success(AudienceSegment::where('domain_id', $domain->id)->get());
    }

    public function suggestions(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);

        return $this->success(
            AiSuggestion::where('domain_id', $domain->id)
                ->where('is_dismissed', false)
                ->orderByRaw("CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 ELSE 4 END")
                ->get()
        );
    }

    public function report(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);
        $report = AiReport::where('domain_id', $domain->id)->latest('generated_at')->first();

        return $this->success($report ? $report->content : null);
    }

    // ── Token status ──────────────────────────────────────────────────────────

    public function quotaStatus(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);
        $user = $request->user();
        $plan = $user->subscription?->plan;
        $isFree = !$plan || $plan->slug === 'free' || $plan->price_monthly == 0;

        $lastReport = AiReport::where('domain_id', $domain->id)->latest('generated_at')->first();

        // Unique visitors in the last 30 days
        $from = now()->subDays(30)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');
        $rows = $this->clickhouse->select("
            SELECT uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domain->id}
              AND ts >= '{$from}' AND ts < '{$to}'
        ");
        $visitorCount = (int) ($rows[0]['unique_visitors'] ?? 0);

        return $this->success([
            'ai_tokens' => $user->ai_tokens,
            'ai_free_used' => (bool) $user->ai_free_used,
            'is_free_plan' => $isFree,
            'visitor_count' => $visitorCount,
            'min_visitors' => self::MIN_VISITORS,
            'can_run_free' => $isFree && !$user->ai_free_used && $visitorCount >= self::MIN_VISITORS,
            'last_analyzed_at' => $lastReport?->generated_at,
            'token_packs' => self::TOKEN_PACKS,
        ]);
    }

    // ── Trigger analysis ──────────────────────────────────────────────────────

    public function analyze(Request $request, int $domainId): JsonResponse
    {
        $domain = $this->authorizedDomain($request, $domainId);
        $user = $request->user();
        $plan = $user->subscription?->plan;
        $isFree = !$plan || $plan->slug === 'free' || $plan->price_monthly == 0;

        // Visitor count gate
        $from = now()->subDays(30)->format('Y-m-d H:i:s');
        $to = now()->format('Y-m-d H:i:s');
        $rows = $this->clickhouse->select("
            SELECT uniq(visitor_id) AS unique_visitors
            FROM events
            WHERE domain_id = {$domain->id}
              AND ts >= '{$from}' AND ts < '{$to}'
        ");
        $visitorCount = (int) ($rows[0]['unique_visitors'] ?? 0);

        if ($visitorCount < self::MIN_VISITORS) {
            return $this->error(
                "Not enough data yet. You need at least " . number_format(self::MIN_VISITORS) .
                " unique visitors in the last 30 days to generate an AI report. You currently have " .
                number_format($visitorCount) . ".",
                422,
                ['visitor_count' => $visitorCount, 'min_visitors' => self::MIN_VISITORS]
            );
        }

        // Token / entitlement check
        $isFreeRun = false;

        if ($isFree) {
            if ($user->ai_free_used) {
                return $this->error(
                    'You have used your free AI analysis. Purchase tokens to run more reports.',
                    402,
                    ['token_packs' => self::TOKEN_PACKS, 'ai_tokens' => $user->ai_tokens]
                );
            }
            $isFreeRun = true; // will be marked used inside the job
        } else {
            if ($user->ai_tokens < 1) {
                return $this->error(
                    'You have no AI tokens remaining. Purchase more to continue.',
                    402,
                    ['token_packs' => self::TOKEN_PACKS, 'ai_tokens' => 0]
                );
            }
        }

        AnalyzeDomainJob::dispatch($domain->id, $user->id, $isFreeRun)->onQueue('ai');

        return $this->success([
            'message' => 'AI analysis started. Results will appear in a few moments.',
            'ai_tokens' => $user->ai_tokens - ($isFreeRun ? 0 : 1),
            'is_free_run' => $isFreeRun,
        ], 202);
    }

    // ── Suggestions management ────────────────────────────────────────────────

    public function dismissSuggestion(Request $request, int $id): JsonResponse
    {
        $suggestion = AiSuggestion::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $suggestion->update(['is_dismissed' => true]);
        return $this->success(['message' => 'Suggestion dismissed.']);
    }

    // ── Token purchase ────────────────────────────────────────────────────────

    /**
     * GET /api/v1/ai/token-packs
     * Returns available packs for display in the UI.
     */
    public function tokenPacks(): JsonResponse
    {
        return $this->success(self::TOKEN_PACKS);
    }

    /**
     * POST /api/v1/ai/tokens/purchase
     * Body: { pack: 'starter'|'growth'|'pro' }
     *
     * Creates a pending Payment record. The admin approves it to release tokens.
     * Tokens are credited immediately so the user can proceed (admin can revoke if fraud).
     */
    public function purchaseTokens(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pack' => ['required', 'string', 'in:starter,growth,pro'],
        ]);

        $pack = self::TOKEN_PACKS[$data['pack']];
        $user = $request->user();

        // Create payment record (pending — admin will verify / Stripe integration future)
        $payment = Payment::create([
            'user_id' => $user->id,
            'amount' => $pack['price_usd'],
            'currency' => 'USD',
            'status' => 'pending',
            'reference' => 'ai-tokens-' . $data['pack'] . '-' . uniqid(),
            'metadata' => [
                'type' => 'ai_tokens',
                'pack' => $data['pack'],
                'tokens' => $pack['tokens'],
                'label' => $pack['label'],
            ],
        ]);

        // Credit tokens immediately — admin will follow up on payment
        $user->increment('ai_tokens', $pack['tokens']);

        return $this->success([
            'message' => "Successfully credited {$pack['tokens']} AI tokens to your account.",
            'ai_tokens' => $user->fresh()->ai_tokens,
            'payment_id' => $payment->id,
            'amount_usd' => $pack['price_usd'],
            'pack' => $data['pack'],
        ], 201);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function authorizedDomain(Request $request, int $domainId): Domain
    {
        return Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();
    }
}
