#!/usr/bin/env node
/* بررسی نحوی فایل‌های PHP با php-parser — چون در محیط ساخت، باینری php نداریم.
 * اجرا:  node phase1/tools/lint/lint.js phase1/public_html
 * نکته: node_modules این پوشه در .gitignore است؛ در صورت نبود:
 *       cd phase1/tools/lint && npm i php-parser@3
 */
const fs = require('fs');
const path = require('path');
const engine = require('php-parser');

const parser = new engine({
  parser: { extractDoc: false, suppressErrors: false },
  ast: { withPositions: true },
});

const root = process.argv[2];
if (!root) {
  console.error('usage: node lint.js <dir-or-file>');
  process.exit(2);
}

let files = 0;
let errors = 0;

function walk(p) {
  const st = fs.statSync(p);
  if (st.isDirectory()) {
    for (const n of fs.readdirSync(p).sort()) {
      if (n === 'node_modules' || n === '.git') continue;
      walk(path.join(p, n));
    }
    return;
  }
  if (!p.endsWith('.php')) return;
  files++;
  const src = fs.readFileSync(p, 'utf8');
  try {
    parser.parseCode(src, p);
  } catch (e) {
    errors++;
    const line = e.lineNumber || (e.loc && e.loc.start && e.loc.start.line) || '?';
    console.log(`ERROR ${p}:${line} ${e.message.split('\n')[0]}`);
  }
}

walk(root);
console.log(`files=${files} errors=${errors}`);
process.exit(errors ? 1 : 0);
