// سلاسل الاختيار: ولاية → محلية → منشأة. الاستخدام: data-cascade على الحاويات
(function () {
    function fill(sel, rows, placeholder) {
        sel.innerHTML = '';
        var o = document.createElement('option'); o.value = ''; o.textContent = placeholder; sel.appendChild(o);
        rows.forEach(function (r) { var op = document.createElement('option'); op.value = r.id; op.textContent = r.name; sel.appendChild(op); });
    }
    function load(type, id, cb) {
        fetch(window.DENGUE_BASE + '/api/lookup.php?type=' + type + '&id=' + encodeURIComponent(id)).then(function (r) { return r.json(); }).then(cb);
    }
    document.addEventListener('DOMContentLoaded', function () {
        var st = document.querySelector('[data-cascade="state"]');
        var lo = document.querySelector('[data-cascade="locality"]');
        var fa = document.querySelector('[data-cascade="facility"]');
        if (!st || !lo) return;
        st.addEventListener('change', function () {
            fill(lo, [], 'اختر المحلية'); if (fa) fill(fa, [], 'اختر المنشأة');
            if (st.value) load('localities', st.value, function (rows) { fill(lo, rows, 'اختر المحلية'); });
        });
        if (fa) lo.addEventListener('change', function () {
            fill(fa, [], 'اختر المنشأة');
            if (lo.value) load('facilities', lo.value, function (rows) { fill(fa, rows, 'اختر المنشأة'); });
        });
    });
})();
