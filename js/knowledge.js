/* ВЖУХ — база знаний: дерево тем, ленивый рендер карточек,
   поиск по слову, deep-links #g/#s/#m */
(function () {
  "use strict";

  var DATA = window.WZ_KNOWLEDGE;
  var tree = document.getElementById("kb-tree");
  var resultsBox = document.getElementById("kb-results");
  var searchInput = document.getElementById("kb-search");
  var clearBtn = document.getElementById("kb-clear");
  var topBtn = document.getElementById("kb-top");

  if (!tree) { return; }
  if (!DATA || !Array.isArray(DATA.groups)) {
    var loading = tree.querySelector(".kb-loading");
    if (loading) {
      loading.textContent =
        "База знаний не загрузилась. Проверьте, что рядом лежит файл " +
        "data\\knowledge.js — и запустите сборку: python tools\\build_knowledge.py";
    }
    return;
  }

  /* --- Индекс тем: sid → {group, topic} ------------------------------ */
  var topicIndex = {};
  var cardIndex = {}; // id карточки → {group, topic, card}
  DATA.groups.forEach(function (g) {
    g.topics.forEach(function (t) {
      topicIndex[t.id] = { group: g, topic: t };
      t.cards.forEach(function (c) {
        cardIndex[c.id] = { group: g, topic: t, card: c };
      });
    });
  });

  /* --- Построение разметки ------------------------------------------- */

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== "") { e.textContent = text; }
    return e;
  }

  function buildCard(card, opts) {
    var art = el("article", "card kb-card");
    if (opts && opts.inTree) { art.id = card.id; } else { art.dataset.id = card.id; }

    var header = el("header");
    header.appendChild(el("h3", null, card.title || "Заметка"));
    var meta = el("div", "meta");
    var parts = [];
    if (card.date) { parts.push(card.date); }
    meta.textContent = parts.join(" ");
    // #NNN — ссылка к самой себе (в дереве это якорь, в результатах — переход)
    var badge = document.createElement("a");
    badge.className = "idbadge";
    badge.href = "#" + card.id;
    badge.textContent = "#" + card.id.replace(/^m/, "");
    meta.appendChild(document.createTextNode(" · "));
    meta.appendChild(badge);
    header.appendChild(meta);
    art.appendChild(header);

    var body = el("div", "body");
    body.innerHTML = card.body || "";
    art.appendChild(body);
    return art;
  }

  function buildTopicCards(topic, container) {
    topic.cards.forEach(function (c) {
      container.appendChild(buildCard(c, { inTree: true }));
    });
    container.__built = true;
  }

  function buildTree() {
    tree.textContent = "";
    DATA.groups.forEach(function (g) {
      var gDet = el("details", "kb-group");
      gDet.id = g.id;
      var gSum = el("summary");
      gSum.appendChild(el("span", null, g.title));
      gSum.appendChild(el("span", "kb-count",
        g.topics.reduce(function (a, t) { return a + t.cards.length; }, 0) + " заметок"));
      gDet.appendChild(gSum);

      var topicsBox = el("div", "kb-topics");
      g.topics.forEach(function (t) {
        var tDet = el("details", "kb-topic");
        tDet.id = t.id;
        var tSum = el("summary");
        tSum.appendChild(el("span", null, t.title));
        tSum.appendChild(el("span", "kb-count", t.cards.length + ""));
        tDet.appendChild(tSum);
        var cardsBox = el("div", "kb-cards");
        tDet.appendChild(cardsBox);
        // лениво: карточки строим при первом раскрытии темы
        tDet.addEventListener("toggle", function () {
          if (tDet.open && !cardsBox.__built) { buildTopicCards(t, cardsBox); }
        });
        topicsBox.appendChild(tDet);
      });
      gDet.appendChild(topicsBox);
      tree.appendChild(gDet);
    });
  }

  /* --- Поиск ------------------------------------------------------------ */

  var index = null;        // [{card, group, topic, hay}]
  var query = "";
  var debounceTimer = null;

  function buildIndex() {
    index = [];
    DATA.groups.forEach(function (g) {
      g.topics.forEach(function (t) {
        t.cards.forEach(function (c) {
          index.push({
            card: c,
            group: g,
            topic: t,
            hay: ((c.title || "") + "\n" + stripHtml(c.body)).toLowerCase()
          });
        });
      });
    });
  }

  function stripHtml(s) {
    var d = document.createElement("div");
    d.innerHTML = s || "";
    return d.textContent || "";
  }

  function highlight(root, q) {
    if (!q || q.length < 2) { return; }
    var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var nodes = [];
    while (walker.nextNode()) { nodes.push(walker.currentNode); }
    var ql = q.toLowerCase();
    nodes.forEach(function (node) {
      var text = node.nodeValue;
      if (!text || text.toLowerCase().indexOf(ql) === -1) { return; }
      var frag = document.createDocumentFragment();
      var rest = text;
      var lowRest = rest.toLowerCase();
      while (lowRest.indexOf(ql) !== -1) {
        var i = lowRest.indexOf(ql);
        frag.appendChild(document.createTextNode(rest.slice(0, i)));
        var mark = document.createElement("mark");
        mark.textContent = rest.substr(i, q.length);
        frag.appendChild(mark);
        rest = rest.slice(i + q.length);
        lowRest = rest.toLowerCase();
      }
      if (rest) { frag.appendChild(document.createTextNode(rest)); }
      node.parentNode.replaceChild(frag, node);
    });
  }

  function renderResults() {
    var found = [];
    if (!index) { buildIndex(); }
    index.forEach(function (item) {
      if (query.length >= 2 && item.hay.indexOf(query) !== -1) { found.push(item); }
    });

    resultsBox.textContent = "";
    var head = el("div", "kb-results-head");
    var back = el("button", "kb-results-back", "← К дереву тем");
    back.type = "button";
    back.addEventListener("click", clearFilters);
    head.appendChild(el("div", "kb-results-count",
      "Поиск «" + query + "» — найдено: " + found.length));
    head.appendChild(back);
    resultsBox.appendChild(head);

    if (!found.length) {
      resultsBox.appendChild(el("p", "kb-empty",
        "Ничего не нашлось. Попробуйте другое слово — например «каденс», «пульс», «питание»."));
    }

    var CAP = 60;
    found.slice(0, CAP).forEach(function (item) {
      var card = buildCard(item.card, { inTree: false });
      var path = el("p", "kb-result-path", item.group.title + " → " + item.topic.title);
      var wrapper = el("div");
      wrapper.appendChild(path);
      wrapper.appendChild(card);
      highlight(card, query);
      resultsBox.appendChild(wrapper);
    });
    if (found.length > CAP) {
      resultsBox.appendChild(el("p", "kb-results-cap",
        "Показаны первые " + CAP + " из " + found.length + ". Уточните запрос."));
    }
  }

  function applyView() {
    var searching = query.length >= 2;
    tree.hidden = searching;
    resultsBox.hidden = !searching;
    clearBtn.hidden = !query;
  }

  function clearFilters() {
    query = "";
    searchInput.value = "";
    applyView();
    tree.scrollIntoView({ block: "start" });
  }

  /* --- Deep-links: #g2, #s05, #m249 (и #m198 → продолжение) ----------- */

  function navigateTo(hash, scroll) {
    var id = (hash || "").replace(/^#/, "");
    if (!id) { return false; }

    var isGroup = /^g\d+$/.test(id);
    var isTopic = !!topicIndex[id];
    var owner = null;
    if (!isGroup && !isTopic) {
      owner = cardIndex[id] || cardIndex[(DATA.contIndex || {})[id]];
      if (!owner) { return false; }
    }

    var groupEl, topicEl, cardsBox;
    if (isGroup) {
      groupEl = document.getElementById(id);
    } else {
      var ref = isTopic ? topicIndex[id] : owner;
      groupEl = document.getElementById(ref.group.id);
      topicEl = document.getElementById(isTopic ? id : owner.topic.id);
      if (topicEl) {
        cardsBox = topicEl.querySelector(".kb-cards");
        if (cardsBox && !cardsBox.__built) { // собрать сразу, не дожидаясь toggle
          buildTopicCards((isTopic ? ref : owner).topic, cardsBox);
        }
      }
    }
    if (groupEl && !groupEl.open) { groupEl.open = true; }
    if (topicEl && !topicEl.open) { topicEl.open = true; }

    if (scroll === false) { return true; }
    var target = document.getElementById(id);
    if (target) {
      target.scrollIntoView({ block: "start", behavior: "smooth" });
    }
    return true;
  }

  /* --- Разное ----------------------------------------------------------- */

  function showTopButton() {
    if (!topBtn) { return; }
    var onScroll = function () {
      topBtn.hidden = window.pageYOffset < 700;
    };
    window.addEventListener("scroll", onScroll, { passive: true });
    onScroll();
    topBtn.addEventListener("click", function () {
      window.scrollTo({ top: 0, behavior: "smooth" });
    });
  }

  /* --- Старт --------------------------------------------------------------- */

  function init() {
    buildTree();
    showTopButton();

    searchInput.addEventListener("input", function () {
      query = searchInput.value.trim().replace(/^#/, "").toLowerCase();
      if (!query) {
        applyView();
        return;
      }
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(applyView, 250);
    });
    clearBtn.addEventListener("click", clearFilters);

    // deep-link при загрузке и на смене хеша
    navigateTo(location.hash);
    window.addEventListener("hashchange", function () {
      if (query) { clearFilters(); }
      navigateTo(location.hash);
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();