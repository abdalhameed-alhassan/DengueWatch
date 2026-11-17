/* assets/js/nav.js
 * قائمة جانبية منزلقة على الجوال: ينشئ زر ☰ وطبقة التعتيم تلقائياً
 * (لا تحتاج الصفحات لأي كود إضافي سوى تضمين هذا الملف). */
(function () {
    function init() {
        var sidebar = document.querySelector('.sidebar');
        var topbar  = document.querySelector('.topbar');
        if (!sidebar || !topbar) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nav-toggle';
        btn.setAttribute('aria-label', 'فتح القائمة');
        btn.setAttribute('aria-expanded', 'false');
        btn.innerHTML = '<span></span><span></span><span></span>';
        topbar.insertBefore(btn, topbar.firstChild);

        var overlay = document.createElement('div');
        overlay.className = 'sidebar-overlay';
        document.body.appendChild(overlay);

        function setOpen(open) {
            document.body.classList.toggle('nav-open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        btn.addEventListener('click', function () {
            setOpen(!document.body.classList.contains('nav-open'));
        });
        overlay.addEventListener('click', function () { setOpen(false); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setOpen(false); });
        window.addEventListener('resize', function () { if (window.innerWidth > 900) setOpen(false); });
        sidebar.querySelectorAll('a').forEach(function (a) {
            a.addEventListener('click', function () { setOpen(false); });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
