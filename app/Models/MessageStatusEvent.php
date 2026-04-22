<?php

namespace App\Models;

use Database\Factories\MessageStatusEventFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageStatusEvent extends Model
{
    /** @use HasFactory<MessageStatusEventFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'status', 'occurred_at', 'raw_payload'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
