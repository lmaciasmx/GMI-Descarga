param([switch]$WithDevDependencies)
$ErrorActionPreference = "Stop"
$runtimeDirectories = @(
    "writable\cache",
    "writable\debugbar",
    "writable\logs",
    "writable\session",
    "writable\uploads",
    "writable\labels"
)
foreach ($directory in $runtimeDirectories) {
    New-Item -ItemType Directory -Force -Path $directory | Out-Null
}
$composerFlags = @("install", "--prefer-dist", "--no-interaction", "--optimize-autoloader")
if (-not $WithDevDependencies) { $composerFlags += "--no-dev" }
& composer @composerFlags
npm ci
npm run verify
php spark migrate --all
php spark cache:clear
Write-Host "Actualización aplicada. Revisa writable/logs y ejecuta las pruebas de humo."
