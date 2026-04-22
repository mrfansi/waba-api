# WABA Outbound Send Pipeline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship P2 (Outbound) — send all 6 WhatsApp message types via Qiscus driver with sync/queue dispatch, idempotency, retry policy, status-callback ingestion, and per-channel API key ability scopes.

**Architecture:** `DispatchService` is the single seam — invoked by both server-to-server invokable actions (channel.apikey-guarded) and the Restify admin action (Sanctum). It persists `Message` rows, writes the BSP's raw request/response, then routes to either inline `attemptSend()` (sync) or `SendMessageJob` (queue). The job retries on transient `DriverException`/timeout and short-circuits on `PermanentSendException`. `StatusRecorder` ingests BSP status callbacks via `HandleInboundWebhook`, applying monotonicity rules and appending append-only `MessageStatusEvent` rows.

**Tech Stack:** PHP 8.3, Laravel 13, Restify 10.4, Sanctum 4, Pest 4, SQLite (tests) / MySQL (prod), Pint, Laravel Queue.

**Spec reference:** `docs/superpowers/specs/2026-04-22-waba-outbound-design.md`

**Branch:** `feat/p2-outbound` (already checked out at the start of this plan).

---

## File Structure

Domain root `app/Waba/`:
- `Contracts/MessageProvider.php` — extend with `normalizeStatus()`
- `Drivers/QiscusDriver.php` — implement `send()`, `normalizeStatus()`, `verifyWebhookSignature()`, expose `lastTransaction()`
- `Outbound/DispatchService.php` — central send orchestrator
- `Outbound/SendMessageJob.php` — queued worker
- `Outbound/IdempotencyStore.php` — cache + DB-backed idempotency
- `Outbound/StatusRecorder.php` — apply status callback to row + append event
- `Dto/OutboundMessage.php` — extend with typed payload union, idempotency key, client reference
- `Dto/SendResult.php` — extend with status, sentAt
- `Dto/NormalizedStatusEvent.php` — new
- `Dto/MessagePayloads/{Text,Media,Template,Interactive,Location,Contact}Payload.php` — new
- `Testing/FakeProvider.php` — extend with assertion helpers + `throwOnSend`
- `Exceptions/PermanentSendException.php`, `IdempotencyMismatchException.php`, `MessageNotFoundException.php`

HTTP layer:
- `app/Http/Middleware/AssertAbility.php` — alias `assert.ability`
- `app/Http/Requests/SendMessageRequest.php`
- `app/Http/Actions/SendMessageActionApi.php`, `ListMessagesActionApi.php`, `ShowMessageActionApi.php`
- `app/Http/Webhooks/HandleInboundWebhook.php` — extend P1 stub: route status callbacks

Restify:
- `app/Restify/MessageRepository.php` — read-only Sanctum repo
- `app/Restify/Actions/SendMessageAction.php` — admin send

Domain:
- `app/Models/Message.php`, `app/Models/MessageStatusEvent.php`
- `database/factories/MessageFactory.php`, `MessageStatusEventFactory.php`
- `database/migrations/2026_04_22_000003_create_messages_table.php`
- `database/migrations/2026_04_22_000004_create_message_status_events_table.php`

Console:
- `app/Console/Commands/PurgeMessagePayloadsCommand.php`

Config & registration:
- `config/waba.php` — extend `outbound`
- `bootstrap/app.php` — register `assert.ability` alias
- `routes/api.php` — register message routes under `channel.apikey` group

Tests:
- `tests/Feature/Outbound/SendMessageEndpointTest.php`
- `tests/Feature/Outbound/SendMessageJobTest.php`
- `tests/Feature/Outbound/StatusCallbackTest.php`
- `tests/Feature/Restify/MessageRepositoryTest.php`
- `tests/Feature/Restify/SendMessageRestifyActionTest.php`
- `tests/Unit/Waba/DispatchServiceTest.php`
- `tests/Unit/Waba/StatusRecorderTest.php`
- `tests/Unit/Waba/IdempotencyStoreTest.php`
- `tests/Unit/Waba/QiscusDriverSendTest.php`
- `tests/Arch/OutboundIsolationTest.php`

---

## Task 1: Config + env extension

**Files:**
- Modify: `config/waba.php`
- Modify: `.env.example`

- [ ] **Step 1: Replace the `'outbound'` block in `config/waba.php`**

```php
'outbound' => [
    'default_mode' => 'queue',
    'queue_connection' => env('WABA_QUEUE', 'default'),
    'queue_name' => 'waba-outbound',
    'sync_timeout_seconds' => env('WABA_SYNC_TIMEOUT', 15),
    'retry' => [
        'attempts' => env('WABA_RETRY_ATTEMPTS', 3),
        'backoff_seconds' => [30, 120, 600],
    ],
    'idempotency' => [
        'ttl_hours' => 24,
        'cache_store' => env('WABA_IDEMPOTENCY_STORE'),
    ],
    'retention' => [
        'request_payload_days' => 30,
    ],
],
```

- [ ] **Step 2: Append to `.env.example`**

```
WABA_SYNC_TIMEOUT=15
WABA_RETRY_ATTEMPTS=3
WABA_IDEMPOTENCY_STORE=
```

- [ ] **Step 3: Verify**

```
php artisan config:show waba.outbound.sync_timeout_seconds
php artisan config:show waba.outbound.retry.attempts
```

Expected: `15` and `3`.

- [ ] **Step 4: Commit**

```
vendor/bin/pint --dirty --format agent
git add config/waba.php .env.example
git commit -m "feat(waba): extend outbound config for P2"
```

---

## Task 2: Messages migration, model, factory

**Files:**
- Create: `database/migrations/2026_04_22_000003_create_messages_table.php`
- Create: `app/Models/Message.php`
- Create: `database/factories/MessageFactory.php`

- [ ] **Step 1: Generate migration**

```
php artisan make:migration create_messages_table --no-interaction
```

Replace generated body with:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('channel_id')->constrained()->cascadeOnDelete();
            $table->enum('direction', ['outbound', 'inbound']);
            $table->string('to_number', 32);
            $table->string('from_number', 32);
            $table->enum('type', ['text', 'media', 'template', 'interactive', 'location', 'contact']);
            $table->json('payload');
            $table->enum('status', ['pending', 'sending', 'sent', 'delivered', 'read', 'failed'])->default('pending');
            $table->string('provider_message_id', 128)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->foreignUlid('api_key_id')->nullable()->constrained('channel_api_keys')->nullOnDelete();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['channel_id', 'status', 'created_at']);
            $table->index(['channel_id', 'provider_message_id']);
            $table->unique(['api_key_id', 'idempotency_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
```

- [ ] **Step 2: Create `app/Models/Message.php`**

```php
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
```

- [ ] **Step 3: Create `database/factories/MessageFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Message> */
class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'direction' => 'outbound',
            'to_number' => '+628'.fake()->numerify('##########'),
            'from_number' => '+628'.fake()->numerify('##########'),
            'type' => 'text',
            'payload' => ['body' => fake()->sentence()],
            'status' => 'pending',
            'attempts' => 0,
        ];
    }

    public function sent(): static
    {
        return $this->state(['status' => 'sent', 'sent_at' => now(), 'provider_message_id' => 'qiscus-'.fake()->uuid()]);
    }

    public function failed(): static
    {
        return $this->state(['status' => 'failed', 'failed_at' => now(), 'error_code' => 'provider_error']);
    }
}
```

- [ ] **Step 4: Run migration + verify**

```
php artisan migrate --no-interaction
php artisan tinker --execute '$m = App\Models\Message::factory()->sent()->create(); echo $m->status, " / ", $m->provider_message_id;'
```

Expected: `sent / qiscus-...`

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/Message.php database/factories/MessageFactory.php
git commit -m "feat(waba): add messages table, model, factory"
```

---

## Task 3: MessageStatusEvent migration, model, factory

**Files:**
- Create: `database/migrations/2026_04_22_000004_create_message_status_events_table.php`
- Create: `app/Models/MessageStatusEvent.php`
- Create: `database/factories/MessageStatusEventFactory.php`

- [ ] **Step 1: Generate migration + replace body**

```
php artisan make:migration create_message_status_events_table --no-interaction
```

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_status_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('message_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32);
            $table->timestamp('occurred_at');
            $table->json('raw_payload');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['message_id', 'occurred_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_status_events');
    }
};
```

- [ ] **Step 2: Create `app/Models/MessageStatusEvent.php`**

```php
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
```

- [ ] **Step 3: Create `database/factories/MessageStatusEventFactory.php`**

```php
<?php

namespace Database\Factories;

use App\Models\Message;
use App\Models\MessageStatusEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MessageStatusEvent> */
class MessageStatusEventFactory extends Factory
{
    protected $model = MessageStatusEvent::class;

    public function definition(): array
    {
        return [
            'message_id' => Message::factory(),
            'status' => 'sent',
            'occurred_at' => now(),
            'raw_payload' => [],
        ];
    }
}
```

- [ ] **Step 4: Migrate + verify**

```
php artisan migrate --no-interaction
php artisan tinker --execute '$e = App\Models\MessageStatusEvent::factory()->create(); echo $e->status, " / ", $e->message->status;'
```

Expected: `sent / pending`

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/MessageStatusEvent.php database/factories/MessageStatusEventFactory.php
git commit -m "feat(waba): add message_status_events table, model, factory"
```

---

## Task 4: New exceptions

**Files:**
- Create: `app/Waba/Exceptions/PermanentSendException.php`
- Create: `app/Waba/Exceptions/IdempotencyMismatchException.php`
- Create: `app/Waba/Exceptions/MessageNotFoundException.php`

- [ ] **Step 1: PermanentSendException**

```php
<?php

namespace App\Waba\Exceptions;

class PermanentSendException extends WabaException
{
    /** @param array<string,mixed> $meta */
    public function __construct(string $message, private array $meta = [])
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return 'provider_rejected';
    }

    public function httpStatus(): int
    {
        return 422;
    }

    public function details(): array
    {
        return $this->meta;
    }
}
```

- [ ] **Step 2: IdempotencyMismatchException**

```php
<?php

namespace App\Waba\Exceptions;

class IdempotencyMismatchException extends WabaException
{
    public function __construct(string $key)
    {
        parent::__construct("Idempotency key [{$key}] reused with different request body.");
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
```

- [ ] **Step 3: MessageNotFoundException**

```php
<?php

namespace App\Waba\Exceptions;

class MessageNotFoundException extends WabaException
{
    public static function id(string $id): self
    {
        return new self("Message [{$id}] not found.");
    }

    public function errorCode(): string
    {
        return 'message_not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
```

- [ ] **Step 4: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Exceptions
git commit -m "feat(waba): add P2 exceptions"
```

---

## Task 5: Payload DTOs

**Files (all under `app/Waba/Dto/MessagePayloads/`):**

- [ ] **Step 1: TextPayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class TextPayload
{
    public function __construct(public string $body, public bool $previewUrl = false) {}
}
```

- [ ] **Step 2: MediaPayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class MediaPayload
{
    public function __construct(
        public string $kind,
        public string $url,
        public ?string $caption = null,
        public ?string $filename = null,
    ) {}
}
```

- [ ] **Step 3: TemplatePayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class TemplatePayload
{
    /** @param array<int,array{type:string,parameters:array<int,mixed>}> $components */
    public function __construct(
        public string $name,
        public string $language,
        public array $components = [],
    ) {}
}
```

- [ ] **Step 4: InteractivePayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class InteractivePayload
{
    /** @param array<string,mixed> $action */
    public function __construct(
        public string $kind,
        public string $body,
        public array $action,
        public ?string $header = null,
        public ?string $footer = null,
    ) {}
}
```

- [ ] **Step 5: LocationPayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class LocationPayload
{
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}
}
```

- [ ] **Step 6: ContactPayload**

```php
<?php

namespace App\Waba\Dto\MessagePayloads;

final readonly class ContactPayload
{
    /** @param array<int,array<string,mixed>> $contacts */
    public function __construct(public array $contacts) {}
}
```

- [ ] **Step 7: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Dto/MessagePayloads
git commit -m "feat(waba): add 6 message payload DTOs"
```

---

## Task 6: Extend OutboundMessage, SendResult; add NormalizedStatusEvent

**Files:**
- Modify: `app/Waba/Dto/OutboundMessage.php`
- Modify: `app/Waba/Dto/SendResult.php`
- Create: `app/Waba/Dto/NormalizedStatusEvent.php`
- Modify: `tests/Unit/Waba/QiscusDriverTest.php` (P1 test — adapt due to signature change)

- [ ] **Step 1: Replace `OutboundMessage.php`**

```php
<?php

namespace App\Waba\Dto;

use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;

final readonly class OutboundMessage
{
    public function __construct(
        public string $to,
        public string $type,
        public TextPayload|MediaPayload|TemplatePayload|InteractivePayload|LocationPayload|ContactPayload $payload,
        public ?string $idempotencyKey = null,
        public ?string $clientReference = null,
    ) {}
}
```

- [ ] **Step 2: Replace `SendResult.php`**

```php
<?php

namespace App\Waba\Dto;

final readonly class SendResult
{
    /** @param array<string,mixed> $raw */
    public function __construct(
        public string $providerMessageId,
        public string $status,
        public array $raw = [],
        public ?\DateTimeInterface $sentAt = null,
    ) {}
}
```

- [ ] **Step 3: Create `NormalizedStatusEvent.php`**

```php
<?php

namespace App\Waba\Dto;

final readonly class NormalizedStatusEvent
{
    public function __construct(
        public string $providerMessageId,
        public string $status,
        public \DateTimeInterface $occurredAt,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
```

- [ ] **Step 4: Update P1 `QiscusDriverTest` "throws not-implemented"**

In `tests/Unit/Waba/QiscusDriverTest.php` find the line:

```php
$driver->send(new OutboundMessage('+62', 'text'));
```

Replace with:

```php
$driver->send(new OutboundMessage('+62', 'text', new \App\Waba\Dto\MessagePayloads\TextPayload('hi')));
```

- [ ] **Step 5: Run P1 test suite**

```
vendor/bin/pest tests/Unit/Waba
```

Expected: still passes (the only signature break is the line you just adapted).

- [ ] **Step 6: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Dto tests/Unit/Waba/QiscusDriverTest.php
git commit -m "feat(waba): extend OutboundMessage/SendResult, add NormalizedStatusEvent"
```

---

## Task 7: Extend MessageProvider contract + driver/fake stubs

**Files:**
- Modify: `app/Waba/Contracts/MessageProvider.php`
- Modify: `app/Waba/Drivers/QiscusDriver.php`
- Modify: `app/Waba/Testing/FakeProvider.php`

- [ ] **Step 1: Add `normalizeStatus` to `MessageProvider` interface**

Add import:
```php
use App\Waba\Dto\NormalizedStatusEvent;
```

Add inside the interface (place after `normalizeInbound` declaration):
```php
    /** @param array<string,mixed> $rawPayload */
    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent;
```

- [ ] **Step 2: Add stub to `QiscusDriver`**

Add import in `app/Waba/Drivers/QiscusDriver.php`:
```php
use App\Waba\Dto\NormalizedStatusEvent;
```

Add method (Task 10 replaces this with full impl):
```php
    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }
```

- [ ] **Step 3: Add to `FakeProvider`**

Add import in `app/Waba/Testing/FakeProvider.php`:
```php
use App\Waba\Dto\NormalizedStatusEvent;
```

Add property near top of class:
```php
    public ?NormalizedStatusEvent $normalizedStatusResult = null;
```

Add method (after `normalizeInbound`):
```php
    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        $this->record(__FUNCTION__, [$rawPayload]);

        return $this->normalizedStatusResult;
    }
```

- [ ] **Step 4: Run unit tests**

```
vendor/bin/pest tests/Unit/Waba
```

Expected: still passes.

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Contracts/MessageProvider.php app/Waba/Drivers/QiscusDriver.php app/Waba/Testing/FakeProvider.php
git commit -m "feat(waba): add normalizeStatus to MessageProvider contract"
```

---

## Task 8: IdempotencyStore — TDD

**Files:**
- Test: `tests/Unit/Waba/IdempotencyStoreTest.php`
- Create: `app/Waba/Outbound/IdempotencyStore.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Waba\Outbound\IdempotencyStore;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->store = new IdempotencyStore();
});

it('returns null when no record exists', function () {
    expect($this->store->find('apikey-1', 'k1'))->toBeNull();
});

it('remembers and returns record', function () {
    $this->store->remember('apikey-1', 'k1', 'msg-1', 'hash-a');

    expect($this->store->find('apikey-1', 'k1'))
        ->toBe(['message_id' => 'msg-1', 'request_hash' => 'hash-a']);
});

it('isolates by api key id', function () {
    $this->store->remember('apikey-1', 'k1', 'msg-1', 'hash-a');

    expect($this->store->find('apikey-2', 'k1'))->toBeNull();
});
```

- [ ] **Step 2: Run — expect FAIL**

```
vendor/bin/pest tests/Unit/Waba/IdempotencyStoreTest.php
```

- [ ] **Step 3: Implement store**

```php
<?php

namespace App\Waba\Outbound;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

class IdempotencyStore
{
    private Repository $cache;

    public function __construct()
    {
        $store = config('waba.outbound.idempotency.cache_store');
        $this->cache = $store ? Cache::store($store) : Cache::store();
    }

    /** @return array{message_id:string, request_hash:string}|null */
    public function find(string $apiKeyId, string $key): ?array
    {
        $value = $this->cache->get($this->key($apiKeyId, $key));

        return is_array($value) ? $value : null;
    }

    public function remember(string $apiKeyId, string $key, string $messageId, string $requestHash): void
    {
        $ttl = (int) config('waba.outbound.idempotency.ttl_hours', 24) * 3600;
        $this->cache->put(
            $this->key($apiKeyId, $key),
            ['message_id' => $messageId, 'request_hash' => $requestHash],
            $ttl,
        );
    }

    private function key(string $apiKeyId, string $key): string
    {
        return "waba:idem:{$apiKeyId}:{$key}";
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Outbound/IdempotencyStore.php tests/Unit/Waba/IdempotencyStoreTest.php
git commit -m "feat(waba): add IdempotencyStore"
```

---

## Task 9: StatusRecorder — TDD

**Files:**
- Test: `tests/Unit/Waba/StatusRecorderTest.php`
- Create: `app/Waba/Outbound/StatusRecorder.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Outbound\StatusRecorder;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function makeMsg(Channel $ch, array $state = []): Message
{
    return Message::factory()->for($ch)->create(array_merge([
        'provider_message_id' => 'p-1',
        'status' => 'sent',
    ], $state));
}

beforeEach(function () {
    $this->channel = Channel::factory()->create();
    $this->recorder = app(StatusRecorder::class);
});

it('updates status forward and appends event', function () {
    $m = makeMsg($this->channel);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, ['raw' => 1]);

    expect($m->fresh()->status)->toBe('delivered')
        ->and($m->fresh()->delivered_at)->not->toBeNull()
        ->and($m->statusEvents()->count())->toBe(1);
});

it('does not downgrade read to delivered', function () {
    $m = makeMsg($this->channel, ['status' => 'read', 'read_at' => now()]);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect($m->fresh()->status)->toBe('read');
    expect($m->statusEvents()->count())->toBe(1);
});

it('failed is terminal', function () {
    $m = makeMsg($this->channel, ['status' => 'failed', 'failed_at' => now()]);
    $event = new NormalizedStatusEvent('p-1', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect($m->fresh()->status)->toBe('failed');
});

it('no-ops on unknown provider id', function () {
    $event = new NormalizedStatusEvent('unknown', 'delivered', now());

    $this->recorder->record($this->channel, $event, []);

    expect(true)->toBeTrue();
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement recorder**

```php
<?php

namespace App\Waba\Outbound;

use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageStatusEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class StatusRecorder
{
    private const ORDER = ['pending' => 0, 'sending' => 1, 'sent' => 2, 'delivered' => 3, 'read' => 4];

    /** @param array<string,mixed> $rawPayload */
    public function record(Channel $channel, NormalizedStatusEvent $event, array $rawPayload): void
    {
        DB::transaction(function () use ($channel, $event, $rawPayload) {
            $message = Message::query()
                ->where('channel_id', $channel->id)
                ->where('provider_message_id', $event->providerMessageId)
                ->lockForUpdate()
                ->first();

            if (! $message) {
                Log::warning('waba.status.unknown_message', [
                    'channel_id' => $channel->id,
                    'provider_message_id' => $event->providerMessageId,
                ]);

                return;
            }

            MessageStatusEvent::create([
                'message_id' => $message->id,
                'status' => $event->status,
                'occurred_at' => $event->occurredAt,
                'raw_payload' => $rawPayload,
            ]);

            $this->maybeApplyDenormStatus($message, $event);
        });
    }

    private function maybeApplyDenormStatus(Message $message, NormalizedStatusEvent $event): void
    {
        if ($message->status === 'failed') {
            return;
        }

        if ($event->status === 'failed') {
            $message->forceFill([
                'status' => 'failed',
                'failed_at' => $event->occurredAt,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
            ])->save();

            return;
        }

        $newOrder = self::ORDER[$event->status] ?? null;
        $currentOrder = self::ORDER[$message->status] ?? null;

        if ($newOrder === null || $currentOrder === null || $newOrder <= $currentOrder) {
            return;
        }

        $update = ['status' => $event->status];
        $tsCol = match ($event->status) {
            'sent' => 'sent_at',
            'delivered' => 'delivered_at',
            'read' => 'read_at',
            default => null,
        };
        if ($tsCol !== null) {
            $update[$tsCol] = $event->occurredAt;
        }

        $message->forceFill($update)->save();
    }
}
```

- [ ] **Step 4: Run — expect PASS**

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Outbound/StatusRecorder.php tests/Unit/Waba/StatusRecorderTest.php
git commit -m "feat(waba): add StatusRecorder"
```

---

## Task 10: QiscusDriver send + normalizeStatus + signature — TDD

**Files:**
- Test: `tests/Unit/Waba/QiscusDriverSendTest.php`
- Modify: `app/Waba/Drivers/QiscusDriver.php`

> **Note:** Qiscus REST endpoint path/payload shape per type must be verified against current docs (https://documentation.qiscus.com/omnichannel-chat/api). The plan uses the documented WhatsApp send endpoint shape. If the live endpoint differs, adjust driver method bodies — `Http::fake()` keeps tests local.

- [ ] **Step 1: Write failing tests**

```php
<?php

use App\Waba\Drivers\QiscusDriver;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\PermanentSendException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->creds = new ChannelCredentials(
        driver: 'qiscus',
        channelId: '01HZ',
        data: ['app_id' => 'app1', 'secret_key' => 'sec1', 'phone_number_id' => 'pni-1'],
        webhookSecret: 'wh-secret',
    );
    $this->driver = (new QiscusDriver())->bind($this->creds);
});

it('sends text and returns SendResult', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'qiscus-msg-1']], 200)]);

    $result = $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));

    expect($result->providerMessageId)->toBe('qiscus-msg-1')->and($result->status)->toBe('accepted');
    expect($this->driver->lastTransaction())->toHaveKeys(['request', 'response']);
});

it('throws PermanentSendException on 4xx', function () {
    Http::fake(['*' => Http::response(['error' => 'invalid number'], 400)]);

    $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));
})->throws(PermanentSendException::class);

it('throws DriverException on 5xx', function () {
    Http::fake(['*' => Http::response([], 503)]);

    $this->driver->send(new OutboundMessage('+628111', 'text', new TextPayload('hi')));
})->throws(\App\Waba\Exceptions\DriverException::class);

it('builds media payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-2']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'media',
        new MediaPayload(kind: 'image', url: 'https://x/y.jpg', caption: 'cap'),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'image')
        && str_contains(json_encode($req->data()), 'cap'));
});

it('builds template payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-3']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'template',
        new TemplatePayload(name: 'order_confirm', language: 'id', components: []),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'order_confirm'));
});

it('builds interactive payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-4']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'interactive',
        new InteractivePayload(kind: 'button', body: 'pick one', action: ['buttons' => []]),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), 'pick one'));
});

it('builds location payload', function () {
    Http::fake(['*' => Http::response(['data' => ['id' => 'q-5']], 200)]);

    $this->driver->send(new OutboundMessage('+628', 'location',
        new LocationPayload(latitude: 1.23, longitude: 4.56, name: 'office'),
    ));

    Http::assertSent(fn ($req) => str_contains(json_encode($req->data()), '1.23'));
});

it('normalizes status webhook', function () {
    $event = $this->driver->normalizeStatus([
        'event' => 'status',
        'data' => ['message_id' => 'q-1', 'status' => 'delivered', 'timestamp' => '2026-04-22T10:00:00Z'],
    ]);

    expect($event)->not->toBeNull()
        ->and($event->providerMessageId)->toBe('q-1')
        ->and($event->status)->toBe('delivered');
});

it('returns null for non-status webhook', function () {
    expect($this->driver->normalizeStatus(['event' => 'message', 'data' => []]))->toBeNull();
});

it('verifies HMAC signature', function () {
    $payload = '{"hello":"world"}';
    $sig = hash_hmac('sha256', $payload, 'wh-secret');

    expect($this->driver->verifyWebhookSignature($payload, ['x-qiscus-signature' => [$sig]]))->toBeTrue();
    expect($this->driver->verifyWebhookSignature($payload, ['x-qiscus-signature' => ['bad']]))->toBeFalse();
});
```

- [ ] **Step 2: Run — expect FAIL**

```
vendor/bin/pest tests/Unit/Waba/QiscusDriverSendTest.php
```

- [ ] **Step 3: Replace `app/Waba/Drivers/QiscusDriver.php`**

```php
<?php

namespace App\Waba\Drivers;

use App\Waba\Contracts\MessageProvider;
use App\Waba\Dto\ChannelCredentials;
use App\Waba\Dto\MediaReference;
use App\Waba\Dto\MediaUpload;
use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\NormalizedInboundEvent;
use App\Waba\Dto\NormalizedStatusEvent;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Dto\SendResult;
use App\Waba\Dto\TemplateDefinition;
use App\Waba\Dto\TemplateSyncResult;
use App\Waba\Exceptions\DriverException;
use App\Waba\Exceptions\DriverTimeoutException;
use App\Waba\Exceptions\PermanentSendException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class QiscusDriver implements MessageProvider
{
    private ?ChannelCredentials $credentials = null;

    /** @var array{request:array<string,mixed>,response:array<string,mixed>}|null */
    private ?array $lastTransaction = null;

    public function name(): string
    {
        return 'qiscus';
    }

    public function bind(ChannelCredentials $credentials): static
    {
        $clone = clone $this;
        $clone->credentials = $credentials;
        $clone->lastTransaction = null;

        return $clone;
    }

    public function probe(): bool
    {
        $creds = $this->requireCredentials();

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders($this->authHeaders($creds))
                ->get($this->baseUrl().'/api/v2/app/config');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    public function send(OutboundMessage $message): SendResult
    {
        $creds = $this->requireCredentials();
        $body = $this->buildSendBody($message);

        $this->lastTransaction = ['request' => $body, 'response' => []];

        try {
            $response = Http::timeout((int) config('waba.providers.qiscus.timeout', 15))
                ->withHeaders($this->authHeaders($creds))
                ->asJson()
                ->post($this->sendUrl($creds), $body);
        } catch (ConnectionException $e) {
            throw new DriverTimeoutException('Qiscus connection error: '.$e->getMessage());
        }

        $this->lastTransaction['response'] = [
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ];

        if ($response->status() >= 400 && $response->status() < 500) {
            throw new PermanentSendException(
                "Qiscus rejected message: HTTP {$response->status()}",
                ['provider' => 'qiscus', 'upstream_status' => $response->status(), 'body' => $response->json()],
            );
        }

        if (! $response->successful()) {
            throw new DriverException(
                "Qiscus upstream error: HTTP {$response->status()}",
                ['provider' => 'qiscus', 'upstream_status' => $response->status()],
            );
        }

        $providerId = (string) ($response->json('data.id') ?? $response->json('id') ?? '');

        return new SendResult(
            providerMessageId: $providerId,
            status: 'accepted',
            raw: $response->json() ?? [],
            sentAt: now(),
        );
    }

    /** @return array{request:array<string,mixed>,response:array<string,mixed>}|null */
    public function lastTransaction(): ?array
    {
        return $this->lastTransaction;
    }

    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $creds = $this->requireCredentials();
        $provided = $headers['x-qiscus-signature'][0] ?? $headers['X-Qiscus-Signature'][0] ?? null;

        if ($provided === null) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $creds->webhookSecret);

        return hash_equals($expected, (string) $provided);
    }

    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent
    {
        throw DriverException::notImplemented(__METHOD__);
    }

    public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent
    {
        if (($rawPayload['event'] ?? null) !== 'status') {
            return null;
        }
        $data = $rawPayload['data'] ?? [];

        $occurred = isset($data['timestamp'])
            ? Carbon::parse((string) $data['timestamp'])
            : now();

        return new NormalizedStatusEvent(
            providerMessageId: (string) ($data['message_id'] ?? ''),
            status: (string) ($data['status'] ?? 'sent'),
            occurredAt: $occurred,
            errorCode: isset($data['error']['code']) ? (string) $data['error']['code'] : null,
            errorMessage: isset($data['error']['message']) ? (string) $data['error']['message'] : null,
        );
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

    /** @return array<string,string> */
    private function authHeaders(ChannelCredentials $creds): array
    {
        return [
            'Qiscus-App-Id' => (string) $creds->get('app_id'),
            'Qiscus-Secret-Key' => (string) $creds->get('secret_key'),
            'Accept' => 'application/json',
        ];
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('waba.providers.qiscus.base_url'), '/');
    }

    private function sendUrl(ChannelCredentials $creds): string
    {
        $appId = (string) $creds->get('app_id');
        $phoneId = (string) $creds->get('phone_number_id', '');

        return $this->baseUrl()."/{$appId}/api/v1/qiscus/whatsapp/{$phoneId}/send";
    }

    /** @return array<string,mixed> */
    private function buildSendBody(OutboundMessage $message): array
    {
        $base = ['to' => $message->to, 'type' => $message->type];

        return match (true) {
            $message->payload instanceof TextPayload => $base + [
                'text' => ['body' => $message->payload->body, 'preview_url' => $message->payload->previewUrl],
            ],
            $message->payload instanceof MediaPayload => $base + [
                $message->payload->kind => array_filter([
                    'link' => $message->payload->url,
                    'caption' => $message->payload->caption,
                    'filename' => $message->payload->filename,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof TemplatePayload => $base + [
                'template' => [
                    'name' => $message->payload->name,
                    'language' => ['code' => $message->payload->language],
                    'components' => $message->payload->components,
                ],
            ],
            $message->payload instanceof InteractivePayload => $base + [
                'interactive' => array_filter([
                    'type' => $message->payload->kind,
                    'header' => $message->payload->header ? ['type' => 'text', 'text' => $message->payload->header] : null,
                    'body' => ['text' => $message->payload->body],
                    'footer' => $message->payload->footer ? ['text' => $message->payload->footer] : null,
                    'action' => $message->payload->action,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof LocationPayload => $base + [
                'location' => array_filter([
                    'latitude' => $message->payload->latitude,
                    'longitude' => $message->payload->longitude,
                    'name' => $message->payload->name,
                    'address' => $message->payload->address,
                ], fn ($v) => $v !== null),
            ],
            $message->payload instanceof ContactPayload => $base + ['contacts' => $message->payload->contacts],
        };
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

- [ ] **Step 4: Run — iterate until PASS**

```
vendor/bin/pest tests/Unit/Waba/QiscusDriverSendTest.php
```

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Drivers/QiscusDriver.php tests/Unit/Waba/QiscusDriverSendTest.php
git commit -m "feat(waba): implement QiscusDriver send/normalizeStatus/verifySignature"
```

---

## Task 11: DispatchService + FakeProvider extension — TDD

**Files:**
- Test: `tests/Unit/Waba/DispatchServiceTest.php`
- Modify: `app/Waba/Testing/FakeProvider.php`
- Create: `app/Waba/Outbound/DispatchService.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\IdempotencyMismatchException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\DispatchService;
use App\Waba\Outbound\SendMessageJob;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
    $this->apiKey = ChannelApiKey::factory()->for($this->channel)->create();
    $this->svc = app(DispatchService::class);
    $this->msg = new OutboundMessage('+62811', 'text', new TextPayload('hi'));
});

it('persists pending row and queues job in queue mode', function () {
    Queue::fake();

    $row = $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'queue');

    expect($row->status)->toBe('pending')->and($row->channel_id)->toBe($this->channel->id);
    Queue::assertPushed(SendMessageJob::class);
});

it('returns sent row in sync mode on success', function () {
    Waba::fake();

    $row = $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'sync');

    expect($row->status)->toBe('sent')
        ->and($row->provider_message_id)->toBe('fake-id');
});

it('marks row failed on PermanentSendException sync', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new PermanentSendException('rejected');

    expect(fn () => $this->svc->dispatch($this->channel, $this->apiKey, $this->msg, 'sync'))
        ->toThrow(PermanentSendException::class);

    $row = Message::where('channel_id', $this->channel->id)->first();
    expect($row->status)->toBe('failed');
});

it('returns existing row on idempotency hit', function () {
    Queue::fake();
    $msg = new OutboundMessage('+62811', 'text', new TextPayload('hi'), idempotencyKey: 'k1');

    $row1 = $this->svc->dispatch($this->channel, $this->apiKey, $msg, 'queue');
    $row2 = $this->svc->dispatch($this->channel, $this->apiKey, $msg, 'queue');

    expect($row2->id)->toBe($row1->id);
    Queue::assertPushed(SendMessageJob::class, 1);
});

it('throws on idempotency mismatch (same key, different body)', function () {
    Queue::fake();
    $a = new OutboundMessage('+62811', 'text', new TextPayload('hi'), idempotencyKey: 'k1');
    $b = new OutboundMessage('+62811', 'text', new TextPayload('different'), idempotencyKey: 'k1');

    $this->svc->dispatch($this->channel, $this->apiKey, $a, 'queue');

    expect(fn () => $this->svc->dispatch($this->channel, $this->apiKey, $b, 'queue'))
        ->toThrow(IdempotencyMismatchException::class);
});
```

- [ ] **Step 2: Extend `FakeProvider` to support `throwOnSend` and `lastTransaction`**

In `app/Waba/Testing/FakeProvider.php`:

Add property near top of class:
```php
    public ?\Throwable $throwOnSend = null;
```

Replace existing `send()` method body:
```php
    public function send(OutboundMessage $message): SendResult
    {
        $this->record(__FUNCTION__, [$message]);

        if ($this->throwOnSend !== null) {
            throw $this->throwOnSend;
        }

        return new SendResult('fake-id', 'accepted', sentAt: now());
    }
```

Add `lastTransaction` method (DispatchService calls it via `method_exists`):
```php
    /** @return array{request:array<string,mixed>,response:array<string,mixed>} */
    public function lastTransaction(): array
    {
        return ['request' => [], 'response' => []];
    }
```

Add assertion helpers (used in later tests too):
```php
    public function assertSent(?callable $matcher = null): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        \PHPUnit\Framework\Assert::assertNotEmpty($sends, 'No messages sent');
        if ($matcher !== null) {
            $matched = array_filter($sends, fn ($c) => $matcher($c['args'][0]));
            \PHPUnit\Framework\Assert::assertNotEmpty($matched, 'No sent message matched');
        }
    }

    public function assertNothingSent(): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        \PHPUnit\Framework\Assert::assertEmpty($sends);
    }

    public function assertSentCount(int $count): void
    {
        $sends = array_filter($this->calls, fn ($c) => $c['method'] === 'send');
        \PHPUnit\Framework\Assert::assertCount($count, $sends);
    }
```

- [ ] **Step 3: Implement `DispatchService`**

```php
<?php

namespace App\Waba\Outbound;

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Models\MessageStatusEvent;
use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use App\Waba\Exceptions\IdempotencyMismatchException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchService
{
    public function __construct(private IdempotencyStore $idempotency) {}

    public function dispatch(Channel $channel, ChannelApiKey $apiKey, OutboundMessage $msg, string $mode = 'queue'): Message
    {
        $requestHash = $this->hashMessage($msg);

        if ($msg->idempotencyKey !== null) {
            $hit = $this->idempotency->find($apiKey->id, $msg->idempotencyKey);
            if ($hit !== null) {
                if (! hash_equals($hit['request_hash'], $requestHash)) {
                    throw new IdempotencyMismatchException($msg->idempotencyKey);
                }

                $existing = Message::findOrFail($hit['message_id']);
                $existing->wasIdempotentReplay = true;

                return $existing;
            }
        }

        $message = $this->createPending($channel, $apiKey, $msg, $requestHash);

        if ($mode === 'sync') {
            return $this->attemptSend($message);
        }

        SendMessageJob::dispatch($message->id)
            ->onConnection(config('waba.outbound.queue_connection'))
            ->onQueue(config('waba.outbound.queue_name'));

        return $message;
    }

    public function attemptSend(Message $m): Message
    {
        $m->forceFill(['status' => 'sending', 'attempts' => $m->attempts + 1])->save();

        $outbound = $this->dtoFromRow($m);
        $provider = Waba::channel($m->channel->name);

        try {
            $result = $provider->send($outbound);
        } catch (PermanentSendException $e) {
            $this->recordFailure($m, $provider, $e);
            throw $e;
        } catch (Throwable $e) {
            $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
            $m->forceFill([
                'request_payload' => $tx['request'] ?? null,
                'response_payload' => $tx['response'] ?? null,
            ])->save();
            throw $e;
        }

        $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
        $m->forceFill([
            'status' => 'sent',
            'provider_message_id' => $result->providerMessageId,
            'sent_at' => $result->sentAt ?? now(),
            'request_payload' => $tx['request'] ?? null,
            'response_payload' => $tx['response'] ?? null,
        ])->save();

        MessageStatusEvent::create([
            'message_id' => $m->id,
            'status' => 'sent',
            'occurred_at' => $result->sentAt ?? now(),
            'raw_payload' => $result->raw,
        ]);

        return $m->fresh();
    }

    private function createPending(Channel $channel, ChannelApiKey $apiKey, OutboundMessage $msg, string $hash): Message
    {
        try {
            return DB::transaction(function () use ($channel, $apiKey, $msg, $hash) {
                $row = Message::create([
                    'channel_id' => $channel->id,
                    'direction' => 'outbound',
                    'to_number' => $msg->to,
                    'from_number' => $channel->phone_number,
                    'type' => $msg->type,
                    'payload' => $this->payloadToArray($msg),
                    'status' => 'pending',
                    'idempotency_key' => $msg->idempotencyKey,
                    'api_key_id' => $apiKey->id,
                    'attempts' => 0,
                ]);

                if ($msg->idempotencyKey !== null) {
                    $this->idempotency->remember($apiKey->id, $msg->idempotencyKey, $row->id, $hash);
                }

                return $row;
            });
        } catch (QueryException $e) {
            if ($msg->idempotencyKey === null) {
                throw $e;
            }
            $existing = Message::where('api_key_id', $apiKey->id)
                ->where('idempotency_key', $msg->idempotencyKey)
                ->firstOrFail();
            $this->idempotency->remember($apiKey->id, $msg->idempotencyKey, $existing->id, $hash);

            return $existing;
        }
    }

    private function recordFailure(Message $m, mixed $provider, PermanentSendException $e): void
    {
        $tx = method_exists($provider, 'lastTransaction') ? $provider->lastTransaction() : null;
        $m->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'error_code' => $e->errorCode(),
            'error_message' => $e->getMessage(),
            'request_payload' => $tx['request'] ?? null,
            'response_payload' => $tx['response'] ?? null,
        ])->save();

        MessageStatusEvent::create([
            'message_id' => $m->id,
            'status' => 'failed',
            'occurred_at' => now(),
            'raw_payload' => $e->details(),
        ]);
    }

    /** @return array<string,mixed> */
    private function payloadToArray(OutboundMessage $msg): array
    {
        return get_object_vars($msg->payload);
    }

    private function dtoFromRow(Message $m): OutboundMessage
    {
        $p = $m->payload;
        $payload = match ($m->type) {
            'text' => new TextPayload($p['body'] ?? '', (bool) ($p['previewUrl'] ?? false)),
            'media' => new MediaPayload($p['kind'], $p['url'], $p['caption'] ?? null, $p['filename'] ?? null),
            'template' => new TemplatePayload($p['name'], $p['language'], $p['components'] ?? []),
            'interactive' => new InteractivePayload($p['kind'], $p['body'], $p['action'] ?? [], $p['header'] ?? null, $p['footer'] ?? null),
            'location' => new LocationPayload((float) $p['latitude'], (float) $p['longitude'], $p['name'] ?? null, $p['address'] ?? null),
            'contact' => new ContactPayload($p['contacts'] ?? []),
        };

        return new OutboundMessage($m->to_number, $m->type, $payload);
    }

    private function hashMessage(OutboundMessage $m): string
    {
        return hash('sha256', json_encode([
            'to' => $m->to,
            'type' => $m->type,
            'payload' => get_object_vars($m->payload),
        ]));
    }
}
```

- [ ] **Step 4: Run — expect PASS**

```
vendor/bin/pest tests/Unit/Waba/DispatchServiceTest.php
```

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Outbound/DispatchService.php app/Waba/Testing/FakeProvider.php tests/Unit/Waba/DispatchServiceTest.php
git commit -m "feat(waba): add DispatchService + extend FakeProvider"
```

---

## Task 12: SendMessageJob — TDD

**Files:**
- Test: `tests/Feature/Outbound/SendMessageJobTest.php`
- Create: `app/Waba/Outbound/SendMessageJob.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Exceptions\DriverException;
use App\Waba\Exceptions\PermanentSendException;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\DispatchService;
use App\Waba\Outbound\SendMessageJob;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
    $this->msg = Message::factory()->for($this->channel)->create();
});

it('marks message sent on success', function () {
    Waba::fake();

    (new SendMessageJob($this->msg->id))->handle(app(DispatchService::class));

    expect($this->msg->fresh()->status)->toBe('sent');
});

it('marks failed on PermanentSendException without retry', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new PermanentSendException('rejected');

    $job = new SendMessageJob($this->msg->id);
    try {
        $job->handle(app(DispatchService::class));
    } catch (\Throwable) {
        // job swallows internally via $this->fail()
    }

    expect($this->msg->fresh()->status)->toBe('failed');
});

it('rethrows DriverException for queue retry', function () {
    $fake = Waba::fake();
    $fake->throwOnSend = new DriverException('transient');

    $job = new SendMessageJob($this->msg->id);

    expect(fn () => $job->handle(app(DispatchService::class)))
        ->toThrow(DriverException::class);

    expect($this->msg->fresh()->status)->toBe('sending');
    expect($this->msg->fresh()->attempts)->toBe(1);
});

it('failed callback marks pending row as failed terminally', function () {
    $job = new SendMessageJob($this->msg->id);
    $job->failed(new \RuntimeException('boom'));

    expect($this->msg->fresh()->status)->toBe('failed');
});
```

- [ ] **Step 2: Run — expect FAIL**

- [ ] **Step 3: Implement job**

```php
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
```

- [ ] **Step 4: Run — expect PASS**

```
vendor/bin/pest tests/Feature/Outbound/SendMessageJobTest.php
```

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Waba/Outbound/SendMessageJob.php tests/Feature/Outbound/SendMessageJobTest.php
git commit -m "feat(waba): add SendMessageJob"
```

---

## Task 13: AssertAbility middleware

**Files:**
- Create: `app/Http/Middleware/AssertAbility.php`
- Modify: `bootstrap/app.php` (add alias)

- [ ] **Step 1: Write middleware**

```php
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
```

- [ ] **Step 2: Register alias in `bootstrap/app.php`**

In the existing `withMiddleware` block, extend the `alias()` call:

```php
$middleware->alias([
    'channel.apikey' => \App\Http\Middleware\AuthenticateChannelApiKey::class,
    'assert.ability' => \App\Http\Middleware\AssertAbility::class,
]);
```

- [ ] **Step 3: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Http/Middleware/AssertAbility.php bootstrap/app.php
git commit -m "feat(waba): add AssertAbility middleware"
```

---

## Task 14: SendMessageRequest FormRequest

**Files:**
- Create: `app/Http/Requests/SendMessageRequest.php`

- [ ] **Step 1: Write FormRequest**

```php
<?php

namespace App\Http\Requests;

use App\Waba\Dto\MessagePayloads\ContactPayload;
use App\Waba\Dto\MessagePayloads\InteractivePayload;
use App\Waba\Dto\MessagePayloads\LocationPayload;
use App\Waba\Dto\MessagePayloads\MediaPayload;
use App\Waba\Dto\MessagePayloads\TemplatePayload;
use App\Waba\Dto\MessagePayloads\TextPayload;
use App\Waba\Dto\OutboundMessage;
use Illuminate\Foundation\Http\FormRequest;

class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to' => ['required', 'string'],
            'type' => ['required', 'in:text,media,template,interactive,location,contact'],
            'mode' => ['nullable', 'in:sync,queue'],

            'text' => ['required_if:type,text', 'array'],
            'text.body' => ['required_if:type,text', 'string'],
            'text.preview_url' => ['sometimes', 'boolean'],

            'media' => ['required_if:type,media', 'array'],
            'media.kind' => ['required_if:type,media', 'in:image,video,audio,document,sticker'],
            'media.url' => ['required_if:type,media', 'url'],
            'media.caption' => ['nullable', 'string'],
            'media.filename' => ['nullable', 'string'],

            'template' => ['required_if:type,template', 'array'],
            'template.name' => ['required_if:type,template', 'string'],
            'template.language' => ['required_if:type,template', 'string'],
            'template.components' => ['sometimes', 'array'],

            'interactive' => ['required_if:type,interactive', 'array'],
            'interactive.kind' => ['required_if:type,interactive', 'in:button,list'],
            'interactive.body' => ['required_if:type,interactive', 'string'],
            'interactive.action' => ['required_if:type,interactive', 'array'],
            'interactive.header' => ['nullable', 'string'],
            'interactive.footer' => ['nullable', 'string'],

            'location' => ['required_if:type,location', 'array'],
            'location.latitude' => ['required_if:type,location', 'numeric'],
            'location.longitude' => ['required_if:type,location', 'numeric'],
            'location.name' => ['nullable', 'string'],
            'location.address' => ['nullable', 'string'],

            'contacts' => ['required_if:type,contact', 'array'],
        ];
    }

    public function toDto(): OutboundMessage
    {
        $type = $this->input('type');
        $idempotency = $this->header('Idempotency-Key');

        $payload = match ($type) {
            'text' => new TextPayload($this->input('text.body'), (bool) $this->input('text.preview_url', false)),
            'media' => new MediaPayload(
                kind: $this->input('media.kind'),
                url: $this->input('media.url'),
                caption: $this->input('media.caption'),
                filename: $this->input('media.filename'),
            ),
            'template' => new TemplatePayload(
                name: $this->input('template.name'),
                language: $this->input('template.language'),
                components: $this->input('template.components', []),
            ),
            'interactive' => new InteractivePayload(
                kind: $this->input('interactive.kind'),
                body: $this->input('interactive.body'),
                action: $this->input('interactive.action'),
                header: $this->input('interactive.header'),
                footer: $this->input('interactive.footer'),
            ),
            'location' => new LocationPayload(
                latitude: (float) $this->input('location.latitude'),
                longitude: (float) $this->input('location.longitude'),
                name: $this->input('location.name'),
                address: $this->input('location.address'),
            ),
            'contact' => new ContactPayload($this->input('contacts', [])),
        };

        return new OutboundMessage(
            to: $this->input('to'),
            type: $type,
            payload: $payload,
            idempotencyKey: is_string($idempotency) ? $idempotency : null,
            clientReference: $this->input('client_reference'),
        );
    }

    public function mode(): string
    {
        return $this->header('X-Send-Mode')
            ?: $this->input('mode')
            ?: (string) config('waba.outbound.default_mode', 'queue');
    }
}
```

- [ ] **Step 2: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/SendMessageRequest.php
git commit -m "feat(waba): add SendMessageRequest with toDto"
```

---

## Task 15: Send/List/Show invokable actions + routes — TDD

**Files:**
- Test: `tests/Feature/Outbound/SendMessageEndpointTest.php`
- Create: `app/Http/Actions/SendMessageActionApi.php`
- Create: `app/Http/Actions/ListMessagesActionApi.php`
- Create: `app/Http/Actions/ShowMessageActionApi.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Models\Message;
use App\Waba\Facades\Waba;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function mkAuthHeader(Channel $channel, array $abilities = ['*']): array
{
    $prefix = 'wba_'.Str::lower(Str::random(8));
    $secret = Str::random(40);
    ChannelApiKey::factory()->for($channel)->create([
        'prefix' => $prefix,
        'key_hash' => hash('sha256', $secret),
        'abilities' => $abilities,
    ]);

    return ['Authorization' => "Bearer {$prefix}_{$secret}"];
}

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
});

it('queues a text send', function () {
    Queue::fake();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->postJson('/api/v1/channels/main/messages', [
            'to' => '+62811',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'pending');
});

it('returns 200 sync with sent status', function () {
    Waba::fake();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers + ['X-Send-Mode' => 'sync'])
        ->postJson('/api/v1/channels/main/messages', [
            'to' => '+62811',
            'type' => 'text',
            'text' => ['body' => 'hi'],
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.status', 'sent');
});

it('rejects without messages:send ability', function () {
    $headers = mkAuthHeader($this->channel, ['messages:read']);

    $this->withHeaders($headers)
        ->postJson('/api/v1/channels/main/messages', ['to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi']])
        ->assertStatus(403);
});

it('replays idempotent send', function () {
    Waba::fake();
    $headers = mkAuthHeader($this->channel) + ['X-Send-Mode' => 'sync', 'Idempotency-Key' => 'k1'];

    $first = $this->withHeaders($headers)->postJson('/api/v1/channels/main/messages', [
        'to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi'],
    ])->assertStatus(200)->json('data.id');

    $second = $this->withHeaders($headers)->postJson('/api/v1/channels/main/messages', [
        'to' => '+62811', 'type' => 'text', 'text' => ['body' => 'hi'],
    ])->assertStatus(200)
      ->assertHeader('X-Idempotent-Replay', 'true')
      ->json('data.id');

    expect($second)->toBe($first);
});

it('lists messages', function () {
    Message::factory()->for($this->channel)->count(3)->create();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->getJson('/api/v1/channels/main/messages')
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('shows a single message', function () {
    $m = Message::factory()->for($this->channel)->create();
    $headers = mkAuthHeader($this->channel);

    $this->withHeaders($headers)
        ->getJson("/api/v1/channels/main/messages/{$m->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $m->id);
});
```

- [ ] **Step 2: Implement `SendMessageActionApi`**

```php
<?php

namespace App\Http\Actions;

use App\Http\Requests\SendMessageRequest;
use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Waba\Outbound\DispatchService;
use Illuminate\Http\JsonResponse;

class SendMessageActionApi
{
    public function __invoke(SendMessageRequest $request, DispatchService $dispatcher): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');
        /** @var ChannelApiKey $apiKey */
        $apiKey = $request->attributes->get('apiKey');

        $message = $dispatcher->dispatch($channel, $apiKey, $request->toDto(), $request->mode());

        $replay = (bool) ($message->wasIdempotentReplay ?? false);
        $statusCode = $request->mode() === 'sync' ? 200 : ($replay ? 200 : 202);

        $response = response()->json([
            'data' => [
                'id' => $message->id,
                'status' => $message->status,
                'channel' => $channel->name,
                'to' => $message->to_number,
                'type' => $message->type,
                'provider_message_id' => $message->provider_message_id,
                'sent_at' => optional($message->sent_at)->toIso8601String(),
                'queued_at' => optional($message->created_at)->toIso8601String(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ], $statusCode);

        if ($replay) {
            $response->header('X-Idempotent-Replay', 'true');
        }

        return $response;
    }
}
```

- [ ] **Step 3: Implement `ListMessagesActionApi`**

```php
<?php

namespace App\Http\Actions;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListMessagesActionApi
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');

        $rows = Message::where('channel_id', $channel->id)
            ->orderByDesc('created_at')
            ->limit((int) $request->query('limit', 50))
            ->get();

        return response()->json([
            'data' => $rows->map(fn (Message $m) => $this->present($m, $channel))->values(),
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }

    /** @return array<string,mixed> */
    private function present(Message $m, Channel $channel): array
    {
        return [
            'id' => $m->id,
            'status' => $m->status,
            'channel' => $channel->name,
            'to' => $m->to_number,
            'type' => $m->type,
            'provider_message_id' => $m->provider_message_id,
            'sent_at' => optional($m->sent_at)->toIso8601String(),
            'created_at' => optional($m->created_at)->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 4: Implement `ShowMessageActionApi`**

```php
<?php

namespace App\Http\Actions;

use App\Models\Channel;
use App\Models\Message;
use App\Waba\Exceptions\MessageNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowMessageActionApi
{
    public function __invoke(Request $request, string $channelParam, string $id): JsonResponse
    {
        /** @var Channel $channel */
        $channel = $request->attributes->get('channel');

        $m = Message::where('channel_id', $channel->id)->find($id);

        if (! $m) {
            throw MessageNotFoundException::id($id);
        }

        return response()->json([
            'data' => [
                'id' => $m->id,
                'status' => $m->status,
                'channel' => $channel->name,
                'to' => $m->to_number,
                'type' => $m->type,
                'provider_message_id' => $m->provider_message_id,
                'sent_at' => optional($m->sent_at)->toIso8601String(),
                'delivered_at' => optional($m->delivered_at)->toIso8601String(),
                'read_at' => optional($m->read_at)->toIso8601String(),
                'failed_at' => optional($m->failed_at)->toIso8601String(),
                'error_code' => $m->error_code,
                'error_message' => $m->error_message,
                'created_at' => optional($m->created_at)->toIso8601String(),
            ],
            'request_id' => $request->attributes->get('request_id'),
        ]);
    }
}
```

- [ ] **Step 5: Register routes in `routes/api.php`**

Replace the existing `Route::middleware('channel.apikey')->prefix('v1/channels/{channel}')->group(...)` block with:

```php
Route::middleware('channel.apikey')
    ->prefix('v1/channels/{channel}')
    ->group(function () {
        Route::get('/ping', fn () => response()->json(['ok' => true]));

        Route::post('/messages', \App\Http\Actions\SendMessageActionApi::class)
            ->middleware('assert.ability:messages:send');
        Route::get('/messages', \App\Http\Actions\ListMessagesActionApi::class)
            ->middleware('assert.ability:messages:read');
        Route::get('/messages/{id}', \App\Http\Actions\ShowMessageActionApi::class)
            ->middleware('assert.ability:messages:read');
    });
```

- [ ] **Step 6: Run — iterate**

```
vendor/bin/pest tests/Feature/Outbound/SendMessageEndpointTest.php
```

- [ ] **Step 7: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Http/Actions routes/api.php tests/Feature/Outbound/SendMessageEndpointTest.php
git commit -m "feat(waba): add Send/List/Show message API actions"
```

---

## Task 16: Webhook ingress extension + status callback test

**Files:**
- Modify: `app/Http/Webhooks/HandleInboundWebhook.php`
- Test: `tests/Feature/Outbound/StatusCallbackTest.php`

- [ ] **Step 1: Write test**

```php
<?php

use App\Models\Channel;
use App\Models\Message;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    $this->channel = Channel::factory()->create(['name' => 'main']);
});

it('accepts and applies status callback', function () {
    $m = Message::factory()->for($this->channel)->create([
        'provider_message_id' => 'q-1',
        'status' => 'sent',
    ]);

    $this->postJson('/api/v1/webhooks/qiscus/main', [
        'event' => 'status',
        'data' => ['message_id' => 'q-1', 'status' => 'delivered', 'timestamp' => now()->toIso8601String()],
    ])->assertStatus(202);

    expect($m->fresh()->status)->toBe('delivered');
    expect($m->statusEvents()->count())->toBe(1);
});

it('returns 202 for inbound message (P3 stub)', function () {
    $this->postJson('/api/v1/webhooks/qiscus/main', [
        'event' => 'message',
        'data' => ['from' => '+62811', 'text' => 'hi'],
    ])->assertStatus(202)
      ->assertJsonPath('note', 'inbound_p3_pending');
});
```

- [ ] **Step 2: Replace `HandleInboundWebhook`**

```php
<?php

namespace App\Http\Webhooks;

use App\Models\Channel;
use App\Waba\Facades\Waba;
use App\Waba\Outbound\StatusRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HandleInboundWebhook
{
    public function __invoke(Request $request, string $provider, string $channel, StatusRecorder $recorder): JsonResponse
    {
        $providerInstance = Waba::channel($channel);
        $payload = $request->json()->all();

        $statusEvent = $providerInstance->normalizeStatus($payload);
        if ($statusEvent !== null) {
            $channelRow = Channel::where('name', $channel)->firstOrFail();
            $recorder->record($channelRow, $statusEvent, $payload);

            return response()->json(['accepted' => true], 202);
        }

        // Inbound message — P3 fills.
        return response()->json(['accepted' => true, 'note' => 'inbound_p3_pending'], 202);
    }
}
```

- [ ] **Step 3: Run — expect PASS**

```
vendor/bin/pest tests/Feature/Outbound/StatusCallbackTest.php
```

- [ ] **Step 4: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Http/Webhooks/HandleInboundWebhook.php tests/Feature/Outbound/StatusCallbackTest.php
git commit -m "feat(waba): route status callbacks via StatusRecorder"
```

---

## Task 17: Restify MessageRepository (read-only) — TDD

**Files:**
- Test: `tests/Feature/Restify/MessageRepositoryTest.php`
- Create: `app/Restify/MessageRepository.php`

- [ ] **Step 1: Write failing test**

```php
<?php

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
    $this->channel = Channel::factory()->create();
});

it('lists messages', function () {
    Message::factory()->for($this->channel)->count(2)->create();

    $this->getJson('/api/restify/messages')
        ->assertOk()
        ->assertJsonPath('meta.total', 2);
});

it('shows a message', function () {
    $m = Message::factory()->for($this->channel)->create();

    $this->getJson("/api/restify/messages/{$m->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $m->id);
});

it('matches by status', function () {
    Message::factory()->for($this->channel)->sent()->create();
    Message::factory()->for($this->channel)->failed()->create();

    $this->getJson('/api/restify/messages?status=failed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1);
});
```

- [ ] **Step 2: Implement repository**

```php
<?php

namespace App\Restify;

use App\Models\Message;
use Binaryk\LaravelRestify\Http\Requests\RestifyRequest;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

class MessageRepository extends Repository
{
    public static string $model = Message::class;

    public static array $search = ['provider_message_id', 'to_number'];

    public static array $match = [
        'channel_id' => 'string',
        'status' => 'string',
        'type' => 'string',
        'direction' => 'string',
    ];

    public function fields(RestifyRequest $request): array
    {
        return [
            id(),
            field('channel_id')->readonly(),
            field('direction')->readonly(),
            field('to_number')->readonly(),
            field('from_number')->readonly(),
            field('type')->readonly(),
            field('payload')->readonly(),
            field('status')->readonly(),
            field('provider_message_id')->readonly(),
            field('idempotency_key')->readonly(),
            field('error_code')->readonly(),
            field('error_message')->readonly(),
            field('attempts')->readonly(),
            field('sent_at')->datetime()->readonly(),
            field('delivered_at')->datetime()->readonly(),
            field('read_at')->datetime()->readonly(),
            field('failed_at')->datetime()->readonly(),
            field('created_at')->datetime()->readonly(),
        ];
    }

    public function store(RestifyRequest $request)
    {
        throw new MethodNotAllowedHttpException(['GET']);
    }

    public function update(RestifyRequest $request, $repositoryId)
    {
        throw new MethodNotAllowedHttpException(['GET']);
    }

    public function destroy(RestifyRequest $request, $repositoryId)
    {
        throw new MethodNotAllowedHttpException(['GET']);
    }
}
```

> Verify the parent Repository's `store/update/destroy` signatures by inspecting `vendor/binaryk/laravel-restify/src/Repositories/Repository.php`. If they differ, adjust the override signatures here. The override goal is to refuse writes — any equivalent (override + throw, or alternative disable mechanism the version supports) is acceptable.

- [ ] **Step 3: Run — iterate**

```
vendor/bin/pest tests/Feature/Restify/MessageRepositoryTest.php
```

- [ ] **Step 4: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Restify/MessageRepository.php tests/Feature/Restify/MessageRepositoryTest.php
git commit -m "feat(waba): add read-only MessageRepository"
```

---

## Task 18: Restify SendMessageAction — TDD

**Files:**
- Test: `tests/Feature/Restify/SendMessageRestifyActionTest.php`
- Create: `app/Restify/Actions/SendMessageAction.php`
- Modify: `app/Restify/ChannelRepository.php` (register the action)

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
    Waba::fake();
});

it('admin sends via restify action sync mode', function () {
    $channel = Channel::factory()->create();

    $response = $this->postJson("/api/restify/channels/{$channel->id}/actions?action=send-message", [
        'to' => '+62811',
        'type' => 'text',
        'text' => ['body' => 'hi'],
    ])->assertOk()->json();

    expect(data_get($response, 'data.status'))->toBe('sent');
});
```

- [ ] **Step 2: Implement action**

```php
<?php

namespace App\Restify\Actions;

use App\Http\Requests\SendMessageRequest;
use App\Models\Channel;
use App\Models\ChannelApiKey;
use App\Waba\Outbound\DispatchService;
use Binaryk\LaravelRestify\Actions\Action;
use Binaryk\LaravelRestify\Http\Requests\ActionRequest;
use Illuminate\Support\Collection;

class SendMessageAction extends Action
{
    public static $uriKey = 'send-message';

    public function handle(ActionRequest $request, $models)
    {
        $models = $models instanceof Collection ? $models : collect([$models]);
        /** @var Channel $channel */
        $channel = $models->first();

        $send = SendMessageRequest::createFrom($request, new SendMessageRequest());
        $send->setContainer(app())->setRedirector(app('redirect'));
        $send->validateResolved();

        $apiKey = ChannelApiKey::where('channel_id', $channel->id)->active()->first()
            ?? ChannelApiKey::factory()->for($channel)->create(['abilities' => ['*']]);

        $msg = app(DispatchService::class)->dispatch($channel, $apiKey, $send->toDto(), 'sync');

        return response()->json([
            'data' => [
                'id' => $msg->id,
                'status' => $msg->status,
                'channel' => $channel->name,
                'provider_message_id' => $msg->provider_message_id,
            ],
        ]);
    }
}
```

> If Restify's Action passes the model directly (not Collection), the `$models instanceof Collection` check above handles both cases. Refer to existing `ProbeChannelAction` (built in P1 Task 16) for the actual signature in this codebase.

- [ ] **Step 3: Register in `ChannelRepository::actions()`**

```php
public function actions(\Binaryk\LaravelRestify\Http\Requests\RestifyRequest $request): array
{
    return [
        \App\Restify\Actions\ProbeChannelAction::new(),
        \App\Restify\Actions\SendMessageAction::new(),
    ];
}
```

- [ ] **Step 4: Run — iterate**

```
vendor/bin/pest tests/Feature/Restify/SendMessageRestifyActionTest.php
```

- [ ] **Step 5: Commit**

```
vendor/bin/pint --dirty --format agent
git add app/Restify/Actions/SendMessageAction.php app/Restify/ChannelRepository.php tests/Feature/Restify/SendMessageRestifyActionTest.php
git commit -m "feat(waba): add SendMessageAction Restify action"
```

---

## Task 19: Purge command + arch test + final sweep

**Files:**
- Create: `app/Console/Commands/PurgeMessagePayloadsCommand.php`
- Create: `tests/Arch/OutboundIsolationTest.php`

- [ ] **Step 1: Write purge command**

```php
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
```

- [ ] **Step 2: Write arch test**

```php
<?php

arch('outbound services do not depend on controllers')
    ->expect('App\Waba\Outbound')
    ->not->toUse('Illuminate\Routing\Controller');

arch('outbound services do not depend on http requests directly')
    ->expect('App\Waba\Outbound')
    ->not->toUse('Illuminate\Http\Request');
```

- [ ] **Step 3: Full suite**

```
php artisan test --compact
```

All tests must pass. If any fail, STOP and fix before commit. Report failing tests + error messages.

- [ ] **Step 4: Pint sweep**

```
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 5: Final commit**

```
git add app/Console/Commands/PurgeMessagePayloadsCommand.php tests/Arch/OutboundIsolationTest.php
git diff --cached --quiet || git commit -m "feat(waba): add purge command + outbound arch tests"
```

If pint changed unrelated files:

```
git add -A
git diff --cached --quiet || git commit -m "chore(waba): pint sweep for P2"
```

---

## Acceptance (matches spec §15)

- [ ] Migrations applied; `messages` + `message_status_events` exist with indexes.
- [ ] `Message` + `MessageStatusEvent` models with relations, scopes, casts.
- [ ] `OutboundMessage` + 6 payload DTOs in place; `SendResult`, `NormalizedStatusEvent` updated/added.
- [ ] `MessageProvider` extended with `normalizeStatus()`.
- [ ] `QiscusDriver::send()` implements all 6 types; `normalizeStatus()` maps webhook; `verifyWebhookSignature()` HMAC-SHA256.
- [ ] `DispatchService`, `SendMessageJob`, `IdempotencyStore`, `StatusRecorder` implemented and tested.
- [ ] `PermanentSendException`, `IdempotencyMismatchException`, `MessageNotFoundException` registered (handled by P1 envelope).
- [ ] `AssertAbility` middleware aliased and applied.
- [ ] Bare routes `POST/GET /api/v1/channels/{channel}/messages` (+show) under `channel.apikey` + `assert.ability`.
- [ ] Restify `MessageRepository` (read-only) + `SendMessageAction` (admin write).
- [ ] `HandleInboundWebhook` routes status callbacks via `StatusRecorder`.
- [ ] `config/waba.php` `outbound` block extended; `.env.example` updated.
- [ ] Idempotency replay returns 200 + `X-Idempotent-Replay: true`.
- [ ] All test suites green; `vendor/bin/pint --dirty --format agent` clean.
