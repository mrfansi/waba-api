<?php

namespace App\Http\Middleware;

use App\Models\ChannelApiKey;
use App\Waba\Exceptions\InsufficientAbilityException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AssertAbility
{
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $apiKey = $request->attributes->get('apiKey');

        if (! $apiKey instanceof ChannelApiKey || ! $apiKey->tokenCan($ability)) {
            throw new InsufficientAbilityException($ability);
        }

        return $next($request);
    }
}
