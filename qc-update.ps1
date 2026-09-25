<#
.SYNOPSIS
    QC Local Auto Watch & Safe Deployment - SMART CASSAVAS
    Theo dõi liên tục branch phát triển trên GitHub và tự động triển khai an toàn.

.DESCRIPTION
    Script hoạt động ở chế độ Watcher liên tục (QC AUTO WATCH = ON):
    1. Kiểm tra môi trường (Git, Docker, Docker Compose, Docker daemon).
    2. Đảm bảo tính duy nhất qua cơ chế khóa Concurrency Lock (.qc-update.lock).
    3. Tự động xác định development branch từ repository thực tế.
    4. Thăm dò GitHub định kỳ ($POLL_INTERVAL_SECONDS) để phát hiện commit mới.
    5. Khi có commit mới: thực thi Safe Deployment qua thư mục cô lập (.qc-candidate).
       - Kiểm tra cú pháp PHP (PHP Lint).
       - Xây dựng tài nguyên (Composer, Vite build).
       - Khởi chạy candidate container trên cổng 8001 (RUN_MIGRATIONS=false).
       - Chạy Health Check thực tế (/health).
       - PASS: Chuyển active container (cổng 8000) sang commit mới, migrate và restart.
       - FAIL: Giữ nguyên/Rollback về phiên bản cũ đang chạy.
    6. Dừng an toàn khi người dùng bấm Ctrl + C (QC AUTO WATCH = OFF).

.PARAMETER Branch
    Tên nhánh cần theo dõi (mặc định lấy theo nhánh hiện tại của repository).

.PARAMETER PollIntervalSeconds
    Khoảng thời gian thăm dò GitHub tính bằng giây (mặc định: 5 giây).

.PARAMETER Once
    Chạy cập nhật 1 lần duy nhất thay vì chạy vòng lặp Watcher liên tục.

.EXAMPLE
    .\qc-update.ps1
    .\qc-update.ps1 -Branch main
    .\qc-update.ps1 -PollIntervalSeconds 10
    .\qc-update.ps1 -Once
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [string]$Branch = "",

    [Parameter()]
    [int]$PollIntervalSeconds = 5,

    [Parameter()]
    [switch]$Once = $false
)

$ErrorActionPreference = "Continue"
$projectRoot = $PSScriptRoot
$lockFile = Join-Path $projectRoot ".qc-update.lock"
$candidateDir = Join-Path $projectRoot ".qc-candidate"
$lastCommitFile = Join-Path $projectRoot ".last_qc_commit"
$candidateContainerName = "smart_cassavas_app_candidate"
$activeContainerName = "smart_cassavas_app"

# Biến điều khiển vòng lặp watch
$script:isWatching = $true

function Write-QCWatchLog {
    param([string]$Message, [string]$Color = "White")
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    Write-Host "[$timestamp][QC WATCH] $Message" -ForegroundColor $Color
}

function Test-HealthEndpoint {
    param(
        [string]$Url,
        [int]$MaxRetries = 25,
        [int]$IntervalSec = 2,
        [string]$StageName = "Health check"
    )

    for ($i = 1; $i -le $MaxRetries; $i++) {
        Write-Host -NoNewline "  [QC] $StageName attempt ${i}/${MaxRetries}: $Url ... " -ForegroundColor Gray
        try {
            $response = Invoke-RestMethod -Uri $Url -TimeoutSec 15 -ErrorAction Stop
            if ($response -and ($response.status -eq "healthy" -or $response.status -eq "ok" -or $response.system -eq "healthy")) {
                Write-Host "PASSED (HTTP 200 - Healthy)" -ForegroundColor Green
                if ($response.version) {
                    Write-QCWatchLog "Reported Version: $($response.version)" "Cyan"
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
    Write-QCWatchLog "Health check TIMEOUT after $MaxRetries attempts on $Url" "Red"
    return $false
}

function Test-EnvironmentReady {
    Write-QCWatchLog "Checking environment..." "White"

    # Git
    try {
        $gitVer = (git --version 2>&1 | Out-String).Trim()
        Write-QCWatchLog "Git: OK ($gitVer)" "Green"
    } catch {
        Write-QCWatchLog "Git: NOT FOUND. Please install Git." "Red"
        return $false
    }

    # Docker
    try {
        $dockerVer = (docker --version 2>&1 | Out-String).Trim()
        Write-QCWatchLog "Docker: OK ($dockerVer)" "Green"
    } catch {
        Write-QCWatchLog "Docker: NOT FOUND. Please install Docker." "Red"
        return $false
    }

    # Docker Compose
    try {
        $composeVer = (docker compose version 2>&1 | Out-String).Trim()
        Write-QCWatchLog "Docker Compose: OK ($composeVer)" "Green"
    } catch {
        Write-QCWatchLog "Docker Compose: NOT FOUND." "Red"
        return $false
    }

    # Docker Daemon Check
    $dockerInfo = docker info 2>&1
    if ($LASTEXITCODE -ne 0) {
        Write-QCWatchLog "Docker daemon is NOT running! Please start Docker Desktop." "Red"
        return $false
    }

    # Dam bao active containers dang hoat dong
    $dbContainer = docker ps --filter "name=smart_cassavas_db" --format "{{.Names}}"
    if (-not $dbContainer) {
        Write-QCWatchLog "Starting database and initial containers..." "Yellow"
        docker compose up -d db app 2>&1 | Out-Null
        Start-Sleep -Seconds 3
    }

    return $true
}

function Invoke-SafeDeployment {
    param(
        [string]$CurrentCommit,
        [string]$TargetCommit,
        [string]$TargetBranch
    )

    $shortCurrent = $CurrentCommit.Substring(0, [Math]::Min(7, $CurrentCommit.Length))
    $shortTarget = $TargetCommit.Substring(0, [Math]::Min(7, $TargetCommit.Length))

    Write-QCWatchLog "----------------------------------------" "Cyan"
    Write-QCWatchLog "Preparing candidate for commit: $shortTarget" "Cyan"

    # 1. Chuan bi thu muc worktree candidate co lap
    if (Test-Path $candidateDir) {
        git worktree remove --force .qc-candidate 2>$null | Out-Null
        if (Test-Path $candidateDir) {
            Remove-Item -Recurse -Force $candidateDir -ErrorAction SilentlyContinue
        }
    }

    git worktree add --detach .qc-candidate $TargetCommit 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path $candidateDir)) {
        Write-QCWatchLog "Failed to create git worktree for candidate commit $shortTarget." "Red"
        return $false
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

    # 2. Kiem tra cu phap PHP (PHP Lint)
    Write-QCWatchLog "Validating candidate syntax & code integrity..." "White"
    $changedPhpFiles = git diff --name-only $CurrentCommit $TargetCommit -- '*.php'
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
        Write-QCWatchLog "BUILD/VALIDATION FAILED: PHP syntax errors detected!" "Red"
        $syntaxErrors | ForEach-Object { Write-Host "  $_" -ForegroundColor Red }
        Write-QCWatchLog "Current version remains: $shortCurrent" "Green"
        git worktree remove --force .qc-candidate 2>$null | Out-Null
        return $false
    }
    Write-QCWatchLog "PHP Syntax Check: PASSED" "Green"

    # 3. Kiem tra va dong bo Composer dependencies
    $vendorMount = ""
    $composerDiff = git diff --name-only $CurrentCommit $TargetCommit -- 'composer.json' 'composer.lock'
    if ($composerDiff) {
        Write-QCWatchLog "Composer dependencies changed. Syncing vendor in candidate..." "Yellow"
        if (Test-Path (Join-Path $projectRoot "vendor")) {
            Copy-Item -Path (Join-Path $projectRoot "vendor") -Destination (Join-Path $candidateDir "vendor") -Recurse -Force
        }
        docker exec --workdir="/var/www/html/.qc-candidate" $activeContainerName composer install --no-interaction --prefer-dist --optimize-autoloader 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-QCWatchLog "Composer install failed on candidate version!" "Red"
            git worktree remove --force .qc-candidate 2>$null | Out-Null
            return $false
        }
        $vendorMount = "${candidateDir}/vendor"
    } else {
        $vendorMount = "${projectRoot}/vendor"
    }

    # 4. Kiem tra va bien dich frontend Vite
    $buildMount = ""
    $frontendDiff = git diff --name-only $CurrentCommit $TargetCommit -- 'package.json' 'resources/' 'vite.config.js'
    if ($frontendDiff) {
        Write-QCWatchLog "Frontend files changed. Compiling Vite bundle..." "Yellow"
        docker exec --workdir="/var/www/html/.qc-candidate" $activeContainerName npm run build 2>&1 | Out-Null
        if ($LASTEXITCODE -ne 0) {
            Write-QCWatchLog "Vite build failed on candidate version!" "Red"
            git worktree remove --force .qc-candidate 2>$null | Out-Null
            return $false
        }
        $buildMount = "${candidateDir}/public/build"
    } else {
        $buildMount = "${projectRoot}/public/build"
    }
    Write-QCWatchLog "Build candidate: PASSED" "Green"

    # 5. Khoi chay candidate container tren cong 8001
    Write-QCWatchLog "Starting candidate container on port 8001 for isolated verification..." "White"
    $network = (docker inspect --format='{{range $k, $v := .NetworkSettings.Networks}}{{$k}}{{end}}' $activeContainerName 2>$null | Out-String).Trim()
    if (-not $network) {
        $network = "quanly_toanha-dancu_cassavas_network"
    }

    docker rm -f $candidateContainerName 2>$null | Out-Null

    # Trich xuat volume node_modules tu active container
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

    docker run -d --name $candidateContainerName `
        --network $network `
        -p 8001:8000 `
        $candidateVolumeArgs `
        -e APP_ENV=local `
        -e APP_DEBUG=true `
        -e RUN_MIGRATIONS=false `
        -e COMMIT_SHA=$shortTarget `
        -e APP_VERSION=$shortTarget `
        quanly_toanha-dancu-app:latest 2>&1 | Out-Null

    # 6. Health Check Candidate tren cong 8001
    Write-QCWatchLog "Running candidate health check on http://localhost:8001/health..." "White"
    $candidateHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8001/health" -MaxRetries 20 -IntervalSec 2 -StageName "Candidate"

    # Don dep candidate container
    docker rm -f $candidateContainerName 2>$null | Out-Null

    if (-not $candidateHealthy) {
        Write-QCWatchLog "Candidate version $shortTarget FAILED health check!" "Red"
        Write-QCWatchLog "Current version remains: $shortCurrent" "Green"
        git worktree remove --force .qc-candidate 2>$null | Out-Null
        return $false
    }

    Write-QCWatchLog "Candidate verification: PASSED (Activating new version...)" "Green"

    # 7. Kich hoat phien ban moi len active service
    $CurrentCommit | Out-File -FilePath $lastCommitFile -Encoding ascii

    git checkout $TargetBranch 2>&1 | Out-Null
    git merge --ff-only $TargetCommit 2>&1 | Out-Null
    if ($LASTEXITCODE -ne 0) {
        git reset --hard $TargetCommit 2>&1 | Out-Null
    }

    if (Test-Path (Join-Path $candidateDir "vendor")) {
        Copy-Item -Path (Join-Path $candidateDir "vendor/*") -Destination (Join-Path $projectRoot "vendor") -Recurse -Force -ErrorAction SilentlyContinue
    }
    if (Test-Path (Join-Path $candidateDir "public/build")) {
        Copy-Item -Path (Join-Path $candidateDir "public/build/*") -Destination (Join-Path $projectRoot "public/build") -Recurse -Force -ErrorAction SilentlyContinue
    }

    Write-QCWatchLog "Executing database migrations..." "White"
    docker exec $activeContainerName php artisan migrate --force 2>&1 | Out-Null

    Write-QCWatchLog "Restarting active container..." "White"
    docker compose restart app 2>&1 | Out-Null

    # 8. Xac minh active service tren cong 8000
    Write-QCWatchLog "Verifying active service health on http://localhost:8000/health..." "White"
    $activeHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8000/health" -MaxRetries 20 -IntervalSec 2 -StageName "Active"

    if ($activeHealthy) {
        git worktree remove --force .qc-candidate 2>$null | Out-Null
        Write-QCWatchLog "Deployment SUCCESS (Commit: $shortTarget)" "Green"
        Write-QCWatchLog "----------------------------------------" "Cyan"
        return $true
    } else {
        # 9. Automated Rollback neu active service that bai
        Write-QCWatchLog "Active health check FAILED! Starting Rollback to $shortCurrent..." "Red"
        git checkout $CurrentCommit 2>&1 | Out-Null
        docker compose restart app 2>&1 | Out-Null

        $rollbackHealthy = Test-HealthEndpoint -Url "http://127.0.0.1:8000/health" -MaxRetries 15 -IntervalSec 2 -StageName "Rollback"
        git worktree remove --force .qc-candidate 2>$null | Out-Null

        if ($rollbackHealthy) {
            Write-QCWatchLog "Rollback SUCCESS. Current version remains: $shortCurrent" "Green"
        } else {
            Write-QCWatchLog "Rollback FAILED. Please inspect docker logs $activeContainerName" "Red"
        }
        Write-QCWatchLog "----------------------------------------" "Cyan"
        return $false
    }
}

# ==============================================================================
# ENTRYPOINT CHÍNH
# ==============================================================================

# Dang ky xu ly Ctrl+C
try {
    $cancelHandler = [ConsoleCancelEventHandler] {
        param($sender, $eventArgs)
        $eventArgs.Cancel = $true
        $script:isWatching = $false
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Yellow
        Write-QCWatchLog "Stop signal received (Ctrl+C)" "Yellow"
        Write-QCWatchLog "Stopping watcher..." "Yellow"
        Write-QCWatchLog "AUTO WATCH OFF" "Red"
        Write-Host "========================================" -ForegroundColor Yellow
    }
    [Console]::add_CancelKeyPress($cancelHandler)
} catch {
    # Non-interactive console fallback
}

# 1. Kiem tra concurrency lock
if (Test-Path $lockFile) {
    $existingPid = (Get-Content $lockFile -ErrorAction SilentlyContinue | Out-String).Trim()
    if ($existingPid -and (Get-Process -Id $existingPid -ErrorAction SilentlyContinue)) {
        Write-Host "========================================" -ForegroundColor Red
        Write-QCWatchLog "Watcher is already running (PID $existingPid)" "Red"
        Write-QCWatchLog "A watcher instance is already active. Exiting." "Yellow"
        Write-Host "========================================" -ForegroundColor Red
        exit 1
    } else {
        Remove-Item $lockFile -Force -ErrorAction SilentlyContinue
    }
}
$PID | Out-File -FilePath $lockFile -Encoding ascii

try {
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "        QC LOCAL AUTO WATCH" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan

    # 2. Kiem tra moi truong
    if (-not (Test-EnvironmentReady)) {
        Write-QCWatchLog "Environment check FAILED" "Red"
        exit 1
    }

    # 3. Kiem tra Git repository
    $isWorkTree = (git rev-parse --is-inside-work-tree 2>&1 | Out-String).Trim()
    if ($isWorkTree -ne "true") {
        Write-QCWatchLog "Repository check FAILED: Not a valid git repository." "Red"
        exit 1
    }

    # 4. Xac dinh branch theo doi thuc te
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

    $initialCommit = (git rev-parse HEAD 2>&1 | Out-String).Trim()
    $shortInitial = $initialCommit.Substring(0, [Math]::Min(7, $initialCommit.Length))

    Write-QCWatchLog "Repository: OK" "Green"
    Write-QCWatchLog "Branch: $targetBranch" "Cyan"
    Write-QCWatchLog "Current Commit: $shortInitial" "Cyan"
    Write-QCWatchLog "Polling Interval: ${PollIntervalSeconds}s" "Cyan"
    Write-QCWatchLog "AUTO WATCH = ON" "Green"
    Write-QCWatchLog "Press Ctrl + C to stop watching" "Yellow"
    Write-Host "========================================" -ForegroundColor Cyan

    # --------------------------------------------------------------------------
    # 5. WATCH LOOP LIÊN TỤC
    # --------------------------------------------------------------------------
    while ($script:isWatching) {
        # Kiem tra thay doi local chua commit
        $dirtyTracked = git status --porcelain | Where-Object { $_ -notmatch '^\?\?' }
        if ($dirtyTracked) {
            Write-QCWatchLog "LOCAL CHANGES DETECTED" "Yellow"
            Write-QCWatchLog "AUTO DEPLOY STOPPED (Skipping update until local changes are committed/stashed)" "Yellow"
            Start-Sleep -Seconds $PollIntervalSeconds
            continue
        }

        # Kiem tra Docker Daemon con hoat dong
        $dockerCheck = docker ps --filter "name=smart_cassavas_app" --format "{{.Names}}" 2>&1
        if ($LASTEXITCODE -ne 0) {
            Write-QCWatchLog "Docker unavailable. Retrying in ${PollIntervalSeconds}s..." "Yellow"
            Start-Sleep -Seconds $PollIntervalSeconds
            continue
        }

        # Fetch cap nhat moi tu remote branch
        $fetchOutput = git fetch origin $targetBranch 2>&1
        $fetchExit = $LASTEXITCODE
        if ($fetchExit -ne 0) {
            Write-QCWatchLog "GitHub check FAILED (Network/SSH). Keeping current version. Retrying in ${PollIntervalSeconds}s..." "Yellow"
            Start-Sleep -Seconds $PollIntervalSeconds
            continue
        }

        $localCommit = (git rev-parse HEAD 2>&1 | Out-String).Trim()
        $remoteCommit = (git rev-parse FETCH_HEAD 2>&1 | Out-String).Trim()
        if (-not $remoteCommit -or $LASTEXITCODE -ne 0) {
            $remoteCommit = (git rev-parse "origin/$targetBranch" 2>&1 | Out-String).Trim()
        }

        $shortLocal = $localCommit.Substring(0, [Math]::Min(7, $localCommit.Length))
        $shortRemote = $remoteCommit.Substring(0, [Math]::Min(7, $remoteCommit.Length))

        if ($localCommit -eq $remoteCommit) {
            # Khong co commit moi
            Write-QCWatchLog "Checking GitHub... No new commit (Current: $shortLocal)" "Gray"
        } else {
            # Phat hien commit moi
            Write-QCWatchLog "New commit detected!" "Green"
            Write-QCWatchLog "Current: $shortLocal" "Cyan"
            Write-QCWatchLog "Target:  $shortRemote" "Cyan"

            $deploySuccess = Invoke-SafeDeployment -CurrentCommit $localCommit -TargetCommit $remoteCommit -TargetBranch $targetBranch
            if ($deploySuccess) {
                Write-QCWatchLog "Watching for next commit..." "White"
            } else {
                Write-QCWatchLog "Deployment failed. Continuing watch for next valid commit..." "Yellow"
            }
        }

        # Neu chi chay 1 lan theo tham so -Once
        if ($Once) {
            Write-QCWatchLog "Single pass complete (-Once). Exiting." "Cyan"
            break
        }

        Start-Sleep -Seconds $PollIntervalSeconds
    }

} finally {
    # Don dep lock file va container candidate neu co
    if (Test-Path $lockFile) {
        Remove-Item $lockFile -Force -ErrorAction SilentlyContinue
    }
    docker rm -f $candidateContainerName 2>$null | Out-Null
    if (Test-Path $candidateDir) {
        git worktree remove --force .qc-candidate 2>$null | Out-Null
    }
    Write-QCWatchLog "AUTO WATCH OFF" "Red"
}
