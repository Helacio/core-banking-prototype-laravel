# ============================================================================
# FinAegis / PayProof - Demo traffic generator
# ============================================================================
# Generates realistic traffic so the "Core Bancario - KPIs" Grafana dashboard
# shows data: logins, account creation, deposits, transfers (completed and
# failed) and 404/401 error spikes.
#
# Requirements:
#   - The core running at http://localhost:8000 (docker compose up -d)
#   - A demo user (default: demo.user@gcu.global / demo123)
#
# Usage:
#   powershell -ExecutionPolicy Bypass -File scripts/demo-traffic.ps1
#   powershell -ExecutionPolicy Bypass -File scripts/demo-traffic.ps1 -Transfers 15 -ErrorRequests 30
#
# Note: this script is intentionally ASCII-only so Windows PowerShell 5.1
# reads it correctly without a BOM.
# ============================================================================

param(
    [string]$BaseUrl = "http://localhost:8000",
    [string]$Email = "demo.user@gcu.global",
    [string]$Password = "demo123",
    [int]$Transfers = 12,
    [int]$ErrorRequests = 20,
    [int]$BreathMs = 300
)

$ErrorActionPreference = "Stop"
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12

$script:StatusCounts = @{}

function Add-StatusCount {
    param([int]$Code)
    $key = [string]$Code
    if ($script:StatusCounts.ContainsKey($key)) {
        $script:StatusCounts[$key] = $script:StatusCounts[$key] + 1
    } else {
        $script:StatusCounts[$key] = 1
    }
}

function Invoke-Api {
    param(
        [string]$Method,
        [string]$Path,
        [string]$Token = "",
        $Body = $null
    )

    $headers = @{ "Accept" = "application/json" }
    if ($Token -ne "") { $headers["Authorization"] = "Bearer $Token" }

    $params = @{
        Method          = $Method
        Uri             = "$BaseUrl$Path"
        Headers         = $headers
        UseBasicParsing = $true
        TimeoutSec      = 60
    }

    if ($null -ne $Body) {
        $params["ContentType"] = "application/json"
        $params["Body"] = ($Body | ConvertTo-Json -Depth 8)
    }

    try {
        $resp = Invoke-WebRequest @params
        Add-StatusCount ([int]$resp.StatusCode)
        return @{ Status = [int]$resp.StatusCode; Content = [string]$resp.Content }
    } catch {
        $status = 0
        $content = ""
        try {
            $response = $_.Exception.Response
            if ($null -ne $response) {
                $status = [int]$response.StatusCode
                $stream = $response.GetResponseStream()
                $reader = New-Object System.IO.StreamReader($stream)
                $content = $reader.ReadToEnd()
                $reader.Dispose()
            }
        } catch {
            $status = 0
        }

        if ($status -eq 0) { throw }

        Add-StatusCount $status
        return @{ Status = $status; Content = $content }
    }
}

function Show-Summary {
    Write-Host ""
    Write-Host "=== Resumen de trafico ===" -ForegroundColor Cyan
    foreach ($k in ($script:StatusCounts.Keys | Sort-Object { [int]$_ })) {
        Write-Host ("  HTTP {0}: {1}" -f $k, $script:StatusCounts[$k])
    }
}

Write-Host "=== FinAegis demo traffic ===" -ForegroundColor Cyan
Write-Host "BaseUrl: $BaseUrl"
Write-Host "Dashboard: http://localhost:3000/d/core-banking-kpis"

# ----------------------------------------------------------------------------
# 1) Login
# ----------------------------------------------------------------------------
Write-Host ""
Write-Host "[1/5] Login como $Email ..."
$login = Invoke-Api -Method "POST" -Path "/api/auth/login" -Body @{
    email       = $Email
    password    = $Password
    device_name = "demo-traffic"
}

if ($login.Status -ne 200) {
    Write-Host "  Login fallo (HTTP $($login.Status)): $($login.Content)" -ForegroundColor Red
    Write-Host "  Verifica que el usuario demo exista (demo.user@gcu.global / demo123)." -ForegroundColor Yellow
    Show-Summary
    exit 1
}

$token = ($login.Content | ConvertFrom-Json).data.access_token
if (-not $token) {
    Write-Host "  No se pudo extraer el access_token de la respuesta." -ForegroundColor Red
    exit 1
}
Write-Host "  OK - token obtenido"

# ----------------------------------------------------------------------------
# 2) Accounts: ensure at least 2 exist
# ----------------------------------------------------------------------------
Write-Host ""
Write-Host "[2/5] Verificando cuentas ..."

$uuis = @()
$accountsResp = Invoke-Api -Method "GET" -Path "/api/v2/accounts" -Token $token
try {
    $accounts = @((($accountsResp.Content | ConvertFrom-Json).data))
    $uuis = @($accounts | Where-Object { $_.uuid } | Select-Object -ExpandProperty uuid -Unique)
} catch {
    $uuis = @()
}

while ($uuis.Count -lt 2) {
    $n = $uuis.Count + 1
    Write-Host "  Creando cuenta demo $n ..."
    $create = Invoke-Api -Method "POST" -Path "/api/v2/accounts" -Token $token -Body @{
        name = "PayProof Demo $n"
    }

    if ($create.Status -eq 200 -or $create.Status -eq 201) {
        try {
            $newUuid = (($create.Content | ConvertFrom-Json).data).uuid
        } catch {
            $newUuid = $null
        }

        if ($newUuid) {
            $uuis = @($uuis) + $newUuid
        } else {
            # Re-fetch in case the response shape differs
            Start-Sleep -Milliseconds 500
            $refetch = Invoke-Api -Method "GET" -Path "/api/v2/accounts" -Token $token
            try {
                $accounts = @((($refetch.Content | ConvertFrom-Json).data))
                $uuis = @($accounts | Where-Object { $_.uuid } | Select-Object -ExpandProperty uuid -Unique)
            } catch { }
        }
    } else {
        Write-Host "  No se pudo crear cuenta (HTTP $($create.Status))." -ForegroundColor Yellow
        break
    }
}

if ($uuis.Count -lt 2) {
    Write-Host "  Se necesitan al menos 2 cuentas para transferir (hay $($uuis.Count))." -ForegroundColor Yellow
} else {
    Write-Host "  OK - cuentas: $($uuis.Count)"
}

# ----------------------------------------------------------------------------
# 3) Fund the source account
# ----------------------------------------------------------------------------
if ($uuis.Count -ge 2) {
    Write-Host ""
    Write-Host "[3/5] Depositando 100 EUR en la cuenta origen ..."
    $deposit = Invoke-Api -Method "POST" -Path "/api/v2/accounts/$($uuis[0])/deposit" -Token $token -Body @{
        amount      = 100
        asset_code  = "EUR"
        description = "PayProof demo liquidity"
    }
    Write-Host "  Deposit HTTP $($deposit.Status)"
}

# ----------------------------------------------------------------------------
# 4) Transfers (completed + possibly failed)
# ----------------------------------------------------------------------------
$completed = 0
$failedT = 0

if ($uuis.Count -ge 2) {
    Write-Host ""
    Write-Host "[4/5] Generando $Transfers transferencias ..."

    for ($i = 1; $i -le $Transfers; $i++) {
        $amount = [math]::Round((Get-Random -Minimum 50 -Maximum 200) / 100.0, 2)

        $t = Invoke-Api -Method "POST" -Path "/api/v2/transfers" -Token $token -Body @{
            from_account_uuid = $uuis[0]
            to_account_uuid   = $uuis[1]
            amount            = $amount
            asset_code        = "EUR"
            description       = "PayProof demo transfer $i"
        }

        $color = "Yellow"
        if ($t.Status -ge 200 -and $t.Status -lt 300) {
            $completed++
            $color = "Green"
        } else {
            $failedT++
        }

        Write-Host ("  Transfer {0,2}: HTTP {1} ({2} EUR)" -f $i, $t.Status, $amount) -ForegroundColor $color
        Start-Sleep -Milliseconds $BreathMs
    }
}

# ----------------------------------------------------------------------------
# 5) Error traffic: 404 (bad routes) + 401 (missing token)
# ----------------------------------------------------------------------------
Write-Host ""
Write-Host "[5/5] Generando $ErrorRequests requests con error (404/401) ..."

for ($i = 1; $i -le $ErrorRequests; $i++) {
    if (($i % 4) -eq 0) {
        # 401: protected route without token
        Invoke-Api -Method "GET" -Path "/api/v2/accounts" | Out-Null
    } else {
        # 404: non-existent route
        Invoke-Api -Method "GET" -Path "/api/v2/no-existe-$i" -Token $token | Out-Null
    }
    Start-Sleep -Milliseconds 100
}

# ----------------------------------------------------------------------------
# Summary
# ----------------------------------------------------------------------------
Show-Summary
Write-Host ""
Write-Host "Transferencias completadas: $completed | fallidas: $failedT" -ForegroundColor Cyan
Write-Host ""
Write-Host "Abre Grafana:   http://localhost:3000  (dashboard: Core Bancario - KPIs)" -ForegroundColor Cyan
Write-Host "Prometheus:     http://localhost:9090" -ForegroundColor Cyan
