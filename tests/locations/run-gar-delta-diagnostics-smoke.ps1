#requires -Version 7.0
$ErrorActionPreference = 'Stop'
$root = Join-Path ([IO.Path]::GetTempPath()) ('wdc-gar-delta-tests-' + [guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($root) | Out-Null
$script = Join-Path $PSScriptRoot '../../tools/diagnostics/Inspect-GarDelta.ps1'
$errors = $null
$tokens = $null
$null = [Management.Automation.Language.Parser]::ParseFile($script, [ref]$tokens, [ref]$errors)
if ($errors.Count) { throw ($errors | Out-String) }
function Assert($value, $message) { if (-not $value) { throw $message } }
function Zip($name, $files) {
    $path = Join-Path $root "$name.zip"
    $zip = [IO.Compression.ZipFile]::Open($path, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($file in $files.GetEnumerator()) {
            $writer = [IO.StreamWriter]::new($zip.CreateEntry($file.Key).Open(), [Text.UTF8Encoding]::new($false))
            try { $writer.Write($file.Value) } finally { $writer.Dispose() }
        }
    } finally { $zip.Dispose() }
    return $path
}
$objects = '<ROOT><OBJECT ID="9007199254740993" OBJECTID="100" OBJECTGUID="test-guid" NAME="Хёлки" TYPENAME="х." LEVEL="2" ISACTUAL="0" ISACTIVE="0"/><OBJECT ID="9007199254740994" OBJECTID="100" OBJECTGUID="test-guid" NAME="Хёлки Новые" TYPENAME="х." LEVEL="2" ISACTUAL="1" ISACTIVE="1"/></ROOT>'
$files = @{
    'version.txt' = "2026.09.08`nv.synthetic"
    '01/AS_ADDR_OBJ_20260907_fixture.XML' = $objects
    '01/AS_ADDR_OBJ_PARAMS_20260907_fixture.XML' = '<ROOT><PARAM ID="3" OBJECTID="100" TYPEID="10" VALUE="00100"/></ROOT>'
    '01/AS_ADM_HIERARCHY_20260907_fixture.XML' = '<ROOT><ITEM ID="4" OBJECTID="100" PARENTOBJID="999" PATH="999.100" ISACTIVE="1"/></ROOT>'
    'AS_PARAM_TYPES_20260907_fixture.XML' = '<ROOT><PARAMTYPE ID="10" CODE="CODE" NAME="КЛАДР" ISACTIVE="true"/></ROOT>'
    'AS_ADDR_OBJ_TYPES_20260907_fixture.XML' = '<ROOT><TYPE ID="88" NAME="not an object"/></ROOT>'
}
$zip = Zip 'valid' $files
& $script -DeltaArchive $zip -OutDir (Join-Path $root 'valid-out') -ReleaseDate 2026-09-08
$s = Get-Content (Join-Path $root 'valid-out/statistics.json') -Raw -Encoding utf8 | ConvertFrom-Json -AsHashtable
Assert ($s.AS_ADDR_OBJ.rows -eq 2) 'Exact family separation failed'
Assert ($s.AS_ADDR_OBJ.objects -eq 1) 'Version/object identity mixed'
Assert ($s.categories_delta_only.A_potential_target_objects -eq 1) 'Non-5/6 place predicate failed'
Assert ($s.categories_delta_only.A_active_actual_target_objects -eq 1) 'Historical row hid active version'
$samples = Get-Content (Join-Path $root 'valid-out/object-version-samples.jsonl') -Raw -Encoding utf8
Assert ($samples.Contains('Хёлки') -and $samples.Contains('9007199254740993')) 'Unicode or ID precision lost'
$missing = Get-Content (Join-Path $root 'valid-out/missing-context.json') -Raw | ConvertFrom-Json
Assert ($missing.absent_from_delta_objects -eq 1) 'Missing parent not reported'
Assert (-not $s.AS_OBJECT_LEVELS.present) 'Missing family mistaken for present empty family'
foreach ($case in @('malformed','dtd')) {
    $text = if ($case -eq 'malformed') { '<ROOT><OBJECT' } else { '<!DOCTYPE ROOT [<!ENTITY x SYSTEM "file:///not-allowed">]><ROOT>&x;</ROOT>' }
    $bad = Zip $case @{ '01/AS_ADDR_OBJ_20260907_fixture.XML'=$text }
    $failed = $false
    try { & $script -DeltaArchive $bad -OutDir (Join-Path $root "$case-out") -ReleaseDate 2026-09-08 } catch { $failed = $true }
    Assert $failed "$case should fail closed"
    Assert (Test-Path (Join-Path $root "$case-out/failure.json")) 'Failure artifact absent'
    Assert (-not (Test-Path (Join-Path $root "$case-out/statistics.json"))) 'Failure published successful stats'
}
$a = [Collections.Generic.Dictionary[string,string]]::new(); $a['ID']='1'; $a['NAME']='Хёлки'
$b = [Collections.Generic.Dictionary[string,string]]::new(); $b['ID']='1'; $b['NAME']='Other'
$list = [Collections.Generic.List[Collections.Generic.Dictionary[string,string]]]::new(); $list.Add($a); $list.Add($a)
Assert ([WdcResearch.GarInspector]::Merge($list).Count -eq 1) 'Repeated version not idempotent'
$list.Add($b); $failed=$false
try { [WdcResearch.GarInspector]::Merge($list) | Out-Null } catch { $failed=$true }
Assert $failed 'Conflicting version silently selected by order'
$baseFiles = @{
    '01/AS_ADDR_OBJ_20260903_fixture.XML' = '<ROOT><OBJECT ID="9007199254740993" OBJECTID="100" OBJECTGUID="test-guid" NAME="Хёлки" TYPENAME="х." LEVEL="2" ISACTUAL="1" ISACTIVE="1"/><OBJECT ID="77" OBJECTID="999" OBJECTGUID="parent-guid" NAME="Район" TYPENAME="р-н" LEVEL="2" ISACTUAL="1" ISACTIVE="1"/></ROOT>'
    '01/AS_ADM_HIERARCHY_20260903_fixture.XML' = '<ROOT><ITEM ID="4" OBJECTID="100" PARENTOBJID="999" PATH="999.100" ISACTIVE="1"/></ROOT>'
    '01/AS_ADDR_OBJ_PARAMS_20260903_fixture.XML' = '<ROOT><PARAM ID="3" OBJECTID="100" TYPEID="10" VALUE="00100"/><PARAM ID="5" OBJECTID="100" TYPEID="6" VALUE="kept"/></ROOT>'
}
$baseZip = Zip 'base' $baseFiles
& $script -DeltaArchive $zip -BaseArchive $baseZip -OutDir (Join-Path $root 'replay-out') -ReleaseDate 2026-09-08
$s = Get-Content (Join-Path $root 'replay-out/statistics.json') -Raw -Encoding utf8 | ConvertFrom-Json -AsHashtable
foreach ($family in @('AS_ADDR_OBJ','AS_ADM_HIERARCHY','AS_ADDR_OBJ_PARAMS')) {
    Assert $s["replay_$family"].idempotent_and_order_independent 'Replay ordering/idempotence failed'
    Assert $s["replay_$family"].absent_version_ids_preserved 'Absence deleted a baseline row'
}
$replay = Get-Content (Join-Path $root 'replay-out/replay-result.json') -Raw -Encoding utf8 | ConvertFrom-Json -AsHashtable
Assert ($replay.source_patch_excludes -contains 'postal_code') 'Enrichment ownership not explicit'
$objectsAfter = @($replay.examples | Where-Object family -eq 'AS_ADDR_OBJ')[0].after
Assert (@($objectsAfter | Where-Object { $_.ISACTUAL -eq '1' -and $_.ISACTIVE -eq '1' }).Count -eq 1) 'Version overlay lost actual row'
Write-Host "PASS: parse, exact families, versions/flags, Int64 text, Cyrillic, missing parent/family, XML/DTD failures, replay identity/conflict. Artifacts: $root"
