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

**Commands:** all artisan, composer and test commands run inside the container via `docker compose exec app ...`, never on the host.

## Key directories

| Path | Purpose |
|---|---|
| `app/` | Application code |
| `database/` | Migrations, factories, seeders |
| `resources/` | Blade views, JS and CSS built by Vite |
| `routes/web.php` | Web routes |
| `tests/` | Test suite |

## Decisions

| Decision | Why |
|---|---|
| MySQL + Docker/FrankenPHP instead of the brief's SQLite + Herd | Project default stack; reproducible on any machine, closer to production |
| Separate `queue` container | Capture runs off the request path; worker lifecycle is visible in `docker compose ps` |
| Database queue driver, no Redis | Keeps the stack small; volume is low for a playground |
| PostHog Cloud EU, free tier | Self-hosting is out of scope |
| Skeleton `CLAUDE.md` / `AGENTS.md` removed | They instruct host-side PHP and Boost installs, which conflicts with container-only commands |

## Gotchas

- On a fresh clone the `queue` container restart-loops until the first `migrate` creates the `jobs` table; it recovers on its own afterwards
- `.env` is not copied into the image (`.dockerignore`); it is read from the bind mount
