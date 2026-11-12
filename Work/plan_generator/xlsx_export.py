"""Экспорт плана в XLSX (листы: План, Зоны, Сводка)."""

from __future__ import annotations

from pathlib import Path

from openpyxl import Workbook
from openpyxl.styles import Alignment, Font, PatternFill
from openpyxl.utils import get_column_letter

from .assemble import DAY_NAMES

# Цветовая кодировка типов сессий в сетке недели
KIND_FILL = {
    "easy": "EAF4EA",    # лёгкий
    "quality": "FDEEDC", # качественная
    "long": "E8F0FE",    # лонг
    "race": "FBE3E4",    # гонка
}

PHASE_FILL = {
    "base": "F4FAF4",
    "recovery": "EDF2F7",
    "taper": "F7F1FA",
    "race": "FBE3E4",
}

THIN_FILL_HEADER = "2F3B4C"
WRAP = Alignment(wrap_text=True, vertical="top")
CENTER = Alignment(horizontal="center", vertical="center")


def export_plan_xlsx(plan: dict, path: str | Path) -> Path:
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True)

    wb = Workbook()
    _sheet_plan(wb, plan)
    _sheet_zones(wb, plan)
    _sheet_summary(wb, plan)

    wb.save(path)
    return path


def _header(ws, headers: list[str]):
    for col, text in enumerate(headers, start=1):
        cell = ws.cell(row=1, column=col, value=text)
        cell.font = Font(bold=True, color="FFFFFF")
        cell.fill = PatternFill("solid", fgColor=THIN_FILL_HEADER)
        cell.alignment = CENTER
    ws.freeze_panes = "A2"


def _sheet_plan(wb: Workbook, plan: dict):
    ws = wb.active
    ws.title = "План"
    _header(ws, ["Неделя", "Фаза", "Объём, км", "Лонг, км", *DAY_NAMES])

    for r, wk in enumerate(plan["weeks"], start=2):
        ws.cell(row=r, column=1, value=wk["week"]).alignment = CENTER
        ph = ws.cell(row=r, column=2, value=wk["phase_ru"])
        if wk["phase"] in PHASE_FILL:
            ph.fill = PatternFill("solid", fgColor=PHASE_FILL[wk["phase"]])
        ws.cell(row=r, column=3, value=wk["volume_km"])
        ws.cell(row=r, column=4, value=wk["long_km"])

        for s in wk["sessions"]:
            text = s["text"]
            if s["kind"] in ("quality", "race"):
                text += f" ({s['km']:g}К)"
            cell = ws.cell(row=r, column=5 + s["day"], value=text)
            cell.alignment = WRAP
            cell.fill = PatternFill("solid", fgColor=KIND_FILL[s["kind"]])

    for col, width in enumerate([8, 17, 10, 10, 34, 34, 34, 34, 34, 30, 30], start=1):
        ws.column_dimensions[get_column_letter(col)].width = width


def _sheet_zones(wb: Workbook, plan: dict):
    ws = wb.create_sheet("Зоны")
    _header(ws, ["Зона", "Название", "Темп от", "Темп до"])
    for r, z in enumerate(plan["zones"], start=2):
        low = z["pace"]
        high = z.get("range_to", z["pace"])
        from .zones import fmt_pace
        ws.cell(row=r, column=1, value=z["zone"]).font = Font(bold=True)
        ws.cell(row=r, column=2, value=z["name"])
        ws.cell(row=r, column=3, value=fmt_pace(low))
        ws.cell(row=r, column=4, value=fmt_pace(high) if high != low else "—")
    for col, width in enumerate([10, 26, 12, 12], start=1):
        ws.column_dimensions[get_column_letter(col)].width = width


def _sheet_summary(wb: Workbook, plan: dict):
    ws = wb.create_sheet("Сводка")
    build = [w for w in plan["weeks"] if w["phase"] in ("base", "build1", "build2", "recovery")]
    peak_volume = max(w["volume_km"] for w in build) if build else 0
    peak_long = max(w["long_km"] for w in plan["weeks"]) if plan["weeks"] else 0

    rows = [
        ("Спортсмен", plan["athlete"]),
        ("Дистанция, км", plan["race_km"]),
        ("Дата гонки", plan["race_date"].isoformat()),
        ("Гоночный темп (прогноз), мин/км", plan["race_pace"]),
        ("Недель", len(plan["weeks"])),
        ("Пик объёма, км/нед", peak_volume),
        ("Пик лонга, км", peak_long),
        ("Цель", _goal_text(plan)),
        ("Сгенерировано", plan["generated"].isoformat()),
    ]
    _header(ws, ["Параметр", "Значение"])
    for r, (k, v) in enumerate(rows, start=2):
        ws.cell(row=r, column=1, value=k).font = Font(bold=True)
        ws.cell(row=r, column=2, value=v).alignment = WRAP
    ws.column_dimensions["A"].width = 34
    ws.column_dimensions["B"].width = 30


def _goal_text(plan: dict) -> str:
    goal = plan.get("goal_target_s")
    if not goal:
        return "финишировать"
    return f"{int(goal // 3600)}:{int(goal % 3600 // 60):02d}:{int(goal % 60):02d}"