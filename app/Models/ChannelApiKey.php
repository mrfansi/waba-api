<?php

namespace App\Models;

use Database\Factories\ChannelApiKeyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelApiKey extends Model
{
    /** @use HasFactory<ChannelApiKeyFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'channel_id', 'name', 'prefix', 'key_hash', 'abilities',
        'last_used_at', 'expires_at', 'revoked_at',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function tokenCan(string $ability): bool
    {
        return in_array('*', $this->abilities ?? [], true)
            || in_array($ability, $this->abilities ?? [], true);
    }
}
