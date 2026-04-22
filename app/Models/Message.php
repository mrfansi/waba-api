<?php

namespace App\Models;

use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'channel_id', 'direction', 'to_number', 'from_number', 'type', 'payload',
        'status', 'provider_message_id', 'idempotency_key', 'api_key_id',
        'error_code', 'error_message', 'request_payload', 'response_payload',
        'sent_at', 'delivered_at', 'read_at', 'failed_at', 'attempts',
    ];

    protected $hidden = ['request_payload', 'response_payload'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'request_payload' => 'array',
            'response_payload' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ChannelApiKey::class, 'api_key_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(MessageStatusEvent::class);
    }

    public function scopeOutbound(Builder $q): Builder
    {
        return $q->where('direction', 'outbound');
    }

    public function scopeInbound(Builder $q): Builder
    {
        return $q->where('direction', 'inbound');
    }

    public function scopePending(Builder $q): Builder
    {
        return $q->whereIn('status', ['pending', 'sending']);
    }

    public function scopeFailed(Builder $q): Builder
    {
        return $q->where('status', 'failed');
    }
}
