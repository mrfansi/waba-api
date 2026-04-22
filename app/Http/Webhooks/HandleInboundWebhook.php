<?php

namespace App\Http\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandleInboundWebhook
{
    public function __invoke(Request $request, string $provider, string $channel): JsonResponse
    {
        return response()->json(['accepted' => true], 202);
    }
}
