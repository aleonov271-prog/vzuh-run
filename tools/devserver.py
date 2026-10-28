#!/usr/bin/env python3
# ============================================================
# ВЖУХ — локальный сервер разработки (tools/devserver.py)
#
# Зачем: python -m http.server отдаёт файлы, но НЕ выполняет
# PHP — форма отзывов не работает. Этот сервер делает то же,
# что http.server, плюс исполняет поведение reviews.php:
# принимает POST формы отзыва и дописывает его в data/reviews.js.
#
# Запуск из папки проекта:
#     python tools/devserver.py          (порт 8000)
#     python tools/devserver.py 8080
# Открывать: http://localhost:8000/index.html
#
# Логика отзыва повторяет reviews.php: валидация длин,
# honeypot, лимит 3 отзыва/час/адрес, запись атомарно
# (tmp + rename), бэкап предыдущей версии в data/_backups/.
# ============================================================

import http.server
import json
import os
import re
import sys
import threading
import time
import urllib.parse
import uuid
import zipfile
from xml.sax.saxutils import escape

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

NAME_MAX     = 40
TEXT_MAX     = 800
TEXT_MIN     = 10
MAX_REVIEWS  = 300
MAX_FILE_BYTES = 200000
RATE_WINDOW  = 3600

# Локальный сервер — для живой проверки человека лимит щедрый.
# Строгий прод-лимиты (3/час, как на хостинге):  python tools/devserver.py --strict
if "--strict" in sys.argv:
    RATE_LIMIT = 3
else:
    RATE_LIMIT = 30

MONTHS = ["января", "февраля", "марта", "апреля", "мая", "июня",
          "июля", "августа", "сентября", "октября", "ноября", "декабря"]

# Локальный лимит частоты: {ip: [время, ...]}
_rate = {}
_rate_lock = threading.Lock()
_file_lock = threading.Lock()


def clean(s, max_len):
    s = (s or "").replace("\r\n", "\n").replace("\r", "\n")
    s = re.sub(r"[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]", "", s).strip()
    return s[:max_len]


def read_reviews():
    path = os.path.join(ROOT, "data", "reviews.js")
    if not os.path.isfile(path):
        return []
    raw = open(path, encoding="utf-8").read()
    m = re.search(r"window\.WZ_REVIEWS\s*=\s*(\{.*\})", raw, re.S)
    if not m:
        return []
    try:
        data = json.loads(m.group(1))
        return data.get("reviews", []) if isinstance(data, dict) else []
    except ValueError:
        return []


def write_reviews(reviews):
    json_str = json.dumps({"reviews": reviews},
                          ensure_ascii=False, indent=2)
    # "</" внутри отзыва не должен закрыть тег <script>
    body = ("window.WZ_REVIEWS = " +
            json_str.replace("</", "<\\/") + ";\n")
    target = os.path.join(ROOT, "data", "reviews.js")
    tmp = target + ".tmp"
    with open(tmp, "w", encoding="utf-8", newline="\n") as f:
        f.write(body)
    os.replace(tmp, target)


# --- Эмуляция ai-plan/plan.php (сборка демо-xlsx) -------------------
# На хостинге план пишет GigaChat, локально — заглушка: теми же
# листами (Сводка / План / Зоны и советы), чтобы проверить интерфейс
# и открытие файла в Excel. Генератор страниц темы: см. план.php.


def _x_cell(col, row, value):
    head, body = "", ""
    if row == 1:
        head = ' s="1"'
    if isinstance(value, (int, float)):
        body = "<v>%s</v>" % value
    else:
        body = '<is><t xml:space="preserve">%s</t></is>' % escape(str(value))
    return '<c r="%s%s"%s%s>%s</c>' % (
        col, row, head, "" if isinstance(value, (int, float)) else
        ' t="inlineStr"', body)


def _x_rows(header, rows):
    cols = ["10", "11", "6", "12", "52", "6", "18", "44", "28", "70"]
    colxml = "".join('<col min="%d" max="%d" width="%s" customWidth="1"/>'
                     % (i + 1, i + 1, w) for i, w in enumerate(cols))
    out = ['<?xml version="1.0" encoding="UTF-8" standalone="yes"?>',
           '<worksheet xmlns="http://schemas.openxmlformats.org/'
           'spreadsheetml/2006/main">', "<cols>%s</cols><sheetData>" % colxml]
    letters = "ABCDEFGHIJ"
    xml_rows = ["<row r=\"1\">" + "".join(
        _x_cell(letters[i], 1, header[i]) for i in range(len(header))) +
        "</row>"]
    for idx, row_data in enumerate(rows, start=2):
        xml_rows.append('<row r="%d">%s</row>' % (
            idx, "".join(_x_cell(letters[i], idx, c)
                         for i, c in enumerate(row_data[:len(header)]))))
    out.append("".join(xml_rows))
    out.append("</sheetData></worksheet>")
    return "".join(out)


def build_demo_xlsx(path, summary_rows, plan_rows, zone_rows):
    ctypes = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              '<Types xmlns="http://schemas.openxmlformats.org/package/2006/'
              'content-types">'
              '<Default Extension="rels" ContentType="application/vnd.'
              'openxmlformats-package.relationships+xml"/>'
              '<Default Extension="xml" ContentType="application/xml"/>'
              '<Override PartName="/xl/workbook.xml" ContentType="application/'
              'vnd.openxmlformats-officedocument.spreadsheetml.'
              'sheet.main+xml"/>'
              '<Override PartName="/xl/worksheets/sheet1.xml" ContentType='
              '"application/vnd.openxmlformats-officedocument.spreadsheetml'
              '.worksheet+xml"/>'
              '<Override PartName="/xl/worksheets/sheet2.xml" ContentType='
              '"application/vnd.openxmlformats-officedocument.spreadsheetml'
              '.worksheet+xml"/>'
              '<Override PartName="/xl/worksheets/sheet3.xml" ContentType='
              '"application/vnd.openxmlformats-officedocument.spreadsheetml'
              '.worksheet+xml"/>'
              '<Override PartName="/xl/styles.xml" ContentType="application/'
              'vnd.openxmlformats-officedocument.spreadsheetml.'
              'styles+xml"/></Types>')
    root_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                 '<Relationships xmlns="http://schemas.openxmlformats.org/'
                 'package/2006/relationships"><Relationship Id="rId1" Type='
                 '"http://schemas.openxmlformats.org/officeDocument/2006/'
                 'relationships/officeDocument" Target="xl/workbook.xml"/>'
                 '</Relationships>')
    workbook = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                '<workbook xmlns="http://schemas.openxmlformats.org/'
                'spreadsheetml/2006/main" xmlns:r="http://schemas.'
                'openxmlformats.org/officeDocument/2006/relationships">'
                '<sheets><sheet name="Сводка" sheetId="1" r:id="rId1"/>'
                '<sheet name="План" sheetId="2" r:id="rId2"/>'
                '<sheet name="Зоны и советы" sheetId="3" r:id="rId3"/>'
                '</sheets></workbook>')
    wb_rels = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
               '<Relationships xmlns="http://schemas.openxmlformats.org/'
               'package/2006/relationships">'
               '<Relationship Id="rId1" Type="http://schemas.'
               'openxmlformats.org/officeDocument/2006/relationships/'
               'worksheet" Target="worksheets/sheet1.xml"/>'
               '<Relationship Id="rId2" Type="http://schemas.'
               'openxmlformats.org/officeDocument/2006/relationships/'
               'worksheet" Target="worksheets/sheet2.xml"/>'
               '<Relationship Id="rId3" Type="http://schemas.'
               'openxmlformats.org/officeDocument/2006/relationships/'
               'worksheet" Target="worksheets/sheet3.xml"/>'
               '<Relationship Id="rId4" Type="http://schemas.'
               'openxmlformats.org/officeDocument/2006/relationships/'
               'styles" Target="styles.xml"/></Relationships>')
    styles = ('<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
              '<styleSheet xmlns="http://schemas.openxmlformats.org/'
              'spreadsheetml/2006/main"><fonts count="2">'
              '<font><sz val="11"/><name val="Calibri"/></font>'
              '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
              '<fills count="2"><fill><patternFill patternType="none"/></fill>'
              '<fill><patternFill patternType="gray125"/></fill></fills>'
              '<borders count="1"><border/></borders>'
              '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
              '<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs>'
              '<cellStyles count="1"><cellStyle name="Normal" xfId="0" '
              'builtinId="0"/></cellStyles></styleSheet>')
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", ctypes)
        z.writestr("_rels/.rels", root_rels)
        z.writestr("xl/workbook.xml", workbook)
        z.writestr("xl/_rels/workbook.xml.rels", wb_rels)
        z.writestr("xl/styles.xml", styles)
        z.writestr("xl/worksheets/sheet1.xml",
                   _x_rows(["Что", "Значение"], summary_rows))
        z.writestr("xl/worksheets/sheet2.xml",
                   _x_rows(["Неделя", "Дата", "День", "Тип", "Тренировка",
                            "Км", "Интенсивность", "Комментарий"], plan_rows))
        z.writestr("xl/worksheets/sheet3.xml",
                   _x_rows(["Зона", "Название", "Пульс", "Зачем"], zone_rows))


def demo_plan_reply(data):
    """Заглушка плана: 3 недели с равномерной недельной нагрузкой."""
    import datetime
    today = datetime.date.today()
    monday = today - datetime.timedelta(days=today.weekday())
    plan_rows = []
    for n in range(1, 4):
        wk = monday + datetime.timedelta(days=7 * (n - 1))
        for di, (day, typ, km, inten, cmt) in enumerate([
                ("ПН", "отдых", 0, "—", "отдых и сон"),
                ("ВТ", "техника", 4, "зона 1", "лёгкий бег + упражнения"),
                ("СР", "отдых", 0, "—", "или ходьба 30 минут"),
                ("ЧТ", "темп", 6, "зона 2", "5 км в темпе + разминка"),
                ("ПТ", "отдых", 0, "—", "отдых"),
                ("СБ", "интервалы", 5, "зона 3", "разминка, 4×400 м, заминка"),
                ("ВС", "длинный", 10, "зона 1", "медленно по парку"),
        ]):
            wkday = wk + datetime.timedelta(days=di)
            plan_rows.append([n, wkday.isoformat(), day, typ,
                              "демо-запись (сборка без модели)",
                              km, inten, cmt])
    summary_rows = [
        ["Клуб", "ВЖУХ — беговой клуб, Москва"],
        ["Ответы формы", json.dumps(data, ensure_ascii=False)[:400]],
        ["Режим", "ДЕМО-файл devserver (модель не вызывалась)"],
        ["Дата составления", time.strftime("%d.%m.%Y %H:%M")],
    ]
    zone_rows = [
        ["зона 1", "восстановление", "до 70%", "база и лёгкий бег"],
        ["зона 2", "темпо", "70–80%", "марафонский темп"],
        ["зона 3", "интервалы", "80–90%", "развитие", ],
    ]
    fname = "plan-demo-%s.xlsx" % uuid.uuid4().hex[:8]
    out_dir = os.path.join(ROOT, "ai-plan", "out")
    os.makedirs(out_dir, exist_ok=True)
    build_demo_xlsx(os.path.join(out_dir, fname),
                    summary_rows, plan_rows, zone_rows)
    return {"ok": True, "url": "/ai-plan/out/" + fname, "file": fname}


class Handler(http.server.SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=ROOT, **kwargs)

    def _json(self, payload, code=200):
        raw = json.dumps(payload, ensure_ascii=False).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type",
                         "application/json; charset=utf-8")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        path = urllib.parse.urlparse(self.path).path
        for ep in ("reviews.php", "ai-plan/plan.php"):
            if path.rstrip("/").endswith(ep):
                self._json({"ok": False, "error": "method"}, 405)
                return
        super().do_GET()

    def do_POST(self):
        path = urllib.parse.urlparse(self.path).path
        if path.rstrip("/").endswith("ai-plan/plan.php"):
            length = int(self.headers.get("Content-Length") or 0)
            try:
                data = json.loads(
                    self.rfile.read(length).decode("utf-8", "replace") or "{}")
            except ValueError:
                data = {}
            if not isinstance(data, dict):
                self._json({"ok": False, "error": "format"})
                return
            if (data.get("website") or "").strip():
                self._json({"ok": False, "error": "spam"})
                return
            dist_ok = (data.get("distance") in ("5", "10", "15", "21.1", "42.2")
                       or (data.get("distance") == "other"
                           and (data.get("custom_distance") or "").strip()))
            exp_ok = data.get("experience") in ("none", "lt1", "1-3", "gt3")
            km_ok = str(data.get("km_per_week", "")).strip() != ""
            if not (dist_ok and exp_ok and km_ok):
                self._json({"ok": False, "error": "format"})
                return
            # как в plan.php: дата начала тренировок не позже даты старта
            sd, rd = data.get("start_date") or "", data.get("race_date") or ""
            if sd and rd and sd > rd:
                self._json({"ok": False, "error": "bad_dates"}, 400)
                return
            self._json(demo_plan_reply(data))
            return
        if not path.rstrip("/").endswith("reviews.php"):
            self._json({"ok": False, "error": "method"}, 405)
            return

        length = int(self.headers.get("Content-Length") or 0)
        # боты присылают не-UTF-8 байты — не падаем, а читаем с заменой
        form = urllib.parse.parse_qs(
            self.rfile.read(length).decode("utf-8", "replace"))

        website = clean(form.get("website", [""])[0], 100)
        if website:
            self._json({"ok": False, "error": "spam"})
            return

        name = clean(form.get("name", [""])[0], NAME_MAX)
        text = clean(form.get("text", [""])[0], TEXT_MAX)
        if not name or len(text) < TEXT_MIN:
            self._json({"ok": False, "error": "format"})
            return

        ip = self.client_address[0]
        with _rate_lock:
            now = int(time.time())
            stamps = [t for t in _rate.get(ip, [])
                      if now - t < RATE_WINDOW]
            if len(stamps) >= RATE_LIMIT:
                self._json({"ok": False, "error": "rate"})
                return
            stamps.append(now)
            _rate[ip] = stamps

        months_num = MONTHS
        lt = time.localtime()
        date = "%d %s %d" % (lt.tm_mday, months_num[lt.tm_mon - 1],
                             lt.tm_year)

        review = {"name": name, "date": date, "text": text,
                  "ts": int(time.time())}

        with _file_lock:  # как flock в reviews.php
            reviews = read_reviews()
            reviews.append(review)
            del reviews[:-MAX_REVIEWS]
            # бэкап предыдущей версии
            backups = os.path.join(ROOT, "data", "_backups")
            os.makedirs(backups, exist_ok=True)
            target = os.path.join(ROOT, "data", "reviews.js")
            if os.path.isfile(target):
                raw = open(target, encoding="utf-8").read()
                open(os.path.join(backups, "reviews-prev.js"),
                     "w", encoding="utf-8").write(raw)
            try:
                write_reviews(reviews)
            except OSError:
                self._json({"ok": False, "error": "write_failed"}, 500)
                return

        self._json({"ok": True, "review": {
            "name": review["name"],
            "date": review["date"],
            "text": review["text"]}})

    def log_message(self, fmt, *args):
        sys.stdout.write("[devserver] " + fmt % args + "\n")


def main():
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    port = int(args[0]) if args else 8000
    server = http.server.ThreadingHTTPServer(
        ("127.0.0.1", port), Handler)
    print("ВЖУХ devserver: http://localhost:%d/index.html" % port)
    print("Остановить: Ctrl+C")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nОстановлено.")


if __name__ == "__main__":
    main()