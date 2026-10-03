# -*- coding: utf-8 -*-
import re, sys, html

def inline(t):
    t = html.escape(t)
    t = re.sub(r'`([^`]+)`', r'<code>\1</code>', t)
    t = re.sub(r'\*\*([^*]+)\*\*', r'<strong>\1</strong>', t)
    t = re.sub(r'(?<!\*)\*([^*]+)\*(?!\*)', r'<em>\1</em>', t)
    t = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'<a href="\2">\1</a>', t)
    return t

def convert(md):
    lines = md.split('\n')
    out, i, n = [], 0, len(lines)
    while i < n:
        ln = lines[i]
        s = ln.strip()
        if not s:
            i += 1; continue
        if s.startswith('```'):
            i += 1; buf = []
            while i < n and not lines[i].strip().startswith('```'):
                buf.append(html.escape(lines[i])); i += 1
            i += 1
            out.append('<pre><code>' + '\n'.join(buf) + '</code></pre>')
            continue
        if re.match(r'^-{3,}$', s):
            out.append('<hr>'); i += 1; continue
        m = re.match(r'^(#{1,6})\s+(.*)$', s)
        if m:
            lvl = len(m.group(1))
            out.append('<h%d>%s</h%d>' % (lvl, inline(m.group(2)), lvl)); i += 1; continue
        if s.startswith('|') and i + 1 < n and re.match(r'^\|[\s:\-|]+\|$', lines[i+1].strip()):
            head = [c.strip() for c in s.strip('|').split('|')]
            i += 2; rows = []
            while i < n and lines[i].strip().startswith('|'):
                rows.append([c.strip() for c in lines[i].strip().strip('|').split('|')]); i += 1
            t = ['<table><thead><tr>'] + ['<th>%s</th>' % inline(c) for c in head] + ['</tr></thead><tbody>']
            for r in rows:
                t.append('<tr>' + ''.join('<td>%s</td>' % inline(c) for c in r) + '</tr>')
            t.append('</tbody></table>')
            out.append(''.join(t)); continue
        if s.startswith('> '):
            buf = []
            while i < n and lines[i].strip().startswith('>'):
                buf.append(lines[i].strip().lstrip('>').strip()); i += 1
            out.append('<blockquote>' + inline(' '.join(buf)) + '</blockquote>'); continue
        m = re.match(r'^(\d+)\.\s+(.*)$', s)
        if m:
            buf = []
            while i < n and re.match(r'^\s*\d+\.\s+', lines[i]):
                buf.append(re.sub(r'^\s*\d+\.\s+', '', lines[i]))
                i += 1
                while i < n and lines[i].startswith('   ') and lines[i].strip() and not re.match(r'^\s*[\d\-]+[.\s]', lines[i].strip()[:3]):
                    buf[-1] += ' ' + lines[i].strip(); i += 1
            out.append('<ol>' + ''.join('<li>%s</li>' % inline(b) for b in buf) + '</ol>'); continue
        if re.match(r'^[-*]\s+', s):
            buf = []
            while i < n and re.match(r'^\s*[-*]\s+', lines[i]) and not re.match(r'^-{3,}$', lines[i].strip()):
                buf.append(re.sub(r'^\s*[-*]\s+', '', lines[i].strip())); i += 1
            out.append('<ul>' + ''.join('<li>%s</li>' % inline(b) for b in buf) + '</ul>'); continue
        buf = []
        while i < n and lines[i].strip() and not re.match(r'^(#{1,6}\s|\||>|```|-{3,}$|\s*[-*]\s|\s*\d+\.\s)', lines[i].strip()):
            buf.append(lines[i].strip()); i += 1
        if buf:
            out.append('<p>' + inline(' '.join(buf)) + '</p>')
        else:
            i += 1
    return '\n'.join(out)

CSS = """
:root{--p:#2E5090;--s:#5B9BD5;--ok:#27AE60;--wr:#F39C12;--dg:#E74C3C;--bg:#F5F7FA;--tx:#2C3E50;--bd:#ECF0F1}
@font-face{font-family:'Vazirmatn';src:url('public_html/assets/fonts/Vazirmatn-Regular.woff2') format('woff2');font-weight:400;font-display:swap}
@font-face{font-family:'Vazirmatn';src:url('public_html/assets/fonts/Vazirmatn-Bold.woff2') format('woff2');font-weight:700;font-display:swap}
*{box-sizing:border-box}
body{font-family:'Vazirmatn',Tahoma,sans-serif;font-size:16px;line-height:1.8;color:var(--tx);background:var(--bg);margin:0;padding:24px 16px}
.wrap{max-width:900px;margin:0 auto;background:#fff;border:1px solid var(--bd);border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.1);padding:32px 36px}
h1{color:var(--p);font-size:28px;border-bottom:3px solid var(--p);padding-bottom:12px;margin-top:0}
h2{color:var(--p);font-size:22px;margin-top:36px;border-right:4px solid var(--s);padding-right:10px}
h3{color:var(--s);font-size:18px;margin-top:24px}
hr{border:0;border-top:1px solid var(--bd);margin:28px 0}
table{width:100%;border-collapse:collapse;margin:16px 0;font-size:15px}
th,td{border:1px solid var(--bd);padding:10px 12px;text-align:right;vertical-align:top}
th{background:var(--p);color:#fff;font-weight:700}
tbody tr:nth-child(even){background:#FAFBFC}
code{background:#F0F3F7;color:#C0392B;padding:2px 6px;border-radius:4px;font-family:Consolas,monospace;font-size:14px;direction:ltr;display:inline-block}
pre{background:#2C3E50;color:#ECF0F1;padding:16px;border-radius:6px;overflow-x:auto;direction:ltr;text-align:left}
pre code{background:none;color:inherit;padding:0}
blockquote{background:#FFF8E7;border-right:4px solid var(--wr);margin:16px 0;padding:12px 16px;border-radius:0 6px 6px 0}
blockquote p{margin:0}
ul,ol{padding-right:24px}
li{margin:6px 0}
a{color:var(--p)}
.toc{background:var(--bg);border:1px solid var(--bd);border-radius:6px;padding:16px 24px;margin-bottom:28px}
@media print{body{background:#fff;padding:0}.wrap{box-shadow:none;border:0}}
@media (max-width:768px){.wrap{padding:20px 16px}table{font-size:14px}th,td{padding:8px}}
"""

src, dst, title = sys.argv[1], sys.argv[2], sys.argv[3]
body = convert(open(src, encoding='utf-8').read())
doc = ('<!DOCTYPE html>\n<html lang="fa" dir="rtl">\n<head>\n<meta charset="UTF-8">\n'
       '<meta name="viewport" content="width=device-width, initial-scale=1">\n'
       '<title>' + html.escape(title) + '</title>\n<style>' + CSS + '</style>\n</head>\n<body>\n'
       '<div class="wrap">\n' + body + '\n</div>\n</body>\n</html>\n')
open(dst, 'w', encoding='utf-8').write(doc)
print('wrote', dst, len(doc), 'bytes')
