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
| `queue` | Same image, `php artisan queue:work --tries=3 --backoff=5` | none | Database queue driver; restart after code changes to pick them up |
| `mysql` | `mysql:8.4`, named volume `mysql-data` | `FORWARD_DB_PORT`, default 3306 | Container-internal `DB_HOST=mysql`, `DB_PORT=3306` |

**Ports:** defaults are app 8000 and MySQL 3306. If a default is taken on the host, bump it (8001, 8002... / 3307, 3308...) in `.env` via `APP_PORT` / `FORWARD_DB_PORT` and update `APP_URL`. The original dev machine uses 8002 / 3308.

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
| `Booking` | `bookings` | Purchase record with `reference`, quantity, ticket/travel/total in pence |

Money is stored as integer pence. Seats are not modelled individually: stock per fixture per stand is enough for funnel and sell-out behaviour.

## Key directories

| Path | Purpose |
|---|---|
| `app/` | Application code |
| `app/Enums/` | Backed enums (`PriceBand`) |
| `database/` | Migrations, factories, seeders |
| `resources/` | Blade views, JS and CSS built by Vite |
| `routes/web.php` | Web routes |
| `tests/` | Pest suites: `Unit` (no framework), `Feature` (full app via `tests/Pest.php`), `Arch` (`tests/ArchTest.php`) |

## Decisions

| Decision | Why |
|---|---|
| MySQL + Docker/FrankenPHP instead of the brief's SQLite + Herd | Project default stack; reproducible on any machine, closer to production |
| Separate `queue` container | Capture runs off the request path; worker lifecycle is visible in `docker compose ps` |
| In-memory SQLite for tests, MySQL for the app | Fast, isolated tests with no second MySQL database to provision; avoid MySQL-only SQL in app code |
| Database queue driver, no Redis | Keeps the stack small; volume is low for a playground |
| PostHog Cloud EU, free tier | Self-hosting is out of scope |
| Skeleton `CLAUDE.md` / `AGENTS.md` removed | They instruct host-side PHP and Boost installs, which conflicts with container-only commands |

## Testing

| Item | Detail |
|---|---|
| Framework | Pest 5 with `pestphp/pest-plugin-laravel`; run via `make test` |
| Database | In-memory SQLite (`phpunit.xml`), queue `sync`, cache and session `array` |
| Arch rules | Pest `php` and `security` presets, no `dd`/`dump`/`ray`, `App` uses strict types |

Pest's `php` preset does not cover `dd`/`dump`; those are asserted explicitly rather than adopting the stricter `laravel` preset.

## Git hooks

Husky-managed, tracked in `.husky/`, installed by `npm install` (`prepare` script). Hook bodies are POSIX `sh` because Husky ignores the shebang.

| Hook | Checks |
|---|---|
| `commit-msg` | Entirely lowercase, Conventional Commits `type: description`, no scopes |
| `pre-commit` | `./vendor/bin/pint --test`, then `osv-scanner --lockfile=composer.lock` (runs on the host; needs host PHP and `osv-scanner`) |

## Gotchas

- On a fresh clone the `queue` container restart-loops until the first `migrate` creates the `jobs` table; it recovers on its own afterwards
- `.env` is not copied into the image (`.dockerignore`); it is read from the bind mount
