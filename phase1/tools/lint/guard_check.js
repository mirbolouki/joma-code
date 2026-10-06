#!/usr/bin/env node
/* ═══════════════════════════════════════════════════════════════════
 *  guard_check.js — قفلِ فهرست صفحه‌های بدون احراز هویت
 *
 *  در این معماری نگهبان سراسری وجود ندارد؛ هر صفحه خودش نگهبان را صدا
 *  می‌زند. پس «فراموش کردن یک خط» = یک صفحهٔ باز. این ابزار جلوی آن را
 *  می‌گیرد: هر صفحهٔ ورودی یا نگهبان دارد، یا باید صراحتاً در
 *  public_pages.txt فهرست شده باشد.
 *
 *  اجرا:  node phase1/tools/lint/guard_check.js phase1/public_html
 *  خروج:  0 = سالم · 1 = صفحهٔ بدون نگهبانِ اعلام‌نشده پیدا شد
 * ═══════════════════════════════════════════════════════════════════ */
'use strict';
const fs = require('fs');
const path = require('path');

const GUARDS = [
  'auth_require_active_session(',
  'patient_require_portal_session(',
  'require_login(',
];

/* پوشه‌هایی که صفحهٔ ورودی نیستند (کتابخانه و قطعهٔ نمایشی) */
const NOT_ENTRY = new Set(['includes', 'templates', 'database', 'assets']);

function walk(dir, out) {
  for (const name of fs.readdirSync(dir)) {
    const full = path.join(dir, name);
    const st = fs.statSync(full);
    if (st.isDirectory()) { walk(full, out); }
    else if (name.endsWith('.php')) { out.push(full); }
  }
  return out;
}

const root = process.argv[2] || 'phase1/public_html';
const allowFile = path.join(__dirname, 'public_pages.txt');

const allow = new Set(
  fs.existsSync(allowFile)
    ? fs.readFileSync(allowFile, 'utf8').split('\n')
        .map(l => l.replace(/#.*$/, '').trim()).filter(Boolean)
    : []
);

const files = walk(root, []);
const unguarded = [];
let guarded = 0, skipped = 0;

for (const f of files) {
  const rel = path.relative(root, f).split(path.sep).join('/');
  const top = rel.includes('/') ? rel.split('/')[0] : '';
  if (NOT_ENTRY.has(top)) { skipped++; continue; }

  const src = fs.readFileSync(f, 'utf8');
  if (GUARDS.some(g => src.includes(g))) { guarded++; continue; }
  unguarded.push(rel);
}

const undeclared = unguarded.filter(p => !allow.has(p));
const stale = [...allow].filter(p => !unguarded.includes(p));

console.log('صفحهٔ ورودی بررسی‌شده : ' + (guarded + unguarded.length));
console.log('  دارای نگهبان        : ' + guarded);
console.log('  بدون نگهبان         : ' + unguarded.length);
console.log('فایل کتابخانه‌ای (رد شد): ' + skipped);

if (unguarded.length) {
  console.log('\nصفحه‌های بدون نگهبان:');
  for (const p of unguarded) {
    console.log('   ' + (allow.has(p) ? '✓ اعلام‌شده  ' : '✗ اعلام‌نشده ') + p);
  }
}
if (stale.length) {
  console.log('\n⚠ در فهرست مجاز هست ولی دیگر بدون نگهبان نیست (پاک شود):');
  stale.forEach(p => console.log('   ' + p));
}
if (undeclared.length) {
  console.log('\n❌ ' + undeclared.length + ' صفحهٔ بدون نگهبانِ اعلام‌نشده.');
  console.log('   اگر عمدی است، به phase1/tools/lint/public_pages.txt اضافه شود.');
  process.exit(1);
}
console.log('\n✅ همهٔ صفحه‌های بدون نگهبان، اعلام‌شده و عمدی‌اند.');
