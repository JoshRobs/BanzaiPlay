<#
.SYNOPSIS
    Builds the distributable plugin ZIP.

.DESCRIPTION
    Attach the resulting ZIP to a GitHub release. It is the whole plugin —
    there is one build, with every feature in it.

    The file list is an ALLOWLIST, deliberately — the same as BanzaiEmbed's
    build. A blocklist has to be right every time a new file appears in the
    repo root, and the cost of getting it wrong once is shipping local config
    such as .wp-env.override.json to everyone who downloads it. Anything not
    named here does not ship.

    The archive contains a single top-level directory named for the slug, which
    is what WordPress expects when the ZIP is installed through the Plugins
    screen.

.EXAMPLE
    pwsh tools/build.ps1
#>
[CmdletBinding()]
param(
    # Where the finished ZIP is written.
    [string] $OutputDir
)

$ErrorActionPreference = 'Stop'

$slug = 'banzaiplay'
$root = Split-Path -Parent $PSScriptRoot

if (-not $OutputDir) {
    $OutputDir = Join-Path $root 'dist'
}

# Read the version from the plugin header rather than holding a second copy of
# it here. Two versions that can disagree is how a release gets mislabelled.
$mainFile = Join-Path $root "$slug.php"
$header = Get-Content $mainFile -TotalCount 30 -Encoding UTF8
$versionLine = $header | Where-Object { $_ -match '^\s*\*\s*Version:\s*(.+?)\s*$' }

if (-not $versionLine) {
    throw "Could not read Version from $mainFile."
}

$version = $Matches[1]

# Everything that ships. Directories are copied whole.
$include = @(
    "$slug.php",
    'readme.txt',
    'license.txt',
    'includes',
    'assets',
    'blocks',
    'templates'
)

$staging = Join-Path ([System.IO.Path]::GetTempPath()) "bzpl-build-$([guid]::NewGuid().ToString('N'))"
$payload = Join-Path $staging $slug

New-Item -ItemType Directory -Force -Path $payload | Out-Null

foreach ($item in $include) {
    $source = Join-Path $root $item

    if (-not (Test-Path $source)) {
        throw "Missing required path: $item"
    }

    $destination = Join-Path $payload $item
    $parent = Split-Path -Parent $destination

    if (-not (Test-Path $parent)) {
        New-Item -ItemType Directory -Force -Path $parent | Out-Null
    }

    Copy-Item -Path $source -Destination $destination -Recurse -Force
}

# A last look before sealing it. The allowlist should make this impossible, but
# the thing it is guarding against is bad enough to be worth proving each time
# rather than assuming.
$leaked = Get-ChildItem $payload -Recurse -File |
    Where-Object { $_.Name -match '^\.wp-env' -or $_.Extension -eq '.zip' }

if ($leaked) {
    throw "Refusing to package: unexpected files present -> $($leaked.Name -join ', ')"
}

if (-not (Test-Path $OutputDir)) {
    New-Item -ItemType Directory -Force -Path $OutputDir | Out-Null
}

$zip = Join-Path $OutputDir "$slug-$version.zip"

if (Test-Path $zip) {
    Remove-Item $zip -Force
}

Compress-Archive -Path $payload -DestinationPath $zip -CompressionLevel Optimal

Remove-Item $staging -Recurse -Force

$size = [math]::Round((Get-Item $zip).Length / 1MB, 2)

Write-Host "built  $slug $version  ->  $zip  ($size MB)"
