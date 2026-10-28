# -*- coding: utf-8 -*-
"""Сборщик базы знаний сайта ВЖУХ.

Читает дайджест «Техника бега — подборка сообщений.html» (корень проекта)
и генерирует compact-файл данных data/knowledge.js (window.WZ_KNOWLEDGE).

Запуск (из папки проекта или из любого места):
    python tools/build_knowledge.py
    python tools/build_knowledge.py "другой-дайгест.html"

Файл data/knowledge.js — генерируемый: руками не править.
Только stdlib, без pip. Python 3.7+.
"""
import html
import json
import re
import sys
from datetime import date
from html.parser import HTMLParser
from pathlib import Path

# --- Настройки -------------------------------------------------------------

# Группы уровня 1 и состав тем (id секций дайджеста). Единственное место,
# которое правится руками. Спорные позиции (правится одной строкой):
#   s16 «Питание»         — можно было в группу 2 (Тренировки);
#   s19 «Физиология»      — крупный слот в группе 3 (55 записей всего);
#   s10 «Бег босиком»     — можно было в группу 1 (Техника).
GROUPS = [
    ("g1", "Техника бега", [
        "s01", "s02", "s03", "s04", "s05", "s06", "s07", "s08", "s14",
    ]),
    ("g2", "Тренировки и подготовка", [
        "s09", "s11", "s12", "s17", "s20",
    ]),
    ("g3", "Здоровье и восстановление", [
        "s13", "s16", "s18", "s19",
    ]),
    ("g4", "Практика и мифы", [
        "s10", "s15", "s21",
    ]),
]

# Ручные правки заголовков тем поверх дайджеста (ключ — id секции).
# Заполняется при необходимости; пустой словарь = заголовки «как в источнике».
TITLE_OVERRIDES = {
    "s16": "Питание и питьё",
}

# Карточки, которые не показываем в базе (id вида «m249» из дайджеста).
# Например, сообщение с неработающими ссылками. При пересборке дайджеста
# исключение сохраняется — id сообщения при новой выгрузке не меняются.
CARD_EXCLUDES = {
    "m249",   # «Экономичная техника бега для стайеров…» — неработающие ссылки
}

EXPECTED_SECTIONS = 21
EXPECTED_CARDS = 306
TOP_TAGS = 20  # сколько тегов-чипов класть в данные


def out(s):
    sys.stdout.write(s + "\n")


# --- Парсер тела карточки ---------------------------------------------------

class BodyParser(HTMLParser):
    """Пересобирает тело карточки по белому списку тегов.

    Сохраняет: <strong>, <br>, span.vid-note, div.cont-mark (с id),
    div.cont-body. span.hl (#теги) уходит в список тегов, <img> вырезается,
    прочие теги выводятся только текстом.
    """

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.out = []
        self.tags = []        # собранные хэштеги (с #)
        self.imgs = 0
        self.strong = 0
        self.hl = 0
        self.vid = 0
        self.cont_mark = 0
        self.cont_body = 0
        self._hl_buf = []

    # -- служебное -----------------------------------------------------------
    def _cls(self, attrs):
        for k, v in attrs:
            if k == "class" and v:
                return v.lower()
        return ""

    def _attr(self, attrs, name):
        for k, v in attrs:
            if k == name and v is not None:
                return v
        return ""

    def _esc(self, s):
        return html.escape(s, quote=False)

    # -- события -------------------------------------------------------------
    def handle_starttag(self, tag, attrs):
        cls = self._cls(attrs)
        if tag == "strong":
            self.strong += 1
            self.out.append("<strong>")
        elif tag == "br":
            self.out.append("<br>")
        elif tag == "img":
            self.imgs += 1
        elif tag == "span" and "hl" in cls.split():
            self.hl += 1
            self._hl_buf = []
        elif tag == "span" and "vid-note" in cls.split():
            self.vid += 1
            self.out.append('<span class="vid-note">')
        elif tag == "div" and "cont-mark" in cls.split():
            self.cont_mark += 1
            cid = self._attr(attrs, "id")
            cid_attr = ' id="%s"' % html.escape(cid, quote=True) if cid else ""
            self.out.append('<div class="cont-mark"%s>' % cid_attr)
        elif tag == "div" and "cont-body" in cls.split():
            self.cont_body += 1
            self.out.append("<div class=\"cont-body\">")
        # прочие теги игнорируем — текст внутри вытекает как есть

    def handle_startendtag(self, tag, attrs):
        if tag == "br":
            self.out.append("<br>")
            return
        if tag == "img":
            self.imgs += 1
            return
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_data(self, data):
        if self.hl:
            self._hl_buf.append(data)
        else:
            self.out.append(self._esc(data))

    def handle_endtag(self, tag):
        if tag == "strong" and self.strong:
            self.strong -= 1
            self.out.append("</strong>")
        elif tag == "span" and self.hl:
            self.hl -= 1
            word = "".join(self._hl_buf).strip()
            # вытащить все «слова на #» из содержимого подсветки
            for w in re.findall(r"#\S+", word):
                self.tags.append(w)
        elif tag == "span" and self.vid:
            self.vid -= 1
            self.out.append("</span>")
        elif tag == "div" and self.cont_mark:
            self.cont_mark -= 1
            self.out.append("</div>")
        elif tag == "div" and self.cont_body:
            self.cont_body -= 1
            self.out.append("</div>")

    def close(self):
        super().close()
        # незакрытые strong на границе данных — закрыть, чтобы не ломать вёрстку
        if self.strong:
            self.out.append("</strong>" * self.strong)
            self.strong = 0

    def body_html(self):
        s = "".join(self.out)
        return re.sub(r"[ \t]*\n[ \t]*", " ", s).strip()


# --- Извлечение ---------------------------------------------------------------

def strip_tags(s):
    s = re.sub(r"<[^>]+>", "", s)
    return html.unescape(s).replace("\n", " ").strip()


def section_chunks(src):
    """[(sid, title, cnt, chunk)] по границам <section id="sNN">."""
    marks = list(re.finditer(r'<section\b[^>]*\bid="(s\d+)"', src))
    chunks = []
    for i, m in enumerate(marks):
        end = marks[i + 1].start() if i + 1 < len(marks) else len(src)
        chunk = src[m.start():end]
        sid = m.group(1)
        h2 = re.search(r"<h2[^>]*>(.*?)</h2>", chunk, re.S)
        title, cnt = "", 0
        if h2:
            cm = re.search(r'<span class="cnt">(\d+)</span>', h2.group(1))
            cnt = int(cm.group(1)) if cm else 0
            title = re.sub(r'<span class="cnt">\d+</span>', "", h2.group(1))
            title = strip_tags(title)
        chunks.append((sid, title, cnt, chunk))
    return chunks


def card_positions(chunk):
    """Границы карточек внутри секции: [(mid, start_pos)] или [(None, pos)]."""
    pos = [(m.start(), m.group(0)) for m in re.finditer(r"<article\b[^>]*>", chunk)]
    cards = []
    attrs_list = [re.sub(r"^<article\b|>$", "", tag) for _, tag in pos]
    for i, (start, tag) in enumerate(pos):
        attrs = attrs_list[i]
        end = pos[i + 1][0] if i + 1 < len(pos) else len(chunk)
        mid = re.search(r'id="(m\d+)"', attrs)
        if mid and "card" in attrs:
            cards.append((mid.group(1), chunk[start:end]))
    return cards


def extract_body(chunk):
    """Вырезает <div class="body">…</div> с учётом вложенности div."""
    m = re.search(r'<div\b[^>]*class="[^"]*\bbody\b[^"]*"[^>]*>', chunk)
    if not m:
        return None
    depth = 1
    pos = m.end()
    for t in re.finditer(r"<div\b|</div>", chunk[pos:]):
        depth += 1 if t.group(0) == "<div" else -1
        if depth == 0:
            return chunk[pos:pos + t.start()]
    return chunk[pos:]  # незакрытый div — берём до конца карточки


def parse_card(mid, chunk):
    title_m = re.search(r"<h3[^>]*>(.*?)</h3>", chunk, re.S)
    title = strip_tags(title_m.group(1)) if title_m else ""
    date_m = re.search(r">(\d{4}-\d{2}-\d{2})<", chunk)
    # vid-note идёт <div>'ом после body — собрать отдельно и убрать из куска
    vid_notes = [
        html.unescape(re.sub(r"<[^>]+>", "", v)).strip()
        for v in re.findall(r'<div class="vid-note">(.*?)</div>', chunk, re.S)
    ]
    chunk = re.sub(r'<div class="vid-note">.*?</div>', "", chunk, flags=re.S)
    inner = extract_body(chunk)
    conts = [mm.group(1) for mm in
             re.finditer(r'class="cont-mark"[^>]*\bid="(m\d+)"', chunk)]
    tags, body, imgs = [], "", 0
    if inner is not None:
        p = BodyParser()
        p.feed(inner)
        p.close()
        body = p.body_html()
        imgs = p.imgs
        seen = set()
        for t in p.tags:
            t = t.lower().lstrip("#")
            if len(t) >= 3 and not t.isdigit() and t not in seen:
                seen.add(t)
                tags.append(t)
    else:
        body = strip_tags(chunk)  # сырой резерв без вёрстки
    for v in vid_notes:
        if body:
            body += " "
        body += '<span class="vid-note">%s</span>' % html.escape(v, quote=False)
    c = {"id": mid, "date": date_m.group(1) if date_m else "",
         "title": title, "body": body}
    if tags:
        c["tags"] = " ".join(tags)
    if conts:
        c["conts"] = " ".join(conts)
    return c, {"vid": len(vid_notes), "imgs": imgs, "no_body": inner is None,
               "no_date": not date_m}


# --- Главная ----------------------------------------------------------------

def main():
    try:
        sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    except Exception:
        pass
    root = Path(__file__).resolve().parent.parent
    src_name = sys.argv[1] if len(sys.argv) > 1 else "Техника бега — подборка сообщений.html"
    src_path = Path(src_name)
    if not src_path.is_absolute():
        src_path = root / src_path
    out_path = root / "data" / "knowledge.js"

    # Защита ручных правок: сборка перезапишет data/knowledge.js целиком,
    # а статьи, добавленные tools/kb_edit.py, в дайджесте не живут.
    if "--force" not in sys.argv and out_path.exists():
        prev = out_path.read_text(encoding="utf-8")
        n_manual = prev.count('"manual":true')
        if n_manual:
            out("В data/knowledge.js есть статьи, добавленные через tools/kb_edit.py (%d шт.)." % n_manual)
            out("Полная пересборка из дайджеста их стрёт — редактор не запускает пересборку автоматически.")
            out("Править базу сейчас:  python tools\\kb_edit.py list")
            out("Если пересборка нужна сознательно:  python tools\\build_knowledge.py --force")
            sys.exit(1)

    text = src_path.read_text(encoding="utf-8")

    warns, errors = [], []
    groups, all_tags, cont_index = [], {}, {}
    all_ids = set()
    n_cards = n_vids = n_imgs = 0

    # Секции дайджеста: карта sid → (title, cnt, chunk)
    sections = {sid: (title, cnt, chunk)
                for sid, title, cnt, chunk in section_chunks(text)}

    for gid, gtitle, sids in GROUPS:
        topics = []
        for sid in sids:
            if sid not in sections:
                warns.append("Секция %s не найдена в дайджесте" % sid)
                topics.append({"id": sid, "title": sid, "cnt": 0, "cards": []})
                continue
            title, cnt, chunk = sections[sid]
            title = TITLE_OVERRIDES.get(sid, title)
            cards = []
            excl_cnt = 0
            for mid, cchunk in card_positions(chunk):
                if mid in CARD_EXCLUDES:
                    warns.append("%s: исключён по CARD_EXCLUDES" % mid)
                    excl_cnt += 1
                    continue
                c, info = parse_card(mid, cchunk)
                if mid in all_ids:
                    errors.append("id повторяется: %s" % mid)
                all_ids.add(mid)
                for cont in (c.get("conts") or "").split():
                    cont_index[cont] = mid
                if info["no_body"]:
                    warns.append("%s: не найден <div class=body>" % mid)
                if info["no_date"]:
                    warns.append("%s: нет даты" % mid)
                for t in (c.get("tags") or "").split():
                    all_tags[t] = all_tags.get(t, 0) + 1
                n_vids += info["vid"]
                n_imgs += info["imgs"]
                cards.append(c)
                n_cards += 1
            if not cards and not excl_cnt:
                warns.append("Секция %s пуста (0 карточек)" % sid)
            # cnt считаем по дайджесту минус исключённые; иначе — расхождение
            if cnt and len(cards) != cnt and len(cards) + excl_cnt != cnt:
                warns.append("Секция %s: cnt=%d, найдено %d (+исключено %d)"
                             % (sid, cnt, len(cards), excl_cnt))
            topics.append({"id": sid, "title": title, "cnt": cnt,
                           "cards": cards})
        groups.append({"id": gid, "title": gtitle, "topics": topics})

    # Секции, не попавшие ни в одну группу
    used = {sid for _, _, sids in GROUPS for sid in sids}
    found = {sid for sid, _, _, _ in section_chunks(text)}
    for sid in sorted(found - used):
        warns.append("Секция %s не включена ни в одну группу" % sid)

    # Данные
    data = {
        "generated": date.today().isoformat(),
        "source": src_path.name,
        "counts": {"sections": len(found), "cards": n_cards},
        "groups": groups,
        "tagCounts": [[t, c] for t, c in
                      sorted(all_tags.items(), key=lambda x: -x[1])[:TOP_TAGS]],
        "contIndex": cont_index,
    }
    js = "window.WZ_KNOWLEDGE = " + json.dumps(
        data, ensure_ascii=False, separators=(",", ":")) + ";\n"
    out_path.write_text(js, encoding="utf-8")

    # --- Самопроверки --------------------------------------------------------
    out("Готово: %s (%d КБ)" % (out_path.relative_to(root), len(js) // 1024))
    out("Секции: %d (ожидалось %d)" % (len(found), EXPECTED_SECTIONS))
    out("Карточки: %d (ожидалось %d)" % (n_cards, EXPECTED_CARDS))
    out("Продолжений: %d | видео-заметок: %d | картинок вырезано: %d"
        % (len(cont_index), n_vids, n_imgs))
    out("Тегов: %d (top: %s)" % (len(all_tags),
        ", ".join("%s %d" % (kv[0], kv[1]) for kv in data["tagCounts"][:5])))
    if len(found) != EXPECTED_SECTIONS:
        errors.append("Секций %d вместо %d" % (len(found), EXPECTED_SECTIONS))
    expected_cards = EXPECTED_CARDS - len(CARD_EXCLUDES)
    if n_cards != expected_cards:
        errors.append("Карточек %d вместо %d" % (n_cards, expected_cards))
    if len(all_tags) < 25:
        errors.append("Тегов всего %d — парсер, похоже, потерял span.hl" % len(all_tags))
    top1 = data["tagCounts"][0][0] if data["tagCounts"] else ""
    if top1 != "техникабега":
        errors.append("Топ-1 тег = %r, а не «техникабега»" % top1)

    # Повторный запуск: старые якоря не должны пропадать
    if out_path.exists():
        try:
            prev = out_path.read_text(encoding="utf-8")
        except Exception:
            prev = ""
        prev_ids = set(re.findall(r'"id":"(m\d+)"', prev))
        if prev_ids:
            lost = prev_ids - all_ids
            if lost:
                warns.append("Пропали из прошлой сборки: %s"
                             % ", ".join(sorted(lost)[:10]))

    if warns:
        out("")
        out("Предупреждения (%d):" % len(warns))
        for w in warns[:30]:
            out("  - " + w)
        if len(warns) > 30:
            out("  … и ещё %d" % (len(warns) - 30))
    if errors:
        out("")
        out("ОШИБКИ:")
        for e in errors:
            out("  ! " + e)
        sys.exit(1)


if __name__ == "__main__":
    main()