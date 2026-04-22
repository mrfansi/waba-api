# WABA Outbound Send Pipeline — Design (Sub-project P2)

**Date:** 2026-04-22
**Scope:** P2 of 6. Builds on P1 (Core Foundation). Subsequent: P3 Inbound, P4 Templates, P5 Media, P6 Conversations.
**Status:** Approved design, pending implementation plan.

---

## 1. Goal

Ship the outbound send pipeline: send WhatsApp messages of all 6 types (text, media, template, interactive, location, contact) via Qiscus driver, with sync + queued dispatch modes, idempotency, retry policy, status-callback ingestion, full audit trail (request + response payload retention), and ability-scoped per-channel API key authorization.

## 2. Non-goals (P2)

- Inbound message normalization & fanout (P3 owns).
- Template management (CRUD/sync) — P4. P2 only sends pre-existing approved templates by name.
- Media upload through this API (P5). P2 sends media by URL only (BSP fetches).
- Conversation threading (P6).

## 3. Architecture

### 3.1 Component layout

```
app/
├── Waba/
│   ├── Dto/
│   │   ├── OutboundMessage.php           # extend P1: typed payload, idempotencyKey, clientReference
│   │   ├── SendResult.php                # extend P1: status, providerMessageId, sentAt
│   │   ├── NormalizedStatusEvent.php     # NEW
│   │   └── MessagePayloads/              # NEW
│   │       ├── TextPayload.php
│   │       ├── MediaPayload.php
│   │       ├── TemplatePayload.php
│   │       ├── InteractivePayload.php
│   │       ├── LocationPayload.php
│   │       └── ContactPayload.php
│   ├── Outbound/                          # NEW
│   │   ├── DispatchService.php
│   │   ├── SendMessageJob.php
│   │   ├── IdempotencyStore.php
│   │   └── StatusRecorder.php
│   ├── Drivers/
│   │   └── QiscusDriver.php               # extend: implement send(), normalizeStatus()
│   └── Exceptions/
│       ├── PermanentSendException.php     # NEW (422, no-retry)
│       ├── IdempotencyMismatchException.php  # NEW (409)
│       └── MessageNotFoundException.php   # NEW (404)
├── Models/
│   ├── Message.php                        # NEW
│   └── MessageStatusEvent.php             # NEW
├── Http/
│   ├── Actions/                           # NEW (invokable, channel.apikey-guarded)
│   │   ├── SendMessageActionApi.php
│   │   ├── ListMessagesActionApi.php
│   │   └── ShowMessageActionApi.php
│   ├── Middleware/
│   │   └── AssertAbility.php              # NEW alias `assert.ability`
│   ├── Requests/
│   │   └── SendMessageRequest.php         # NEW (FormRequest with toDto())
│   └── Webhooks/
│       └── HandleInboundWebhook.php       # extend P1 stub: route status callbacks
├── Restify/
│   ├── MessageRepository.php              # NEW (read-only)
│   └── Actions/
│       └── SendMessageAction.php          # NEW (admin send)
database/migrations/
  - 2026_04_22_xxxxxx_create_messages_table.php
  - 2026_04_22_xxxxxx_create_message_status_events_table.php
config/waba.php → extend `outbound`
```

### 3.2 Boundary rules

- `DispatchService` is the single seam invoked by both `SendMessageActionApi` (server-to-server) and `SendMessageAction` (Restify admin). No other class persists outbound `Message` rows directly.
- `SendMessageJob` orchestrates only — calls `DispatchService::attemptSend()`. No driver knowledge.
- `MessageProvider::send()` returns `SendResult` or throws `PermanentSendException` (4xx) / `DriverException` (5xx-class) / `DriverTimeoutException`.
- Driver classes still must not import `App\Models\*` (enforced by P1 arch test). `DispatchService`, `StatusRecorder`, `IdempotencyStore` may.

### 3.3 Extensibility

P2 contract additions to `MessageProvider` are mandatory for all drivers. New drivers must implement `send()` and `normalizeStatus()`. The default behaviour for unimplemented methods (still applicable to template/media methods deferred to P4/P5) remains throwing `DriverException::notImplemented(__METHOD__)`.

---

## 4. Domain model

### 4.1 `messages` table

| column | type | notes |
|---|---|---|
| id | ulid PK | also returned to client as `message_id` |
| channel_id | foreignUlid → channels, cascade | indexed |
| direction | enum(`outbound`,`inbound`) | P2 only writes outbound; P3 writes inbound |
| to_number | string(32) | E.164 recipient |
| from_number | string(32) | denorm of channel.phone_number |
| type | enum(`text`,`media`,`template`,`interactive`,`location`,`contact`) | |
| payload | json | matches MessagePayloads DTO structure |
| status | enum(`pending`,`sending`,`sent`,`delivered`,`read`,`failed`) | denorm of latest event |
| provider_message_id | string(128) nullable | indexed |
| idempotency_key | string(64) nullable | |
| api_key_id | foreignUlid → channel_api_keys nullable, restrictOnDelete | audit |
| error_code | string(64) nullable | |
| error_message | text nullable | |
| request_payload | json nullable | raw HTTP body sent to BSP |
| response_payload | json nullable | raw HTTP body received from BSP |
| sent_at | timestamp nullable | |
| delivered_at | timestamp nullable | |
| read_at | timestamp nullable | |
| failed_at | timestamp nullable | |
| attempts | unsigned tinyint default 0 | |
| created_at / updated_at | timestamps | |

Indexes:
- `(channel_id, status, created_at)` — dashboard queries
- `(channel_id, provider_message_id)` — status callback lookup
- `unique(api_key_id, idempotency_key)` — SQL spec allows multiple NULLs across both backends (SQLite + MySQL); collision only when both columns non-null and identical
- `created_at` — pagination

Retention: `request_payload`, `response_payload` purged by scheduled command `php artisan waba:purge-payloads` after `config('waba.outbound.retention.request_payload_days')` days. Command shipped in P2; cron registration left to operator.

### 4.2 `message_status_events` table

| column | type | notes |
|---|---|---|
| id | ulid PK | |
| message_id | foreignUlid → messages, cascade | |
| status | string(32) | `accepted`/`sent`/`delivered`/`read`/`failed` |
| occurred_at | timestamp | provider-reported timestamp (fallback `now()`) |
| raw_payload | json | full provider event |
| created_at | timestamp default now() | no `updated_at` |

Indexes: `(message_id, occurred_at)`, `(status, created_at)`.

### 4.3 Models

- `Message` — `HasFactory`, `HasUlids`. Fillable excludes timestamps/status (system-managed). Casts: `payload`, `request_payload`, `response_payload` → `array`; status timestamps → `datetime`. Relations: `channel()`, `apiKey()`, `statusEvents()`. Scopes: `outbound()`, `inbound()`, `pending()`, `failed()`. Hidden: `request_payload`, `response_payload` (reveal only when actor is admin via Restify).
- `MessageStatusEvent` — `HasFactory`, `HasUlids`. `UPDATED_AT = null`. Cast `raw_payload` → `array`. Relation `message()`.

---

## 5. DTO contracts

### 5.1 `OutboundMessage` (extends P1 stub)

```php
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

### 5.2 Payload DTOs

```php
final readonly class TextPayload {
    public function __construct(public string $body, public bool $previewUrl = false) {}
}

final readonly class MediaPayload {
    public function __construct(
        public string $kind,             // image|video|audio|document|sticker
        public string $url,
        public ?string $caption = null,
        public ?string $filename = null,
    ) {}
}

final readonly class TemplatePayload {
    /** @param array<int,array{type:string,parameters:array<int,mixed>}> $components */
    public function __construct(
        public string $name,
        public string $language,
        public array $components = [],
    ) {}
}

final readonly class InteractivePayload {
    public function __construct(
        public string $kind,             // button|list
        public string $body,
        /** @var array<int,array<string,mixed>> */ public array $action,
        public ?string $header = null,
        public ?string $footer = null,
    ) {}
}

final readonly class LocationPayload {
    public function __construct(
        public float $latitude,
        public float $longitude,
        public ?string $name = null,
        public ?string $address = null,
    ) {}
}

final readonly class ContactPayload {
    /** @param array<int,array<string,mixed>> $contacts */
    public function __construct(public array $contacts) {}
}
```

### 5.3 `SendResult` (extends P1)

```php
final readonly class SendResult
{
    public function __construct(
        public string $providerMessageId,
        public string $status,           // accepted|sent
        public array $raw = [],
        public ?\DateTimeInterface $sentAt = null,
    ) {}
}
```

### 5.4 `NormalizedStatusEvent`

```php
final readonly class NormalizedStatusEvent
{
    public function __construct(
        public string $providerMessageId,
        public string $status,           // sent|delivered|read|failed
        public \DateTimeInterface $occurredAt,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}
}
```

---

## 6. Driver contract additions

`MessageProvider` gains:

```php
/** @param array<string,mixed> $rawPayload */
public function normalizeStatus(array $rawPayload): ?NormalizedStatusEvent;
```

Returns `null` when payload is not a status event (inbound message — P3 territory).

`QiscusDriver::send()` and `QiscusDriver::normalizeStatus()` implemented in P2 per Qiscus REST docs:
- Send endpoint: confirmed during implementation spike (path `POST {base_url}/{app_id}/api/v1/qiscus/whatsapp/{phone_number_id}/send` is the target reference).
- Status webhook payload contains `event=status`, `data.message_id`, `data.status`. Map to `NormalizedStatusEvent`.

---

## 7. Dispatch flow

### 7.1 `DispatchService::dispatch(Channel $channel, ChannelApiKey $apiKey, OutboundMessage $msg, string $mode = 'queue'): Message`

1. **Idempotency lookup** — when `$msg->idempotencyKey` set:
   - Check `IdempotencyStore::find($apiKey->id, $msg->idempotencyKey)`. Hit → compare request hash; if matches return cached `Message`; if mismatch throw `IdempotencyMismatchException`.
2. **Persist row** — INSERT `messages` with status=`pending`, attempts=0, payload serialized; request hash stored separately via `IdempotencyStore::remember`.
3. **Mode dispatch:**
   - `sync`: call `attemptSend($message)` directly within the request lifecycle, bounded by `config('waba.outbound.sync_timeout_seconds')`. Return updated row (response-time send result).
   - `queue` (default): `SendMessageJob::dispatch($message->id)` onto configured connection + queue name. Return row immediately (status=`pending`).

### 7.2 `DispatchService::attemptSend(Message $m): Message`

Used by both sync path and `SendMessageJob`.

1. UPDATE status=`sending`, attempts++.
2. Build `OutboundMessage` from `$m->payload`.
3. `Waba::channel($m->channel->name)->send($outbound)`.
4. Capture full HTTP request/response into `$m->request_payload`/`$m->response_payload` via the driver's `lastTransaction()` accessor (see §7.3).
5. On `SendResult`: UPDATE status=`sent`, `provider_message_id`, `sent_at`. Append `MessageStatusEvent('sent', occurred_at=$result->sentAt ?? now(), raw=$result->raw)`.
6. On `PermanentSendException $e`: UPDATE status=`failed`, `failed_at`, `error_code=$e->errorCode()`, `error_message=$e->getMessage()`. Append `MessageStatusEvent('failed', raw=$e->details())`. Caller (job) handles short-circuit via `$job->fail()`.
7. On `DriverException`/`DriverTimeoutException`: re-throw → job retries.

### 7.3 Capturing raw request/response

Driver exposes `lastTransaction(): ?array{request:array<string,mixed>, response:array<string,mixed>}` populated after each `send()` call. `DispatchService` reads this and writes to `Message`. `QiscusDriver::send()` populates the property by capturing the Laravel HTTP client request body and response body before returning.

### 7.4 `SendMessageJob`

```php
class SendMessageJob implements ShouldQueue
{
    public int $tries;
    public function __construct(public string $messageId) { $this->tries = config('waba.outbound.retry.attempts'); }
    public function backoff(): array { return config('waba.outbound.retry.backoff_seconds'); }
    public function handle(DispatchService $svc): void {
        $m = Message::findOrFail($this->messageId);
        try { $svc->attemptSend($m); }
        catch (PermanentSendException $e) { $this->fail($e); }
    }
    public function failed(Throwable $e): void {
        Message::where('id', $this->messageId)
            ->whereNotIn('status', ['sent','delivered','read','failed'])
            ->update(['status' => 'failed', 'failed_at' => now(), 'error_message' => $e->getMessage()]);
    }
}
```

### 7.5 `IdempotencyStore`

- `find(string $apiKeyId, string $key): ?array{message_id:string, request_hash:string}` — Cache::get under key `waba:idem:{apiKeyId}:{key}`.
- `remember(string $apiKeyId, string $key, string $messageId, string $requestHash): void` — Cache::put with TTL `config('waba.outbound.idempotency.ttl_hours') * 3600`.
- DB unique constraint on `(api_key_id, idempotency_key)` is the source of truth: race-condition second insert catches `QueryException`, refetches existing row, repopulates cache.

---

## 8. HTTP surface

### 8.1 Server-to-server (channel API key)

| Method | Path | Middleware | Ability | Purpose |
|---|---|---|---|---|
| POST | `/api/v1/channels/{channel}/messages` | `channel.apikey`, `assert.ability:messages:send` | `messages:send` | Send |
| GET | `/api/v1/channels/{channel}/messages` | `channel.apikey`, `assert.ability:messages:read` | `messages:read` | List (paginated) |
| GET | `/api/v1/channels/{channel}/messages/{id}` | `channel.apikey`, `assert.ability:messages:read` | `messages:read` | Show |

Routes registered in `routes/api.php` under existing `channel.apikey` group (alongside `/ping`).

Headers:
- `Authorization: Bearer wba_<prefix>_<secret>` (or `X-Api-Key`).
- `Idempotency-Key: <opaque-string ≤ 64 chars>` (optional).
- `X-Send-Mode: sync|queue` (optional, default `queue`). Body `mode` field accepted as alternative.

### 8.2 Send response shapes

Queued (default):
```http
202 Accepted
{
  "data": {
    "id": "01HZ...", "status": "pending", "channel": "sales",
    "to": "+62811...", "type": "text", "queued_at": "2026-04-22T10:11:12Z"
  },
  "request_id": "01HZ..."
}
```

Sync success:
```http
200 OK
{
  "data": {
    "id": "01HZ...", "status": "sent", "provider_message_id": "qiscus-...",
    "sent_at": "2026-04-22T10:11:12Z", "channel": "sales", "to": "+62811...", "type": "text"
  },
  "request_id": "01HZ..."
}
```

Sync provider rejection:
```http
422 Unprocessable
{
  "error": {
    "code": "provider_rejected",
    "message": "Invalid recipient number",
    "details": { "provider": "qiscus", "upstream_status": 400, "message_id": "01HZ..." }
  },
  "request_id": "01HZ..."
}
```

Idempotent replay:
```http
200 OK
X-Idempotent-Replay: true
{ "data": { "id": "01HZ...", "status": "sent", ... } }
```

### 8.3 Admin (Sanctum)

| Surface | Notes |
|---|---|
| `MessageRepository` | Read-only Restify repo. `$search = ['provider_message_id', 'to_number']`. `$match = ['channel_id' => 'string', 'status' => 'string', 'type' => 'string', 'direction' => 'string']`. Index/show only — store/update/delete disabled. |
| `SendMessageAction` | Restify Action on `ChannelRepository` (matches `ProbeChannelAction` pattern). Uses `DispatchService` with default sync mode. |

### 8.4 Webhook ingress

`HandleInboundWebhook` (existing P1 stub, extended in P2) routes payloads:

```php
public function __invoke(Request $request, string $provider, string $channel)
{
    $providerInstance = Waba::channel($channel);
    $providerInstance->verifyWebhookSignature($request->getContent(), $request->headers->all());

    $statusEvent = $providerInstance->normalizeStatus($request->json()->all());
    if ($statusEvent !== null) {
        app(StatusRecorder::class)->record(
            Channel::where('name', $channel)->firstOrFail(),
            $statusEvent,
            $request->json()->all(),
        );
        return response()->json(['accepted' => true], 202);
    }

    // Inbound message — P3 fills.
    return response()->json(['accepted' => true, 'note' => 'inbound_p3_pending'], 202);
}
```

Signature verification implementation: P2 provides a baseline implementation in `QiscusDriver::verifyWebhookSignature()` (HMAC-SHA256 over body using `webhook_secret`); P3 hardens around delivery-replay protection and per-event signing schemes.

---

## 9. `StatusRecorder`

`record(Channel $channel, NormalizedStatusEvent $event, array $rawPayload): void`

1. `Message::query()->where('channel_id', $channel->id)->where('provider_message_id', $event->providerMessageId)->lockForUpdate()->first()`. If null → log warning, return.
2. Insert `MessageStatusEvent(message_id, status=$event->status, occurred_at=$event->occurredAt, raw_payload=$rawPayload)`.
3. Compute new denorm `status` per monotonicity rule:
   - Order: `pending=0, sending=1, sent=2, delivered=3, read=4`.
   - `failed` is terminal; ignore non-failed updates after `failed`.
   - `read` is terminal forward; never downgrade to `delivered`.
   - Only update when new status > current ordinal (or transitioning to `failed` from a non-terminal state).
4. Persist matching timestamp column (`delivered_at` etc.).

---

## 10. Authorization

### 10.1 `AssertAbility` middleware (alias `assert.ability`)

```php
public function handle(Request $request, Closure $next, string $ability): Response
{
    $apiKey = $request->attributes->get('apiKey');
    if (! $apiKey || ! $apiKey->tokenCan($ability)) {
        throw new InsufficientAbilityException($ability);
    }
    return $next($request);
}
```

Registered via `bootstrap/app.php` middleware alias.

### 10.2 Admin via Sanctum

Restify `MessageRepository` and `SendMessageAction` rely on existing P1 `auth:sanctum` global Restify middleware. Sanctum tokens carry implicit full access for admin operations (no scope check inside Restify path).

---

## 11. Configuration

### 11.1 `config/waba.php` `outbound` (extends P1)

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
        'cache_store' => env('WABA_IDEMPOTENCY_STORE'),  // null = default
    ],
    'retention' => [
        'request_payload_days' => 30,
    ],
],
```

### 11.2 `.env.example` additions

```
WABA_SYNC_TIMEOUT=15
WABA_RETRY_ATTEMPTS=3
WABA_IDEMPOTENCY_STORE=
```

---

## 12. Error handling additions

| Exception | HTTP | `error.code` |
|---|---|---|
| `PermanentSendException` | 422 | `provider_rejected` |
| `IdempotencyMismatchException` | 409 | `idempotency_conflict` |
| `MessageNotFoundException` | 404 | `message_not_found` |

All inherit `WabaException`, rendered via P1 envelope (`bootstrap/app.php`).

---

## 13. Testing

| Suite | File | Scope |
|---|---|---|
| Feature | `tests/Feature/Outbound/SendMessageEndpointTest.php` | All 6 types, sync/queue, idempotency replay, 4xx rejection, ability denial, missing channel |
| Feature | `tests/Feature/Outbound/SendMessageJobTest.php` | Retry on transient, no-retry on permanent, attempts increment, terminal `failed` |
| Feature | `tests/Feature/Outbound/StatusCallbackTest.php` | Webhook updates row + appends event; out-of-order; unknown id no-op |
| Feature | `tests/Feature/Restify/MessageRepositoryTest.php` | Index/show under Sanctum, match/search filters |
| Feature | `tests/Feature/Restify/SendMessageRestifyActionTest.php` | Admin send via Restify Action |
| Unit | `tests/Unit/Waba/DispatchServiceTest.php` | persist+route logic with `Waba::fake()` |
| Unit | `tests/Unit/Waba/StatusRecorderTest.php` | monotonicity rules, terminal states |
| Unit | `tests/Unit/Waba/IdempotencyStoreTest.php` | cache + DB fallback + mismatch detection |
| Unit | `tests/Unit/Waba/QiscusDriverSendTest.php` | Per-type request body shape via `Http::fake()`, `normalizeStatus` mapping |
| Arch | `tests/Arch/OutboundIsolationTest.php` | `app/Waba/Drivers/*` no Eloquent (existing rule extended); `app/Waba/Outbound/*` may import models but no `Illuminate\Routing\Controller` |

Coverage target: ≥ 80% on `app/Waba/Outbound/*`, `app/Waba/Dto/MessagePayloads/*`, `app/Models/Message.php`, `app/Models/MessageStatusEvent.php`, new middleware/exceptions.

`Waba::fake()` extended with `assertSent(callable $matcher)`, `assertNothingSent()`, `assertSentCount(int)` helpers via `FakeProvider`.

---

## 14. Open questions (resolve during implementation)

- Exact Qiscus send endpoint path and payload shape per type (documentation spike at start of Task 1 of plan).
- `inbound:status` cache to absorb webhook bursts — defer to P3 if needed.

---

## 15. Acceptance checklist

- [ ] Migrations create `messages` + `message_status_events` with indexes.
- [ ] `Message` + `MessageStatusEvent` models with relations, scopes, casts.
- [ ] `OutboundMessage` + 6 payload DTOs in place.
- [ ] `SendResult`, `NormalizedStatusEvent` DTOs.
- [ ] `MessageProvider` extended with `normalizeStatus()`.
- [ ] `QiscusDriver::send()` implements all 6 types; `normalizeStatus()` maps Qiscus status webhook; `verifyWebhookSignature()` baseline HMAC.
- [ ] `DispatchService`, `SendMessageJob`, `IdempotencyStore`, `StatusRecorder` implemented.
- [ ] `PermanentSendException`, `IdempotencyMismatchException`, `MessageNotFoundException` registered in handler.
- [ ] `AssertAbility` middleware aliased and applied.
- [ ] Bare routes `POST/GET /api/v1/channels/{channel}/messages` (+show).
- [ ] Restify `MessageRepository` + `SendMessageAction`.
- [ ] `HandleInboundWebhook` extended to route status callbacks via `StatusRecorder`.
- [ ] `config/waba.php` `outbound` block extended; `.env.example` updated.
- [ ] Idempotency replay returns 200 + `X-Idempotent-Replay: true`.
- [ ] All test suites green; coverage ≥ 80% for new code.
- [ ] `vendor/bin/pint --dirty --format agent` clean.
