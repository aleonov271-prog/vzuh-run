#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Самопроверка сайта ВЖУХ перед коммитом и заливкой на хостинг.

    python tools/selfcheck.py                # быстрые проверки (без PHP-сервера)
    python tools/selfcheck.py --full         # + функциональные тесты генератора
    python tools/selfcheck.py --full --keep  # не удалять временный каталог тестов

Что проверяется:
  LINT      php -l по всем PHP (если есть локальный PHP), node --check по JS
  LINKS     sitemap.xml → файлы, локальные href/src в страницах
  KIT       «сайт/» собран из текущих файлов, служебные каталоги закрыты
  SECRETS   реальные значения из secrets.php не попали в файлы под git
  FUNC      (--full) генератор «Алгоритм»: разбор времени, зоны, тексты,
            новичковый трек, квота; страховка от fatal; публикация и бэкапы

Локальный PHP ожидается в tools/php/php.exe (в git не входит, см. README).
Без него LINT и FUNC пропускаются, остальное работает.
"""
from __future__ import annotations

import argparse
import glob
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
import zipfile
import xml.etree.ElementTree as ET

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SITE = os.path.join(ROOT, "сайт")
PHP = os.path.join(ROOT, "tools", "php", "php.exe")
SKIP_DIRS = {".git", "tools/php", "_Ненужности", "сайт", "Work", "docs", "github", ".claude"}
TEXT_EXT = {".html", ".php", ".js", ".css", ".md", ".txt", ".xml", ".json", ".htaccess", ".py"}

RESULTS = []


def check(name, cond, detail="", skip=False):
    if skip:
        print("SKIP  " + name)
        return
    RESULTS.append((name, bool(cond)))
    print(("PASS  " if cond else "FAIL  ") + name + (("  — " + str(detail)) if detail and not cond else ""))


def walk_files():
    for base, dirs, files in os.walk(ROOT):
        rel = os.path.relpath(base, ROOT).replace("\\", "/")
        dirs[:] = [d for d in dirs if (d if rel == "." else rel + "/" + d) not in SKIP_DIRS]
        dirs[:] = [d for d in dirs if d != "__pycache__"]
        for f in files:
            yield os.path.join(base, f)


def rel(p):
    return os.path.relpath(p, ROOT).replace("\\", "/")


def php_bin():
    return PHP if os.path.isfile(PHP) else shutil.which("php")


# --- LINT ------------------------------------------------------------------

def section_lint():
    print("\n== LINT ==")
    php = php_bin()
    php_files = [p for p in walk_files() if p.endswith(".php")]
    if php:
        for p in php_files:
            r = subprocess.run([php, "-l", p], capture_output=True, text=True, encoding="utf-8", errors="replace")
            check("php -l " + rel(p), r.returncode == 0, (r.stdout or "") + (r.stderr or ""))
    else:
        check("php -l (нужен tools/php/php.exe)", False, "PHP не найден", skip=True)

    node = shutil.which("node")
    js_files = [p for p in walk_files() if p.endswith(".js")]
    if node:
        for p in js_files:
            r = subprocess.run([node, "--check", p], capture_output=True, text=True, encoding="utf-8", errors="replace")
            check("node --check " + rel(p), r.returncode == 0, (r.stdout or "") + (r.stderr or ""))
    else:
        check("node --check", False, "node не найден", skip=True)

    # кодировка: файлы должны читаться как UTF-8
    bad_enc = []
    for p in walk_files():
        if os.path.splitext(p)[1].lower() not in TEXT_EXT and os.path.basename(p) != ".htaccess":
            continue
        try:
            io.open(p, encoding="utf-8").read()
        except UnicodeDecodeError:
            bad_enc.append(rel(p))
    check("все текстовые файлы в UTF-8", not bad_enc, bad_enc)


# --- LINKS -----------------------------------------------------------------

def section_links():
    print("\n== LINKS ==")
    sm = io.open(os.path.join(ROOT, "sitemap.xml"), encoding="utf-8").read()
    urls = re.findall(r"<loc>(.*?)</loc>", sm)
    missing = []
    for u in urls:
        p = u.replace("https://vzuh.run/", "")
        if p == "":
            p = "index.html"
        elif p.endswith("/"):
            p += "index.html"
        if not os.path.exists(os.path.join(ROOT, p)):
            missing.append(p)
    check("sitemap: %d URL, все файлы на месте" % len(urls), not missing, missing)

    pages = ["index.html", "knowledge.html", "editor.html", "kb-editor.html",
             "kb/index.html", "ai-plan/index.html"]
    broken = []
    for page in pages:
        path = os.path.join(ROOT, page)
        if not os.path.exists(path):
            broken.append(page + " (нет файла)")
            continue
        text = io.open(path, encoding="utf-8", errors="replace").read()
        for h in re.findall(r'(?:href|src)="([^"#?]*)(?:[#?][^"]*)?"', text):
            if not h or h.startswith(("http://", "https://", "mailto:", "tel:", "data:", "javascript:", "#", "//")):
                continue
            target = os.path.normpath(os.path.join(ROOT if h.startswith("/") else os.path.dirname(path),
                                                   h.lstrip("/")))
            if not os.path.exists(target):
                broken.append(page + " → " + h)
    check("локальные ссылки в %d страницах" % len(pages), not broken, broken)


# --- KIT -------------------------------------------------------------------

def section_kit():
    print("\n== KIT ==")
    if not os.path.isdir(SITE):
        check("комплект «сайт/» собран", False, "нет папки сайт/ — запустите python tools/make_deploy.py")
        return
    diff = []
    for base, _dirs, files in os.walk(SITE):
        for f in files:
            dst = os.path.join(base, f)
            src = os.path.join(ROOT, os.path.relpath(dst, SITE))
            if not os.path.exists(src):
                continue                      # сгенерированный .htaccess
            if open(src, "rb").read() != open(dst, "rb").read():
                diff.append(rel(dst))
    check("комплект совпадает с источниками", not diff, diff)

    forbidden = [p for p in walk_files()
                 if re.search(r"ai-plan[\\/](diag|delete-diag|plan-backup-pre-algo)\.php$", p)]
    check("в рабочей папке нет diag/backup генератора", not forbidden, forbidden)

    for d in ["ai-plan/data", "ai-plan/out", "data/_backups", "data/_rate"]:
        kit_ht = os.path.join(SITE, d, ".htaccess")
        check("закрыт каталог " + d, os.path.isfile(kit_ht), "нет " + rel(kit_ht))

    check("в комплекте нет фото-заглушки вместо images",
          os.path.isdir(os.path.join(SITE, "images")), "нет сайт/images/")


# --- SECRETS ---------------------------------------------------------------

def section_secrets():
    print("\n== SECRETS ==")
    secrets = os.path.join(ROOT, "secrets.php")
    if not os.path.isfile(secrets):
        check("secrets.php есть локально", False, "нет файла — значения не проверить")
        return
    text = io.open(secrets, encoding="utf-8").read()
    values = [v for v in re.findall(r"define\('([A-Z_]+)',\s*'([^']+)'\)", text)
              if v[0] not in ("GIGA_KEY_ALREADY_BASE64",) and len(v[1]) >= 6]
    # проверяем только то, что реально может попасть в коммит: файлы под git
    # и неигнорируемые новые (инструкции с паролями и папка с ключами — ignore)
    listed = subprocess.run(["git", "ls-files", "-co", "--exclude-standard"], cwd=ROOT,
                            capture_output=True, text=True, encoding="utf-8",
                            errors="replace").stdout.splitlines()
    found = []
    for r in listed:
        p = os.path.join(ROOT, r)
        if not os.path.isfile(p) or r == "secrets.php":
            continue
        if os.path.splitext(p)[1].lower() not in TEXT_EXT and os.path.basename(p) != ".htaccess":
            continue
        try:
            body = io.open(p, encoding="utf-8", errors="ignore").read()
        except OSError:
            continue
        for name, val in values:
            if val in body:
                found.append("%s: значение %s" % (r, name))
    check("значения секретов не встречаются в файлах под git", not found, found)

    tracked = subprocess.run(["git", "ls-files"], cwd=ROOT, capture_output=True, text=True,
                             encoding="utf-8", errors="replace").stdout.splitlines()
    leaks = [t for t in tracked
             if re.search(r"(^|/)(secrets\.php|reviews\.js|Gigachat_Key\.txt|diag\.php|delete-diag\.php|plan-backup-pre-algo\.php)$", t)]
    check("секреты и ПДн не в индексе git", not leaks, leaks)


# --- FUNC ------------------------------------------------------------------

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}


def read_xlsx(path):
    """Мини-читатель xlsx без зависимостей: {лист: [[значения]]}."""
    z = zipfile.ZipFile(path)
    shared = []
    if "xl/sharedStrings.xml" in z.namelist():
        root = ET.fromstring(z.read("xl/sharedStrings.xml"))
        for si in root.findall("m:si", NS):
            shared.append("".join(t.text or "" for t in si.iter(
                "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t")))
    wb = ET.fromstring(z.read("xl/workbook.xml"))
    out = {}
    for i, s in enumerate(wb.find("m:sheets", NS), start=1):
        sheet = ET.fromstring(z.read("xl/worksheets/sheet%d.xml" % i))
        rows = []
        for row in sheet.iter("{http://schemas.openxmlformats.org/spreadsheetml/2006/main}row"):
            cells = []
            for c in row.findall("m:c", NS):
                t, v = c.get("t"), c.find("m:v", NS)
                if v is None:
                    isn = c.find("m:is", NS)
                    cells.append("".join(x.text or "" for x in isn.iter(
                        "{http://schemas.openxmlformats.org/spreadsheetml/2006/main}t")) if isn is not None else "")
                elif t == "s":
                    cells.append(shared[int(v.text)])
                else:
                    cells.append(v.text or "")
            rows.append(cells)
        out[s.get("name")] = rows
    return out


def flat(sheets, name):
    return "\n".join(" | ".join(r) for r in sheets.get(name, []))


class Server:
    def __init__(self, docroot, port):
        self.docroot, self.port, self.proc = docroot, port, None

    def __enter__(self):
        self.proc = subprocess.Popen([php_bin(), "-S", "127.0.0.1:%d" % self.port, "-t", self.docroot],
                                     stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        time.sleep(1.5)
        return self

    def __exit__(self, *exc):
        self.proc.terminate()
        try:
            self.proc.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.proc.kill()


def algo_payload(**kw):
    p = {
        "model": "algo", "target_time": "", "race_date": "", "start_date": "",
        "distance": "10", "weeks": "10", "experience": "1-3", "km_per_week": "25",
        "longest_run": "12", "days_per_week": "3", "pref_days": "2,4,7",
        "place": "park", "gender": "м", "age": "40",
        "recent_race": {"km": "", "time": ""}, "injuries": "", "hr_watch": "yes",
        "thr_ae_hr": "", "thr_ae_pace": "", "thr_pano_hr": "", "thr_pano_pace": "",
        "breaks": [], "races": [], "pro_pass": "", "website": "",
    }
    p.update(kw)
    return p


class Client:
    def __init__(self, base, docroot):
        self.base, self.docroot = base, docroot

    def post(self, path, payload):
        req = urllib.request.Request(self.base + path, data=json.dumps(payload).encode(),
                                     headers={"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(req, timeout=180) as r:
                return json.loads(r.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as e:
            return {"http": e.code, "body": e.read().decode("utf-8", "replace")}

    def post_form(self, path, fields):
        """publish.php и reviews.php читают $_POST, а не JSON."""
        data = urllib.parse.urlencode(fields).encode()
        req = urllib.request.Request(self.base + path, data=data,
                                     headers={"Content-Type": "application/x-www-form-urlencoded"})
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                return json.loads(r.read().decode("utf-8", "replace"))
        except urllib.error.HTTPError as e:
            return {"http": e.code, "body": e.read().decode("utf-8", "replace")}

    def poll(self, job, path="/ai-plan/plan.php", tries=300, delay=0.4):
        for _ in range(tries):
            with urllib.request.urlopen(self.base + path + "?job=" + job, timeout=30) as r:
                st = json.loads(r.read().decode("utf-8", "replace"))
            if not st.get("wait"):
                return st
            time.sleep(delay)
        return {"error": "timeout"}

    def plan(self, **kw):
        ack = self.post("/ai-plan/plan.php", algo_payload(**kw))
        if "job" not in ack:
            return ack, None
        st = self.poll(ack["job"])
        if not st.get("file"):
            return st, None
        path = os.path.join(self.docroot, "ai-plan", "out", st["file"])
        return st, read_xlsx(path)


def section_func(keep=False):
    print("\n== FUNC ==")
    if not php_bin():
        check("функциональные тесты (нужен PHP)", False, "PHP не найден", skip=True)
        return
    if not os.path.isdir(SITE):
        check("функциональные тесты", False, "нет сайт/ — запустите make_deploy.py")
        return
    tmpbase = os.path.join(ROOT, ".tmp-selfcheck")
    os.makedirs(tmpbase, exist_ok=True)
    # не tempfile.mkdtemp: каталог с «случайным» именем на Windows-песочнице
    # не даёт создать внутри себя подкаталог (WinError 5) — берём своё имя
    tmp = os.path.join(tmpbase, "run-%d" % time.time_ns())
    os.makedirs(tmp, exist_ok=True)
    doc = os.path.join(tmp, "site")
    shutil.copytree(SITE, doc)
    # как в репозитории: secrets.php нет. Тогда publish.php обязан отказать
    # в публикации даже с пустым паролем (раньше — пропускал)
    sec = os.path.join(doc, "secrets.php")
    if os.path.isfile(sec):
        os.remove(sec)
    # чистые счётчики лимита
    data_dir = os.path.join(doc, "ai-plan", "data")
    for nm in os.listdir(data_dir):
        if nm.startswith("_rate-"):
            os.unlink(os.path.join(data_dir, nm))
    try:
        with Server(doc, 8093):
            c = Client("http://127.0.0.1:8093", doc)

            st, sh = c.plan(distance="10", km_per_week="25", days_per_week="3",
                            recent_race={"km": "10", "time": "52:00"})
            check("генерация плана (10К, 52:00)", st.get("ok"), str(st)[:200])
            if sh:
                svod, zones, plan = flat(sh, "Сводка"), flat(sh, "Зоны и советы"), flat(sh, "План")
                check("разбор недавнего старта", "10 км за 52:00" in svod, svod[:300])
                check("зоны Ригеля правдоподобны", re.search(r"5:[0-3]\d", zones) is not None, zones[:300])
                check("нет фигурных скобок в текстах", "{" not in plan + zones,
                      [l for l in (plan + zones).splitlines() if "{" in l][:3])
                check("метки зон не подменены темпом", not re.search(r"Интервалы \d", plan),
                      [l for l in plan.splitlines() if re.search(r"Интервалы \d", l)][:2])

            st, sh = c.plan(distance="21.1", km_per_week="40", days_per_week="4",
                            pref_days="2,4,6,7", weeks="12",
                            recent_race={"km": "21.1", "time": "1:52:30"})
            if sh:
                check("время ЧЧ:ММ:СС разобрано", "1:52:30" in flat(sh, "Сводка"), flat(sh, "Сводка")[:300])

            st, sh = c.plan(recent_race={"km": "10", "time": "0:10"})
            if sh:
                zones, plan = flat(sh, "Зоны и советы"), flat(sh, "План")
                check("неправдоподобный результат проигнорирован",
                      "проигнорирован" in flat(sh, "Сводка"))
                check("при игноре — новичковый трек", "Контрольный тест" in plan and "Контрольный тест" in zones)

            st, sh = c.plan()
            if sh:
                check("новичок: тест в неделе 1 и в листе 3",
                      "Контрольный тест" in flat(sh, "План") and "Контрольный тест" in flat(sh, "Зоны и советы"))

            st, sh = c.plan(thr_ae_hr="140", thr_ae_pace="5:40", thr_pano_hr="165", thr_pano_pace="4:50")
            if sh:
                check("новичок+пороги: без двойного набора зон",
                      flat(sh, "Зоны и советы").count("Зона 2") == 0)

            st, sh = c.plan(distance="10", km_per_week="20", days_per_week="2", pref_days="")
            if sh:
                check("2 дня в неделю → 3 и честная Сводка", "3 по дням" in flat(sh, "Сводка"))

            # квота алгоритма
            last = None
            for _ in range(21):
                ack = c.post("/ai-plan/plan.php", algo_payload(distance="5", weeks="8", km_per_week="15"))
                if ack.get("http") == 429:
                    last = ack
                    break
                if "job" in ack:
                    c.poll(ack["job"], tries=60, delay=0.2)
            check("квота алгоритма (429 rate_algo)",
                  last is not None and "rate_algo" in last.get("body", ""), str(last)[:200])

            # страховка от fatal
            src = io.open(os.path.join(doc, "ai-plan", "plan.php"), encoding="utf-8", newline="").read()
            anchor = ("if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }"
                      + "\r\n@flush();")
            broken = src.replace(anchor, anchor + "\r\nno_such_function_xyz();", 1)
            open(os.path.join(doc, "ai-plan", "plan-fatal-test.php"), "w", encoding="utf-8", newline="").write(broken)
            ack = c.post("/ai-plan/plan-fatal-test.php", algo_payload(distance="5", weeks="8", km_per_week="15"))
            st = c.poll(ack.get("job", ""), "/ai-plan/plan-fatal-test.php", tries=20, delay=0.3) if ack.get("job") else {}
            check("fatal → статус задачи закрыт как fatal",
                  st.get("ok") is False and st.get("error") == "fatal", str(st)[:200])

            # публикация и бэкапы
            resp = c.post_form("/publish.php", {"password": "", "content": "window.WZ_SCHEDULE = {days: []};"})
            check("публикация: пустой пароль отвергнут",
                  resp.get("http") == 500 and "password_not_set" in resp.get("body", ""), str(resp)[:200])
            r1 = c.post_form("/reviews.php", {"name": "Тест1", "text": "Первый отзыв для проверки бэкапа"})
            prev_path = os.path.join(doc, "data", "_backups", "reviews-prev.js")
            prev = io.open(prev_path, encoding="utf-8").read() if os.path.isfile(prev_path) else ""
            check("бэкап отзывов = версия ДО записи",
                  bool(r1.get("ok")) and "Тест1" not in prev and "reviews" in prev,
                  "ответ: %s | бэкап: %s" % (r1, prev[:120]))
    finally:
        if keep:
            print("временный каталог:", tmp)
        else:
            shutil.rmtree(tmp, ignore_errors=True)


def main():
    ap = argparse.ArgumentParser(description="Самопроверка сайта ВЖУХ")
    ap.add_argument("--full", action="store_true", help="функциональные тесты (нужен PHP)")
    ap.add_argument("--keep", action="store_true", help="не удалять временный каталог")
    args = ap.parse_args()

    section_lint()
    section_links()
    section_kit()
    section_secrets()
    if args.full:
        section_func(keep=args.keep)

    bad = [r for r in RESULTS if not r[1]]
    print("\nитог: %d проверок, провалов %d" % (len(RESULTS), len(bad)))
    for name, _ in bad:
        print("  провал:", name)
    return 1 if bad else 0


if __name__ == "__main__":
    sys.exit(main())
