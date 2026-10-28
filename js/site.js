/* ВЖУХ — финальный сайт: расписание из data/schedule.js, меню,
   отзывы (data/reviews.js + reviews.php), демо-форма, reveal,
   модальное окно описания тренировки */
(function () {
  "use strict";
  document.documentElement.classList.add("js");

  /* Запасные описания для статической разметки-заглушки
     (используются только если data/schedule.js не загрузился). */
  var WORKOUTS = {
    tech: {
      name: "ВЖУХ-ТЕХНИКА-БЕГА",
      desc: "Базовая тренировка клуба: работа над основами бегового шага — положение корпуса, стопы, каденс, расслабленные руки. Подходит любому уровню и возрасту."
    },
    baryery: {
      name: "ВЖУХ-БАРЬЕРЫ",
      desc: "Ловкость и координация: круговые барьерные упражнения — частота шагов, работа голеностопа, точность и лёгкость."
    },
    goro4ki: {
      name: "ВЖУХ-ГОРОЧКИ",
      desc: "Отрезки в горку. Развивает силу ног, мощность шага и умение бегать по рельефу."
    },
    kant: {
      name: "ВЖУХ-КАНТ-ХОЛМЫ",
      desc: "Холмовая работа: восхождения и бег по пересечённой местности, техника бега в гору и с горы."
    },
    intervals: {
      name: "ВЖУХ-ИНТЕРВАЛЫ",
      desc: "Отрезки с точным отдыхом на рабочих пульсовых зонах — скорость держится ровно, а не рывками."
    },
    jumps: {
      name: "ВЖУХ-ПРЫЖКИ-СФП",
      desc: "Специальная физическая подготовка: прыжковые упражнения, сила стоп, взрывность и экономичность бега."
    },
    temp: {
      name: "ВЖУХ-ТЕМПОВАЯ",
      desc: "Длинные отрезки на комфортно-жёстком темпе (зоны 3–4) — подготовка к соревновательной скорости."
    },
    long: {
      name: "ВЖУХ-ДЛИТЕЛЬНАЯ на Воробьёвых",
      desc: "Длинная спокойная пробежка по Воробьёвым горам (зоны 1–2) — фундамент выносливости."
    }
  };

  /* --- Построение недели из data/schedule.js ----------------- */
  function renderWeek(schedule) {
    var grid = document.getElementById("week-grid");
    if (!grid || !schedule || !Array.isArray(schedule.days) || !schedule.days.length) {
      return false; /* данных нет — остаётся статичная разметка */
    }
    var subtitle = document.getElementById("schedule-subtitle");
    if (subtitle && schedule.subtitle) { subtitle.textContent = schedule.subtitle; }
    var footnote = document.getElementById("schedule-footnote");
    if (footnote && schedule.footnote) { footnote.textContent = schedule.footnote; }

    grid.textContent = ""; /* убираем статичную заглушку */

    schedule.days.forEach(function (day, dayIndex) {
      var card = document.createElement("article");
      card.className = "day-card";

      var dateP = document.createElement("p");
      dateP.className = "day-date";
      dateP.textContent = day.day || "";
      card.appendChild(dateP);

      if (!day.workouts || !day.workouts.length) {
        var rest = document.createElement("p");
        rest.className = "day-rest";
        rest.textContent = day.note || "Выходной";
        card.appendChild(rest);
      } else {
        day.workouts.forEach(function (w, wi) {
          var btn = document.createElement("button");
          btn.type = "button";
          btn.className = "workout";
          btn.dataset.key = dayIndex + ":" + wi;

          if (w.image) {
            btn.classList.add("has-thumb");
            var thumb = document.createElement("img");
            thumb.className = "workout-thumb";
            thumb.src = "images/" + w.image;
            thumb.alt = "";
            thumb.loading = "lazy";
            /* файла нет — просто прячем миниатюру */
            thumb.addEventListener("error", function () {
              thumb.remove();
              btn.classList.remove("has-thumb");
            });
            btn.appendChild(thumb);
          }

          var text = document.createElement("span");
          text.className = "workout-text";
          var name = document.createElement("strong");
          name.textContent = w.name || "Тренировка";
          var meta = document.createElement("small");
          meta.textContent = [w.time, w.place].filter(Boolean).join(" · ");

          text.appendChild(name);
          if (meta.textContent) { text.appendChild(meta); }
          btn.appendChild(text);
          card.appendChild(btn);
        });
      }
      grid.appendChild(card);
    });
    return true;
  }

  /* --- Отзывы: рендер и форма ---------------------------------- */

  /* Карточка отзыва. В отзывах от анонимов может быть любой HTML —
     поэтому только textContent, innerHTML из данных запрещён. */
  function appendReviewCard(strip, review) {
    var card = document.createElement("blockquote");
    card.className = "review";

    var text = document.createElement("p");
    text.textContent = "«" + (review.text || "") + "»";
    card.appendChild(text);

    var footer = document.createElement("footer");
    footer.textContent = (review.name || "Аноним") + " · " + (review.date || "");
    card.appendChild(footer);

    strip.appendChild(card);
    return card;
  }

  function renderReviews() {
    var strip = document.getElementById("reviews-strip");
    var empty = document.getElementById("reviews-empty");
    if (!strip) { return; }

    var list = null;
    try {
      var data = window.WZ_REVIEWS; /* из data/reviews.js */
      if (data && Array.isArray(data.reviews)) { list = data.reviews; }
    } catch (e) { /* битый файл — считаем, что отзывов нет */ }

    if (list && list.length) {
      if (empty) { empty.hidden = true; }
      list.forEach(function (review) { appendReviewCard(strip, review); });
    } else if (empty) {
      empty.hidden = false;
    }
  }

  function initReviewForm() {
    var form = document.getElementById("review-form");
    if (!form) { return; }
    var btn = document.getElementById("r-submit");
    var note = document.getElementById("r-note");
    var strip = document.getElementById("reviews-strip");

    /* Локально (file://) отправлять некуда — оставляем только просмотр */
    if (location.protocol === "file:") {
      btn.hidden = true;
      note.textContent = "Отправка отзывов работает на сайте; локально форма отключена.";
      return;
    }

    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var name = form.querySelector("#r-name").value.trim();
      var text = form.querySelector("#r-text").value.trim();

      note.textContent = "";
      if (!name || name.length > 40) {
        note.textContent = "Укажите имя (до 40 символов).";
        return;
      }
      if (text.length < 10 || text.length > 800) {
        note.textContent = "Отзыв — от 10 до 800 символов.";
        return;
      }

      /* honeypot не заполняется людьми — боты на это провоцируются.
         URLSearchParams (как у обычной формы) — PHP читает $_POST
         так же, а локальный эмулятор проще парсит. */
      var body = new URLSearchParams(new FormData(form));

      btn.disabled = true;
      note.textContent = "Отправляю…";
      fetch("reviews.php", { method: "POST", body: body })
        .then(function (res) {
          return res.json().catch(function () {
            /* не JSON — значит, отвечал не PHP: либо файл не загружен
               на хостинг, либо открыт локально без PHP-сервера */
            throw new Error("по этому адресу отвечает не обработчик (код " + res.status + "): на хостинге должен лежать файл reviews.php");
          }).then(function (answer) { return { status: res.status, answer: answer }; });
        })
        .then(function (r) {
          if (r.answer && r.answer.ok) {
            if (r.answer.review && strip) {
              var card = appendReviewCard(strip, r.answer.review);
              /* список не пуст — прячем сообщение «Пока нет отзывов» */
              var empty = document.getElementById("reviews-empty");
              if (empty) { empty.hidden = true; }
              card.scrollIntoView({ behavior: "smooth", inline: "end", block: "nearest" });
            }
            form.reset();
            note.textContent = "Спасибо! Отзыв опубликован.";
          } else if (r.answer && r.answer.error === "rate") {
            note.textContent = "Слишком много отзывов с одного адреса — попробуйте позже.";
          } else if (r.answer && r.answer.error === "format") {
            note.textContent = "Имя или отзыв не прошли проверку — проверьте длину.";
          } else {
            note.textContent = "Не получилось отправить отзыв. Попробуйте ещё раз.";
          }
        })
        .catch(function (err) {
          /* Сервер недоступен/не PHP. Отзыв не потерян: показываем
             его локально, в этом окне, честно объясняя статус. */
          var card = appendReviewCard(strip, {
            name: name,
            text: text,
            date: "сохранено локально"
          });
          var empty = document.getElementById("reviews-empty");
          if (empty) { empty.hidden = true; }
          card.scrollIntoView({ behavior: "smooth", inline: "end", block: "nearest" });
          note.textContent = "На сервер записать не удалось" +
            (err && err.message ? " — " + err.message : "") +
            ". Отзыв показан только вам; на сайте он появится после проверки обработчика.";
        })
        .finally(function () { btn.disabled = false; });
    });
  }

  /* --- Модальное окно ---------------------------------------- */
  function fillDialog(dialog, title, desc, image) {
    document.getElementById("wd-title").textContent = title;
    document.getElementById("wd-desc").textContent = desc;

    /* Фото тренировки в окне (если задано и файл найден) */
    var media = document.getElementById("wd-media");
    var img = document.getElementById("wd-img");
    if (media && img) {
      if (image) {
        img.alt = "Фото тренировки: " + title;
        img.onload = function () { media.hidden = false; };
        img.onerror = function () { media.hidden = true; };
        img.src = "images/" + image;
      } else {
        media.hidden = true;
        img.removeAttribute("src");
      }
    }
    dialog.showModal();
  }

  function init() {
    /* Мобильное меню */
    var toggle = document.querySelector(".nav-toggle");
    var nav = document.getElementById("site-nav");
    if (toggle && nav) {
      toggle.addEventListener("click", function () {
        var open = nav.classList.toggle("open");
        toggle.setAttribute("aria-expanded", String(open));
      });
      nav.querySelectorAll("a").forEach(function (a) {
        a.addEventListener("click", function () {
          nav.classList.remove("open");
          toggle.setAttribute("aria-expanded", "false");
        });
      });
      /* Тап мимо меню и Esc тоже закрывают его */
      function closeMenu() {
        nav.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
      }
      document.addEventListener("click", function (e) {
        if (nav.classList.contains("open") &&
            !nav.contains(e.target) && !toggle.contains(e.target)) {
          closeMenu();
        }
      });
      document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { closeMenu(); }
      });
    }

    /* Расписание из данных (если файл присутствует и корректен) */
    var hasData = false;
    if (window.WZ_SCHEDULE) {
      try { hasData = renderWeek(window.WZ_SCHEDULE); } catch (e) { hasData = false; }
    }

    /* Модальное окно: единый обработчик кликов по .workout */
    var dialog = document.getElementById("workout-dialog");
    if (dialog && typeof dialog.showModal === "function") {
      document.addEventListener("click", function (e) {
        var btn = e.target.closest(".workout");
        if (!btn) { return; }
        if (hasData && btn.dataset.key && window.WZ_SCHEDULE) {
          var pos = btn.dataset.key.split(":");
          var day = window.WZ_SCHEDULE.days[Number(pos[0])];
          var w = day && day.workouts[Number(pos[1])];
          if (w) { fillDialog(dialog, w.name, w.description || "", w.image || ""); }
        } else {
          var w2 = WORKOUTS[btn.dataset.workout];
          if (w2) { fillDialog(dialog, w2.name, w2.desc, ""); }
        }
      });
      dialog.querySelector(".dialog-close").addEventListener("click", function () {
        dialog.close();
      });
      /* Клик по затемнению закрывает окно */
      dialog.addEventListener("click", function (e) {
        var rect = dialog.getBoundingClientRect();
        var outside = e.clientX < rect.left || e.clientX > rect.right ||
                      e.clientY < rect.top || e.clientY > rect.bottom;
        if (outside) { dialog.close(); }
      });
    }

    /* Демо-форма: ничего не отправляет */
    document.querySelectorAll("form.demo-form").forEach(function (form) {
      form.addEventListener("submit", function (e) {
        e.preventDefault();
        var note = form.querySelector(".form-note");
        if (note) {
          note.textContent = "Спасибо! Это демо-форма — данные никуда не отправляются.";
          note.hidden = false;
        }
        form.reset();
      });
    });

    /* Отзывы: рендер из data/reviews.js + форма отправки */
    renderReviews();
    initReviewForm();

    /* Плавное появление секций */
    var reveals = document.querySelectorAll(".reveal");
    if (reveals.length && "IntersectionObserver" in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("in");
            io.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12 });
      reveals.forEach(function (el) { io.observe(el); });
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();