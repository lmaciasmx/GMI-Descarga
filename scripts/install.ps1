param([string]$AdminEmail = "", [string]$AdminUser = "")
$ErrorActionPreference = "Stop"
if (-not (Test-Path ".env")) {
    Copy-Item ".env.example" ".env"
    Write-Error "Se creó .env. Configúralo y vuelve a ejecutar el script."
}
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
composer install --prefer-dist --no-interaction
npm ci
npm run verify
php spark migrate --all
php spark db:seed 'App\Database\Seeds\CoreCatalogSeeder'
if ($AdminEmail -and $AdminUser) { php spark app:bootstrap-admin $AdminEmail $AdminUser }
else { Write-Host "Crea el administrador: php spark app:bootstrap-admin correo@empresa.com usuario" }
