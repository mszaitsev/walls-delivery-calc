[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repositoryRoot = Split-Path -Parent $PSScriptRoot
$entryFile = Join-Path $repositoryRoot 'walls-delivery-calc.php'
$entrySource = [IO.File]::ReadAllText($entryFile)
$versionMatch = [regex]::Match($entrySource, '(?m)^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$')
if (-not $versionMatch.Success) {
    throw 'Plugin version was not found in walls-delivery-calc.php.'
}

$version = $versionMatch.Groups[1].Value
$pluginName = 'walls-delivery-calc'
$distDirectory = Join-Path $repositoryRoot 'dist'
$outputPath = Join-Path $distDirectory "$pluginName-$version.zip"
$temporaryRoot = Join-Path ([IO.Path]::GetTempPath()) ("wdc-release-" + [guid]::NewGuid().ToString('N'))
$packageRoot = Join-Path $temporaryRoot $pluginName
$runtimeEntries = @('walls-delivery-calc.php', 'uninstall.php', 'src', 'assets', 'database')

try {
    [IO.Directory]::CreateDirectory($packageRoot) | Out-Null
    foreach ($entry in $runtimeEntries) {
        $source = Join-Path $repositoryRoot $entry
        if (-not (Test-Path -LiteralPath $source)) {
            throw "Required runtime entry is missing: $entry"
        }
        Copy-Item -LiteralPath $source -Destination $packageRoot -Recurse
    }

    if (-not (Test-Path -LiteralPath (Join-Path $packageRoot 'src\Export-GarPlaces.ps1'))) {
        throw 'Runtime admin download src/Export-GarPlaces.ps1 is missing from the package.'
    }

    [IO.Directory]::CreateDirectory($distDirectory) | Out-Null
    if (Test-Path -LiteralPath $outputPath) {
        Remove-Item -LiteralPath $outputPath -Force
    }
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archiveStream = [IO.File]::Open($outputPath, [IO.FileMode]::CreateNew)
    $writeArchive = [IO.Compression.ZipArchive]::new($archiveStream, [IO.Compression.ZipArchiveMode]::Create)
    try {
        Get-ChildItem -LiteralPath $packageRoot -File -Recurse | ForEach-Object {
            $relativePath = $_.FullName.Substring($temporaryRoot.Length + 1).Replace('\', '/')
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
                $writeArchive,
                $_.FullName,
                $relativePath,
                [IO.Compression.CompressionLevel]::Optimal
            ) | Out-Null
        }
    } finally {
        $writeArchive.Dispose()
        $archiveStream.Dispose()
    }

    $archive = [IO.Compression.ZipFile]::OpenRead($outputPath)
    try {
        $names = @($archive.Entries | ForEach-Object { $_.FullName })
        if ($names | Where-Object { $_ -match '\\' }) {
            throw 'ZIP contains Windows path separators and cannot be installed portably.'
        }
        if (-not ($names -contains "$pluginName/walls-delivery-calc.php")) {
            throw 'ZIP does not contain the plugin entry file under the required root folder.'
        }
        if ($names | Where-Object { $_ -match '^walls-delivery-calc/(tests|docs|node_modules|vendor|\.git|\.github)(/|$)' }) {
            throw 'ZIP contains a development-only directory.'
        }
    } finally {
        $archive.Dispose()
    }

    Write-Output $outputPath
} finally {
    $resolvedTemporaryBase = [IO.Path]::GetFullPath([IO.Path]::GetTempPath())
    $resolvedTemporaryRoot = [IO.Path]::GetFullPath($temporaryRoot)
    if ($resolvedTemporaryRoot.StartsWith($resolvedTemporaryBase, [StringComparison]::OrdinalIgnoreCase) -and (Test-Path -LiteralPath $temporaryRoot)) {
        Remove-Item -LiteralPath $temporaryRoot -Recurse -Force
    }
}
