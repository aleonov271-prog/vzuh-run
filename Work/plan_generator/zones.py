"""Темповые зоны из недавнего результата (шаг 2 алгоритма).

Скелетная модель: прогноз через формулу Ригеля + зоны как смещения
от прогнозных темпов. Для боевой версии заменить на таблицы VDOT
Daniels (см. README, раздел «Точки расширения»).
"""

from __future__ import annotations

RIGEL_EXP = 1.06

# Контрольные дистанции для расчёта зон
DIST_1500 = 1.5
DIST_3K = 3.0
DIST_5K = 5.0
DIST_10K = 10.0
DIST_HALF = 21.0975
DIST_MAR = 42.195


def riegel_time(t_s: float, d_from: float, d_to: float, exp: float = RIGEL_EXP) -> float:
    """Прогноз времени (сек) на дистанцию d_to по результату (d_from км, t_s сек)."""
    return t_s * (d_to / d_from) ** exp


def parse_time(text: str) -> float:
    """'hh:mm:ss' | 'mm:ss' | 'ss' -> секунды."""
    parts = [float(x) for x in str(text).strip().split(":")]
    sec = 0.0
    for p in parts:
        sec = sec * 60 + p
    return sec


def fmt_pace(s_per_km: float) -> str:
    """Сек/км -> 'm:ss'."""
    m = int(s_per_km // 60)
    s = int(round(s_per_km % 60))
    if s == 60:  # округление вверх
        m, s = m + 1, 0
    return f"{m}:{s:02d}"


def zone_paces(recent_time_s: float, recent_km: float) -> list[dict]:
    """Зоны E/M/T/I/R в сек/км. Для E задаётся диапазон (две границы)."""
    pace_mar = riegel_time(recent_time_s, recent_km, DIST_MAR) / DIST_MAR
    pace_10k = riegel_time(recent_time_s, recent_km, DIST_10K) / DIST_10K
    pace_3k = riegel_time(recent_time_s, recent_km, DIST_3K, exp=1.08) / DIST_3K
    pace_1500 = riegel_time(recent_time_s, recent_km, DIST_1500, exp=1.10) / DIST_1500

    return [
        {"zone": "E", "name": "Easy / лёгкий", "pace": pace_mar + 60, "range_to": pace_mar + 90},
        {"zone": "M", "name": "Marathon Pace", "pace": pace_mar},
        {"zone": "T", "name": "Threshold / темпо", "pace": pace_10k + 12},
        {"zone": "I", "name": "Interval / VO2max", "pace": pace_3k},
        {"zone": "R", "name": "Repetition / скорость", "pace": pace_1500},
    ]