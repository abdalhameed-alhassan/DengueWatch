#!/usr/bin/env python3
"""اختبار دخان HTTP لنسخة النشر. التشغيل (بعد تشغيل الخادم المحلي ووجود قاعدة مستوردة):
   python3 tests/smoke_http.py http://127.0.0.1:8095 [DB_NAME]
يتطلب: pip install requests، وعميل mysql محلي لفحص جدول sessions."""
import re, subprocess, sys, requests
B = sys.argv[1].rstrip('/'); DBN = sys.argv[2] if len(sys.argv) > 2 else 'dengue_dep'
ok = fail = 0
def chk(name, cond, extra=''):
    global ok, fail
    if cond: ok += 1
    else: fail += 1; print('FAIL:', name, extra)
def sql(q): return subprocess.run(['mysql', '-uroot', '-N', '-B', DBN, '-e', q], capture_output=True, text=True).stdout.strip()
def csrf(html): m = re.search(r'name="csrf" value="([^"]+)"', html); return m.group(1) if m else ''

# --- مسارات محجوبة ---
for p in ['/includes/auth.php', '/includes/session_handler.php', '/config/database.php', '/database/database.sql', '/tools/import_database.php',
          '/tools/seed_demo_local.php', '/endpoints/lookup.php', '/.git/config', '/.env', '/README.md', '/tests/smoke_http.py', '/vercel.json', '/api/index.php', '/install.php']:
    r = requests.get(B + p, allow_redirects=False); chk('blocked ' + p, r.status_code == 404, r.status_code)

# --- صفحة الدخول وزر وزارة الصحة ---
s = requests.Session()
r = s.get(B + '/auth/login.php'); chk('login 200', r.status_code == 200)
chk('demo button shown', 'الدخول إلى واجهة وزارة الصحة' in r.text)
chk('no demo/graduation banner', 'نسخة تجريبية للتخرج' not in r.text)
chk('assets linked from root', 'href="/assets/css/style.css' in r.text)
tok = csrf(r.text); chk('csrf present', bool(tok))
chk('session cookie HttpOnly+SameSite', 'HttpOnly' in r.headers.get('Set-Cookie', '') and 'SameSite=Lax' in r.headers.get('Set-Cookie', ''))
sid = s.cookies.get('PHPSESSID'); chk('session row in DB', sql(f"SELECT COUNT(*) FROM sessions WHERE id='{sid}'") == '1')

# --- الدخول بلا csrf مرفوض ---
r = requests.post(B + '/auth/login.php', data={'demo': 'manager'}); chk('demo login without csrf rejected', r.status_code == 400, r.status_code)

# --- الدخول التجريبي ---
r = s.post(B + '/auth/login.php', data={'demo': 'manager', 'csrf': tok}, allow_redirects=False)
chk('demo login redirects to manager', r.status_code == 302 and r.headers.get('Location', '').endswith('/manager/dashboard.php'), (r.status_code, r.headers.get('Location')))
sid2 = s.cookies.get('PHPSESSID'); chk('session id regenerated', sid2 != sid)
chk('old session removed/not valid', sql(f"SELECT COUNT(*) FROM sessions WHERE id='{sid}'") in ('0', '1'))
chk('session user_id stored', sql(f"SELECT user_id FROM sessions WHERE id='{sid2}'") not in ('', 'NULL'))
for pg in ['dashboard', 'localities', 'doctors', 'facilities', 'alerts', 'reports', 'forecast']:
    r = s.get(f'{B}/manager/{pg}.php'); chk(f'manager/{pg} 200', r.status_code == 200 and 'Fatal' not in r.text and 'Warning' not in r.text, r.status_code)
r = s.get(B + '/manager/dashboard.php'); chk('dashboard has KPIs', 'لوحة البيانات الوطنية' in r.text)
chk('no demo banner on dashboard', 'نسخة تجريبية للتخرج' not in r.text)
r = s.get(B + '/manager/reports.php'); chk('no demo banner on reports', 'البيانات تجريبية' not in r.text)
r = s.get(B + '/manager/report_csv.php?type=states'); chk('csv export', r.status_code == 200 and r.content.startswith(b'\xef\xbb\xbf'), r.status_code)
r = s.get(B + '/manager/forecast.php'); chk('forecast page shows results', 'تشغيل التنبؤ' in r.text and 'نموذج توضيحي مبسّط' not in r.text)
r = s.get(B + '/doctor/dashboard.php', allow_redirects=False); chk('manager blocked from doctor area', r.status_code == 302)

# --- وضع القراءة فقط للحساب التجريبي ---
r = s.get(B + '/manager/doctors.php'); t2 = csrf(r.text)
pend = sql("SELECT id FROM users WHERE status='pending' LIMIT 1")
before = sql(f"SELECT status FROM users WHERE id={pend}")
r = s.post(B + '/manager/doctors.php', data={'csrf': t2, 'user_id': pend, 'action': 'approve'}, allow_redirects=False)
chk('demo write blocked (403)', r.status_code == 403, r.status_code)
chk('doctor status unchanged', sql(f"SELECT status FROM users WHERE id={pend}") == before)
r = s.get(B + '/manager/alerts.php'); t3 = csrf(r.text)
r = s.post(B + '/manager/alerts.php', data={'csrf': t3, 'action': 'settings', 'alert_warning_cases': 1}, allow_redirects=False)
chk('demo settings change blocked', r.status_code == 403, r.status_code)
chk('settings unchanged', sql("SELECT `value` FROM settings WHERE `key`='alert_warning_cases'") == '10')

# --- تشغيل التنبؤ مسموح ---
r = s.get(B + '/manager/forecast.php'); t4 = csrf(r.text)
runs0 = int(sql("SELECT COUNT(*) FROM forecast_runs"))
r = s.post(B + '/manager/forecast.php', data={'csrf': t4}, allow_redirects=True)
chk('forecast run allowed', r.status_code == 200 and ('اكتمل التنبؤ' in r.text), r.text[:200] if r.status_code != 200 else '')
chk('forecast run stored', int(sql("SELECT COUNT(*) FROM forecast_runs")) == min(runs0 + 1, 10))
chk('forecast rows = 18 states x 4 weeks', sql("SELECT COUNT(*) FROM forecasts WHERE run_id=(SELECT MAX(id) FROM forecast_runs)") == '72')

# --- الخروج ---
r = s.get(B + '/auth/logout.php', allow_redirects=False); chk('logout redirect', r.status_code == 302)
chk('session row deleted at logout', sql(f"SELECT COUNT(*) FROM sessions WHERE id='{sid2}'") == '0')
r = requests.get(B + '/manager/dashboard.php', cookies={'PHPSESSID': sid2}, allow_redirects=False); chk('old session unusable', r.status_code == 302)
# معرّف جلسة مختلق لا يُقبل (strict mode)
r = requests.get(B + '/auth/login.php', cookies={'PHPSESSID': 'forgedsessionid123'}); chk('forged sid replaced', 'forgedsessionid123' not in r.headers.get('Set-Cookie', ''))

# --- تسجيل طبيب جديد (واجهة التسجيل + القوائم المتسلسلة) ---
s2 = requests.Session(); r = s2.get(B + '/auth/register.php'); chk('register 200', r.status_code == 200); t5 = csrf(r.text)
r = s2.get(B + '/api/lookup.php?type=localities&id=1'); chk('lookup localities json', r.status_code == 200 and len(r.json()) > 3)
loc = r.json()[0]['id']; fac = s2.get(f'{B}/api/lookup.php?type=facilities&id={loc}').json()
chk('lookup facilities json', len(fac) >= 1)
ph = '0999' + str(__import__('random').randint(100000, 999999))
r = s2.post(B + '/auth/register.php', data={'csrf': t5, 'full_name': 'طبيب اختبار', 'phone': ph, 'password': 'Test@12345', 'password2': 'Test@12345',
            'password_confirm': 'Test@12345', 'facility_id': fac[0]['id'], 'license_no': 'SMC-99999', 'state_id': 1, 'locality_id': loc}, allow_redirects=True)
chk('register creates pending doctor', sql(f"SELECT status FROM users WHERE phone='{ph}'") == 'pending', r.text[:300])
sql(f"DELETE FROM users WHERE phone='{ph}'")

# --- دخول عادي (هاتف/كلمة مرور): كلمات المرور المولّدة مجهولة فلا يصح أحد بـ doctor123 ---
s3 = requests.Session(); r = s3.get(B + '/auth/login.php'); t6 = csrf(r.text)
r = s3.post(B + '/auth/login.php', data={'csrf': t6, 'phone': '0911111111', 'password': 'manager123'}, allow_redirects=False)
chk('known demo password rejected', r.status_code == 200 and 'غير صحيحة' in r.text)
r = s3.post(B + '/auth/login.php', data={'csrf': t6, 'phone': '0922222222', 'password': 'doctor123'}, allow_redirects=False)
chk('doctor123 rejected', r.status_code == 200 and 'غير صحيحة' in r.text)
print(f'passed {ok}, failed {fail}')
sys.exit(1 if fail else 0)
