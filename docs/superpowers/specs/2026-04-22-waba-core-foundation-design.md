# WABA Core Foundation — Design (Sub-project P1)

**Date:** 2026-04-22
**Scope:** P1 of 6 (Core Foundation). Subsequent sub-projects: P2 Outbound, P3 Inbound, P4 Templates, P5 Media, P6 Conversations.
**Status:** Approved design, pending implementation plan.

---

## 1. Goal

Build a driver-based orchestration layer over multiple WhatsApp providers (Qiscus first), exposing a uniform internal API and a Restify-first public API. P1 delivers only the foundation: channel model, provider contract, Qiscus driver skeleton, dual-auth (Sanctum + per-channel API key), configuration, error handling, and tests. Send/receive/template/media/conversation logic lands in P2–P6.

## 2. Non-goals (P1)

- Actual message sending, template sync, media I/O, inbound webhook ingest — stubs only.
- Multi-tenant workspaces (single-tenant, multi-channel only).
- Billing, rate analytics, audit log UI.

## 3. Architecture

### 3.1 Package layout

```
app/
├── Waba/
│   ├── Contracts/
│   │   └── MessageProvider.php
│   ├── Drivers/
│   │   └── QiscusDriver.php
│   ├── Support/
│   │   ├── WabaManager.php
│   │   └── ChannelResolver.php
│   ├── Exceptions/
│   │   ├── WabaException.php
│   │   ├── ChannelNotFoundException.php
│   │   ├── UnauthorizedChannelException.php
│   │   ├── InsufficientAbilityException.php
│   │   ├── DriverException.php
│   │   └── DriverTimeoutException.php
│   └── Dto/
│       ├── ChannelCredentials.php
│       ├── OutboundMessage.php
│       ├── SendResult.php
│       ├── NormalizedInboundEvent.php
│       ├── MediaReference.php
│       ├── MediaUpload.php
│       ├── TemplateDefinition.php
│       └── TemplateSyncResult.php
├── Models/
│   ├── Channel.php
│   └── ChannelApiKey.php
├── Http/
│   ├── Middleware/
│   │   ├── AuthenticateChannelApiKey.php
│   │   └── AssignRequestId.php
│   └── Webhooks/
│       └── HandleInboundWebhook.php        # stub invokable (P3 fills)
├── Restify/
│   ├── ChannelRepository.php
│   ├── ChannelApiKeyRepository.php
│   └── Actions/
│       └── ProbeChannelAction.php
├── Policies/
│   ├── ChannelPolicy.php
│   └── ChannelApiKeyPolicy.php
└── Providers/
    └── WabaServiceProvider.php
config/waba.php
database/migrations/
  xxxx_xx_xx_create_channels_table.php
  xxxx_xx_xx_create_channel_api_keys_table.php
```

### 3.2 Boundary rules

- Controllers / Restify repositories depend only on the `Waba` facade and Eloquent models. Never instantiate a driver directly.
- `app/Waba/Drivers/*` must not import `Illuminate\Database\Eloquent\*`. Enforced by Pest arch test.
- `ChannelResolver` is the single seam translating a `Channel` row into a bound `MessageProvider` instance.

### 3.3 Extensibility

New providers added by:
1. Creating class implementing `MessageProvider`.
2. Registering in `config/waba.php` under `providers.<name>.class`.
3. No code change in callers; new `driver` string accepted on `Channel` row.

---

## 4. Domain model

### 4.1 `channels` table

| column | type | notes |
|---|---|---|
| id | ulid PK | |
| name | string(64) unique | slug, e.g. `sales-qiscus` |
| display_name | string(128) | human label |
| driver | string(32) | `qiscus` (P1); indexed |
| phone_number | string(32) | E.164 WA business number |
| phone_number_id | string(64) nullable | provider-assigned id |
| credentials | text (encrypted:json) | per-provider secrets |
| webhook_secret | string(64) | verify inbound signatures |
| settings | json | fanout modes, broadcasting prefs, rate limit override, timezone |
| status | enum(`active`,`disabled`,`pending`) | default `pending` |
| last_verified_at | timestamp nullable | last successful `probe()` |
| created_at / updated_at | timestamps | |
| deleted_at | soft delete | |

Indexes: `unique(name)`, `index(driver, status)`.

### 4.2 `channel_api_keys` table

| column | type | notes |
|---|---|---|
| id | ulid PK | |
| channel_id | FK channels, cascade | |
| name | string(64) | label |
| prefix | string(12) unique | plaintext lookup segment (`wba_ab12cd34`) |
| key_hash | string(64) | SHA-256 of raw secret segment |
| abilities | json | array of ability strings |
| last_used_at | timestamp nullable | throttled write |
| expires_at | timestamp nullable | |
| revoked_at | timestamp nullable | |
| created_at | timestamp | |

Indexes: `unique(prefix)`, `index(channel_id, revoked_at)`.

### 4.3 API key format

```
wba_<8-char-prefix>_<40-char-secret>
```

- `prefix` stored plaintext (also used as display identifier).
- `<secret>` hashed with SHA-256, stored as `key_hash`.
- Lookup: `WHERE prefix = :p AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())`, then `hash_equals($row->key_hash, hash('sha256', $secret))`.

### 4.4 Ability catalog

- `messages:send`, `messages:read`
- `templates:read`, `templates:write`
- `media:read`, `media:write`
- `conversations:read`, `conversations:write`
- `webhooks:manage`
- `*` (wildcard admin)

Enforcement deferred to P2+; catalog + `tokenCan()` implemented in P1.

---

## 5. Driver contract

```php
namespace App\Waba\Contracts;

interface MessageProvider
{
    public function name(): string;
    public function bind(ChannelCredentials $credentials): static;

    // P2
    public function send(OutboundMessage $message): SendResult;

    // P3
    public function verifyWebhookSignature(string $payload, array $headers): bool;
    public function normalizeInbound(array $rawPayload): NormalizedInboundEvent;

    // P4
    public function listTemplates(): array;
    public function submitTemplate(TemplateDefinition $def): TemplateSyncResult;
    public function deleteTemplate(string $providerTemplateId): void;

    // P5
    public function uploadMedia(MediaUpload $upload): MediaReference;
    public function downloadMedia(string $providerMediaId): MediaReference;

    // P1
    public function probe(): bool;
}
```

### 5.1 `QiscusDriver` P1 scope

- Implements `name()` returning `'qiscus'`.
- Implements `bind()` returning a cloned instance with credentials set (readonly semantics).
- Implements `probe()` calling Qiscus health / me endpoint (exact endpoint confirmed during implementation spike).
- All other methods throw `DriverException::notImplemented(__METHOD__)`.

### 5.2 `WabaManager`

- Extends `Illuminate\Support\Manager`.
- Method `channel(string $slug): MessageProvider` → resolves `Channel` row via `ChannelResolver`, instantiates class from `config('waba.providers.<driver>.class')`, calls `bind($credentials)`.
- Method `driver(string $name)` (inherited) resolves default provider instance (unbound).
- Method `fake(): FakeProvider` registers a spy for tests.

### 5.3 Facade

```php
Waba::channel('sales-qiscus')->probe();
Waba::fake();
```

---

## 6. Auth flows

### 6.1 Dashboard user (Sanctum)

- Routes: `api/v1/admin/*` and Restify-protected repositories.
- Middleware: `auth:sanctum`.
- Rate limit: Laravel default (`throttle:api`, 60/min).

### 6.2 Server-to-server consumer (channel API key)

- Routes: `api/v1/channels/{channel}/*`.
- Middleware: `channel.apikey` → `AuthenticateChannelApiKey`.
- Header: `Authorization: Bearer wba_...` (primary) or `X-Api-Key: wba_...` (fallback).
- Resolution steps (implemented in middleware):
  1. Extract raw token.
  2. Parse prefix (`wba_<prefix>_<secret>`).
  3. Query `channel_api_keys` by prefix with active scope.
  4. Verify hash via `hash_equals`.
  5. Ensure key `channel_id` matches route `{channel}` slug/id.
  6. Bind `channel` and `apiKey` onto request attributes.
  7. Throttled `last_used_at` update (only if older than `config('waba.api_key.last_used_throttle_seconds')`).
- Rate limit: `RateLimiter::for('channel-api')` keyed on API key id, default 600/min, override via `channels.settings.rate_limit_per_minute`.

### 6.3 Webhook ingress (P3 — noted only)

- Route: `POST /webhooks/{provider}/{channel}`.
- Handler: invokable `HandleInboundWebhook`.
- Auth via `MessageProvider::verifyWebhookSignature($payload, $headers)`. No Sanctum, no API key.

### 6.4 Restify integration

- Restify repositories receive `$middleware = ['auth:sanctum']` for admin surface.
- Per-channel Restify Actions (`ProbeChannelAction`) run under Sanctum from the admin dashboard; server-to-server consumers use dedicated non-Restify routes guarded by `channel.apikey`.
- Ability checks via policy methods; `tokenCan()` consulted inside policy when the actor is a channel API key.

---

## 7. Configuration

### 7.1 `config/waba.php`

```php
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

### 7.2 `.env.example` additions

```
WABA_DEFAULT_CHANNEL=
QISCUS_BASE_URL=https://multichannel.qiscus.com
WABA_MEDIA_DISK=local
WABA_QUEUE=default
```

---

## 8. Public HTTP surface (P1)

| Method | Path | Auth | Purpose |
|---|---|---|---|
| Restify CRUD | `/api/restify/channels` | Sanctum | manage channels |
| Restify CRUD | `/api/restify/channel-api-keys` (nested) | Sanctum | manage API keys |
| Restify Action | `POST /api/restify/channels/{id}/actions/probe` | Sanctum | call `probe()` |
| POST | `/api/v1/webhooks/{provider}/{channel}` | signature | P3 (stub 200 OK in P1) |
| GET | `/up` | none | health |

No other endpoints in P1. P2–P6 add their own.

### 8.1 Restify-first principle

All admin/dashboard CRUD and per-channel server-to-server operations belong to Restify Repositories and Actions. The only non-Restify routes are:
- Webhook ingress (third-party caller, provider signature auth).
- Sanctum default `/sanctum/*`.
- Laravel `/up` health.

---

## 9. Error handling

All domain exceptions extend `App\Waba\Exceptions\WabaException`. Registered in `bootstrap/app.php`:

| Exception | HTTP | `error.code` |
|---|---|---|
| `ChannelNotFoundException` | 404 | `channel_not_found` |
| `UnauthorizedChannelException` | 401 | `unauthorized` |
| `InsufficientAbilityException` | 403 | `forbidden` |
| `DriverException` | 502 | `provider_error` |
| `DriverTimeoutException` | 504 | `provider_timeout` |
| `ValidationException` | 422 | `validation_failed` |

### 9.1 Response envelope (errors)

```json
{
  "error": {
    "code": "provider_error",
    "message": "Qiscus returned HTTP 500",
    "details": { "provider": "qiscus", "upstream_status": 500 }
  },
  "request_id": "01HZ..."
}
```

### 9.2 Request correlation

Middleware `AssignRequestId` adds header `X-Request-Id`, stores ULID on request, binds to Monolog context.

---

## 10. Testing (P1)

Framework: Pest v4 (per `composer.json`).

| Suite | File | Scope |
|---|---|---|
| Feature | `tests/Feature/Restify/ChannelCrudTest.php` | create/read/update/delete via Restify under Sanctum |
| Feature | `tests/Feature/Restify/ChannelApiKeyCrudTest.php` | nested repo, key generation returns raw once |
| Feature | `tests/Feature/Auth/ChannelApiKeyAuthTest.php` | valid, wrong channel, revoked, expired, missing prefix, wrong secret |
| Feature | `tests/Feature/Restify/ProbeChannelActionTest.php` | action invokes driver, handles failure |
| Unit | `tests/Unit/Waba/WabaManagerTest.php` | slug resolution, fake registration |
| Unit | `tests/Unit/Waba/QiscusDriverTest.php` | `probe()` with HTTP fake; unimplemented methods throw |
| Unit | `tests/Unit/Waba/ChannelResolverTest.php` | driver class selection from config |
| Arch | `tests/Arch/DriverIsolationTest.php` | `App\Waba\Drivers\*` must not reference `Illuminate\Database\*` |

Coverage target: ≥ 80 % for `app/Waba/*` and new middleware / policies.

### 10.1 `Waba::fake()`

```php
Waba::fake();
Waba::channel('x')->probe();
Waba::assertSent(/* ... P2 adds assertions ... */);
```

P1 supplies base `FakeProvider` with recording; assertion helpers grow per sub-project.

---

## 11. Open questions (resolve during P1 implementation)

- Exact Qiscus probe endpoint (documentation spike during plan phase).
- Whether Restify repository route prefix stays at `/api/restify` or moves to `/api/v1/admin`. Default: keep Restify default, alias via server-to-server routes in later sub-projects.
- API key rotation UX — out of P1 (tracked for P2).

---

## 12. Dependencies on downstream sub-projects

P1 deliberately leaves the following as stubs / catalog entries:

- DTO fields beyond identifiers (P2–P6 extend).
- Ability enforcement in repositories (P2 wires).
- Webhook ingress body (P3).
- Fanout configuration application (P3).
- Media storage (P5).

P1 is independently shippable: operator can create channels, rotate API keys, probe connectivity. No message I/O yet.

---

## 13. Acceptance checklist

- [ ] Migrations create `channels` and `channel_api_keys` with specified columns and indexes.
- [ ] `Channel` and `ChannelApiKey` models with encrypted / JSON casts and active scopes.
- [ ] `MessageProvider` contract file in place.
- [ ] `QiscusDriver` implementing `name`, `bind`, `probe`; other methods throw.
- [ ] `WabaManager` + `Waba` facade resolve channels.
- [ ] `AuthenticateChannelApiKey` middleware + route group scaffold.
- [ ] `AssignRequestId` middleware applied globally.
- [ ] `ChannelRepository`, `ChannelApiKeyRepository`, `ProbeChannelAction` wired into Restify.
- [ ] `config/waba.php` published; `.env.example` updated.
- [ ] Exception handlers mapped; error envelope verified in tests.
- [ ] All test suites green; coverage ≥ 80 % for new code.
- [ ] `vendor/bin/pint --dirty --format agent` clean.
