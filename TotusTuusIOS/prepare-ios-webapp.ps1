param(
    [string]$SourceWebApp = "",
    [string]$DestinationWebApp = ".\WebApp"
)

$ErrorActionPreference = "Stop"

if ([string]::IsNullOrWhiteSpace($SourceWebApp)) {
    $scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
    $SourceWebApp = Join-Path (Split-Path -Parent $scriptDir) "WebApp"
    $DestinationWebApp = Join-Path $scriptDir "WebApp"
}

$source = Resolve-Path -LiteralPath $SourceWebApp -ErrorAction Stop
$destination = $DestinationWebApp
if (-not [System.IO.Path]::IsPathRooted($destination)) {
    $destination = Join-Path (Get-Location) $destination
}

if (-not (Test-Path -LiteralPath (Join-Path $source "index.html"))) {
    throw "Source WebApp folder must contain index.html: $source"
}

if (Test-Path -LiteralPath $destination) {
    Remove-Item -LiteralPath $destination -Recurse -Force
}

Copy-Item -LiteralPath $source -Destination $destination -Recurse -Force

Write-Host "Copied WebApp from $source to $destination"
Write-Host "Now copy/open the whole TotusTuusIOS folder on macOS and build TotusTuusIOS.xcodeproj."
