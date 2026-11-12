"""Шаги 3 и 5 алгоритма: блоки периодизации и недельный километраж.

V(неделя) = V_start × прогресс-фактор × фазовый коэффициент × recovery-коэффициент
"""

from __future__ import annotations

import datetime as dt

# Параметры цикла по дистанции (спецификация: шаг 3 и шаг 5)
RACE_CFG = {
    "5k":       dict(min_weeks=8,  taper=2, build1=3, build2=3, long_peak=12),
    "10k":      dict(min_weeks=10, taper=2, build1=3, build2=3, long_peak=16),
    "half":     dict(min_weeks=12, taper=2, build1=3, build2=3, long_peak=20),
    "marathon": dict(min_weeks=16, taper=3, build1=4, build2=4, long_peak=33),
}

# Доля лонга от недельного объёма (ограничитель валидации шага 6)
LONG_VOLUME_CAP = {  # в build-неделях
    "marathon": 0.35,
}
DEFAULT_LONG_CAP = 0.30

# Каскад тапера: доля от пикового лонга по порядковой тапер-неделе
TAPER_LONG_FRAC = {1: [0.45], 2: [0.70, 0.45], 3: [0.75, 0.55, 0.40]}


def dist_key_of(km: float) -> str:
    """10.5 -> '10k'; промежуточные значения — к ближайшей ключевой дистанции."""
    table = (("5k", 5.0), ("10k", 10.0), ("half", 21.0975), ("marathon", 42.195))
    for key, d in table:
        if abs(km - d) < 1.0:
            return key
    return min(table, key=lambda t: abs(t[1] - km))[0]


def _phase_list(weeks: int, cfg: dict) -> list[str]:
    """Раскладка недель по фазам; recovery вставляется на каждый 4-й строительный месяц."""
    n_taper = min(cfg["taper"], max(0, weeks - 1))
    rest = max(0, weeks - 1 - n_taper)  # минус гоночная неделя
    n_b2 = min(cfg["build2"], max(0, rest - 2))
    n_b1 = min(cfg["build1"], max(0, rest - n_b2))
    n_base = max(0, rest - n_b1 - n_b2)

    phases = (
        ["base"] * n_base
        + ["build1"] * n_b1
        + ["build2"] * n_b2
        + ["taper"] * n_taper
        + ["race"]
    )
    # Схема «3+1»: каждый 4-й строительный месяц = recovery-неделя
    out, run = [], 0
    for ph in phases:
        if ph in ("base", "build1", "build2"):
            run += 1
            if run == 4:
                out.append("recovery")
                run = 0
                continue
        out.append(ph)
    return out[:weeks]


def _volumes(profile, phases: list[str]) -> list[float]:
    """Прогрессия +6 %/нед с recovery-неделями (−20 %) и тапером (−30 %/нед)."""
    v = profile.current_weekly_km
    cap = profile.target_peak_weekly_km or float("inf")
    out = []
    for ph in phases:
        if ph == "recovery":
            out.append(round(v * 0.8))  # v сам не растёт
        elif ph == "taper":
            v = round(v * 0.7)
            out.append(v)
        elif ph == "race":
            out.append(round(profile.race_distance_km))
        else:
            out.append(round(v))
            v = min(v * 1.06, cap)
    return out


def _longs(profile, phases: list[str], dist: str, vols: list[float], cfg: dict) -> list[float]:
    """Длинный бег: линейный рост от текущего уровня к пику к концу build2."""
    peak = cfg["long_peak"]
    start = max(8.0, 0.30 * profile.current_weekly_km)
    n_build = sum(1 for ph in phases if ph in ("base", "build1", "build2"))
    taper_f = TAPER_LONG_FRAC.get(cfg["taper"], [0.45])
    cap = LONG_VOLUME_CAP.get(dist, DEFAULT_LONG_CAP)

    out, prev, done, ti = [], start, 0, 0
    for i, ph in enumerate(phases):
        if ph in ("base", "build1", "build2"):
            done += 1
            t = (done - 1) / max(1, n_build - 1)
            lg = min(start + t * (peak - start), cap * vols[i]) if vols[i] else start
            out.append(round(lg))
            prev = out[-1]
        elif ph == "recovery":
            out.append(prev)  # не растёт, но и не падает ниже прошлого уровня
        elif ph == "taper":
            out.append(round(peak * taper_f[ti]) if ti < len(taper_f) else prev)
            ti += 1
        else:  # race — лонг-слот становится гонкой
            out.append(round(profile.race_distance_km))
    return out


def build_volume_plan(profile, today: dt.date | None = None) -> list[dict]:
    """Возвращает построчные данные недель: фаза, объём, лонг."""
    today = today or dt.date.today()
    dist = dist_key_of(profile.race_distance_km)
    cfg = RACE_CFG[dist]

    days = (profile.race_date - today).days
    if days <= 0:
        raise ValueError(f"Дата гонки в прошлом или сегодня: {profile.race_date}")
    weeks = days // 7 + 1
    # TODO: выравнивать границы недель по дню гонки; скелет считает недели полными.

    phases = _phase_list(weeks, cfg)
    vols = _volumes(profile, phases)
    longs = _longs(profile, phases, dist, vols, cfg)

    rows = []
    for i, (ph, v, lg) in enumerate(zip(phases, vols, longs), start=1):
        if ph == "race":
            v = round(profile.race_distance_km + (18 if dist == "marathon" else 5))
        rows.append({
            "week": i,
            "phase": ph,
            "volume_km": v,
            "long_km": lg,
            "short_plan": weeks < cfg["min_weeks"],  # предупреждение выводит CLI
        })
    return rows