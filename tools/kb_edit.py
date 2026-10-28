# -*- coding: utf-8 -*-
"""Редактор базы знаний сайта ВЖУХ.

Данные живут в data/knowledge.js (window.WZ_KNOWLEDGE). Этот файл —
единственный источник правды: дайджест-подборка сообщений больше не используется.
Править data/knowledge.js руками не нужно — весь ремонт через этот инструмент.

Команды:
  python tools/kb_edit.py list [--cards s16]
  python tools/kb_edit.py add --topic s16 --file заметка.txt --title "Заголовок"
                              [--date 2026-09-30] [--tags "питание вода"] [--end]
  python tools/kb_edit.py delete m249 [--yes]
  python tools/kb_edit.py rename s16 "Питание и питьё"

Перед каждой записью создаётся бэкап: data/_backups/knowledge-ГГГГММДД-ЧЧММСС.js.
Только stdlib, без pip. Python 3.7+.
"""
import html
import json
import re
import shutil
import sys
from collections import Counter
from datetime import date, datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "data" / "knowledge.js"
BACKUP_DIR = DATA.parent / "_backups"
TOP_TAGS = 20  # синхронно с build_knowledge.py


def out(s):
    try:
        sys.stdout.write(s + "\n")
    except UnicodeEncodeError:  # старые консоли Windows
        sys.stdout.buffer.write((s + "\n").encode("utf-8", "replace"))


def die(msg, hint=""):
    out("ОШИБКА: " + msg)
    if hint:
        out("Подсказка: " + hint)
    sys.exit(1)


# --- Чтение/запись данных ---------------------------------------------------

def load():
    if not DATA.exists():
        die("Нет файла %s" % DATA,
            "собрать данные: python tools\\build_knowledge.py")
    text = DATA.read_text(encoding="utf-8")
    m = re.search(r"window\.WZ_KNOWLEDGE\s*=\s*(.+?)\s*;\s*$", text, re.S)
    if not m:
        die("В %s не найден window.WZ_KNOWLEDGE" % DATA)
    try:
        data = json.loads(m.group(1))
    except ValueError as e:
        die("Файл данных повреждён (не JSON): %s. Восстановите из бэкапа "
            "data\\_backups\\ и перезапустите." % e)
    if not isinstance(data.get("groups"), list) or not data["groups"]:
        die("В данных нет групп — похоже, файл повреждён.",
            "восстановите из data\\_backups\\")
    return data


def save(data):
    # пересчёт производных полей
    total = 0
    counter = Counter()
    for g in data["groups"]:
        for t in g["topics"]:
            for c in t["cards"]:
                total += 1
                for tg in (c.get("tags") or "").split():
                    if tg:
                        counter[tg] += 1
    data.setdefault("counts", {})["cards"] = total
    data["generated"] = date.today().isoformat()
    data["lastEdit"] = datetime.now().strftime("%Y-%m-%d %H:%M")
    data["tagCounts"] = [[t, n] for t, n in
                         sorted(counter.items(), key=lambda x: (-x[1], x[0]))[:TOP_TAGS]]

    if DATA.exists():
        BACKUP_DIR.mkdir(exist_ok=True)
        stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
        backup = BACKUP_DIR / ("knowledge-%s.js" % stamp)
        i = 1
        while backup.exists():  # две правки в одну секунду — не затираем
            i += 1
            backup = BACKUP_DIR / ("knowledge-%s-%d.js" % (stamp, i))
        shutil.copy2(DATA, backup)
        out("Бэкап: %s" % (backup.relative_to(ROOT)))

    js = ("window.WZ_KNOWLEDGE = "
          + json.dumps(data, ensure_ascii=False, separators=(",", ":")) + ";\n")
    DATA.write_text(js, encoding="utf-8")
    return total


def all_ids(data):
    ids = []
    for g in data["groups"]:
        for t in g["topics"]:
            for c in t["cards"]:
                ids.append(c["id"])
    return ids


def find_topic(data, sid):
    for g in data["groups"]:
        for t in g["topics"]:
            if t["id"] == sid:
                return g, t
    die("Тема «%s» не найдена." % sid,
        "смотрите список тем: python tools\\kb_edit.py list")


def find_card(data, mid):
    for g in data["groups"]:
        for t in g["topics"]:
            for i, c in enumerate(t["cards"]):
                if c["id"] == mid:
                    return g, t, i, c
    die("Статья %s не найдена. Список статей темы: "
        "python tools\\kb_edit.py list --cards s16" % mid)


# --- Команда list -----------------------------------------------------------

def cmd_list(args):
    data = load()
    for g in data["groups"]:
        out("")
        out("%s — %s" % (g["id"], g["title"]))
        for t in g["topics"]:
            if args.cards and t["id"] != args.cards:
                continue
            out("  %s  %s (%d)" % (t["id"], t["title"], len(t["cards"])))
            if args.cards == t["id"]:
                for c in t["cards"]:
                    out("      %-6s %s  %s"
                        % (c["id"], c.get("date", ""), c.get("title", "")[:70]))
    total = sum(len(t["cards"]) for g in data["groups"] for t in g["topics"])
    out("")
    out("Карточек всего: %d" % total)


# --- Команда add ------------------------------------------------------------

def txt_to_body(text):
    """Простой текст → html тела карточки: **жирный**, абзацы, переносы."""
    esc = html.escape(text.strip(), quote=False)
    esc = re.sub(r"\*\*(.+?)\*\*", r"<strong>\1</strong>", esc, flags=re.S)
    # абзац = блок между пустыми строками; внутри абзаца перенос строки
    paras = re.split(r"\n\s*\n+", esc)
    return "<br><br>".join(p.replace("\n", "<br>") for p in paras)


def html_to_body(path):
    """Чистка html-файла тем же белым списком, что и build_knowledge.py."""
    try:
        from build_knowledge import BodyParser  # tools/ и так есть в sys.path
    except ImportError:
        die("Не найден build_knowledge.py в tools\\",
            "запускать из папки проекта: python tools\\kb_edit.py …")
    src = Path(path).read_text(encoding="utf-8")
    # выбросить <script>/<style>, если их не вычистил парсер
    src = re.sub(r"<(script|style)\b.*?</\1>", "", src, flags=re.S | re.I)
    p = BodyParser()
    p.feed(src)
    p.close()
    body = p.body_html()
    tags = [t.lower().lstrip("#") for t in p.tags if len(t) >= 3]
    return body, tags


def normal_tags(raw):
    lst, seen = [], set()
    for t in (raw or "").split():
        t = t.lower().lstrip("#")
        if len(t) >= 3 and not t.isdigit() and t not in seen:
            seen.add(t)
            lst.append(t)
    return lst


def cmd_add(args):
    if not args.title:
        die("Нужно указать --title \"Заголовок\"",
            "python tools\\kb_edit.py add --topic s16 --file заметка.txt --title \"Заголовок\"")
    topic_path = Path(args.file)
    if not topic_path.is_absolute():
        topic_path = Path.cwd() / topic_path
    if not topic_path.exists():
        die("Файл не найден: %s" % topic_path)

    date_str = args.date or date.today().isoformat()
    if not re.match(r"\d{4}-\d{2}-\d{2}$", date_str):
        die("Дата должна быть в виде 2026-09-30, получено: %r" % date_str)

    if topic_path.suffix.lower() in (".html", ".htm"):
        body, auto_tags = html_to_body(topic_path)
    else:
        body, auto_tags = txt_to_body(topic_path.read_text(encoding="utf-8")), []
    if not body:
        die("Файл пустой — нечего добавлять.")
    tags = normal_tags(args.tags) or normal_tags(" ".join(auto_tags))

    data = load()
    g, t = find_topic(data, args.topic)

    used = {int(i[1:]) for i in all_ids(data) if re.match(r"m\d+$", i)}
    mid = "m%d" % (max(used) + 1 if used else 1)
    card = {"id": mid, "date": date_str, "title": args.title,
            "body": body, "manual": True}
    if tags:
        card["tags"] = " ".join(tags)

    if args.end:
        t["cards"].append(card)
    else:
        t["cards"].insert(0, card)
    total = save(data)

    out("")
    out("Добавлено: %s «%s» → %s / %s (%s, %s)" % (
        mid, args.title, g["title"], t["title"], date_str,
        "в конец" if args.end else "сверху"))
    if tags:
        out("Теги: %s" % ", ".join(tags))
    out("Карточек всего: %d" % total)
    out("Ссылка: knowledge.html#%s  |  Обновите страницу (F5)." % mid)


# --- Команда delete ----------------------------------------------------------

def cmd_delete(args):
    data = load()
    mid = args.card if re.match(r"m\d+$", args.card) else "m" + args.card
    g, t, i, c = find_card(data, mid)
    out("Будет удалена статья %s «%s» (%s) из темы «%s»."
        % (mid, c.get("title", ""), c.get("date", ""), t["title"]))
    if not args.yes:
        try:
            ans = input("Удалить? (y/yes — да, всё остальное — отмена): ")
        except KeyboardInterrupt:
            out("\nОтменено.")
            sys.exit(0)
        if ans.strip().lower() not in ("y", "yes"):
            out("Отменено — ничего не изменено.")
            return
    del t["cards"][i]
    total = save(data)
    out("Удалено: %s. Карточек всего: %d. Обновите страницу (F5)." % (mid, total))


# --- Команда rename ----------------------------------------------------------

def cmd_rename(args):
    data = load()
    g, t = find_topic(data, args.topic)
    old = t["title"]
    t["title"] = args.title
    save(data)
    out("Тема %s: «%s» → «%s». Обновите страницу (F5)." % (args.topic, old, args.title))


# --- Точка входа --------------------------------------------------------------

def main():
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass
    import argparse
    ap = argparse.ArgumentParser(description="Редактор базы знаний ВЖУХ (data/knowledge.js)")
    sub = ap.add_subparsers(dest="cmd", required=True)

    p = sub.add_parser("list", help="темы и счётчики базы")
    p.add_argument("--cards", metavar="sNN", help="показать статьи темы")
    p.set_defaults(fn=cmd_list)

    p = sub.add_parser("add", help="добавить статью из файла в тему")
    p.add_argument("--topic", required=True, metavar="sNN")
    p.add_argument("--file", required=True)
    p.add_argument("--title", required=True)
    p.add_argument("--date", help="YYYY-MM-DD; по умолчанию сегодня")
    p.add_argument("--tags", help="теги через пробел: \"питание вода\"")
    p.add_argument("--end", action="store_true", help="в конец темы (по умолчанию — сверху)")
    p.set_defaults(fn=cmd_add)

    p = sub.add_parser("delete", help="удалить статью по id (m123)")
    p.add_argument("card", metavar="mNNN")
    p.add_argument("--yes", action="store_true")
    p.set_defaults(fn=cmd_delete)

    p = sub.add_parser("rename", help="переименовать тему")
    p.add_argument("topic", metavar="sNN")
    p.add_argument("title")
    p.set_defaults(fn=cmd_rename)

    args = ap.parse_args()
    args.fn(args)


if __name__ == "__main__":
    main()