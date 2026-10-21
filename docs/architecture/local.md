# Local development environment

The Local_Dev_Environment is Docker Compose with exactly six services
(Requirement 24.1):

| Service | Role | Published port |
| --- | --- | --- |
| `frontend` | Vite development server for the Web_Client | `FRONTEND_PORT` (5173) |
| `backend` | Delivery_API HTTP process | `BACKEND_PORT` (8000) |
| `queue-worker` | Queue_Worker over `default,notifications,payments,dispatch,reports` | — |
| `reverb` | Realtime_Gateway (Laravel Reverb) | `REVERB_PORT` (8080) |
| `postgres` | PostgreSQL 16 + PostGIS, volume `pgdata` | `DB_PORT` (5432) |
| `redis` | Redis 7 (append-only), volume `redisdata` | `REDIS_PORT` (6379) |

`./backend` and `./frontend` are bind-mounted, so a source change takes effect
without an image rebuild (Requirement 24.3). Database and cache data live only
in the named volumes `pgdata` and `redisdata`, so removing and recreating a
container preserves every committed row (Requirements 24.4, 24.5).

`backend/.env` is the single environment file. It supplies both the container
environment and the Compose interpolation values, which is why every command
below passes `--env-file ./backend/.env` (Requirement 24.6).

## Commands

| Purpose | Command |
| --- | --- |
| Start everything (Requirement 24.2) | `docker compose --env-file ./backend/.env up -d --build` |
| Application key, once per checkout | `docker compose --env-file ./backend/.env exec backend php artisan key:generate` |
| Migrate (Requirement 24.7) | `docker compose --env-file ./backend/.env exec backend php artisan migrate` |
| Migrate and seed demonstration data (Requirement 24.7) | `docker compose --env-file ./backend/.env exec backend php artisan migrate --seed` |
| Seed only | `docker compose --env-file ./backend/.env exec backend php artisan db:seed` |
| Full test suite in the container (Requirement 24.8) | `docker compose --env-file ./backend/.env exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: backend php artisan test` |
| One suite | `docker compose --env-file ./backend/.env exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: backend php artisan test --testsuite=Unit` |
| Stop, keeping data | `docker compose --env-file ./backend/.env down` |
| Stop and discard volumes | `docker compose --env-file ./backend/.env down -v` |

A clean checkout needs no manual preparation: the backend entrypoint copies
`.env.example` to `.env` when absent and runs `composer install` when `vendor/`
is absent, then the service command starts.

The seed command runs `Database\Seeders\DatabaseSeeder`, the entry point the
demonstration data seeder registers itself in. The seeder reads its default
Administrator credentials from `SEED_ADMIN_EMAIL` and `SEED_ADMIN_PASSWORD` and
stores the password only as a salted adaptive hash.

The Courier movement simulator command (Requirement 24.10) is documented with
the Tracking module task that adds it.

Two services wait on later tasks before they start cleanly: `frontend` needs the
Web_Client scaffold under `./frontend`, and `reverb` needs the `reverb:start`
command that arrives with `laravel/reverb`. The other four services run today,
so `docker compose --env-file ./backend/.env up -d backend queue-worker postgres
redis` is the useful subset until then.

## Boot-time configuration check

`App\Providers\EnvironmentServiceProvider` runs before every other binding and
asserts the contract in `App\Shared\Support\Configuration\RequiredConfiguration`.
A required variable that is absent or holds an empty value terminates startup
with a message naming the variable and its grouping heading, for example:

```
Startup terminated: 1 required environment variable is absent or empty:
DB_PASSWORD (grouping heading DATABASE). ...
```

(Requirements 24.9, 44.8.)

## Environment variables

Every variable belongs to exactly one of the nine headings APP, DATABASE, REDIS,
CACHE, QUEUE, REVERB, FILESYSTEM, MAIL, API (Requirement 44.3). "Required" marks
the variables the boot check enforces. No populated environment file, private
key, or credential file is committed (Requirement 44.5); production secrets come
from a managed secret store at runtime (Requirement 44.9).

### APP

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `APP_NAME` | Application name in logs and mail | string | `Waselna` | yes | no |
| `APP_ENV` | Environment name | `local` \| `testing` \| `production` | `local` | yes | no |
| `APP_KEY` | Encryption key | `base64:` + 32 random bytes | — | yes | yes |
| `APP_DEBUG` | Verbose error output | `true` \| `false` | `true` locally | yes | no |
| `APP_URL` | Absolute Delivery_API base URL | URL | `http://localhost:8000` | yes | no |
| `APP_LOCALE` | Default locale | ISO 639-1 | `en` | yes | no |
| `APP_FALLBACK_LOCALE` | Fallback locale | ISO 639-1 | `en` | no | no |
| `APP_FAKER_LOCALE` | Faker locale for factories | locale | `en_US` | no | no |
| `APP_MAINTENANCE_DRIVER` | Maintenance-mode driver | `file` \| `cache` | `file` | no | no |
| `BCRYPT_ROUNDS` | Adaptive hash cost | integer | `12` | no | no |
| `LOG_CHANNEL` | Log channel | channel name | `stack` | yes | no |
| `LOG_STACK` | Channels inside the stack | comma-separated | `single` | no | no |
| `LOG_DEPRECATIONS_CHANNEL` | Deprecation channel | channel name \| `null` | `null` | no | no |
| `LOG_LEVEL` | Minimum severity | PSR-3 level | `debug` | yes | no |
| `FRONTEND_PORT` | Host port of the Vite server | port | `5173` | no | no |
| `BACKEND_PORT` | Host port of the Delivery_API | port | `8000` | no | no |
| `SEED_ADMIN_EMAIL` | Demonstration Administrator email | email | `admin@waselna.local` | no | no |
| `SEED_ADMIN_PASSWORD` | Demonstration Administrator password, local only | string | `local-seed-password` | no | yes |

### DATABASE

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `DB_CONNECTION` | Connection driver | `pgsql` | `pgsql` | yes | no |
| `DB_HOST` | PostgreSQL host | hostname | `postgres` | yes | no |
| `DB_PORT` | PostgreSQL port | port | `5432` | yes | no |
| `DB_DATABASE` | Database name | identifier | `waselna` | yes | no |
| `DB_USERNAME` | Database role | identifier | `waselna` | yes | no |
| `DB_PASSWORD` | Database password | string | — | yes | yes |

### REDIS

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `REDIS_CLIENT` | Client extension | `phpredis` \| `predis` | `phpredis` | yes | no |
| `REDIS_HOST` | Redis host | hostname | `redis` | yes | no |
| `REDIS_PORT` | Redis port | port | `6379` | yes | no |
| `REDIS_PASSWORD` | Redis password, `null` when unset | string \| `null` | `null` | yes | yes |
| `REDIS_DB` | Default database index | integer | `0` | no | no |

### CACHE

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `CACHE_STORE` | Cache_Store driver | `redis` \| `array` \| `database` | `redis` | yes | no |
| `CACHE_PREFIX` | Key prefix | string | `waselna_cache` | yes | no |
| `THROTTLE_CACHE_STORE` | Store holding rate-limit counters | store name | `redis` | no | no |

### QUEUE

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `QUEUE_CONNECTION` | Queue backend | `redis` \| `sync` | `redis` | yes | no |
| `QUEUE_FAILED_DRIVER` | Dead-letter storage | `database-uuids` | `database-uuids` | yes | no |
| `REDIS_QUEUE` | Default queue name | string | `default` | no | no |

### REVERB

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `BROADCAST_CONNECTION` | Broadcaster | `reverb` \| `null` | `reverb` | yes | no |
| `REVERB_APP_ID` | Realtime_Gateway application id | string | `waselna-local` | yes | no |
| `REVERB_APP_KEY` | Public client key | string | `waselna-local-key` | yes | no |
| `REVERB_APP_SECRET` | Signing secret | string | — | yes | yes |
| `REVERB_HOST` | Realtime_Gateway host | hostname | `reverb` | yes | no |
| `REVERB_PORT` | Realtime_Gateway port | port | `8080` | yes | no |
| `REVERB_SCHEME` | Realtime_Gateway scheme | `http` \| `https` | `http` | yes | no |

### FILESYSTEM

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `FILESYSTEM_DISK` | Default disk | `local` \| `s3` | `local` | yes | no |

### MAIL

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `MAIL_MAILER` | Transport | `log` \| `smtp` \| `array` | `log` | yes | no |
| `MAIL_HOST` | SMTP host | hostname | `127.0.0.1` | no | no |
| `MAIL_PORT` | SMTP port | port | `2525` | no | no |
| `MAIL_USERNAME` | SMTP user | string \| `null` | `null` | no | yes |
| `MAIL_PASSWORD` | SMTP password | string \| `null` | `null` | no | yes |
| `MAIL_FROM_ADDRESS` | Sender address | email | `no-reply@waselna.local` | yes | no |
| `MAIL_FROM_NAME` | Sender name | string | `${APP_NAME}` | yes | no |

### API

| Variable | Purpose | Format | Default | Required | Secret |
| --- | --- | --- | --- | --- | --- |
| `WEB_CLIENT_ORIGINS` | Origins granted cross-origin access | comma-separated origins | `http://localhost:5173` | yes | no |
| `SECURITY_HSTS` | `Strict-Transport-Security` value | header value | `max-age=31536000; includeSubDomains` | no | no |
| `SECURITY_CSP` | `Content-Security-Policy` value | header value | `default-src 'none'; frame-ancestors 'none'; base-uri 'none'` | no | no |
| `VITE_API_BASE_URL` | Delivery_API base URL used by the Web_Client | URL | `http://localhost:8000/api/v1` | no | no |
| `VITE_REVERB_HOST` | Realtime_Gateway host used by the Web_Client | hostname | `localhost` | no | no |
