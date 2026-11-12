# -*- coding: utf-8 -*-
"""Офлайн-санити режима «Алгоритм (без ИИ)» из ai-plan/plan.php.

Python-зеркало новых algo-функций PHP + упрощённый серверный календарь
(detWk: фазы/объёмы/разгрузки/подводка — те же правила, что в plan.php).
Проверяет инварианты сборки и печатает превью недель для сличения
с выходом Work/plan_generator.

Запуск:  python algo_sanity.py
"""
from __future__ import annotations
import datetime as dt

# --- PHP-функции (порт) -------------------------------------------

def fmt_pace(s: float) -> str:
    s = int(round(float(s)))
    m, ss = divmod(s, 60)
    if ss == 60:
        m, ss = m + 1, 0
    return f"{m}:{ss:02d}"


def riegel(t, d_from, d_to, exp=1.06):
    return t * (d_to / d_from) ** exp


def zones(rr_km, rr_s):
    mp = riegel(rr_s, rr_km, 42.195) / 42.195
    p10 = riegel(rr_s, rr_km, 10.0) / 10.0
    p3 = riegel(rr_s, rr_km, 3.0, 1.08) / 3.0
    p15 = riegel(rr_s, rr_km, 1.5, 1.10) / 1.5
    return [
        ("E", mp + 60, mp + 90), ("M", mp, mp),
        ("T", p10 + 12, p10 + 12), ("I", p3, p3), ("R", p15, p15),
    ]


def dist_key(km):
    table = (("10k", 10.0), ("5k", 5.0), ("half", 21.1), ("marathon", 42.2))
    for k, d in table:
        if abs(km - d) < 1.0:
            return k
    return min(table, key=lambda t: abs(t[1] - km))[0]


LONG_PEAK = {"5k": 12, "10k": 16, "half": 20, "marathon": 33}
FRACS = {"5k": [0.45], "10k": [0.70, 0.45],
         "half": [0.70, 0.45], "marathon": [0.75, 0.55, 0.40]}
MIN_WK = {"5k": 8, "10k": 10, "half": 12, "marathon": 16}

BASE_POOL = [
    dict(zone="E", est=8, txt="Фартлек E: 8×1 мин быстро / 2 мин джог"),
    dict(zone="E", est=6, txt="Лёгкий бег 6 км + страйды 6×20 сек"),
]
TAPER_POOL = {
    "5k": [dict(zone="I", est=7, txt="Открывашка: 3×1000")],
    "10k": [dict(zone="I", est=8, txt="Открывашка: 5К в целевом темпе")],
    "half": [dict(zone="T", est=9, txt="Открывашка: 2×3К в темпе цели")],
    "marathon": [dict(zone="M", est=7, txt="Открывашка: 3К в темпе цели")],
}
BUILD_POOL = {
    "build1": {
        "5k": [dict(zone="I", est=10, txt="5×1000 I"), dict(zone="T", est=9, txt="2×15 мин T")],
        "10k": [dict(zone="T", est=12, txt="2×20 мин T"), dict(zone="I", est=10, txt="6×1000 I")],
        "half": [dict(zone="T", est=14, txt="T 40-50 мин"), dict(zone="T", est=12, txt="прогрессия T")],
        "marathon": [dict(zone="T", est=13, txt="T 45 мин"), dict(zone="T", est=12, txt="2×20 мин T")],
    },
    "build2": {
        "5k": [dict(zone="I", est=11, txt="6-8×800 I"), dict(zone="R", est=8, txt="8×400 R")],
        "10k": [dict(zone="I", est=13, txt="5×1600 I"), dict(zone="I", est=12, txt="2×(3К+1К)")],
        "half": [dict(zone="T", est=15, txt="2×5К в темпе цели"), dict(zone="T", est=14, txt="10К репетиция")],
        "marathon": [dict(zone="M", est=18, txt="связка T+M"), dict(zone="T", est=11, txt="T 40 мин")],
    },
}


def pool_for(ph, key):
    if ph in ("base", "recovery"):
        return BASE_POOL
    if ph == "taper":
        return TAPER_POOL[key]
    return BUILD_POOL[ph][key]


# --- Календарь detWk (упрощённый порт plan.php:919-1018) -----------

def det_wk(n_total, dist, km_anketa):
    race_ts = 1  # раскладка фаз не зависит от календаря дней
    n_rec = 2 if n_total >= 16 else (1 if n_total >= 10 else 0)
    n_tap = 2 if n_total >= 10 else 1
    if n_rec + n_tap >= n_total:
        n_rec, n_tap = 0, 1
    rest = n_total - n_rec - n_tap
    n_base = int(-(-rest * 55 // 100))
    vol = max(km_anketa, 8)
    cap = min(100, max(12, round(vol * (1.6 if n_total >= 24 else 1.5))))
    weeks = []
    for i in range(1, n_total + 1):
        in_rec, in_tap = i <= n_rec, i > n_total - n_tap
        in_spc = (not in_rec and not in_tap and (i - n_rec) > n_base)
        cut = False
        if not in_rec and not in_tap:
            within = (i - n_rec - n_base) if in_spc else (i - n_rec)
            if within > 1 and within % 4 == 0:
                cut = True
        if in_rec:
            period, volw = "Восстановление", round(vol * 0.8)
        elif in_tap:
            period = "Подводка"
            volw = round(vol * (0.45 if i == n_total else 0.7))
        elif cut:
            period = "Специальная" if in_spc else ("База I" if (i - n_rec) <= -(-n_base // 2) else "База II")
            volw = round(vol * 0.7)
        else:
            period = "Специальная" if in_spc else ("База I" if (i - n_rec) <= -(-n_base // 2) else "База II")
            vol = min(cap, round(vol * (1.04 if in_spc else 1.08)))
            volw = vol
        if not in_tap:
            volw = max(volw, km_anketa)
        focus = "разгрузочная: объём −30%" if cut else ""
        weeks.append(dict(n=i, period=period, volume=volw, focus=focus))
    return weeks


# --- Сборка недели (упрощённый algo_build_plan: слоты по 7 дней) ---

def build(weeks, dist, km_anketa, train_days=(2, 4, 6, 7), race_week_n=None,
          vac_weeks=()):
    key = dist_key(dist)
    peak = LONG_PEAK[key]
    notes = []
    n_build = sum(1 for w in weeks if w["period"] in ("База I", "База II", "Специальная"))
    taper_ns = [w["n"] for w in weeks
                if w["period"] == "Подводка" and w["n"] != race_week_n]
    fracs = {1: [0.45], 2: [0.70, 0.45], 3: [0.75, 0.55, 0.40]}[
        max(1, min(3, len(taper_ns)))]
    out, long_prev, build_done, tap_done = [], 0.0, 0, 0
    for w in weeks:
        ph = {"Восстановление": "recovery", "База I": "base", "База II": "build1",
              "Специальная": "build2", "Подводка": "taper"}[w["period"]]
        is_deload = w["focus"].startswith("разгрузочная")
        vol = float(w["volume"])
        build_ph = ph in ("base", "build1", "build2") and not is_deload
        is_vac = w["n"] in vac_weeks
        is_race = w["n"] == race_week_n

        if is_vac:
            out.append(dict(week=w, days=[("отдых", 0)], vac=True))
            continue
        slots = sorted(train_days)   # недели полные, 7 дней, день 7 = гонки
        days = []
        if is_race:
            days.append(("СТАРТ", round(dist, 1)))
            days.append(("ВОС", 6))
            days.append(("ВОС", 3))
            remaining = max(0, len(slots) - 3)
            per = max(2, min(14, round(max(0.0, vol - 15) / max(1, remaining))))
            for _ in range(remaining):
                days.append(("ВОС", per))
            out.append(dict(week=w, days=days, race=True))
            continue
        n_qual = 1 if (len(slots) <= 4 or ph in ("taper", "recovery") or is_deload) else 2
        long_iso = slots[-1]
        if ph == "taper":
            frac = fracs[tap_done] if tap_done < len(fracs) else 0.45
            long_km = max(6, round(peak * frac))
            tap_done += 1
        elif build_ph:
            t = build_done / (n_build - 1) if n_build > 1 else 0
            start = max(8.0, 0.30 * max(1.0, float(km_anketa)))
            long_km = round(start + t * (peak - start))
            build_done += 1
        else:
            long_km = round(long_prev) if long_prev else max(6, round(0.3 * km_anketa))
        if vol > 0.001:   # универсальный потолок: в разгрузке режется сам лонг
            long_km = min(long_km, int(0.35 * vol))
        long_km = max(4, min(long_km, int(vol)))
        long_prev = long_km
        if vol > 0 and long_km > 0.37 * vol:
            notes.append(f"нед. {w['n']}: лонг >37% объёма")
        pool = pool_for("base" if is_deload else ph, key)
        cand = sorted((i for i in slots if abs(i - long_iso) >= 2),
                      key=lambda i: abs(i - (long_iso - 3)))
        qual = []
        for c in cand:   # как в PHP: пропускаем кандидатов ближе 2 дней к выбранным
            if all(abs(c - q) >= 2 for q in qual):
                qual.append(c)
            if len(qual) >= n_qual:
                break
        qual_est = sum(pool[min(j, len(pool) - 1)]["est"] for j in range(len(qual)))
        easy = [i for i in slots if i != long_iso and i not in qual]
        per = max(2, min(14, round(max(0.0, vol - long_km - qual_est) / max(1, len(easy)))))
        # добор недобора объёма: остаток — в лонг до 45% объёма
        deficit = vol - long_km - qual_est - per * len(easy)
        if deficit > 0 and vol > 0.001:
            add = min(deficit, max(0, int(0.45 * vol) - long_km))
            long_km += add
            deficit -= add
            if deficit >= 3:
                notes.append(f"нед. {w['n']}: объём недобран на {deficit:g} км")
        if len(qual) == 2 and abs(qual[0] - qual[1]) < 2:
            notes.append(f"нед. {w['n']}: качественные подряд")
        ir = sum(pool[min(j, len(pool) - 1)]["est"] for j in range(len(qual))
                 if pool[min(j, len(pool) - 1)]["zone"] in ("I", "R"))
        ir_share = ir / vol if vol else 0
        if vol and ir_share > 0.25:
            notes.append(f"нед. {w['n']}: I+R {ir_share:.0%}")
        for i in slots:
            if i == long_iso:
                days.append(("Д", long_km))
            elif i in qual:
                s = pool[min(qual.index(i), len(pool) - 1)]
                days.append(("QUAL:" + s["zone"], s["est"]))
            else:
                days.append(("ВОС", per))
        out.append(dict(week=w, days=days))
    # глобальные санити
    if len(out) < MIN_WK[key]:
        notes.append(f"недель {len(out)} < нормы {MIN_WK[key]}")
    return out, notes


def check(case_name, res, notes, race_week_n, dist):
    problems = []
    for w in res:
        wk = w["week"]
        if w.get("race"):
            starts = sum(1 for t, _ in w["days"] if t == "СТАРТ")
            if starts != 1:
                problems.append(f"нед. {wk['n']}: стартов {starts} != 1")
            continue
        if w.get("vac"):
            continue
        s = sum(k for _, k in w["days"])
        v = float(wk["volume"])
        if v > 0 and abs(s - v) > max(6, 0.2 * v):
            problems.append(f"нед. {wk['n']}: сумма дней {s:g} != объём {v:g}")
    build_vols = [float(w["week"]["volume"]) for w in res
                  if w["week"]["period"] in ("База I", "База II", "Специальная")]
    if build_vols and res:
        peak_v = max(build_vols)
        last_v = float(res[-2]["week"]["volume"])
        if last_v > 0 and last_v < 0.3 * peak_v:
            notes.append("глубина подводки > -70%")
    print(f"=== {case_name} (дист {dist}) ===")
    for w in res[:6]:
        days_txt = ", ".join(
            f"{t}{' ' + str(k) if t != 'СТАРТ' else ''}" for t, k in w["days"])
        print(f"  н{w['week']['n']:>2} {w['week']['period']:<14} "
              f"{w['week']['volume']:>3} км: {days_txt}")
    print(f"  недель: {len(res)}; последних 2: "
          + "; ".join(f"{w['week']['period']} {w['week']['volume']}км" for w in res[-2:]))
    for n in notes:
        print(f"  NOTE: {n}")
    for p in problems:
        print(f"  PROBLEM: {p}")
    print()
    return problems


cases = [
    ("half 12 нед, 4 дня (2,4,6,7), км/нед 40", dict(dist=21.1, km=40, n=12, train=(2, 4, 6, 7), race_week=12)),
    ("марафон 16 нед + перерыв (н8), 5 дней", dict(dist=42.2, km=50, n=16, train=(2, 4, 5, 6, 7), race_week=16, vac={8})),
    ("5К 8 нед, 3 дня (2,4,7), км/нед 15", dict(dist=5.0, km=15, n=8, train=(2, 4, 7), race_week=8)),
    ("кастом 15 км → ключ по ближайшей, 10 нед", dict(dist=15.0, km=30, n=10, train=(2, 4, 6, 7), race_week=10)),
    ("10К 10 нед, 2 дня → +1 день", dict(dist=10.0, km=20, n=10, train=(4, 7), race_week=10)),
]

fails = 0
for name, c in cases:
    weeks = det_wk(c["n"], c["dist"], c["km"])
    if c.get("vac"):
        for w in weeks:
            if w["n"] in c["vac"]:
                w["volume"] = 0
    res, notes = build(weeks, c["dist"], c["km"], c["train"], c["race_week"], c.get("vac", set()))
    fails += len(check(name, res, notes, c["race_week"], c["dist"]))

print("_zones sample (10К за 52:00):")
for code, a, b in zones(10.0, 52 * 60):
    rng = f"{fmt_pace(a)}-{fmt_pace(b)}" if b > a + 0.01 else fmt_pace(a)
    print(f"  {code}: {rng}/км")
print("key(15) =", dist_key(15.0), "  key(21.1) =", dist_key(21.1))
print("FAILS:", fails)