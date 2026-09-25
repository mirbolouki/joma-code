# -*- coding: utf-8 -*-
"""ادغام فایل‌های PSV استخراج‌شده از ravandarman.com و ساخت doctors.json
فرمت PSV (batch2 به بعد): id|slug|name|type|professions|city|area|rating|votes|visits
فرمت batch1:              id|name|type|professions|city|area|rating|votes|visits  (slug در batch1-slugs.txt)
"""
import json, csv, io, os

BASE = os.path.dirname(os.path.abspath(__file__))
DATA = os.path.join(BASE, "data")

slugs = {}
with open(os.path.join(DATA, "batch1-slugs.txt"), encoding="utf-8") as f:
    for line in f:
        line = line.strip()
        if "=" in line:
            k, v = line.split("=", 1)
            slugs[k] = v

doctors = {}

def add(rec):
    doctors[rec["id"]] = rec  # آخرین نسخه جایگزین می‌شود (به‌روزرسانی‌ها)

def parse_line(parts, with_slug):
    if with_slug:
        _id, slug, name, typ, profs, city, area, rating, votes, visits = parts
    else:
        _id, name, typ, profs, city, area, rating, votes, visits = parts
        slug = slugs.get(_id, "")
    return {
        "id": int(_id),
        "name": name.strip(),
        "type": typ.strip(),
        "professions": [p.strip() for p in profs.split(",") if p.strip()],
        "city": city.strip() or "نامشخص",
        "area": area.strip(),
        "rating": float(rating) if rating else None,
        "votes": int(votes) if votes else 0,
        "visits": [v.strip() for v in visits.split(",") if v.strip()],
        "url": f"https://ravandarman.com/doctor/{_id}-{slug}" if slug else "",
    }

import glob

# ── لایهٔ ۱: فهرست کامل پزشکان فعال از نقشهٔ سایت (رکوردهای پایه) ──
def slug_to_name(slug):
    s = slug.rstrip("0123456789")
    return " ".join(w.capitalize() for w in s.split("-") if w)

for smf in sorted(glob.glob(os.path.join(DATA, "sitemap-part*.txt"))):
    with open(smf, encoding="utf-8") as f:
        for line in f:
            line = line.strip()
            if not line or "-" not in line:
                continue
            _id, slug = line.split("-", 1)
            if not _id.isdigit():
                continue
            doctors[int(_id)] = {
                "id": int(_id),
                "name": slug_to_name(slug),
                "type": "روانشناس",
                "professions": [],
                "city": "در انتظار تکمیل",
                "area": "",
                "rating": None,
                "votes": 0,
                "visits": [],
                "url": f"https://ravandarman.com/doctor/{_id}-{slug}",
            }

# ── لایهٔ تلفن‌دار: رکوردهای منبع سلامتی۲۴/دکتریاب با شمارهٔ کامل ──
# فرمت: name|professions|city|address|phone
_pid = 900000
for pf in sorted(glob.glob(os.path.join(DATA, "phones-*.psv"))):
    with open(pf, encoding="utf-8") as f:
        for line in f:
            line = line.rstrip("\n")
            if not line.strip():
                continue
            parts = line.split("|")
            if len(parts) != 5:
                print(f"!! خط نامعتبر در {os.path.basename(pf)}: {parts[0]}")
                continue
            name, profs, city, address, phone = parts
            _pid += 1
            doctors[_pid] = {
                "id": _pid,
                "name": name.strip(),
                "type": "روانشناس",
                "professions": [p.strip() for p in profs.split(",") if p.strip()],
                "city": city.strip() or "نامشخص",
                "area": address.strip(),
                "rating": None,
                "votes": 0,
                "visits": ["حضوری"],
                "url": "",
                "phone": phone.strip(),
            }

# ── لایهٔ ۲: رکوردهای کامل استخراج‌شده (جایگزین رکورد پایه می‌شوند) ──
batch_files = sorted(glob.glob(os.path.join(DATA, "batch*.psv")))
for fname, with_slug in [(os.path.basename(p), os.path.basename(p) != "batch1.psv") for p in batch_files]:
    with open(os.path.join(DATA, fname), encoding="utf-8") as f:
        for line in f:
            line = line.rstrip("\n")
            if not line.strip():
                continue
            parts = line.split("|")
            expected = 10 if with_slug else 9
            if len(parts) != expected:
                print(f"!! خط نامعتبر در {fname}: {parts[0]} ({len(parts)} فیلد)")
                continue
            add(parse_line(parts, with_slug))

records = sorted(doctors.values(), key=lambda d: (-(d["votes"] or 0), d["name"]))

meta = {
    "source": "ravandarman.com",
    "extracted_at": "2026-09-25",
    "phase": 1,
    "note": "فاز اول: برترین متخصصان فعال (مرتب‌سازی محبوبیت سایت) + روانپزشکان منتخب",
    "total": len(records),
    "cities": sorted({d["city"] for d in records}),
    "professions": sorted({p for d in records for p in d["professions"]}),
}

with open(os.path.join(BASE, "app", "doctors.json"), "w", encoding="utf-8") as f:
    json.dump({"meta": meta, "doctors": records}, f, ensure_ascii=False, indent=1)

# CSV با BOM برای اکسل
buf = io.StringIO()
w = csv.writer(buf)
w.writerow(["شناسه", "نام", "نوع تخصص", "حوزه‌های فعالیت", "شهر", "محدوده/آدرس", "تلفن", "امتیاز", "تعداد نظرات", "انواع ویزیت", "لینک پروفایل"])
for d in records:
    w.writerow([d["id"], d["name"], d["type"], "، ".join(d["professions"]), d["city"], d["area"], d.get("phone", ""),
                d["rating"] if d["rating"] is not None else "", d["votes"], "، ".join(d["visits"]), d["url"]])
with open(os.path.join(BASE, "app", "doctors.csv"), "w", encoding="utf-8-sig", newline="") as f:
    f.write(buf.getvalue())

print(f"OK: {len(records)} متخصص | {len(meta['cities'])} شهر | {len(meta['professions'])} حوزه")

# ── بانک پیامک: فقط رکوردهای دارای شماره موبایل (09xxxxxxxxx) ──
import re as _re
sms_buf = io.StringIO()
sw = csv.writer(sms_buf)
sw.writerow(["شماره موبایل", "نام", "شهر", "حوزه‌های فعالیت", "آدرس"])
sms_count = 0
for d in records:
    ph = (d.get("phone") or "").replace("-", "").replace(" ", "")
    m = _re.findall(r"09\d{9}", ph)
    if m:
        sw.writerow([m[0], d["name"], d["city"], "، ".join(d["professions"]), d["area"]])
        sms_count += 1
with open(os.path.join(BASE, "app", "sms.csv"), "w", encoding="utf-8-sig", newline="") as f:
    f.write(sms_buf.getvalue())
print(f"SMS bank: {sms_count} شماره موبایل")
