param([string]$Destination = ".\backups")
$ErrorActionPreference = "Stop"
foreach ($name in @("GMAR_DB_HOST", "GMAR_DB_NAME", "GMAR_DB_USER", "GMAR_DB_PASSWORD")) {
    if (-not [Environment]::GetEnvironmentVariable($name)) { throw "Define $name" }
}
New-Item -ItemType Directory -Force -Path $Destination | Out-Null
$stamp = (Get-Date).ToUniversalTime().ToString("yyyyMMddTHHmmssZ")
$target = Join-Path $Destination "grupomar-$stamp.sql"
$errorFile = Join-Path $Destination "grupomar-$stamp.mysqldump-error.log"
$env:MYSQL_PWD = $env:GMAR_DB_PASSWORD
try {
    $arguments = @(
        "--host=$($env:GMAR_DB_HOST)", "--user=$($env:GMAR_DB_USER)",
        "--single-transaction", "--routines", "--triggers", "--set-gtid-purged=OFF",
        $env:GMAR_DB_NAME
    )
    $process = Start-Process -FilePath "mysqldump" -ArgumentList $arguments -RedirectStandardOutput $target -RedirectStandardError $errorFile -NoNewWindow -Wait -PassThru
    if ($process.ExitCode -ne 0 -or -not (Test-Path $target) -or (Get-Item $target).Length -eq 0) {
        $detail = if (Test-Path $errorFile) { (Get-Content -Raw $errorFile).Trim() } else { "sin detalle" }
        Remove-Item $target -Force -ErrorAction SilentlyContinue
        throw "mysqldump falló con código $($process.ExitCode): $detail"
    }
    $hash = (Get-FileHash -Algorithm SHA256 $target).Hash.ToLowerInvariant()
    $checksum = "$hash  $([IO.Path]::GetFileName($target))`r`n"
    [IO.File]::WriteAllText("$target.sha256", $checksum, [Text.UTF8Encoding]::new($false))
} finally {
    Remove-Item Env:MYSQL_PWD -ErrorAction SilentlyContinue
    Remove-Item $errorFile -Force -ErrorAction SilentlyContinue
}
Write-Host "Respaldo creado: $target"
