<?php

namespace App\Waba\Outbound;

use App\Models\Message;
use App\Waba\Exceptions\PermanentSendException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public function __construct(public string $messageId)
    {
        $this->tries = (int) config('waba.outbound.retry.attempts', 3);
    }

    /** @return array<int,int> */
    public function backoff(): array
    {
        return (array) config('waba.outbound.retry.backoff_seconds', [30, 120, 600]);
    }

    public function handle(DispatchService $svc): void
    {
        $m = Message::findOrFail($this->messageId);

        try {
            $svc->attemptSend($m);
        } catch (PermanentSendException $e) {
            $this->fail($e);
        }
    }

    public function failed(Throwable $e): void
    {
        Message::where('id', $this->messageId)
            ->whereNotIn('status', ['sent', 'delivered', 'read', 'failed'])
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
    }
}
