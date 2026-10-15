<#
.SYNOPSIS
    Self-test for scripts/dev/servana-hosts.ps1 on TEMPORARY fixture hosts files (LOCAL-UAT-001).

.DESCRIPTION
    Never touches the real Windows hosts file. Each case writes a fixture into a fresh temp folder, runs
    the manager against it with -HostsPath, and asserts the outcome. Exit code 0 = all cases passed.

    pwsh -NoProfile -File scripts/dev/tests/servana-hosts.selftest.ps1
#>
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$manager = Join-Path $PSScriptRoot '..\servana-hosts.ps1'
$pwsh = (Get-Command pwsh).Source
$failures = [Collections.Generic.List[string]]::new()
$passes = 0
$hosts = @('servana.test', 'citrus.servana.test', 'branch.servana.test', 'finance.servana.test', 'hr.servana.test', 'office.servana.test', 'staff.servana.test', 'audit.servana.test')

function New-Fixture([byte[]] $bytes) {
    $dir = Join-Path ([IO.Path]::GetTempPath()) "servana-hosts-selftest-$([guid]::NewGuid().ToString('N'))"
    New-Item -ItemType Directory -Path $dir | Out-Null
    $path = Join-Path $dir 'hosts'
    [IO.File]::WriteAllBytes($path, $bytes)
    return $path
}

function Invoke-Manager([string] $path, [string] $mode) {
    $resultPath = "$path.result.json"
    $args = @('-NoProfile', '-File', $manager, '-HostsPath', $path, '-ResultPath', $resultPath)
    if ($mode) { $args += "-$mode" }
    $output = & $pwsh @args 2>&1
    return [pscustomobject]@{
        Code = $LASTEXITCODE
        Output = ($output -join "`n")
        Result = (Get-Content -LiteralPath $resultPath -Raw | ConvertFrom-Json)
    }
}

function Assert([string] $case, [bool] $condition, [string] $detail = '') {
    if ($condition) { $script:passes++ } else { $script:failures.Add("$case :: $detail") }
}

function Text([string] $path) { [IO.File]::ReadAllText($path) }
function Backups([string] $path) { @(Get-ChildItem -LiteralPath (Split-Path $path) -Filter 'hosts.servana-backup-*') }
function Remove-Fixture([string] $path) { Remove-Item -LiteralPath (Split-Path $path) -Recurse -Force }

$utf8 = [Text.UTF8Encoding]::new($false)
$original = "# Copyright (c) Microsoft Corp.`r`n#`r`n127.0.0.1 localhost`r`n::1 localhost`r`n192.168.0.102 host.docker.internal`r`n"

# 1. Apply adds one block, keeps every original line and CRLF, writes a verified backup.
$f = New-Fixture $utf8.GetBytes($original)
$r = Invoke-Manager $f 'Apply'
$t = Text $f
Assert 'apply.exit' ($r.Code -eq 0) $r.Output
Assert 'apply.preserves-prefix' ($t.StartsWith($original)) 'original lines changed'
Assert 'apply.crlf' (-not ($t -match "[^`r]`n")) 'LF found in a CRLF file'
Assert 'apply.eight-records' ((@($hosts | Where-Object { $t -match "(?m)^127\.0\.0\.1 $([regex]::Escape($_))\r?$" })).Count -eq 8) $t
Assert 'apply.backup' (@(Backups $f).Count -eq 1 -and [IO.File]::ReadAllText(@(Backups $f)[0].FullName) -eq $original) 'backup missing or wrong'

# 2. A second Apply is a no-op: same bytes, no second backup.
$before = (Get-FileHash -LiteralPath $f).Hash
$r = Invoke-Manager $f 'Apply'
Assert 'idempotent.exit' ($r.Code -eq 0) $r.Output
Assert 'idempotent.unchanged' ((Get-FileHash -LiteralPath $f).Hash -eq $before -and -not $r.Result.changed) 'file rewritten'
Assert 'idempotent.no-new-backup' (@(Backups $f).Count -eq 1) 'extra backup'

# 3. Check passes on the applied fixture; Remove restores the original bytes exactly.
$r = Invoke-Manager $f ''
Assert 'check.pass' ($r.Code -eq 0) $r.Output
$r = Invoke-Manager $f 'Remove'
Assert 'remove.restores' ($r.Code -eq 0 -and (Text $f) -eq $original) (Text $f)
Remove-Fixture $f

# 4. An unmanaged record pointing a Servana host elsewhere is a conflict: refused, file untouched.
$conflict = $original + "10.0.0.5 hr.servana.test`r`n"
$f = New-Fixture $utf8.GetBytes($conflict)
$r = Invoke-Manager $f 'Apply'
Assert 'conflict.refused' ($r.Code -ne 0 -and @($r.Result.conflicts).Count -eq 1) $r.Output
Assert 'conflict.untouched' ((Text $f) -eq $conflict -and @(Backups $f).Count -eq 0) 'file or backup changed'
Remove-Fixture $f

# 5. An owner's own loopback record is respected, never duplicated.
$own = $original + "127.0.0.1 servana.test`r`n"
$f = New-Fixture $utf8.GetBytes($own)
$r = Invoke-Manager $f 'Apply'
$t = Text $f
Assert 'own-loopback.exit' ($r.Code -eq 0) $r.Output
Assert 'own-loopback.single' (([regex]::Matches($t, '(?m)^127\.0\.0\.1 servana\.test\r?$')).Count -eq 1) $t
Assert 'own-loopback.others' ((@($hosts | Where-Object { $t -match "(?m)^127\.0\.0\.1 $([regex]::Escape($_))\r?$" })).Count -eq 8) $t
Remove-Fixture $f

# 6. LF newlines, a UTF-8 BOM and a non-ASCII comment survive byte for byte.
$lf = "# hôte — commentaire`n127.0.0.1 localhost`n"
$bom = [byte[]] (@(0xEF, 0xBB, 0xBF) + $utf8.GetBytes($lf))
$f = New-Fixture $bom
$r = Invoke-Manager $f 'Apply'
$bytes = [IO.File]::ReadAllBytes($f)
Assert 'encoding.exit' ($r.Code -eq 0) $r.Output
Assert 'encoding.bom-kept' ($bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) 'BOM lost'
Assert 'encoding.text-kept' ($utf8.GetString($bytes, 3, $bytes.Length - 3).StartsWith($lf)) 'non-ASCII comment changed'
Assert 'encoding.lf-kept' (-not $utf8.GetString($bytes).Contains("`r")) 'CR introduced'
Remove-Fixture $f

# 7. A malformed marker (unterminated block) is refused without any write.
$broken = $original + "# >>> servana local account hosts (scripts/dev/servana-hosts.ps1) >>>`r`n127.0.0.1 servana.test`r`n"
$f = New-Fixture $utf8.GetBytes($broken)
$r = Invoke-Manager $f 'Apply'
Assert 'malformed.refused' ($r.Code -ne 0 -and (Text $f) -eq $broken -and @(Backups $f).Count -eq 0) $r.Output
Remove-Fixture $f

# 8. A fixture never asks for elevation (only the system hosts file does).
$f = New-Fixture $utf8.GetBytes($original)
$r = Invoke-Manager $f 'Apply'
Assert 'fixture.no-elevation' ($r.Code -eq 0 -and -not ($r.Output -match 'elevated')) $r.Output
Remove-Fixture $f

Write-Host "servana-hosts self-test: $passes passed, $($failures.Count) failed"
foreach ($failure in $failures) { Write-Host "FAIL $failure" }
exit $(if ($failures.Count -eq 0) { 0 } else { 1 })
