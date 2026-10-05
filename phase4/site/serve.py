#!/usr/bin/env python3
"""
جوما — سرور کوچک صفحهٔ دانلود بستهٔ فاز ۴.

هیچ فایلی کپی نمی‌شود: نشانی‌های /files/... مستقیم از داخل مخزن خوانده
می‌شوند. به همین دلیل بسته و مستندات همیشه همان نسخهٔ واقعی مخزن‌اند و
امکان «نسخهٔ کهنهٔ کپی‌شده» وجود ندارد.

اجرا:  python3 phase4/site/serve.py [PORT]
"""
import http.server
import mimetypes
import os
import posixpath
import socketserver
import sys
import urllib.parse

PORT = int(sys.argv[1]) if len(sys.argv) > 1 else 8092

HERE = os.path.dirname(os.path.abspath(__file__))        # phase4/site
PHASE4 = os.path.dirname(HERE)                           # phase4
REPO = os.path.dirname(PHASE4)                           # ریشهٔ مخزن

# ریشه‌هایی که /files/<name> در آن‌ها جست‌وجو می‌شود، به همین ترتیب
FILE_ROOTS = [
    PHASE4,
    os.path.join(PHASE4, 'package_docs'),
    os.path.join(REPO, 'phase3'),
    os.path.join(REPO, 'phase3', 'package_docs'),
]

mimetypes.add_type('application/zip', '.zip')
mimetypes.add_type('text/plain; charset=utf-8', '.md')
mimetypes.add_type('text/plain; charset=utf-8', '.txt')
mimetypes.add_type('text/plain; charset=utf-8', '.sql')


def resolve(name):
    """نام فایل را در ریشه‌های مجاز پیدا می‌کند؛ خارج از آن‌ها چیزی سرو نمی‌شود."""
    name = posixpath.basename(name)          # جلوگیری از ../ و مسیرهای تودرتو
    if not name:
        return None
    for root in FILE_ROOTS:
        candidate = os.path.join(root, name)
        if os.path.isfile(candidate):
            return candidate
    return None


class Handler(http.server.BaseHTTPRequestHandler):
    server_version = 'JomaDownload/1.0'

    def log_message(self, fmt, *args):
        sys.stdout.write('%s %s\n' % (self.address_string(), fmt % args))
        sys.stdout.flush()

    def _send(self, status, body, ctype='text/html; charset=utf-8', extra=None):
        self.send_response(status)
        self.send_header('Content-Type', ctype)
        self.send_header('Content-Length', str(len(body)))
        self.send_header('Cache-Control', 'no-store, no-cache, must-revalidate')
        self.send_header('Pragma', 'no-cache')
        if extra:
            for k, v in extra.items():
                self.send_header(k, v)
        self.end_headers()
        if self.command != 'HEAD':
            self.wfile.write(body)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        path = urllib.parse.unquote(path)

        if path in ('/', '/index.html'):
            index = os.path.join(HERE, 'index.html')
            with open(index, 'rb') as fh:
                self._send(200, fh.read())
            return

        if path.startswith('/files/'):
            target = resolve(path[len('/files/'):])
            if target is None:
                self._send(404, 'فایل یافت نشد.'.encode('utf-8'),
                           'text/plain; charset=utf-8')
                return
            ctype = mimetypes.guess_type(target)[0] or 'application/octet-stream'
            with open(target, 'rb') as fh:
                data = fh.read()
            extra = None
            if target.endswith('.zip'):
                quoted = urllib.parse.quote(os.path.basename(target))
                extra = {'Content-Disposition':
                         "attachment; filename*=UTF-8''" + quoted}
            self._send(200, data, ctype, extra)
            return

        self._send(404, 'یافت نشد.'.encode('utf-8'), 'text/plain; charset=utf-8')


socketserver.TCPServer.allow_reuse_address = True
with socketserver.TCPServer(('0.0.0.0', PORT), Handler) as httpd:
    print('serving joma phase 4 download page on 0.0.0.0:%d' % PORT)
    print('repo root: %s' % REPO)
    httpd.serve_forever()
