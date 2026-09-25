<#
.SYNOPSIS
    QC Local Auto Update - SMART CASSAVAS
    Cap nhat phien ban QC Local an toan tu GitHub chi voi 1 cau lenh.

.DESCRIPTION
    Script thuc hien quy trinh Safe Deployment:
    1. Kiem tra moi truong (Git, Docker, Docker Compose).
    2. Khoa tien trinh chong xung dot (Concurrency Lock).
    3. Kiem tra thay doi cuc bo (Local changes detection).
    4. Fetch commit moi tu remote branch thuc te.
    5. Chuan bi phien ban moi trong thu muc biet lap (.qc-candidate qua git worktree).
    6. Kiem tra cu phap (PHP lint) va build tai nguyen.
    7. Khoi chay candidate container tren cong 8001 va kiem tra suc khoe (/health).
    8. Neu candidate PASS: Chuyen QC sang phien ban moi, chay migrate va restart.
    9. Neu candidate FAIL hoac activate FAIL: Bao toan/Rollback ve phien ban cu.

.PARAMETER Branch
    Ten nhanh can cap nhat (mac dinh lay theo nhanh hien tai cua repository).

.PARAMETER Force
    Ep buoc cap nhat/kiem tra lai ke ca khi commit da trung khop.

.EXAMPLE
    .\qc-update.ps1
    .\qc-update.ps1 -Branch main
    .\qc-update.ps1 -Force
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]$Branch = "",

    [Parameter()]
    [switch]$Force = $false
)

$ErrorActionPreference = "Continue"
$projectRoot = $PSScriptRoot
$lockFile = Join-Path $projectRoot ".qc-update.lock"
$candidateDir = Join-Path $projectRoot ".qc-candidate"
$lastCommitFile = Join-Path $projectRoot ".last_qc_commit"
$candidateContainerName = "smart_cassavas_app_candidate"
$activeContainerName = "smart_cassavas_app"

function Write-QCLog {
    param([string]$Message, [string]$Color = "White")
    Write-Host "[QC] $Message" -ForegroundColor $Color
}

function Test-HealthEndpoint {
    param(
        [string]$Url,
        [int]$MaxRetries = 25,
        [int]$IntervalSec = 3,
        [string]$StageName = "Health check"
    )

    for ($i = 1; $i -le $MaxRetries; $i++) {
        Write-Host -NoNewline "[QC] $StageName attempt ${i}/${MaxRetries}: $Url ... " -ForegroundColor Gray
        try {
            $response = Invoke-RestMethod -Uri $Url -TimeoutSec 15 -ErrorAction Stop
            if ($response -and ($response.status -eq "healthy" -or $response.status -eq "ok" -or $response.system -eq "healthy")) {
                Write-Host "PASSED (HTTP 200 - Healthy)" -ForegroundColor Green
                if ($response.version) {
                    Write-QCLog "Reported Version: $($response.version)" "Cyan"
                }
                return $true
            } else {
                Write-Host "DEGRADED/NOT READY" -ForegroundColor Yellow
            }
        } catch {
            Write-Host "WAITING" -ForegroundColor Yellow
        }
        Start-Sleep -Seconds $IntervalSec
    }

    Write-Host ""
    Write-QCLog "Health check TIMEOUT after $MaxRetries attempts on $Url" "Red"
    return $false
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "        QC LOCAL AUTO UPDATE" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan

# ------------------------------------------------------------------------------
# 1. KIEM TRA CONCURRENCY (LOCK FILE)
# ------------------------------------------------------------------------------
if (Test-Path $lockFile) {
    $existingPid = (Get-Content $lockFile -ErrorAction SilentlyContinue | Out-String).Trim()
    if ($existingPid -and (Get-Process -Id $existingPid -ErrorAction SilentlyContinue)) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "QC UPDATE ALREADY RUNNING (PID $existingPid)" "Red"
        Write-QCLog "Another QC update process is currently running. Exiting." "Yellow"
        Write-Host "========================================" -ForegroundColor Red
        exit 1
    } else {
        Remove-Item $lockFile -Force -ErrorAction SilentlyContinue
    }
}
$PID | Out-File -FilePath $lockFile -Encoding ascii

try {
    # --------------------------------------------------------------------------
    # 2. KIEM TRA MOI TRUONG HE THONG
    # --------------------------------------------------------------------------
    Write-QCLog "Checking environment..." "White"

    # Git
    try {
        $gitVer = (git --version 2>&1 | Out-String).Trim()
        Write-QCLog "Git: OK ($gitVer)" "Green"
    } catch {
        Write-QCLog "Git: NOT FOUND. Please install Git." "Red"
        exit 1
    }

    # Docker
    try {
        $dockerVer = (docker --version 2>&1 | Out-String).Trim()
        Write-QCLog "Docker: OK ($dockerVer)" "Green"
    } catch {
        Write-QCLog "Docker: NOT FOUND. Please install Docker." "Red"
        exit 1
    }

    # Docker Compose
    try {
        $composeVer = (docker compose version 2>&1 | Out-String).Trim()
        Write-QCLog "Docker Compose: OK ($composeVer)" "Green"
    } catch {
        Write-QCLog "Docker Compose: NOT FOUND." "Red"
        exit 1
    }

    # Docker Daemon Running check
    $dockerInfo = docker info 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-QCLog "Docker daemon is NOT running! Please start Docker Desktop." "Red"
        exit 1
    }

    # Dam bao database container dang hoat dong
    $dbContainer = docker ps --filter "name=smart_cassavas_db" --format "{{.Names}}"
    if (-not $dbContainer) {
        Write-QCLog "Starting database and initial containers..." "Yellow"
        docker compose up -d db app
        Start-Sleep -Seconds 5
    }

    # --------------------------------------------------------------------------
    # 3. KIEM TRA GIT WORKING TREE (LOCAL CHANGES DETECTION)
    # --------------------------------------------------------------------------
    $isWorkTree = (git rev-parse --is-inside-work-tree 2>&1 | Out-String).Trim()
    if ($isWorkTree -ne "true") {
        Write-QCLog "Current directory is not a valid Git repository." "Red"
        exit 1
    }

    $dirtyTracked = git status --porcelain | Where-Object { $_ -notmatch '^\?\?' }
    if ($dirtyTracked) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "QC UPDATE STOPPED" "Red"
        Write-QCLog "LOCAL CHANGES DETECTED" "Red"
        Write-Host "Uncommitted local changes detected in working tree:" -ForegroundColor Yellow
        $dirtyTracked | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        Write-Host "Please commit or stash your changes before updating." -ForegroundColor Yellow
        Write-Host "========================================" -ForegroundColor Red
        exit 1
    }

    # --------------------------------------------------------------------------
    # 4. XAC DINH BRANCH THUC TE & FETCH CODE
    # --------------------------------------------------------------------------
    if ([string]::IsNullOrWhiteSpace($Branch)) {
        $detectedBranch = (git branch --show-current 2>&1 | Out-String).Trim()
        if ($detectedBranch) {
            $targetBranch = $detectedBranch
        } else {
            $targetBranch = "main"
        }
    } else {
        $targetBranch = $Branch
    }

    Write-QCLog "Fetching updates for branch '$targetBranch' from origin..." "White"
    $fetchOutput = git fetch origin $targetBranch 2>&1
    $fetchExit = $LASTEXITCODE
    if ($fetchExit -ne 0) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "QC UPDATE FAILED" "Red"
        Write-QCLog "Cannot fetch updates for branch '$targetBranch' from origin (exit code $fetchExit):" "Red"
        $fetchOutput | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
        Write-Host "========================================" -ForegroundColor Red
        exit 1
    }

    $CURRENT_COMMIT = (git rev-parse HEAD 2>&1 | Out-String).Trim()
    $TARGET_COMMIT = (git rev-parse FETCH_HEAD 2>&1 | Out-String).Trim()
    if (-not $TARGET_COMMIT -or $LASTEXITCODE -ne 0) {
        $TARGET_COMMIT = (git rev-parse "origin/$targetBranch" 2>&1 | Out-String).Trim()
    }
    $SHORT_CURRENT = $CURRENT_COMMIT.Substring(0, 7)
    $SHORT_TARGET = $TARGET_COMMIT.Substring(0, 7)

    Write-QCLog "Branch: $targetBranch" "Cyan"
    Write-QCLog "Current Commit: $SHORT_CURRENT" "Cyan"
    Write-QCLog "Target Commit:  $SHORT_TARGET" "Cyan"

    # Kiem tra neu da o commit moi nhat
    if (($CURRENT_COMMIT -eq $TARGET_COMMIT) -and (-not $Force)) {
        Write-QCLog "Already up to date. No new changes to deploy." "Green"
        Write-Host "========================================" -ForegroundColor Green
        Write-Host "QC UPDATE SUCCESS" -ForegroundColor Green
        Write-Host "Commit: $SHORT_CURRENT" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
        exit 0
    }

    # --------------------------------------------------------------------------
    # 5. CHUAN BI PHIEN BAN MOI TRONG THU MUC CO LAP (.qc-candidate)
    # --------------------------------------------------------------------------
    Write-QCLog "Preparing isolated candidate for commit $SHORT_TARGET..." "White"

    if (Test-Path $candidateDir) {
        git worktree remove --force .qc-candidate 2>$null
        if (Test-Path $candidateDir) {
            Remove-Item -Recurse -Force $candidateDir -ErrorAction SilentlyContinue
        }
    }

    git worktree add --detach .qc-candidate $TARGET_COMMIT 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $candidateDir)) {
        Write-QCLog "Failed to create git worktree for candidate commit $SHORT_TARGET." "Red"
        exit 1
    }

    # Sao chep .env sang candidate
    if (Test-Path (Join-Path $projectRoot ".env")) {
        Copy-Item -Path (Join-Path $projectRoot ".env") -Destination (Join-Path $candidateDir ".env") -Force
    }

    # Dam bao cau truc thu muc storage & cache trong candidate
    $storageDirs = @(
        "storage/logs",
        "storage/framework/views",
        "storage/framework/sessions",
        "storage/framework/cache",
        "bootstrap/cache"
    )
    foreach ($dir in $storageDirs) {
        $fullSub = Join-Path $candidateDir $dir
        if (-not (Test-Path $fullSub)) {
            New-Item -ItemType Directory -Path $fullSub -Force | Out-Null
        }
    }

    # --------------------------------------------------------------------------
    # 6. KIEM TRA CU PHAP & XAY DUNG TAI NGUYEN TREN CANDIDATE
    # --------------------------------------------------------------------------
    Write-QCLog "Validating candidate syntax & code integrity..." "White"

    # Kiem tra cu phap PHP (PHP Lint) tren cac file thay doi
    $changedPhpFiles = git diff --name-only $CURRENT_COMMIT $TARGET_COMMIT -- '*.php'
    $syntaxErrors = @()
    foreach ($phpFile in $changedPhpFiles) {
        $candidateFilePath = Join-Path $candidateDir $phpFile
        if (Test-Path $candidateFilePath) {
            $lintRes = docker exec $activeContainerName php -l "/var/www/html/.qc-candidate/$phpFile" 2>&1 | Out-String
            if ($lintRes -notmatch "No syntax errors detected") {
                $syntaxErrors += "$phpFile : $lintRes"
            }
        }
    }

    if ($syntaxErrors.Count -gt 0) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "BUILD/VALIDATION FAILED: PHP syntax errors detected!" "Red"
        $syntaxErrors | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
        Write-QCLog "Old version ($SHORT_CURRENT) remains active and untouched." "Green"
        Write-Host "========================================" -ForegroundColor Red
        git worktree remove --force .qc-candidate 2>$null
        exit 1
    }
    Write-QCLog "PHP Syntax Check: PASSED" "Green"

    # Kiem tra thay doi composer.json
    $vendorMount = ""
    $composerDiff = git diff --name-only $CURRENT_COMMIT $TARGET_COMMIT -- 'composer.json' 'composer.lock'
    if ($composerDiff) {
        Write-QCLog "Composer dependencies changed. Syncing vendor in candidate..." "Yellow"
        if (Test-Path (Join-Path $projectRoot "vendor")) {
            Copy-Item -Path (Join-Path $projectRoot "vendor") -Destination (Join-Path $candidateDir "vendor") -Recurse -Force
        }
        docker exec --workdir="/var/www/html/.qc-candidate" $activeContainerName composer install --no-interaction --prefer-dist --optimize-autoloader 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-QCLog "Composer install failed on candidate version!" "Red"
            git worktree remove --force .qc-candidate 2>$null
            exit 1
        }
        $vendorMount = "${candidateDir}/vendor"
    } else {
        $vendorMount = "${projectRoot}/vendor"
    }

    # Kiem tra thay doi frontend Vite
    $buildMount = ""
    $frontendDiff = git diff --name-only $CURRENT_COMMIT $TARGET_COMMIT -- 'package.json' 'resources/' 'vite.config.js'
    if ($frontendDiff) {
        Write-QCLog "Frontend files changed. Compiling Vite bundle..." "Yellow"
        docker exec --workdir="/var/www/html/.qc-candidate" $activeContainerName npm run build 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-QCLog "Vite build failed on candidate version!" "Red"
            git worktree remove --force .qc-candidate 2>$null
            exit 1
        }
        $buildMount = "${candidateDir}/public/build"
    } else {
        $buildMount = "${projectRoot}/public/build"
    }
    Write-QCLog "Build assets: PASSED" "Green"

    # --------------------------------------------------------------------------
    # 7. KHOI CHAY CANDIDATE CONTAINER & HEALTH CHECK DOC LAP (PORT 8001)
    # --------------------------------------------------------------------------
    Write-QCLog "Starting candidate container on port 8001 for isolated verification..." "White"

    $network = (docker inspect --format='{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' $activeContainerName 2>$null | Out-String).Trim()
    if (-not $network) {
        $network = "quanly_toanha-dancu_cassavas_network"
    }

    docker rm -f $candidateContainerName 2>$null | Out-Null

    # Trich xuat volume node_modules tu active container de tranh npm install lai
    $mountLines = docker inspect --format='{{range .Mounts}}{{println .Destination .Name}}{{end}}' $activeContainerName 2>$null
    $nodeVol = ""
    foreach ($line in $mountLines) {
        if ($line -match '^/var/www/html/node_modules\s+(\S+)') {
            $nodeVol = $matches[1]
            break
        }
    }

    $candidateVolumeArgs = @("-v", "${candidateDir}:/var/www/html")
    if ($nodeVol) {
        $candidateVolumeArgs += @("-v", "${nodeVol}:/var/www/html/node_modules")
    }
    if ($vendorMount -and (Test-Path $vendorMount)) {
        $candidateVolumeArgs += @("-v", "${vendorMount}:/var/www/html/vendor")
    }
    if ($buildMount -and (Test-Path $buildMount)) {
        $candidateVolumeArgs += @("-v", "${buildMount}:/var/www/html/public/build")
    }

    # Khoi chay candidate container voi RUN_MIGRATIONS=false de khong lam thay doi CSDL truoc khi pass
    docker run -d --name $candidateContainerName `
        --network $network `
        -p 8001:8000 `
        $candidateVolumeArgs `
        -e APP_ENV=local `
        -e APP_DEBUG=true `
        -e RUN_MIGRATIONS=false `
        -e COMMIT_SHA=$SHORT_TARGET `
        -e APP_VERSION=$SHORT_TARGET `
        quanly_toanha-dancu-app:latest 2>&1 | Out-Null

    Write-QCLog "Running candidate health check on http://localhost:8001/health..." "White"
    $candidateHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8001/health" -MaxRetries 20 -IntervalSec 3 -StageName "Candidate"

    # Don dep candidate container sau khi kiem tra
    docker rm -f $candidateContainerName 2>$null | Out-Null

    if (-not $candidateHealthy) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "QC UPDATE FAILED" "Red"
        Write-QCLog "Candidate version $SHORT_TARGET failed health check!" "Red"
        Write-QCLog "Current Version: $SHORT_CURRENT (STILL ACTIVE & UNTOUCHED)" "Green"
        Write-QCLog "Target Version:  $SHORT_TARGET (REJECTED)" "Yellow"
        Write-Host "========================================" -ForegroundColor Red
        git worktree remove --force .qc-candidate 2>$null
        exit 1
    }

    Write-QCLog "Candidate verification: PASSED (Ready to activate)" "Green"

    # --------------------------------------------------------------------------
    # 8. KICH HOAT PHIEN BAN MOI LEN HE THONG CHINH (ACTIVATE VERSION B)
    # --------------------------------------------------------------------------
    Write-QCLog "Activating new version on active environment..." "White"

    # Luu lai commit truoc do phuc vu rollback neu can
    $CURRENT_COMMIT | Out-File -FilePath $lastCommitFile -Encoding ascii

    # Cap nhat ma nguon chinh
    git checkout $targetBranch 2>&1 | Out-Null
    git merge --ff-only $TARGET_COMMIT 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        git reset --hard $TARGET_COMMIT 2>&1 | Out-Null
    }

    # Dong bo vendor va public/build da kiem dinh
    if (Test-Path (Join-Path $candidateDir "vendor")) {
        Copy-Item -Path (Join-Path $candidateDir "vendor/*") -Destination (Join-Path $projectRoot "vendor") -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path (Join-Path $candidateDir "public/build")) {
        Copy-Item -Path (Join-Path $candidateDir "public/build/*") -Destination (Join-Path $projectRoot "public/build") -Recurse -Force -ErrorAction SilentlyContinue
    }

    # Chay migrations co so du lieu an toan
    Write-QCLog "Executing database migrations..." "White"
    docker exec $activeContainerName php artisan migrate --force 2>&1 | Out-Null

    # Khoi dong lai active container
    Write-QCLog "Restarting active container..." "White"
    docker compose restart app 2>&1 | Out-Null

    # --------------------------------------------------------------------------
    # 9. XAC MINH SUC KHOE SAU KHI KICH HOAT (ACTIVE VERIFICATION TREN CONG 8000)
    # --------------------------------------------------------------------------
    Write-QCLog "Verifying active service health on http://localhost:8000/health..." "White"
    $activeHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8000/health" -MaxRetries 20 -IntervalSec 3 -StageName "Active"

    if ($activeHealthy) {
        # Don dep worktree candidate
        git worktree remove --force .qc-candidate 2>$null
        Write-Host "========================================" -ForegroundColor Green
        Write-Host "QC UPDATE SUCCESS" -ForegroundColor Green
        Write-Host "Commit: $SHORT_TARGET" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
        exit 0
    } else {
        # ----------------------------------------------------------------------
        # 10. AUTOMATED ROLLBACK NEU KICH HOAT THAT BAI
        # ----------------------------------------------------------------------
        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "WARNING: Active service did not respond after activation! Initiating Rollback..." "Red"
        
        git checkout $CURRENT_COMMIT 2>&1 | Out-Null
        docker compose restart app 2>&1 | Out-Null

        $rollbackHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8000/health" -MaxRetries 15 -IntervalSec 3 -StageName "Rollback"

        git worktree remove --force .qc-candidate 2>$null

        Write-Host "========================================" -ForegroundColor Red
        Write-QCLog "QC UPDATE FAILED" "Red"
        Write-QCLog "Current Version: $SHORT_CURRENT (Restored via Rollback)" "Yellow"
        Write-QCLog "Target Version:  $SHORT_TARGET (Activation Failed)" "Red"
        if ($rollbackHealthy) {
            Write-QCLog "Rollback Status: SUCCESS (System restored to stable state)" "Green"
        } else {
            Write-QCLog "Rollback Status: FAILED (Please check docker logs $activeContainerName)" "Red"
        }
        Write-Host "========================================" -ForegroundColor Red
        exit 1
    }

} finally {
    # Don dep lock file
    if (Test-Path $lockFile) {
        Remove-Item $lockFile -Force -ErrorAction SilentlyContinue
    }
    # Don dep candidate container neu con sot lai
    docker rm -f $candidateContainerName 2>$null | Out-Null
}
