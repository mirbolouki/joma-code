#!/usr/bin/env python3
# E2E: freemium trial/lock/grant in file mode
import urllib.request, urllib.parse, urllib.error, http.cookiejar, re, json, sys

BASE = 'http://localhost:8090/'

class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.op = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar),
            NoRedirect(),
        )
    def get(self, url, follow=False):
        req = urllib.request.Request(BASE + url, headers={'User-Agent': 'e2e'})
        try:
            r = self.op.open(req, timeout=60)
            return r.status, dict(r.headers), r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode('utf-8', 'replace')
    def post(self, url, data):
        enc = urllib.parse.urlencode(data).encode()
        req = urllib.request.Request(BASE + url, data=enc, headers={'User-Agent': 'e2e', 'Content-Type': 'application/x-www-form-urlencoded'})
        try:
            r = self.op.open(req, timeout=60)
            return r.status, dict(r.headers), r.read().decode('utf-8', 'replace')
        except urllib.error.HTTPError as e:
            return e.code, dict(e.headers), e.read().decode('utf-8', 'replace')
    def csrf(self, page='index.php?p=login'):
        st, h, b = self.get(page)
        m = re.search(r'name="csrf" value="([^"]+)"', b)
        return m.group(1) if m else None

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None

def expect(cond, label, extra=''):
    print(('PASS' if cond else 'FAIL'), '-', label, extra[:180] if extra else '')
    if not cond: sys.exit(1)

# ---------- admin login ----------
admin = Client()
tok = admin.csrf()
expect(tok, 'login page has csrf')
st, h, b = admin.post('index.php?p=login', {'csrf': tok, 'identifier': 'modir', 'password': 'Joma!1404'})
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 and 'login' not in loc, 'admin login redirect', f'{st} -> {loc}')

# admin console shows the new tool link
st, h, b = admin.get('index.php?p=admin_console')
expect(st == 200, 'admin_console 200')
expect('p=admin_subs' in b, 'admin_console links to admin_subs')

# admin_subs page renders; legacy users grandfathered
st, h, b = admin.get('index.php?p=admin_subs')
expect(st == 200, 'admin_subs 200')
expect('دائمی' in b, 'grandfathered label shown')
expect('@modir' in b and '@maryam' in b, 'users listed')

# ---------- register new user (trial starts) ----------
reg = Client()
tok = reg.csrf('index.php?p=register')
expect(tok, 'register page has csrf')
st, h, b = reg.post('index.php?p=register', {
    'csrf': tok, 'username': 'trialuser', 'password': 'Trial!1404', 'confirm': 'Trial!1404',
    'first_name': 'تست', 'last_name': 'اشتراک', 'email': 't@example.com', 'phone': '09120000000',
    'job': 'فریلنسر', 'accept': '1',
})
loc = h.get('Location') or h.get('location') or ''
expect(st == 302, 'register redirects', f'{st} -> {loc} body={b[:200]}')

store = json.load(open('/home/user/work/test/data/store.json'))
tu = [u for u in store['users'] if u['username'] == 'trialuser']
expect(tu, 'trialuser created in store')
tu = tu[0]
expect(tu.get('trial_until', '') != '', f"trial_until set: {tu.get('trial_until')}")
expect(not tu.get('subscription_until'), 'subscription_until empty')

# login as trialuser
tu_c = Client()
tok = tu_c.csrf()
st, h, b = tu_c.post('index.php?p=login', {'csrf': tok, 'identifier': 'trialuser', 'password': 'Trial!1404'})
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 and 'login' not in loc, 'trialuser login redirect', f'{st} -> {loc}')

st, h, b = tu_c.get('index.php?p=dashboard')
expect(st == 200, 'trialuser dashboard 200')
expect('در دورهٔ رایگان هستی' in b, 'trial banner visible on dashboard')
expect('۰۹۹۶۷۹۷۹۴۷۱' in b or '09967979471' in b, 'support number mentioned in banner')

# ---------- admin locks the user ----------
tok = admin.csrf('index.php?p=admin_subs')
st, h, b = admin.post('index.php?p=admin_subs', {'csrf': tok, 'uid': tu['id'], 'act': 'lock'})
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 or st == 200, 'lock action accepted', f'{st} -> {loc}')
st, h, b = admin.get('index.php?p=admin_subs')
expect('قفل' in b, 'locked state visible in admin table')

# trialuser now blocked from dashboard -> redirected to locked page
st, h, b = tu_c.get('index.php?p=dashboard')
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 and 'p=locked' in loc, 'locked user redirected from dashboard', f'{st} -> {loc}')
st, h, b = tu_c.get('index.php?p=today')
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 and 'p=locked' in loc, 'locked user redirected from today page')
st, h, b = tu_c.get('index.php?p=reports')
loc = h.get('Location') or h.get('location') or ''
expect(st == 302 and 'p=locked' in loc, 'locked user redirected from reports')

# locked page itself renders with paywall
st, h, b = tu_c.get('index.php?p=locked')
expect(st == 200, 'locked page 200')
expect('دورهٔ رایگان شما تمام شده' in b, 'paywall headline shown')
expect('09967979471' in b or '۰۹۹۶۷۹۷۹۴۷۱' in b, 'contact number on paywall')
expect('trialuser' in b, 'username shown for support contact')
expect('p=logout' in b, 'logout link available')

# logout must NOT bounce back to locked
st, h, b = tu_c.get('index.php?p=logout')
loc = h.get('Location') or h.get('location') or ''
expect('locked' not in loc, 'logout works while locked', f'{st} -> {loc}')

# ---------- admin grants 30 days ----------
tu_c2 = Client()
tok = tu_c2.csrf()
st, h, b = tu_c2.post('index.php?p=login', {'csrf': tok, 'identifier': 'trialuser', 'password': 'Trial!1404'})
tok = admin.csrf('index.php?p=admin_subs')
st, h, b = admin.post('index.php?p=admin_subs', {'csrf': tok, 'uid': tu['id'], 'act': 'grant30'})
st, h, b = admin.get('index.php?p=admin_subs')
expect('اشتراک فعال' in b, 'subscription active chip visible after grant')

st, h, b = tu_c2.get('index.php?p=dashboard')
expect(st == 200, 'granted user can open dashboard again')
expect('در دورهٔ رایگان هستی' not in b, 'trial banner gone after subscription')

# trial reset action
tok = admin.csrf('index.php?p=admin_subs')
st, h, b = admin.post('index.php?p=admin_subs', {'csrf': tok, 'uid': tu['id'], 'act': 'resettrial'})
store = json.load(open('/home/user/work/test/data/store.json'))
tu2 = [u for u in store['users'] if u['username'] == 'trialuser'][0]
expect(tu2.get('trial_until', '') != '' and not tu2.get('subscription_until'), 'reset_trial restores fresh trial')

# unlimited grant
tok = admin.csrf('index.php?p=admin_subs')
st, h, b = admin.post('index.php?p=admin_subs', {'csrf': tok, 'uid': tu['id'], 'act': 'grantforever'})
st, h, b = tu_c2.get('index.php?p=dashboard')
expect(st == 200, 'unlimited user can open dashboard')
st, h, b = admin.get('index.php?p=admin_subs')
expect('اشتراک فعال' in b or 'دائمی' in b, 'forever state shown')

# CSRF guard: wrong token dies with 400
try:
    st, h, b = admin.post('index.php?p=admin_subs', {'csrf': 'wrong', 'uid': tu['id'], 'act': 'lock'})
    expect(st == 400, 'bad csrf rejected', f'{st}')
except Exception as ex:
    expect('400' in str(ex), 'bad csrf rejected (exception)', str(ex))

# admin itself never locked even if forced
tok = admin.csrf('index.php?p=admin_subs')
admin_id = [u for u in store['users'] if u['username'] == 'modir'][0]['id']
st, h, b = admin.post('index.php?p=admin_subs', {'csrf': tok, 'uid': admin_id, 'act': 'lock'})
st, h, b = admin.get('index.php?p=admin_console')
expect(st == 200, 'admin never locked even with lock attempt')

print()
print('ALL E2E PASSED')
