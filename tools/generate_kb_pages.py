# -*- coding: utf-8 -*-
"""Генератор статических страниц базы знаний сайта ВЖУХ.

Читает компакт-файл данных data/knowledge.js (window.WZ_KNOWLEDGE) и
генерирует в kb/:
  * kb/index.html         — хаб со списком всех тем;
  * kb/sXX-slug.html      — страница каждой темы (21 шт.);
  * data/kb-sitemap.xml   — фрагмент sitemap, из которого собирается
                            весь sitemap.xml (см. rebuild_sitemap ниже).

Страницы статические: их видит поисковик без выполнения JS — в отличие
от интерактивной knowledge.html, где контент лежит в data/knowledge.js.

Запуск:
    python tools/generate_kb_pages.py

Файлы в kb/ — генерируемые: руками не править.
Только stdlib. Python 3.7+.
"""
import json
import re
import sys
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
DATA = ROOT / "data" / "knowledge.js"
OUT = ROOT / "kb"

SITE = "https://vzuh.run"
TODAY = date.today().isoformat()

# Транслитерация для ЧПУ (кириллица -> латиница)
TRANSLIT = {
    "а": "a", "б": "b", "в": "v", "г": "g", "д": "d", "е": "e", "ё": "e",
    "ж": "zh", "з": "z", "и": "i", "й": "y", "к": "k", "л": "l", "м": "m",
    "н": "n", "о": "o", "п": "p", "р": "r", "с": "s", "т": "t", "у": "u",
    "ф": "f", "х": "h", "ц": "c", "ч": "ch", "ш": "sh", "щ": "sch",
    "ъ": "", "ы": "y", "ь": "", "э": "e", "ю": "yu", "я": "ya",
}

PAGE_STYLE = """
    <style>
    .kb-page-nav { margin-top: 8px; }
    .kb-cards { max-width: 72ch; }
    .kb-card { padding: 18px 0; border-top: 1px solid #e5e5e5; }
    .kb-card:first-of-type { border-top: 0; }
    .kb-card h3 { margin: 0 0 8px; }
    .kb-card p { margin: 0 0 10px; }
    .kb-toptopics { list-style: none; padding: 0; max-width: 72ch; }
    .kb-toptopics li { padding: 10px 0; border-top: 1px solid #e5e5e5; }
    .kb-toptopics li:first-child { border-top: 0; }
    .kb-topic-note { color: #666; }
    </style>
""".strip()


def slugify(text: str) -> str:
    text = text.split(":")[0]  # «Основы: техника…» -> «Основы»
    text = text.lower()
    out = "".join(TRANSLIT.get(ch, ch) for ch in text)
    out = re.sub(r"[^a-z0-9]+", "-", out).strip("-")
    return out


def topic_url(topic) -> str:
    return "/kb/%s-%s.html" % (topic["id"], slugify(topic["title"]))


def strip_tags(html: str) -> str:
    return re.sub(r"<[^>]+>", " ", html)


def topic_description(topic, group) -> str:
    """Стабильное описание: заголовок темы + что это за материал.
    Тело первой карточки не используем намеренно: оно часто начинается
    с эмодзи и «болтовни» автора — для meta description это мусор."""
    return ("{} — заметки тренера Александра Трофимова из базы знаний "
            "бегового клуба «ВЖУХ»: раздел «{}», {} заметок.".format(
                topic["title"], group["title"], len(topic["cards"])))


def strip_card_heading(card) -> str:
    """Убирает из тела дублирующийся заголовок карточки
    (он уже выводится как <h3>)."""
    body = card["body"]
    t = re.escape(card["title"])
    body = re.sub(r"^\s*<strong>\s*" + t + r"\s*</strong>\s*(<br\s*/?>\s*)*", "", body)
    return body


# --- Шаблоны ---------------------------------------------------------------

HEAD = """<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{title}</title>
  <meta name="description" content="{description}">
  <link rel="canonical" href="{site}{path}">
  <meta property="og:title" content="{title}">
  <meta property="og:description" content="{description}">
  <meta property="og:image" content="{site}/assets/emblem.jpg">
  <meta property="og:url" content="{site}{path}">
  <meta property="og:type" content="article">
  <meta property="og:locale" content="ru_RU">
  <link rel="icon" type="image/png" sizes="32x32" href="/assets/favicon-32.png">
  <link rel="icon" href="/assets/favicon.ico">
  <link rel="apple-touch-icon" href="/assets/apple-touch-icon.png">
  <script type="application/ld+json">
  {json_ld}
  </script>
  <link rel="stylesheet" href="/css/shared.css">
  <link rel="stylesheet" href="/css/04-corporate.css">
{extra_head}
</head>
<body class="v04">

<header class="site-header">
  <div class="container header-inner">
    <a class="brand" href="/">
      <img class="brand-logo" src="/assets/emblem.jpg" alt="Эмблема клуба ВЖУХ: сердце из бегущих дуг">
      <span class="brand-name">ВЖУХ</span>
    </a>
    <nav class="site-nav" id="site-nav" aria-label="Основная навигация">
      <a href="/">Главная</a>
      <a href="/#training">Тренировки</a>
      <a href="/knowledge.html">База знаний</a>
      <a href="{back_here}" aria-current="page">Темы базы знаний</a>
      <a href="/#contacts">Контакты</a>
    </nav>
    <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="site-nav">Меню</button>
  </div>
</header>
"""

FOOTER = """
<footer class="site-footer">
  <div class="container footer-inner">
    <div class="footer-brand">
      <img src="/assets/emblem.jpg" alt="Эмблема клуба ВЖУХ">
      <span>ВЖУХ — беговой клуб · Москва</span>
    </div>
    <nav class="footer-links" aria-label="Ссылки в подвале">
      <a href="/">Главная</a>
      <a href="/knowledge.html">База знаний</a>
      <a href="{back_here}">Темы базы знаний</a>
      <a href="/#contacts">Контакты</a>
    </nav>
    <span>© 2026 ВЖУХ</span>
  </div>
</footer>
"""


def render_article_jsonld(title: str, description: str, path: str) -> str:
    return json.dumps({
        "@context": "https://schema.org",
        "@type": "Article",
        "headline": title,
        "description": description,
        "mainEntityOfPage": SITE + path,
        "author": {"@type": "Person", "name": "Александр Трофимов"},
        "publisher": {"@type": "Organization", "name": "ВЖУХ — беговой клуб",
                      "logo": {"@type": "ImageObject", "url": SITE + "/assets/emblem.jpg"}},
        "dateModified": DATA_GEN_DATE,
    }, ensure_ascii=False, indent=2)


def render_hub(data) -> str:
    topics_all = [t for g in data["groups"] for t in g["topics"]]
    items = []
    for g in data["groups"]:
        for t in g["topics"]:
            items.append(
                '<li><a href="%s">%s</a> <span class="kb-topic-note">— %s, заметок: %d</span></li>'
                % (topic_url(t), t["title"], g["title"], len(t["cards"]))
            )
    nav_all = "\n".join(items)
    json_ld = json.dumps({
        "@context": "https://schema.org",
        "@type": "CollectionPage",
        "name": "База знаний — все темы",
        "url": SITE + "/kb/",
        "description": "Все темы базы знаний бегового клуба «ВЖУХ»: техника бега, тренировки, восстановление, физиология. Записки тренера Александра Трофимова.",
        "publisher": {"@type": "Organization", "name": "ВЖУХ — беговой клуб"},
    }, ensure_ascii=False, indent=2)
    page = HEAD.format(
        title="База знаний — все темы · ВЖУХ — беговой клуб",
        description="Все темы базы знаний клуба ВЖУХ: техника бега, наклон и стопа, пульсовые зоны, марафонская подготовка, восстановление, физиология — записки тренера.",
        site=SITE, path="/kb/",
        json_ld=json_ld,
        extra_head=PAGE_STYLE, back_here="/kb/",
    ) + """
<main class="section" id="top">
  <div class="container">
    <p class="section-kicker">База знаний</p>
    <h1 class="section-title">Заметки тренера по темам</h1>
    <p class="section-lead">Техника бега, тренировки и подготовка, здоровье и восстановление
      — все темы записок тренера Александра Трофимова отдельными страницами.
      Интерактивная версия с поиском: <a href="/knowledge.html">База знаний</a>.</p>

    <ul class="kb-toptopics">
%(nav)s
    </ul>
  </div>
</main>
""" % {"nav": nav_all} + FOOTER.format(back_here="/kb/")
    return page + "\n<!-- generated: generate_kb_pages.py %s -->\n" % TODAY


def render_topic(data, group, topic, prev_topic, next_topic) -> str:
    path = topic_url(topic).lstrip("/")
    cards = []
    for c in topic["cards"]:
        cards.append(
            '<article class="kb-card" id="%s">\n      <h3>%s</h3>\n      %s\n    </article>'
            % (c["id"], escape(c["title"]), strip_card_heading(c))
        )
    cards_html = "\n    ".join(cards)
    desc = topic_description(topic, group)
    prev_links = []
    if prev_topic:
        prev_links.append('← <a href="%s">%s</a>' % (topic_url(prev_topic), escape(prev_topic["title"])))
    if next_topic:
        prev_links.append('<a href="%s">%s</a> →' % (topic_url(next_topic), escape(next_topic["title"])))
    prev_html = " · ".join(prev_links) or ""
    json_ld = render_article_jsonld(topic["title"], desc, "/" + path)
    back_line = ('<p><a href="/kb/">← Все темы</a> · '
                 '<a href="/knowledge.html">Открыть в интерактивной базе</a></p>')
    page = HEAD.format(
        title="%s · База знаний · ВЖУХ" % escape(topic["title"]),
        description=escape(desc),
        site=SITE, path="/" + path,
        json_ld=json_ld,
        extra_head=PAGE_STYLE, back_here="/kb/",
    ) + """
<main class="section" id="top">
  <div class="container">
    <p class="section-kicker">База знаний · {grp}</p>
    <h1 class="section-title">{title}</h1>
    <p class="section-lead">{desc}</p>
    {back_line}

    <div class="kb-cards">
    {cards}
    </div>

    <p class="kb-page-nav">{pn}</p>
  </div>
</main>
""".format(
        grp=escape(group["title"]),
        title=escape(topic["title"]),
        desc=escape(desc),
        cards=cards_html,
        pn=prev_html,
        back_line=back_line,
    ) + FOOTER.format(back_here="/kb/")
    return page + "\n<!-- generated: generate_kb_pages.py %s -->\n" % TODAY


# --- Утилиты --------------------------------------------------------------

def escape(s: str) -> str:
    return (s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;"))


def write_sitemap(data) -> None:
    """Пересобирает sitemap.xml целиком: 2 статические страницы сайта +
    хаб и все страницы тем. Хранится в data/kb-sitemap.xml только как
    черновик; финальный файл — sitemap.xml в корне проекта."""
    urls = ["", "knowledge.html", "kb/"] + \
           [topic_url(t).lstrip("/") for _, t in
            [(g, t) for g in data["groups"] for t in g["topics"]]]
    rows = "\n".join(
        "  <url>\n    <loc>%s/%s</loc>\n    <lastmod>%s</lastmod>\n  </url>"
        % (SITE, u, TODAY) for u in urls
    )
    xml = ('<?xml version="1.0" encoding="UTF-8"?>\n'
           '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
           '%s\n</urlset>\n' % rows)
    (ROOT / "sitemap.xml").write_text(xml, encoding="utf-8", newline="\n")
    print("written: sitemap.xml (%d urls)" % len(urls))


def main() -> None:
    src = DATA.read_text(encoding="utf-8")
    data = json.loads(src[src.index("{"):src.rindex("}") + 1])
    globals()["DATA_GEN_DATE"] = data.get("generated", TODAY)

    OUT.mkdir(exist_ok=True)
    (OUT / "index.html").write_text(render_hub(data), encoding="utf-8", newline="\n")
    print("written:", OUT / "index.html")

    topics = [(g, t) for g in data["groups"] for t in g["topics"]]
    for i, (g, t) in enumerate(topics):
        prev = topics[i - 1][1] if i > 0 else None
        nxt = topics[i + 1][1] if i + 1 < len(topics) else None
        p = OUT / (topic_url(t).lstrip("/").rsplit("/", 1)[-1])
        p.write_text(render_topic(data, g, t, prev, nxt), encoding="utf-8", newline="\n")
        print("written:", p.name)

    print("topics:", len(topics), "cards:", sum(len(t["cards"]) for _, t in topics))
    write_sitemap(data)


if __name__ == "__main__":
    main()