<#
.SYNOPSIS
    Servana local browser UAT — one PowerShell 7 entry point for the existing Docker Compose stack
    (LOCAL-UAT-001). Runbook: docs/runbooks/local-browser-uat.md

.DESCRIPTION
    pwsh -File scripts/dev/servana-local.ps1 <action>

      preflight  Tools, Docker, the eight host mappings, ports, local .env (names only), local-only DB.
      hosts      Map the eight *.servana.test hosts (raises ONE normal Windows UAC prompt if needed).
      start      Start this project's services (idempotent), wait for health, build the SPA if missing,
                 prove each host through nginx, print the eight URLs and Mailpit.
      status     Service health, deep health, queue/mail/storage readiness, host probes.
      restart    Restart this project's services only, then re-check readiness.
      stop       Stop this project's services. Volumes and data are KEPT.
      logs       Bounded logs:  logs [service] [lines]   (default: nginx app worker, 80 lines; tokens redacted)
      migrate    Non-destructive `migrate` on the LOCAL database only (never fresh/wipe).
      seed       Opt-in LOCAL-UAT dataset (idempotent, local-only, no external sends).
      build      Rebuild the SPA bundle nginx serves (`npm run build`).
      watch      Rebuild the SPA on every source change (reload the browser to see it).
      uat        Clear local rate limits, then run the real-stack browser acceptance suite.

    It only ever addresses the `servana` Compose project. It never runs `down -v`, `migrate:fresh`,
    `db:wipe`, `docker system prune` or `wsl --shutdown`, and never prints secrets or Magic Link tokens.
    A full local reset is documented in the runbook and is never automated here.
#>
param(
    [Parameter(Position = 0)] [string] $Action = 'help',
    [Parameter(Position = 1)] [string] $Target = '',
    [Parameter(Position = 2)] [int] $Lines = 80
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$root = Resolve-Path (Join-Path $PSScriptRoot '..\..')
Set-Location -LiteralPath $root

$port = 8080          # nginx published port (docker-compose.yml: "8080:8080")
$mailpit = 'http://localhost:8025'
$registry = Get-Content -LiteralPath (Join-Path $root 'config\account-hosts.json') -Raw | ConvertFrom-Json
$accounts = foreach ($a in $registry.accounts) {
    $hostName = if ($null -eq $a.subdomain) { $registry.domains.local } else { "$($a.subdomain).$($registry.domains.local)" }
    [pscustomobject]@{ Key = $a.account_key; Name = $a.display_name; Host = $hostName; Url = "http://${hostName}:$port/" }
}
$services = @('postgres', 'redis', 'meilisearch', 'minio', 'minio-init', 'mailpit', 'app', 'nginx', 'worker', 'file-worker', 'scheduler')
$script:failed = 0

function Gate([string] $name, [bool] $ok, [string] $detail = '') {
    if (-not $ok) { $script:failed++ }
    '{0,-5} {1,-34} {2}' -f ($(if ($ok) { 'PASS' } else { 'FAIL' })), $name, $detail
}

function Compose([string[]] $arguments) {
    & docker compose @arguments
    if ($LASTEXITCODE -ne 0) { throw "docker compose $($arguments -join ' ') failed ($LASTEXITCODE)." }
}

function Invoke-Http([string] $hostName, [string] $path) {
    try {
        $r = Invoke-WebRequest -Uri "http://${hostName}:$port$path" -SkipHttpErrorCheck -TimeoutSec 15 -MaximumRedirection 0
        return [int] $r.StatusCode
    } catch { return 0 }
}

function Test-Preflight {
    Gate 'repository root' (Test-Path (Join-Path $root 'artisan')) $root
    Gate 'PowerShell 7' ($PSVersionTable.PSVersion.Major -ge 7) $PSVersionTable.PSVersion.ToString()
    foreach ($tool in 'docker', 'node', 'npm', 'git') { Gate "tool: $tool" ([bool](Get-Command $tool -ErrorAction SilentlyContinue)) }
    $docker = (& docker info --format '{{.ServerVersion}}' 2>$null)
    Gate 'Docker daemon reachable' ($LASTEXITCODE -eq 0) "$docker"
    Gate 'local .env present (values not shown)' (Test-Path (Join-Path $root '.env'))
    $appEnv = (Select-String -Path (Join-Path $root '.env') -Pattern '^APP_ENV=(.*)$' -ErrorAction SilentlyContinue).Matches.Groups[1].Value
    Gate 'APP_ENV is local' ($appEnv -eq 'local') "APP_ENV=$appEnv"
    Gate 'compose database is the local `servana` DB' ((Get-Content docker-compose.yml -Raw) -match 'DB_HOST: postgres') 'DB_HOST=postgres (container), DB_DATABASE=servana'
    foreach ($a in $accounts) {
        $ok = $false
        try { $ok = @([Net.Dns]::GetHostAddresses($a.Host) | ForEach-Object { $_.IPAddressToString }) -contains '127.0.0.1' } catch { }
        Gate "host resolves: $($a.Host)" $ok
    }
    $branch = (& git branch --show-current)
    Gate 'git branch (informational)' $true $branch
}

function Wait-Healthy {
    $deadline = (Get-Date).AddMinutes(4)
    $edgeRefreshed = $false
    do {
        $ps = (& docker compose ps --format json) | ForEach-Object { $_ | ConvertFrom-Json }
        # Docker can reassign the app IP on restart. nginx resolves its FastCGI upstream when
        # loading configuration, so refresh it AFTER PHP is healthy (never recreate nginx/logs).
        $appReady = @($ps | Where-Object { $_.Service -eq 'app' -and $_.State -eq 'running' -and $_.Health -eq 'healthy' }).Count -eq 1
        $edgeRunning = @($ps | Where-Object { $_.Service -eq 'nginx' -and $_.State -eq 'running' }).Count -eq 1
        if (-not $edgeRefreshed -and $appReady -and $edgeRunning) {
            Compose @('exec', '-T', 'nginx', 'nginx', '-s', 'reload')
            $edgeRefreshed = $true
        }
        $unhealthy = @($ps | Where-Object { $_.Health -and $_.Health -ne 'healthy' })
        if ($edgeRefreshed -and $unhealthy.Count -eq 0 -and $edgeRunning -and (Invoke-Http 'localhost' '/health') -eq 200) { return $true }
        Start-Sleep -Seconds 3
    } while ((Get-Date) -lt $deadline)
    return $false
}

function Show-Status {
    $ps = (& docker compose ps --all --format json) | ForEach-Object { $_ | ConvertFrom-Json }
    foreach ($svc in $services) {
        $row = $ps | Where-Object { $_.Service -eq $svc } | Select-Object -First 1
        $expectedExit = $svc -eq 'minio-init'
        $ok = $row -and (($row.State -eq 'running' -and (-not $row.Health -or $row.Health -eq 'healthy')) -or ($expectedExit -and $row.State -eq 'exited'))
        Gate "service: $svc" ([bool] $ok) $(if ($row) { "$($row.State) $($row.Health)" } else { 'not created' })
    }
    try {
        $deep = Invoke-RestMethod -Uri "http://localhost:$port/health/deep" -TimeoutSec 15
        foreach ($check in $deep.checks.PSObject.Properties) { Gate "deep health: $($check.Name)" ($check.Value.status -eq 'ok') $check.Value.status }
    } catch { Gate 'deep health' $false $_.Exception.Message }
    Gate 'SPA bundle built (public/spa manifest)' (Test-Path (Join-Path $root 'public\spa\.vite\manifest.json'))
    try { $null = Invoke-RestMethod "$mailpit/api/v1/info" -TimeoutSec 10; Gate 'Mailpit API' $true $mailpit } catch { Gate 'Mailpit API' $false $mailpit }
    foreach ($a in $accounts) {
        $code = Invoke-Http $a.Host '/'
        Gate "browser host: $($a.Host)" ($code -eq 200) "GET / -> $code"
    }
}

function Show-Urls {
    ''
    'Servana local browser UAT — open these in any browser (public landing page first):'
    foreach ($a in $accounts) { '  {0,-24} {1}' -f $a.Name, $a.Url }
    "  Mail (sign-in links)      $mailpit/"
    'Sign in: open a host, choose Log in, enter a seeded email, open the link from Mailpit. See docs/runbooks/local-browser-uat.md.'
}

switch ($Action.ToLowerInvariant()) {
    'preflight' { Test-Preflight }
    'hosts' {
        & pwsh -NoProfile -File (Join-Path $PSScriptRoot 'servana-hosts.ps1') | Out-Host
        if ($LASTEXITCODE -eq 0) { 'All eight hosts already map to 127.0.0.1; nothing to do.'; break }
        $result = Join-Path $env:TEMP "servana-hosts-$([guid]::NewGuid().ToString('N')).json"
        $arguments = '-NoProfile -File "' + (Join-Path $PSScriptRoot 'servana-hosts.ps1') + '" -Apply -ResultPath "' + $result + '"'
        'A Windows UAC prompt will appear: approve it to let the reviewed hosts script add the eight records.'
        try {
            $child = Start-Process -FilePath (Get-Command pwsh).Source -ArgumentList $arguments -Verb RunAs -Wait -PassThru
        } catch { throw "Elevation did not complete: $($_.Exception.Message)" }
        if (Test-Path $result) { Get-Content $result -Raw }
        & pwsh -NoProfile -File (Join-Path $PSScriptRoot 'servana-hosts.ps1') | Out-Host
        if ($child.ExitCode -ne 0 -or $LASTEXITCODE -ne 0) { $script:failed++ }
    }
    'start' {
        Compose (@('up', '-d') + $services)
        if (-not (Test-Path (Join-Path $root 'public\spa\.vite\manifest.json'))) {
            'SPA bundle missing; building it (npm run build)…'
            & npm run build; if ($LASTEXITCODE -ne 0) { throw 'npm run build failed.' }
        }
        $healthy = Wait-Healthy
        Gate 'containers healthy' $healthy
        Show-Status
        Show-Urls
    }
    'status' { Show-Status; Show-Urls }
    'restart' {
        Compose (@('restart') + ($services | Where-Object { $_ -ne 'minio-init' }))
        Gate 'containers healthy after restart' (Wait-Healthy)
        Show-Status
    }
    'stop' {
        Compose (@('stop') + $services)
        'Stopped. Volumes (database, Redis, MinIO, Meilisearch) are kept; `start` resumes where you left off.'
    }
    'logs' {
        [string[]] $targets = if ($Target) { @($Target) } else { @('nginx', 'app', 'worker') }
        & docker compose logs --no-color --tail $Lines @targets 2>&1 |
            ForEach-Object { $_ -replace '(token=)[^&\s"]+', '$1<redacted>' -replace '(servana_session=)[^;\s]+', '$1<redacted>' }
        if ($LASTEXITCODE -ne 0) { $script:failed++ }
    }
    'migrate' {
        Compose @('exec', '-T', 'app', 'php', 'artisan', 'migrate:status')
        Compose @('exec', '-T', 'app', 'php', 'artisan', 'migrate', '--no-interaction')
    }
    'seed' {
        Compose @('exec', '-T', 'app', 'php', 'artisan', 'db:seed', '--class=Database\Seeders\LocalUat\LocalUatDatasetSeeder')
        'Identities: storage/app/local-uat/fixture-manifest.json (git-ignored). Sign in with Magic Link via Mailpit.'
    }
    'build' { & npm run build; if ($LASTEXITCODE -ne 0) { $script:failed++ } }
    'watch' {
        'Rebuilding the SPA into public/spa on every change. Reload the browser after "built in". Ctrl+C stops.'
        & npx vite build --watch
        if ($LASTEXITCODE -ne 0) { $script:failed++ }
    }
    'uat' {
        Compose @('exec', '-T', 'app', 'php', 'artisan', 'cache:clear')
        $results = Join-Path $root 'test-results\local-uat\results'
        if (Test-Path $results) { Get-ChildItem -LiteralPath $results -Filter '*.jsonl' | Remove-Item }
        $env:LOCAL_UAT_RESOLVER = 'hosts'
        & npx playwright test -c playwright.local-uat.config.ts
        if ($LASTEXITCODE -ne 0) { $script:failed++ }
    }
    default {
        Get-Help $MyInvocation.MyCommand.Path -Detailed | Out-String | Write-Host
    }
}

if ($script:failed -gt 0) {
    Write-Host "$($script:failed) gate(s) failed."
    exit 1
}
exit 0
