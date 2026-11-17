# DengueWatch SD — الترصد الذكي لحمى الضنك (نسخة النشر)

منصة ويب (PHP + MySQL) لتسجيل حالات حمى الضنك والتنبؤ بالإصابات الأسبوعية في السودان، بواجهتين: طبيب الوحدة ومدير الترصد بوزارة الصحة.
هذه نسخة جاهزة للنشر على Vercel (vercel-php) مع قاعدة Aiven MySQL. التعليمات الكاملة بالعربية في `docs/DEPLOY_AR.md`.

- `api/index.php` نقطة الدخول الوحيدة (قائمة بيضاء للمسارات) · `public/` الملفات الساكنة · `auth/ doctor/ manager/` الصفحات
- `includes/db_conn.php` اتصال من متغيرات البيئة · `includes/session_handler.php` جلسات في جدول `sessions`
- `includes/ml.php` نموذج التنبؤ (Ridge) بـ PHP · `tools/ml_reference/forecast.py` النسخة البايثونية المرجعية
- `database/database.sql` هيكل + بيانات تجريبية (بلا DROP) · `tools/` أدوات الاستيراد وكلمات المرور
- البيانات كلها تجريبية مولَّدة بنمط موسمي (`is_demo = 1`).
