# WABA Core Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship P1 (Core Foundation) of waba-api: driver-based WhatsApp provider abstraction, Channel + ChannelApiKey domain models, Restify-first admin surface, dual auth (Sanctum + per-channel API keys), Qiscus driver skeleton with working `probe()`.

**Architecture:** Laravel 13 + Restify 10. Driver contract `App\Waba\Contracts\MessageProvider`; resolution via `WabaManager`. Channels DB-backed with encrypted credentials. API keys hashed with `wba_<prefix>_<secret>` format. Sanctum guards admin (Restify repos); custom `channel.apikey` middleware guards server-to-server routes. Qiscus driver implements `name`/`bind`/`probe` in P1 — all other contract methods throw `DriverException::notImplemented()` to be filled in P2–P6.

**Tech Stack:** PHP 8.3, Laravel 13, Restify 10.4, Sanctum 4, Pest 4, SQLite (tests) / MySQL (prod), Pint.

**Spec reference:** `docs/superpowers/specs/2026-04-22-waba-core-foundation-design.md`

---

## File Structure

Domain root `app/Waba/`:
- `Contracts/MessageProvider.php` — driver interface (all 6 sub-project methods declared)
- `Drivers/QiscusDriver.php` — concrete driver, only `probe()` functional
- `Support/WabaManager.php` — resolves channel slug → bound driver
- `Support/ChannelResolver.php` — single seam channel row → driver instance
- `Facades/Waba.php` — static facade over manager
- `Testing/FakeProvider.php` — spy for `Waba::fake()`
- `Dto/*.php` — readonly DTOs (skeletons for later sub-projects)
- `Exceptions/*.php` — typed exception hierarchy

HTTP layer:
- `app/Http/Middleware/AssignRequestId.php` — global, adds ULID + header
- `app/Http/Middleware/AuthenticateChannelApiKey.php` — route group guard
- `app/Http/Webhooks/HandleInboundWebhook.php` — invokable stub (P3 fills)

Restify:
- `app/Restify/ChannelRepository.php` + `ChannelApiKeyRepository.php`
- `app/Restify/Actions/ProbeChannelAction.php`

Domain:
- `app/Models/Channel.php`, `app/Models/ChannelApiKey.php`
- `database/factories/ChannelFactory.php`, `ChannelApiKeyFactory.php`
- `database/migrations/2026_04_22_000001_create_channels_table.php`
- `database/migrations/2026_04_22_000002_create_channel_api_keys_table.php`

Config & providers:
- `config/waba.php`
- `app/Providers/WabaServiceProvider.php`
- `bootstrap/app.php` — register middleware + exception map
- `bootstrap/providers.php` — register WabaServiceProvider
- `.env.example` — new vars
- `config/restify.php` — enable `auth:sanctum`
- `routes/api.php` — webhook stub + API-key test route

Tests:
- `tests/Feature/Restify/ChannelCrudTest.php`
- `tests/Feature/Restify/ChannelApiKeyCrudTest.php`
- `tests/Feature/Restify/ProbeChannelActionTest.php`
- `tests/Feature/Auth/ChannelApiKeyAuthTest.php`
- `tests/Feature/Webhooks/WebhookStubTest.php`
- `tests/Unit/Waba/WabaManagerTest.php`
- `tests/Unit/Waba/QiscusDriverTest.php`
- `tests/Unit/Waba/ChannelResolverTest.php`
- `tests/Arch/DriverIsolationTest.php`

---

## Task 1: Config scaffolding

**Files:**
- Create: `config/waba.php`
- Modify: `.env.example`

- [ ] **Step 1: Write `config/waba.php`**

```php
<?php

return [
    'default' => env('WABA_DEFAULT_CHANNEL'),

    'providers' => [
        'qiscus' => [
            'class' => \App\Waba\Drivers\QiscusDriver::class,
            'base_url' => env('QISCUS_BASE_URL', 'https://multichannel.qiscus.com'),
            'timeout' => 15,
            'retries' => 2,
        ],
    ],

    'api_key' => [
        'prefix' => 'wba',
        'header' => 'Authorization',
        'last_used_throttle_seconds' => 60,
    ],

    'media' => [
        'disk' => env('WABA_MEDIA_DISK', 'local'),
        'path' => 'waba/media',
        'retention_days' => 30,
    ],

    'inbound' => [
        'store_raw' => true,
        'fanout' => [
            'webhook' => true,
            'broadcasting' => false,
            'polling' => true,
        ],
    ],

    'outbound' => [
        'default_mode' => 'queue',
        'queue_connection' => env('WABA_QUEUE', 'default'),
        'queue_name' => 'waba-outbound',
    ],

    'rate_limit' => [
        'channel_api_per_minute' => 600,
    ],
];
```

- [ ] **Step 2: Append to `.env.example`**

```
# WABA
WABA_DEFAULT_CHANNEL=
QISCUS_BASE_URL=https://multichannel.qiscus.com
WABA_MEDIA_DISK=local
WABA_QUEUE=default
```

- [ ] **Step 3: Commit**

```bash
git add config/waba.php .env.example
git commit -m "feat(waba): add core config scaffolding"
```

---

## Task 2: Channels migration, model, factory

**Files:**
- Create: `database/migrations/2026_04_22_000001_create_channels_table.php`
- Create: `app/Models/Channel.php`
- Create: `database/factories/ChannelFactory.php`

- [ ] **Step 1: Generate migration**

Run: `php artisan make:migration create_channels_table --no-interaction`

Replace generated migration body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name', 64)->unique();
            $table->string('display_name', 128);
            $table->string('driver', 32);
            $table->string('phone_number', 32);
            $table->string('phone_number_id', 64)->nullable();
            $table->text('credentials');
            $table->string('webhook_secret', 64);
            $table->json('settings')->nullable();
            $table->enum('status', ['active', 'disabled', 'pending'])->default('pending');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['driver', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
```

- [ ] **Step 2: Create model `app/Models/Channel.php`**

```php
<?php

namespace App\Models;

use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    protected $fillable = [
        'name',
        'display_name',
        'driver',
        'phone_number',
        'phone_number_id',
        'credentials',
        'webhook_secret',
        'settings',
        'status',
    ];

    protected $hidden = ['credentials', 'webhook_secret'];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => AsArrayObject::class,
            'last_verified_at' => 'datetime',
        ];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ChannelApiKey::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
```

- [ ] **Step 3: Create factory `database/factories/ChannelFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Channel> */
class ChannelFactory extends Factory
{
    protected $model = Channel::class;

    public function definition(): array
    {
        return [
            'name' => 'ch-'.Str::random(8),
            'display_name' => fake()->company(),
            'driver' => 'qiscus',
            'phone_number' => '+628'.fake()->numerify('##########'),
            'phone_number_id' => null,
            'credentials' => ['app_id' => Str::random(16), 'secret_key' => Str::random(32)],
            'webhook_secret' => Str::random(32),
            'settings' => [],
            'status' => 'active',
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }
}
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate --no-interaction`
Expected: `Migrated: 2026_04_22_000001_create_channels_table`

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/Channel.php database/factories/ChannelFactory.php
git commit -m "feat(waba): add channels table, model, factory"
```

---

## Task 3: ChannelApiKey migration, model, factory

**Files:**
- Create: `database/migrations/2026_04_22_000002_create_channel_api_keys_table.php`
- Create: `app/Models/ChannelApiKey.php`
- Create: `database/factories/ChannelApiKeyFactory.php`

- [ ] **Step 1: Write migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_api_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('prefix', 12)->unique();
            $table->string('key_hash', 64);
            $table->json('abilities');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['channel_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_api_keys');
    }
};
```

- [ ] **Step 2: Write model `app/Models/ChannelApiKey.php`**

```php
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
```

- [ ] **Step 3: Write factory `database/factories/ChannelApiKeyFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<ChannelApiKey> */
class ChannelApiKeyFactory extends Factory
{
    protected $model = ChannelApiKey::class;

    public function definition(): array
    {
        $prefix = Str::lower(Str::random(8));
        $secret = Str::random(40);

        return [
            'channel_id' => Channel::factory(),
            'name' => fake()->words(2, true),
            'prefix' => 'wba_'.$prefix,
            'key_hash' => hash('sha256', $secret),
            'abilities' => ['*'],
        ];
    }

    public function revoked(): static
    {
        return $this->state(['revoked_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subDay()]);
    }
}
```

- [ ] **Step 4: Run migration**

Run: `php artisan migrate --no-interaction`

- [ ] **Step 5: Commit**

```bash
git add database/migrations app/Models/ChannelApiKey.php database/factories/ChannelApiKeyFactory.php
git commit -m "feat(waba): add channel_api_keys table, model, factory"
```

---

## Task 4: Exception hierarchy

**Files:**
- Create: `app/Waba/Exceptions/WabaException.php`
- Create: `app/Waba/Exceptions/ChannelNotFoundException.php`
- Create: `app/Waba/Exceptions/UnauthorizedChannelException.php`
- Create: `app/Waba/Exceptions/InsufficientAbilityException.php`
- Create: `app/Waba/Exceptions/DriverException.php`
- Create: `app/Waba/Exceptions/DriverTimeoutException.php`

- [ ] **Step 1: Base exception**

```php
<?php

namespace App\Waba\Exceptions;

use RuntimeException;

abstract class WabaException extends RuntimeException
{
    abstract public function errorCode(): string;

    abstract public function httpStatus(): int;

    /** @return array<string,mixed> */
    public function details(): array
    {
        return [];
    }
}
```

- [ ] **Step 2: ChannelNotFoundException**

```php
<?php

namespace App\Waba\Exceptions;

class ChannelNotFoundException extends WabaException
{
    public static function slug(string $slug): self
    {
        return new self("Channel [{$slug}] not found.");
    }

    public function errorCode(): string
    {
        return 'channel_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
```

- [ ] **Step 3: UnauthorizedChannelException**

```php
<?php

namespace App\Waba\Exceptions;

class UnauthorizedChannelException extends WabaException
{
    public function errorCode(): string
    {
        return 'unauthorized';
    }

    public function httpStatus(): int
    {
        return 401;
    }
}
```

- [ ] **Step 4: InsufficientAbilityException**

```php
<?php

namespace App\Waba\Exceptions;

class InsufficientAbilityException extends WabaException
{
    public function __construct(private string $ability)
    {
        parent::__construct("Missing ability: {$ability}");
    }

    public function errorCode(): string
    {
        return 'forbidden';
    }

    public function httpStatus(): int
    {
        return 403;
    }

    public function details(): array
    {
        return ['required_ability' => $this->ability];
    }
}
```

- [ ] **Step 5: DriverException + DriverTimeoutException**

`DriverException.php`:
```php
<?php

namespace App\Waba\Exceptions;

class DriverException extends WabaException
{
    /** @param array<string,mixed> $meta */
    public function __construct(string $message, private array $meta = [])
    {
        parent::__construct($message);
    }

    public static function notImplemented(string $method): self
    {
        return new self("Method {$method} not implemented in P1.", ['stage' => 'p1_stub']);
    }

    public function errorCode(): string
    {
        return 'provider_error';
    }

    public function httpStatus(): int
    {
        return 502;
    }

    public function details(): array
    {
        return $this->meta;
    }
}
```

`DriverTimeoutException.php`:
```php
<?php

namespace App\Waba\Exceptions;

class DriverTimeoutException extends DriverException
{
    public function errorCode(): string
    {
        return 'provider_timeout';
    }

    public function httpStatus(): int
    {
        return 504;
    }
}
```

- [ ] **Step 6: Commit**

```bash
git add app/Waba/Exceptions
git commit -m "feat(waba): add exception hierarchy"
```

---

## Task 5: DTO skeletons

**Files:**
- Create all under `app/Waba/Dto/`: `ChannelCredentials.php`, `OutboundMessage.php`, `SendResult.php`, `NormalizedInboundEvent.php`, `MediaReference.php`, `MediaUpload.php`, `TemplateDefinition.php`, `TemplateSyncResult.php`

- [ ] **Step 1: ChannelCredentials**

```php
<?php

namespace App\Waba\Dto;

final readonly class ChannelCredentials
{
    /** @param array<string,mixed> $data */
    public function __construct(
        public string $driver,
        public string $channelId,
        public array $data,
        public string $webhookSecret,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }
}
```

- [ ] **Step 2: OutboundMessage**

```php
<?php

namespace App\Waba\Dto;

final readonly class OutboundMessage
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $to, public string $type, public array $payload = []) {}
}
```

- [ ] **Step 3: SendResult**

```php
<?php

namespace App\Waba\Dto;

final readonly class SendResult
{
    /** @param array<string,mixed> $raw */
    public function __construct(public string $providerMessageId, public string $status, public array $raw = []) {}
}
```

- [ ] **Step 4: NormalizedInboundEvent**

```php
<?php

namespace App\Waba\Dto;

final readonly class NormalizedInboundEvent
{
    /** @param array<string,mixed> $payload */
    public function __construct(public string $type, public array $payload) {}
}
```

- [ ] **Step 5: MediaReference**

```php
<?php

namespace App\Waba\Dto;

final readonly class MediaReference
{
    public function __construct(public string $id, public string $mime, public ?string $url = null) {}
}
```

- [ ] **Step 6: MediaUpload**

```php
<?php

namespace App\Waba\Dto;

final readonly class MediaUpload
{
    public function __construct(public string $path, public string $mime, public string $filename) {}
}
```

- [ ] **Step 7: TemplateDefinition**

```php
<?php

namespace App\Waba\Dto;

final readonly class TemplateDefinition
{
    /** @param array<int,array<string,mixed>> $components */
    public function __construct(public string $name, public string $language, public array $components = []) {}
}
```

- [ ] **Step 8: TemplateSyncResult**

```php
<?php

namespace App\Waba\Dto;

final readonly class TemplateSyncResult
{
    public function __construct(public string $providerTemplateId, public string $status) {}
}
```

- [ ] **Step 9: Commit**

```bash
git add app/Waba/Dto
git commit -m "feat(waba): add DTO skeletons"
```

---

## Task 6: MessageProvider contract

**Files:**
- Create: `app/Waba/Contracts/MessageProvider.php`

- [ ] **Step 1: Write contract**

```php
<?php

namespace App\Waba\Contracts;

use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;

interface MessageProvider
{
    public function name(): string;

    public function bind(ChannelCredentials $credentials): static;

    public function probe(): bool;

    // P2
    public function send(OutboundMessage $message): SendResult;

    // P3
    /** @param array<string,string|array<int,string>> $headers */
    public function verifyWebhookSignature(string $payload, array $headers): bool;

    /** @param array<string,mixed> $rawPayload */
    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent;

    // P4
    /** @return array<int,TemplateDefinition> */
    public function listTemplates(): array;

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult;

    public function deleteTemplate(string $providerTemplateId): void;

    // P5
    public function uploadMedia(MediaUpload $upload): MediaReference;

    public function downloadMedia(string $providerMediaId): MediaReference;
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Waba/Contracts/MessageProvider.php
git commit -m "feat(waba): add MessageProvider contract"
```

---

## Task 7: QiscusDriver skeleton — test first

**Files:**
- Test: `tests/Unit/Waba/QiscusDriverTest.php`
- Create: `app/Waba/Drivers/QiscusDriver.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

use App\Waba\Drivers\QiscusDriver;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->creds = new ChannelCredentials(
        driver: 'qiscus',
        channelId: '01HZ-test',
        data: ['app_id' => 'app123', 'secret_key' => 'sec123'],
        webhookSecret: 'whsec',
    );
});

it('returns name', function () {
    expect((new QiscusDriver())->name())->toBe('qiscus');
});

it('binds credentials immutably', function () {
    $a = new QiscusDriver();
    $b = $a->bind($this->creds);
    expect($b)->not->toBe($a);
});

it('probes successfully', function () {
    Http::fake([
        '*' => Http::response(['app' => ['id' => 'app123']], 200),
    ]);

    $driver = (new QiscusDriver())->bind($this->creds);
    expect($driver->probe())->toBeTrue();
});

it('probe returns false on http failure', function () {
    Http::fake(['*' => Http::response([], 500)]);

    $driver = (new QiscusDriver())->bind($this->creds);
    expect($driver->probe())->toBeFalse();
});

it('throws not-implemented for unimplemented methods', function () {
    $driver = (new QiscusDriver())->bind($this->creds);
    $driver->send(new OutboundMessage('+62', 'text'));
})->throws(DriverException::class, 'not implemented');
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Unit/Waba/QiscusDriverTest.php`
Expected: FAIL (class missing)

- [ ] **Step 3: Write driver**

```php
<?php

namespace App\Waba\Drivers;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;
use App\Waba\Exceptions\DriverException;
use Illuminate\Support\Facades\Http;
use Throwable;

class QiscusDriver implements MessageProvider
{
    private ?ChannelCredentials $credentials = null;

    public function name(): string
    {
        return 'qiscus';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $clone = clone $this;
        $clone->credentials = $credentials;

        return $clone;
    }

    public function probe(): bool
    {
        $creds = $this->requireCredentials();

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders([
                    'Qiscus-App-Id' => (string) $creds->get('app_id'),
                    'Qiscus-Secret-Key' => (string) $creds->get('secret_key'),
                    'Accept' => 'application/json',
                ])
                ->get(rtrim((string) config('waba.providers.qiscus.base_url'), '/').'/api/v2/app/config');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function send(OutboundMessage $message): SendResult
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function listTemplates(): array
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function deleteTemplate(string $providerTemplateId): void
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function uploadMedia(MediaUpload $upload): MediaReference
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function downloadMedia(string $providerMediaId): MediaReference
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    private function requireCredentials(): ChannelCredentials
    {
        if ($this->credentials === null) {
            throw new DriverException('QiscusDriver called without bound credentials');
        }

        return $this->credentials;
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

Run: `vendor/bin/pest tests/Unit/Waba/QiscusDriverTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Waba/Drivers tests/Unit/Waba/QiscusDriverTest.php
git commit -m "feat(waba): add QiscusDriver skeleton with probe"
```

---

## Task 8: ChannelResolver — test first

**Files:**
- Test: `tests/Unit/Waba/ChannelResolverTest.php`
- Create: `app/Waba/Support/ChannelResolver.php`

- [ ] **Step 1: Write test**

```php
<?php

use App\Models\Channel;
use App\Waba\Drivers\QiscusDriver;
use App\Waba\Exceptions\ChannelNotFoundException;
use App\Waba\Support\ChannelResolver;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('resolves slug to bound driver', function () {
    Channel::factory()->create(['name' => 'sales']);

    $driver = app(ChannelResolver::class)->resolve('sales');

    expect($driver)->toBeInstanceOf(QiscusDriver::class);
});

it('throws when channel not found', function () {
    app(ChannelResolver::class)->resolve('missing');
})->throws(ChannelNotFoundException::class);

it('throws when channel disabled', function () {
    Channel::factory()->create(['name' => 'off', 'status' => 'disabled']);
    app(ChannelResolver::class)->resolve('off');
})->throws(ChannelNotFoundException::class);
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Unit/Waba/ChannelResolverTest.php`

- [ ] **Step 3: Write resolver**

```php
<?php

namespace App\Waba\Support;

use App\Models\Channel;
use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Exceptions\ChannelNotFoundException;
use App\Waba\Exceptions\DriverException;
use Illuminate\Contracts\Container\Container;

class ChannelResolver
{
    public function __construct(private Container $container) {}

    public function resolve(string $slug): MessageProvider
    {
        $channel = Channel::query()
            ->where('name', $slug)
            ->where('status', 'active')
            ->first();

        if (! $channel) {
            throw ChannelNotFoundException::slug($slug);
        }

        return $this->fromModel($channel);
    }

    public function fromModel(Channel $channel): MessageProvider
    {
        $class = config("waba.providers.{$channel->driver}.class");

        if (! $class || ! class_exists($class)) {
            throw new DriverException("No driver registered for [{$channel->driver}]");
        }

        /** @var MessageProvider $driver */
        $driver = $this->container->make($class);

        return $driver->bind(new ChannelCredentials(
            driver: $channel->driver,
            channelId: (string) $channel->id,
            data: $channel->credentials ?? [],
            webhookSecret: $channel->webhook_secret,
        ));
    }
}
```

- [ ] **Step 4: Run tests — expect PASS**

- [ ] **Step 5: Commit**

```bash
git add app/Waba/Support/ChannelResolver.php tests/Unit/Waba/ChannelResolverTest.php
git commit -m "feat(waba): add ChannelResolver"
```

---

## Task 9: WabaManager, Facade, FakeProvider

**Files:**
- Create: `app/Waba/Support/WabaManager.php`
- Create: `app/Waba/Facades/Waba.php`
- Create: `app/Waba/Testing/FakeProvider.php`
- Test: `tests/Unit/Waba/WabaManagerTest.php`

- [ ] **Step 1: Write test**

```php
<?php

use App\Models\Channel;
use App\Waba\Contracts\MessageProvider;
use App\Waba\Facades\Waba;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('resolves channel via facade', function () {
    Channel::factory()->create(['name' => 'main']);

    expect(Waba::channel('main'))->toBeInstanceOf(MessageProvider::class);
});

it('fake replaces resolution', function () {
    Channel::factory()->create(['name' => 'main']);

    $fake = Waba::fake();

    expect(Waba::channel('main'))->toBe($fake);
});
```

- [ ] **Step 2: Write FakeProvider**

```php
<?php

namespace App\Waba\Testing;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;

class FakeProvider implements MessageProvider
{
    /** @var array<int,array{method:string,args:array<int,mixed>}> */
    public array $calls = [];

    public bool $probeResult = true;

    public function name(): string
    {
        return 'fake';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $this->record(__FUNCTION__, [$credentials]);

        return $this;
    }

    public function probe(): bool
    {
        $this->record(__FUNCTION__, []);

        return $this->probeResult;
    }

    public function send(OutboundMessage $message): SendResult
    {
        $this->record(__FUNCTION__, [$message]);

        return new SendResult('fake-id', 'queued');
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $this->record(__FUNCTION__, [$payload, $headers]);

        return true;
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        $this->record(__FUNCTION__, [$rawPayload]);

        return new NormalizedInboundEvent('message', $rawPayload);
    }

    public function listTemplates(): array
    {
        return [];
    }

    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult
    {
        return new TemplateSyncResult('fake-tpl', 'submitted');
    }

    public function deleteTemplate(string $providerTemplateId): void {}

    public function uploadMedia(MediaUpload $upload): MediaReference
    {
        return new MediaReference('fake-media', $upload->mime);
    }

    public function downloadMedia(string $providerMediaId): MediaReference
    {
        return new MediaReference($providerMediaId, 'application/octet-stream');
    }

    /** @param array<int,mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
```

- [ ] **Step 3: Write WabaManager**

```php
<?php

namespace App\Waba\Support;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Testing\FakeProvider;
use Illuminate\Contracts\Container\Container;

class WabaManager
{
    private ?FakeProvider $fake = null;

    public function __construct(
        private Container $container,
        private ChannelResolver $resolver,
    ) {}

    public function channel(string $slug): MessageProvider
    {
        if ($this->fake !== null) {
            return $this->fake;
        }

        return $this->resolver->resolve($slug);
    }

    public function fake(): FakeProvider
    {
        return $this->fake = new FakeProvider();
    }
}
```

- [ ] **Step 4: Write Facade**

```php
<?php

namespace App\Waba\Facades;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Support\WabaManager;
use App\Waba\Testing\FakeProvider;
use Illuminate\Support\Facades\Facade;

/**
 * @method static MessageProvider channel(string $slug)
 * @method static FakeProvider fake()
 */
class Waba extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WabaManager::class;
    }
}
```

- [ ] **Step 5: Commit (tests pass after Task 10)**

```bash
git add app/Waba/Support/WabaManager.php app/Waba/Facades app/Waba/Testing tests/Unit/Waba/WabaManagerTest.php
git commit -m "feat(waba): add WabaManager, Waba facade, FakeProvider"
```

---

## Task 10: WabaServiceProvider + register

**Files:**
- Create: `app/Providers/WabaServiceProvider.php`
- Modify: `bootstrap/providers.php`

- [ ] **Step 1: Write provider**

```php
<?php

namespace App\Providers;

use App\Waba\Support\WabaManager;
use Illuminate\Support\ServiceProvider;

class WabaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WabaManager::class);
    }

    public function boot(): void
    {
        //
    }
}
```

- [ ] **Step 2: Read existing `bootstrap/providers.php` and add WabaServiceProvider**

Read current content. If it returns `[]`, replace with:

```php
<?php

return [
    App\Providers\WabaServiceProvider::class,
];
```

If it already contains providers, append `App\Providers\WabaServiceProvider::class,` as a new array entry.

- [ ] **Step 3: Run Waba unit tests**

Run: `vendor/bin/pest tests/Unit/Waba`
Expected: all PASS (WabaManager, ChannelResolver, QiscusDriver tests).

- [ ] **Step 4: Commit**

```bash
git add app/Providers/WabaServiceProvider.php bootstrap/providers.php
git commit -m "feat(waba): register WabaServiceProvider"
```

---

## Task 11: AssignRequestId middleware

**Files:**
- Create: `app/Http/Middleware/AssignRequestId.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 1: Write middleware**

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $request->headers->get('X-Request-Id') ?: (string) Str::ulid();
        $request->attributes->set('request_id', $id);

        Log::withContext(['request_id' => $id]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
```

- [ ] **Step 2: Register globally in `bootstrap/app.php`**

Replace the `->withMiddleware(function (Middleware $middleware): void { // })` block with:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(\App\Http\Middleware\AssignRequestId::class);
})
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Middleware/AssignRequestId.php bootstrap/app.php
git commit -m "feat(waba): add AssignRequestId middleware"
```

---

## Task 12: AuthenticateChannelApiKey middleware — test first

**Files:**
- Test: `tests/Feature/Auth/ChannelApiKeyAuthTest.php`
- Create: `app/Http/Middleware/AuthenticateChannelApiKey.php`
- Modify: `bootstrap/app.php` (middleware alias + exception renderer)
- Modify: `routes/api.php` (test route)

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function createKey(Channel $channel, array $state = []): array
{
    $prefix = 'wba_'.Str::lower(Str::random(8));
    $secret = Str::random(40);
    $key = ChannelApiKey::factory()->for($channel)->create(array_merge([
        'prefix' => $prefix,
        'key_hash' => hash('sha256', $secret),
    ], $state));

    return [$key, $prefix.'_'.$secret];
}

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'ch1']);
});

it('rejects missing token', function () {
    $this->getJson('/api/v1/channels/ch1/ping')->assertStatus(401);
});

it('accepts valid token', function () {
    [, $raw] = createKey($this->channel);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertOk();
});

it('rejects wrong channel', function () {
    $other = Channel::factory()->create(['name' => 'ch2']);
    [, $raw] = createKey($other);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects revoked', function () {
    [, $raw] = createKey($this->channel, ['revoked_at' => now()]);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects expired', function () {
    [, $raw] = createKey($this->channel, ['expires_at' => now()->subDay()]);

    $this->withHeader('Authorization', "Bearer {$raw}")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});

it('rejects bad secret', function () {
    [$key] = createKey($this->channel);

    $this->withHeader('Authorization', "Bearer {$key->prefix}_zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz")
        ->getJson('/api/v1/channels/ch1/ping')
        ->assertStatus(401);
});
```

- [ ] **Step 2: Run — expect fail**

Run: `vendor/bin/pest tests/Feature/Auth/ChannelApiKeyAuthTest.php`

- [ ] **Step 3: Write middleware**

```php
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
```

- [ ] **Step 4: Update `bootstrap/app.php`**

Extend middleware block and add exception renderer:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(\App\Http\Middleware\AssignRequestId::class);
    $middleware->alias([
        'channel.apikey' => \App\Http\Middleware\AuthenticateChannelApiKey::class,
    ]);
})
->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (\App\Waba\Exceptions\WabaException $e, \Illuminate\Http\Request $request) {
        return response()->json([
            'error' => [
                'code' => $e->errorCode(),
                'message' => $e->getMessage(),
                'details' => $e->details(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $e->httpStatus());
    });
})
```

- [ ] **Step 5: Add test route in `routes/api.php`**

Append:

```php
Route::middleware('channel.apikey')
    ->prefix('v1/channels/{channel}')
    ->group(function () {
        Route::get('/ping', fn () => response()->json(['ok' => true]));
    });
```

- [ ] **Step 6: Run tests — expect PASS**

Run: `vendor/bin/pest tests/Feature/Auth/ChannelApiKeyAuthTest.php`

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/AuthenticateChannelApiKey.php bootstrap/app.php routes/api.php tests/Feature/Auth
git commit -m "feat(waba): add channel API key auth middleware and exception renderer"
```

---

## Task 13: Enable Sanctum on Restify base middleware

**Files:**
- Modify: `config/restify.php`

- [ ] **Step 1: Enable `auth:sanctum` in `config/restify.php`**

Find the `'middleware' => [ ... ]` array (around line 129). Change:

```php
'middleware' => [
    'api',
    // 'auth:sanctum',
    DispatchRestifyStartingEvent::class,
    AuthorizeRestify::class,
],
```

to:

```php
'middleware' => [
    'api',
    'auth:sanctum',
    DispatchRestifyStartingEvent::class,
    AuthorizeRestify::class,
],
```

- [ ] **Step 2: Commit**

```bash
git add config/restify.php
git commit -m "feat(waba): enforce Sanctum on Restify base middleware"
```

---

## Task 14: ChannelRepository (Restify) — test first

**Files:**
- Test: `tests/Feature/Restify/ChannelCrudTest.php`
- Create: `app/Restify/ChannelRepository.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists channels', function () {
    Channel::factory()->count(2)->create();

    $this->getJson('/api/restify/channels')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('creates a channel', function () {
    $payload = [
        'name' => 'promo',
        'display_name' => 'Promo Channel',
        'driver' => 'qiscus',
        'phone_number' => '+628111111111',
        'credentials' => ['app_id' => 'a', 'secret_key' => 's'],
        'webhook_secret' => str_repeat('x', 32),
        'status' => 'active',
    ];

    $this->postJson('/api/restify/channels', $payload)->assertCreated();

    expect(Channel::where('name', 'promo')->exists())->toBeTrue();
});

it('hides credentials in response', function () {
    $channel = Channel::factory()->create();

    $response = $this->getJson("/api/restify/channels/{$channel->id}")->assertOk()->json();
    expect(data_get($response, 'data.attributes'))->not->toHaveKey('credentials');
});
```

- [ ] **Step 2: Write repository**

```php
<?php

namespace App\Restify;

use App\Models\Channel;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;

class ChannelRepository extends Repository
{
    public static string $model = Channel::class;

    public static array $search = ['name', 'display_name', 'phone_number'];

    public static array $match = ['driver' => 'string', 'status' => 'string'];

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('name')->required(),
            field('display_name')->required(),
            field('driver')->required(),
            field('phone_number')->required(),
            field('phone_number_id')->nullable(),
            field('credentials')->storable()->hidden(),
            field('webhook_secret')->storable()->hidden(),
            field('settings')->nullable(),
            field('status')->rules('in:active,disabled,pending'),
            field('last_verified_at')->datetime()->readonly(),
            field('created_at')->datetime()->readonly(),
            field('updated_at')->datetime()->readonly(),
        ];
    }
}
```

> If `->hidden()` does not exist in the installed Restify version, inspect `vendor/binaryk/laravel-restify/src/Fields/Field.php`. Alternative: remove `credentials`/`webhook_secret` from `fields()` entirely and persist them via an overridden `store()`/`update()` method that reads from `$request->input()` directly. The `$hidden` property on the model already blocks serialization; the test `hides credentials in response` must still pass either way.

- [ ] **Step 3: Run tests — iterate until green**

Run: `vendor/bin/pest tests/Feature/Restify/ChannelCrudTest.php`

- [ ] **Step 4: Commit**

```bash
git add app/Restify/ChannelRepository.php tests/Feature/Restify/ChannelCrudTest.php
git commit -m "feat(waba): add ChannelRepository with Restify CRUD"
```

---

## Task 15: ChannelApiKeyRepository — generate-once raw key

**Files:**
- Test: `tests/Feature/Restify/ChannelApiKeyCrudTest.php`
- Create: `app/Restify/ChannelApiKeyRepository.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $this->channel = Channel::factory()->create();
});

it('creates api key and returns raw once', function () {
    $response = $this->postJson('/api/restify/channel-api-keys', [
        'channel_id' => $this->channel->id,
        'name' => 'default',
        'abilities' => ['messages:send', 'messages:read'],
    ])->assertCreated()->json();

    $raw = data_get($response, 'data.attributes.raw_key') ?? data_get($response, 'data.raw_key');
    expect($raw)->toStartWith('wba_')->and(substr_count((string) $raw, '_'))->toBe(2);

    $id = data_get($response, 'data.id');
    $show = $this->getJson("/api/restify/channel-api-keys/{$id}")->json();
    expect(data_get($show, 'data.attributes'))->not->toHaveKey('raw_key')
        ->and(data_get($show, 'data.attributes'))->not->toHaveKey('key_hash');
});

it('revoke sets revoked_at', function () {
    $key = ChannelApiKey::factory()->for($this->channel)->create();

    $this->patchJson("/api/restify/channel-api-keys/{$key->id}", [
        'revoked_at' => now()->toIso8601String(),
    ])->assertOk();

    expect($key->fresh()->revoked_at)->not->toBeNull();
});
```

- [ ] **Step 2: Write repository**

```php
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

    public static function stored($repository, $request): void
    {
        /** @var ChannelApiKey $model */
        $model = $repository->resource;
        $prefix = 'wba_'.Str::lower(Str::random(8));
        $secret = Str::random(40);

        $model->forceFill([
            'prefix' => $prefix,
            'key_hash' => hash('sha256', $secret),
        ])->save();

        $model->setAttribute('raw_key', $prefix.'_'.$secret);
    }
}
```

> If `stored` hook signature differs in the installed Restify version, read `vendor/binaryk/laravel-restify/src/Repositories/Repository.php` for the correct extension point. Fallback: override `store()` method — call `parent::store()`, then apply the same forceFill + `setAttribute('raw_key', ...)` before returning the response.

- [ ] **Step 3: Run tests — iterate until green**

Run: `vendor/bin/pest tests/Feature/Restify/ChannelApiKeyCrudTest.php`

- [ ] **Step 4: Commit**

```bash
git add app/Restify/ChannelApiKeyRepository.php tests/Feature/Restify/ChannelApiKeyCrudTest.php
git commit -m "feat(waba): add ChannelApiKeyRepository with generate-once key"
```

---

## Task 16: ProbeChannelAction — test first

**Files:**
- Test: `tests/Feature/Restify/ProbeChannelActionTest.php`
- Create: `app/Restify/Actions/ProbeChannelAction.php`
- Modify: `app/Restify/ChannelRepository.php`

- [ ] **Step 1: Write test**

```php
<?php

use App\Models\Channel;
use App\Models\User;
use App\Waba\Facades\Waba;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('invokes probe via action', function () {
    $channel = Channel::factory()->create();
    $fake = Waba::fake();
    $fake->probeResult = true;

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=probe-channel")
        ->assertOk()
        ->json();

    expect(data_get($response, 'data.ok'))->toBeTrue();
});

it('reports failure when probe returns false', function () {
    $channel = Channel::factory()->create();
    $fake = Waba::fake();
    $fake->probeResult = false;

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=probe-channel")
        ->assertOk()
        ->json();

    expect(data_get($response, 'data.ok'))->toBeFalse();
});
```

- [ ] **Step 2: Write action**

```php
<?php

namespace App\Restify\Actions;

use App\Models\Channel;
use App\Waba\Facades\Waba;
use Binaryk\LaravelRestify\Actions\Action;
use Binaryk\LaravelRestify\Http\Requests\ActionRequest;
use Illuminate\Support\Collection;

class ProbeChannelAction extends Action
{
    public static $uriKey = 'probe-channel';

    public function handle(ActionRequest $request, Collection $models)
    {
        /** @var Channel $channel */
        $channel = $models->first();

        $ok = Waba::channel($channel->name)->probe();

        if ($ok) {
            $channel->forceFill(['last_verified_at' => now()])->save();
        }

        return data(['ok' => $ok, 'channel' => $channel->name]);
    }
}
```

> `Action` base class path may differ; consult `vendor/binaryk/laravel-restify/src/Actions/Action.php`. If `data()` helper is unavailable, replace the `return` with `return response()->json(['data' => ['ok' => $ok, 'channel' => $channel->name]]);`.

- [ ] **Step 3: Register in `ChannelRepository`**

Add method to `ChannelRepository`:

```php
public function actions(RestifyRequest $request): array
{
    return [
        \App\Restify\Actions\ProbeChannelAction::new(),
    ];
}
```

- [ ] **Step 4: Run tests — iterate**

Run: `vendor/bin/pest tests/Feature/Restify/ProbeChannelActionTest.php`

- [ ] **Step 5: Commit**

```bash
git add app/Restify/Actions app/Restify/ChannelRepository.php tests/Feature/Restify/ProbeChannelActionTest.php
git commit -m "feat(waba): add ProbeChannelAction"
```

---

## Task 17: Webhook ingress stub + route

**Files:**
- Create: `app/Http/Webhooks/HandleInboundWebhook.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/Webhooks/WebhookStubTest.php`

- [ ] **Step 1: Write test**

```php
<?php

it('webhook stub returns 202', function () {
    $this->postJson('/api/v1/webhooks/qiscus/ch1', ['hello' => 'world'])
        ->assertStatus(202);
});
```

- [ ] **Step 2: Write invokable**

```php
<?php

namespace App\Http\Webhooks;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandleInboundWebhook
{
    public function __invoke(Request $request, string $provider, string $channel): JsonResponse
    {
        // P3 fills: signature verify, persist raw, normalize, fanout.
        return response()->json(['accepted' => true], 202);
    }
}
```

- [ ] **Step 3: Register route in `routes/api.php`**

Append:

```php
Route::post('/v1/webhooks/{provider}/{channel}', \App\Http\Webhooks\HandleInboundWebhook::class);
```

- [ ] **Step 4: Run test**

Run: `vendor/bin/pest tests/Feature/Webhooks`

- [ ] **Step 5: Commit**

```bash
git add app/Http/Webhooks routes/api.php tests/Feature/Webhooks
git commit -m "feat(waba): add inbound webhook stub route"
```

---

## Task 18: Arch test — driver isolation

**Files:**
- Test: `tests/Arch/DriverIsolationTest.php`

- [ ] **Step 1: Write arch test**

```php
<?php

arch('drivers do not depend on Eloquent')
    ->expect('App\Waba\Drivers')
    ->not->toUse('Illuminate\Database\Eloquent');

arch('drivers do not depend on Models')
    ->expect('App\Waba\Drivers')
    ->not->toUse('App\Models');

arch('Waba domain uses no controllers')
    ->expect('App\Waba')
    ->not->toUse('Illuminate\Routing\Controller');
```

- [ ] **Step 2: Run**

Run: `vendor/bin/pest tests/Arch`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
git add tests/Arch
git commit -m "test(waba): add driver isolation arch tests"
```

---

## Task 19: Full suite + lint + coverage sweep

- [ ] **Step 1: Run full test suite**

Run: `php artisan test --compact`
Expected: all tests pass.

- [ ] **Step 2: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no issues (or auto-fixed).

- [ ] **Step 3: Coverage check (if pcov/xdebug available)**

Run: `vendor/bin/pest --coverage --min=80 --coverage-filter=app/Waba --coverage-filter=app/Http/Middleware`

If unavailable, skip and note in PR.

- [ ] **Step 4: Final commit (only if pint made changes)**

```bash
git add -A
git diff --cached --quiet || git commit -m "chore(waba): pint sweep for P1"
```

---

## Acceptance (matches spec §13)

- [ ] Migrations applied; `channels` + `channel_api_keys` exist with indexes.
- [ ] `Channel` + `ChannelApiKey` models with casts + scopes.
- [ ] `MessageProvider` contract implemented by `QiscusDriver` (only `probe` functional).
- [ ] `WabaManager` + `Waba` facade resolve channels; `Waba::fake()` swaps in `FakeProvider`.
- [ ] `AuthenticateChannelApiKey` + `AssignRequestId` middleware registered.
- [ ] Restify: `ChannelRepository`, `ChannelApiKeyRepository`, `ProbeChannelAction` all Sanctum-guarded.
- [ ] `config/waba.php` + `.env.example` updated.
- [ ] Exception handler renders `WabaException` envelope with `request_id`.
- [ ] All tests green, Pint clean.
