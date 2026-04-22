<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;

class PurgeMessagePayloadsCommand extends Command
{
    protected $signature = 'waba:purge-payloads {--days= : Override retention days}';

    protected $description = 'Null out request_payload and response_payload for messages older than retention threshold';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('waba.outbound.retention.request_payload_days', 30));
        $cutoff = now()->subDays($days);

        $count = Message::where('created_at', '<', $cutoff)
            ->whereNotNull('request_payload')
            ->update([
                'request_payload' => null,
                'response_payload' => null,
            ]);

        $this->info("Purged payloads from {$count} messages older than {$days} days.");

        return self::SUCCESS;
    }
}
