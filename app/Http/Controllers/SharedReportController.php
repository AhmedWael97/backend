<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\SharedReport;
use App\Services\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SharedReportController extends Controller
{
    public function __construct(private readonly AnalyticsQueryService $analytics)
    {
    }

    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success(
            SharedReport::where('domain_id', $domain->id)
                ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()
                ->get()
        );
    }

    public function listAll(Request $request): JsonResponse
    {
        $reports = SharedReport::where('user_id', $request->user()->id)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get();

        return $this->success($reports);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer'],
            'label' => ['required', 'string', 'max:120'],
            'allowed_pages' => ['nullable', 'array'],
            'expires_at' => ['nullable', 'date'],
        ]);

        Domain::where('id', $data['domain_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $report = SharedReport::create([
            'domain_id' => $data['domain_id'],
            'user_id' => $request->user()->id,
            'token' => Str::random(48),
            'label' => $data['label'],
            'allowed_pages' => $data['allowed_pages'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return $this->success($report, 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        SharedReport::where('user_id', $request->user()->id)
            ->findOrFail($id)
            ->delete();

        return $this->success(['message' => 'Shared report revoked.']);
    }

    /**
     * GET /api/public/report/{token}  — no auth required.
     */
    public function publicView(string $token): JsonResponse
    {
        $report = SharedReport::where('token', $token)
            ->where(fn($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->firstOrFail();

        $domain = $report->domain;
        $from = now()->subDays(30);
        $to = now();

        $snapshot = [
            'label' => $report->label,
            'domain' => $domain->domain,
            'allowed_pages' => $report->allowed_pages,
            'expires_at' => $report->expires_at,
            'stats' => $this->analytics->stats($domain->id, $from, $to, 'day'),
        ];

        return $this->success($snapshot);
    }
}
