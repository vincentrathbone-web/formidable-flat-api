# Package Script for Formidable Flat API
# This script creates a versioned ZIP file with the correct folder structure.

$pluginSlug = "formidable-flat-api"
$version = "3.2.2" # Update this whenever the version changes
$zipFileName = "$pluginSlug-v$version.zip"
$tempBase = "$env:TEMP\ffapi-package-$(Get-Date -Format 'yyyyMMddHHmmss')"
$tempPluginDir = "$tempBase\$pluginSlug"

Write-Host "Creating package: $zipFileName" -ForegroundColor Cyan

# 1. Cleanup old temp dir if it exists
if (Test-Path $tempBase) { Remove-Item -Path $tempBase -Recurse -Force }

# 2. Create the required directory structure (single top-level folder)
New-Item -ItemType Directory -Path $tempPluginDir | Out-Null

# 3. Copy all project files, excluding distribution-irrelevant items
# "admin-src" is the Svelte SOURCE for the admin UI (package.json, node_modules/, .svelte
# files) — not needed at runtime, only its build output ("dist/", not excluded here)
# matters to a shipped install. Run `npm run build` inside admin-src/ BEFORE packaging a
# release so dist/ is current — see CLAUDE.md "Admin UI build step".
$excludeItems = @("*.zip", "*.md", "temp_package_dir", "package.ps1", ".git", ".github", ".gitignore", ".claude", ".qwen", "inspect_views.php", "chat.log", "check_zip.ps1", "Flat Tables Examples", "Reports Examples", "admin-src")
Get-ChildItem -Path $PSScriptRoot | Where-Object {
    $name = $_.Name
    $skip = $false
    foreach ($pattern in $excludeItems) {
        if ($name -like $pattern) { $skip = $true; break }
    }
    -not $skip
} | Copy-Item -Destination $tempPluginDir -Recurse -Force

# 4. Create the ZIP using Python's zipfile (always uses forward slashes)
# Compress-Archive on Windows stores backslashes in paths which corrupts
# WordPress's plugin installer. Python's zipfile always uses forward slashes.
#
# IMPORTANT: Previous versions of the ZIP (e.g. formidable-flat-api-v2.9.0.zip)
# must be preserved as a release history. This script only refuses to touch
# existing ZIPs: if a ZIP for the CURRENT version already exists, abort so the
# user bumps the version rather than silently overwriting a prior build.
if (Test-Path $zipFileName) {
    Write-Host "[ABORT] $zipFileName already exists. Bump the version in package.ps1, formidable-flat-api.php (header + FRM_FLAT_VERSION) and PLUGIN.md before re-packaging. Previous version ZIPs must be preserved." -ForegroundColor Red
    if (Test-Path $tempBase) { Remove-Item -Path $tempBase -Recurse -Force }
    exit 1
}

# Build a Python script inline
$pyScript = @"
import os, zipfile

plugin_dir = r'$tempPluginDir'
plugin_slug = '$pluginSlug'
zip_path = r'$(Resolve-Path $PSScriptRoot | Join-Path -ChildPath $zipFileName)'

with zipfile.ZipFile(zip_path, 'w', zipfile.ZIP_DEFLATED) as zf:
    for root, dirs, files in os.walk(plugin_dir):
        for f in files:
            full = os.path.join(root, f)
            rel = os.path.relpath(full, plugin_dir)
            arcname = plugin_slug + '/' + rel.replace(os.sep, '/')
            zf.write(full, arcname)
            print(f'  Added: {arcname}')
    entry_count = len(zf.namelist())

print(f'\nZIP created: {zip_path}')
print(f'Entries: {entry_count}')
"@

$pyTmp = [System.IO.Path]::GetTempFileName() + ".py"
Set-Content -Path $pyTmp -Value $pyScript -Encoding UTF8
python $pyTmp
Remove-Item $pyTmp -Force

# 5. Cleanup temp dir
Remove-Item -Path $tempBase -Recurse -Force

# 6. Verify structure using .NET ZipFile (reliable, no COM issues)
# Use the same absolute path built for the Python step above ($zipFileName alone is
# relative and resolves against whatever the shell's cwd happens to be, which caused
# spurious [FAIL] output here even when the ZIP built correctly).
$zipFullPath = Join-Path $PSScriptRoot $zipFileName
Add-Type -Assembly "System.IO.Compression.FileSystem"
$zipFile = [System.IO.Compression.ZipFile]::OpenRead($zipFullPath)
$rootFolders = @{}
foreach ($entry in $zipFile.Entries) {
    $sepIdx = $entry.FullName.IndexOf("/")
    if ($sepIdx -lt 0) { $sepIdx = $entry.FullName.IndexOf("\") }
    if ($sepIdx -gt 0) {
        $root = $entry.FullName.Substring(0, $sepIdx)
        $rootFolders[$root] = $true
    }
}
$zipFile.Dispose()

$rootNames = $rootFolders.Keys -join ", "
Write-Host "`nZIP root folder(s): $rootNames" -ForegroundColor Cyan

if ($rootFolders.Count -eq 1 -and $rootFolders.Keys -contains $pluginSlug) {
    Write-Host "[PASS] Correct: single '$pluginSlug' folder at root." -ForegroundColor Green
} else {
    Write-Host "[FAIL] Expected only '$pluginSlug', got: $rootNames" -ForegroundColor Red
}
