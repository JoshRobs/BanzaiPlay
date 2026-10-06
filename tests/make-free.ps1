<#
.SYNOPSIS
    Approximates the free build Freemius generates, for testing it locally.

.DESCRIPTION
    Freemius makes the wp.org build from the premium ZIP (tools/build.ps1):
    it leaves out every file whose name contains __premium_only, strips
    `if ( banzaiplay_fs()->is__premium_only() ) { … }` blocks, and sets
    is_premium to false. This does the same to a copy, closely enough to
    prove the free code stands on its own — never ship its output.

    Writes tests/output/free/banzaiplay-free/. To try it on wp-env:

      pwsh tests/make-free.ps1
      docker cp tests/output/free/banzaiplay-free <wordpress container>:/var/www/html/wp-content/plugins/
      npx @wordpress/env run cli wp plugin deactivate BanzaiPlay
      npx @wordpress/env run cli wp plugin activate banzaiplay-free

    and back with the two commands the other way round. Remove the copy by deleting
    its folder (docker exec -u root <wordpress container> rm -rf …/banzaiplay-free),
    never with wp plugin delete or the Plugins screen: deleting a plugin runs its
    uninstall, and the copy's would delete every game, which both copies share.
#>
$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$out  = Join-Path $root 'tests/output/free/banzaiplay-free'

if (Test-Path $out) {
    Remove-Item $out -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $out | Out-Null

foreach ($item in 'banzaiplay.php', 'readme.txt', 'license.txt', 'includes', 'assets', 'blocks', 'templates', 'vendor/freemius') {
    $destination = Join-Path $out $item
    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $destination) | Out-Null
    Copy-Item -Path (Join-Path $root $item) -Destination $destination -Recurse -Force
}

$premium = Get-ChildItem $out -Recurse -File | Where-Object { $_.Name -match '__premium_only' }
$premium | Remove-Item -Force

$stripped = 0

foreach ($file in Get-ChildItem (Join-Path $out 'includes'), (Join-Path $out 'templates') -Recurse -Filter *.php) {
    $text = [IO.File]::ReadAllText($file.FullName)
    # The block and the comment above it, up to its closing brace at the
    # same indentation.
    $new = [regex]::Replace($text, '(?s)(\n(\t+))(// [^\n]*\n\2)?if \( banzaiplay_fs\(\)->is__premium_only\(\) \) \{\n.*?\n\2\}\n', "`n")

    if ($new -ne $text) {
        $stripped++
        [IO.File]::WriteAllText($file.FullName, $new)
    }
}

$main = Join-Path $out 'banzaiplay.php'
$text = [IO.File]::ReadAllText($main)
$text = $text.Replace("'is_premium'          => true", "'is_premium'          => false")
$text = [regex]::Replace($text, "(?m)^\s*'wp_org_gatekeeper'.*\r?\n", '')
[IO.File]::WriteAllText($main, $text)

Write-Host "free build -> $out  ($($premium.Count) premium files left out, $stripped premium blocks stripped)"
