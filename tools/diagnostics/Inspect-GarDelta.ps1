#requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)][string]$DeltaArchive,
    [Parameter(Mandatory)][string]$OutDir,
    [Parameter(Mandatory)][ValidatePattern('^\d{4}-\d{2}-\d{2}$')][string]$ReleaseDate,
    [string]$BaseArchive,
    [string]$BaseCsv,
    [ValidateRange(1,100)][int]$SampleLimit = 10
)
$ErrorActionPreference = 'Stop'
foreach ($path in @($DeltaArchive, $OutDir, $BaseArchive, $BaseCsv)) {
    if ($path -and ($path.StartsWith('\\') -or $path -match '^[a-z]+://')) { throw 'Only local filesystem paths are allowed.' }
}
$date = [datetime]::ParseExact($ReleaseDate, 'yyyy-MM-dd', [cultureinfo]::InvariantCulture)
$delta = (Resolve-Path -LiteralPath $DeltaArchive).Path
$output = [IO.Path]::GetFullPath($OutDir)
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if ($output -eq $repo -or $output.StartsWith($repo + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'OutDir must be outside the repository.'
}
if ((Test-Path -LiteralPath $output) -and @(Get-ChildItem -LiteralPath $output -Force).Count) {
    throw 'OutDir must be empty: refusing to overwrite an earlier investigation.'
}
$base = if ($BaseArchive) { (Resolve-Path -LiteralPath $BaseArchive).Path } else { '' }
$csv = if ($BaseCsv) { (Resolve-Path -LiteralPath $BaseCsv).Path } else { '' }
[IO.Directory]::CreateDirectory($output) | Out-Null
if (-not ('WdcResearch.GarInspector' -as [type])) {
    Add-Type -Path (Join-Path $PSScriptRoot 'GarDeltaInspector.cs')
}
try {
    $inspector = [WdcResearch.GarInspector]::new($SampleLimit, $ReleaseDate, $output)
    $inspector.Run($delta, $base)
    if ($csv) {
        $watch = [Diagnostics.Stopwatch]::StartNew()
        $rows = 0
        $matches = [Collections.Generic.List[object]]::new()
        $affected = [Collections.Generic.List[object]]::new()
        Import-Csv -LiteralPath $csv -Delimiter ';' -Encoding utf8 | ForEach-Object {
            $rows++
            if ($inspector.RelevantIds.Contains([string]$_.gar_object_id)) {
                $inspector.CsvTargetIds.Add([string]$_.gar_object_id) | Out-Null
                if ($matches.Count -lt $SampleLimit) { $matches.Add($_) }
            }
            if ($inspector.ChangedGuids.Contains([string]$_.region_fias_id) -or
                $inspector.ChangedGuids.Contains([string]$_.city_fias_id) -or
                $inspector.ChangedGuids.Contains([string]$_.district_fias_id)) {
                if ($affected.Count -lt $SampleLimit) { $affected.Add($_) }
                $inspector.CsvAncestorHits++
            }
        }
        @{ path=$csv; bytes=(Get-Item -LiteralPath $csv).Length; rows=$rows;
            provenance='User supplied; CSV has no embedded release or exporter evaluation date';
            directly_referenced_targets=$inspector.CsvTargetIds.Count;
            ancestor_change_hits=$inspector.CsvAncestorHits; samples=$matches.ToArray();
            ancestor_samples=$affected.ToArray(); elapsed_seconds=$watch.Elapsed.TotalSeconds
        } | ConvertTo-Json -Depth 15 | Set-Content (Join-Path $output 'base-csv.json') -Encoding utf8
    }
    $inspector.Finish()
    Write-Host "Completed: $output"
} catch {
    @{ success=$false; error=$_.Exception.Message; time_utc=[datetime]::UtcNow.ToString('o') } |
        ConvertTo-Json | Set-Content (Join-Path $output 'failure.json') -Encoding utf8
    throw
}
