#!/usr/bin/env python3
# E2E: SMS OTP registration + password recovery (mock mode)
import urllib.request, urllib.parse, urllib.error, http.cookiejar, re, json, sys

BASE = 'http://localhost:8090/'
STORE = '/home/user/work/test/data/store.json'

class NR(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *a, **k): return None

class Client:
    def __init__(self):
        self.op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()), NR())
    def req(self, u, data=None):
        r_ = urllib.request.Request(BASE + u, data=urllib.parse.urlencode(data).encode() if data else None)
        try:
            r = self.op.open(r_, timeout=60); return r.status, dict(r.headers), r.read().decode()
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode()
    def csrf(self, page):
        st, h, b = self.req(page)
        return re.search(r'name="csrf" value="([^"]+)"', b).group(1)

def loc(h): return h.get('location') or h.get('Location')
def expect(c, label, extra=''):
    print(('PASS' if c else 'FAIL'), '-', label, (extra or '')[:160])
    if not c: sys.exit(1)

def reg_form(i):
    phone = '093511122%02d' % i
    return {
        'first_name': 'تست', 'last_name': 'او‌تی‌پی' + str(i),
        'username': 'otptest%d' % i, 'email': 'otptest%d@ex.com' % i,
        'phone': phone, 'job': 'فریلنسر',
        'password': 'Otp!1404', 'confirm': 'Otp!1404', 'accept': '1',
    }

# ---------- 1) register page shows OTP block ----------
c = Client()
tok = c.csrf('index.php?p=register')
st, h, b = c.req('index.php?p=register')
expect('ارسال کد تأیید' in b, 'register page shows OTP block')

# ---------- 2) send code ----------
f = reg_form(1)
st, h, b = c.req('index.php?p=register', dict(f, csrf=tok, otp_action='send'))
expect(st == 200, 'send-code POST re-renders', str(st))
m = re.search(r'کد تأیید: (\d{5})', b)
expect(m, 'mock code displayed', b[:100])
code = m.group(1)
expect('نام="otp_code"' in b or 'name="otp_code"' in b, 'otp_code field now visible')
expect(loc(h) is None or st == 200, 'no redirect on send')

# ---------- 3) wrong code ----------
tok = c.csrf('index.php?p=register')
st, h, b = c.req('index.php?p=register', dict(f, csrf=tok, otp_code='00000'))
d = json.load(open(STORE))
expect('otptest1' not in [u['username'] for u in d['users']], 'account NOT created with wrong code')
expect('کد درست نیست' in b, 'wrong-code error shown')

# ---------- 4) correct code ----------
tok = c.csrf('index.php?p=register')
st, h, b = c.req('index.php?p=register', dict(f, csrf=tok, otp_code=code))
expect(st == 302 and 'p=mood' in (loc(h) or ''), 'correct code -> account created', f'{st} {loc(h)}')
d = json.load(open(STORE))
u = [x for x in d['users'] if x['username'] == 'otptest1']
expect(u, 'otptest1 exists in store')

# ---------- 5) cooldown ----------
f2 = reg_form(2)
f2['phone'] = f['phone']  # same number, different account
c2 = Client()
tok = c2.csrf('index.php?p=register')
st, h, b = c2.req('index.php?p=register', dict(f2, csrf=tok, otp_action='send'))
expect('صبر کن' in b, 'resend cooldown enforced', b[b.find('bad'):b.find('bad')+80] if 'bad' in b else '')

# ---------- 6) forgot password via SMS ----------
# rewind the 2-minute send cooldown (test acceleration only)
import time as _t
d = json.load(open(STORE))
k = 'sms_otp_' + f['phone']
if k in d.get('settings', {}):
    rec = json.loads(d['settings'][k])
    rec['sent_at'] = int(_t.time()) - 300
    rec['sends'] = [int(_t.time()) - 300]
    d['settings'][k] = json.dumps(rec)
    json.dump(d, open(STORE, 'w'), ensure_ascii=False)
c3 = Client()
tok = c3.csrf('index.php?p=forgot')
st, h, b = c3.req('index.php?p=forgot', {'csrf': tok, 'action': 'ask', 'identifier': 'otptest1'})
m = re.search(r'کد تأیید: (\d{5})', b)
expect(m, 'forgot: sms code issued (mock shown)', b[:120])
rcode = m.group(1)
expect('کد تأیید ۵ رقمی' in b, 'forgot: smscode step rendered')

tok = c3.csrf('index.php?p=forgot')
st, h, b = c3.req('index.php?p=forgot', {'csrf': tok, 'action': 'smsreset', 'identifier': 'otptest1',
                                          'otp_code': rcode, 'password': 'NewOtp!1404', 'confirm': 'NewOtp!1404'})
expect(st == 302 and 'p=login' in (loc(h) or ''), 'smsreset ok -> login', f'{st} {loc(h)}')

# login with NEW password works
c4 = Client()
tok = c4.csrf('index.php?p=login')
st, h, b = c4.req('index.php?p=login', {'csrf': tok, 'identifier': 'otptest1', 'password': 'NewOtp!1404'})
expect(st == 302 and 'login' not in (loc(h) or ''), 'login with new password works')
# OLD password no longer works
c5 = Client()
tok = c5.csrf('index.php?p=login')
st, h, b = c5.req('index.php?p=login', {'csrf': tok, 'identifier': 'otptest1', 'password': 'Otp!1404'})
expect(st == 200, 'old password rejected')

# ---------- 7) resend on forgot ----------
d = json.load(open(STORE))
k = 'sms_otp_' + f['phone']
if k in d.get('settings', {}):
    rec = json.loads(d['settings'][k])
    rec['sent_at'] = int(_t.time()) - 300
    rec['sends'] = [int(_t.time()) - 300]
    d['settings'][k] = json.dumps(rec)
    json.dump(d, open(STORE, 'w'), ensure_ascii=False)
c6 = Client()
tok = c6.csrf('index.php?p=forgot')
st, h, b = c6.req('index.php?p=forgot', {'csrf': tok, 'action': 'ask', 'identifier': 'otptest1'})
expect(re.search(r'کد تأیید: (\d{5})', b), 'forgot ask issues code again')
# rewind again for the resend test
d = json.load(open(STORE))
if k in d.get('settings', {}):
    rec = json.loads(d['settings'][k])
    rec['sent_at'] = int(_t.time()) - 300
    rec['sends'] = [int(_t.time()) - 300]
    d['settings'][k] = json.dumps(rec)
    json.dump(d, open(STORE, 'w'), ensure_ascii=False)
tok = c6.csrf('index.php?p=forgot')
st, h, b = c6.req('index.php?p=forgot', {'csrf': tok, 'action': 'resend', 'identifier': 'otptest1'})
expect(st == 200 and ('کد تازه' in b or re.search(r'کد تأیید: (\d{5})', b)), 'resend action works')

# ---------- 8) subscription still intact for new signup ----------
d = json.load(open(STORE))
u = [x for x in d['users'] if x['username'] == 'otptest1'][0]
expect(u.get('trial_until', '') != '', 'trial still auto-started with OTP signup: %s' % u.get('trial_until'))

print()
print('ALL OTP E2E PASSED')
