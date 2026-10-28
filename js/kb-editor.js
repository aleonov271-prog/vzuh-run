/* ВЖУХ — редактор базы знаний: дерево тем, карточки, статьи,
   черновик, скачивание knowledge.js и публикация через kb.php */
(function () {
  "use strict";

  var DRAFT_KEY = "wz-kb-draft";

  function byId(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /* Простой текст -> HTML для новых статей: экран «<» «>&», строки -> <br> */
  function textToHtml(text) {
    return esc(text).replace(/\n{2,}/g, "<br><br>").replace(/\n/g, "<br>");
  }

  var K = null;          /* рабочая копия базы */
  var selected = null;   /* { gi, ti } выбранной темы */

  /* --- Инициализация данных ------------------------------------ */
  function initData() {
    var data = window.WZ_KNOWLEDGE;
    if (!data || !Array.isArray(data.groups)) {
      byId("kb-status").textContent =
        "База знаний не загрузилась: проверьте, что рядом лежит файл data/knowledge.js.";
      return;
    }
    /* рабочая копия — независимая, правки не трогают исходник */
    K = JSON.parse(JSON.stringify(data));
    K.groups.forEach(function (g) {
      g.topics = g.topics || [];
      g.topics.forEach(function (t) { t.cards = t.cards || []; });
    });
    selected = K.groups.length && K.groups[0].topics.length ? { gi: 0, ti: 0 } : null;
    render();
    restoreDraftOffer();
  }

  /* --- Черновик -------------------------------------------------- */
  var draftTimer = null;
  function saveDraft() {
    if (!K) { return; }
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify(K));
    } catch (e) { /* квота localStorage — не критично */ }
  }
  function touchDraft() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(saveDraft, 600);
  }

  function restoreDraftOffer() {
    var raw = null;
    try { raw = localStorage.getItem(DRAFT_KEY); } catch (e) {}
    if (!raw) { return; }
    var draft = null;
    try { draft = JSON.parse(raw); } catch (e) {}
    if (!draft || !draft.groups) { return; }
    if (JSON.stringify(draft) === JSON.stringify(K)) { return; } /* без правок */
    var btn = byId("btn-draft");
    btn.hidden = false;
    btn.addEventListener("click", function () {
      if (confirm("Вернуть правки из черновика (то, что не публиковалось)?")) {
        K = draft;
        selected = K.groups.length && K.groups[0].topics.length ? { gi: 0, ti: 0 } : null;
        render();
      }
    });
  }

  /* --- Утилиты дерева -------------------------------------------- */
  function groupByIndex() { return K.groups; }
  function topicOf(sel) { return K.groups[sel.gi].topics[sel.ti]; }
  function groupOf(sel) { return K.groups[sel.gi]; }

  function allCardIds() {
    var ids = [];
    K.groups.forEach(function (g) {
      g.topics.forEach(function (t) {
        t.cards.forEach(function (c) { ids.push(c.id); });
      });
    });
    return ids;
  }
  function nextCardId() {
    var max = 0;
    allCardIds().forEach(function (id) {
      var m = String(id).match(/^m(\d+)$/);
      if (m) { max = Math.max(max, Number(m[1])); }
    });
    return "m" + (max + 1);
  }
  function todayISO() {
    var d = new Date();
    return d.getFullYear() + "-" +
      ("0" + (d.getMonth() + 1)).slice(-2) + "-" +
      ("0" + d.getDate()).slice(-2);
  }

  /* --- Разбор вставленных статей --------------------------------- */
  /* Блоки разделены строкой «---»; первая строка блока — заголовок;
     если следующая строка — дата вида 2026-01-05, берём её как дату */
  function parseArticles(text) {
    var out = [];
    var blocks = String(text).split(/^\s*-{3,}\s*$/m);
    blocks.forEach(function (block) {
      var lines = block.split(/\n/);
      while (lines.length && !lines[0].trim()) { lines.shift(); }
      if (!lines.length) { return; }
      var title = lines.shift().trim();
      if (!title) { return; }
      var date = "";
      if (lines.length && /^\d{4}-\d{2}-\d{2}\b/.test(lines[0].trim())) {
        date = lines[0].trim().match(/^\d{4}-\d{2}-\d{2}/)[0];
        lines.shift();
      }
      var body = lines.join("\n").trim();
      out.push({
        title: title.slice(0, 120),
        date: date,
        body: body ? textToHtml(body) : ""
      });
    });
    return out;
  }

  function addArticle(list, article) {
    list.push({
      id: nextCardId(),
      date: article.date || todayISO(),
      title: article.title,
      body: article.body
    });
  }

  /* --- Рендер: дерево тем ---------------------------------------- */
  function render() {
    renderTree();
    renderCards();
    renderStatus();
  }

  function renderStatus() {
    var totalTopics = 0, totalCards = 0;
    K.groups.forEach(function (g) {
      totalTopics += g.topics.length;
      g.topics.forEach(function (t) { totalCards += t.cards.length; });
    });
    byId("kb-status").textContent =
      "Групп: " + K.groups.length + " · тем: " + totalTopics + " · карточек: " + totalCards;
  }

  function moveEl(arr, i, dir) {
    var j = i + dir;
    if (j < 0 || j >= arr.length) { return; }
    var tmp = arr[i]; arr[i] = arr[j]; arr[j] = tmp;
  }

  function renderTree() {
    var tree = byId("kb-tree");
    tree.textContent = "";

    K.groups.forEach(function (g, gi) {
      var groupEl = document.createElement("div");
      groupEl.className = "kb-group";

      var head = document.createElement("div");
      head.className = "kb-row kb-group-row";
      head.innerHTML =
        '<button type="button" class="mini-btn kb-up" title="Выше">▲</button>' +
        '<button type="button" class="mini-btn kb-down" title="Ниже">▼</button>' +
        '<button type="button" class="mini-btn kb-del" title="Удалить группу">✕</button>' +
        '<input class="kb-title-input" placeholder="Название группы">';
      head.querySelector(".kb-title-input").value = g.title;

      head.querySelector(".kb-up").addEventListener("click", function () {
        moveEl(K.groups, gi, -1); touchDraft(); render();
      });
      head.querySelector(".kb-down").addEventListener("click", function () {
        moveEl(K.groups, gi, 1); touchDraft(); render();
      });
      head.querySelector(".kb-del").addEventListener("click", function () {
        var total = g.topics.length;
        if (!confirm('Удалить группу «' + g.title + '»' +
            (total ? " вместе с " + total + " тем(ой) и всеми её карточками" : "") + "?")) {
          return;
        }
        K.groups.splice(gi, 1);
        if (selected && selected.gi === gi) { selected = null; }
        else if (selected) {
          if (selected.gi > gi) { selected.gi--; }
          else if (selected.gi === gi) { selected = null; }
        }
        touchDraft(); render();
      });
      head.querySelector(".kb-title-input").addEventListener("input", function () {
        g.title = this.value; touchDraft();
      });

      groupEl.appendChild(head);

      g.topics.forEach(function (t, ti) {
        var row = document.createElement("div");
        var isSel = selected && selected.gi === gi && selected.ti === ti;
        row.className = "kb-row kb-topic-row" + (isSel ? " kb-selected" : "");
        row.innerHTML =
          '<button type="button" class="mini-btn kb-up" title="Выше">▲</button>' +
          '<button type="button" class="mini-btn kb-down" title="Ниже">▼</button>' +
          '<button type="button" class="mini-btn kb-del" title="Удалить тему">✕</button>' +
          '<input class="kb-title-input" placeholder="Название темы">' +
          '<span class="kb-cnt">' + (t.cards ? t.cards.length : 0) + '</span>';
        row.querySelector(".kb-title-input").value = t.title;

        row.addEventListener("click", function (e) {
          var tag = e.target.tagName;
          if (tag === "BUTTON") { return; }
          if (tag === "INPUT" && isSel) { return; } /* переименование — не перерисовывать */
          selected = { gi: gi, ti: ti };
          render();
          if (tag === "INPUT") {
            var again = byId("kb-tree").querySelector(".kb-row.kb-selected .kb-title-input");
            if (again) { again.focus(); }
          }
        });
        row.querySelector(".kb-up").addEventListener("click", function () {
          moveEl(g.topics, ti, -1); touchDraft(); render();
        });
        row.querySelector(".kb-down").addEventListener("click", function () {
          moveEl(g.topics, ti, 1); touchDraft(); render();
        });
        row.querySelector(".kb-del").addEventListener("click", function () {
          if (!confirm('Удалить тему «' + t.title + "» вместе с " +
              (t.cards ? t.cards.length : 0) + " карточками?")) { return; }
          g.topics.splice(ti, 1);
          if (selected && selected.gi === gi) {
            if (selected.ti >= g.topics.length) { selected.ti = g.topics.length - 1; }
            if (selected.ti < 0) { selected = null; }
          }
          touchDraft(); render();
        });
        row.querySelector(".kb-title-input").addEventListener("input", function () {
          t.title = this.value; touchDraft();
        });

        groupEl.appendChild(row);
      });

      tree.appendChild(groupEl);
    });
  }

  /* --- Рендер: карточки выбранной темы --------------------------- */
  function renderCards() {
    var box = byId("kb-cards");
    box.textContent = "";

    if (!selected) {
      var hint = document.createElement("p");
      hint.className = "kb-empty-topic";
      hint.textContent = "Выберите тему слева или создайте новую — кнопка «+ Группа».";
      box.appendChild(hint);
      return;
    }

    var group = groupOf(selected);
    var topic = topicOf(selected);

    var head = document.createElement("div");
    head.className = "kb-cards-head";
    head.innerHTML =
      '<h3 class="kb-cards-title">' + esc(topic.title) + ' <small>' +
      esc(group.title) + '</small></h3>';
    var addBtn = document.createElement("button");
    addBtn.type = "button";
    addBtn.className = "mini-btn";
    addBtn.textContent = "+ Добавить карточку";
    addBtn.addEventListener("click", function () {
      var card = { id: nextCardId(), date: todayISO(), title: "", body: "" };
      topic.cards.push(card);
      touchDraft();
      render();
      var last = box.querySelector(".kb-card:last-child");
      if (last) {
        last.scrollIntoView({ behavior: "smooth", block: "nearest" });
        var inp = last.querySelector(".kc-title");
        if (inp) { inp.focus(); }
      }
    });
    head.appendChild(addBtn);
    box.appendChild(head);

    topic.cards.forEach(function (card, ci) {
      box.appendChild(cardBlock(topic, ci));
    });
  }

  function cardBlock(topic, ci) {
    var card = topic.cards[ci];
    var el = document.createElement("article");
    el.className = "kb-card";
    el.innerHTML =
      '<details>' +
        '<summary>' +
          '<span class="kc-title-preview">' + esc(card.title || "(без названия)") + '</span>' +
          '<span class="kc-badge">#' + esc(String(card.id).replace(/^m/, "")) + '</span>' +
        '</summary>' +
        '<div class="kc-fields">' +
          '<div class="kc-rows">' +
            '<label>Заголовок<input class="kc-title" value="' + esc(card.title) + '"></label>' +
            '<label>Дата<input class="kc-date" value="' + esc(card.date || "") + '" placeholder="2026-10-01"></label>' +
          '</div>' +
          '<label>Текст карточки (HTML разрешён: &lt;strong&gt;, &lt;br&gt;)<textarea class="kc-body" rows="6">' + esc(card.body) + '</textarea></label>' +
          '<div class="kc-actions">' +
            '<button type="button" class="mini-btn kc-up">▲ Выше</button>' +
            '<button type="button" class="mini-btn kc-down">▼ Ниже</button>' +
            '<button type="button" class="mini-btn kc-remove">✕ Удалить карточку</button>' +
          '</div>' +
        '</div>' +
      '</details>';

    el.querySelector(".kc-title").addEventListener("input", function () {
      card.title = this.value;
      el.querySelector(".kc-title-preview").textContent = card.title || "(без названия)";
      touchDraft();
    });
    el.querySelector(".kc-date").addEventListener("input", function () {
      card.date = this.value.trim();
      touchDraft();
    });
    el.querySelector(".kc-body").addEventListener("input", function () {
      card.body = this.value;
      touchDraft();
    });
    el.querySelector(".kc-up").addEventListener("click", function () {
      if (ci === 0) { return; }
      var tmp = topic.cards[ci - 1];
      topic.cards[ci - 1] = topic.cards[ci];
      topic.cards[ci] = tmp;
      touchDraft(); render();
    });
    el.querySelector(".kc-down").addEventListener("click", function () {
      if (ci >= topic.cards.length - 1) { return; }
      var tmp = topic.cards[ci + 1];
      topic.cards[ci + 1] = topic.cards[ci];
      topic.cards[ci] = tmp;
      touchDraft(); render();
    });
    el.querySelector(".kc-remove").addEventListener("click", function () {
      if (!confirm('Удалить карточку «' + (card.title || card.id) + "»?")) { return; }
      topic.cards.splice(ci, 1);
      touchDraft(); render();
    });

    return el;
  }

  /* --- Сборка файла ---------------------------------------------- */
  function collect() {
    var totalTopics = 0, totalCards = 0;
    K.groups.forEach(function (g) {
      totalTopics += g.topics.length;
      g.topics.forEach(function (t) {
        t.cnt = t.cards.length;
        totalCards += t.cards.length;
      });
    });
    /* sections — число ТЕМ (так в исходной базе: 4 группы, sections=21) */
    K.counts = { sections: totalTopics, cards: totalCards };
    K.generated = todayISO();
    return K;
  }

  function buildFileContent() {
    return "/* БАЗА ЗНАНИЙ КЛУБА «ВЖУХ» — сгенерировано kb-editor.html (" + todayISO() + ").\n" +
      "   Правьте базу в редакторе, не вручную. Кодировка: UTF-8.\n" +
      "   Карточка: { id, date, title, body }. В body — HTML или простой текст. */\n\n" +
      "window.WZ_KNOWLEDGE = " + JSON.stringify(collect()) + ";\n";
  }

  function downloadKnowledge() {
    var out = buildFileContent();
    var blob = new Blob([out], { type: "text/javascript;charset=utf-8" });
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "knowledge.js";
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);
    alert(
      "Файл knowledge.js скачан (он в папке «Загрузки»).\n\n" +
      "1) Переместите его в папку data — с заменой старого файла.\n" +
      "2) Имя файла должно остаться knowledge.js.\n" +
      "3) Откройте knowledge.html и обновите страницу (F5)."
    );
    saveDraft();
  }

  /* --- Публикация (kb.php) ---------------------------------------- */
  var PUBLISH_KEY = "wz-publish-creds"; /* общие с редактором расписания */

  function publishStatus(text, cls) {
    var el = byId("publish-status");
    el.textContent = text;
    el.className = "publish-status" + (cls ? " " + cls : "");
  }

  function publish() {
    var site = byId("f-site").value.trim().replace(/\/+$/, "");
    var password = byId("f-password").value;

    if (!site || !/^https?:\/\//i.test(site)) {
      publishStatus("Укажите адрес сайта вида https://…", "err");
      return;
    }
    if (!password) {
      publishStatus("Введите пароль публикации.", "err");
      return;
    }
    try {
      localStorage.setItem(PUBLISH_KEY, JSON.stringify({ site: site, password: password }));
    } catch (e) {}

    var btn = byId("btn-publish");
    btn.disabled = true;
    publishStatus("Отправляю… (база большая — до минуты)");

    var body = new URLSearchParams();
    body.append("content", buildFileContent());
    body.append("password", password);
    fetch(site + "/kb.php", { method: "POST", body: body })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error("по этому адресу отвечает не обработчик (код " + res.status + "): на хостинге должен лежать файл kb.php");
        }).then(function (answer) { return { status: res.status, answer: answer }; });
      })
      .then(function (r) {
        if (r.answer && r.answer.ok) {
          publishStatus("Опубликовано ✅ откройте knowledge.html и обновите (F5)", "ok");
          try { localStorage.removeItem(DRAFT_KEY); } catch (e) {} /* черновик выгружен */
        } else if (r.status === 403 && r.answer && r.answer.error === "wrong_password") {
          publishStatus("Неверный пароль публикации.", "err");
        } else if (r.answer && r.answer.error === "size") {
          publishStatus("База получилась слишком большая — сообщите разработчику.", "err");
        } else {
          var code = r.answer && r.answer.error ? " (" + r.answer.error + ")" : "";
          publishStatus("Не удалось опубликовать" + code + ".", "err");
        }
      })
      .catch(function (err) {
        publishStatus("Ошибка связи с сайтом: " + err.message, "err");
      })
      .finally(function () { btn.disabled = false; });
  }

  function loadPublishCreds() {
    try {
      var raw = localStorage.getItem(PUBLISH_KEY);
      if (!raw) { return; }
      var creds = JSON.parse(raw);
      if (creds.site) { byId("f-site").value = creds.site; }
      if (creds.password) { byId("f-password").value = creds.password; }
    } catch (e) {}
  }

  /* --- Инициализация ---------------------------------------------- */
  function init() {
    initData();

    byId("btn-download").addEventListener("click", downloadKnowledge);

    /* Загрузка статей файлом */
    byId("btn-load-txt").addEventListener("click", function () {
      byId("file-articles").click();
    });
    byId("file-articles").addEventListener("change", function () {
      if (this.files && this.files[0]) {
        var reader = new FileReader();
        reader.onload = function () { handlePastedArticles(String(reader.result), "файл"); };
        reader.readAsText(this.files[0], "utf-8");
        this.value = "";
      }
    });

    /* Добавление статей из поля */
    byId("btn-paste").addEventListener("click", function () {
      handlePastedArticles(byId("t-paste").value, "поле");
    });

    byId("btn-add-group").addEventListener("click", function () {
      K.groups.push({ id: "g" + (K.groups.length + 1) + "-" + Date.now(), title: "", topics: [] });
      touchDraft(); render();
      var inputs = byId("kb-tree").querySelectorAll(".kb-group:last-child .kb-title-input");
      if (inputs.length) { inputs[inputs.length - 1].focus(); }
    });

    loadPublishCreds();
    byId("btn-publish").addEventListener("click", publish);
  }

  function handlePastedArticles(text, source) {
    if (!selected) {
      byId("kb-paste-status").textContent =
        "Сначала выберите тему слева, куда добавить статьи.";
      return;
    }
    var articles = parseArticles(text);
    if (!articles.length) {
      byId("kb-paste-status").textContent =
        "Не распознал ни одной статьи. Блоки разделены строкой «---», первая строка блока — заголовок.";
      return;
    }
    var topic = topicOf(selected);
    articles.forEach(function (a) { addArticle(topic.cards, a); });
    touchDraft();
    render();
    byId("t-paste").value = "";
    byId("kb-paste-status").textContent =
      "Добавлено статей: " + articles.length + " в тему «" + topic.title + "» (" + source + ").";
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();