using System;
using System.IO;
using System.IO.Compression;
using System.Xml;
using System.Linq;
using System.Collections.Generic;
using System.Diagnostics;
using System.Security.Cryptography;
using System.Text;
using System.Text.Json;
using System.Text.RegularExpressions;

namespace WdcResearch {
public sealed class GarInspector {
    readonly int limit;
    readonly string release, output;
    readonly Stopwatch clock = Stopwatch.StartNew();
    readonly Dictionary<string, List<Dictionary<string,string>>> data = new();
    readonly Dictionary<string, List<Dictionary<string,string>>> baseline = new();
    readonly Dictionary<string, object> stats = new();
    readonly Dictionary<string, string> baseRegions = new();
    readonly HashSet<string> baselineSamples = new();
    readonly HashSet<string> references = new();
    readonly HashSet<string> deltaObjects = new();
    public HashSet<string> RelevantIds { get; } = new(StringComparer.Ordinal);
    public HashSet<string> ChangedGuids { get; } = new(StringComparer.OrdinalIgnoreCase);
    public HashSet<string> CsvTargetIds { get; } = new(StringComparer.Ordinal);
    public int CsvAncestorHits { get; set; }
    static readonly string[] Core = { "AS_ADDR_OBJ", "AS_ADM_HIERARCHY", "AS_ADDR_OBJ_PARAMS" };
    static readonly string[] Refs = { "AS_PARAM_TYPES", "AS_OPERATION_TYPES", "AS_OBJECT_LEVELS", "AS_ADDR_OBJ_TYPES" };
    static readonly HashSet<string> PlaceTypes = new("г|город|с|село|д|деревня|п|поселок|посёлок|рп|рабочий поселок|рабочий посёлок|пгт|кп|дп|х|хутор|аул|ст-ца|станица|сл|слобода|м|местечко|нп|населенный пункт|населённый пункт".Split('|'));
    static readonly JsonSerializerOptions Json = new() { WriteIndented=true, Encoder=System.Text.Encodings.Web.JavaScriptEncoder.UnsafeRelaxedJsonEscaping };
    public GarInspector(int sampleLimit, string releaseDate, string outDir) { limit=sampleLimit; release=releaseDate; output=outDir; }
    static string V(Dictionary<string,string> row, string key) => row.TryGetValue(key, out var value) ? value : "";
    public static bool Target(Dictionary<string,string> row) {
        return TargetValues(V(row,"LEVEL"),V(row,"TYPENAME"));
    }
    static bool TargetValues(string level,string type) {
        var normalized=(type??"").Trim().ToLowerInvariant();
        while(normalized.EndsWith(".",StringComparison.Ordinal)) normalized=normalized.Substring(0,normalized.Length-1).Trim();
        return level=="5" || level=="6" || PlaceTypes.Contains(normalized);
    }
    public static string Family(string name) {
        var match=Regex.Match(Path.GetFileName(name), @"^(AS_[A-Z_]+?)(?:_\d{8}(?:_|\.)|\.XML$)", RegexOptions.IgnoreCase);
        return match.Success ? match.Groups[1].Value.ToUpperInvariant() : "OTHER";
    }
    static string Region(string name) => name.Contains('/') ? name.Split('/')[0] : "ROOT";
    void Save(string name, object value) => File.WriteAllText(Path.Combine(output,name),JsonSerializer.Serialize(value,Json),new UTF8Encoding(false));
    void Lines(string name, IEnumerable<object> values) {
        using var writer=new StreamWriter(Path.Combine(output,name),false,new UTF8Encoding(false));
        var options=new JsonSerializerOptions(Json) { WriteIndented=false };
        foreach(var value in values) writer.WriteLine(JsonSerializer.Serialize(value,options));
    }
    static Dictionary<string,string> Attributes(XmlReader reader) {
        var row=new Dictionary<string,string>(StringComparer.Ordinal);
        while(reader.MoveToNextAttribute()) row[reader.Name]=reader.Value;
        reader.MoveToElement();
        return row;
    }
    static long Read(ZipArchiveEntry entry, Action<Dictionary<string,string>> action, Func<XmlReader,bool> accept=null) {
        using var stream=entry.Open();
        var settings=new XmlReaderSettings { DtdProcessing=DtdProcessing.Prohibit, XmlResolver=null, IgnoreWhitespace=true };
        using var reader=XmlReader.Create(stream,settings);
        long count=0;
        try {
            while(reader.Read()) if(reader.NodeType==XmlNodeType.Element && reader.Depth==1) {
                count++;
                if(accept==null || accept(reader)) { var row=Attributes(reader); row["_entry"]=entry.FullName; action(row); }
            }
        } catch(Exception ex) { throw new InvalidDataException("XML read failed: "+entry.FullName+": "+ex.Message,ex); }
        return count;
    }
    static object Metadata(ZipArchive zip) => zip.Entries.Where(e=>Regex.IsMatch(e.Name,"version|manifest|\\.xsd$",RegexOptions.IgnoreCase)).Select(e=>new {
        entry=e.FullName, bytes=e.Length, text=e.Length<=65536 && !e.Name.EndsWith(".xsd",StringComparison.OrdinalIgnoreCase) ? SmallText(e) : null
    }).ToArray();
    static string SmallText(ZipArchiveEntry entry) { using var r=new StreamReader(entry.Open(),Encoding.UTF8,true); return r.ReadToEnd(); }
    public void Run(string delta, string full) {
        using(var zip=ZipFile.OpenRead(delta)) {
            var entries=zip.Entries.OrderBy(e=>e.FullName,StringComparer.Ordinal).ToArray();
            using(var stream=File.OpenRead(delta)) Save("archive-manifest.json",new {
                path=delta, bytes=stream.Length, sha256=Convert.ToHexString(SHA256.HashData(stream)), release_date_declared=release,
                observed_at_utc=DateTime.UtcNow.ToString("o"), file_last_write_not_release=File.GetLastWriteTimeUtc(delta).ToString("o"),
                metadata=Metadata(zip), entries=entries.Length, uncompressed_bytes=entries.Sum(e=>e.Length),
                families=entries.GroupBy(e=>Family(e.Name)).ToDictionary(g=>g.Key,g=>(object)new { files=g.Count(),bytes=g.Sum(e=>e.Length),compressed=g.Sum(e=>e.CompressedLength) }),
                regions=entries.GroupBy(e=>Region(e.FullName)).ToDictionary(g=>g.Key,g=>g.Count())
            });
            using(var writer=new StreamWriter(Path.Combine(output,"files.csv"),false,new UTF8Encoding(false))) {
                writer.WriteLine("entry,family,region,bytes,compressed_bytes");
                foreach(var e in entries) writer.WriteLine("\""+e.FullName.Replace("\"","\"\"")+"\","+Family(e.Name)+","+Region(e.FullName)+","+e.Length+","+e.CompressedLength);
            }
            foreach(var family in Refs.Concat(Core)) {
                var selected=entries.Where(e=>Family(e.Name)==family).ToArray();
                var rows=new List<Dictionary<string,string>>(); data[family]=rows;
                Console.WriteLine("Delta "+family+": "+selected.Length+" files");
                foreach(var entry in selected) Read(entry,r=>rows.Add(r));
                stats[family]=Describe(rows,selected.Length);
            }
            var refs=data.Where(k=>Refs.Contains(k.Key)).ToDictionary(k=>k.Key,k=>k.Value);
            Save("reference-records.json",refs);
            foreach(var row in data["AS_ADDR_OBJ"]) { deltaObjects.Add(V(row,"OBJECTID")); ChangedGuids.Add(V(row,"OBJECTGUID")); }
            ChangedGuids.Remove("");
            foreach(var row in Core.SelectMany(f=>data[f])) RelevantIds.Add(V(row,"OBJECTID"));
            RelevantIds.Remove("");
            foreach(var row in data["AS_ADM_HIERARCHY"]) {
                references.Add(V(row,"PARENTOBJID"));
                foreach(var id in Regex.Split(V(row,"PATH"),@"[./,]")) if(id!="") references.Add(id);
            }
            references.Remove(""); references.Remove("0");
            var parents=new HashSet<string>(data["AS_ADM_HIERARCHY"].Select(r=>V(r,"PARENTOBJID")).Where(x=>x!=""&&x!="0"));
            Save("missing-context.json",new {
                parent_path_ids=references.Count, absent_from_delta_objects=references.Except(deltaObjects).Count(),
                direct_parent_ids=parents.Count,direct_parents_absent_from_delta=parents.Except(deltaObjects).Count(),
                direct_parent_samples=parents.Except(deltaObjects).OrderBy(x=>x,StringComparer.Ordinal).Take(limit),
                absent_samples=references.Except(deltaObjects).OrderBy(x=>x,StringComparer.Ordinal).Take(limit),
                hierarchy_objects_without_object_card=data["AS_ADM_HIERARCHY"].Select(r=>V(r,"OBJECTID")).Distinct().Except(deltaObjects).Count(),
                parameter_objects_without_object_card=data["AS_ADDR_OBJ_PARAMS"].Select(r=>V(r,"OBJECTID")).Distinct().Except(deltaObjects).Count(),
                note="Absence from delta is not absence from GAR; counts include non-address entities in shared hierarchy."
            });
            Samples();
            // Shared hierarchy/registry contain houses too. Count all, keep only diagnostic samples.
            foreach(var family in new[]{"AS_REESTR_OBJECTS","AS_CHANGE_HISTORY"}) {
                long count=0; var levels=new Dictionary<string,long>(); var samples=new List<Dictionary<string,string>>();
                foreach(var entry in entries.Where(e=>Family(e.Name)==family)) count+=Read(entry,r=>{
                    var key=V(r,"LEVELID"); levels[key]=levels.GetValueOrDefault(key)+1;
                    if(samples.Count<limit && deltaObjects.Contains(V(r,"OBJECTID"))) samples.Add(r);
                });
                stats[family]=new { present=entries.Any(e=>Family(e.Name)==family),rows=count,levels,samples };
            }
        }
        if(full!="") ReadBaseline(full);
    }
    static object Distribution(List<Dictionary<string,string>> rows,string field) => rows.GroupBy(r=>V(r,field)).ToDictionary(g=>g.Key,g=>g.Count());
    static object Describe(List<Dictionary<string,string>> rows,int files) => new {
        present=files>0, files, rows=rows.Count,
        fields=rows.SelectMany(r=>r.Keys).Where(k=>k!="_entry").Distinct().OrderBy(x=>x).ToArray(),
        objects=rows.Select(r=>V(r,"OBJECTID")).Where(x=>x!="").Distinct().Count(),
        guids=rows.Select(r=>V(r,"OBJECTGUID")).Where(x=>x!="").Distinct().Count(),
        duplicate_version_ids=rows.Where(r=>V(r,"ID")!="").GroupBy(r=>V(r,"ID")).Count(g=>g.Count()>1),
        conflicting_version_ids=rows.Where(r=>V(r,"ID")!="").GroupBy(r=>V(r,"ID")).Count(g=>g.Select(Fingerprint).Distinct().Count()>1),
        levels=Distribution(rows,"LEVEL"),types=Distribution(rows,"TYPENAME"),operations=Distribution(rows,"OPERTYPEID"),param_types=Distribution(rows,"TYPEID"),
        flags=rows.GroupBy(r=>V(r,"ISACTUAL")+"/"+V(r,"ISACTIVE")).ToDictionary(g=>g.Key,g=>g.Count()),
        dates=new[]{"UPDATEDATE","STARTDATE","ENDDATE"}.ToDictionary(k=>k,k=>(object)new {
            min=rows.Select(r=>V(r,k)).Where(x=>x!="").OrderBy(x=>x,StringComparer.Ordinal).FirstOrDefault(),
            max=rows.Select(r=>V(r,k)).Where(x=>x!="").OrderByDescending(x=>x,StringComparer.Ordinal).FirstOrDefault()
        })
    };
    static string Fingerprint(Dictionary<string,string> row) => string.Join("\n",row.Where(p=>p.Key!="_entry").OrderBy(p=>p.Key,StringComparer.Ordinal).Select(p=>p.Key+"="+p.Value));
    void Samples() {
        var objects=data["AS_ADDR_OBJ"].GroupBy(r=>V(r,"OBJECTID")).OrderBy(g=>g.Key,StringComparer.Ordinal).ToArray();
        var examples=new List<object>();
        void Add(string category,IEnumerable<IGrouping<string,Dictionary<string,string>>> groups) {
            foreach(var g in groups.Take(limit)) examples.Add(new { category,object_id=g.Key,versions=g.ToArray() });
        }
        Add("multiple_versions",objects.Where(g=>g.Count()>1));
        Add("name_or_type_variation",objects.Where(g=>g.Select(r=>V(r,"NAME")+"|"+V(r,"TYPENAME")).Distinct().Count()>1));
        Add("actual_inactive",objects.Where(g=>g.Any(r=>V(r,"ISACTUAL")=="1" && V(r,"ISACTIVE")=="0")));
        Add("potential_target_not_proven_new",objects.Where(g=>g.Any(Target)));
        Lines("object-version-samples.jsonl",examples);
        foreach(var family in new[]{"AS_ADM_HIERARCHY","AS_ADDR_OBJ_PARAMS"}) {
            var groups=data[family].GroupBy(r=>V(r,"OBJECTID")+(family.EndsWith("PARAMS")?"|"+V(r,"TYPEID"):""));
            var selected=groups.OrderByDescending(g=>g.Count()>1).ThenByDescending(g=>deltaObjects.Contains(V(g.First(),"OBJECTID"))).ThenBy(g=>g.Key,StringComparer.Ordinal).Take(limit);
            Lines(family.EndsWith("PARAMS")?"parameter-samples.jsonl":"hierarchy-samples.jsonl",selected.Select(g=>(object)new { key=g.Key,versions=g.ToArray() }));
        }
        stats["categories_delta_only"]=new {
            A_potential_target_objects=objects.Count(g=>g.Any(Target)),
            A_active_actual_target_objects=objects.Count(g=>g.Any(r=>Target(r)&&V(r,"ISACTUAL")=="1"&&V(r,"ISACTIVE")=="1")),
            B_changed_objects_used_as_direct_parent=data["AS_ADM_HIERARCHY"].Select(r=>V(r,"PARENTOBJID")).Distinct().Intersect(deltaObjects).Count(),
            C_hierarchy_objects=data["AS_ADM_HIERARCHY"].Select(r=>V(r,"OBJECTID")).Distinct().Count(),
            D_parameter_objects=data["AS_ADDR_OBJ_PARAMS"].Where(r=>new[]{"6","7","10"}.Contains(V(r,"TYPEID"))).Select(r=>V(r,"OBJECTID")).Distinct().Count(),
            note="Overlapping sets, not a NEW/CHANGED/REMOVED classification. D uses exporter fallback IDs; verify reference-records."
        };
    }
    void ReadBaseline(string path) {
        using var zip=ZipFile.OpenRead(path);
        var sw=Stopwatch.StartNew();
        Save("base-manifest.json",new {path,bytes=new FileInfo(path).Length,metadata=Metadata(zip),sha256="Not computed for large baseline"});
        var wanted=new HashSet<string>(RelevantIds); wanted.UnionWith(references);
        var rows=new List<Dictionary<string,string>>(); baseline["AS_ADDR_OBJ"]=rows;
        long scanned=0, activeTargets=0, activeContext=0; int files=0;
        foreach(var e in zip.Entries.Where(e=>Family(e.Name)=="AS_ADDR_OBJ").OrderBy(e=>e.FullName,StringComparer.Ordinal)) {
            Console.WriteLine("Baseline objects "+e.FullName);
            scanned+=Read(e,r=>{rows.Add(r);baseRegions[V(r,"OBJECTID")]=Region(e.FullName);},r=>{
                if(r.GetAttribute("ISACTUAL")=="1" && r.GetAttribute("ISACTIVE")=="1") {
                    var target=TargetValues(r.GetAttribute("LEVEL"),r.GetAttribute("TYPENAME"));
                    if(target) activeTargets++;
                    int.TryParse(r.GetAttribute("LEVEL"),out var level);
                    var type=r.GetAttribute("TYPENAME")??"";
                    if(target || (level>=1 && level<=8) || type.Contains("район") || type.Contains("р-н")) activeContext++;
                }
                return wanted.Contains(r.GetAttribute("OBJECTID")??"");
            }); files++;
        }
        var targetIds=new HashSet<string>(rows.Where(Target).Select(r=>V(r,"OBJECTID")));
        targetIds.UnionWith(data["AS_ADDR_OBJ"].Where(Target).Select(r=>V(r,"OBJECTID")));
        // Baseline hierarchy is ~41 GB uncompressed. Inspect a deterministic bounded object cohort, in one pass per selected region.
        var priority=data["AS_ADDR_OBJ"].Where(Target).Select(r=>V(r,"OBJECTID")).Distinct().OrderBy(x=>x,StringComparer.Ordinal)
            .Concat(targetIds.Where(RelevantIds.Contains).OrderBy(x=>x,StringComparer.Ordinal)).Distinct();
        foreach(var id in priority.Take(limit)) baselineSamples.Add(id);
        var regions=new HashSet<string>(baselineSamples.Where(baseRegions.ContainsKey).Select(id=>baseRegions[id]));
        var hierarchy=new List<Dictionary<string,string>>(); baseline["AS_ADM_HIERARCHY"]=hierarchy;
        long hierarchyScanned=0, hierarchyBytes=0;
        foreach(var e in zip.Entries.Where(e=>Family(e.Name)=="AS_ADM_HIERARCHY" && regions.Contains(Region(e.FullName)))) {
            Console.WriteLine("Baseline hierarchy "+e.FullName);
            hierarchyBytes+=e.Length; hierarchyScanned+=Read(e,r=>hierarchy.Add(r),r=>baselineSamples.Contains(r.GetAttribute("OBJECTID")??""));
        }
        var paramsWanted=new HashSet<string>(baselineSamples);
        foreach(var r in hierarchy.Concat(data["AS_ADM_HIERARCHY"].Where(r=>baselineSamples.Contains(V(r,"OBJECTID"))))) foreach(var id in Regex.Split(V(r,"PATH"),@"[./,]")) paramsWanted.Add(id);
        var oldAncestorsMissing=new HashSet<string>(paramsWanted.Except(rows.Select(r=>V(r,"OBJECTID"))).Where(x=>x!=""));
        long ancestorScan=0;
        if(oldAncestorsMissing.Count>0) foreach(var e in zip.Entries.Where(e=>Family(e.Name)=="AS_ADDR_OBJ" && regions.Contains(Region(e.FullName)))) {
            Console.WriteLine("Baseline old-ancestor closure "+e.FullName);
            ancestorScan+=Read(e,r=>rows.Add(r),r=>oldAncestorsMissing.Contains(r.GetAttribute("OBJECTID")??""));
        }
        var parameters=new List<Dictionary<string,string>>(); baseline["AS_ADDR_OBJ_PARAMS"]=parameters;
        long paramsScanned=0;
        foreach(var e in zip.Entries.Where(e=>Family(e.Name)=="AS_ADDR_OBJ_PARAMS" && regions.Contains(Region(e.FullName)))) {
            Console.WriteLine("Baseline parameters "+e.FullName);
            paramsScanned+=Read(e,r=>parameters.Add(r),r=>paramsWanted.Contains(r.GetAttribute("OBJECTID")??"") && new[]{"6","7","10"}.Contains(r.GetAttribute("TYPEID")??""));
        }
        var baseIds=new HashSet<string>(rows.Select(r=>V(r,"OBJECTID")));
        var currentTargets=new HashSet<string>(rows.Where(r=>Target(r)&&V(r,"ISACTUAL")=="1"&&V(r,"ISACTIVE")=="1").Select(r=>V(r,"OBJECTID")));
        Save("classification.json",new {
            baseline_current_target_rows_total=activeTargets,baseline_current_context_rows_total=activeContext,
            delta_object_targets_in_baseline=currentTargets.Intersect(deltaObjects).OrderBy(x=>x).ToArray(),
            hierarchy_current_target_ids=data["AS_ADM_HIERARCHY"].Select(r=>V(r,"OBJECTID")).Distinct().Intersect(currentTargets).OrderBy(x=>x).ToArray(),
            parameter_current_target_ids=data["AS_ADDR_OBJ_PARAMS"].Select(r=>V(r,"OBJECTID")).Distinct().Intersect(currentTargets).OrderBy(x=>x).ToArray(),
            context_only_samples=baselineSamples.Where(id=>!currentTargets.Contains(id)).Select(id=>new {object_id=id,versions=rows.Where(r=>V(r,"OBJECTID")==id).ToArray()}),
            sample_ancestor_cards=rows.Where(r=>paramsWanted.Contains(V(r,"OBJECTID")) && !baselineSamples.Contains(V(r,"OBJECTID")) && V(r,"ISACTUAL")=="1").Take(limit),
            missing_sample_ancestor_cards=paramsWanted.Where(x=>x!="").Except(baseIds).Except(deltaObjects).OrderBy(x=>x).ToArray(),
            note="Counts of current rows, not deduplicated full-source integrity validation. Historical targets retained separately for transitions."
        });
        Save("baseline-context.json",new {
            objects_scanned=scanned, object_files=files, retained_objects=rows.Select(r=>V(r,"OBJECTID")).Distinct().Count(), retained_versions=rows.Count,
            referenced_ids_not_in_baseline_addr=wanted.Except(rows.Select(r=>V(r,"OBJECTID"))).Count(),
            target_ids_with_delta_reference=targetIds.Count, sample_ids=baselineSamples.OrderBy(x=>x).ToArray(), regions=regions.OrderBy(x=>x).ToArray(),
            delta_target_ids_absent_from_full_addr=data["AS_ADDR_OBJ"].Where(Target).Select(r=>V(r,"OBJECTID")).Distinct().Except(baseIds).ToArray(),
            hierarchy_target_ids=data["AS_ADM_HIERARCHY"].Select(r=>V(r,"OBJECTID")).Distinct().Intersect(targetIds).Count(),
            parameter_target_ids=data["AS_ADDR_OBJ_PARAMS"].Select(r=>V(r,"OBJECTID")).Distinct().Intersect(targetIds).Count(),
            parameter_only_target_ids=data["AS_ADDR_OBJ_PARAMS"].Select(r=>V(r,"OBJECTID")).Distinct().Intersect(targetIds).Except(deltaObjects).Count(),
            hierarchy_rows_scanned=hierarchyScanned,hierarchy_bytes_scanned=hierarchyBytes,hierarchy_retained=hierarchy.Count,
            additional_old_ancestor_scan_rows=ancestorScan,
            params_rows_scanned=paramsScanned,params_retained=parameters.Count, elapsed_seconds=sw.Elapsed.TotalSeconds,
            note="Missing IDs may be houses/non-address entities, not missing locations. No full baseline hash or extraction."
        });
        Replay();
    }
    public static Dictionary<string,Dictionary<string,string>> Merge(IEnumerable<Dictionary<string,string>> rows) {
        var result=new Dictionary<string,Dictionary<string,string>>();
        foreach(var row in rows) {
            var id=V(row,"ID"); if(id=="") throw new InvalidDataException("Missing record-version ID");
            if(result.TryGetValue(id,out var old) && Fingerprint(old)!=Fingerprint(row)) throw new InvalidDataException("Conflicting record-version ID "+id);
            result[id]=row;
        }
        return result;
    }
    static Dictionary<string,Dictionary<string,string>> Overlay(IEnumerable<Dictionary<string,string>> old, IEnumerable<Dictionary<string,string>> delta) {
        var result=Merge(old); foreach(var pair in Merge(delta)) result[pair.Key]=pair.Value; return result;
    }
    void Replay() {
        var examples=new List<object>();
        foreach(var family in Core) {
            var ids= family=="AS_ADDR_OBJ" ? new HashSet<string>(data[family].Select(r=>V(r,"OBJECTID"))) : baselineSamples;
            var old=baseline[family].Where(r=>ids.Contains(V(r,"OBJECTID"))).ToArray();
            var delta=data[family].Where(r=>ids.Contains(V(r,"OBJECTID")) && (family!="AS_ADDR_OBJ_PARAMS" || new[]{"6","7","10"}.Contains(V(r,"TYPEID")))).ToArray();
            var once=Overlay(old,delta); var twice=Overlay(once.Values,delta); var reverse=Overlay(old,delta.Reverse());
            var equal=once.OrderBy(p=>p.Key).Select(p=>Fingerprint(p.Value)).SequenceEqual(twice.OrderBy(p=>p.Key).Select(p=>Fingerprint(p.Value))) &&
                once.OrderBy(p=>p.Key).Select(p=>Fingerprint(p.Value)).SequenceEqual(reverse.OrderBy(p=>p.Key).Select(p=>Fingerprint(p.Value)));
            var groups=old.Concat(delta).Select(r=>V(r,"OBJECTID")).Distinct().Where(id=>delta.Any(r=>V(r,"OBJECTID")==id))
                .OrderByDescending(id=>data["AS_ADDR_OBJ"].Any(r=>V(r,"OBJECTID")==id&&Target(r))).ThenBy(id=>id,StringComparer.Ordinal).Take(limit);
            foreach(var id in groups) examples.Add(new {family,object_id=id,before=old.Where(r=>V(r,"OBJECTID")==id),delta=delta.Where(r=>V(r,"OBJECTID")==id),after=once.Values.Where(r=>V(r,"OBJECTID")==id)});
            stats["replay_"+family]=new { before=old.Length,delta=delta.Length,after=once.Count,idempotent_and_order_independent=equal,
                absent_version_ids_preserved=Merge(old).Keys.Except(Merge(delta).Keys).All(once.ContainsKey) };
        }
        Save("replay-result.json",new { kind="Sample record-version overlay, not a complete WDC projector", assessment_date=release,
            date_policy="No temporal selection performed. ReleaseDate is metadata, not DateTime.Today or implicit validity cutoff.",
            conflict_policy="Different payloads for same ID within one input fail; release delta replaces baseline version by ID, without max-ID selection.",
            source_patch_excludes=new[]{"postal_code","latitude","longitude","russianpost_courier_calc_postal_code"}, examples });
    }
    public void Finish() {
        stats["base_csv_target_intersection"]=CsvTargetIds.Count;
        stats["base_csv_ancestor_hits"]=CsvAncestorHits;
        stats["run"]=new {success=true,elapsed_seconds=clock.Elapsed.TotalSeconds,peak_working_set_bytes=Process.GetCurrentProcess().PeakWorkingSet64,
            managed_bytes=GC.GetTotalMemory(false),release_date_declared=release,version_selection="None; all observed versions retained",sample_limit=limit};
        Save("statistics.json",stats);
    }
}
}
