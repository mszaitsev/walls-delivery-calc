<#
.SYNOPSIS
  Экспортирует компактный список населённых пунктов из полной ГАР/FIAS XML-выгрузки.

.DESCRIPTION
  Скрипт читает большой ZIP-архив напрямую, без полной распаковки, и использует только:
  - AS_ADDR_OBJ_*.XML
  - AS_ADM_HIERARCHY_*.XML
  - AS_ADDR_OBJ_PARAMS_*.XML
  - AS_PARAM_TYPES_*.XML, если он есть в архиве

  На выходе формируется CSV с регионами, районами, городами/населёнными пунктами, OBJECTGUID/FIAS ID,
  OBJECTID/GAR ID и KLADR ID. Дома, квартиры, участки, комнаты и документы не обрабатываются.

.EXAMPLE
  pwsh -ExecutionPolicy Bypass -File .\Export-GarPlaces.ps1 `
    -Archive "D:\FIAS\gar_xml_full.zip" `
    -OutCsv "D:\FIAS\out\gar_places.csv" `
    -IncludeOptionalCodes
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$Archive,

    [Parameter(Mandatory = $true)]
    [string]$OutCsv,

    # Для актуального GAR обычно: 5 = город, 6 = населённый пункт/местность.
    # Города федерального значения уровня 1 с типом "г" добавляются автоматически.
    [string]$PlaceLevels = "5,6",

    # Добавить OKATO, OKTMO и колонку postal_code.
    # postal_code намеренно оставляется пустым: индексы будут получаться позднее через DaData API.
    [switch]$IncludeOptionalCodes
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

$archivePath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Archive)
if (-not (Test-Path -LiteralPath $archivePath)) {
    throw "Archive not found: $archivePath"
}

$outCsvPath = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($OutCsv)
$outDir = Split-Path -Parent $outCsvPath
if ($outDir -and -not (Test-Path -LiteralPath $outDir)) {
    New-Item -ItemType Directory -Path $outDir | Out-Null
}

$cs = @'
using System;
using System.Collections.Generic;
using System.Globalization;
using System.IO;
using System.IO.Compression;
using System.Text;
using System.Xml;

public static class GarPlacesExtractor
{
    private sealed class Obj
    {
        public long ObjectId;
        public string Guid;
        public string Name;
        public string TypeName;
        public int Level;
        public string RegionCode;
        public string AdmPath;
        public string Kladr;
        public string Okato;
        public string Oktmo;
        public string PostalCode;
        public DateTime KladrStart;
        public DateTime OkatoStart;
        public DateTime OktmoStart;
        public DateTime PostalStart;
    }

    private static Dictionary<long, Obj> Objects;
    private static Dictionary<long, byte> TargetIds;
    private static Dictionary<long, byte> ParamWantedIds;
    private static Dictionary<int, byte> PlaceLevels;

    // Fallback для текущей практической структуры GAR: KLADR=10, postal=5, OKATO=6, OKTMO=7.
    // Если AS_PARAM_TYPES есть в архиве, значения уточняются по нему.
    private static int KladrTypeId = 10;
    private static int PostalTypeId = 5;
    private static int OkatoTypeId = 6;
    private static int OktmoTypeId = 7;

    public static void Run(string archivePath, string outCsvPath, string placeLevelsCsv, bool includeOptionalCodes)
    {
        Objects = new Dictionary<long, Obj>(300000);
        TargetIds = new Dictionary<long, byte>();
        ParamWantedIds = new Dictionary<long, byte>();
        PlaceLevels = ParseLevels(placeLevelsCsv);

        using (FileStream fs = new FileStream(archivePath, FileMode.Open, FileAccess.Read, FileShare.Read))
        using (ZipArchive zip = new ZipArchive(fs, ZipArchiveMode.Read))
        {
            Console.WriteLine("1/4 Reading AS_PARAM_TYPES...");
            ParseParamTypes(zip);
            Console.WriteLine("Detected/fallback TYPEID: KLADR=" + KladrTypeId + ", postal=" + PostalTypeId + ", OKATO=" + OkatoTypeId + ", OKTMO=" + OktmoTypeId);

            Console.WriteLine("2/4 Reading AS_ADDR_OBJ...");
            ParseAddrObj(zip);
            Console.WriteLine("Objects kept: " + Objects.Count + "; target places: " + TargetIds.Count);

            Console.WriteLine("3/4 Reading AS_ADDR_OBJ_PARAMS...");
            ParseParams(zip, includeOptionalCodes);

            Console.WriteLine("4/4 Reading AS_ADM_HIERARCHY...");
            ParseAdmHierarchy(zip);
        }

        WriteCsv(outCsvPath, includeOptionalCodes);
        Console.WriteLine("Done: " + outCsvPath);
    }

    private static Dictionary<int, byte> ParseLevels(string csv)
    {
        Dictionary<int, byte> set = new Dictionary<int, byte>();
        string[] parts = (csv ?? "5,6").Split(new char[] { ',', ';', ' ' }, StringSplitOptions.RemoveEmptyEntries);
        foreach (string p in parts)
        {
            int n;
            if (Int32.TryParse(p.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out n))
                set[n] = 1;
        }
        if (set.Count == 0)
        {
            set[5] = 1;
            set[6] = 1;
        }
        return set;
    }

    private static void ParseEntries(ZipArchive zip, string logicalName, Action<XmlReader, string> onElement)
    {
        foreach (ZipArchiveEntry entry in zip.Entries)
        {
            string entryName = entry.FullName.Replace('\\', '/');
            if (!IsDatedGarXmlFile(entryName, logicalName))
                continue;

            Console.WriteLine("  " + entryName);
            using (Stream s = entry.Open())
            {
                XmlReaderSettings settings = new XmlReaderSettings();
                settings.IgnoreWhitespace = true;
                settings.IgnoreComments = true;
                settings.DtdProcessing = DtdProcessing.Ignore;

                using (XmlReader r = XmlReader.Create(s, settings))
                {
                    while (r.Read())
                    {
                        if (r.NodeType != XmlNodeType.Element)
                            continue;
                        onElement(r, entryName);
                    }
                }
            }
        }
    }

    private static bool IsDatedGarXmlFile(string entryName, string logicalName)
    {
        if (String.IsNullOrEmpty(entryName) || String.IsNullOrEmpty(logicalName))
            return false;

        int slash = entryName.LastIndexOf('/');
        string fileName = slash >= 0 ? entryName.Substring(slash + 1) : entryName;
        if (!fileName.EndsWith(".XML", StringComparison.OrdinalIgnoreCase))
            return false;

        string prefix = logicalName + "_";
        if (!fileName.StartsWith(prefix, StringComparison.OrdinalIgnoreCase))
            return false;

        int pos = prefix.Length;
        if (fileName.Length <= pos + 8 || fileName[pos + 8] != '_')
            return false;

        for (int i = 0; i < 8; i++)
        {
            char c = fileName[pos + i];
            if (c < '0' || c > '9')
                return false;
        }
        return true;
    }

    private static void ParseParamTypes(ZipArchive zip)
    {
        ParseEntries(zip, "AS_PARAM_TYPES", delegate(XmlReader r, string entryName)
        {
            if (!Eq(r.LocalName, "PARAMTYPE"))
                return;

            string active = A(r, "ISACTIVE");
            if (!String.IsNullOrEmpty(active) && active != "1")
                return;

            int id;
            if (!Int32.TryParse(A(r, "ID"), NumberStyles.Integer, CultureInfo.InvariantCulture, out id))
                return;

            string hay = (A(r, "CODE") + " " + A(r, "NAME") + " " + A(r, "DESC")).ToUpperInvariant();
            if (hay.Contains("KLADR") || hay.Contains("КЛАДР")) KladrTypeId = id;
            if (hay.Contains("POST") || hay.Contains("ПОЧТ") || hay.Contains("ИНДЕКС")) PostalTypeId = id;
            if (hay.Contains("OKATO") || hay.Contains("ОКАТО")) OkatoTypeId = id;
            if (hay.Contains("OKTMO") || hay.Contains("ОКТМО")) OktmoTypeId = id;
        });
    }

    private static void ParseAddrObj(ZipArchive zip)
    {
        ParseEntries(zip, "AS_ADDR_OBJ", delegate(XmlReader r, string entryName)
        {
            if (!Eq(r.LocalName, "OBJECT"))
                return;

            if (A(r, "ISACTUAL") != "1" || A(r, "ISACTIVE") != "1")
                return;

            int level;
            long objectId;
            if (!Int32.TryParse(A(r, "LEVEL"), NumberStyles.Integer, CultureInfo.InvariantCulture, out level))
                return;
            if (!Int64.TryParse(A(r, "OBJECTID"), NumberStyles.Integer, CultureInfo.InvariantCulture, out objectId))
                return;

            string typeName = A(r, "TYPENAME");
            string name = A(r, "NAME");

            // В свежих GAR-выгрузках часть реальных населённых пунктов может иметь LEVEL не 5/6.
            // Пример: г. Нижний Новгород в выгрузке 2026-05-18 имеет LEVEL=2, TYPENAME="г.".
            // Поэтому целевой объект определяется не только по LEVEL, но и по типу населённого пункта.
            bool isFederalCity = (level == 1 && IsCityLikeType(typeName));
            bool isTargetByLevel = PlaceLevels.ContainsKey(level);
            bool isTargetByType = IsPlaceType(typeName);
            bool isTarget = isTargetByLevel || isTargetByType || isFederalCity;

            // Для построения region/district/city/place держим широкий контекст 1-8.
            // Таргетом в CSV становятся только isTarget-объекты.
            bool keep = isTarget || (level >= 1 && level <= 8) || IsDistrictType(typeName);
            if (!keep)
                return;

            Obj o = new Obj();
            o.ObjectId = objectId;
            o.Guid = A(r, "OBJECTGUID");
            o.Name = name;
            o.TypeName = typeName;
            o.Level = level;
            o.KladrStart = DateTime.MinValue;
            o.OkatoStart = DateTime.MinValue;
            o.OktmoStart = DateTime.MinValue;
            o.PostalStart = DateTime.MinValue;
            o.PostalCode = "";
            Objects[objectId] = o;

            if (isTarget)
                TargetIds[objectId] = 1;

            // KLADR нужен таргетам, регионам, районам и городам-предкам.
            // Сам район становится известен после разбора PATH, поэтому для простоты берём KLADR по всем сохранённым уровням 1-6.
            ParamWantedIds[objectId] = 1;
        });
    }

    private static void ParseParams(ZipArchive zip, bool includeOptionalCodes)
    {
        ParseEntries(zip, "AS_ADDR_OBJ_PARAMS", delegate(XmlReader r, string entryName)
        {
            if (!Eq(r.LocalName, "PARAM"))
                return;

            long objectId;
            int typeId;
            if (!Int64.TryParse(A(r, "OBJECTID"), NumberStyles.Integer, CultureInfo.InvariantCulture, out objectId))
                return;
            if (!ParamWantedIds.ContainsKey(objectId))
                return;
            if (!Int32.TryParse(A(r, "TYPEID"), NumberStyles.Integer, CultureInfo.InvariantCulture, out typeId))
                return;

            bool useful = (typeId == KladrTypeId) || (includeOptionalCodes && (typeId == PostalTypeId || typeId == OkatoTypeId || typeId == OktmoTypeId));
            if (!useful)
                return;
            if (!IsCurrent(A(r, "STARTDATE"), A(r, "ENDDATE")))
                return;

            Obj o;
            if (!Objects.TryGetValue(objectId, out o))
                return;

            string value = A(r, "VALUE");
            DateTime start = ParseDateOrMin(A(r, "STARTDATE"));

            if (typeId == KladrTypeId && start >= o.KladrStart) { o.Kladr = value; o.KladrStart = start; }
            // postal_code для населённых пунктов намеренно не заполняем из GAR.
            // В GAR индексы часто находятся на уровне домов, а не на уровне населённого пункта.
            // Индексы будут получаться позднее через DaData API.
            else if (includeOptionalCodes && typeId == PostalTypeId) { }
            else if (includeOptionalCodes && typeId == OkatoTypeId && start >= o.OkatoStart) { o.Okato = value; o.OkatoStart = start; }
            else if (includeOptionalCodes && typeId == OktmoTypeId && start >= o.OktmoStart) { o.Oktmo = value; o.OktmoStart = start; }
        });
    }

    private static void ParseAdmHierarchy(ZipArchive zip)
    {
        ParseEntries(zip, "AS_ADM_HIERARCHY", delegate(XmlReader r, string entryName)
        {
            if (!Eq(r.LocalName, "ITEM"))
                return;
            if (A(r, "ISACTIVE") != "1")
                return;

            long objectId;
            if (!Int64.TryParse(A(r, "OBJECTID"), NumberStyles.Integer, CultureInfo.InvariantCulture, out objectId))
                return;
            if (!TargetIds.ContainsKey(objectId))
                return;

            Obj o;
            if (!Objects.TryGetValue(objectId, out o))
                return;

            o.RegionCode = A(r, "REGIONCODE");
            o.AdmPath = A(r, "PATH");
        });
    }

    private static void WriteCsv(string outCsvPath, bool includeOptionalCodes)
    {
        List<Obj> rows = new List<Obj>(TargetIds.Count);
        foreach (long id in TargetIds.Keys)
        {
            Obj o;
            if (Objects.TryGetValue(id, out o))
                rows.Add(o);
        }

        rows.Sort(delegate(Obj a, Obj b)
        {
            Obj ar = FindAncestor(a, 1, false);
            Obj br = FindAncestor(b, 1, false);
            int c = String.Compare(Safe(ar == null ? "" : ar.Name), Safe(br == null ? "" : br.Name), StringComparison.CurrentCultureIgnoreCase);
            if (c != 0) return c;
            c = String.Compare(Safe(a.Name), Safe(b.Name), StringComparison.CurrentCultureIgnoreCase);
            if (c != 0) return c;
            return a.ObjectId.CompareTo(b.ObjectId);
        });

        int missingKladr = 0;
        Encoding utf8NoBom = new UTF8Encoding(false);
        using (StreamWriter w = new StreamWriter(outCsvPath, false, utf8NoBom))
        {
            string header = "region_code;region_name;region_type;region_fias_id;region_kladr_id;district_name;district_type;district_fias_id;district_kladr_id;district_gar_object_id;district_level;city_name;city_type;city_fias_id;city_kladr_id;place_name;place_type;place_level;display_name;fias_id;gar_object_id;kladr_id";
            if (includeOptionalCodes) header += ";okato;oktmo;postal_code";
            w.WriteLine(header);

            foreach (Obj place in rows)
            {
                Obj region = FindAncestor(place, 1, false);
                if (region == null && place.Level == 1) region = place;

                Obj city = null;
                if (IsCityLikeType(place.TypeName)) city = place;
                else city = FindAncestor(place, 5, true);
                if (city == null && place.Level == 5) city = place;

                Obj district = FindDistrict(place, region, city);

                string regionCode = FirstNonEmpty(place.RegionCode, RegionCodeFromKladr(region == null ? null : region.Kladr), RegionCodeFromKladr(place.Kladr));
                string display = BuildDisplay(region, district, city, place);
                if (String.IsNullOrEmpty(place.Kladr)) missingKladr++;

                StringBuilder line = new StringBuilder(512);
                Add(line, regionCode);
                Add(line, region == null ? "" : region.Name);
                Add(line, region == null ? "" : region.TypeName);
                Add(line, region == null ? "" : region.Guid);
                Add(line, region == null ? "" : region.Kladr);
                Add(line, district == null ? "" : district.Name);
                Add(line, district == null ? "" : district.TypeName);
                Add(line, district == null ? "" : district.Guid);
                Add(line, district == null ? "" : district.Kladr);
                Add(line, district == null ? "" : district.ObjectId.ToString(CultureInfo.InvariantCulture));
                Add(line, district == null ? "" : district.Level.ToString(CultureInfo.InvariantCulture));
                Add(line, city == null ? "" : city.Name);
                Add(line, city == null ? "" : city.TypeName);
                Add(line, city == null ? "" : city.Guid);
                Add(line, city == null ? "" : city.Kladr);
                Add(line, place.Name);
                Add(line, place.TypeName);
                Add(line, place.Level.ToString(CultureInfo.InvariantCulture));
                Add(line, display);
                Add(line, place.Guid);
                Add(line, place.ObjectId.ToString(CultureInfo.InvariantCulture));
                Add(line, place.Kladr, true);

                if (includeOptionalCodes)
                {
                    line.Append(';'); line.Append(Csv(place.Okato));
                    line.Append(';'); line.Append(Csv(place.Oktmo));
                    line.Append(';'); line.Append(Csv(""));
                }

                w.WriteLine(line.ToString());
            }
        }

        Console.WriteLine("Rows exported: " + rows.Count + "; rows with empty KLADR: " + missingKladr);
    }

    private static Obj FindAncestor(Obj target, int level, bool nearest)
    {
        if (target == null) return null;
        if (target.Level == level) return target;
        if (String.IsNullOrEmpty(target.AdmPath)) return null;

        Obj found = null;
        string[] parts = target.AdmPath.Split(new char[] { '.', '/', ',' }, StringSplitOptions.RemoveEmptyEntries);
        foreach (string p in parts)
        {
            long id;
            if (!Int64.TryParse(p.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out id))
                continue;
            Obj o;
            if (Objects.TryGetValue(id, out o) && o.Level == level)
            {
                found = o;
                if (!nearest) return found;
            }
        }
        return found;
    }

    private static Obj FindDistrict(Obj target, Obj region, Obj city)
    {
        if (target == null || String.IsNullOrEmpty(target.AdmPath)) return null;

        List<Obj> path = GetPathObjects(target);
        if (path.Count == 0) return null;

        int regionIndex = IndexOfObject(path, region == null ? 0 : region.ObjectId);
        int cityIndex = IndexOfObject(path, city == null ? 0 : city.ObjectId);
        int targetIndex = IndexOfObject(path, target.ObjectId);
        if (targetIndex < 0) targetIndex = path.Count;

        // Район нужен как контекст между субъектом РФ и городом/населённым пунктом.
        // Внутригородские районы после города не подмешиваем в display_name.
        int from = regionIndex >= 0 ? regionIndex + 1 : 0;
        int to = targetIndex;
        if (city != null && city.ObjectId != target.ObjectId && cityIndex >= 0)
            to = cityIndex;

        Obj found = null;
        for (int i = from; i < to && i < path.Count; i++)
        {
            Obj o = path[i];
            if (o == null) continue;
            if (region != null && o.ObjectId == region.ObjectId) continue;
            if (city != null && o.ObjectId == city.ObjectId) continue;
            if (o.ObjectId == target.ObjectId) continue;
            if (IsDistrictType(o.TypeName)) found = o;
        }
        return found;
    }

    private static List<Obj> GetPathObjects(Obj target)
    {
        List<Obj> path = new List<Obj>();
        if (target == null || String.IsNullOrEmpty(target.AdmPath)) return path;

        string[] parts = target.AdmPath.Split(new char[] { '.', '/', ',' }, StringSplitOptions.RemoveEmptyEntries);
        foreach (string p in parts)
        {
            long id;
            if (!Int64.TryParse(p.Trim(), NumberStyles.Integer, CultureInfo.InvariantCulture, out id))
                continue;
            Obj o;
            if (Objects.TryGetValue(id, out o)) path.Add(o);
        }
        return path;
    }

    private static int IndexOfObject(List<Obj> path, long objectId)
    {
        if (objectId == 0) return -1;
        for (int i = 0; i < path.Count; i++)
            if (path[i] != null && path[i].ObjectId == objectId) return i;
        return -1;
    }

    private static bool IsDistrictType(string typeName)
    {
        string t = Safe(typeName).Trim().ToLowerInvariant();
        if (String.IsNullOrEmpty(t)) return false;
        return t == "р-н" || t == "район" || t.Contains("район") || t.Contains("р-н");
    }

    private static string BuildDisplay(Obj region, Obj district, Obj city, Obj place)
    {
        List<string> parts = new List<string>();
        if (region != null) parts.Add(FormatObj(region, true));
        if (district != null && (region == null || district.ObjectId != region.ObjectId) && district.ObjectId != place.ObjectId) parts.Add(FormatObj(district, false));
        if (city != null && (region == null || city.ObjectId != region.ObjectId) && city.ObjectId != place.ObjectId && (district == null || city.ObjectId != district.ObjectId)) parts.Add(FormatObj(city, false));
        if (place != null && (region == null || place.ObjectId != region.ObjectId || !IsFederalCityType(place.TypeName))) parts.Add(FormatObj(place, false));
        return String.Join(", ", parts.ToArray());
    }

    private static string FormatObj(Obj o, bool isRegion)
    {
        if (o == null) return "";
        string name = Safe(o.Name);
        string type = Safe(o.TypeName);
        if (String.IsNullOrEmpty(type)) return name;

        // Для городов и большинства населённых пунктов привычнее префикс: "г Москва", "с Ивановка".
        // Для областей/краёв чаще удобнее суффикс: "Московская обл", "Краснодарский край".
        string lower = type.ToLowerInvariant();
        if (IsPrefixType(type))
            return (type + " " + name).Trim();

        return (name + " " + type).Trim();
    }

    private static bool IsFederalCityType(string typeName)
    {
        return IsCityLikeType(typeName);
    }

    private static bool IsCityLikeType(string typeName)
    {
        string t = NormalizeType(typeName);
        return t == "г" || t == "город";
    }

    private static bool IsPlaceType(string typeName)
    {
        string t = NormalizeType(typeName);
        if (String.IsNullOrEmpty(t)) return false;

        // Города и населённые пункты. Список намеренно шире LEVEL=5/6,
        // чтобы не терять объекты, которым в GAR присвоен другой LEVEL.
        return
            t == "г" || t == "город" ||
            t == "с" || t == "село" ||
            t == "д" || t == "деревня" ||
            t == "п" || t == "поселок" || t == "посёлок" ||
            t == "рп" || t == "рабочий поселок" || t == "рабочий посёлок" ||
            t == "пгт" || t == "кп" || t == "дп" ||
            t == "х" || t == "хутор" ||
            t == "аул" || t == "ст-ца" || t == "станица" ||
            t == "сл" || t == "слобода" ||
            t == "м" || t == "местечко" ||
            t == "нп" || t == "населенный пункт" || t == "населённый пункт";
    }

    private static bool IsPrefixType(string typeName)
    {
        string t = NormalizeType(typeName);
        return
            t == "г" || t == "город" ||
            t == "с" || t == "село" ||
            t == "д" || t == "деревня" ||
            t == "п" || t == "поселок" || t == "посёлок" ||
            t == "рп" || t == "пгт" || t == "кп" || t == "дп" ||
            t == "х" || t == "хутор" ||
            t == "аул" || t == "ст-ца" || t == "станица" ||
            t == "сл" || t == "слобода" ||
            t == "м" || t == "местечко" ||
            t == "нп";
    }

    private static string NormalizeType(string typeName)
    {
        string t = Safe(typeName).Trim().ToLowerInvariant();
        while (t.EndsWith(".")) t = t.Substring(0, t.Length - 1).Trim();
        return t;
    }

    private static bool IsCurrent(string start, string end)
    {
        DateTime today = DateTime.Today;
        DateTime startDt;
        if (DateTime.TryParse(start, CultureInfo.InvariantCulture, DateTimeStyles.None, out startDt) && startDt.Date > today) return false;
        DateTime endDt;
        if (DateTime.TryParse(end, CultureInfo.InvariantCulture, DateTimeStyles.None, out endDt) && endDt.Date < today) return false;
        return true;
    }

    private static DateTime ParseDateOrMin(string s)
    {
        DateTime d;
        if (DateTime.TryParse(s, CultureInfo.InvariantCulture, DateTimeStyles.None, out d)) return d;
        return DateTime.MinValue;
    }

    private static string RegionCodeFromKladr(string kladr)
    {
        if (String.IsNullOrEmpty(kladr) || kladr.Length < 2) return "";
        return kladr.Substring(0, 2);
    }

    private static string FirstNonEmpty(params string[] xs)
    {
        foreach (string x in xs) if (!String.IsNullOrEmpty(x)) return x;
        return "";
    }

    private static string A(XmlReader r, string name)
    {
        string v = r.GetAttribute(name);
        return v ?? "";
    }

    private static bool Eq(string a, string b)
    {
        return String.Equals(a, b, StringComparison.OrdinalIgnoreCase);
    }

    private static string Safe(string s)
    {
        return s ?? "";
    }

    private static void Add(StringBuilder sb, string value)
    {
        if (sb.Length > 0) sb.Append(';');
        sb.Append(Csv(value));
    }

    private static void Add(StringBuilder sb, string value, bool last)
    {
        Add(sb, value);
    }

    private static string Csv(string value)
    {
        if (value == null) value = "";
        return "\"" + value.Replace("\"", "\"\"") + "\"";
    }
}
'@


# PowerShell Core/.NET can fail to compile embedded C# if -ReferencedAssemblies
# is too narrow. Build references from Trusted Platform Assemblies and the
# currently loaded runtime assemblies, then retry once with PowerShell defaults.
foreach ($assemblyName in @(
    "System.IO.Compression",
    "System.IO.Compression.FileSystem",
    "System.Xml",
    "System.Xml.ReaderWriter",
    "System.Runtime",
    "System.Collections",
    "System.Console",
    "netstandard"
)) {
    try { Add-Type -AssemblyName $assemblyName -ErrorAction SilentlyContinue } catch { }
}

function Get-GarAddTypeReferenceAssemblies {
    $refs = @()

    try {
        $tpa = [System.AppContext]::GetData("TRUSTED_PLATFORM_ASSEMBLIES")
        if ($tpa) {
            $refs += ($tpa -split [System.IO.Path]::PathSeparator)
        }
    }
    catch { }

    try {
        $refs += [System.AppDomain]::CurrentDomain.GetAssemblies() |
            Where-Object { $_ -and -not [string]::IsNullOrWhiteSpace($_.Location) } |
            ForEach-Object { $_.Location }
    }
    catch { }

    # Fallback for Windows PowerShell / .NET Framework.
    if (-not $refs -or $refs.Count -eq 0) {
        $refs += @(
            "mscorlib.dll",
            "System.dll",
            "System.Core.dll",
            "System.Xml.dll",
            "System.IO.Compression.dll",
            "System.IO.Compression.FileSystem.dll"
        )
    }

    $refs |
        Where-Object { $_ -and ($_ -is [string]) } |
        Select-Object -Unique
}

$compiled = $false
$compileMessages = New-Object System.Collections.ArrayList
$referenceAssembliesArray = @(Get-GarAddTypeReferenceAssemblies)

if ($referenceAssembliesArray.Count -gt 0) {
    try {
        Add-Type -TypeDefinition $cs -ReferencedAssemblies $referenceAssembliesArray -ErrorAction Stop
        $compiled = $true
    }
    catch {
        [void]$compileMessages.Add("С попыткой явных ссылок: $($_.Exception.Message)")
    }
}

if (-not $compiled) {
    try {
        Add-Type -TypeDefinition $cs -ErrorAction Stop
        $compiled = $true
    }
    catch {
        [void]$compileMessages.Add("С настройками Add-Type по умолчанию: $($_.Exception.Message)")
    }
}

if (-not $compiled) {
    $msg = "Не удалось скомпилировать встроенный C#-парсер через Add-Type. " + ($compileMessages -join "`n")
    Write-Error $msg
    throw $msg
}

[GarPlacesExtractor]::Run($archivePath, $outCsvPath, $PlaceLevels, [bool]$IncludeOptionalCodes.IsPresent)
