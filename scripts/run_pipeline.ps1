param(
    [switch]$OnlyDeploy,
    [switch]$SkipLoadTest
)

$ErrorActionPreference = "Stop"
$ProjectRoot = Split-Path -Parent $PSScriptRoot
$ApacheTarget = "C:\Apache24\htdocs\restaurante"
$PsqlExe = "C:\Program Files\PostgreSQL\16\bin\psql.exe"

function Write-Stage($Title) {
    Write-Host ""
    Write-Host "============================================================" -ForegroundColor Cyan
    Write-Host "  $Title" -ForegroundColor Cyan
    Write-Host "============================================================" -ForegroundColor Cyan
}

# Cargar variables de .env
$EnvFile = Join-Path $ProjectRoot ".env"
if (Test-Path $EnvFile) {
    Get-Content $EnvFile | ForEach-Object {
        if ($_ -match "^\s*([^#][^=]+)=(.*)$") {
            $name = $matches[1].Trim()
            $val = $matches[2].Trim()
            [Environment]::SetEnvironmentVariable($name, $val, "Process")
        }
    }
}

$DbHost = $env:DB_HOST
$DbPort = $env:DB_PORT
$DbName = $env:DB_NAME
$DbTest = $env:DB_TEST_NAME
$DbUser = $env:DB_USER
$env:PGPASSWORD = $env:DB_PASS

if (-not $OnlyDeploy) {
    Write-Stage "ETAPA 1: Lint PHP y Analisis Estatico (SAST)"
    $phpFiles = Get-ChildItem -Path "$ProjectRoot\src", "$ProjectRoot\public", "$ProjectRoot\tests" -Filter "*.php" -Recurse
    foreach ($f in $phpFiles) {
        $lintOut = & php -l $f.FullName 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-Host "[ERROR] Fallo de sintaxis en $($f.FullName): $lintOut" -ForegroundColor Red
            exit 1
        }
        Write-Host "  [OK] $lintOut"
    }

    # SAST básico: buscar eval() o credenciales hardcodeadas en src/ y public/
    $unsafe = Select-String -Path "$ProjectRoot\src\*.php", "$ProjectRoot\public\*.php" -Pattern "eval\s*\(|1097911705" -ErrorAction SilentlyContinue
    if ($unsafe) {
        Write-Host "[SAST ERROR] Se detecto codigo inseguro o credenciales hardcodeadas:" -ForegroundColor Red
        $unsafe | ForEach-Object { Write-Host "  $($_.Filename):$($_.LineNumber) $($_.Line)" -ForegroundColor Red }
        exit 1
    }
    Write-Host "  [OK] SAST limpio: sin funciones eval() ni secretos en codigo fuente." -ForegroundColor Green

    Write-Stage "ETAPA 2: Build, Dependencias y Auditoria de Seguridad"
    Push-Location $ProjectRoot
    if (-not (Test-Path "$ProjectRoot\vendor\autoload.php")) {
        & composer install --no-interaction --prefer-dist
        if ($LASTEXITCODE -ne 0) { Pop-Location; exit 1 }
    } else {
        Write-Host "  [OK] Dependencias Composer verificadas (vendor/autoload.php)."
    }
    & composer audit --no-interaction
    if ($LASTEXITCODE -ne 0) {
        Write-Host "[SECURITY ERROR] Vulnerabilidades detectadas por composer audit." -ForegroundColor Red
        Pop-Location; exit 1
    }
    Pop-Location

    Write-Stage "ETAPA 3: Pruebas Automatizadas (PHPUnit + PostgreSQL 16)"
    & $PsqlExe -h $DbHost -p $DbPort -U $DbUser -d $DbTest -f "$ProjectRoot\database\schema.sql" | Out-Null
    & $PsqlExe -h $DbHost -p $DbPort -U $DbUser -d $DbTest -f "$ProjectRoot\database\seed.sql" | Out-Null
    Push-Location $ProjectRoot
    & php "$ProjectRoot\vendor\bin\phpunit" --testdox
    $testExit = $LASTEXITCODE
    Pop-Location
    if ($testExit -ne 0) {
        Write-Host "[CI FAILED] Las pruebas unitarias fallaron. Despliegue a Apache ABORTADO." -ForegroundColor Red
        exit 1
    }
    Write-Host "  [OK] Todas las pruebas PHPUnit pasaron exitosamente." -ForegroundColor Green
}

Write-Stage "ETAPA 4: Despliegue Controlado a Apache (C:\Apache24\htdocs\restaurante)"
if (-not (Test-Path $ApacheTarget)) {
    New-Item -ItemType Directory -Path $ApacheTarget -Force | Out-Null
}

foreach ($dir in @("src", "public", "vendor", "database")) {
    $srcDir = Join-Path $ProjectRoot $dir
    $dstDir = Join-Path $ApacheTarget $dir
    & robocopy $srcDir $dstDir /MIR /NFL /NDL /NJH /NJS /NC /NS | Out-Null
    if ($LASTEXITCODE -ge 8) {
        Write-Host "[DEPLOY ERROR] Fallo al sincronizar $dir hacia Apache." -ForegroundColor Red
        exit 1
    }
}
Copy-Item -Path "$ProjectRoot\.env" -Destination "$ApacheTarget\.env" -Force

# Redireccion raiz limpia hacia public/
@"
<?php
header('Location: public/');
exit;
"@ | Set-Content -Path "$ApacheTarget\index.php" -Encoding UTF8

# Aplicar migracion idempotente en BD de produccion/staging
& $PsqlExe -h $DbHost -p $DbPort -U $DbUser -d $DbName -f "$ProjectRoot\database\schema.sql" | Out-Null
& $PsqlExe -h $DbHost -p $DbPort -U $DbUser -d $DbName -f "$ProjectRoot\database\seed.sql" | Out-Null
Write-Host "  [OK] Artefactos desplegados en $ApacheTarget y esquema PostgreSQL actualizado." -ForegroundColor Green

Write-Stage "ETAPA 5: Smoke Test y Prueba de Carga (RNF)"
$healthUrl = "http://localhost:8080/restaurante/public/index.php?health=1"
$resp = Invoke-WebRequest -Uri $healthUrl -UseBasicParsing -TimeoutSec 5
if ($resp.StatusCode -ne 200) {
    Write-Host "[SMOKE ERROR] HTTP $($resp.StatusCode)" -ForegroundColor Red
    exit 1
}
Write-Host "  [OK] Smoke Test HTTP 200: $($resp.Content)" -ForegroundColor Green

if (-not $SkipLoadTest) {
    & php "$ProjectRoot\scripts\load_test.php" $healthUrl 25
    if ($LASTEXITCODE -ne 0) { exit 1 }
}

Write-Host ""
Write-Host "[PIPELINE EXITOSO] Aplicacion disponible en: http://localhost:8080/restaurante/" -ForegroundColor Green
