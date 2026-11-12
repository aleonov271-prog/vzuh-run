"""CLI: python -m plan_generator <profile.json> [--out <файл>] [--format md|xlsx|all]"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from .assemble import assemble_plan, render_plan, validate_plan
from .profile import load_profile


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(description="Скелет генератора беговых планов")
    ap.add_argument("profile", help="JSON-файл анкеты")
    ap.add_argument("--out", default=None, help="путь к выходному файлу (расширение подставляется)")
    ap.add_argument("--format", choices=("md", "xlsx", "all"), default="all",
                    help="формат вывода (по умолчанию all)")
    args = ap.parse_args(argv)

    profile = load_profile(args.profile)
    plan = assemble_plan(profile)
    warns = validate_plan(plan)

    out_dir = Path(__file__).resolve().parent / "output"
    base = out_dir / f"plan-{plan['dist']}-{profile.race_date.isoformat()}"
    if args.out:
        custom = Path(args.out)
        base = custom.parent / custom.stem  # единый префикс для md и xlsx

    if args.format in ("md", "all"):
        path = base.with_suffix(".md")
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(render_plan(plan), encoding="utf-8")
        print(f"[ok] Markdown: {path}")

    if args.format in ("xlsx", "all"):
        from .xlsx_export import export_plan_xlsx

        path = base.with_suffix(".xlsx")
        export_plan_xlsx(plan, path)
        print(f"[ok] XLSX:     {path}")

    print(f"[ok] Дистанция: {plan['dist']}  недель: {len(plan['weeks'])}  "
          f"прогноз: {plan['race_pace']}/км")
    for z in plan["zones"]:
        print(f"     {z['zone']}  {plan['pace_text'][z['zone']]}")
    if warns:
        print("[warnings]")
        for w in warns:
            print(f"  - {w}")
    return 0


if __name__ == "__main__":
    sys.exit(main())