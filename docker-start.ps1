#!/usr/bin/env pwsh
<#
.SYNOPSIS
Waselna Food Delivery Platform - Docker Start Script (PowerShell)

.DESCRIPTION
This script starts all Docker containers with proper environment configuration.
It handles service startup, health checks, and provides useful information.

.PARAMETER Action
The action to perform: start, logs, rebuild, stop, restart, status, or test

.EXAMPLE
PS> .\docker-start.ps1 -Action start
Starts all services in background

.EXAMPLE
PS> .\docker-start.ps1 -Action logs
Starts services and streams logs
#>

param(
    [ValidateSet("start", "logs", "rebuild", "stop", "restart", "status", "test", "bash")]
    [string]$Action = "start"
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

# Get script directory
$ScriptDir = Split-Path -Parent -Path $MyInvocation.MyCommand.Definition
Set-Location $ScriptDir

Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "Waselna Platform - Docker Startup" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "[*] Action: $Action" -ForegroundColor Yellow
Write-Host "[*] Working directory: $(Get-Location)" -ForegroundColor Yellow
Write-Host "[*] Env file: $(Join-Path $PSScriptRoot 'backend\.env')" -ForegroundColor Yellow
Write-Host ""

# Check if Docker is running
Write-Host "[*] Checking Docker status..." -ForegroundColor Yellow
try {
    docker ps | Out-Null
    Write-Host "[OK] Docker is running" -ForegroundColor Green
}
catch {
    Write-Host "[ERROR] Docker is not running!" -ForegroundColor Red
    Write-Host "[*] Please start Docker Desktop and try again" -ForegroundColor Yellow
    exit 1
}
Write-Host ""

# Verify environment file
$EnvFile = Join-Path $PSScriptRoot "backend\.env"
$EnvExample = Join-Path $PSScriptRoot "backend\.env.example"

if (-not (Test-Path $EnvFile)) {
    Write-Host "[WARNING] $EnvFile not found!" -ForegroundColor Yellow
    if (Test-Path $EnvExample) {
        Write-Host "[*] Creating .env from .env.example..." -ForegroundColor Yellow
        Copy-Item $EnvExample $EnvFile
        Write-Host "[OK] Created $EnvFile" -ForegroundColor Green
    }
    else {
        Write-Host "[ERROR] Neither .env nor .env.example found!" -ForegroundColor Red
        exit 1
    }
}
Write-Host ""

# Helper function to wait for service
function Wait-ForService {
    param(
        [string]$Name,
        [string]$Url,
        [int]$MaxRetries = 30,
        [int]$DelaySeconds = 2
    )
    
    $retries = 0
    while ($retries -lt $MaxRetries) {
        try {
            $response = Invoke-WebRequest -Uri $Url -UseBasicParsing -TimeoutSec 2 -ErrorAction Stop
            Write-Host "[OK] $Name is ready!" -ForegroundColor Green
            return $true
        }
        catch {
            $retries++
            Write-Host "[*] Checking $Name... (attempt $retries of $MaxRetries)" -ForegroundColor Yellow
            Start-Sleep -Seconds $DelaySeconds
        }
    }
    Write-Host "[WARNING] Timeout waiting for $Name. Check logs with: docker compose logs" -ForegroundColor Yellow
    return $false
}

# Execute action
switch ($Action) {
    "logs" {
        Write-Host "[*] Starting services and showing logs..." -ForegroundColor Yellow
        Write-Host ""
        docker compose --env-file ./backend/.env up -d
        Start-Sleep -Seconds 2
        docker compose logs -f
    }
    
    "rebuild" {
        Write-Host "[*] Rebuilding images and starting services..." -ForegroundColor Yellow
        Write-Host ""
        docker compose --env-file ./backend/.env down
        docker compose --env-file ./backend/.env build --no-cache
        docker compose --env-file ./backend/.env up -d
    }
    
    "start" {
        Write-Host "[*] Starting services in background..." -ForegroundColor Yellow
        docker compose --env-file ./backend/.env up -d
    }
    
    "stop" {
        Write-Host "[*] Stopping services..." -ForegroundColor Yellow
        docker compose --env-file ./backend/.env down
    }
    
    "restart" {
        Write-Host "[*] Restarting services..." -ForegroundColor Yellow
        docker compose --env-file ./backend/.env restart
    }
    
    "status" {
        docker compose --env-file ./backend/.env ps
        exit 0
    }
    
    "test" {
        Write-Host "[*] Running tests..." -ForegroundColor Yellow
        docker compose --env-file ./backend/.env exec backend php artisan test
        exit $LASTEXITCODE
    }
    
    "bash" {
        Write-Host "[*] Opening backend container shell..." -ForegroundColor Yellow
        docker compose --env-file ./backend/.env exec backend bash
        exit 0
    }
}

Write-Host ""
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host ""

# Wait for services to be ready
Write-Host "[*] Waiting for services to be ready..." -ForegroundColor Yellow
Write-Host ""

Wait-ForService -Name "Backend" -Url "http://localhost:8000/api/v1/health"

Write-Host ""
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "Services Status:" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan
docker compose --env-file ./backend/.env ps
Write-Host ""

Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "Access the application:" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "  Frontend:    http://localhost:5173" -ForegroundColor Green
Write-Host "  Backend:     http://localhost:8000/api/v1" -ForegroundColor Green
Write-Host "  Health:      http://localhost:8000/api/v1/health" -ForegroundColor Green
Write-Host "  WebSocket:   ws://localhost:8080" -ForegroundColor Green
Write-Host "  Database:    localhost:5432 (user: waselna)" -ForegroundColor Green
Write-Host "  Redis:       localhost:6379" -ForegroundColor Green
Write-Host ""

Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "Demo Credentials:" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "  Email:    admin@waselna.local" -ForegroundColor Yellow
Write-Host "  Password: demo_password_123" -ForegroundColor Yellow
Write-Host ""

Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "Useful Commands:" -ForegroundColor Cyan
Write-Host "============================================================================" -ForegroundColor Cyan
Write-Host "  .\docker-start.ps1 -Action logs          # Show live logs" -ForegroundColor Cyan
Write-Host "  .\docker-start.ps1 -Action stop          # Stop services" -ForegroundColor Cyan
Write-Host "  .\docker-start.ps1 -Action restart       # Restart services" -ForegroundColor Cyan
Write-Host "  .\docker-start.ps1 -Action bash          # Backend container shell" -ForegroundColor Cyan
Write-Host "  docker compose exec backend php artisan tinker" -ForegroundColor Cyan
Write-Host "  docker compose logs -f                   # Stream all logs" -ForegroundColor Cyan
Write-Host ""
Write-Host "============================================================================" -ForegroundColor Cyan
