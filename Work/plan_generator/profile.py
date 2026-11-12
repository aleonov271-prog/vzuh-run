"""Анкета спортсмена (шаг 1 алгоритма): загрузка профиля из JSON."""

from __future__ import annotations

import json
from dataclasses import dataclass, field
from datetime import date
from pathlib import Path

from .zones import parse_time


@dataclass
class AthleteProfile:
    name: str
    race_distance_km: float
    race_date: date
    recent_race_km: float
    recent_race_time_s: float
    current_weekly_km: float
    days_per_week: int = 4
    experience_years: float = 0.0
    injuries: list[str] = field(default_factory=list)
    goal_type: str = "finish"  # 'time' | 'finish'
    goal_target_s: float | None = None
    # Опциональный ограничитель пика недельного объёма (км/нед)
    target_peak_weekly_km: float | None = None


def load_profile(path: str | Path) -> AthleteProfile:
    data = json.loads(Path(path).read_text(encoding="utf-8-sig"))
    race = data["recent_race"]
    goal = data.get("goal", {})
    target = goal.get("target")
    peak = data.get("target_peak_weekly_km")
    return AthleteProfile(
        name=data.get("name", "Runner"),
        race_distance_km=float(data["race_distance_km"]),
        race_date=date.fromisoformat(data["race_date"]),
        recent_race_km=float(race["distance_km"]),
        recent_race_time_s=parse_time(race["time"]),
        current_weekly_km=float(data["current_weekly_km"]),
        days_per_week=int(data.get("days_per_week", 4)),
        experience_years=float(data.get("experience_years", 0)),
        injuries=list(data.get("injuries", [])),
        goal_type=goal.get("type", "finish"),
        goal_target_s=parse_time(target) if target else None,
        target_peak_weekly_km=float(peak) if peak else None,
    )