# Скелет генератора беговых тренировочных планов

Реализация алгоритма из `../2026-11-01-running-plan-algorithm.md` (шаги 1–7).

## Запуск

```bash
python -m plan_generator plan_generator/profiles/sample_profile.json
# план: plan_generator/output/plan-half-2027-02-14.md + .xlsx

python -m plan_generator profile.json --format xlsx   # только Excel
python -m plan_generator profile.json --out out/plan  # префикс для обоих файлов
```

Требования: Python 3.10+, `openpyxl` (для XLSX). Остальное — стандартная библиотека.

## Структура

| Файл | Шаг алгоритма | Содержимое |
|---|---|---|
| `profile.py` | 1 | Анкета: `AthleteProfile`, загрузка JSON |
| `zones.py` | 2 | Ригель → зоны E/M/T/I/R (сек/км) |
| `volume.py` | 3, 5 | Блоки периодизации, недельный километраж, длинный бег |
| `sessions.py` | 4 | Матрица «блок × дистанция → сессии», открывашки, МР-лонги |
| `assemble.py` | 6, 7 | Каркас недели, валидация, рендеринг markdown |
| `xlsx_export.py` | — | XLSX: листы «План» (сетка недель × дни, цвет по типу сессии), «Зоны», «Сводка» |
| `__main__.py` | — | CLI |

## Схема профиля (JSON)

```jsonc
{
  "name": "...",                          // опционально
  "race_distance_km": 21.0975,            // 5 / 10 / 21.0975 / 42.195
  "race_date": "YYYY-MM-DD",
  "goal": { "type": "time", "target": "01:50:00" },  // или {"type":"finish"}
  "recent_race": { "distance_km": 10, "time": "00:52:00" },
  "current_weekly_km": 40,                // V_start шага 5
  "days_per_week": 5,                     // 3–6
  "experience_years": 2,
  "injuries": [],
  "target_peak_weekly_km": 70             // опциональный ограничитель пика
}
```

## Что сознательно упрощено (точки расширения)

1. **Зоны** — упрощённая модель Ригеля вместо таблиц VDOT Daniels; подключить
   таблицы в `zones.zone_paces()` без изменения остального кода.
2. **Границы недель** — считаются полными (`days // 7 + 1`), не выровнены
   по дню гонки; выравнивание — в `volume.build_volume_plan`.
3. **Анкета** — травмы/рельеф загружаются, но пока не влияют на выбор сессий;
   фильтр сессий по `injuries` — расширение `sessions.py`.
4. **Адаптация (шаг 7)** — пересчёт зон по новому контрольному результату
   реализуется повторным запуском с обновлённым `recent_race`; автоматический
   пересчёт по середине плана — TODO.
5. **XLSX** — типы сессий и фазы раскрашены через константы `KIND_FILL`/`PHASE_FILL`
   в `xlsx_export.py`; добавить легенду/чарты к недельному объёму при желании.
6. **Validation-пороги** вынесены прямо в `assemble.validate_plan` — при развитии
   вынести в конфиг.