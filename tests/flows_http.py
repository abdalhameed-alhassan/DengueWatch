#!/usr/bin/env python3
"""اختبارات إضافية: دخول عادي بعد تعيين كلمة مرور (أداة reset_password)، تسجيل حالة بواسطة طبيب، طلبات متزامنة على جلسة واحدة.
   python3 tests/flows_http.py http://127.0.0.1:8095 DB_NAME"""
import re, subprocess, sys, os, requests, concurrent.futures as cf
B = sys.argv[1].rstrip('/'); DBN = sys.argv[2]
ok = fail = 0
def chk(n, c, x=''):
    global ok, fail
    if c: ok += 1
    else: fail += 1; print('FAIL:', n, x)
def sql(q): return subprocess.run(['mysql', '-uroot', '-N', '-B', DBN, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(h): m = re.search(r'name="csrf" value="([^"]+)"', h); return m.group(1) if m else ''
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
def reset(phone, pw):
    env = dict(os.environ, DB_HOST='localhost', DB_USER='root', DB_PASS='', DB_NAME=DBN, RESET_PHONE=phone, RESET_PASSWORD=pw)
    return subprocess.run(['php', root + '/tools/reset_password.php'], env=env, capture_output=True, text=True)
r = reset('0911111111', 'aaaaaaaa'); chk('weak password refused by tool', r.returncode == 2)
r = reset('0911111111', 'Mgr#Test2026'); chk('tool sets manager password', 'updated' in r.stdout, r.stdout + r.stderr)
r = reset('0922222222', 'Doc#Test2026'); chk('tool sets doctor password', 'updated' in r.stdout, r.stdout + r.stderr)
r = reset('0900000000', 'Doc#Test2026'); chk('tool: unknown phone', 'No active user' in r.stdout)

# manager normal login (full access, not demo)
s = requests.Session(); t = csrf(s.get(B + '/auth/login.php').text)
r = s.post(B + '/auth/login.php', data={'csrf': t, 'phone': '+249 911111111', 'password': 'Mgr#Test2026'}, allow_redirects=False)
chk('manager normal login (+249 format)', r.status_code == 302 and r.headers['Location'].endswith('/manager/dashboard.php'), (r.status_code, r.text[:100]))
r = s.get(B + '/manager/doctors.php'); t2 = csrf(r.text)
pend = sql("SELECT id FROM users WHERE status='pending' LIMIT 1")
r = s.post(B + '/manager/doctors.php', data={'csrf': t2, 'user_id': pend, 'action': 'approve'}, allow_redirects=False)
chk('non-demo manager can approve', sql(f"SELECT status FROM users WHERE id={pend}") == 'active', r.status_code)
sql(f"UPDATE users SET status='pending', approved_by=NULL, approved_at=NULL WHERE id={pend}")

# doctor: login + register a case
d = requests.Session(); t = csrf(d.get(B + '/auth/login.php').text)
r = d.post(B + '/auth/login.php', data={'csrf': t, 'phone': '0922222222', 'password': 'Doc#Test2026'}, allow_redirects=False)
chk('doctor login', r.status_code == 302 and r.headers['Location'].endswith('/doctor/dashboard.php'), r.status_code)
r = d.get(B + '/doctor/new_case.php'); t = csrf(r.text); chk('new_case page', r.status_code == 200 and bool(t))
n0 = int(sql("SELECT COUNT(*) FROM cases")); import datetime
form = {'csrf': t, 'onset_date': (datetime.date.today() - datetime.timedelta(days=2)).isoformat(), 'age_years': 30, 'sex': 'female',
        'classification': 'confirmed', 'test_type': 'ns1', 'severity': 'mild', 'hospitalized': '0', 'outcome': 'under_treatment', 'notes': 'اختبار'}
r = d.post(B + '/doctor/new_case.php', data=form, allow_redirects=False)
chk('doctor registers case', r.status_code == 302 and int(sql("SELECT COUNT(*) FROM cases")) == n0 + 1, (r.status_code, r.text[:200]))
sql("DELETE FROM cases WHERE doctor_id=(SELECT id FROM users WHERE phone='0922222222') AND is_demo=0")
r = d.get(B + '/manager/dashboard.php', allow_redirects=False); chk('doctor blocked from manager', r.status_code == 302)
r = d.get(B + '/doctor/my_cases.php'); chk('my_cases ok', r.status_code == 200)

# concurrency: 24 parallel requests on one session, no lost update / errors
c = requests.Session(); t = csrf(c.get(B + '/auth/login.php').text)
c.post(B + '/auth/login.php', data={'csrf': t, 'phone': '0911111111', 'password': 'Mgr#Test2026'})
def hit(i): return requests.get(B + '/manager/dashboard.php', cookies=c.cookies.get_dict()).status_code
with cf.ThreadPoolExecutor(12) as ex: codes = list(ex.map(hit, range(24)))
chk('24 parallel dashboard requests all 200', all(x == 200 for x in codes), codes)
def fresh(i): return requests.get(B + '/auth/login.php').status_code
with cf.ThreadPoolExecutor(16) as ex: codes = list(ex.map(fresh, range(60)))
chk('60 parallel new visitors all 200 (no deadlock)', all(x == 200 for x in codes), set(codes))
# إعادة كلمات المرور إلى قيم عشوائية مجهولة (تنظيف قاعدة الاختبار)
import secrets
reset('0911111111', secrets.token_urlsafe(24)); reset('0922222222', secrets.token_urlsafe(24))
print(f'passed {ok}, failed {fail}'); sys.exit(1 if fail else 0)
