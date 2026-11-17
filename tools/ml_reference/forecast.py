#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
ml/forecast.py — نموذج التنبؤ الأسبوعي بحمى الضنك (مبسّط عمداً لتوضيح الفكرة)

الاستخدام:
    python forecast.py  weekly_input.csv  forecast_output.json  [horizon=4]

المدخل (CSV بترميز UTF-8، رأسه):  state_id,state_name,week_start,cases
    أسابيع كاملة فقط (الأسبوع الجاري الناقص يستبعده PHP قبل التصدير).

الفكرة:
  * نموذج انحدار ريدج (Ridge) لكل ولاية على لوغاريتم عدد الحالات log(1+y).
  * المتغيرات: أعداد الأسابيع الأربعة السابقة + نفس الأسبوع في السنة الماضية (إن توفر)
    + موسمية السنة (sin/cos لرقم الأسبوع).
  * التنبؤ لعدة أسابيع قادمة بالتتابع (recursive): كل توقع يغذّي الذي بعده.
  * التقييم: نختبر على آخر 12 أسبوعاً (rolling-origin) ونقارن متوسط الخطأ المطلق MAE
    مع «التوقع الساذج» (تكرار آخر أسبوع)، حتى نعرف هل النموذج أفضل فعلاً.
  * مستوى الخطورة: نسبة التوقع إلى أعلى أسبوع سُجّل تاريخياً لنفس الولاية (≥ 70% مرتفع، ≥ 35% متوسط).

تنبيه علمي: على بيانات تجريبية مولَّدة لا تعبّر أرقام الدقة عن الواقع؛ الهدف توضيح الأسلوب
وقابليته لإعادة التدريب على بيانات وزارة الصحة الفعلية.
"""
import csv, json, math, sys
from datetime import datetime, timedelta
from collections import defaultdict

import numpy as np
from sklearn.linear_model import Ridge

LAGS = (1, 2, 3, 4)
SEASON_LAG = 52
HOLDOUT = 12


def week_of_year(d):
    return d.isocalendar()[1]


def seasonal(d):
    w = week_of_year(d)
    return math.sin(2 * math.pi * w / 52.18), math.cos(2 * math.pi * w / 52.18)


def build_row(hist, dates, t, target_date):
    """متجه المتغيرات للتنبؤ بالأسبوع t اعتماداً على hist[:t] (قيم log1p)."""
    row = [hist[t - l] for l in LAGS]
    row.append(sum(hist[t - l] for l in LAGS) / len(LAGS))
    row.append(hist[t - SEASON_LAG] if t - SEASON_LAG >= 0 else float(np.mean(hist[max(0, t - 8):t])))
    s, c = seasonal(target_date)
    row += [s, c]
    return row


def fit(y_log, dates):
    X, Y = [], []
    start = max(LAGS)
    for t in range(start, len(y_log)):
        X.append(build_row(y_log, dates, t, dates[t]))
        Y.append(y_log[t])
    model = Ridge(alpha=1.0)
    model.fit(np.array(X), np.array(Y))
    resid = np.array(Y) - model.predict(np.array(X))
    return model, float(np.std(resid)) if len(resid) > 2 else 0.3


def forecast_series(model, y_log, dates, horizon):
    h = list(y_log); d = list(dates); out = []
    for _ in range(horizon):
        t = len(h)
        target = d[-1] + timedelta(days=7)
        d.append(target)
        h.append(0.0)  # مكان مؤقت
        row = build_row(h, d, t, target)
        pred = float(model.predict(np.array([row]))[0])
        pred = max(0.0, pred)
        h[-1] = pred
        out.append(pred)
    return out


def main():
    # على ويندوز قد يكون ترميز الطرفية cp1252 فيفشل طباعة العربية؛ نجبره على UTF-8
    try:
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
        sys.stderr.reconfigure(encoding='utf-8', errors='replace')
    except Exception:
        pass
    if len(sys.argv) < 3:
        print(__doc__); sys.exit(1)
    src, dst = sys.argv[1], sys.argv[2]
    horizon = int(sys.argv[3]) if len(sys.argv) > 3 else 4

    data = defaultdict(dict); names = {}
    with open(src, encoding='utf-8-sig', newline='') as f:
        for r in csv.DictReader(f):
            sid = int(r['state_id']); names[sid] = r['state_name']
            data[sid][datetime.strptime(r['week_start'], '%Y-%m-%d').date()] = int(r['cases'])

    err_model, err_naive, n_eval = 0.0, 0.0, 0
    forecasts = []; n_weeks = 0
    for sid, series in sorted(data.items()):
        dates = sorted(series)
        y = [series[d] for d in dates]
        n_weeks = max(n_weeks, len(y))
        if len(y) < HOLDOUT + max(LAGS) + 20:
            raise SystemExit('البيانات غير كافية: نحتاج على الأقل %d أسبوعاً لكل ولاية' % (HOLDOUT + max(LAGS) + 20))
        y_log = [math.log1p(v) for v in y]

        # --- تقييم rolling-origin: 9 نقاط أصل داخل آخر 12 أسبوعاً، أفق 4 أسابيع ---
        for origin in range(len(y) - HOLDOUT, len(y) - horizon + 1):
            m, _ = fit(y_log[:origin], dates[:origin])
            pred = forecast_series(m, y_log[:origin], dates[:origin], horizon)
            for k in range(horizon):
                actual = y[origin + k]
                err_model += abs(math.expm1(pred[k]) - actual)
                err_naive += abs(y[origin - 1] - actual)
                n_eval += 1

        # --- النموذج النهائي على كل البيانات ---
        model, sigma = fit(y_log, dates)
        pred = forecast_series(model, y_log, dates, horizon)
        peak = max(y)  # أعلى عدد أسبوعي سُجّل للولاية
        p60, p85 = 0.35 * peak, 0.70 * peak
        last = dates[-1]
        for k, p in enumerate(pred, start=1):
            val = math.expm1(p)
            lo = max(0.0, math.expm1(p - 1.28 * sigma * math.sqrt(k)))
            hi = math.expm1(p + 1.28 * sigma * math.sqrt(k))
            risk = 'high' if val >= p85 else ('medium' if val >= p60 else 'low')
            forecasts.append({'state_id': sid, 'week_start': (last + timedelta(days=7 * k)).isoformat(),
                              'predicted': round(val, 1), 'lower': round(lo, 1), 'upper': round(hi, 1), 'risk': risk})

    result = {
        'meta': {
            'trained_at': datetime.now().strftime('%Y-%m-%d %H:%M:%S'),
            'model': 'Ridge على log(1+y): 4 تأخيرات + متوسطها + نفس الأسبوع بالسنة السابقة + موسمية',
            'horizon_weeks': horizon, 'n_train_weeks': n_weeks, 'n_states': len(data),
            'mae_model': round(err_model / n_eval, 2), 'mae_naive': round(err_naive / n_eval, 2),
            'n_eval_points': n_eval,
        },
        'forecasts': forecasts,
    }
    with open(dst, 'w', encoding='utf-8') as f:
        json.dump(result, f, ensure_ascii=False, indent=1)
    print('OK', json.dumps(result['meta'], ensure_ascii=True))


if __name__ == '__main__':
    main()
