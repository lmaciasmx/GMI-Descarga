param(
    [Parameter(Mandatory = $true)][string]$BackupFile,
    [switch]$Confirm
)
$ErrorActionPreference = "Stop"
if (-not $Confirm) { throw "La restauración requiere -Confirm." }
if (-not (Test-Path $BackupFile -PathType Leaf)) { throw "No existe el respaldo indicado." }
foreach ($name in @("GMAR_DB_HOST", "GMAR_DB_NAME", "GMAR_DB_USER", "GMAR_DB_PASSWORD")) {
    if (-not [Environment]::GetEnvironmentVariable($name)) { throw "Define $name" }
}
$checksumFile = "$BackupFile.sha256"
if (Test-Path $checksumFile -PathType Leaf) {
    $expected = ((Get-Content -Raw $checksumFile).Trim() -split '\s+')[0].ToLowerInvariant()
    $actual = (Get-FileHash -Algorithm SHA256 $BackupFile).Hash.ToLowerInvariant()
    if ($expected -ne $actual) { throw "El checksum SHA-256 del respaldo no coincide." }
} else {
    Write-Warning "No se encontró $checksumFile; valida la procedencia antes de continuar."
}
$env:MYSQL_PWD = $env:GMAR_DB_PASSWORD
try {
    $arguments = @("--host=$($env:GMAR_DB_HOST)", "--user=$($env:GMAR_DB_USER)", $env:GMAR_DB_NAME)
    $process = Start-Process -FilePath "mysql" -ArgumentList $arguments -RedirectStandardInput $BackupFile -NoNewWindow -Wait -PassThru
    if ($process.ExitCode -ne 0) { throw "mysql devolvió el código $($process.ExitCode)" }
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
}
Write-Host "Restauración terminada. Ejecuta php spark migrate:status y las pruebas de humo."
