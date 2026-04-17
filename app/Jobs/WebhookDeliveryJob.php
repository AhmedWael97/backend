<?php

namespace App\Jobs;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class WebhookDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 300, 900, 3600, 7200];

    public function __construct(
        public readonly int $webhookId,
        public readonly string $eventType,
        public readonly array $payload,
    ) {
    }

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);
        if (!$webhook || !$webhook->is_active) {
            return;
        }

        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_type' => $this->eventType,
            'payload' => $this->payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        $result = self::deliver($webhook, $this->payload, $delivery);

        if (!$result['ok']) {
            $this->release($this->backoff[$this->attempts()] ?? 7200);
        }
    }

    /**
     * Deliver synchronously and record in webhook_deliveries; returns result array.
     */
    public static function deliverSync(Webhook $webhook, array $payload): array
    {
        $delivery = WebhookDelivery::create([
            'webhook_id' => $webhook->id,
            'event_type' => 'test',
            'payload' => $payload,
            'status' => 'pending',
            'attempts' => 0,
        ]);

        return self::deliver($webhook, $payload, $delivery);
    }

    private static function deliver(Webhook $webhook, array $payload, WebhookDelivery $delivery): array
    {
        $body = json_encode($payload);
        $sig = hash_hmac('sha256', $body, (string) $webhook->secret);

        $attempt = ($delivery->attempts ?? 0) + 1;

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'X-Eye-Signature' => "sha256={$sig}",
                'X-Eye-Event' => $delivery->event_type,
            ])
                ->timeout(10)
                ->post($webhook->url, $payload);

            $ok = $response->successful();

            $delivery->update([
                'status' => $ok ? 'delivered' : 'failed',
                'attempts' => $attempt,
            ]);

            $webhook->update(['last_triggered_at' => now()]);

            return [
                'ok' => $ok,
                'status_code' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ];
        } catch (\Throwable $e) {
            $delivery->update([
                'status' => 'failed',
                'attempts' => $attempt,
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
