# laravel-posthog-playground

<img width="3026" height="1654" alt="Screenshot 2026-10-12 170615" src="https://github.com/user-attachments/assets/29973630-2e08-448d-a3d0-fb1374d9a8f8" />

A Laravel 13 football ticketing app that shows where PostHog fits in a real codebase: product analytics, identity, feature flags, experiments, session replay, surveys and error tracking, wired the Laravel way rather than as scattered SDK calls.

<img width="3156" height="1664" alt="Screenshot 2026-10-12 170758" src="https://github.com/user-attachments/assets/8d8cd1bd-26dd-4e6d-9873-b9227f18ee9b" />

> **PostHog Cloud (EU), free tier.** Self-hosting PostHog is out of scope. The app also runs with no PostHog account at all: analytics falls back to a null driver that can log every event locally.

<img width="3048" height="1650" alt="Screenshot 2026-10-12 170550" src="https://github.com/user-attachments/assets/7f9c9da4-8c17-483b-bb98-3b0b4ee735c9" />


**Matchday** sells tickets for invented clubs. Supporters browse fixtures, pick a stand, optionally add a coach for away days, log in and pay with a fake card. That funnel has obvious drop-off points and money at the end, which makes every PostHog feature easy to explain.

## What this demonstrates

| PostHog feature | Where it lives | The interesting part |
|---|---|---|
| Product analytics | Domain events, one subscriber, a queued driver | Controllers never call PostHog; an arch test enforces it |
| Identity | `onLogin` aliases the anonymous ID to the user | `make simulate-unstitched` shows the funnel collapsing to 0% without it |
| Groups | Supporters' clubs as the `supporters_club` group | Server and browser events both carry the group |
| Feature flags | A custom Laravel Pennant driver | App code calls `Feature::active()` and never knows PostHog is the source |
| Experiments | `checkout-cta`, two button labels | Variant chosen server-side, so no flicker; exposure only where the button is seen |
| Session replay | posthog-js behind a consent banner | Inputs masked, card fields never recorded |
| Surveys | Checkout exit survey | Answers stored on the basket and sent to a PostHog API survey |
| Error tracking | `withExceptions()` in `bootstrap/app.php` | Errors linked to the person and their replay session |

Two things make it a playground rather than a demo:

- **Dashboards are never empty.** `matchday:simulate` replays hundreds of backdated supporter journeys through the real domain events, so funnels, flag rollouts and the experiment all have data on first run.
- **Real browsers too.** Laravel Dusk drives Chromium through the funnel, producing genuine client events and session replays.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Database | MySQL 8.4 (tests use in-memory SQLite) |
| Runtime | Docker Compose: FrankenPHP `app`, `queue` worker, `scheduler`, `mysql`, and a Selenium `browser` for Dusk |
| Front end | Blade, Tailwind 4, Vite 8 (Node runs on the host) |
| PostHog | `posthog/posthog-php` 4.x on the server, `posthog-js` in the browser |
| Flags | `laravel/pennant` with a PostHog driver |
| Tests | Pest 5 (feature, unit and architecture), `node --test` for JavaScript, Laravel Dusk for browser journeys |
| Quality | Laravel Pint (strict types), Husky hooks, `osv-scanner` |

## Getting started

### Prerequisites

- **Docker** with Compose
- **Node.js 22+** on the host (Vite builds and the Git hooks run there)
- **osv-scanner** on the host for the pre-commit security audit: `go install github.com/google/osv-scanner/cmd/osv-scanner@latest`
- **Free ports.** The app defaults to **8000** and MySQL to **3306** on the host. If either is taken, copy `.env.example` to `.env` first and bump `APP_PORT` (8001, 8002...) and `APP_URL` to match, or `FORWARD_DB_PORT` (3307, 3308...)
- **Optional:** a PostHog Cloud EU project. Without one everything runs on the null driver

### Run it

1. `make setup` - creates `.env`, installs PHP and Node dependencies (which also activates the Git hooks), generates the app key, migrates and seeds MySQL, builds assets and starts the containers
2. Open http://localhost:8000 (or your `APP_PORT`)
3. `make simulate` - generate 500 backdated supporter journeys over 30 days
4. `make test` - run the Pest suite; `make test-js` for the JavaScript tests
5. `make dusk` - drive real browser journeys through the funnel

`make help` lists every target. Day to day: `make up`, `make down`, `make logs`, `make queue-restart` after changing PHP code the worker runs.

### Seeded logins

All passwords are `password`.

| Email | Role | Supporters' club |
|---|---|---|
| `admin@matchday.test` | Admin (can open `/debug/flags` and `/debug/error`) | None |
| `alex.morgan@matchday.test`, `priya.shah@matchday.test`, `tom.fletcher@matchday.test`, `jess.okafor@matchday.test` | Supporter | Harbour City Exiles |
| `sam.doyle@matchday.test`, `chloe.bennett@matchday.test`, `marcus.reid@matchday.test` | Supporter | Eastcliff Gulls Travel Club |
| `hannah.price@matchday.test`, `owen.hughes@matchday.test` | Supporter | Northgate Ironsides |
| `leah.turner@matchday.test` | Supporter | Millbrook Away Days |

Test cards: `4242 4242 4242 4242` pays, `4000 0000 0000 0002` is declined. Nothing is charged or stored.

### Connecting PostHog

1. Use a PostHog Cloud EU project, or point the three `POSTHOG_*_HOST` keys at your region
2. Put your project token (`phc_...`) in `POSTHOG_PROJECT_TOKEN`, and a personal API key (`phx_...`) in `POSTHOG_PERSONAL_API_KEY`. Give the key write access to feature flags, experiments and surveys: the app uses it for local flag evaluation and the setup command uses it to configure the project
3. `make posthog-setup` creates everything the app expects and is safe to re-run (anything that already exists is left alone):
   - `new-seat-picker`: boolean flag, 20% rollout
   - `checkout-cta`: multivariate flag, `control` and `test` at 50% each
   - "Checkout CTA wording" experiment on `checkout-cta`, a `checkout_started` to `ticket_purchased` funnel, launched with a start date 30 days back so simulated traffic counts (`DAYS=7` to change it)
   - "Checkout exit survey", an API survey with the six exit reasons, started on the same backdated date (the simulator answers it for some abandoned checkouts), its ID written to `POSTHOG_EXIT_SURVEY_ID` in `.env`
4. `make queue-restart`, then `make sync-groups` and `make simulate`
5. In PostHog, turn on session replay under Settings, the one thing the API setup leaves to you

## Environment variables

| Key | Default | Purpose |
|---|---|---|
| `APP_PORT` | `8000` | Host port for the app; bump if taken |
| `FORWARD_DB_PORT` | `3306` | Host port for MySQL; bump if taken |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `matchday` / `matchday` / `secret` | MySQL credentials (container host `mysql`, port 3306) |
| `QUEUE_CONNECTION` | `database` | Queue driver for analytics delivery |
| `POSTHOG_DISABLED` | `false` | Kill switch: nothing is sent even with a token |
| `POSTHOG_PROJECT_TOKEN` | empty | Public project key; empty means the null driver |
| `POSTHOG_PERSONAL_API_KEY` | empty | Server-only key for downloading flag definitions |
| `POSTHOG_HOST` | `https://eu.i.posthog.com` | Ingestion and API |
| `POSTHOG_ASSETS_HOST` | `https://eu-assets.i.posthog.com` | posthog-js bundles behind the proxy |
| `POSTHOG_UI_HOST` | `https://eu.posthog.com` | PostHog app links |
| `POSTHOG_PROXY_PATH` | `ingest` | Reverse proxy path for browser events |
| `POSTHOG_QUEUE` | `analytics` | Queue for server-side delivery |
| `POSTHOG_LOG_CHANNEL` | empty | Without a token, log every analytics call here (try `single`) |
| `POSTHOG_DEBUG` | `false` | SDK debug output |
| `POSTHOG_FLAG_EVALUATION` | `local` | `local` (needs the personal key) or `remote` |
| `POSTHOG_FLAG_DEFINITIONS_TTL` | `60` | Seconds flag definitions stay cached |
| `POSTHOG_FLAG_EXPOSURE_TTL` | `86400` | Seconds before the same exposure is sent again |
| `POSTHOG_EXIT_SURVEY_ID` | empty | PostHog API survey for exit survey answers |
| `PENNANT_STORE` | `posthog` | Pennant store: `posthog`, `database` or `array` |

## Endpoints and request.http

`request.http` works with the VS Code REST Client and the JetBrains HTTP Client. `@baseUrl` defaults to port 8000. POST routes are CSRF-protected web routes: open the fixture page first, then paste the page's `csrf-token` meta value into `@csrfToken`.

| Route | Purpose |
|---|---|
| `GET /`, `GET /fixtures/{slug}` | Fixture list and fixture page |
| `POST /fixtures/{slug}/seats` | Select a stand and quantity |
| `GET /basket`, `POST`/`DELETE /basket/travel` | Basket and away coach |
| `GET`/`POST /checkout` | Checkout and fake payment (login required) |
| `POST /checkout/exit-survey` | Exit survey answer |
| `GET /bookings/{reference}` | Confirmation, owner only |
| `GET`/`POST /login`, `/register`, `POST /logout` | Hand-rolled auth |
| `ANY /ingest/{path}` | posthog-js reverse proxy |
| `GET /debug/flags`, `GET /debug/error` | Admin only: flag evaluation timings, deliberate error |

## Event catalogue

Names are snake_case and past tense, defined in `App\Analytics\Events`. Properties come from an allow-listed builder, so names, emails and card details cannot reach PostHog; the only person identifier is the distinct ID.

| Event | Source | Key properties |
|---|---|---|
| `$pageview` | Browser | Automatic |
| `fixture_viewed` | Server | Fixture, competition and club slugs, kick-off, days until kick-off |
| `seat_selected` | Server | Stand, price band, home or away side, unit price, quantity, stock left |
| `travel_added` | Server | Basket totals, coach price |
| `checkout_started` | Server | Basket totals, `$feature/checkout-cta` |
| `ticket_purchased` | Server, after the booking commits | `revenue`, `currency`, booking reference, `$feature/checkout-cta` |
| `checkout_abandoned` | Server, scheduled sweep | Basket totals, `$feature/checkout-cta` |
| `exit_survey_answered` | Server | Basket totals, `exit_reason` |
| `$feature_flag_called` | Server, Pennant driver | `$feature_flag`, `$feature_flag_response` |
| `$exception` | Server, Laravel's exception handler | Stack, route, path, `$session_id` |
| `$create_alias`, `$identify`, `$groupidentify` | Server, on login | Person and group properties |

## Architecture notes

### One path for every event

```
Controller / action  ->  domain event  ->  AnalyticsEventSubscriber  ->  Analytics contract  ->  queue  ->  PostHog
```

Business code only fires Laravel events such as `TicketPurchased`. A single subscriber translates them into analytics calls, so there is one place to read to know what PostHog receives. `Analytics` is an interface with three implementations: PostHog (queued), null (optionally logged) and a fake with `assertCaptured()`-style assertions for tests. Pest architecture rules enforce the boundaries: controllers, models and checkout logic cannot touch PostHog or the analytics layer.

### Delivery that survives a queue

The PostHog message is built in the request and the job is a courier. Three details matter:

- **Time fixed at dispatch,** so a queue backlog never shifts events later, and the simulator's backdated times pass straight through
- **A UUID per message,** so a retried job is de-duplicated by PostHog instead of counted twice
- **Flush every job and fail loudly,** because the SDK batches in memory: a long-running worker would otherwise sit on events, and a rejected batch is retried with backoff rather than dropped

### Identity that follows the person

Anonymous visitors get an ID the server shares with posthog-js in both directions (bootstrap on first visit, then adopt the browser's cookie). On login the server aliases that ID to the user, posthog-js identifies, and on logout it resets. A shared browser never merges two users: identified cookies are ignored server-side, and the browser resets before identifying someone new.

### Flags that do not know about PostHog

A custom Pennant driver makes PostHog the source of `Feature::active()`. Flags are scoped to the analytics distinct ID, so anonymous visitors get a variant and keep it after login. Local evaluation computes flags in PHP from definitions cached in Laravel's cache; without that cache PHP's request lifecycle would download every definition on every request, making "local" slower than remote. `/debug/flags` shows both modes side by side with timings. When PostHog is not configured, each feature's fallback reproduces PostHog's own bucketing, verified against the SDK.

### Experiments without flicker or bias

The `checkout-cta` variant is chosen on the server and rendered into the page, so the button never changes after load. Exposure is recorded where the button is shown, and only checkout-stage events are tagged with `$feature/checkout-cta`: tagging earlier steps would evaluate the flag, and so count as exposed, people who never reached checkout.

### Consent

One first-party cookie drives both sides. posthog-js starts opted out (no capture, cookies or replay) until the visitor accepts; a visitor who declines gets nothing captured server-side either, via a decorator around the bound driver. Before any choice, first-party server events still flow; changing that to "no choice means no" is one line in `App\Analytics\Consent`.

### PostHog error tracking compared with Sentry

Laravel-reported exceptions go to PostHog as `$exception` events from `withExceptions()`, linked to the person and to the posthog-js session, so an error sits next to the replay that caused it. Failed analytics deliveries are never reported back, which would loop while PostHog is down.

| | PostHog error tracking (as built here) | Sentry |
|---|---|---|
| Linked to replay, person, flags and experiments | Yes, it is the same product | Has its own replay; flags and product analytics live elsewhere |
| Laravel integration | Hand-rolled: under 200 lines on the existing analytics contract | First-party `sentry/sentry-laravel` with automatic breadcrumbs, queries and queue context |
| Performance tracing | No | Yes (transactions, spans, profiling) |
| Releases, alerting, issue workflow | Basic | Mature: release health, ownership rules, rich alerting and integrations |
| One more vendor and bill | No | Yes |

Choose PostHog error tracking when the questions are "who hit this, what were they doing, and which variant were they in". Choose Sentry when you need performance tracing and a mature triage workflow. Running both is common.

### Other decisions

- **Baskets live in a table, not the session,** so the abandonment sweep can query idle checkouts. The sweep claims each basket with a conditional update, so overlapping runs cannot double-count
- **The `/ingest` reverse proxy** runs outside the web middleware group (no session, cookies or CSRF), forwards the raw path because PostHog's endpoints are slash-terminated, and is off entirely without a token so it is never an open relay
- **Stock is locked during checkout,** and `TicketPurchased` only dispatches after the transaction commits, so analytics never records a purchase that rolled back
- **The simulator writes nothing to the database.** Baskets, bookings and users are in-memory models; each simulated supporter browses as `sim-anon-...` and logs in as `sim-user-...`, easy to filter out in PostHog
- **Dusk runs in a Selenium container sharing the app's network,** so Chromium reaches the app as `localhost`, which Chrome never upgrades to HTTPS
- **A `packages/larahog` branch** swaps the delivery layer for `temperbit/larahog` to show what a package gives and costs; the contract keeps that swap to one class and one binding. Its `CONTEXT.md` has the comparison

`CONTEXT.md` is the detailed orientation guide for anyone, or any coding assistant, picking the project up.
