# Local Development Guide

This document explains how to run the Waselna Food Delivery Platform on your local machine using Docker.

## Prerequisites

- Docker Desktop (with Docker Compose v2)
- Git

## Quick Start

1. **Clone the repository** (if not already done)
   ```bash
   git clone <repository-url>
   cd Waselna
   ```

2. **Start the development environment**
   ```bash
   docker compose --env-file ./backend/.env up -d
   ```

3. **Verify all services are running**
   ```bash
   docker compose ps
   ```
   
   You should see 6 services:
   - `frontend` (Vite dev server)
   - `backend` (Laravel API)
   - `queue-worker` (Queue processing)
   - `reverb` (Realtime WebSocket server)
   - `postgres` (Database with PostGIS)
   - `redis` (Cache and queue)

## Access Points

- **Frontend**: http://localhost:5173
- **Backend API**: http://localhost:8000
- **Database (PostgreSQL)**: localhost:5432
- **Redis**: localhost:6379
- **Reverb (WebSocket)**: ws://localhost:8080

## Common Commands

### View logs
```bash
# All services
docker compose logs -f

# Backend only
docker compose logs -f backend

# Frontend only
docker compose logs -f frontend
```

### Stop the environment
```bash
docker compose down
```

### Stop and remove volumes
```bash
docker compose down -v
```

### Run artisan commands
```bash
# Migrate database
docker compose exec backend php artisan migrate

# Run seeder
docker compose exec backend php artisan db:seed

# Run tinker
docker compose exec backend php artisan tinker
```

### Restart services
```bash
docker compose restart
```

## Admin Credentials

After running `docker compose up -d` for the first time, the demo seeder creates a default administrator account:

- **Email**: admin@waselna.local
- **Password**: local-seed-password

> **Note**: These are local development credentials only. Never use them in production.

## Development Workflow

### Backend Development

The backend Laravel application is mounted into the container at `/var/www/html`. Changes to PHP files are reflected immediately.

1. Open `backend/app/` in your code editor
2. Make changes to controllers, services, models, etc.
3. Refresh your frontend or API client to see changes

### Frontend Development

The frontend React application is mounted into the container. Vite's HMR (Hot Module Replacement) is enabled by default.

1. Open `frontend/src/` in your code editor
2. Make changes to components, hooks, etc.
3. Changes reflect automatically in the browser

## Troubleshooting

### Service won't start

Check logs for errors:
```bash
docker compose logs <service-name>
```

### Database connection issues

1. Verify PostgreSQL is healthy:
   ```bash
   docker compose ps postgres
   ```

2. Restart the backend to reinitialize the connection:
   ```bash
   docker compose restart backend
   ```

### Clear cache
```bash
docker compose exec backend php artisan cache:clear
docker compose exec backend php artisan config:clear
docker compose exec backend php artisan route:clear
docker compose exec backend php artisan view:clear
```

### Rebuild containers
```bash
docker compose down
docker compose build --no-cache
docker compose up -d
```

## Environment Variables

All configuration is in `backend/.env`. Key variables:

| Variable | Description | Default |
|----------|-------------|---------|
| `APP_URL` | Backend URL | http://localhost:8000 |
| `DB_HOST` | PostgreSQL host | postgres |
| `DB_DATABASE` | Database name | waselna |
| `DB_USERNAME` | Database user | waselna |
| `DB_PASSWORD` | Database password | (see .env file) |
| `REDIS_HOST` | Redis host | redis |
| `VITE_API_BASE_URL` | Frontend API URL | http://localhost:8000/api/v1 |

## Docker Volumes

Data persists in Docker named volumes:
- `pgdata` - PostgreSQL data (including PostGIS geometries)
- `redisdata` - Redis persistence

## Service Details

### Backend (Laravel 12)
- PHP 8.4
- PostgreSQL 16 + PostGIS
- Redis 7
- Queue worker for background jobs
- Realtime gateway via Laravel Reverb

### Frontend (React + Vite)
- React 19
- TypeScript
- Vite 5
- Tailwind CSS
- TanStack Query for data fetching

## Next Steps

- Read [Architecture Documentation](./architecture/)
- Check [Task Board](../.kiro/specs/Waselna/tasks.md) for implementation status
- Run tests: `docker compose exec backend ./vendor/bin/pest`

## Support

For issues:
1. Check logs: `docker compose logs`
2. Verify environment: `docker compose --env-file ./backend/.env config`
3. Review documentation in `docs/`