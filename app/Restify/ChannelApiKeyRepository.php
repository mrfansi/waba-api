<?php

namespace App\Restify;

use App\Models\ChannelApiKey;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;
use Illuminate\Support\Str;

class ChannelApiKeyRepository extends Repository
{
    public static string $model = ChannelApiKey::class;

    public static array $match = ['channel_id' => 'string'];

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('channel_id')->required(),
            field('name')->required(),
            field('abilities')->required(),
            field('prefix')->readonly(),
            field('last_used_at')->datetime()->readonly(),
            field('expires_at')->datetime()->nullable(),
            field('revoked_at')->datetime()->nullable(),
            field('created_at')->datetime()->readonly(),
        ];
    }

    /**
     * Override store to inject server-generated prefix/key_hash before INSERT
     * and return the raw key once in the response body.
     *
     * prefix and key_hash are NOT NULL so they must be set before the parent
     * transaction calls model->save(). We pre-fill them on $this->resource,
     * then delegate to parent::store() which handles field filling, saving,
     * and calling the stored() hook. After that we build a custom response
     * that merges raw_key into the data payload.
     */
    public function store(RestifyRequest $request)
    {
        $secret = Str::random(40);
        $prefix = 'wba_'.Str::lower(Str::random(8));
        $rawKey = $prefix.'_'.$secret;

        // Pre-seed the model so the INSERT satisfies NOT NULL constraints.
        $this->resource = static::newModel();
        $this->resource->prefix = $prefix;
        $this->resource->key_hash = hash('sha256', $secret);

        // Let parent handle field filling, transaction, save, and stored() hook.
        parent::store($request);

        // Build the response payload with raw_key injected once.
        // serializeForShow returns ['id', 'type', 'attributes', ...] — the data()
        // helper wraps that in {'data': {...}} which matches the standard shape.
        $serialized = $this->serializeForShow($request);
        data_set($serialized, 'attributes.raw_key', $rawKey);

        return data($serialized, 201, ['Location' => static::uriTo($this->resource)]);
    }
}
