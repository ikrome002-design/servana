<#
.SYNOPSIS
    Map the eight Servana local account hosts to 127.0.0.1 in the Windows hosts file (LOCAL-UAT-001).

.DESCRIPTION
    The eight hosts come from config/account-hosts.json (domains.local = servana.test). `.test` is a
    reserved TLD no public resolver answers, so an ordinary browser reaches them only through the hosts
    file. The hosts file has no wildcards, so each host is one explicit record.

    -Check (default)  Read-only. System hosts file: every host must resolve ONLY to 127.0.0.1 through the
                      Windows resolver. A fixture (-HostsPath): every host must be mapped exactly once.
    -Apply            Add or correct the ONE marked Servana block. Needs an elevated PowerShell for the
                      system hosts file. Idempotent: an already-correct file is not written at all.
    -Remove           Delete only the marked Servana block (elevated for the system hosts file).

    Safety rules this script keeps (and scripts/dev/tests/servana-hosts.selftest.ps1 proves on fixtures):
      * a uniquely named backup is written BEFORE the first change, and never overwrites another backup;
      * every line outside the marked block is preserved byte for byte, with the file's own encoding
        (BOM or not) and newline style;
      * an UNMANAGED line mapping one of the eight hosts to anything other than 127.0.0.1 is a conflict:
        the run fails and changes nothing (it never edits a line it does not own);
      * the file is re-hashed immediately before writing; if another process changed it meanwhile, the
        run aborts;
      * it touches nothing but this one file: no DNS servers, firewall, registry, certificates, ACLs or
        browser settings, and it never disables host validation anywhere.

    -ResultPath  Optional. Writes a small JSON outcome there, so a non-elevated parent that launched this
                 script elevated (Start-Process -Verb RunAs) can read what happened.
    -HostsPath   Optional. Operate on another file (fixtures/tests). Defaults to the system hosts file.
#>
[CmdletBinding(DefaultParameterSetName = 'Check')]
param(
    [Parameter(ParameterSetName = 'Apply')] [switch] $Apply,
    [Parameter(ParameterSetName = 'Remove')] [switch] $Remove,
    [string] $HostsPath = (Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'),
    [string] $ResultPath = ''
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$beginMarker = '# >>> servana local account hosts (scripts/dev/servana-hosts.ps1) >>>'
$endMarker = '# <<< servana local account hosts <<<'
$loopback = '127.0.0.1'

$repoRoot = Resolve-Path (Join-Path $PSScriptRoot '..\..')
$registry = Get-Content -LiteralPath (Join-Path $repoRoot 'config\account-hosts.json') -Raw | ConvertFrom-Json
$localDomain = [string] $registry.domains.local
$hostNames = @(foreach ($account in $registry.accounts) {
    if ($null -eq $account.subdomain) { $localDomain } else { "$($account.subdomain).$localDomain" }
})
$systemHosts = Join-Path $env:SystemRoot 'System32\drivers\etc\hosts'
$isSystemFile = [string]::Equals([IO.Path]::GetFullPath($HostsPath), [IO.Path]::GetFullPath($systemHosts), [StringComparison]::OrdinalIgnoreCase)

$result = [ordered]@{
    action = $PSCmdlet.ParameterSetName.ToLowerInvariant()
    hosts_path = $HostsPath
    changed = $false
    backup = $null
    sha256_before = $null
    sha256_after = $null
    conflicts = @()
    error = $null
}

function Write-Result([int] $code) {
    if ($ResultPath -ne '') {
        $result['exit_code'] = $code
        ($result | ConvertTo-Json -Depth 4) | Set-Content -LiteralPath $ResultPath -Encoding utf8
    }
}

function Get-Sha256([byte[]] $bytes) {
    return [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes))
}

# Read the file as bytes and decode it with its own encoding, remembering how to write it back.
function Read-HostsFile([string] $path) {
    $bytes = [IO.File]::ReadAllBytes($path)
    $encoding = [Text.UTF8Encoding]::new($false)
    $preamble = 0
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $encoding = [Text.UTF8Encoding]::new($true); $preamble = 3
    } elseif ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE) {
        $encoding = [Text.UnicodeEncoding]::new($false, $true); $preamble = 2
    }
    $text = $encoding.GetString($bytes, $preamble, $bytes.Length - $preamble)
    $newline = if ($text.Contains("`r`n")) { "`r`n" } else { "`n" }
    $endsWithNewline = $text.EndsWith("`n")
    $body = if ($endsWithNewline) { $text.Substring(0, $text.Length - $newline.Length) } else { $text }
    $lines = if ($text.Length -eq 0) { @() } else { @($body.Split($newline)) }

    return [pscustomobject]@{
        Bytes = $bytes; Encoding = $encoding; Newline = $newline; EndsWithNewline = $endsWithNewline; Lines = $lines
    }
}

function ConvertTo-Bytes($file, [string[]] $lines) {
    $text = ($lines -join $file.Newline)
    if ($lines.Count -gt 0 -and ($file.EndsWithNewline -or $file.Lines.Count -eq 0)) { $text += $file.Newline }
    $body = $file.Encoding.GetBytes($text)
    $preamble = $file.Encoding.GetPreamble()

    return [byte[]] ($preamble + $body)
}

# Split into the lines this script does NOT own and the managed block (if any).
function Split-Managed([string[]] $lines) {
    $outside = [Collections.Generic.List[string]]::new()
    $inside = [Collections.Generic.List[string]]::new()
    $state = 'outside'; $blocks = 0
    foreach ($line in $lines) {
        if ($line -eq $beginMarker) {
            if ($state -eq 'inside') { throw 'The hosts file has a nested Servana begin marker. Nothing was changed.' }
            $state = 'inside'; $blocks++; continue
        }
        if ($line -eq $endMarker) {
            if ($state -ne 'inside') { throw 'The hosts file has a Servana end marker without a begin marker. Nothing was changed.' }
            $state = 'outside'; continue
        }
        if ($state -eq 'inside') { $inside.Add($line) } else { $outside.Add($line) }
    }
    if ($state -eq 'inside') { throw 'The hosts file has an unterminated Servana block. Nothing was changed.' }
    if ($blocks -gt 1) { throw 'The hosts file has more than one Servana block. Nothing was changed.' }

    return [pscustomobject]@{ Outside = @($outside); Inside = @($inside); HasBlock = ($blocks -eq 1) }
}

# Unmanaged records for our names: map name -> list of addresses.
function Get-UnmanagedMappings([string[]] $outside) {
    $map = @{}
    foreach ($line in $outside) {
        $content = ($line -replace '#.*$', '').Trim()
        if ($content -eq '') { continue }
        $tokens = @($content -split '\s+')
        if ($tokens.Count -lt 2) { continue }
        foreach ($name in $tokens[1..($tokens.Count - 1)]) {
            $key = $name.ToLowerInvariant()
            if ($hostNames -contains $key) {
                if (-not $map.ContainsKey($key)) { $map[$key] = [Collections.Generic.List[string]]::new() }
                $map[$key].Add($tokens[0])
            }
        }
    }
    return $map
}

function Assert-Elevated {
    if (-not $isSystemFile) { return }
    $principal = [Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()
    if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
        throw 'Editing the system hosts file needs an elevated PowerShell. Nothing was changed.'
    }
}

function Write-Checked($file, [byte[]] $newBytes) {
    # Abort if another process changed the file after it was read.
    $current = [IO.File]::ReadAllBytes($HostsPath)
    if ((Get-Sha256 $current) -ne (Get-Sha256 $file.Bytes)) {
        throw 'The hosts file changed while this script was running. Nothing was changed; run again.'
    }
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss-fff'
    $backup = "$HostsPath.servana-backup-$stamp-$([guid]::NewGuid().ToString('N').Substring(0, 8))"
    [IO.File]::WriteAllBytes($backup, $file.Bytes)
    if ((Get-Sha256 ([IO.File]::ReadAllBytes($backup))) -ne (Get-Sha256 $file.Bytes)) {
        throw "The backup at $backup did not verify. Nothing was changed."
    }
    $result['backup'] = $backup

    [IO.File]::WriteAllBytes($HostsPath, $newBytes)
    $written = [IO.File]::ReadAllBytes($HostsPath)
    if ((Get-Sha256 $written) -ne (Get-Sha256 $newBytes)) {
        throw "The hosts file did not read back as written. The original is preserved at $backup."
    }
    $result['changed'] = $true
    $result['sha256_after'] = Get-Sha256 $written
    Write-Host "Backed up the original to $backup"
    if ($isSystemFile) { ipconfig /flushdns | Out-Null }
}

try {
    $file = Read-HostsFile $HostsPath
    $result['sha256_before'] = Get-Sha256 $file.Bytes
    $split = Split-Managed $file.Lines
    $unmanaged = Get-UnmanagedMappings $split.Outside

    if ($Apply -or $Remove) {
        Assert-Elevated

        if ($Apply) {
            $conflicts = @(foreach ($name in $unmanaged.Keys) {
                foreach ($address in $unmanaged[$name]) { if ($address -ne $loopback) { "$address $name" } }
            })
            if ($conflicts.Count -gt 0) {
                $result['conflicts'] = $conflicts
                throw "Unmanaged hosts records map Servana hosts elsewhere: $($conflicts -join '; '). They were left untouched and nothing was changed; correct or remove them first."
            }
            # A name the owner already maps to loopback outside the block is satisfied; never duplicate it.
            $managedNames = @($hostNames | Where-Object { -not $unmanaged.ContainsKey($_) })
            if (-not $split.HasBlock) {
                # Appended as-is (no blank lines added or trimmed) so -Remove is an exact inverse.
                $newLines = @($split.Outside) + @($beginMarker) + @($managedNames | ForEach-Object { "$loopback $_" }) + @($endMarker)
            } else {
                # Rebuild the block in place, keeping every outside line where it is.
                $newLines = [Collections.Generic.List[string]]::new()
                $state = 'outside'
                foreach ($line in $file.Lines) {
                    if ($line -eq $beginMarker) {
                        $state = 'inside'; $newLines.Add($beginMarker)
                        foreach ($name in $managedNames) { $newLines.Add("$loopback $name") }
                        continue
                    }
                    if ($line -eq $endMarker) { $state = 'outside'; $newLines.Add($endMarker); continue }
                    if ($state -eq 'outside') { $newLines.Add($line) }
                }
                $newLines = @($newLines)
            }
        } else {
            $newLines = @($split.Outside)
        }

        $newBytes = ConvertTo-Bytes $file $newLines
        if ((Get-Sha256 $newBytes) -eq $result['sha256_before']) {
            Write-Host 'Already correct; the hosts file was not written.'
            $result['sha256_after'] = $result['sha256_before']
        } else {
            Write-Checked $file $newBytes
            Write-Host $(if ($Apply) { "Mapped the Servana hosts to $loopback." } else { 'Removed the Servana hosts block.' })
        }
        $file = Read-HostsFile $HostsPath
        $split = Split-Managed $file.Lines
        $unmanaged = Get-UnmanagedMappings $split.Outside
    }

    # ---- verification (all modes) ----
    $managed = Get-UnmanagedMappings $split.Inside
    $missing = 0
    foreach ($name in $hostNames) {
        $addresses = @()
        if ($managed.ContainsKey($name)) { $addresses += $managed[$name] }
        if ($unmanaged.ContainsKey($name)) { $addresses += $unmanaged[$name] }
        $fileOk = ($addresses.Count -ge 1) -and (@($addresses | Where-Object { $_ -ne $loopback }).Count -eq 0) -and (-not ($managed.ContainsKey($name) -and $unmanaged.ContainsKey($name)))
        $resolverOk = $true
        $resolved = ''
        if ($isSystemFile -and -not $Remove) {
            try {
                $ips = @([Net.Dns]::GetHostAddresses($name) | ForEach-Object { $_.IPAddressToString })
                $resolved = $ips -join ', '
                $resolverOk = ($ips -contains $loopback) -and (@($ips | Where-Object { $_ -ne $loopback }).Count -eq 0)
            } catch { $resolverOk = $false; $resolved = 'unresolved' }
        }
        $ok = if ($Remove) { -not $managed.ContainsKey($name) } else { $fileOk -and $resolverOk }
        if (-not $ok) { $missing++ }
        '{0,-5} {1,-24} file={2} {3}' -f ($(if ($ok) { 'PASS' } else { 'FAIL' })), $name, (($addresses -join ',') -replace '^$', '-'), $(if ($resolved) { "resolver=$resolved" } else { '' })
    }

    if ($missing -gt 0 -and -not $Remove) {
        Write-Host ''
        Write-Host "$missing host(s) are not mapped to $loopback. To map them, run (approve the Windows UAC prompt):"
        Write-Host '  pwsh -File scripts\dev\servana-local.ps1 hosts'
        Write-Result 1
        exit 1
    }
    Write-Result 0
    exit 0
} catch {
    $result['error'] = $_.Exception.Message
    Write-Host "ERROR: $($_.Exception.Message)"
    Write-Result 2
    exit 2
}
