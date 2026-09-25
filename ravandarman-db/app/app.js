let DB = null;
const state = { q: "", city: "", prof: "", type: null, visits: new Set(), sort: "votes" };
const LS_KEY = "ravandarman_custom_v1";

const $ = (s) => document.querySelector(s);
const norm = (s) => (s || "")
  .replace(/ي/g, "ی").replace(/ك/g, "ک")
  .replace(/[\u200c\u200f]/g, " ")
  .replace(/\s+/g, " ")
  .trim().toLowerCase();

const faNum = (n) => Number(n).toLocaleString("fa-IR");

/* ---------- داده‌های افزوده‌شده توسط کاربر ---------- */
function loadCustom() {
  try { return JSON.parse(localStorage.getItem(LS_KEY) || "[]"); } catch (e) { return []; }
}
function saveCustom(list) {
  try { localStorage.setItem(LS_KEY, JSON.stringify(list)); } catch (e) {}
}

/* ادغام بدون تکرار: کلید = شناسه، وگرنه نام+شهر */
function dedupeKey(d) {
  return d.id ? "id:" + String(d.id) : "nc:" + norm(d.name) + "|" + norm(d.city);
}
function allDoctors() {
  const seen = new Map();
  DB.doctors.forEach(d => seen.set(dedupeKey(d), d));
  // کلید نام+شهر پایه‌ها را هم ثبت کن تا رکورد واردشده با همان نام+شهر تکراری حساب شود
  DB.doctors.forEach(d => { const k = "nc:" + norm(d.name) + "|" + norm(d.city); if (!seen.has(k)) seen.set(k, d); });
  loadCustom().forEach(d => {
    const kId = d.id ? "id:" + String(d.id) : null;
    const kNc = "nc:" + norm(d.name) + "|" + norm(d.city);
    if ((kId && seen.has(kId)) || seen.has(kNc)) return; // تکراری → رد
    d._custom = true;
    seen.set(kId || kNc, d);
    seen.set(kNc, d);
  });
  return [...new Set(seen.values())];
}

fetch("doctors.json").then(r => r.json()).then(data => {
  DB = data;
  initFilters();
  initStats();
  initTools();
  render();
});

function initStats() {
  const ds = allDoctors();
  const cities = new Set(ds.map(d => d.city).filter(Boolean));
  const profs = new Set(); ds.forEach(d => (d.professions || []).forEach(p => profs.add(p)));
  $("#stTotal").textContent = faNum(ds.length);
  $("#stCities").textContent = faNum(cities.size);
  $("#stProfs").textContent = faNum(profs.size);
  $("#stVotes").textContent = faNum(ds.reduce((a, d) => a + (Number(d.votes) || 0), 0));
  $("#metaDate").textContent = "به‌روزرسانی: " + DB.meta.extracted_at;
}

function rebuildSelects() {
  const cityCount = {}, profCount = {};
  allDoctors().forEach(d => {
    if (d.city) cityCount[d.city] = (cityCount[d.city] || 0) + 1;
    (d.professions || []).forEach(p => profCount[p] = (profCount[p] || 0) + 1);
  });
  const fill = (sel, counts, label) => {
    const cur = sel.value;
    sel.innerHTML = `<option value="">${label}</option>`;
    Object.entries(counts).sort((a, b) => b[1] - a[1]).forEach(([k, v]) => {
      const o = document.createElement("option");
      o.value = k; o.textContent = `${k} (${faNum(v)})`;
      sel.appendChild(o);
    });
    sel.value = cur;
  };
  fill($("#fCity"), cityCount, "همهٔ شهرها");
  fill($("#fProf"), profCount, "همهٔ حوزه‌ها");
}

function initFilters() {
  rebuildSelects();

  $("#q").addEventListener("input", e => { state.q = norm(e.target.value); render(); });
  $("#fCity").addEventListener("change", e => { state.city = e.target.value; render(); });
  $("#fProf").addEventListener("change", e => { state.prof = e.target.value; render(); });
  $("#sort").addEventListener("change", e => { state.sort = e.target.value; render(); });

  document.querySelectorAll(".chip").forEach(ch => ch.addEventListener("click", () => {
    const g = ch.dataset.group, v = ch.dataset.val;
    if (g === "type") {
      const was = ch.classList.contains("on");
      document.querySelectorAll('.chip[data-group="type"]').forEach(c => c.classList.remove("on"));
      state.type = was ? null : v;
      if (!was) ch.classList.add("on");
    } else {
      ch.classList.toggle("on");
      ch.classList.contains("on") ? state.visits.add(v) : state.visits.delete(v);
    }
    render();
  }));

  $("#reset").addEventListener("click", () => {
    state.q = ""; state.city = ""; state.prof = ""; state.type = null; state.visits.clear(); state.sort = "votes";
    $("#q").value = ""; $("#fCity").value = ""; $("#fProf").value = ""; $("#sort").value = "votes";
    document.querySelectorAll(".chip").forEach(c => c.classList.remove("on"));
    render();
  });
}

/* ---------- افزودن دستی + ورود از فایل + خروجی ---------- */
function refreshAll() {
  rebuildSelects();
  initStats();
  render();
}

function initTools() {
  const modal = $("#addModal");
  if ($("#addBtn")) {
    $("#addBtn").addEventListener("click", () => { modal.style.display = "block"; });
    $("#aCancel").addEventListener("click", () => { modal.style.display = "none"; });
    modal.addEventListener("click", e => { if (e.target === modal) modal.style.display = "none"; });
    $("#aSave").addEventListener("click", () => {
      const name = ($("#aName").value || "").trim();
      const city = ($("#aCity").value || "").trim();
      if (!name || !city) { alert("نام و شهر الزامی است."); return; }
      const visits = [];
      if ($("#aV1").checked) visits.push("حضوری");
      if ($("#aV2").checked) visits.push("تلفنی");
      if ($("#aV3").checked) visits.push("آنلاین");
      const rec = {
        id: "u" + Date.now(),
        name, city,
        type: $("#aType").value || "روانشناس",
        professions: ($("#aProfs").value || "").split(/[,،]/).map(s => s.trim()).filter(Boolean),
        area: ($("#aArea").value || "").trim(),
        rating: parseFloat(($("#aRating").value || "").replace(/[۰-۹]/g, c => "۰۱۲۳۴۵۶۷۸۹".indexOf(c))) || null,
        votes: parseInt(($("#aVotes").value || "").replace(/[۰-۹]/g, c => "۰۱۲۳۴۵۶۷۸۹".indexOf(c))) || 0,
        visits,
        url: ($("#aUrl").value || "").trim() || null
      };
      // بررسی تکراری
      const k = "nc:" + norm(rec.name) + "|" + norm(rec.city);
      const existing = allDoctors().some(d => "nc:" + norm(d.name) + "|" + norm(d.city) === k);
      if (existing) { alert("این متخصص (نام + شهر یکسان) از قبل در بانک موجود است و دوباره اضافه نشد."); modal.style.display = "none"; return; }
      const cs = loadCustom(); cs.push(rec); saveCustom(cs);
      modal.style.display = "none";
      ["aName","aCity","aProfs","aArea","aRating","aVotes","aUrl"].forEach(id => $("#" + id).value = "");
      ["aV1","aV2","aV3"].forEach(id => $("#" + id).checked = false);
      refreshAll();
      alert("✅ «" + rec.name + "» با موفقیت اضافه شد.");
    });
  }

  if ($("#importBtn")) {
    $("#importBtn").addEventListener("click", () => $("#importFile").click());
    $("#importFile").addEventListener("change", e => {
      const f = e.target.files[0];
      if (!f) return;
      const reader = new FileReader();
      reader.onload = () => {
        try {
          const recs = parseImport(reader.result, f.name);
          const existingKeys = new Set(allDoctors().flatMap(d => [
            d.id ? "id:" + String(d.id) : null,
            "nc:" + norm(d.name) + "|" + norm(d.city)
          ].filter(Boolean)));
          const cs = loadCustom();
          let added = 0, dup = 0;
          recs.forEach(r => {
            if (!r.name) return;
            const kId = r.id ? "id:" + String(r.id) : null;
            const kNc = "nc:" + norm(r.name) + "|" + norm(r.city);
            if ((kId && existingKeys.has(kId)) || existingKeys.has(kNc)) { dup++; return; }
            if (kId) existingKeys.add(kId);
            existingKeys.add(kNc);
            cs.push(r); added++;
          });
          saveCustom(cs);
          refreshAll();
          alert(`✅ ورود فایل انجام شد.\nافزوده‌شده: ${faNum(added)} رکورد\nتکراری (نادیده گرفته شد): ${faNum(dup)} رکورد`);
        } catch (err) {
          alert("❌ خطا در خواندن فایل: " + err.message + "\nفایل باید CSV (مطابق خروجی همین برنامه) یا JSON باشد.");
        }
        e.target.value = "";
      };
      reader.readAsText(f, "utf-8");
    });
  }

  if ($("#dlCsv")) {
    $("#dlCsv").addEventListener("click", e => {
      e.preventDefault();
      const csv = "\ufeff" + toCSV(allDoctors());
      const a = document.createElement("a");
      a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      a.download = "بانک-روانشناسان.csv";
      a.click();
    });
  }
}

/* CSV با پشتیبانی از نقل‌قول */
function parseCSV(text) {
  const rows = []; let row = [], cur = "", inQ = false;
  text = text.replace(/^\ufeff/, "");
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (inQ) {
      if (c === '"') { if (text[i + 1] === '"') { cur += '"'; i++; } else inQ = false; }
      else cur += c;
    } else if (c === '"') inQ = true;
    else if (c === ",") { row.push(cur); cur = ""; }
    else if (c === "\n" || c === "\r") {
      if (c === "\r" && text[i + 1] === "\n") i++;
      row.push(cur); cur = "";
      if (row.some(x => x.trim() !== "")) rows.push(row);
      row = [];
    } else cur += c;
  }
  if (cur !== "" || row.length) { row.push(cur); if (row.some(x => x.trim() !== "")) rows.push(row); }
  return rows;
}

const HEADER_MAP = {
  "شناسه": "id", "id": "id",
  "نام": "name", "name": "name", "نام کامل": "name",
  "نوع": "type", "type": "type", "نوع تخصص": "type",
  "حوزه‌های فعالیت": "professions", "حوزه های فعالیت": "professions", "حوزه": "professions", "professions": "professions", "تخصص": "professions",
  "شهر": "city", "city": "city",
  "آدرس/محدوده": "area", "آدرس": "area", "محدوده": "area", "area": "area",
  "امتیاز": "rating", "rating": "rating",
  "تعداد نظرات": "votes", "نظرات": "votes", "votes": "votes",
  "انواع ویزیت": "visits", "ویزیت": "visits", "visits": "visits",
  "لینک پروفایل": "url", "لینک": "url", "url": "url"
};

function parseImport(text, fname) {
  text = text.trim();
  if (fname.toLowerCase().endsWith(".json") || text.startsWith("{") || text.startsWith("[")) {
    let j = JSON.parse(text);
    if (j && j.doctors) j = j.doctors;
    if (!Array.isArray(j)) throw new Error("ساختار JSON قابل شناسایی نیست");
    return j.map(normalizeRec);
  }
  const rows = parseCSV(text);
  if (rows.length < 2) throw new Error("فایل CSV خالی است یا فقط سرستون دارد");
  const headers = rows[0].map(h => HEADER_MAP[norm(h)] || HEADER_MAP[h.trim()] || null);
  return rows.slice(1).map(r => {
    const o = {};
    headers.forEach((h, i) => { if (h && r[i] != null) o[h] = r[i]; });
    return normalizeRec(o);
  });
}

const faDigits = s => String(s == null ? "" : s).replace(/[۰-۹]/g, c => "۰۱۲۳۴۵۶۷۸۹".indexOf(c));
function normalizeRec(o) {
  const splitList = v => Array.isArray(v) ? v : String(v || "").split(/[,،؛;|]/).map(s => s.trim()).filter(Boolean);
  return {
    id: o.id ? String(o.id).trim() : "u" + Date.now() + Math.random().toString(36).slice(2, 6),
    name: String(o.name || "").trim(),
    type: /پزشک/.test(String(o.type || "")) ? "روانپزشک" : "روانشناس",
    professions: splitList(o.professions),
    city: String(o.city || "").trim(),
    area: String(o.area || "").trim(),
    rating: parseFloat(faDigits(o.rating)) || null,
    votes: parseInt(faDigits(o.votes)) || 0,
    visits: splitList(o.visits).filter(v => ["حضوری", "تلفنی", "آنلاین"].includes(v.trim())),
    url: (o.url && String(o.url).startsWith("http")) ? String(o.url).trim() : null
  };
}

function toCSV(ds) {
  const esc = v => { v = v == null ? "" : String(v); return /[",\n]/.test(v) ? '"' + v.replace(/"/g, '""') + '"' : v; };
  const head = ["شناسه", "نام", "نوع", "حوزه‌های فعالیت", "شهر", "آدرس/محدوده", "امتیاز", "تعداد نظرات", "انواع ویزیت", "لینک پروفایل"];
  const lines = ds.map(d => [d.id, d.name, d.type, (d.professions || []).join("، "), d.city, d.area || "",
    d.rating != null ? d.rating : "", d.votes || 0, (d.visits || []).join("، "), d.url || ""].map(esc).join(","));
  return head.join(",") + "\n" + lines.join("\n");
}

/* ---------- فیلتر و نمایش ---------- */
function filtered() {
  let ds = allDoctors().filter(d => {
    if (state.city && d.city !== state.city) return false;
    if (state.prof && !(d.professions || []).includes(state.prof)) return false;
    if (state.type && d.type !== state.type) return false;
    for (const v of state.visits) if (!(d.visits || []).includes(v)) return false;
    if (state.q) {
      const hay = norm([d.name, d.city, d.area, d.type, (d.professions || []).join(" ")].join(" "));
      for (const tok of state.q.split(" ")) if (tok && !hay.includes(tok)) return false;
    }
    return true;
  });
  if (state.sort === "votes") ds.sort((a, b) => (b.votes || 0) - (a.votes || 0));
  else if (state.sort === "rating") ds.sort((a, b) => (b.rating || 0) - (a.rating || 0) || (b.votes || 0) - (a.votes || 0));
  else ds.sort((a, b) => a.name.localeCompare(b.name, "fa"));
  return ds;
}

function render() {
  const ds = filtered();
  $("#shown").textContent = faNum(ds.length);

  const act = [];
  if (state.city) act.push("شهر: " + state.city);
  if (state.prof) act.push("حوزه: " + state.prof);
  if (state.type) act.push(state.type);
  if (state.visits.size) act.push([...state.visits].join("، "));
  $("#activeFilters").textContent = act.length ? "فیلترهای فعال: " + act.join(" · ") : "";

  const grid = $("#grid");
  if (!ds.length) {
    grid.innerHTML = '<div class="empty">😕 متخصصی مطابق فیلترهای شما یافت نشد.<br>فیلترها را تغییر دهید یا حذف کنید.</div>';
    return;
  }
  grid.innerHTML = ds.map(d => `
    <div class="card">
      <div class="top">
        <h3>${d.name}</h3>
        <span class="badge ${d.type === "روانپزشک" ? "dr" : "psy"}">${d.type === "روانپزشک" ? "روانپزشک" : "مشاور، روانشناس"}</span>
      </div>
      ${d._custom ? '<div style="font-size:.68rem;color:#92400e;background:#fef3c7;display:inline-block;padding:2px 8px;border-radius:6px;margin-bottom:6px">✍️ افزوده‌شده توسط شما</div>' : ""}
      ${d.rating != null ? `<div class="rate">★ ${Number(d.rating).toLocaleString("fa-IR")} <small>(${faNum(d.votes)} نظر)</small></div>` : ""}
      <div class="profs">${(d.professions || []).map(p => `<span>${p}</span>`).join("")}</div>
      <div class="loc">📍 ${d.city}${d.area ? " — " + d.area : ""}</div>
      ${d.phone ? `<div class="loc" style="direction:ltr;text-align:right">☎️ <a href="tel:${String(d.phone).split("،")[0].replace(/[^0-9+]/g,"")}" style="color:#0d6e4f;font-weight:700;text-decoration:none">${d.phone}</a></div>` : ""}
      <div class="visits">${(d.visits || []).map(v => `<span class="v-${v}">${v === "آنلاین" ? "🎥 آنلاین" : v === "تلفنی" ? "📞 تلفنی" : "🏢 حضوری"}</span>`).join("") || '<span>نوع ویزیت: نامشخص</span>'}</div>
      ${d.url ? `<a class="profile" href="${d.url}" target="_blank" rel="noopener">مشاهدهٔ پروفایل و رزرو نوبت ↗</a>` : ""}
    </div>`).join("");
}
