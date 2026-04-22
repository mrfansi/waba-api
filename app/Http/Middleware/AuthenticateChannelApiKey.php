<?php

namespace App\Http\Middleware;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Waba\Exceptions\UnauthorizedChannelException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateChannelApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $raw = $request->bearerToken() ?: $request->header('X-Api-Key');

        if ($raw === null || ! str_starts_with((string) $raw, 'wba_')) {
            throw new UnauthorizedChannelException('Missing API key');
        }

        $parts = explode('_', (string) $raw, 3);
        if (count($parts) !== 3) {
            throw new UnauthorizedChannelException('Malformed API key');
        }

        [, $prefixId, $secret] = $parts;
        $prefix = 'wba_'.$prefixId;

        $apiKey = ChannelApiKey::query()->active()->where('prefix', $prefix)->first();

        if (! $apiKey || ! hash_equals($apiKey->key_hash, hash('sha256', $secret))) {
            throw new UnauthorizedChannelException('Invalid API key');
        }

        $channelParam = (string) $request->route('channel');
        $channel = Channel::query()
            ->where('id', $apiKey->channel_id)
            ->where(fn ($q) => $q->where('name', $channelParam)->orWhere('id', $channelParam))
            ->first();

        if (! $channel) {
            throw new UnauthorizedChannelException('API key does not match channel');
        }

        $this->maybeTouchLastUsed($apiKey);

        $request->attributes->set('channel', $channel);
        $request->attributes->set('apiKey', $apiKey);

        return $next($request);
    }

    private function maybeTouchLastUsed(ChannelApiKey $key): void
    {
        $throttle = (int) config('waba.api_key.last_used_throttle_seconds', 60);
        if ($key->last_used_at && $key->last_used_at->diffInSeconds(now()) < $throttle) {
            return;
        }
        $key->forceFill(['last_used_at' => now()])->save();
    }
}
