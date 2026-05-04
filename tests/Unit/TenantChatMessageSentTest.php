<?php

namespace Tests\Unit;

use App\Events\TenantChatMessageSent;
use Illuminate\Broadcasting\PrivateChannel;
use PHPUnit\Framework\TestCase;

class TenantChatMessageSentTest extends TestCase {
    public function test_broadcast_uses_sorted_pair_in_channel_name(): void {
        $event = new TenantChatMessageSent(
            'shop1',
            99,
            5,
            ['id' => 1, 'message' => 'hi'],
        );

        $channels = $event->broadcastOn();

        $this->assertCount( 1, $channels );
        $this->assertInstanceOf( PrivateChannel::class, $channels[0] );
        $this->assertSame( 'private-tenant.shop1.chat.5_99', $channels[0]->name );
    }

    public function test_broadcast_as_and_payload(): void {
        $payload = [
            'id'         => 10,
            'sender_id'  => 2,
            'receiver_id' => 7,
            'message'    => 'hello',
        ];
        $event = new TenantChatMessageSent( 't1', 2, 7, $payload );

        $this->assertSame( 'TenantChatMessageSent', $event->broadcastAs() );
        $this->assertSame( ['message' => $payload], $event->broadcastWith() );
    }
}
