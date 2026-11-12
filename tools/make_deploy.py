# -*- coding: utf-8 -*-
"""Собирает комплект для заливки на хостинг (список из инструкции
«2026-11-05 — восстановление на хостинге — инструкция.md») —
в папку «сайт» в корне рабочей папки, с сохранением структуры.

Запуск из корня newsite:
    python tools/make_deploy.py            # папка сайт/
    python tools/make_deploy.py --zip      # + Work/site-deploy.zip

Проверяет критичные файлы (secrets.php, reviews.js, .htaccess) и печатает
замечания. Папка «сайт» — производственный артефакт, в git не входит.
"""
from __future__ import annotations
import shutil, sys, zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
OUT = ROOT / "сайт"
ZIP = ROOT / "Work" / "site-deploy.zip"

# корень → корень сайта
FILES_ROOT = [
    "secrets.php",
    "index.html", "knowledge.html",
    "editor.html", "kb-editor.html",
    "publish.php", "kb.php", "reviews.php",
    "robots.txt", "sitemap.xml",
]
DIRS = ["css", "js", "assets", "images"]
FILES_KB = sorted(p.name for p in (ROOT / "kb").glob("*.html"))
FILES_DATA = ["schedule.js", "knowledge.js", "reviews.js"]
FILES_AIPLAN = ["index.html", "plan.php"]
HTACCESS = '<FilesMatch "\\.(json|txt|log)$">\nRequire all denied\n</FilesMatch>'
# служебные каталоги, которые PHP создаёт сам, но которые обязаны быть
# закрыты с первого же запроса: в них планы с данными бегуна (out/),
# бэкапы отзывов (data/_backups/) и IP-хэши лимитов (data/_rate/)
RUNTIME_DIRS = ["ai-plan/data", "ai-plan/out", "data/_backups", "data/_rate"]


def main(argv):
    do_zip = "--zip" in argv
    if OUT.exists():
        shutil.rmtree(OUT)
    missing = []
    notes = []

    for name in FILES_ROOT:
        src = ROOT / name
        if src.exists():
            (OUT / name).parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(src, OUT / name)
        else:
            missing.append(name)

    for d in DIRS:
        srcd = ROOT / d
        if not srcd.exists():
            missing.append(d + "/")
            continue
        for src in srcd.iterdir():
            if src.is_file():
                (OUT / d).mkdir(parents=True, exist_ok=True)
                shutil.copy2(src, OUT / d / src.name)

    (OUT / "kb").mkdir()
    for name in FILES_KB:
        shutil.copy2(ROOT / "kb" / name, OUT / "kb" / name)

    datad = OUT / "data"
    datad.mkdir()
    for name in FILES_DATA:
        src = ROOT / "data" / name
        if src.exists():
            shutil.copy2(src, datad / name)
        else:
            missing.append("data/" + name)

    aiplan = OUT / "ai-plan"
    aiplan.mkdir()
    for name in FILES_AIPLAN:
        src = ROOT / "ai-plan" / name
        if src.exists():
            shutil.copy2(src, aiplan / name)
        else:
            missing.append("ai-plan/" + name)
    # служебные каталоги: создаём пустыми и сразу закрываем .htaccess
    # (PHP всё равно попытается их создать, но защита должна стоять до
    # первого запроса — это ПДн и бэкапы)
    for rel in RUNTIME_DIRS:
        dst = OUT / rel
        dst.mkdir(parents=True, exist_ok=True)
        ht_src = ROOT / rel / ".htaccess"
        if ht_src.is_file():
            shutil.copy2(ht_src, dst / ".htaccess")
        else:
            (dst / ".htaccess").write_text(HTACCESS, encoding="utf-8", newline="\n")
            notes.append("нет " + rel + "/.htaccess в рабочей папке — "
                         "подставлен базовый запрет .json/.txt/.log")

    # замечания
    jpg = list((ROOT / "images").glob("*.jpg")) + list((ROOT / "images").glob("*.png"))
    if not jpg:
        notes.append("images/ локально без фото тренировок — фото живут только "
                     "на хостинге; если он их отдаст — докачать в images/ до заливки")
    if missing:
        notes.append("ОТСУТСТВУЕТ локально, в пакет не попало: "
                     + ", ".join(missing) + " — восстановить до заливки!")

    print(f"Комплект: {OUT}")
    n = sum(1 for _ in OUT.rglob("*") if _.is_file())
    print(f"Файлов: {n}")
    for note in notes:
        print("!! " + note)
    if not notes:
        print("!! замечаний нет")

    if do_zip:
        if ZIP.exists():
            ZIP.unlink()
        with zipfile.ZipFile(ZIP, "w", zipfile.ZIP_DEFLATED) as z:
            for f in sorted(OUT.rglob("*")):
                if f.is_file():
                    z.write(f, f.relative_to(OUT))
        print(f"Архив: {ZIP}")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
