import { loadNodeRuntime, useHostFilesystem } from '@php-wasm/node';
import { PHP, PHPRequestHandler } from '@php-wasm/universal';
import http from 'http';
import crypto from 'crypto';

const DOCROOT = process.env.DOCROOT || '/home/user/work/preview';
const PORT = parseInt(process.env.PORT || '8090', 10);

const runtimeId = await loadNodeRuntime('8.3', {
  emscriptenOptions: { processId: 7 },
});
const php = new PHP(runtimeId);
useHostFilesystem(php);

const handler = new PHPRequestHandler({
  php,
  documentRoot: DOCROOT,
  absoluteUrl: 'http://localhost:' + PORT,
  cookieStore: false, // disable internal cookie store (it overwrites request cookies)
});

const server = http.createServer(async (req, res) => {
  try {
    const chunks = [];
    for await (const c of req) chunks.push(c);
    const body = Buffer.concat(chunks);
    const headers = { ...req.headers };
    delete headers['host'];
    // wasm build generates deterministic PHPSESSID -> inject random one per cookieless client
    let injectedSid = null;
    if (!headers.cookie || !headers.cookie.includes('PHPSESSID=')) {
      injectedSid = crypto.randomBytes(16).toString('hex');
      headers.cookie = headers.cookie ? headers.cookie + '; PHPSESSID=' + injectedSid : 'PHPSESSID=' + injectedSid;
    }
    const url = 'http://localhost:' + PORT + req.url;
    const phpReq = { method: req.method, url, headers };
    if (!['GET', 'HEAD'].includes(req.method) && body.length) phpReq.body = body;
    const response = await handler.request(phpReq);
    const outHeaders = {};
    for (const [k, v] of Object.entries(response.headers || {})) {
      if (k.toLowerCase() === 'set-cookie') {
        const filtered = (Array.isArray(v) ? v : [v]).filter((c) => !/^PHPSESSID=/.test(String(c)));
        if (filtered.length) outHeaders[k] = filtered;
      } else if (k.toLowerCase() !== 'content-length' && k.toLowerCase() !== 'transfer-encoding') {
        outHeaders[k] = v;
      }
    }
    const buf = Buffer.from(response.bytes || new Uint8Array());
    if (injectedSid) {
      const existing = outHeaders['set-cookie'] || outHeaders['Set-Cookie'];
      const arr = existing ? (Array.isArray(existing) ? existing : [existing]) : [];
      arr.push('PHPSESSID=' + injectedSid + '; Path=/; SameSite=Lax');
      delete outHeaders['Set-Cookie'];
      outHeaders['set-cookie'] = arr;
    }
    outHeaders['content-length'] = buf.length;
    res.writeHead(response.httpStatusCode || 200, outHeaders);
    res.end(buf);
  } catch (e) {
    console.error('REQ ERROR:', e && e.message);
    try { res.writeHead(502, { 'content-type': 'text/plain' }); res.end(String(e)); } catch {}
  }
});

server.listen(PORT, '0.0.0.0', () => console.log('preview listening on ' + PORT + ' docroot ' + DOCROOT));
