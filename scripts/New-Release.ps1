# Run from any directory: powershell -File .\scripts\New-Release.ps1
# This is a source release. Install production Composer dependencies on the server.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$releaseRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$releaseDirectory = Join-Path $releaseRoot 'dist'
New-Item -ItemType Directory -Path $releaseDirectory -Force | Out-Null
$releasePath = Join-Path $releaseDirectory ('backend-api-release-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip')
$releaseFiles = @()
foreach ($name in @('artisan', 'composer.json', 'composer.lock', '.env.example', 'README.md')) {
    $releaseFiles += Get-Item -LiteralPath (Join-Path $releaseRoot $name)
}
foreach ($name in @('app', 'bootstrap', 'config', 'database/factories', 'database/migrations', 'database/seeders', 'public', 'resources', 'routes', 'scripts')) {
    $releaseFiles += Get-ChildItem -LiteralPath (Join-Path $releaseRoot $name) -File -Recurse -Force
}
$archive = [IO.Compression.ZipFile]::Open($releasePath, [IO.Compression.ZipArchiveMode]::Create)
try {
    foreach ($file in $releaseFiles) {
        if ($file.Attributes -band [IO.FileAttributes]::ReparsePoint) { continue }
        $relative = $file.FullName.Substring($releaseRoot.Length + 1).Replace('\', '/')
        if ($relative -match '(^|/)(\.git|node_modules|vendor[^/]*|\.local-backups)(/|$)' -or
            ($relative -match '(^|/)\.env' -and $relative -ne '.env.example') -or
            $relative -match '\.(zip|sqlite|sqlite3|log|key|pem|bak)$' -or
            $relative -match '^bootstrap/cache/.*\.php$' -or
            $relative -match '^public/(storage/|hot$)') { continue }
        [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $relative, [IO.Compression.CompressionLevel]::Optimal) | Out-Null
    }
    foreach ($directory in @('bootstrap/cache/', 'storage/app/private/', 'storage/app/public/', 'storage/framework/cache/data/', 'storage/framework/sessions/', 'storage/framework/views/', 'storage/logs/')) {
        $archive.CreateEntry($directory) | Out-Null
    }
} finally { $archive.Dispose() }
$archive = [IO.Compression.ZipFile]::OpenRead($releasePath)
try {
    $names = $archive.Entries.FullName
    foreach ($required in @('app/Http/Controllers/Api/LoginOtpController.php', 'app/Http/Middleware/EnsureSessionVersion.php', 'app/Providers/AppServiceProvider.php', 'app/Services/AuthDataPruner.php', 'config/auth_retention.php', 'routes/api.php', 'routes/console.php', 'composer.lock', 'resources/views/emails/new-device.blade.php', 'resources/views/emails/login-verification.blade.php', 'resources/views/emails/password-reset.blade.php', 'database/migrations/2026_10_07_130000_add_auth_session_version_to_users_table.php')) {
        if ($names -notcontains $required) { throw "Release is missing $required" }
    }
    $unsafe = $names | Where-Object { ($_ -match '(^|/)\.env' -and $_ -ne '.env.example') -or $_ -match '(^|/)(vendor[^/]*|\.git|\.local-backups)(/|$)|\.sqlite$' }
    if ($unsafe) { throw 'Release contains excluded files. Do not deploy.' }
} finally { $archive.Dispose() }
Write-Output $releasePath
Get-FileHash -LiteralPath $releasePath -Algorithm SHA256
