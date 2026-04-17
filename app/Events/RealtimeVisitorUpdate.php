<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RealtimeVisitorUpdate implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $domainId,
        public readonly int $activeVisitors,
    ) {
    }

    public function broadcastOn(): Channel
    {
        return new PrivateChannel("domain.{$this->domainId}");
    }

    public function broadcastAs(): string
    {
        return 'visitor.update';
    }

    public function broadcastWith(): array
    {
        return [
            'domain_id' => $this->domainId,
            'active_visitors' => $this->activeVisitors,
            'ts' => now()->toIso8601String(),
        ];
    }
}
