<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Laravel-native realtime payload for tenant DM (Echo + Pusher-compatible websocket).
 */
class TenantChatMessageSent implements ShouldBroadcastNow {
    use Dispatchable, InteractsWithSockets;

    public function __construct(
        public string $tenantId,
        public int $senderId,
        public int $receiverId,
        public array $message,
    ) {}

    /**
     * @return array<int, \Illuminate\Broadcasting\PrivateChannel>
     */
    public function broadcastOn(): array {
        $a   = min( $this->senderId, $this->receiverId );
        $b   = max( $this->senderId, $this->receiverId );
        $key = $a . '_' . $b;

        return [
            new PrivateChannel( 'tenant.' . $this->tenantId . '.chat.' . $key ),
        ];
    }

    public function broadcastAs(): string {
        return 'TenantChatMessageSent';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array {
        return [
            'message' => $this->message,
        ];
    }
}
