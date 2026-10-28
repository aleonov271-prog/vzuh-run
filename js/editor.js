/* ВЖУХ — редактор расписания: TXT-план -> формы -> файл/сайт */
(function () {
  "use strict";

  var DRAFT_KEY = "wz-schedule-draft";

  function byId(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }

  /* --- Блок одной тренировки ---------------------------------- */
  function workoutBlock(w) {
    w = w || {};
    var wrap = document.createElement("div");
    wrap.className = "editor-workout";
    wrap.innerHTML =
      '<div class="ew-rows">' +
        '<label>Название тренировки<input class="w-name" value="' + esc(w.name) + '" placeholder="Например: ВЖУХ-ТЕХНИКА-БЕГА"></label>' +
        '<div class="ew-rows">' +
          '<label>Время<input class="w-time" value="' + esc(w.time) + '" placeholder="18:00–19:30"></label>' +
          '<label>Место<input class="w-place" value="' + esc(w.place) + '" placeholder="Боровское шоссе, 2Ак4"></label>' +
        '</div>' +
      '</div>' +
      '<label>Фото — имя файла из папки images <small>(необязательно; файл кладите в папку images)</small>' +
        '<input class="w-image" value="' + esc(w.image || "") + '" placeholder="например: техника.jpg"></label>' +
      '<label>Описание (откроется в окне на сайте)<textarea class="w-desc" rows="4" placeholder="Что это за тренировка, для чего, кто может прийти...">' + esc(w.description) + '</textarea></label>' +
      '<button type="button" class="mini-btn w-remove">✕ Убрать тренировку</button>';

    wrap.querySelector(".w-remove").addEventListener("click", function () {
      if (confirm("Убрать эту тренировку из расписания?")) { wrap.remove(); }
    });
    return wrap;
  }

  /* --- Блок одного дня ----------------------------------------- */
  function dayBlock(day) {
    day = day || {};
    var d = document.createElement("article");
    d.className = "editor-day";
    d.innerHTML =
      '<div class="ed-head">' +
        '<label>День недели и дата<input class="d-title" value="' + esc(day.day || "") + '" placeholder="Например: Понедельник · 5 октября"></label>' +
        '<button type="button" class="mini-btn d-remove">✕ Убрать день</button>' +
      '</div>' +
      '<div class="ed-workouts"></div>' +
      '<div class="ed-footer">' +
        '<button type="button" class="mini-btn btn-add-workout">+ Добавить тренировку</button>' +
      '</div>';

    d.querySelector(".d-remove").addEventListener("click", function () {
      if (confirm("Убрать день целиком, вместе с тренировками?")) { d.remove(); }
    });

    var list = d.querySelector(".ed-workouts");

    /* День без тренировок: поле текста («Выходной», «Восстановление») */
    if (!day.workouts || !day.workouts.length) {
      var note = document.createElement("label");
      note.className = "d-note";
      note.innerHTML =
        'Текст для дня без тренировок <small>(необязательно)</small>' +
        '<input class="d-note-input" value="' + esc(day.note || "") + '" placeholder="Например: Выходной">';
      list.appendChild(note);
    }

    (day.workouts || []).forEach(function (w) {
      list.appendChild(workoutBlock(w));
    });

    d.querySelector(".btn-add-workout").addEventListener("click", function () {
      /* если был блок «Выходной» — убрать его при добавлении тренировки */
      var note = d.querySelector(".d-note");
      if (note) { note.remove(); }
      list.appendChild(workoutBlock({}));
    });

    return d;
  }

  /* --- Сбор и запись данных ------------------------------------ */
  function collect() {
    var data = {
      subtitle: byId("f-subtitle").value,
      footnote: byId("f-footnote").value,
      days: []
    };
    document.querySelectorAll("#days .editor-day").forEach(function (d) {
      var day = {
        day: d.querySelector(".d-title").value,
        workouts: []
      };
      var noteInput = d.querySelector(".d-note input");
      d.querySelectorAll(".editor-workout").forEach(function (we) {
        day.workouts.push({
          name: we.querySelector(".w-name").value,
          time: we.querySelector(".w-time").value,
          place: we.querySelector(".w-place").value,
          image: we.querySelector(".w-image").value.trim(),
          description: we.querySelector(".w-desc").value
        });
      });
      if (noteInput && noteInput.value) { day.note = noteInput.value; }
      data.days.push(day);
    });
    return data;
  }

  function buildFileContent(data) {
    var header =
      "/* РАСПИСАНИЕ КЛУБА «ВЖУХ» — сгенерировано редактором (editor.html).\n" +
      "   Правьте расписание в редакторе, не вручную. Кодировка: UTF-8.\n" +
      "   Поля тренировки: name, time, place, image (файл из папки images), description. */\n\n" +
      "window.WZ_SCHEDULE = ";
    return header + JSON.stringify(data, null, 2) + ";\n";
  }

  function downloadSchedule() {
    var out = buildFileContent(collect());
    var blob = new Blob([out], { type: "text/javascript;charset=utf-8" });
    var a = document.createElement("a");
    a.href = URL.createObjectURL(blob);
    a.download = "schedule.js";
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(function () { URL.revokeObjectURL(a.href); }, 3000);
    alert(
      "Файл schedule.js скачан (он в папке «Загрузки»).\n\n" +
      "1) Переместите его в папку data — с заменой старого файла.\n" +
      "2) Имя файла должно остаться schedule.js.\n" +
      "3) Откройте index.html и обновите страницу (F5)."
    );
  }

  /* --- Загрузка плана недели из TXT-файла ----------------------- */
  var DAY_NAMES = {
    "ПН": "Понедельник", "ВТ": "Вторник", "СР": "Среда",
    "ЧТ": "Четверг", "ПТ": "Пятница", "СБ": "Суббота", "ВС": "Воскресенье"
  };
  var TIME_RE = /^\d{1,2}[:.]\d{2}\s*[–—-]\s*\d{1,2}[:.]\d{2}$/;

  /* Возвращает { data, problems, days, workouts } либо null (не план) */
  function parsePlan(text) {
    var data = { subtitle: "", footnote: "", days: [] };
    var problems = [];
    var workoutsTotal = 0;
    var lines = String(text).split(/\r?\n/);
    var current = null;
    var seenDay = false;

    lines.forEach(function (raw, i) {
      var line = raw.trim();
      var n = i + 1;
      if (!line || line.charAt(0) === "#") { return; }

      /* Продолжение описания: строка с отступом (пробел/tab),
         идущая после строки тренировки. Собирается в один абзац. */
      if (/^[\s\t]+/.test(raw)) {
        var lastW = current && current.workouts.length
          ? current.workouts[current.workouts.length - 1] : null;
        if (lastW) {
          lastW.description = (lastW.description ? lastW.description + " " : "") + line;
          return;
        }
        problems.push("строка " + n + ": текст с отступом, но перед ним нет строки тренировки (время | название | место) — строка пропущена");
        return;
      }

      var subtitle = line.match(/^Подпись:(.*)$/i);
      if (subtitle) { data.subtitle = subtitle[1].trim(); return; }
      var footnote = line.match(/^Сноска:(.*)$/i);
      if (footnote) { data.footnote = footnote[1].trim(); return; }

      var day = line.match(/^(ПН|ВТ|СР|ЧТ|ПТ|СБ|ВС)\s*·\s*(.+)$/i);
      if (day) {
        current = { day: DAY_NAMES[day[1].toUpperCase()] + " · " + day[2].trim(), workouts: [] };
        data.days.push(current);
        seenDay = true;
        return;
      }
      var rest = line.match(/^ВЫХ(?:\s*\|\s*(.*))?$/i);
      if (rest) {
        current = { day: "Выходной", workouts: [] };
        if (rest[1] && rest[1].trim() && rest[1].trim().toLowerCase() !== "выходной") {
          current.note = rest[1].trim();
        }
        data.days.push(current);
        seenDay = true;
        return;
      }

      var parts = line.split("|").map(function (p) { return p.trim(); });
      if (parts.length >= 2 && TIME_RE.test(parts[0])) {
        if (!current) {
          problems.push("строка " + n + ": тренировка идёт до строки дня — пропущен «ПН · дата»");
          return;
        }
        current.workouts.push({
          name: parts[1],
          time: parts[0],
          place: parts[2] || "",
          image: "",
          description: parts.slice(3).join(" | ")
        });
        workoutsTotal++;
        return;
      }

      /* Похоже на тренировку, но время не распознано */
      if (parts.length >= 2) {
        problems.push("строка " + n + ": не распознано время «" + parts[0] + "» (нужно, например: 18:00–19:30) — строка пропущена");
      } else {
        problems.push("строка " + n + ": неизвестная строка (пропущена): «" + line.slice(0, 40) + (line.length > 40 ? "…" : "") + "»");
      }
    });

    if (!seenDay) { return null; } /* в файле нет ни одной строки дня */
    return { data: data, problems: problems, days: data.days.length, workouts: workoutsTotal };
  }

  function handlePlanFile(file) {
    var reader = new FileReader();
    reader.onload = function () {
      var parsed = parsePlan(reader.result);
      if (!parsed) {
        alert("Похоже, это не файл плана недели.\n\nОжидается TXT-файл по образцу «план-недели — шаблон.txt»: в нём должны быть строки дня вида «ПН · 5 октября» или «ВЫХ | Выходной».");
        return;
      }
      var msg = "Найдено дней: " + parsed.days + ", тренировок: " + parsed.workouts + ".";
      if (parsed.problems.length) {
        msg += "\n\nПроблемы (эти строки пропущены):\n- " + parsed.problems.join("\n- ");
      }
      msg += "\n\nЗагрузить план в формы? Текущее содержимое редактора будет заменено.";
      if (!confirm(msg)) { return; }
      render(parsed.data);
    };
    reader.onerror = function () {
      alert("Не удалось прочитать файл. Попробуйте ещё раз.");
    };
    reader.readAsText(file, "utf-8");
  }

  /* --- Публикация на сайт (publish.php на хостинге) -------------- */
  var PUBLISH_KEY = "wz-publish-creds";

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
    } catch (e) { /* localStorage может быть недоступен — не критично */ }

    var btn = byId("btn-publish");
    btn.disabled = true;
    publishStatus("Отправляю…");

    var body = new FormData();
    body.append("content", buildFileContent(collect()));
    body.append("password", password);
    fetch(site + "/publish.php", { method: "POST", body: body })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error("сервер ответил не JSON (кода " + res.status + ") — проверьте, что publish.php на месте");
        }).then(function (answer) { return { status: res.status, answer: answer }; });
      })
      .then(function (r) {
        if (r.answer && r.answer.ok) {
          publishStatus("Опубликовано ✅ обновите сайт (F5), чтобы увидеть", "ok");
        } else if (r.status === 403 && r.answer && r.answer.error === "wrong_password") {
          publishStatus("Неверный пароль публикации.", "err");
        } else if (r.status === 500 && r.answer && r.answer.error === "password_not_set") {
          publishStatus("На сервере publish.php не задан пароль (осталась заглушка).", "err");
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
    } catch (e) { /* нет сохранённых данных — поля остаются пустыми */ }
  }

  /* --- Черновик -------------------------------------------------- */
  function saveDraft() {
    try {
      localStorage.setItem(DRAFT_KEY, JSON.stringify(collect()));
    } catch (e) { /* localStorage может быть недоступен — не критично */ }
  }

  function loadDraft() {
    try {
      var raw = localStorage.getItem(DRAFT_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch (e) { return null; }
  }

  /* --- Заполнение страницы данными ------------------------------- */
  function render(data) {
    byId("f-subtitle").value = data.subtitle || "";
    byId("f-footnote").value = data.footnote || "";
    var days = byId("days");
    days.textContent = "";
    (data.days && data.days.length ? data.days : [{}]).forEach(function (d) {
      days.appendChild(dayBlock(d));
    });
  }

  function init() {
    /* Начальные данные: из файла расписания либо пустая неделя */
    if (window.WZ_SCHEDULE) {
      render(window.WZ_SCHEDULE);
    } else {
      render({});
    }

    /* Если есть несохранённый черновик — предложить вернуть его */
    var draft = loadDraft();
    var draftBtn = byId("btn-draft");
    if (draft) {
      draftBtn.hidden = false;
      draftBtn.addEventListener("click", function () {
        if (confirm("Вернуть правки из черновика (то, что не выгружалось в файл)?")) {
          render(draft);
        }
      });
    }

    /* Автосохранение черновика при правках */
    var timer = null;
    document.addEventListener("input", function () {
      clearTimeout(timer);
      timer = setTimeout(saveDraft, 500);
    });

    byId("btn-download").addEventListener("click", downloadSchedule);

    /* Загрузка TXT-плана: клик по кнопке открывает выбор файла */
    byId("btn-load-txt").addEventListener("click", function () {
      byId("file-plan").click();
    });
    byId("file-plan").addEventListener("change", function () {
      if (this.files && this.files[0]) {
        handlePlanFile(this.files[0]);
        this.value = ""; /* повторный выбор того же файла тоже срабатывает */
      }
    });

    /* Публикация на сайт */
    loadPublishCreds();
    byId("btn-publish").addEventListener("click", publish);

    byId("btn-add-day").addEventListener("click", function () {
      byId("days").appendChild(dayBlock({
        day: "",
        workouts: [{ name: "", time: "", place: "", image: "", description: "" }]
      }));
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();