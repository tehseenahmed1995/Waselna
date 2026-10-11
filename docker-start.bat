@echo off
REM ============================================================================
REM Waselna Food Delivery Platform - Docker Start Script (Windows)
REM ============================================================================
REM This script starts all Docker containers with proper environment configuration
REM
REM Usage:
REM   docker-start.bat              - Start all services
REM   docker-start.bat logs         - Start and show logs
REM   docker-start.bat rebuild      - Rebuild images and start
REM ============================================================================

setlocal enabledelayedexpansion

REM Get the script directory
set SCRIPT_DIR=%~dp0
cd /d "%SCRIPT_DIR%"

echo ============================================================================
echo Waselna Platform - Docker Startup
echo ============================================================================
echo.

REM Determine action
set ACTION=%1
if "%ACTION%"=="" set ACTION=start

echo [*] Action: %ACTION%
echo [*] Working directory: %cd%
echo [*] Env file: %cd%\backend\.env
echo.

REM Check if Docker is running
echo [*] Checking Docker status...
docker ps >nul 2>&1
if errorlevel 1 (
    echo [ERROR] Docker is not running!
    echo [*] Please start Docker Desktop and try again
    pause
    exit /b 1
)
echo [OK] Docker is running
echo.

REM Verify environment file
if not exist "backend\.env" (
    echo [WARNING] backend\.env not found!
    if exist "backend\.env.example" (
        echo [*] Creating backend\.env from backend\.env.example...
        copy "backend\.env.example" "backend\.env" >nul
        echo [OK] Created backend\.env
    ) else (
        echo [ERROR] Neither .env nor .env.example found!
        pause
        exit /b 1
    )
)
echo.

REM Execute the requested action
if "%ACTION%"=="logs" (
    echo [*] Starting services and showing logs...
    echo.
    docker compose --env-file ./backend/.env up -d
    timeout /t 5 /nobreak
    docker compose logs -f
) else if "%ACTION%"=="rebuild" (
    echo [*] Rebuilding images and starting services...
    echo.
    docker compose --env-file ./backend/.env down
    docker compose --env-file ./backend/.env build --no-cache
    docker compose --env-file ./backend/.env up -d
) else if "%ACTION%"=="start" (
    echo [*] Starting services in background...
    docker compose --env-file ./backend/.env up -d
) else if "%ACTION%"=="stop" (
    echo [*] Stopping services...
    docker compose --env-file ./backend/.env down
) else if "%ACTION%"=="restart" (
    echo [*] Restarting services...
    docker compose --env-file ./backend/.env restart
) else if "%ACTION%"=="status" (
    docker compose --env-file ./backend/.env ps
    exit /b 0
) else if "%ACTION%"=="test" (
    echo [*] Running tests...
    docker compose --env-file ./backend/.env exec backend php artisan test
    exit /b !errorlevel!
) else (
    echo [ERROR] Unknown action: %ACTION%
    echo.
    echo Available actions:
    echo   start   - Start services in background (default)
    echo   logs    - Start services and show logs
    echo   rebuild - Rebuild images and start
    echo   stop    - Stop all services
    echo   restart - Restart services
    echo   status  - Show service status
    echo   test    - Run tests
    echo.
    pause
    exit /b 1
)

echo.
echo ============================================================================
echo.

REM Wait for services to be ready
echo [*] Waiting for services to be ready...
echo.

set RETRIES=0
:wait_loop
if %RETRIES% geq 30 (
    echo [WARNING] Timeout waiting for backend. Check logs with: docker compose logs backend
    goto done
)

echo [*] Checking backend health... (attempt %RETRIES% of 30)
curl -s http://localhost:8000/api/v1/health >nul 2>&1
if errorlevel 1 (
    set /a RETRIES+=1
    timeout /t 2 /nobreak >nul
    goto wait_loop
)

echo [OK] Backend is ready!
echo.

:done
echo ============================================================================
echo Services Status:
echo ============================================================================
docker compose --env-file ./backend/.env ps
echo.
echo ============================================================================
echo Access the application:
echo ============================================================================
echo   Frontend:    http://localhost:5173
echo   Backend:     http://localhost:8000/api/v1
echo   Health:      http://localhost:8000/api/v1/health
echo   WebSocket:   ws://localhost:8080
echo   Database:    localhost:5432 (user: waselna, pass: waselna_pass)
echo   Redis:       localhost:6379
echo.
echo ============================================================================
echo Demo Credentials:
echo ============================================================================
echo   Email:    admin@waselna.local
echo   Password: demo_password_123
echo.
echo ============================================================================
echo Useful Commands:
echo ============================================================================
echo   docker-start.bat logs        - Show live logs
echo   docker-start.bat stop        - Stop services
echo   docker-start.bat restart     - Restart services
echo   docker compose exec backend php artisan tinker  - Interactive shell
echo   docker compose logs -f       - Stream all logs
echo.
echo ============================================================================
