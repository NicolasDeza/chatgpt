<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
// use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageStreamed implements ShouldBroadcastNow
{
    public function __construct(
        protected string $channel,
        protected string $content,
        protected bool $isComplete = false,
        protected bool $error = false
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel($this->channel),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.streamed';
    }

    public function broadcastWith(): array
    {
        return [
            'content'    => $this->content,
            'isComplete' => $this->isComplete,
            'error'      => $this->error,
        ];
    }
}
