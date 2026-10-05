# Context

Laravel 13 playground showing how PostHog fits into a Laravel app the Laravel way: product analytics, identity, feature flags, experiments, session replay, surveys and error tracking. Domain is **Matchday**, a football ticket and away-travel booking app for invented clubs. Pairs with Meterly: Meterly answers "is the system healthy?", this answers "what are users doing, and did my change help?"

## Core principles

| Principle | Meaning |
|---|---|
| Controllers never call PostHog | Business code fires Laravel domain events; listeners translate them into analytics events |
| One analytics contract | `Analytics` interface bound in the container; PostHog implementation, null implementation when no token is set |
| Capture off the request path | Server-side events are captured via the queue |
| Flags through Pennant | App code calls `Feature::active()`; a custom Pennant driver makes PostHog the source |
| Dashboards never empty | `matchday:simulate` generates realistic backdated traffic |

## Runtime

| Service | Image / command | Host port | Notes |
|---|---|---|---|
| `app` | `Dockerfile` (`dunglas/frankenphp:php8.4`), Caddy serving `/app/public` on container port 8000 | `APP_PORT`, default 8000 | Healthcheck on `/up`; runs as UID/GID 1000 so bind-mounted files stay host-editable |
| `queue` | Same image, `php artisan queue:work --queue=analytics,default --tries=3 --backoff=5` | none | Database queue driver; `analytics` queue (PostHog capture) drains before `default`; restart after code changes |
| `scheduler` | Same image, `php artisan schedule:work` | none | Runs `routes/console.php` schedule; restart after code changes (`make queue-restart` restarts both) |
| `mysql` | `mysql:8.4`, named volume `mysql-data` | `FORWARD_DB_PORT`, default 3306 | Container-internal `DB_HOST=mysql`, `DB_PORT=3306` |

**Ports:** defaults are app 8000 and MySQL 3306. If a default is taken on the host, bump it (8001, 8002... / 3307, 3308...) in `.env` via `APP_PORT` / `FORWARD_DB_PORT` and update `APP_URL`. The original dev machine uses 8002 / 3308.

**Front end:** Blade + Tailwind 4 via Vite 8. Node runs on the host, not in the image: `make assets` builds to `public/build`, `make vite` runs the dev server with hot reload (writes `public/hot`). FrankenPHP serves the app, so `php artisan dev` is not used. Tests call `withoutVite()` in `tests/TestCase.php`.

**Commands:** `Makefile` is the entry point (`make help` lists targets). All artisan, composer and test commands run inside the container via `docker compose exec app ...`, never on the host.

## Domain model

| Model | Table | Notes |
|---|---|---|
| `Club` | `clubs` | Invented club with stadium and capacity |
| `Competition` | `competitions` | League or cup |
| `SupportersClub` | `supporters_clubs` | Belongs to a club; becomes a PostHog group |
| `User` | `users` | `is_admin`, optional `supporters_club_id` |
| `Stand` | `stands` | Belongs to a club; `price_band` (`App\Enums\PriceBand`), `price_pence`, `is_away_end` |
| `Fixture` | `fixtures` | Home and away club, competition, `kicks_off_at`; routed by `slug`; `upcoming()` scope |
| `TicketAllocation` | `ticket_allocations` | Stock per fixture per stand (`capacity`, `sold`); near sell-out at 90% |
| `TravelOption` | `travel_options` | Coach to an away fixture with its own capacity |
| `Basket` | `baskets` | UUID key; in-progress selection for one fixture (allocation, quantity, optional coach); `updated_at` is last activity; `user_id` and `checkout_started_at` set on first checkout visit; `abandoned_at` set by the abandonment sweep |
| `Booking` | `bookings` | Purchase record with `reference`, quantity, ticket/travel/total in pence |

Money is stored as integer pence. Seats are not modelled individually: stock per fixture per stand is enough for funnel and sell-out behaviour.

## PostHog configuration

`posthog/posthog-php` 4.x, configured in `config/posthog.php`. PostHog Cloud EU, free tier; self-hosting is out of scope.

| Env key | Config key | Default | Purpose |
|---|---|---|---|
| `POSTHOG_DISABLED` | `disabled` | `false` | Kill switch; nothing is sent even with a token |
| `POSTHOG_PROJECT_TOKEN` | `project_token` | empty | Public project key (`phc_...`); empty means the null analytics driver |
| `POSTHOG_PERSONAL_API_KEY` | `secret_key` | empty | Personal (`phx_...`) or project secret (`phs_...`) key for local flag evaluation; server-side only |
| `POSTHOG_HOST` | `host` | `https://eu.i.posthog.com` | Ingestion and API |
| `POSTHOG_ASSETS_HOST` | `assets_host` | `https://eu-assets.i.posthog.com` | Static assets behind the proxy |
| `POSTHOG_UI_HOST` | `ui_host` | `https://eu.posthog.com` | PostHog app links when proxied |
| `POSTHOG_PROXY_PATH` | `proxy_path` | `ingest` | Reverse proxy path for client events |
| `POSTHOG_QUEUE` | `queue` | `analytics` | Queue for server-side capture |
| `POSTHOG_CONSUMER`, `POSTHOG_TIMEOUT_MS`, `POSTHOG_FLAG_TIMEOUT_MS`, `POSTHOG_DEBUG` | `client.*` | `lib_curl`, 3000, 1500, `false` | Passed to `PostHog\Client` |

| `POSTHOG_LOG_CHANNEL` | `log_channel` | empty | When not sending, log every analytics call to this channel (e.g. `single`) instead of dropping it |

`phpunit.xml` forces `POSTHOG_DISABLED=true` and empty keys, so the test suite can never send to PostHog.

## Analytics contract

| Class | Role |
|---|---|
| `App\Analytics\Analytics` | Interface: `capture()`, `identify()`, `alias()`, `groupIdentify()` |
| `App\Analytics\PostHogAnalytics` | Bound when `sending()`; builds PostHog messages, stamps time and UUID v7 at dispatch, queues `CaptureAnalyticsEvent` on `posthog.queue` |
| `App\Jobs\CaptureAnalyticsEvent` | Delivers one message via `PostHog\Client` (`capture`/`identify`/`alias`), then `flush()`; throws `AnalyticsDeliveryFailed` on rejection; 5 tries, backoff 5s/30s/2m/10m |
| `App\Analytics\NullAnalytics` | No token or disabled; drops calls, or logs them when `posthog.log_channel` is set |
| `App\Analytics\FakeAnalytics` | Test double recording calls; `assertCaptured()`, `assertCapturedTimes()`, `assertNotCaptured()`, `assertNothingCaptured()`, `assertIdentified()`, `assertAliased()`, `assertGroupIdentified()` |
| `App\Providers\AnalyticsServiceProvider` | Binds `Analytics` (PostHog when `sending()`, else null) and a `PostHog\Client` singleton for the worker, built with `loadFeatureFlags: false` |

Tests call `fakeAnalytics()` (defined in `tests/Pest.php`) to swap in and return a `FakeAnalytics`. `tests/Support/RecordingHttpClient.php` replaces the SDK's HTTP layer (with canned bodies per path, e.g. `/flags/`) so jobs run through the real `PostHog\Client` and tests assert on the `/batch/` payload without network access.

**Delivery rules:** event time is fixed when the call is made (queue lag never shifts it; simulator timestamps pass straight through); each message carries a UUID generated once, so a retried job is de-duplicated by PostHog; every job flushes because the client batches in memory and a long-running worker would otherwise hold events; `groupIdentify()` is sent as a `$groupidentify` capture because `PostHog\Client` has no such method.

## Feature flags

`laravel/pennant` 1.x with a custom `posthog` store (default). App code only calls `Feature::active()` / `Feature::value()`; PostHog is the source behind it.

| Class (`app/Analytics/Flags/`) | Role |
|---|---|
| `PostHogFeatureDriver` | Pennant driver (`Driver`, `DefinesFeaturesExternally`). Returns PostHog's value (`false`, `true` or a variant key); app `Feature::define()` resolvers are fallbacks when not sending or PostHog lacks the flag (they always receive a `FlagScope`); records `$feature_flag_called` once per person, flag and value per `exposure_ttl` through the queued `Analytics` contract; `activate`/`forget`/`purge` throw `LogicException` because PostHog owns rollout |
| `FlagScope` | Pennant scope: distinct ID + groups + person properties. Default scope (`Feature::resolveScopeUsing`) is `FlagScope::current()`, so anonymous visitors get flags too and keep them after login. `from()` accepts a `FlagScope`, `User`, distinct ID string or null |
| `PostHogFlags` (scoped per request) | Calls `Client::getAllFlags()` once per distinct ID per request, records timings (`mode`, `milliseconds`, `flags`), swallows and reports errors (empty result, so fallbacks apply). Its client is built lazily on first use |
| `FlagClientFactory` | Builds flag clients: `local()` (secret key + `CachedFlagDefinitions`), `remote()` (no secret key), `forConfiguredMode()`; separate from the worker's capture client |
| `FlagEvaluationBenchmark` | Runs the same scopes through both modes, timing client setup separately from per-visitor evaluation, and reports agreement and definitions cache state |
| `CachedFlagDefinitions` | SDK `FlagDefinitionCacheProvider` on Laravel's cache (`posthog:flag-definitions`, `definitions_ttl`); one worker refreshes behind a 30 second lock |

| Env key | Default | Purpose |
|---|---|---|
| `PENNANT_STORE` | `posthog` | Pennant store (`array` in `phpunit.xml`) |
| `POSTHOG_FLAG_EVALUATION` | `local` | `local` (needs `POSTHOG_PERSONAL_API_KEY`) or `remote` (`/flags` per visitor) |
| `POSTHOG_FLAG_DEFINITIONS_TTL` | `60` | Seconds flag definitions stay cached |
| `POSTHOG_FLAG_EXPOSURE_TTL` | `86400` | Seconds before the same exposure is sent again |

| Feature | PostHog key | Fallback | Used in |
|---|---|---|---|
| `App\Features\NewSeatPicker` | `new-seat-picker` | `Rollout::includes()` at 20% | `FixtureController@show` picks `fixtures/partials/seat-map` (stadium map, radio stands around the pitch) or `seat-list`; both post to `seats.store`, so funnel events are unchanged |

- `App\Analytics\Flags\Rollout` reproduces PostHog's percentage bucketing (first 15 hex digits of `sha1("<key>.<distinct id>")` scaled to 0-1, included when at or below N/100), so fallbacks pick the same visitors PostHog would; a test checks it against the SDK's local evaluation for 400 IDs
- Tests force a class feature with `Feature::define('<key>', true|false)`: `activateForEveryone()` only updates scopes already stored, not new visitors
- **Debug page** `GET /debug/flags?samples=N` (`auth` + `can:view-debug`, gate defined in `AppServiceProvider`: `is_admin`; "Debug" nav link for admins): local vs remote for the current visitor plus `debug-sample-*` IDs, with setup, min/mean/max and total timings, per-visitor values and agreement, cache warm/cold and `POST /debug/flags/clear-definitions`. Without a token it lists fallback values. `App\Http\Controllers\Debug` is the one namespace exempt from the controllers-never-touch-analytics arch rule, and is still barred from the PostHog SDK
- `Feature::discover()` runs in `AnalyticsServiceProvider::boot()` after `Feature::extend()`: in `AppServiceProvider` it ran before the `posthog` driver existed and failed boot
- `getAllFlags()` rather than `evaluateFlags()`: the latter sends `$feature_flag_called` through the SDK client inside the request, which breaks "capture stays out of the request path"
- Without a definition cache, PHP's per-request lifecycle would download every definition on every request, making "local" slower than remote

## Domain events to analytics

```
Controller / PlaceBooking  ->  App\Events\*  ->  AnalyticsEventSubscriber  ->  Analytics::capture()  ->  queue  ->  PostHog
```

| Domain event | Dispatched from | Analytics event | Distinct ID |
|---|---|---|---|
| `FixtureViewed` | `FixtureController@show` | `fixture_viewed` | user or anonymous |
| `SeatSelected` | `SeatSelectionController@store` | `seat_selected` | user or anonymous |
| `TravelAdded` | `TravelController@store` | `travel_added` | user or anonymous |
| `CheckoutStarted` | `CheckoutController@show`, first visit per basket only (`baskets.checkout_started_at`) | `checkout_started` | user |
| `TicketPurchased` | `PlaceBooking`, after commit | `ticket_purchased` | booking's user |
| `CheckoutAbandoned` | `DetectAbandonedCheckouts` job, every 5 minutes | `checkout_abandoned` | basket's user |

- Every domain event carries `occurredAt` (default now; `TicketPurchased` uses `purchased_at`), passed through as the analytics timestamp so the simulator can backdate by dispatching the same events
- `App\Listeners\AnalyticsEventSubscriber` is the single translation table, registered with `Event::subscribe()` in `AnalyticsServiceProvider::boot()`; methods are `on*` so listener auto-discovery does not register them twice; runs synchronously because delivery is already queued and the distinct ID needs the request session
- Abandoned = `Basket::abandonable()`: checkout started, `user_id` set, `abandoned_at` null, `updated_at` at least `Basket::ABANDON_AFTER_MINUTES` (30) ago. A conditional `UPDATE ... WHERE abandoned_at IS NULL` claims each basket so overlapping sweeps cannot fire twice; `occurredAt` is last activity + 30 minutes. Only checkout-stage baskets qualify because the job has no session to recover an anonymous ID
- `App\Analytics\DistinctId` (scoped): user ID when logged in, else the posthog-js cookie ID, else a UUID v7 kept in the session under `analytics.anonymous_id`; `useAnonymousId()` overrides it outside HTTP (simulator). It reads `request()` at call time because the subscriber, and so `DistinctId`, is first built at boot
- **Identity stitching:** `AnalyticsEventSubscriber::onLogin()` handles Laravel's `Illuminate\Auth\Events\Login` (password login, `Auth::login()` after registration, remember-me). It calls `alias(userId, anonymousId)` so PostHog merges the anonymous person into the user, then `identify(userId, PersonProperties::for($user))`. The alias is skipped when the anonymous ID already equals the user ID (the browser identified first). With PostHog, alias is queued before identify
- **Groups:** `App\Analytics\Groups` defines the `supporters_club` group type (key: supporters' club slug). `Groups::for($user)` is attached to every server event whose actor belongs to a club (anonymous visitors and club-less supporters send none). `Groups::properties()` (`name`, `club` slug, `members` count; never a member list) is sent with `groupIdentify` on each member login and for every club by `matchday:sync-groups` (`make sync-groups`). posthog-js joins the group via `posthog.group()` from `identity.groups`, so client events carry it too. Group analytics may be a paid PostHog add-on depending on plan; events carry `$groups` either way, so breakdowns appear once it is enabled
- `App\Analytics\PersonProperties`: `supporters_club` (slug), `has_supporters_club`, `signed_up_at` (date). No name or email, same rule as event properties

## Client analytics (posthog-js)

| Piece | Detail |
|---|---|
| `resources/js/analytics.js` | `posthog.init()` from JSON settings in the page; `api_host` `/ingest`, `defaults: '2026-08-30'`, `person_profiles: 'identified_only'`, session replay off until masking is configured |
| `App\View\Components\PosthogConfig` (`<x-posthog-config :user="auth()->user()" />`) | Renders token, proxy path, UI host, `bootstrap` (user ID with `isIdentifiedID: true` when logged in, else the anonymous ID) and `identity` (user ID + `PersonProperties`, or null for guests); renders nothing unless `sending()`; never includes the secret key, name or email |
| `resources/js/identity.js` `syncIdentity()` | Logged in and not yet that user: `identify()`. Identified as a different user: `reset()` then `identify()`, so two people are never merged. Guest but browser still identified: `reset()`. Import-free, tested with `node --test` (`resources/js/identity.test.js`, `make test-js`) |
| `App\Http\Controllers\IngestProxyController` (`ANY /ingest/{path?}`) | Registered in `bootstrap/app.php` `then:` outside the `web` group (no session, cookies or CSRF); forwards raw path and query, body, selected headers and `X-Forwarded-For`; `static/` and `array/` go to the assets host; 404 unless `sending()`, 502 if PostHog is unreachable |

**Shared anonymous ID:** on a first visit the server's anonymous ID bootstraps posthog-js. After that, `DistinctId` adopts `distinct_id` from the posthog-js cookie `ph_<token>_posthog` (exempted from encryption by `AnalyticsServiceProvider::exemptPostHogCookie()`), because the cookie outlives the Laravel session. Malformed or oversized cookie values are ignored, and so is a cookie in the `$user_state: identified` state: it belongs to whoever last logged in on that browser, so adopting it would credit a logged-out visitor's events to them or alias the next login into them. After logout the first page still uses the session's anonymous ID while posthog-js resets to a new one; the server adopts the new cookie ID from the next request.

## Event catalogue

Names live in `App\Analytics\Events` (snake_case, past tense); properties are built with `App\Analytics\EventProperties`.

| Event | Source | Properties |
|---|---|---|
| `$pageview` | Client (posthog-js) | Automatic |
| `fixture_viewed` | Server | fixture |
| `seat_selected` | Server | fixture, seats |
| `travel_added` | Server | basket |
| `checkout_started` | Server | basket |
| `ticket_purchased` | Server, from `TicketPurchased` | booking (includes `revenue` in pounds and `currency`) |
| `checkout_abandoned` | Server, scheduled job | basket |
| `$create_alias`, `$identify` | Server, on `Login` | alias: user ID + anonymous ID; identify: `PersonProperties` |
| `$groupidentify` | Server, on member `Login` and `matchday:sync-groups` | `Groups::properties()` |
| `$feature_flag_called` | Server, from `PostHogFeatureDriver` | `$feature_flag`, `$feature_flag_response` (`EventProperties::featureFlag()`) |

| Builder method | Keys |
|---|---|
| `fixture()` | `fixture_id`, `fixture` (slug), `competition`, `home_club`, `away_club` (slugs), `kicks_off_at`, `days_until_kick_off` |
| `seats()` | `stand`, `price_band`, `supporter_side` (`home`/`away` from the stand), `unit_price_pence`, `quantity`, `tickets_remaining`, `near_sell_out` |
| `travel()` | `has_travel`, `travel_price_pence` |
| `basket()` | fixture + seats + travel + `ticket_total_pence`, `travel_total_pence`, `total_pence` |
| `booking()` | basket keys + `booking_reference`, `revenue`, `currency` |
| `supportersClub()` | `supporters_club` (slug) |

**Personal data:** `with()` rejects any key not in `EventProperties::ALLOWED`, so names, emails and card details cannot reach an event; the only identifier sent is the distinct ID. Null values are dropped. `make($at)` takes the event time so backdated simulator events get correct relative values.

## Simulator

`php artisan matchday:simulate --users=500 --days=30 [--seed=N] [--no-stitching]` (`make simulate USERS=500 DAYS=30`, `make simulate-unstitched`). `App\Simulation\Simulator` fires the real domain events with backdated `occurredAt`, so analytics flows through the same subscriber, contract and queue as live traffic.

| Persona (`App\Simulation\Persona`) | Share | Behaviour |
|---|---|---|
| Browser | 45 | 1-3 fixtures, seldom selects (25%), checkout 40%, buys 45% |
| Bargain hunter | 25 | 2-5 fixtures, cheapest seats across all viewed, buys 60% |
| Loyal away fan | 15 | Own club's away fixtures, away end, coach 80%, always in a supporters' club, buys 90% |
| Abandoner | 15 | Reaches checkout 90% of the time, never pays |

- Nothing is written to the database: baskets, bookings and the checkout `User` are unsaved models with relations set by hand; stock is never decremented
- Each supporter browses as `sim-anon-<hex>` and, on reaching checkout, logs in as `sim-user-<hex>` by firing Laravel's real `Login` event, so the app's own `onLogin` aliases, identifies and group-identifies them (filter on the `sim-` prefix in PostHog). The unsaved `User` uses a string key, because `User`'s integer key type would cast `sim-...` to `0`
- `--no-stitching` skips that login: the same journeys, but purchases land on user IDs that never viewed a fixture, so a PostHog view-to-purchase funnel collapses to 0%. The command prints the per-person view-to-purchase rate PostHog will show (around 17% with stitching, 0% without) for the before/after comparison. Send the two runs to separate PostHog projects, or filter by time range, to compare them side by side
- Every unpaid checkout fires `checkout_abandoned` 30 minutes later, as the real sweep would
- Journeys start between `--days` ago and 3 hours ago, so abandonment never lands in the future
- `--seed` makes runs repeatable (`Random\Randomizer` with `Mt19937`); the command prints persona and funnel tables so it is useful without a PostHog token
- With a token, each event is one queued job and one HTTP flush; a 500-supporter run (~1,750 events) takes a few minutes to drain through the worker

## Seed data

`DatabaseSeeder` calls `ClubSeeder`, `CompetitionSeeder`, `SupportersClubSeeder`, `UserSeeder`, `FixtureSeeder`. Data is fixed (no Faker), every seeder upserts on a natural key so re-seeding is safe, and `WithoutModelEvents` keeps seeding from firing domain events.

| Item | Detail |
|---|---|
| Clubs | 6 (`ClubSeeder::CLUBS`), each with Main (premium), North (standard), Family (value) and Away End (standard) stands priced from a per-club base |
| Fixtures | 20 from the next Saturday: Premier Division round robin (15) plus County Shield midweek ties (5); dates are relative to seed time |
| Stock | Per fixture per home stand; 5 big matches near sell-out with the away end sold out, others a stable 15-69% sold (hash of fixture and stand) |
| Travel | Coach from the away club's city for every fixture, plus a supporters' club coach when the away club has one |
| Users | `admin@matchday.test` plus 10 supporters (`firstname.lastname@matchday.test`, see `UserSeeder::SUPPORTERS`); all passwords `password` |
| Supporters' clubs | 4 with 4, 3, 2 and 1 members |

## Key directories

| Path | Purpose |
|---|---|
| `app/` | Application code |
| `app/Enums/` | Backed enums (`PriceBand`) |
| `database/` | Migrations, factories, seeders |
| `resources/` | Blade views, JS and CSS built by Vite |
| `routes/web.php` | Web routes: `/` fixture list, `/fixtures/{slug}` fixture page, `POST /fixtures/{slug}/seats`, `/basket`, `POST`/`DELETE /basket/travel`, `/checkout` (auth), `/bookings/{reference}` (owner only) |
| `app/Checkout/` | `PlaceBooking` (locks stock, charges, books, dispatches `TicketPurchased`), `FakePaymentGateway`, `CheckoutFailed` |
| `app/Features/` | Pennant class features (PostHog key in `$name`, `resolve()` is the fallback) |
| `app/Simulation/` | `Simulator`, `Persona`, `SimulationReport` for `matchday:simulate` |
| `app/Analytics/` | Event catalogue (`Events`) and allow-listed property builder (`EventProperties`) |
| `app/Events/` | Domain events (`TicketPurchased`, dispatched after commit) |
| `app/Basket/CurrentBasket.php` | Session-scoped access to the visitor's basket (select seats, add/remove travel) |
| `app/Http/Requests/` | Form requests holding stock, ownership and auth validation |
| `app/Http/Controllers/Auth/` | Hand-rolled login, registration and logout; redirect to intended URL, else basket, else fixtures |
| `resources/views/components/form/field.blade.php` | Labelled input with old value and error message |
| `request.http` | Example requests (fixtures, basket, checkout, auth, ingest proxy, debug); `@baseUrl` defaults to port 8000, POSTs need `@csrfToken` from the page |
| `resources/views/components/layouts/app.blade.php` | Anonymous layout component (`<x-layouts.app>`) |
| `app/Support/` | Small helpers (`Money::format()` for pence to pounds) |
| `tests/` | Pest suites: `Unit` (no framework), `Feature` (full app via `tests/Pest.php`), `Arch` (`tests/ArchTest.php`) |

## Decisions

| Decision | Why |
|---|---|
| MySQL + Docker/FrankenPHP instead of the brief's SQLite + Herd | Project default stack; reproducible on any machine, closer to production |
| Separate `queue` container | Capture runs off the request path; worker lifecycle is visible in `docker compose ps` |
| In-memory SQLite for tests, MySQL for the app | Fast, isolated tests with no second MySQL database to provision; avoid MySQL-only SQL in app code |
| Basket in a `baskets` table, only its ID in the session | The abandonment job must query idle baskets; serialised session payloads cannot be queried |
| Checkout in a `PlaceBooking` action with `lockForUpdate()` | Stock re-checked under lock so the last seats cannot be sold twice; controller stays thin |
| `TicketPurchased` implements `ShouldDispatchAfterCommit` | Analytics never records a purchase that rolled back |
| Fake payment gateway with a declined test card | Gives the funnel a realistic payment failure point without real payments |
| Hand-rolled auth, no starter kit | Keeps the login and registration seam visible for identity stitching (alias anonymous ID to user ID) |
| Server bootstraps the client's anonymous ID, then adopts the cookie's | One person across client and server events without a round trip; survives session expiry |
| Node on the host, not in the PHP image | Husky already needs host Node; keeps the FrankenPHP image lean |
| Database queue driver, no Redis | Keeps the stack small; volume is low for a playground |
| PostHog Cloud EU, free tier | Self-hosting is out of scope |
| Dedicated `analytics` queue | Capture backlog never delays app jobs and can be monitored or scaled on its own |
| Queue the PostHog message, not the domain call | The job is a dumb courier; format, time and UUID are decided once in the request, so retries are identical |
| SDK client built from config, not `PostHog::init()` | A container-bound instance is swappable in tests; the static facade also reads `POSTHOG_API_KEY` from the environment implicitly |
| Skeleton `CLAUDE.md` / `AGENTS.md` removed | They instruct host-side PHP and Boost installs, which conflicts with container-only commands |

## Testing

| Item | Detail |
|---|---|
| Framework | Pest 5 with `pestphp/pest-plugin-laravel`; run via `make test`. JavaScript: `node --test` on `resources/js/**/*.test.js`, run via `make test-js` (host Node, no extra dependencies) |
| Database | In-memory SQLite (`phpunit.xml`), queue `sync`, cache and session `array` |
| Arch rules | Pest `php` and `security` presets, no `dd`/`dump`/`ray`, `App` uses strict types, plus the analytics boundaries below |
| Analytics modes | `tests/Feature/Analytics/AnalyticsModesTest.php` walks the full journey with no token, with the kill switch on (nothing queued) and with a token (every funnel event queued on `analytics`, in order) |

Pest's `php` preset does not cover `dd`/`dump`; those are asserted explicitly rather than adopting the stricter `laravel` preset.

**Analytics boundaries (`tests/ArchTest.php`):**

| Rule | Enforces |
|---|---|
| `App\Http\Controllers` (except `Debug`) does not use `PostHog`, `App\Analytics` or `CaptureAnalyticsEvent` | Controllers never call PostHog (brief's definition of done) |
| `App\Http\Controllers\Debug` does not use `PostHog` | Debug pages go through the analytics layer, not the SDK |
| `PostHog` only used in `App\Analytics`, `CaptureAnalyticsEvent`, `AnalyticsServiceProvider` | The SDK is contained |
| `App\Analytics\Analytics` only used in `App\Analytics`, `AnalyticsEventSubscriber`, `AnalyticsServiceProvider`, `SyncAnalyticsGroups` | One translation table, plus the group sync command |
| `App\Events`, `App\Models`, `App\Checkout`, `App\Basket` do not use `PostHog` or `App\Analytics` | Domain code fires events and nothing else |
| `FakeAnalytics` only used in `App\Analytics` | The fake never ships into app code |

Each rule was checked by planting a violation and confirming it fails. Pest 5.3 gotcha: with several targets in one `expect([...])`, `not->toUse()` missed violations outside the first target, so the domain rule loops one namespace at a time.

## Git hooks

Husky-managed, tracked in `.husky/`, installed by `npm install` (`prepare` script). Hook bodies are POSIX `sh` because Husky ignores the shebang.

| Hook | Checks |
|---|---|
| `commit-msg` | Entirely lowercase, Conventional Commits `type: description`, no scopes |
| `pre-commit` | `./vendor/bin/pint --test`, then `osv-scanner --lockfile=composer.lock` (runs on the host; needs host PHP and `osv-scanner`) |

## Gotchas

- On a fresh clone the `queue` container restart-loops until the first `migrate` creates the `jobs` table; it recovers on its own afterwards
- The image pre-creates `/config/psysh` owned by `app` so `php artisan tinker` works as the non-root user
- Test cards: `4242 4242 4242 4242` succeeds, `4000 0000 0000 0002` is declined; card numbers are never stored
- SQLite ignores `lockForUpdate()`, so locking is only exercised against MySQL, not in the test suite
- `CurrentBasket::selectSeats()` calls `unsetRelations()` after changing the selection; otherwise listeners would read the previously loaded stand
- The proxy forwards `getRequestUri()` verbatim: route parameters drop trailing slashes and `getQueryString()` reorders keys. Laravel's test `call()` also trims trailing slashes, so the exact-path test goes through the HTTP kernel directly
- Login and registration call `session()->regenerate()`, which keeps session data: the basket survives authentication, and so will the anonymous analytics ID
- `.env` is not copied into the image (`.dockerignore`); it is read from the bind mount
