# Packages dist/<slug>/ into dist/<slug>.zip.
#
# Compress-Archive is not used: on some PowerShell versions it writes
# backslash path separators, which the ZIP spec forbids. PHP's unzip then
# treats "slug\file.php" as a filename rather than a directory, so the
# plugin lands loose in wp-content/plugins/ instead of its own folder.
# Entry names are therefore written explicitly with forward slashes.
param([string]$Slug = 'cachearmor')

$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$root = Split-Path -Parent $MyInvocation.MyCommand.Path
$src  = Join-Path $root "dist\$Slug"
$zip  = Join-Path $root "dist\$Slug.zip"

if (-not (Test-Path $src)) { throw "missing $src - run build.sh first" }
if (Test-Path $zip) { Remove-Item $zip -Force }

$archive = [System.IO.Compression.ZipFile]::Open($zip, 'Create')
try {
	Get-ChildItem -Path $src -Recurse -File | Sort-Object FullName | ForEach-Object {
		$rel = $_.FullName.Substring($src.Length).TrimStart([char]92, [char]47).Replace([char]92, [char]47)
		$name = "$Slug/$rel"
		[System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $_.FullName, $name) | Out-Null
	}
} finally {
	$archive.Dispose()
}

Write-Output "packaged dist/$Slug.zip"
