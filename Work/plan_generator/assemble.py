"""Шаги 6 и 7 алгоритма: сборка недель, валидация, рендеринг в markdown."""

from __future__ import annotations

import datetime as dt

from .profile import AthleteProfile
from .sessions import TAPER_QUALITY, long_template_for, quality_pool_for
from .volume import build_volume_plan, dist_key_of
from .zones import fmt_pace, riegel_time

DAY_NAMES = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"]

PHASE_RU = {
    "base": "База",
    "build1": "Конструкция I",
    "build2": "Специализация",
    "recovery": "Восстановительная",
    "taper": "Подводка",
    "race": "Гоночная неделя",
}

# Каркас недели: (индекс дня 0=Пн..6=Вс, тип слота). Лонг — по воскресеньям.
LAYOUTS = {
    3: [(2, "quality"), (4, "easy"), (6, "long")],
    4: [(1, "easy"), (2, "quality"), (5, "easy"), (6, "long")],
    5: [(1, "easy"), (2, "quality"), (3, "easy"), (5, "quality"), (6, "long")],
    6: [(0, "easy"), (1, "quality"), (2, "easy"), (4, "easy"), (5, "quality"), (6, "long")],
}

# Гоночный темп по зоне для «репетиций» и дня гонки
RACE_ZONE = {"5k": "I", "10k": "I", "half": "T", "marathon": "M"}


def assemble_plan(profile: AthleteProfile, today: dt.date | None = None) -> dict:
    today = today or dt.date.today()
    dist = dist_key_of(profile.race_distance_km)
    zones = _safe_zones(profile)
    ptext = {z["zone"]: fmt_pace(z["pace"]) for z in zones}

    rows = build_volume_plan(profile, today)
    days = min(max(profile.days_per_week, 3), 6)
    layout = LAYOUTS[days]

    weeks_out = []
    for wk in rows:
        ph = wk["phase"]
        sessions = (
            _race_week_sessions(profile, dist, layout, ptext)
            if ph == "race"
            else _sessions_for_week(dist, ph, wk, layout, days, ptext)
        )
        weeks_out.append({
            "week": wk["week"],
            "phase": ph,
            "phase_ru": PHASE_RU[ph],
            "volume_km": wk["volume_km"],
            "long_km": wk["long_km"],
            "sessions": sessions,
            "short_plan": wk["short_plan"],
        })

    race_pace = (
        riegel_time(profile.recent_race_time_s, profile.recent_race_km, profile.race_distance_km)
        / profile.race_distance_km
    )
    return {
        "athlete": profile.name,
        "dist": dist,
        "race_km": profile.race_distance_km,
        "race_date": profile.race_date,
        "race_pace": fmt_pace(race_pace),
        "goal_target_s": profile.goal_target_s,
        "zones": zones,
        "pace_text": ptext,
        "weeks": weeks_out,
        "generated": today,
    }


def _safe_zones(profile: AthleteProfile) -> list[dict]:
    # Локальный импорт против циркулярной зависимости profile -> zones
    from .zones import zone_paces

    return zone_paces(profile.recent_race_time_s, profile.recent_race_km)


def _sessions_for_week(dist, ph, wk, layout, days, ptext) -> list[dict]:
    pool = quality_pool_for(ph, dist)
    n_qual = 1 if (days <= 4 or ph in ("taper", "recovery")) else 2
    qual_pool = pool[:n_qual]
    qual_days = [d for d, kind in layout if kind == "quality"][:n_qual]

    qual_km = sum(s["est_km"] for s in qual_pool)
    easy_days = [d for d, kind in layout if kind == "easy"]
    easy_total = wk["volume_km"] - wk["long_km"] - qual_km
    per_easy = max(0, round(easy_total / max(1, len(easy_days))))

    sessions, qi = [], 0
    for d, kind in layout:
        if kind == "long":
            text = long_template_for(dist, ph).format(km=wk["long_km"], **ptext)
            sessions.append({"day": d, "kind": "long", "km": wk["long_km"], "text": text})
        elif kind == "quality" and qi < len(qual_pool):
            s = qual_pool[qi]
            qi += 1
            sessions.append({
                "day": d, "kind": "quality", "zone": s["zone"],
                "km": s["est_km"], "text": s["name"].format(**ptext),
            })
        else:
            sessions.append({
                "day": d, "kind": "easy", "km": per_easy,
                "text": f"Лёгкий бег {per_easy}К (E {ptext['E']}/км)",
            })
    return sessions


def _race_week_sessions(profile, dist, layout, ptext) -> list[dict]:
    race_zone = RACE_ZONE[dist]
    opener = TAPER_QUALITY[dist][0]
    opener_placed = False
    sessions = []
    for d, kind in layout:
        if kind == "long":
            sessions.append({
                "day": d, "kind": "race", "km": profile.race_distance_km,
                "text": f"🏁 ГОНКА: {profile.race_distance_km:g}К — гоночный темп ({ptext[race_zone]}/км)",
            })
        elif kind == "quality" and not opener_placed:
            # первый качественный слот (обычно Ср, 4 дня до воскресной гонки)
            opener_placed = True
            sessions.append({
                "day": d, "kind": "quality", "zone": opener["zone"], "km": 5,
                "text": opener["name"].format(**ptext),
            })
        else:
            sessions.append({
                "day": d, "kind": "easy", "km": 5,
                "text": f"Разминка/выхлоп: 5К (E {ptext['E']}/км) + 4 страйда",
            })
    return sessions


def validate_plan(plan: dict) -> list[str]:
    """Проверки валидности шага 6. Возвращает список предупреждений."""
    warns = []

    build = [w for w in plan["weeks"] if w["phase"] in ("base", "build1", "build2", "recovery")]
    if build:
        peak = max(w["volume_km"] for w in build)
        last_taper = [w for w in plan["weeks"] if w["phase"] == "taper"]
        if last_taper and last_taper[-1]["volume_km"] < 0.3 * peak:
            warns.append(
                f"Тапер глубже −70 %: последняя тапер-неделя {last_taper[-1]['volume_km']}К "
                f"при строительном пике {peak}К"
            )

    for wk in plan["weeks"]:
        v = wk["volume_km"]
        if wk["phase"] == "race":
            continue  # гонка сама является «лонгом» недели — ограничитель не применяем
        if v and wk["long_km"] > 0.35 * v:
            warns.append(
                f"Неделя {wk['week']}: лонг {wk['long_km']}К = {wk['long_km'] / v:.0%} объёма (> 30–35 %)"
            )

        qual_days = sorted(s["day"] for s in wk["sessions"] if s["kind"] == "quality")
        if len(qual_days) > 1:
            if days_used(wk) <= 4:
                warns.append(f"Неделя {wk['week']}: 2 качественные при {days_used(wk)} днях бега")
            if any(b - a <= 1 for a, b in zip(qual_days, qual_days[1:])):
                warns.append(f"Неделя {wk['week']}: качественные дни подряд (нужно ≥ 48 ч)")

        iv_km = sum(
            s["km"] for s in wk["sessions"]
            if s["kind"] == "quality" and s.get("zone") in ("I", "R")
        )
        if v and iv_km / v > 0.25:
            warns.append(f"Неделя {wk['week']}: доля I+R ≈ {iv_km / v:.0%} объёма (> 25 %)")

    if plan["weeks"] and plan["weeks"][0].get("short_plan"):
        warns.append("Недель меньше нормы для дистанции: цель или срок стоит пересмотреть")
    return warns


def days_used(wk) -> int:
    return len(wk["sessions"])


def render_plan(plan: dict) -> str:
    p = plan
    lines = [
        f"# План тренировок: {p['race_km']:g} км — к {p['race_date'].isoformat()}",
        "",
        f"**Спортсмен:** {p['athlete']}  ",
        f"**Сгенерировано:** {p['generated'].isoformat()} (скелет генератора)",
        "",
    ]
    if p["goal_target_s"]:
        goal = p["goal_target_s"]
        lines.append(f"**Цель:** {int(goal // 3600)}:{int(goal % 3600 // 60):02d}:{int(goal % 60):02d}  ")
    lines.append(f"**Прогноз по текущей форме:** {p['race_pace']} /км  ")
    lines.append("")
    lines.append("## Темповые зоны")
    lines.append("")
    lines.append("| Зона | Темп (мин/км) |")
    lines.append("|---|---|")
    for z in p["zones"]:
        rng = (
            f"{fmt_pace(z['pace'])}–{fmt_pace(z['range_to'])}"
            if "range_to" in z else fmt_pace(z["pace"])
        )
        lines.append(f"| {z['zone']} ({z['name']}) | {rng} |")

    lines.extend(["", "## Недельная раскладка"])
    for wk in p["weeks"]:
        lines.extend([
            "",
            f"### Неделя {wk['week']} — {wk['phase_ru']} — {wk['volume_km']} км",
        ])
        for s in sorted(wk["sessions"], key=lambda s: s["day"]):
            lines.append(f"- **{DAY_NAMES[s['day']]}** — {s['text']}")

    lines.extend([
        "",
        "## Примечания (скелет)",
        "- План — отправная точка: каждые 2–3 недели пересчитывать зоны по новому результату (шаг 7).",
        "- Границы недель не выровнены по дню гонки (TODO в volume.py).",
        "- Зоны по упрощённой модели Ригеля; боевые значения — таблицы VDOT Daniels.",
    ])
    return "\n".join(lines) + "\n"