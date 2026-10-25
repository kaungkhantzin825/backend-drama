# DramaLoop — Backend

Short-drama streaming app with a rewards system. The backend is built phase by
phase; this document covers everything implemented so far.

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Foundation, mobile auth (guest + Google), admin login, API envelope | Complete — 44 tests |
| 2 | Content domain: categories, dramas, episodes, public catalogue + admin CRUD | Complete — 49 tests |
| 3 | Rewards engine: points ledger, ad events, episode unlocks | Not started |
| 4 | Watch history, likes, continue-watching | Not started |
| 5 | Wallet & withdrawals | Not started |
| 6 | Flutter client | Not started |
| 7 | Admin panel Vue screens, notifications, polish | Not started |

**Current test suite: 93 passing (342 assertions).**

---

## Tech Stack

| Layer        | Technology                                          |
|-------------|-----------------------------------------------------|
| Backend     | Laravel 11 (PHP 8.2+)                               |
| Admin panel | Inertia.js + Vue 3 + Tailwind CSS                    |
| Mobile API  | Laravel Sanctum 4.x (bearer-token, stateless)        |
| Database    | MySQL 8.x (`drama_rewards`)                          |
| Cache       | `database` store (switch to Redis for production)   |
| Auth        | Sanctum (mobile), session guard (admin)              |
| Google Auth | `firebase/php-jwt` + Google JWKS endpoint            |

---

## Product Model

Vertical short-drama: a **drama** owns an ordered run of 60–180 second
**episodes**, browsed through flat **categories** ("Romance", "Revenge", "CEO").

Monetisation is per episode, not per drama: the first three episodes of a series
are free, the rest cost reward points (`episodes.unlock_cost_points`). The client
never decides whether an episode is unlocked — it renders a lock badge from
`requires_unlock` and the server hands out the stream URL only when Phase 3's
ledger says the viewer has paid.

---

## Project Structure

```
backend-drama/
├── app/
│   ├── Enums/
│   │   ├── AuthProvider.php          # Guest | Google
│   │   ├── DramaStatus.php           # Draft | Published | Archived
│   │   ├── Platform.php             # Android | iOS | Web
│   │   └── UserRole.php             # Admin | Editor
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   │   ├── AdminAuthController.php     # Admin login/logout/dashboard
│   │   │   │   ├── CategoryController.php      # Admin category CRUD
│   │   │   │   ├── DramaController.php         # Admin drama CRUD + publish
│   │   │   │   └── EpisodeController.php       # Admin episode CRUD
│   │   │   └── Api/V1/
│   │   │       ├── AuthController.php          # Guest/Google/logout/me/fcm
│   │   │       ├── CategoryController.php      # GET /categories
│   │   │       ├── DramaController.php         # GET /dramas, /dramas/{slug}
│   │   │       ├── EpisodeController.php       # GET .../episodes/{number}
│   │   │       └── HomeController.php          # GET /home (all rails)
│   │   ├── Middleware/
│   │   │   ├── AuthenticateAdmin.php           # Admin guard check
│   │   │   ├── EnsureUserHasRole.php           # Role-based access
│   │   │   └── HandleInertiaRequests.php       # Inertia shared props
│   │   ├── Requests/
│   │   │   ├── Admin/
│   │   │   │   ├── CategoryRequest.php         # create + update
│   │   │   │   ├── DramaRequest.php            # create + update
│   │   │   │   └── EpisodeRequest.php          # create + update + unique number
│   │   │   └── Api/V1/
│   │   │       ├── GuestLoginRequest.php       # Device-scoped validation
│   │   │       ├── GoogleLoginRequest.php      # Google ID token validation
│   │   │       └── DramaIndexRequest.php       # category/q/sort filters
│   │   └── Resources/
│   │       ├── Admin/
│   │       │   ├── CategoryAdminResource.php   # + is_active
│   │       │   ├── DramaAdminResource.php      # + status, counters
│   │       │   └── EpisodeAdminResource.php    # + video_url, schedule
│   │       ├── UserResource.php                # Hides role/last_ip/device_id
│   │       ├── DeviceResource.php
│   │       ├── CategoryResource.php
│   │       ├── DramaResource.php               # lean card payload
│   │       ├── DramaDetailResource.php         # + synopsis, episodes
│   │       ├── EpisodeResource.php             # no video_url
│   │       └── EpisodeDetailResource.php       # + video_url, drama
│   ├── Models/
│   │   ├── User.php                           # HasApiTokens, role casts, scopes
│   │   ├── Device.php                         # Anti-fraud columns
│   │   ├── Category.php                       # shelf, active scope
│   │   ├── Drama.php                          # published scope, slug generation
│   │   └── Episode.php                        # is_free / unlock cost
│   ├── Providers/
│   │   └── AppServiceProvider.php             # Rate limiters, GoogleTokenVerifier binding
│   ├── Repositories/
│   │   ├── UserRepository.php                  # findByGoogleId, adminSearch, etc.
│   │   ├── DeviceRepository.php                # upsert, countAccountsForDevice
│   │   ├── CategoryRepository.php              # active, published counts
│   │   ├── DramaRepository.php                 # browse, featured/latest/trending
│   │   └── EpisodeRepository.php               # released list, next/prev
│   ├── Services/
│   │   ├── Auth/
│   │   │   ├── GoogleTokenVerifier.php         # firebase/php-jwt + JWKS
│   │   │   └── InvalidTokenException.php
│   │   ├── AuthService.php                    # loginWithGuest, loginWithGoogle, logout
│   │   ├── ContentService.php                 # catalogue + admin content logic
│   │   └── DeviceService.php                  # register, emulator detection
│   └── Support/
│       ├── ApiResponse.php                    # {success, message, data} envelope
│       └── ExceptionRenderer.php              # Unified error responses
├── config/
│   ├── auth.php                               # web, admin, sanctum guards
│   ├── sanctum.php                            # guard: [] (bearer-only)
│   └── services.php                           # google, fcm config blocks
├── database/
│   ├── factories/
│   │   ├── UserFactory.php                   # guest, google, admin, editor, banned
│   │   ├── DeviceFactory.php                 # emulator state
│   │   ├── CategoryFactory.php               # inactive state
│   │   ├── DramaFactory.php                  # draft/archived/scheduled/featured
│   │   └── EpisodeFactory.php                # locked/scheduled/forDrama
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php    # users (with role, points, device_id, ban)
│   │   ├── 2026_10_23_174923_create_personal_access_tokens_table.php
│   │   ├── 2026_10_24_000001_create_devices_table.php  # devices (fcm, anti-fraud)
│   │   ├── 2026_10_24_100001_create_categories_table.php
│   │   ├── 2026_10_24_100002_create_dramas_table.php
│   │   └── 2026_10_24_100003_create_episodes_table.php
│   └── seeders/
│       ├── DatabaseSeeder.php                 # admin, editor, 2 demo users
│       └── ContentSeeder.php                  # 6 categories, 8 dramas, 52 episodes
├── routes/
│   ├── api.php                                # /api/v1/* (auth + catalogue)
│   └── web.php                                # /admin/* (pages + /admin/api/*)
├── resources/
│   ├── js/
│   │   ├── Pages/admin/
│   │   │   ├── Dashboard.vue                  # Sidebar, phase checklist
│   │   │   └── auth/Login.vue                 # Dark purple/magenta login
│   │   └── app.js                             # Inertia + Vue 3 bootstrap
│   └── css/app.css                            # Tailwind + component classes
├── tests/
│   ├── Feature/
│   │   ├── Api/V1/AuthTest.php                # 24 tests (guest, google, me, logout, fcm)
│   │   ├── Api/V1/ContentTest.php             # 25 tests (home, browse, detail, episodes)
│   │   ├── Admin/AdminAuthTest.php            # 10 tests (admin login, roles, bans)
│   │   ├── Admin/AdminContentTest.php         # 24 tests (CRUD, invariants, authz)
│   │   └── ApiResponseFormatTest.php          # 5 tests (envelope shape)
│   └── Unit/
│       └── UserRoleTest.php                   # 3 tests (enum methods)
└── tests/fixtures/
    ├── google_test_key.pem                   # Static RSA key for Google token tests
    └── google_jwks.json                       # Matching JWKS set
```

---

## Architecture Layers

### 1. Enums (`app/Enums/`)

Typed PHP enums replace magic strings throughout the codebase.

- **UserRole**: `Admin`, `Editor` — `canManageRevenue()` gates revenue features
- **Platform**: `Android`, `iOS`, `Web`
- **AuthProvider**: `Guest`, `Google` — derived from a user's columns
- **DramaStatus**: `Draft`, `Published`, `Archived` — `isVisibleToMobile()` gates the catalogue

### 2. Models (`app/Models/`)

**User** — The central identity model:
- Casts `role` to `UserRole` enum
- `isGuest()`, `isStaff()`, `isAdmin()`, `isEditor()` helpers
- Scopes: `mobileUsers()`, `staff()`, `googleAccounts()`
- `getAuthProviderAttribute()` — derived attribute returning `AuthProvider` enum
- Relationships: `devices()`, `primaryDevice()`

**Device** — Anti-fraud metadata for each physical device:
- `platform` cast to `Platform` enum
- `is_emulator`, `is_rooted`, `device_hash` for fraud detection
- `looksLikeEmulator()` heuristic

**Category** — flat genre shelf:
- `scopeActive()` — visible shelves in display order
- `dramas()`, `publishedDramas()`

**Drama** — a vertical short-drama series:
- `status` cast to `DramaStatus`; `scopePublished()` enforces `status = published` **and**
  `published_at <= now()` in one place
- `scopeFeatured()`, `scopeInCategory()`, `scopeSearch()`
- `booted()` derives a unique slug from the title and stamps `published_at` on publish
- `isVisible()` mirrors the scope rule for in-memory checks
- `$attributes` mirrors column defaults so a freshly created model never holds
  `status = null` (which would break the enum cast in the create response)

**Episode** — one 60–180s episode:
- `scopeVisible()` — released episodes only
- `isFree()`, `isReleased()`, `durationLabel()`
- `is_free` + `unlock_cost_points` are the price list; the unlock decision is
  server-side (Phase 3)

### 3. Repositories (`app/Repositories/`)

Thin data-access layer. Controllers and services never call `User::where()`
directly — they go through the repository so queries are centralized and
easy to mock.

- **UserRepository**: `findByGoogleId()`, `findGuestByDeviceId()`, `create()`,
  `touchLastSeen()`, `adminSearch()` (q/role/banned filters), `countByDay()`
- **DeviceRepository**: `registerForUser()` (upsert), `countAccountsForDevice()`,
  `forUser()`
- **CategoryRepository**: `active()`, `activeWithPublishedDramaCount()`, `all()`
- **DramaRepository**: `browse()` (whitelisted sort, clamped page size),
  `featured()`, `latest()`, `trending()`, `findPublishedBySlug()`,
  `adminSearch()`, `syncEpisodeCount()`
- **EpisodeRepository**: `paginateForDrama()`, `allForDrama()`,
  `findReleased()`, `nextEpisode()`, `previousEpisode()`, `nextEpisodeNumber()`

### 4. Services (`app/Services/`)

Business logic that orchestrates repositories, external APIs, and
transactions.

**AuthService** — The core auth orchestrator:
- `loginWithGuest($attrs)` — finds or creates a guest account bound to a
  `device_id`, registers the device, issues a Sanctum token
- `loginWithGoogle($idToken, $attrs)` — verifies the Google ID token,
  finds/creates/upgrades the user, registers the device, issues a token
- `logout($user)` — deletes the current access token only
- `revokeAllTokens($user)` — revokes every token (used on ban)
- `isBanned($user)` — checks `banned_at`

**DeviceService** — Device lifecycle:
- `register($user, $attrs)` — upserts by `device_id`, syncs `user.device_id`
- `updateFcmToken()`, `countAccountsForDevice()`, `emulatorDeviceIds()`

**ContentService** — Catalogue business logic:
- `home()` — featured / latest / trending rails + active categories, in one call
- `browseDramas($filters)` — delegates to the repository's whitelisted sort
- `dramaDetail($slug)` — published drama + a fire-and-forget view bump
- `publishedDrama($slug)` — same lookup without the bump (used by episode routes,
  where the view belongs to the episode, not the drama)
- `episodeDetail($drama, $number)` — released episode + previous/next neighbours
- Admin: create/update/delete for categories, dramas and episodes;
  `publishDrama()` stamps `published_at`; episode writes run in a transaction and
  re-sync `dramas.total_episodes`

**GoogleTokenVerifier** (`app/Services/Auth/`):
- Uses `firebase/php-jwt` with Google's JWKS endpoint
- 1-hour JWKS cache (HTTP client, not assumed available at boot)
- Verifies: signature, `aud` (against configured client IDs), `iss`, `sub`,
  `email` claims
- Throws `InvalidTokenException` on any failure

### 5. Support (`app/Support/`)

**ApiResponse** — The unified response envelope:
```php
ApiResponse::success('Signed in.', $data);        // 200 {success: true, message, data}
ApiResponse::error('Invalid token.', null, 401);  // 401 {success: false, message, data}
ApiResponse::paginated($paginator, 'OK');         // 200 + meta {current_page, last_page, ...}
```

**ExceptionRenderer** — Converts every throwable to the same envelope:
- `ValidationException` -> 422 with `data.errors`
- `AuthenticationException` -> 401
- `AuthorizationException` -> 403
- `ModelNotFoundException` / `NotFoundHttpException` -> 404
- `ThrottleRequestsException` -> 429 with `data.retry_after`
- Everything else -> 500

The render callback covers **both** JSON surfaces — `/api/v1/*` and
`/admin/api/*` — so admin validation errors use the same envelope as mobile ones.

### 6. HTTP Layer

**Form Requests** validate input before the controller runs:
- `GuestLoginRequest` — `device_id` (required, min:8, max:128), `platform`
  (enum), `app_version`, `fcm_token`, anti-fraud fields
- `GoogleLoginRequest` — `id_token` (required, min:100) + same device fields
- `DramaIndexRequest` — `category`, `q` (min 2), `featured`, `sort`
  (whitelisted: latest/popular/rating/title), `per_page` (max 50)
- `Admin\CategoryRequest`, `Admin\DramaRequest`, `Admin\EpisodeRequest` — one
  class per resource covering both create and update, so the uniqueness and enum
  rules cannot drift between the two verbs

**Resources** control what each client sees:

| Resource | Adds | Withholds |
|---|---|---|
| `UserResource` | profile, balance, provider | role, last_ip, ban_reason, device_id |
| `DramaResource` | card fields, tagline excerpt | synopsis body, episodes |
| `DramaDetailResource` | full synopsis, trailer, released episodes | — |
| `EpisodeResource` | number, duration, `requires_unlock` | **`video_url`** |
| `EpisodeDetailResource` | **`video_url`**, parent drama | — |
| `Admin\*AdminResource` | status, counters, schedule, `video_url` | — |

The `video_url` split is deliberate: handing stream URLs out in a list would let
a client stream locked episodes. Only the episode detail endpoint returns them,
which gives Phase 3 exactly one place to enforce an unlock check.

---

## API Endpoints

### Public (no auth)

| Method | Endpoint              | Description                          |
|--------|-----------------------|--------------------------------------|
| POST   | `/api/v1/auth/guest`  | Device-scoped guest sign-in          |
| POST   | `/api/v1/auth/google` | Google ID token sign-in              |
| GET    | `/api/v1/health`      | Connectivity probe                    |
| GET    | `/api/v1/home`        | Featured / latest / trending + categories |
| GET    | `/api/v1/categories`  | Active shelves with published drama counts |
| GET    | `/api/v1/dramas`      | Browse: `?category=&q=&featured=&sort=&per_page=&page=` |
| GET    | `/api/v1/dramas/{slug}` | Drama detail + released episodes   |
| GET    | `/api/v1/dramas/{slug}/episodes` | Paginated episode list     |
| GET    | `/api/v1/dramas/{slug}/episodes/{number}` | Episode detail + stream URL + neighbours |

`sort` accepts `latest` (default), `popular`, `rating`, `title`. An unrecognised
value is rejected with 422 rather than silently ignored.

### Authenticated (Bearer token)

| Method | Endpoint                | Description                          |
|--------|--------------------------|--------------------------------------|
| POST   | `/api/v1/auth/logout`   | Revoke the current token only       |
| GET    | `/api/v1/me`            | Profile, balance, registered devices |
| POST   | `/api/v1/auth/fcm-token`| Update push token for a device        |
| GET    | `/api/v1/wallet`        | Balance, cash value, earning state   |
| GET    | `/api/v1/wallet/ledger` | Paginated points history             |
| POST   | `/api/v1/rewards/check-in` | Claim the once-per-day bonus      |
| POST   | `/api/v1/ads/events`    | Report a rewarded-ad impression      |
| POST   | `/api/v1/episodes/{episode}/unlock` | Spend points on an episode |
| GET    | `/api/v1/withdrawals`   | The caller's payout history          |
| POST   | `/api/v1/withdrawals`   | Request a cash-out                   |
| GET    | `/api/v1/notifications` | The caller's inbox, paginated        |
| GET    | `/api/v1/notifications/unread-count` | Badge count for the bell |
| POST   | `/api/v1/notifications/{id}/read` | Mark one notification read |
| POST   | `/api/v1/notifications/read-all` | Mark the whole inbox read  |
| POST   | `/api/v1/episodes/{episode}/progress` | Watch heartbeat; pays watch-time points |
| GET    | `/api/v1/continue-watching` | One in-progress episode per drama |
| GET    | `/api/v1/history`       | Paginated watch history              |
| GET    | `/api/v1/favorites`     | The caller's saved dramas            |
| POST   | `/api/v1/dramas/{drama}/favorite` | Toggle a drama in the list  |
| GET    | `/api/v1/rewards/lucky-wheel` | Wheel segments + spins remaining |
| POST   | `/api/v1/rewards/lucky-wheel/spin` | Spin once; the server picks the prize |

The episode-detail endpoint (`/api/v1/dramas/{slug}/episodes/{number}`) is public
but token-aware: it resolves a bearer token when one is present, and returns
`video_url: null` plus `can_play: false` for any episode the caller has not paid
for. That makes it the single chokepoint for unlock enforcement.

### Admin (session-based, `/admin`)

| Method | Endpoint           | Description                     |
|--------|---------------------|---------------------------------|
| GET    | `/admin/login`      | Inertia login page               |
| POST   | `/admin/login`      | Admin sign-in (email + password) |
| POST   | `/admin/logout`     | Admin sign-out                   |
| GET    | `/admin/dashboard`  | Overview with live counters      |
| GET    | `/admin/content`    | Drama + episode management       |
| GET    | `/admin/users`      | User browser with ban controls   |
| GET    | `/admin/rewards`    | Economy stats + ledger           |
| GET    | `/admin/withdrawals`| Payout queue                     |
| GET    | `/admin/ads`        | Ad impression log                |

### Admin content API (`admin.auth` + `admin.role:admin,editor`)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET/POST | `/admin/api/categories` | List / create |
| GET/PATCH/DELETE | `/admin/api/categories/{category}` | Show / update / delete |
| GET/POST | `/admin/api/dramas` | Paginated list (`?status=&category_id=&q=`) / create |
| GET/PATCH/DELETE | `/admin/api/dramas/{drama}` | Detail (with episodes) / update / delete |
| POST | `/admin/api/dramas/{drama}/publish` | Publish and stamp `published_at` |
| GET/POST | `/admin/api/dramas/{drama}/episodes` | Full run (incl. scheduled) / create |
| GET/PATCH/DELETE | `/admin/api/episodes/{episode}` | Show / update / delete |

These are JSON endpoints under the `web` middleware group so the session-based
`admin` guard applies. The Vue screens under `/admin/*` drive them.

### Admin economy API (`admin.auth` + `admin.role:admin`)

Money-touching endpoints are admin-only; editors keep the content surface.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/admin/api/stats` | Users / content / economy / ads / payouts counters |
| GET | `/admin/api/users` | Paginated list (`?q=&role=&banned=`) |
| GET | `/admin/api/users/{user}` | Detail + counts + recent ledger |
| POST | `/admin/api/users/{user}/ban` | Ban and revoke every mobile token |
| DELETE | `/admin/api/users/{user}/ban` | Unban |
| GET | `/admin/api/rewards/stats` | Outstanding liability, daily flow, breakdown by type |
| GET | `/admin/api/rewards/ledger` | Ledger (`?type=&user_id=`) |
| GET | `/admin/api/withdrawals` | Payout queue (`?status=`) |
| POST | `/admin/api/withdrawals/{id}/approve` | Approve |
| POST | `/admin/api/withdrawals/{id}/reject` | Reject and refund the points |
| POST | `/admin/api/withdrawals/{id}/paid` | Mark the payout sent |
| GET | `/admin/api/ad-events` | Ad impression log (`?status=`) |

---

## Notifications & Push

Two layers, deliberately decoupled:

1. **The inbox** — every notification is a row in `app_notifications`. This is
   the durable record, and it works with no Google credentials at all.
2. **The push** — `SendPushNotification` is queued after the row is written and
   calls FCM HTTP v1. It is best-effort: it can fail, retry, or be skipped.

That order is the whole design. If FCM is down or unconfigured, the user still
sees "your withdrawal was paid" the next time they open the app.

### What generates a notification

| Event | Type | Route the client opens |
|-------|------|------------------------|
| Admin approves a payout | `withdrawal_approved` | `wallet` |
| Admin rejects a payout | `withdrawal_rejected` | `wallet` |
| Admin marks a payout sent | `withdrawal_paid` | `wallet` |

The rejection body states the refund explicitly and quotes the amount with its
currency (`$6.00`), because `amountLabel()` alone returns a bare `6.00` — see
`Withdrawal::displayAmount()`.

### Push configuration

Push is off by default. To enable it:

```dotenv
FCM_ENABLED=true
FIREBASE_PROJECT_ID=your-project-id
FIREBASE_CREDENTIALS=/absolute/path/to/service-account.json
```

`config/firebase.php` reads these. The service-account JSON is never committed;
it is only read by `ServiceAccountTokenProvider`, which mints an OAuth2 access
token through `google/auth` (already a dependency) and caches it for its
lifetime. Access tokens are minted through an `AccessTokenProvider` interface so
tests substitute a stub and never reach Google.

### Delivery rules

- One HTTP request per device — FCM v1 accepts a single message per call.
- Duplicate tokens in the fan-out are collapsed.
- Data values are stringified and `null` keys dropped, as FCM requires.
- A `404` / `NOT_FOUND` response means the app was uninstalled or the token
  rotated: the device's `fcm_token` is nulled so future sends stop paying for it.
- `pushed_at` is only set when at least one device accepted the message, so a
  retry cannot double-push.
- The sender never throws — a dead network must not roll back a payout.

---

## Rewards Economy

Every number lives in `config/rewards.php`, so tuning the economy is a config
change rather than a code change. The mobile client reads the same values back
from `GET /api/v1/wallet` instead of hard-coding them.

| Key | Default | Meaning |
|-----|---------|---------|
| `points_per_currency_unit` | 1000 | Points per 1 USD |
| `check_in_points` | 20 | Daily check-in bonus |
| `ad_reward_points` | 10 | Per verified ad impression |
| `daily_ad_limit` | 20 | Fraud guard: ads claimable per day |
| `min_withdrawal_points` | 5000 | Smallest cashable balance |
| `daily_withdrawal_limit` | 1 | Payout requests per day |
| `payout_methods` | paypal, bank, giftcard | Supported destinations |

### The ledger is the source of truth

`users.points_balance` is a **cache**. Every movement writes an immutable
`points_ledger` row and moves the cached balance in the same transaction, under a
row lock:

```php
$locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
$balanceAfter = $locked->points_balance + $delta;
if ($balanceAfter < 0) {
    throw InsufficientPointsException::for(abs($delta), $locked->points_balance);
}
```

`PointsLedger` throws a `RuntimeException` on `updating` and `deleting`, so a
ledger row can never be rewritten — a correction is a new compensating entry.

### Anti-fraud measures

| Risk | Control |
|------|---------|
| Double-tap / retried request | Unique `idempotency_key` on the ledger; a replay returns the original row |
| Scripted ad farming | `daily_ad_limit` per account per day |
| Ad replay | Unique `idempotency_key` per impression on `ad_events` |
| Concurrent overspend | `lockForUpdate()` inside the transaction; balance may never go negative |
| Double-charged unlock | Unique `(user_id, episode_id)` on `episode_unlocks` |
| Payout spam | `min_withdrawal_points` + `daily_withdrawal_limit` |
| Banned user keeps access | Banning deletes every Sanctum token |

### Withdrawal lifecycle

```
pending ──approve──► approved ──paid──► paid
   │
   └──reject──► rejected  (+ withdrawal_refund ledger entry)
```

Points leave the balance the moment a request is created, so they cannot be
spent twice while the payout is queued. A rejection credits them back as a new
`withdrawal_refund` row — the original debit is never edited.

---

## Library, Watch Rewards & The Lucky Wheel

Three features that all write to the same ledger, and all of them are
server-authoritative.

### Watch progress and watch-time points

The player posts a heartbeat to `POST /api/v1/episodes/{episode}/progress` with
`position_seconds` and `duration_seconds`. `LibraryService::record()` does two
things at once: it stores the furthest position reached, and it pays for any
whole minute newly crossed.

| Guard | Rule |
|-------|------|
| Backwards progress | `position_seconds` only ever moves forward for the row |
| Seek abuse | At most `watch_max_intervals_per_ping` (2) intervals can be claimed by one heartbeat |
| Replay abuse | Each interval has its own idempotency key `watch:{user}:{episode}:{index}` |
| Daily cap | `daily_watch_reward_cap` (100 points) per user per day |
| Paywall | A locked episode is refused before any points are considered |
| Completion | `is_completed` is set at 95% of the duration |

The first three together mean a scripted client cannot skip to the end of an
episode and collect the whole run's worth of points in one call.

### My list, history, continue watching

- `favorites` — unique `(user_id, drama_id)`. `POST /dramas/{drama}/favorite`
  toggles; `DramaDetailResource.is_favorite` tells the detail and player screens
  which icon to draw.
- `history` — paginated, newest first, from `watch_progress`.
- `continue-watching` — exactly one row per drama (the most recently watched
  episode), so the home rail never shows the same title twice.

### The lucky wheel

`config/rewards.php` owns the prize table: six segments with weights, and
`daily_spins` (3). `GET /rewards/lucky-wheel` returns the segments and the
remaining allowance; `POST .../spin` picks a segment with `random_int`, writes a
`lucky_wheel_spins` row and credits the prize with idempotency key
`wheel:{spin_id}`.

The client animates to `segment_index` — it is told the outcome, it does not
choose one. A zero-point segment still consumes an allowance and still writes a
spin row; it simply writes no ledger entry.

---

## Guards & Authentication Design

Three guards are defined in `config/auth.php`:

| Guard    | Driver   | Purpose                        |
|----------|----------|--------------------------------|
| `web`    | session  | Default (unused by API/admin)  |
| `admin`  | session  | Inertia admin panel login      |
| `sanctum`| sanctum  | Mobile API bearer-token auth    |

**Critical configuration decisions:**

1. **`config/sanctum.php` `'guard' => []`** — Deliberately empty. If `['web']`
   were left here, Sanctum's `Guard::__invoke()` would check the session guard
   first (line 32-38 of `Guard.php`), and a stale browser session could
   authenticate an API call, bypassing token revocation on logout.

2. **No `statefulApi()`** — The `EnsureFrontendRequestsAreStateful`
   middleware is NOT added to the API group. The Flutter client is
   bearer-token only — no cookies, no CSRF, no sessions.

3. **Admin guard isolation** — Admins live in the same `users` table but are
   identified by a non-null `role` column. The `AdminAuthController` checks
   that the role is not null before logging in, and the `AuthenticateAdmin`
   middleware enforces the `admin` guard separately.

---

## Rate Limiting

Defined in `AppServiceProvider::boot()`:

| Limiter     | Rate       | Applied to                        |
|-------------|------------|-----------------------------------|
| `api`       | 60/min     | Default API limiter                |
| `auth`      | 20/min     | Guest + Google login endpoints     |
| `rewards`   | 30/min     | Check-in + episode unlock          |
| `withdraw`  | 10/min     | Withdrawal requests                |
| `ad-events` | 120/min    | Ad impression reporting            |
| `ad-verify` | 60/min    | Ad verification callbacks          |

---

## Database Schema

### `users` table

| Column              | Type         | Notes                              |
|---------------------|-------------|-------------------------------------|
| id                  | bigint      | PK                                  |
| name                | string      |                                     |
| email               | string?     | Nullable (guests have none)        |
| email_verified_at   | timestamp?  |                                     |
| password            | string?     | Nullable (guests + Google have none)|
| google_id           | string?     | Unique                             |
| avatar_url          | string?     |                                     |
| device_id           | string?     | Index                               |
| role                | string?     | `admin` or `editor`; null = mobile |
| points_balance      | int         | Default 0                           |
| total_points_earned | int         | Default 0                           |
| total_points_spent  | int         | Default 0                           |
| last_seen_at        | timestamp?  |                                     |
| last_ip             | string?     |                                     |
| banned_at           | timestamp?  |                                     |
| ban_reason          | string?     |                                     |

### `devices` table

| Column          | Type         | Notes                              |
|-----------------|-------------|-------------------------------------|
| id              | bigint      | PK                                  |
| device_id       | string      | Unique                              |
| user_id         | bigint FK   |                                     |
| fcm_token       | string?     |                                     |
| platform        | string      | `android`, `ios`, `web`            |
| app_version     | string?     |                                     |
| model           | string?     |                                     |
| os_version      | string?     |                                     |
| is_emulator     | boolean     | Default false                       |
| is_rooted       | boolean     | Default false                       |
| device_hash     | string?     | Anti-fraud hash                     |
| ip_address      | string?     |                                     |
| user_agent      | string?     |                                     |
| last_active_at  | timestamp?  |                                     |

### `personal_access_tokens` table

Published from Sanctum. Stores bearer tokens for mobile auth.

### `categories` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| name | string | |
| slug | string | Unique |
| description | text? | |
| cover_image | string? | |
| sort_order | int | Lower shows first |
| is_active | boolean | Hidden shelves stay in the DB but never reach the app |

### `dramas` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| category_id | bigint? FK | `nullOnDelete` — deleting a shelf keeps its dramas |
| title | string | |
| slug | string | Unique; auto-derived from the title when omitted |
| synopsis | text? | |
| cover_image | string? | Portrait poster (2:3) |
| banner_image | string? | Landscape hero (16:9) |
| trailer_url | string? | |
| language | string | Default `en` |
| release_year | smallint? | |
| total_episodes | int | Denormalised counter, synced by ContentService |
| status | string | `draft` / `published` / `archived` |
| is_featured | boolean | Drives the home "Featured" rail |
| view_count | bigint | Incremented by ContentService |
| like_count | bigint | Maintained in Phase 4 |
| rating | decimal(3,2)? | |
| tags | json? | e.g. `["ceo","revenge"]` |
| published_at | timestamp? | Separate from `created_at` so releases can be scheduled |

Indexes: `(status, published_at)`, `(category_id, status)`, `(is_featured, status)`, `view_count`.

### `episodes` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| drama_id | bigint FK | `cascadeOnDelete` |
| episode_number | int | Unique per drama |
| title | string? | |
| description | text? | |
| video_url | string? | HLS/DASH manifest — only returned by episode detail |
| thumbnail_url | string? | |
| duration_seconds | int | 60–180s typical |
| is_free | boolean | First episodes are free |
| unlock_cost_points | int | 0 when free |
| view_count | bigint | |
| published_at | timestamp? | Future value = scheduled, hidden from the app |

Unique: `(drama_id, episode_number)`.

### `points_ledger` table

Append-only. The authoritative record of every points movement.

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint FK | `cascadeOnDelete` |
| type | string | `ad_reward`, `check_in`, `episode_unlock`, `withdrawal`, `withdrawal_refund`, `admin_adjust` |
| delta | int | Signed — positive credits, negative debits |
| balance_after | int | Balance immediately after this entry |
| description | string | Human-readable, shown in the app |
| reference_type / reference_id | string? / bigint? | Polymorphic-ish pointer to the cause |
| meta | json? | Extra context (placement, drama id, method) |
| idempotency_key | string? | **Unique** — absorbs retries |
| created_at | timestamp | No `updated_at`; rows are never modified |

### `episode_unlocks` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint FK | |
| episode_id | bigint FK | |
| points_spent | int | 0 for a free episode |
| created_at | timestamp | |

Unique: `(user_id, episode_id)` — a double tap cannot double-charge.

### `ad_events` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint FK | |
| device_id | string? | Fraud signal |
| placement | string | `rewarded_video`, `interstitial`, `banner`, `native` |
| provider | string? | Ad network |
| reward_points | int | Snapshot of what was paid |
| status | string | `pending` / `verified` / `rejected` |
| idempotency_key | string | **Unique** per impression |
| meta | json? | |
| ip_address | string? | Fraud signal |

### `withdrawals` table

| Column | Type | Notes |
|---|---|---|
| id | bigint | PK |
| user_id | bigint FK | |
| points_amount | int | Points consumed |
| currency | string | Default `USD` |
| amount_cents | int | Integer money — never a float |
| method | string | `paypal` / `bank` / `giftcard` |
| account_details | json | Destination, shape depends on method |
| status | string | `pending` / `approved` / `rejected` / `paid` |
| admin_note | string? | Shown to the user on rejection |
| processed_by | bigint? FK | Staff account |
| processed_at | timestamp? | |

---

## Setup

### Prerequisites

- PHP 8.2+
- MySQL 8.x
- Composer
- Node.js 18+ / npm

### Installation

```bash
# Install PHP dependencies
composer install

# Install JS dependencies
npm install

# Copy environment
cp .env.example .env

# Create the databases
mysql -u root -e "CREATE DATABASE drama_rewards CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -e "CREATE DATABASE drama_rewards_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Generate key
php artisan key:generate

# Run migrations + seeders
php artisan migrate:fresh --seed

# Build frontend assets
npm run build    # production build
# or
npm run dev      # dev server with HMR
```

### Environment Variables

| Variable              | Required | Description                            |
|-----------------------|----------|----------------------------------------|
| `DB_DATABASE`         | yes      | `drama_rewards`                        |
| `GOOGLE_CLIENT_ID`   | no*      | Android client ID for Google sign-in   |
| `GOOGLE_CLIENT_ID_IOS`| no       | iOS client ID                           |
| `ADMIN_EMAIL`         | no       | Used by seeder if set                   |
| `ADMIN_PASSWORD`      | no       | Used by seeder if set                   |
| `CACHE_STORE`         | no       | `database` (default) or `redis`         |

*Required when Google sign-in is used in production.

### Seeded Accounts

| Email                | Role  | Password (from .env or default) |
|---------------------|-------|----------------------------------|
| admin@dramaloop.test | Admin | from `ADMIN_PASSWORD` or `password` |
| editor@dramaloop.test| Editor| from `ADMIN_PASSWORD` or `password`|

### Seeded Catalogue

`ContentSeeder` creates 6 categories, 8 dramas and 52 episodes — enough for the
Flutter client to build against. Episodes 1–3 of every drama are free; 4+ cost 30
points. Re-running the seeder updates in place (keyed on slug / episode number).

```bash
php artisan db:seed --class=ContentSeeder
```

**Those placeholder titles point at `cdn.dramaloop.test`, which does not
resolve**, so they cannot actually play. `DemoVideoSeeder` adds three real,
YouTube-backed dramas (5 episodes each, posters from YouTube's thumbnail
service) and then parks the placeholders as `draft` — the admin panel still sees
them, but the app feed only shows content that plays. Flip them back to
`published` once real media is uploaded.

```bash
php artisan db:seed --class=DemoVideoSeeder
```

| Slug | Title | Backing video |
|------|-------|---------------|
| `demo-the-substitute-bride` | The Billionaire's Substitute Bride | `zBjJUV-lzHo` |
| `demo-midnight-oath` | Midnight Oath | `2Z1UYAFdaIg` |
| `demo-second-chance-summer` | Second Chance Summer | `BSKWdAXx_mI` |

---

## Testing

```bash
# Run all tests
php artisan test

# Run a specific suite
php artisan test --filter=AuthTest
php artisan test --filter=ContentTest
php artisan test --testsuite=Unit

# Run with coverage
php artisan test --coverage
```

### Test Organization

| File                              | Tests | Coverage                           |
|-----------------------------------|-------|------------------------------------|
| `tests/Feature/Api/V1/AuthTest.php` | 24   | Guest login, Google login, /me, logout, FCM, health |
| `tests/Feature/Api/V1/ContentTest.php` | 25 | Home rails, browse/filter/sort, drama detail, episode detail, visibility rules |
| `tests/Feature/Admin/AdminAuthTest.php` | 10 | Admin login, roles, bans, dashboard |
| `tests/Feature/Admin/AdminContentTest.php` | 24 | Category/drama/episode CRUD, authz, invariants (unique episode numbers, counters, cascades) |
| `tests/Feature/Api/V1/RewardsTest.php` | 23 | Wallet summary, ledger, check-in, ad rewards, episode unlock, paywall enforcement |
| `tests/Feature/Api/V1/WithdrawalTest.php` | 10 | Minimum threshold, daily cap, payout destinations, refund on reject |
| `tests/Feature/Admin/AdminRewardsTest.php` | 14 | Stats, ledger feed, withdrawal queue, ban/unban, role gating |
| `tests/Feature/Api/V1/NotificationTest.php` | 13 | Inbox scoping, read markers, payout notifications, queueing |
| `tests/Feature/Push/PushNotificationTest.php` | 10 | FCM payload, fan-out, dead-token pruning, idempotent push |
| `tests/Feature/ApiResponseFormatTest.php` | 5 | Envelope shape, pagination, defaults |
| `tests/Unit/UserRoleTest.php`    | 3     | Enum methods                        |
| **Total**                         | **165** |                                   |

Run the Flutter suite separately:

```bash
cd ../flutter_app_video
flutter test        # 21 model/formatting tests
flutter analyze     # zero issues
```

### Google Token Tests

Google won't mint tokens for a fake client ID, so the tests use a checked-in
RSA key pair (`tests/fixtures/google_test_key.pem`) and a matching JWKS set
(`tests/fixtures/google_jwks.json`). The token is signed locally with
`firebase/php-jwt`, and Google's JWKS endpoint is stubbed with `Http::fake()`.
The verification logic (signature, audience, issuer, expiry) is genuinely
exercised — only the key distribution is faked.

### Test Note: Sanctum Guard Caching

`AuthManager` caches guard instances for the lifetime of the application
container. In tests, the app persists across multiple `getJson()`/`postJson()`
calls within a single test method, so `RequestGuard::$user` is resolved on
the first request and returned from cache for subsequent requests — even
after the token is deleted from the DB. The `AuthTest::setUp()` method calls
`Auth::forgetGuards()` to reset this cache. Token-revocation tests also call
it between the logout and the follow-up request.

---

## Admin Panel UI

Dark purple/magenta theme matching the mobile app design language.

Every screen below is live — it fetches from the admin JSON API and mutates real
data. There are no placeholder modules.

| Screen | Route | What it does |
|--------|-------|--------------|
| Login | `/admin/login` | Session sign-in for staff accounts |
| Dashboard | `/admin/dashboard` | Live counters: users, content, economy, ads, payouts |
| Content | `/admin/content` | Create dramas, filter by status, publish, manage episodes |
| Users | `/admin/users` | Search, inspect balances, ban/unban (revokes tokens) |
| Rewards | `/admin/rewards` | Liability, daily flow, breakdown by type, filterable ledger |
| Withdrawals | `/admin/withdrawals` | Approve / reject (refunds) / mark paid |
| Ads | `/admin/ads` | Ad impression log with status filter |

Shared plumbing:

- `resources/js/lib/adminApi.js` — `useAdminApi()` wraps `fetch`, attaches the
  CSRF header, unwraps the envelope and throws `Error(payload.message)`.
- `resources/js/Layouts/AdminLayout.vue` — sidebar filtered by role, so an
  editor never sees a link they cannot open.

Tailwind config (`tailwind.config.js`) defines custom theme colors:
- `surface` (deep purple-black background)
- `brand` (pink `#E91E8C`)
- `grape` (purple)
- `cash` (gold — for reward/monetary UI)
- `success` (green)
- Glow shadows, fade-up and pop-in animations

---

## Flutter Client (`../flutter_app_video`)

The mobile app is a complete Flutter client — 24 Dart files, no placeholder
screens. It talks to the Laravel API over the same `{success, message, data}`
envelope and never computes a reward locally.

```
lib/
├── main.dart                     entry point, ProviderScope
├── app.dart                      MaterialApp + theme + root routing
├── core/
│   ├── api_config.dart           base URL, endpoint paths, header names
│   ├── api_client.dart           Dio wrapper: token injection, envelope
│   │                             unwrapping, 401 → signed-out event
│   ├── api_exception.dart        typed failures (network / validation / 401)
│   ├── theme.dart                dark palette shared with the admin panel
│   └── format.dart               points, cash, durations, compact counts
├── models/models.dart            Category, Drama, Episode, EpisodePlayback,
│                                 HomeFeed, WalletSummary, LedgerEntry,
│                                 Withdrawal, AppUser, AppNotification
├── services/
│   ├── auth_service.dart         guest login, token + device-id persistence
│   ├── content_service.dart      home, browse, drama detail, episode detail
│   ├── wallet_api.dart           summary, ledger, check-in, ad claim, unlock,
│   │                             withdrawal request + history
│   └── notification_api.dart     inbox, unread count, read markers
├── state/providers.dart          Riverpod providers (auth, wallet, feeds)
├── widgets/common.dart           Poster, PointsPill, SectionHeader, lock badge,
│                                 empty/error states, snack helper
└── screens/
    ├── splash_screen.dart        bootstraps the stored session
    ├── shell.dart                bottom nav: Home / Browse / Wallet / Profile
    ├── home_screen.dart          hero carousel, category rail, featured,
    │                             trending, latest
    ├── browse_screen.dart        search + category chips + sort, paginated
    ├── detail_screen.dart        synopsis, episode grid with lock states
    ├── player_screen.dart        video_player, paywall overlay, auto-advance
    ├── wallet_screen.dart        balance, check-in, watch-ad, ledger list
    ├── withdraw_sheet.dart       method picker + destination fields
    ├── notifications_screen.dart inbox with read markers and deep links
    └── profile_screen.dart       account, device, withdrawal history
```

### Inbox

The home header carries a bell with an unread badge. The count comes from
`GET /api/v1/notifications/unread-count`, kept separate from the list so the
header never waits on a full page. Tapping an entry marks it read and then
follows `route` — `wallet`, `drama` or `player` — using the ids in the
notification's `data` payload.

### Session model

There is no password screen. `AuthService` calls `POST /api/v1/auth/guest` with
a persisted device id, stores the Sanctum bearer token in `shared_preferences`,
and re-uses it on every later launch. A `401` from any call clears the token and
drops the app back to the splash gate.

### Paywall behaviour

`detail_screen` renders a lock badge and price from `episode.is_unlocked` /
`unlock_cost_points` — it never guesses. Tapping a locked episode opens the
player in a paywalled state; confirming calls `POST /api/v1/episodes/{id}/unlock`,
and only after the server returns success does the screen re-fetch the playback
payload and start streaming.

### Building

```bash
cd ../flutter_app_video
flutter pub get
flutter test          # 21 passing
flutter analyze       # zero issues
flutter build apk --debug
```

> **Build note (this machine):** the debug APK step currently fails before
> Dart compilation because the `C:` drive has ~240 MB free and the Android
> Gradle/Kotlin toolchain needs several GB. `flutter test` and `flutter analyze`
> both succeed, so the Dart code is verified. Free space on `C:` (or move
> `~/.gradle` to another drive) and the APK build will complete.

---

## Design Rules Enforced

1. **Server-authoritative rewards** — No client-side point calculations. The
   client asks for an action; the server decides the amount, records it in the
   ledger and returns the new balance.
2. **Unified API envelope** — Every JSON response (mobile *and* admin) uses
   `{success, message, data}`, including validation errors (which land in
   `data.errors`).
3. **Form Requests for validation** — No inline `$request->validate()` in
   controllers, apart from the one-field ban reason.
4. **Immutable points ledger** — Every balance change writes an append-only
   `points_ledger` row under a row lock; `updating`/`deleting` throw.
5. **Rate limiting + idempotency + anti-fraud** — Per-endpoint limiters, unique
   idempotency keys on ledger/ad rows, daily caps, device fingerprinting.
6. **Guard isolation** — Mobile API and admin panel use separate guards and
   never cross-authenticate.
7. **One visibility rule** — `Drama::scopePublished()` is the single definition
   of "the app may see this"; drafts, archived and future-dated titles cannot
   leak through any endpoint.
8. **Stream URLs are never listed, and never handed out unpaid** — only the
   episode detail endpoint returns `video_url`, and only when the caller owns the
   episode. Locked episodes return `video_url: null` and `can_play: false`.
9. **Counters are never client-writable** — `view_count`, `like_count`,
   `total_episodes` and `rating` are excluded from the admin Form Requests and
   maintained by the application.
10. **Money is never a float** — payout amounts are stored as `amount_cents`
    integers; only the display string is formatted.
#   b a c k e n d - d r a m a  
 