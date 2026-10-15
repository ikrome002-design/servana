# LOCAL-UAT-001 / LUAT-REL-001: exercise native failures without touching the running Docker stack.
$ErrorActionPreference = 'Stop'
$wrapper = Join-Path $PSScriptRoot '..\servana-local.ps1'
$passed = 0
foreach ($case in @(
    @{ Action = 'logs'; Tool = 'docker'; Code = 0; Expected = 0 },
    @{ Action = 'logs'; Tool = 'docker'; Code = 23; Expected = 1 },
    @{ Action = 'watch'; Tool = 'npx'; Code = 0; Expected = 0 },
    @{ Action = 'watch'; Tool = 'npx'; Code = 23; Expected = 1 }
)) {
    # A child process is required because the wrapper deliberately exits with its gate result.
    $escapedPath = $wrapper.Replace("'", "''")
    $command = "function global:$($case.Tool) { `$global:LASTEXITCODE = $($case.Code); 'fixture command result' }; & '$escapedPath' $($case.Action)"
    $output = & pwsh -NoProfile -Command $command 2>&1
    if ($LASTEXITCODE -ne $case.Expected) {
        throw "$($case.Action), native exit $($case.Code): expected $($case.Expected), got $LASTEXITCODE. $output"
    }
    $passed++
}
# A start/restart must refresh nginx after PHP is healthy, and propagate reload failure.
foreach ($action in 'start', 'restart') {
    foreach ($reloadCode in 0, 23) {
        $logPath = Join-Path $env:TEMP "servana-local-fixture-$([guid]::NewGuid().ToString('N')).txt"
        $escapedLog = $logPath.Replace("'", "''")
        $escapedPath = $wrapper.Replace("'", "''")
        $command = @'
function global:docker {
    $call = $args -join ' '
    Add-Content -LiteralPath '__LOG__' -Value $call
    $global:LASTEXITCODE = 0
    if ($call -match '^compose ps') {
        foreach ($service in 'postgres','redis','meilisearch','minio','minio-init','mailpit','app','nginx','worker','file-worker','scheduler') {
            @{ Service=$service; State='running'; Health='healthy'; ExitCode=0 } | ConvertTo-Json -Compress
        }
    } elseif ($call -eq 'compose exec -T nginx nginx -s reload') {
        $global:LASTEXITCODE = __CODE__
    }
}
function global:Invoke-WebRequest { @{ StatusCode=200 } }
function global:Invoke-RestMethod { [pscustomobject]@{ checks=[pscustomobject]@{ app=[pscustomobject]@{ status='ok' } } } }
& '__WRAPPER__' __ACTION__
'@
        $command = $command.Replace('__LOG__', $escapedLog).Replace('__CODE__', "$reloadCode").Replace('__WRAPPER__', $escapedPath).Replace('__ACTION__', $action)
        $output = & pwsh -NoProfile -Command $command 2>&1
        $expected = if ($reloadCode -eq 0) { 0 } else { 1 }
        if ($LASTEXITCODE -ne $expected) { throw "$action reload=$reloadCode expected exit $expected, got $LASTEXITCODE. $output" }
        $calls = @(Get-Content -LiteralPath $logPath)
        $reloadIndex = [array]::IndexOf($calls, 'compose exec -T nginx nginx -s reload')
        $healthIndex = [array]::IndexOf($calls, 'compose ps --format json')
        if ($healthIndex -lt 0 -or $reloadIndex -le $healthIndex) { throw "$action did not reload nginx after checking app readiness." }
        $passed++
    }
}
"servana-local self-test: $passed passed, 0 failed (native tools and HTTP stubbed; no service mutation)"
