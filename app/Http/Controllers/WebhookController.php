<?php

namespace App\Http\Controllers;

use App\Jobs\WebhookDeliveryJob;
use App\Models\Domain;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    public function index(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return $this->success(Webhook::where('domain_id', $domain->id)->get());
    }

    public function store(Request $request, int $domainId): JsonResponse
    {
        $domain = Domain::where('id', $domainId)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $data = $request->validate([
            'url' => ['required', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string'],
            'is_active' => ['boolean'],
        ]);

        $webhook = Webhook::create([
            'domain_id' => $domain->id,
            'url' => $data['url'],
            'secret' => $data['secret'] ?? Str::random(32),
            'events' => $data['events'],
            'is_active' => $data['is_active'] ?? true,
        ]);

        return $this->success($webhook, 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $data = $request->validate([
            'url' => ['sometimes', 'url', 'max:500'],
            'secret' => ['nullable', 'string', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $webhook->update($data);

        return $this->success($webhook);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Webhook::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id)
            ->delete();

        return $this->success(['message' => 'Webhook deleted.']);
    }

    /**
     * GET /api/domains/{domain}/webhooks/{id}/logs  (or flat alias)
     */
    public function logs(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $deliveries = WebhookDelivery::where('webhook_id', $webhook->id)
            ->latest('delivered_at')
            ->take(50)
            ->get(['id', 'event', 'status_code', 'response_body', 'delivered_at']);

        return $this->success($deliveries);
    }

    /**
     * POST /api/webhooks/{id}/test — send a test payload synchronously.
     */
    public function test(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::whereHas('domain', fn($q) => $q->where('user_id', $request->user()->id))
            ->findOrFail($id);

        $payload = [
            'event' => 'test',
            'domain' => $webhook->domain->domain,
            'test' => true,
            'sent_at' => now()->toIso8601String(),
        ];

        $result = WebhookDeliveryJob::deliverSync($webhook, $payload);

        return $this->success(['result' => $result]);
    }
}
