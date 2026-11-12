<?php
/* ============================================================
   ВЖУХ — генератор плана подготовки (ai-plan/plan.php)

   Форма на ai-plan/index.html отправляет POST JSON — скрипт
   1. проверяет honeypot/лимит/поля (как reviews.php),
   2. обменивает ключ GigaChat на токен (кеш, срок 30 мин),
   3. строит промпт по методике клуба (data/knowledge.js),
   4. вызывает модель (ожидаем строгий JSON),
   5. собирает настоящий .xlsx (ZipArchive, 3 листа),
   6. кладёт файл в out/ с случайным именем (чистка > 24 ч),
   7. возвращает {ok, url} — случайная (не угадываемая) ссылка.

   НАСТРОЙКА КЛЮЧА: впишите ключ в GIGA_KEY ниже (строка const GIGA_KEY).
   Формат ключа (base64 или нет) определяется автоматически.
   Если OAuth отвечает 401 — проверьте, что ключ скопирован целиком: в GigaChat
   console пересоздайте ключ и вставьте сюда его строку без пробелов/переносов.

   Проверка: открыть https://ваш-сайт/ai-plan/plan.php —
   ответ должен быть {"ok":false,"error":"method"}.
   ============================================================ */

header('Content-Type: application/json; charset=utf-8');

/* --- Настройки ------------------------------------------------- */
/* Секреты (ключ GigaChat, пароль Pro) — в соседнем файле secrets.php
   на уровень выше. В репозиторий он не попадает (.gitignore); если файла
   нет — код работает с пустыми заглушками (генерация ответит ошибкой).
   На хостинге secrets.php исполняется PHP и по HTTP не отдаётся. */
if (is_file(__DIR__ . '/../secrets.php')) { require __DIR__ . '/../secrets.php'; }
if (!defined('GIGA_KEY')) { define('GIGA_KEY', ''); }
/* Формат ключа определяется автоматически (ключ из консоли GigaChat
   приходит в base64). Значение меняет режим:
     'auto' — определять сам (рекомендуется);
     true   — ключ уже base64, отправлять как есть;
     false  — кодировать в base64 при отправке. */
if (!defined('GIGA_KEY_ALREADY_BASE64')) { define('GIGA_KEY_ALREADY_BASE64', 'auto'); }
const GIGA_SCOPE = 'GIGACHAT_API_PERS';
const GIGA_MODEL = 'GigaChat-2';    /* скелет и куски — одна лёгкая модель.
   PRO пробовали на скелете: методичнее, но пишет скелет ~5 минут — браузер
   обрывает соединение. Идентификаторы: GigaChat-2, GigaChat-2-Pro,
   GigaChat-2-Max; старый алиас «GigaChat» на мигрировавшем хосте отвечает
   404 No such model. Список доступных ключу моделей — через /v1/models. */
const GIGA_MODEL_DAYS = 'GigaChat-2';  /* фаза 2 — та же модель (см. выше) */
const GIGA_MODEL_PRO = 'GigaChat-2-Pro'; /* старшая — по выбору в форме */
if (!defined('PRO_PASS')) { define('PRO_PASS', ''); }   /* пароль к «Pro» в форме — в secrets.php */
const GIGA_OAUTH_URL = 'https://ngw.devices.sberbank.ru:9443/api/v2/oauth';
/* С 17.07.2026 целевой хост GigaChat — api.giga.chat (пути без /api).
   Старый gigachat.devices.sberbank.ru объявлен устаревшим и отвечает
   нестабильно — наблюдали чередование 200 и 404 «No such model». */
const GIGA_API_URL = 'https://api.giga.chat/v1/chat/completions';
const GIGA_MODELS_URL = 'https://api.giga.chat/v1/models';
/* Эндпоинты GigaChat подписаны корнями доверия РФ, не входящими в
   публичные CA. Проверку TLS отключаем ТОЛЬКО тут (сервер-сервер, ключ
   всё равно не утекает через TLS). Если на хостинге установят
   russian_trusted_root_ca — смените на true. */
const GIGA_INSECURE_TLS = true;

/* Лимит частоты: 10 в час — ОТЛАДОЧНЫЙ режим (поднят на время отладки
   генератора). Штатное значение для прода — 3, оно же указано в README
   и в сводке ограничений: перед релизом вернуть 3. */
const RATE_LIMIT = 10;
/* алгоритм без ИИ: отдельная квота. Генерация дешёвая, но каждый вызов
   создаёт xlsx в out/ — 20 в час с одного адреса хватает с запасом */
const ALGO_RATE_LIMIT = 20;
const RATE_WINDOW = 3600;   /* за час */

define('DIR_DATA', __DIR__ . '/data');
define('DIR_OUT', __DIR__ . '/out');
define('TOKEN_FILE', DIR_DATA . '/token-cache.json');

define('MAX_TEXT', 300);

/* --- Служебное ------------------------------------------------- */
/* Финал каждого запроса фиксируем в журнале: тогда видно, дошло ли
   дело до ответа и какой был URL (иначе отвалившийся браузер оставляет
   тишину в логе и непонятно, где именно оборвало). */
function respond($ok, $error = '', $extra = array()) {
  ai_log('OUT', ($ok ? 'ok ' : 'error ' . $error)
    . (isset($extra['url']) ? $extra['url'] : '')
    . (connection_aborted() ? ' (браузер уже отключился, файл на сервере)' : ''));
  echo json_encode(array_merge(array('ok' => $ok, 'error' => $error), $extra),
    JSON_UNESCAPED_UNICODE);
  exit;
}

/* Уборка runtime: готовые планы старше 24 ч и статусы задач старше часа.
   Вызывается в начале каждой генерации (а не только в конце успешной) —
   падение или брошенная задача больше не оставляет мусор навсегда. */
function plan_cleanup() {
  foreach (glob(DIR_OUT . '/plan-*.xlsx') as $f) {
    if (is_file($f) && (time() - filemtime($f)) > 86400) { @unlink($f); }
  }
  foreach (glob(DIR_DATA . '/plan-status-*.json') as $f) {
    if (is_file($f) && (time() - filemtime($f)) > 3600) { @unlink($f); }
  }
}

/* итог генерации (уже после мгновенного ack): страница вычисляет результат,
   опрашивая файл статуса. Тело ответа браузеру больше не отправляется. */
function status_finish($jobId, $ok, $error = '', $extra = array()) {
  $GLOBALS['plan_done'] = true;
  ai_log('OUT', ($ok ? 'ok ' : 'error ' . $error)
    . (isset($extra['url']) ? $extra['url'] : ''));
  @file_put_contents(DIR_DATA . '/plan-status-' . $jobId . '.json',
    json_encode(array_merge(array('ok' => $ok, 'error' => $error), $extra),
      JSON_UNESCAPED_UNICODE), LOCK_EX);
  exit;
}

date_default_timezone_set('Europe/Moscow');

/* браузер далёк не всегда дожидается: пусть скрипт доделает файл, даже
   если связь уже оборвалась (xlsx всё равно появится в out/) */
ignore_user_abort(true);
/* 600 с не хватало на длинный план: 8 кусков по 240 с + повторы дают
   до ~3800 с, и PHP убивал задачу уже после ack */
set_time_limit(900);

/* Страховка от fatal (память, max_execution_time, ошибка в xlsx):
   без неё задача навсегда остаётся в статусе «wait», и страница
   опрашивает её ~20 минут. Обработчик ставим до тяжёлых фаз. */
$GLOBALS['plan_job'] = '';
$GLOBALS['plan_done'] = false;
register_shutdown_function(function () {
  if (!empty($GLOBALS['plan_done'])) { return; }
  $e = error_get_last();
  if (!is_array($e) || !in_array($e['type'],
      array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR), true)) {
    return;
  }
  $job = (string)$GLOBALS['plan_job'];
  if (!preg_match('/^[a-z0-9]{16}$/', $job)) { return; }   /* не наш запрос */
  @file_put_contents(DIR_DATA . '/plan-status-' . $job . '.json',
    json_encode(array('ok' => false, 'error' => 'fatal'), JSON_UNESCAPED_UNICODE),
    LOCK_EX);
  @file_put_contents(DIR_DATA . '/model-log.txt',
    "==== " . date('Y-m-d H:i:s') . " FATAL ====" . PHP_EOL
    . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line'] . PHP_EOL,
    FILE_APPEND | LOCK_EX);
});

/* --- GET: опрос статуса готовящегося плана ----------------------
   POST отдаёт моментальный ack с идентификатором задачи и продолжает
   генерацию уже после ответа (fastcgi_finish_request, Beget = PHP-FPM).
   Страница опрашивает сюда каждые пару секунд: короткие запросы
   нечувствительны к нестабильной сети, в отличие от одного долгого. */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $job = (string)($_GET['job'] ?? '');
  if (!preg_match('/^[a-z0-9]{16}$/', $job)) { respond(false, 'format'); }
  $sf = DIR_DATA . '/plan-status-' . $job . '.json';
  $st = @json_decode(@file_get_contents($sf), true);
  if (!is_array($st)) {
    /* файла нет: задача устарела или её убрала чистка */
    echo json_encode(array('ok' => false, 'error' => 'gone'));
    exit;
  }
  echo json_encode($st, JSON_UNESCAPED_UNICODE);
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  respond(false, 'method');
}

/* --- Вход: JSON из тела запроса -------------------------------- */
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) { respond(false, 'format'); }

/* Honeypot: человек поле не видит */
if (isset($in['website']) && trim((string)$in['website']) !== '') {
  respond(false, 'spam');
}

/* --- Выбор режима: Lite по умолчанию, Pro — по паролю, «Алгоритм» — без ИИ --- */
$modelOpt = (isset($in['model']) && $in['model'] === 'pro') ? 'pro'
  : ((isset($in['model']) && $in['model'] === 'algo') ? 'algo' : 'lite');
/* algo — детерминированный генератор (Riegel + периодизация): без GigaChat,
   без rate-limit, моментальный. ИИ-фазы ниже оборачиваются в if (!$algo) */
$algo = ($modelOpt === 'algo');
$gigaModel = GIGA_MODEL;                       /* лёгкая — вся база */
if ($modelOpt === 'pro') {
  if (!hash_equals(PRO_PASS, (string)($in['pro_pass'] ?? ''))) {
    respond(false, 'pro_pass');
  }
  $gigaModel = GIGA_MODEL_PRO;
}

/* --- Санитизация полей ----------------------------------------- */
function clean($s, $maxLen) {
  $s = str_replace(array("\r\n", "\r"), "\n", (string)$s);
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
  $s = trim($s);
  return function_exists('mb_substr') ? mb_substr($s, 0, $maxLen) : substr($s, 0, $maxLen * 4);
}
function int_or($v, $def, $min, $max) {
  $n = (int)$v;
  return ($n >= $min && $n <= $max) ? $n : $def;
}
/* mb_substr может отсутствовать — режем байтово */
function mcut($s, $n) {
  return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n * 2);
}

$DISTS = array(5.0, 10.0, 15.0, 21.1, 42.2);
$dist  = 0.0;
if (isset($in['distance']) && $in['distance'] === 'other') {
  $cust = str_replace(',', '.', clean($in['custom_distance'] ?? '', 16));
  if (is_numeric($cust) && (float)$cust >= 1 && (float)$cust <= 300) {
    $dist = (float)$cust;
  }
} elseif (isset($in['distance']) && is_numeric($in['distance'])) {
  $f = (float)$in['distance'];
  if (in_array($f, $DISTS)) { $dist = $f; }
}
if ($dist <= 0) { respond(false, 'format'); }

$expMap = array(
  'none' => 'никогда не бегал(а) системно',
  'lt1'  => 'бег опыт до года',
  '1-3'  => 'бег опыт 1-3 года',
  'gt3'  => 'бег опыт более 3 лет',
);
$experience = $in['experience'] ?? '';
if (!isset($expMap[$experience])) { respond(false, 'format'); }

$km  = int_or($in['km_per_week'] ?? -1, -1, 0, 300);
if ($km < 0) { respond(false, 'format'); }
/* км/нед из анкеты отдельно: переменная $km ниже переиспользуется циклом
   сборки дней (у строки дня своё км) и к моменту Сводки уже затёрта */
$kmAnketa = $km;

$raceDate = '';
if (isset($in['race_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['race_date'])) {
  $ts = strtotime((string)$in['race_date']);
  if ($ts && $ts > time() - 86400) { $raceDate = (string)$in['race_date']; }
}
/* дата начала тренировок (опционально): план строится от неё вперёд */
$startDate = '';
if (isset($in['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$in['start_date'])) {
  $ts = strtotime((string)$in['start_date']);
  if ($ts && $ts > time() - 86400) { $startDate = (string)$in['start_date']; }
}
if ($startDate !== '' && $raceDate !== '' && $startDate > $raceDate) {
  http_response_code(400);
  respond(false, 'bad_dates');
}
$weeks = int_or($in['weeks'] ?? 0, 0, 0, 36);
if ($raceDate === '' && ($weeks < 3 || $weeks > 36)) { respond(false, 'format'); }

/* пол и возраст обязательны: по ним сервер считает ЧССмакс и границы зон */
$gender = ($in['gender'] ?? '') === 'м' ? 'муж'
        : (($in['gender'] ?? '') === 'ж' ? 'жен' : '');
$age = int_or($in['age'] ?? -1, -1, 8, 99);
if ($gender === '' || $age < 0) {
  http_response_code(400);
  respond(false, 'bad_fields');
}

/* планируемый результат старта «ЧЧ:ММ» или «ЧЧ:ММ:СС» (опционально) */
$targetTime = '';
if (isset($in['target_time']) &&
    preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', (string)$in['target_time'], $m)) {
  $mins = (int)$m[1] * 60 + (int)$m[2] + (!empty($m[3]) ? (int)$m[3] / 60 : 0);
  if ($mins >= 15 && $mins <= 600) { $targetTime = (string)$in['target_time']; }
}

/* целевой темп старта, мин/км (диапазон ±5 с) — считаем на сервере из
   результата и дистанции: он же опора для ЦМТ-тренировок у модели */
$targetPace = '';
$targetPaceSec = 0;
if ($targetTime !== '' && $dist > 0 &&
    preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', $targetTime, $m2)) {
  /* ЧЧ:ММ[:СС] → секунды; секунды на км из результата и дистанции */
  $tot = ((int)$m2[1] * 60 + (int)$m2[2]) * 60 + (!empty($m2[3]) ? (int)$m2[3] : 0);
  $spk = (int)floor($tot / $dist);
  if ($spk >= 120 && $spk <= 900) {
    $fmtP = function ($s) { return floor($s / 60) . ':' . sprintf('%02d', $s % 60); };
    $targetPaceSec = $spk;
    $targetPace = $fmtP(max(1, $spk - 5)) . '–' . $fmtP($spk + 5) . ' на км';
  }
}

/* пороги (опционально): пульс в уд/мин и темп «ЧЧ:ММ»/«М:СС» на км.
   Пульс порога ниже 100 не бывает физически — считаем «не указано»;
   темп принимаем в разумных пределах 2:00–15:00 на км */
$paceRe = '/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/';

/* «52:00» → 3120 с, «1:52:30» → 6750 с, мусор → 0.
   Нужна отдельная функция: $paceRe описывает темп (ММ:СС) и раньше
   использовалась ещё и для времени старта, где читались группы 2 и 3 —
   их в той регулярке не было. «1:52:30» превращалось в 60 с (за 10 км),
   и зоны Ригеля выходили вида «1:07-1:37/км». */
function time_to_sec($s) {
  $s = trim((string)$s);
  if (preg_match('/^(\d{1,2}):([0-5]\d)$/', $s, $m)) {          /* ММ:СС */
    return (int)$m[1] * 60 + (int)$m[2];
  }
  if (preg_match('/^(\d{1,2}):([0-5]\d):([0-5]\d)$/', $s, $m)) { /* ЧЧ:ММ:СС */
    return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
  }
  return 0;
}


/* недавний результат на старте (дистанция + время): для режима «Алгоритм»
   обязателен — единственный корень расчёта темповых зон (формула Ригеля);
   для ИИ-режимов — необязательный контекст в данных бегуна */
$rrKm = 0.0;
$rrTimeS = 0.0;
$rrBad = '';
if (isset($in['recent_race']) && is_array($in['recent_race'])) {
  $rrKmRaw = str_replace(',', '.', (string)($in['recent_race']['km'] ?? ''));
  if (is_numeric($rrKmRaw) && (float)$rrKmRaw >= 1 && (float)$rrKmRaw <= 300) {
    $rrKm = (float)$rrKmRaw;
  }
  $rrTimeRaw = str_replace(' ', '', clean($in['recent_race']['time'] ?? '', 12));
  $rrTimeS = (float)time_to_sec($rrTimeRaw);
  /* правдоподобие: темп 2:00–15:00/км — те же границы, что у порогов.
     «10 км за 0:10» давало зоны «0:05/км», «300 км» — «0:00/км»; такой
     результат считаем не указанным и честно пишем об этом в замечаниях. */
  if ($rrKm > 0 && $rrTimeS > 0) {
    $rrPace = $rrTimeS / $rrKm;
    if ($rrPace < 120 || $rrPace > 900) {
      $rrBad = 'недавний результат вне разумных границ ('
        . rtrim(rtrim(number_format($rrKm, 1, ',', ''), '0'), ',') . ' км за '
        . $rrTimeRaw . ') — проигнорирован';
      $rrKm = 0.0;
      $rrTimeS = 0.0;
    }
  } elseif ($rrTimeRaw !== '' && $rrTimeS <= 0) {
    $rrBad = 'время недавнего старта не разобрано («' . $rrTimeRaw
      . '») — проигнорировано, нужен формат ММ:СС или ЧЧ:ММ:СС';
    $rrKm = 0.0;
  }
}
/* план новичка допустим и без недавнего результата (шаг 1 алгоритма):
   темпы — словесно, в неделю 1 вписывается контрольный тест */
$algoNoAnchor = ($algo && ($rrKm <= 0 || $rrTimeS <= 0));

$thrAeHr = int_or($in['thr_ae_hr'] ?? 0, 0, 0, 230);
$thrPanoHr = int_or($in['thr_pano_hr'] ?? 0, 0, 0, 230);
$thrAeHr = $thrAeHr >= 100 ? $thrAeHr : 0;
$thrPanoHr = $thrPanoHr >= 100 ? $thrPanoHr : 0;
$paceOk = function ($s) use ($paceRe) {
  if (!preg_match($paceRe, (string)$s, $m)) { return ''; }
  $sec = time_to_sec((string)$s);
  return ($sec >= 120 && $sec <= 900) ? (string)$s : '';
};
$thrAePace = $paceOk($in['thr_ae_pace'] ?? '');
$thrPanoPace = $paceOk($in['thr_pano_pace'] ?? '');
/* мерзость-защита: пульс порога не может быть выше ЧССмакс — вычисленной */
$hrMax = (int)round(208 - 0.7 * $age);   /* формула Танаки */
if ($thrAeHr > $hrMax) { $thrAeHr = 0; }
if ($thrPanoHr > $hrMax || ($thrAeHr && $thrPanoHr < $thrAeHr)) { $thrPanoHr = 0; }

/* границы пульсовых зон (4 зоны методики клуба). Измеренные пороги
   уточняют границы: аэробный порог — верх зоны 2, ПАНО — верх зоны 3. */
$z1z2 = (int)round($hrMax * 0.70);
$z2z3 = (int)round($hrMax * 0.80);
$z3z4 = (int)round($hrMax * 0.90);
if ($thrAeHr)   { $z2z3 = $thrAeHr; }
if ($thrPanoHr) { $z3z4 = $thrPanoHr; }
/* порядок зон должен сохраняться, даже если кто-то ошибся в порогах */
if ($z2z3 <= $z1z2) { $z2z3 = $z1z2 + 5; }
if ($z3z4 <= $z2z3) { $z3z4 = $z2z3 + 5; }

/* --- Таблица темпов: раскладка методики клуба, ВСЁ от якоря ЦМТ.
   Раскладка тренера от целевого темпа: Д +25 с, ВОС +55 с,
   П −35 с (уровень ПАНО), И −60 с. Модель цифр не придумывает. */
$toSec = function ($t) {
  if (preg_match('/^(\d{1,2}):([0-5]\d)/', (string)$t, $m)) {
    return (int)$m[1] * 60 + (int)$m[2];
  }
  return 0;
};
$fmtP = function ($s) { return floor($s / 60) . ':' . sprintf('%02d', $s % 60); };
$rangeP = function ($mid) use ($fmtP) {
  return $fmtP(max(1, $mid - 10)) . '-' . $fmtP($mid + 10);
};
$secPanoPace = $toSec($thrPanoPace);
$secAePace = $toSec($thrAePace);
$secCMT = $targetPaceSec;
if (!$secCMT && $secPanoPace) { $secCMT = $secPanoPace + 35; }   /* П ≈ ПАНО */
elseif (!$secCMT && $secAePace) { $secCMT = min(900, $secAePace - 10); }
$pacePlanTxt = '';
if ($secCMT) {
  $pacePlanTxt = 'ВОС ' . $rangeP($secCMT + 55)
    . '; Д ' . $rangeP($secCMT + 25)
    . '; ЦМТ ' . ($targetPace !== ''
        ? str_replace(' на км', '', $targetPace) : $rangeP($secCMT)) . '/км'
    . '; П ' . ($secPanoPace ? $rangeP($secPanoPace) : $rangeP($secCMT - 35)) . '/км'
    . '; И ' . $rangeP($secCMT - 60) . '/км'
    . '; Горка: темп не задавать (по усилию, пульс до верхней границы зоны 3)'
    . '; Фартлек: быстрые отрезки на темпах П..И, медленные на ВОС..Д';
} else {
  $pacePlanTxt = 'ЦМТ/П/И без цифр (опоры нет): словесно — «чуть быстрее Д», '
    . '«уровень порога», «выше порога». ВОС и Д — «очень легко / разговорный». '
    . 'Цифры темпов не выдумывать.';
}

/* дни тренировок: анкета может задать конкретные дни недели (ПН..ВС),
   тогда число тренировок в неделю = выбранным дням; иначе сервер
   распределяет сам по days_per_week — модель дни НЕ выбирает */
$wdNames = array(1 => 'ПН', 2 => 'ВТ', 3 => 'СР', 4 => 'ЧТ',
                 5 => 'ПТ', 6 => 'СБ', 7 => 'ВС');
$prefDays = array();
if (isset($in['pref_days']) && is_string($in['pref_days'])) {
  foreach (explode(',', $in['pref_days']) as $pd) {
    $pd = (int)trim($pd);
    if ($pd >= 1 && $pd <= 7) { $prefDays[$pd] = true; }
  }
  $prefDays = array_keys($prefDays);        /* уникальные */
  sort($prefDays);                          /* порядок 1..7 */
}
$data = array(
  'experience'   => $expMap[$experience],
  'km_per_week'  => $km,
  'longest_run'  => int_or($in['longest_run'] ?? 0, 0, 0, 300),
  'days_per_week'=> int_or($in['days_per_week'] ?? 3, 3, 1, 7),
  'pref_days'    => implode(',', $prefDays),
  'place'        => array_key_exists('place', $in) ? clean($in['place'], 40) : '',
  'gender'       => $gender,
  'age'          => $age,
  'injuries'     => isset($in['injuries']) ? clean($in['injuries'], MAX_TEXT) : '',
  'hr_watch'     => (($in['hr_watch'] ?? '') === 'no') ? 'нет' : 'есть',
  /* особые периоды — структурированные поля анкеты вместо разбора текста:
     breaks = перерывы (start/end/ease), races = промежуточные старты (date/km);
     их валидация и разметка недель — ниже, отдельным детерминированным блоком */
  'breaks'       => (isset($in['breaks']) && is_array($in['breaks'])) ? $in['breaks'] : array(),
  'races'        => (isset($in['races']) && is_array($in['races'])) ? $in['races'] : array(),
  'recent_race'  => array(
    'km'      => $rrKm,
    'time_s'  => $rrTimeS,
    'txt'     => ($rrKm > 0 && $rrTimeS > 0)
      ? (($rrKm === (int)$rrKm ? (string)(int)$rrKm : (string)round($rrKm, 1)) . ' км за '
         . ($rrTimeS >= 3600
            ? ((int)floor($rrTimeS / 3600) . ':' . sprintf('%02d:%02d',
                (int)floor($rrTimeS % 3600 / 60), $rrTimeS % 60))
            : $fmtP($rrTimeS)))
      : ''),
);

/* контроль числа тренировок: если дни заданы — их количество и есть
   число тренировок; иначе дефолтная раскладка (длинный — в воскресенье) */
$daysWanted = count($prefDays) > 0
  ? count($prefDays) : (int)$data['days_per_week'];
if (count($prefDays) > 0) {
  $data['days_per_week'] = count($prefDays);
}
$defSets = array(
  1 => array(7),
  2 => array(4, 7),
  3 => array(2, 4, 7),
  4 => array(2, 4, 6, 7),
  5 => array(1, 2, 4, 6, 7),
  6 => array(1, 2, 3, 4, 6, 7),
  7 => array(1, 2, 3, 4, 5, 6, 7),
);
/* день старта — тренировочный ТОЛЬКО в неделе старта (раньше добавлялся
   глобально: бегун снял галочку с ВС, день гонки пришёлся на ВС — план
   получил пятый тренировочный день во ВСЕХ неделях). Приоритет: дни из
   галочек сильнее числа «сколько тренировок в неделю» — они и число
   тренировок, и их раскладка; не отмечено — раскладка по числу */
$trainDays = count($prefDays) > 0
  ? $prefDays
  : $defSets[max(1, min(7, $data['days_per_week']))];
sort($trainDays);
/* раскладка алгоритма рассчитана на 3-6 тренировок в неделю: 1-2 дня —
   добираем из дефолтной тройки, 7 — оставляем первые 6 (для ИИ-режимов
   число дней не трогаем) */
$algoNotes = array();
/* результат старта не принят — сказать об этом в «Замечаниях алгоритма»,
   а не строить зоны из мусора молча */
if ($algo && $rrBad !== '') { $algoNotes[] = $rrBad; }
if ($algo) {
  if (count($trainDays) < 3) {
    foreach ($defSets[3] as $dA) {
      if (count($trainDays) >= 3) { break; }
      if (!in_array($dA, $trainDays, true)) { $trainDays[] = $dA; }
    }
    sort($trainDays);
    $algoNotes[] = 'раскладка алгоритма рассчитана минимум на 3 тренировки '
      . 'в неделю — добавлены дни ' . implode(', ', array_map(
        function ($d) use ($wdNames) { return $wdNames[$d]; }, $trainDays));
  } elseif (count($trainDays) > 6) {
    $trainDays = array_slice($trainDays, 0, 6);
    $algoNotes[] = 'раскладка алгоритма рассчитана максимум на 6 тренировок '
      . 'в неделю — 7-й день отброшен';
  }
  /* в Сводке показываем фактическое число тренировок, а не то, что в анкете:
     иначе «2 по дням» при трёх днях в плане */
  $data['days_per_week'] = count($trainDays);
}
$raceIso = 0;
if ($raceDate !== '') {
  $raceIso0 = strtotime($raceDate);
  if ($raceIso0 !== false) { $raceIso = (int)date('N', $raceIso0); }
}
$raceDaySuffix = ($raceIso > 0 && !in_array($raceIso, $trainDays, true))
  ? ' (день старта — ' . $wdNames[$raceIso] . ', только в неделе старта)'
  : '';
$trainDaysTxt = implode(', ', array_map(function ($d) use ($wdNames) {
  return $wdNames[$d]; }, $trainDays)) . $raceDaySuffix;

/* точка входа в журнал: по ней видно, дошёл ли запрос вообще (пустой лог
   при «не получилось связаться» = браузер не добрался до сервера) */
ai_log('REQ', 'дист=' . $dist
  . ' цель=' . ($targetTime ?: '-') . ' темп=' . ($targetPace ?: '-')
  . ' пороги=' . ($thrAeHr || $thrPanoHr ? 'да' : 'нет')
  . ' км/нед=' . $km . ' дней/нед=' . $data['days_per_week']
  . ' дни=' . ($data['pref_days'] ?: $trainDaysTxt)
  . ' модель=' . ($algo ? 'Алгоритм (без ИИ)' : $gigaModel));

/* --- Лимит частоты (по образцу reviews.php) -------------------- */
/* Лимит и ключ GigaChat — только для ИИ-режимов: алгоритм детерминирован,
   не тратит токены и не ограничивается */
/* два независимых счётчика: ИИ тратит токены GigaChat, алгоритм — нет,
   но каждый запрос алгоритма пишет xlsx в out/ и грузит CPU. Раньше
   алгоритм не ограничивался вообще, и скрипт мог забить диск планами. */
if (!is_dir(DIR_DATA)) { @mkdir(DIR_DATA, 0755, true); }
$salt = __FILE__ . '|' . php_uname();
$ipHash = md5(($_SERVER['REMOTE_ADDR'] ?? '') . $salt);
$rateLimit = $algo ? ALGO_RATE_LIMIT : RATE_LIMIT;
$rateFile = DIR_DATA . '/_rate-' . ($algo ? 'algo-' : '') . $ipHash . '.json';
$now = time();
$stamps = @json_decode(@file_get_contents($rateFile), true);
if (!is_array($stamps)) { $stamps = array(); }
$stamps = array_values(array_filter($stamps, function ($t) use ($now) {
  return is_numeric($t) && ($now - (int)$t) < RATE_WINDOW;
}));
if (count($stamps) >= $rateLimit) {
  http_response_code(429);
  respond(false, $algo ? 'rate_algo' : 'rate');
}
$stamps[] = $now;
@file_put_contents($rateFile, json_encode($stamps), LOCK_EX);

/* --- GigaChat: ключ нужен только ИИ-режимам ------------------- */
if (!$algo && GIGA_KEY === '') { http_response_code(503); respond(false, 'no_key'); }

/* уборка прошлых запусков: раньше чистка была только в конце успешной
   генерации, поэтому упавшие задачи и мусор в out/ жили вечно */
plan_cleanup();

/* --- Мгновенный ack: браузеру достаточно идентификатора задачи,
       генерация продолжается уже ПОСЛЕ ответа. Короткий запрос не рвёт
       нестабильную сеть, а страница далее опрашивает план.php?job=… */
$jobId = (string)($in['job'] ?? '');
if (!preg_match('/^[a-z0-9]{16}$/', $jobId)) {
  $jobId = bin2hex(random_bytes(8));
}
@file_put_contents(DIR_DATA . '/plan-status-' . $jobId . '.json',
  json_encode(array('ok' => false, 'wait' => true)), LOCK_EX);
/* с этого момента fatal-обработчик знает, какую задачу закрывать */
$GLOBALS['plan_job'] = $jobId;
header('Content-Type: application/json; charset=utf-8');
echo json_encode(array('ok' => true, 'job' => $jobId), JSON_UNESCAPED_UNICODE);
if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
@flush();

function http_json($method, $url, $headers, $body = null, $timeout = 20) {
  $ch = curl_init($url);
  curl_setopt_array($ch, array(
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => ($method === 'POST'),
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_TIMEOUT => $timeout,
    CURLOPT_ENCODING => '',
    CURLOPT_FOLLOWLOCATION => true,  /* api.giga.chat может отдавать редиректы */
  ));
  /* пульс: пока модель думает (иногда минуты), клиенту каждые ~5 с идёт
     пробел — иначе нестабильная сеть или прокси разрывают «ничего не
     отвечает» соединение, и пользователь видит «не получилось связаться»,
     хотя сервер ещё работает. Ведущий пробел в JSON-ответе валиден. */
  if (!headers_sent()) {
    $hbTick = 0;
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION,
      function ($ch2, $dT = 0, $dN = 0, $uT = 0, $uN = 0) use (&$hbTick) {
        if (time() - $hbTick >= 5) {
          $hbTick = time();
          echo ' ';
          @ob_flush();
          @flush();
        }
        return 0;
      });
  }
  if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
  if (GIGA_INSECURE_TLS) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($res === false) { return array(null, 0); }
  return array($res, $code);
}

/* UUID v4 (RqUID должен быть валидным UUID — Сбер проверяет форму) */
function uuid_v4() {
  $b = random_bytes(16);
  $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
  $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/* Ключ может прийти в base64 («MDFh…») или сырым uuid:secret.
   Определяем автоматически: декодируем; если внутри «uuid:uuid» —
   ключ уже base64, отправляем как есть. */
function giga_basic_auth() {
  if (GIGA_KEY_ALREADY_BASE64 !== 'auto') {
    return GIGA_KEY_ALREADY_BASE64 ? GIGA_KEY : base64_encode(GIGA_KEY);
  }
  $dec = base64_decode(GIGA_KEY, true);
  if ($dec !== false && $dec !== '' &&
      preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}:[0-9a-f-]+$/i', $dec)) {
    return GIGA_KEY;
  }
  return base64_encode(GIGA_KEY);
}

function oauth_token() {
  global $now;
  /* кеш: используем, если в запасе больше 2 минут */
  $cache = @json_decode(@file_get_contents(TOKEN_FILE), true);
  if (is_array($cache) && isset($cache['token'], $cache['exp']) &&
      (float)$cache['exp'] - $now > 120) {
    return array($cache['token'], true);
  }
  $fresh = null;          /* что удалось получить сейчас */
  for ($att = 0; $att < 2 && $fresh === null; $att++) {
    if ($att > 0) { sleep(3); }   /* OAuth тоже бывает лимитирован — подождать */
    $key = giga_basic_auth();
    $headers = array(
      'Authorization: Basic ' . $key,
      'RqUID: ' . uuid_v4(),
      'Content-Type: application/x-www-form-urlencoded',
    );
    list($res, $code) = http_json('POST', GIGA_OAUTH_URL, $headers,
      http_build_query(array('scope' => GIGA_SCOPE)), 25);
    if (!$res) { $res = ''; }   /* не оставить тело null в логе */
    if ($code !== 200) {
      /* тихий отказ здесь — главный источник «недоступен» без записей
         в логе: теперь логируем каждый неуспех обмена ключа */
      ai_log('OAUTH code=' . $code . ' попытка=' . ($att + 1), (string)$res);
      continue;
    }
    $j = @json_decode($res, true);
    if (!$j || empty($j['access_token'])) {
      ai_log('OAUTH без токена', (string)$res);
      continue;
    }
    $exp = isset($j['expires_at']) ? (float)$j['expires_at'] / 1000.0 : ($now + 1500);
    @file_put_contents(TOKEN_FILE, json_encode(array(
      'token' => $j['access_token'], 'exp' => $exp)), LOCK_EX);
    $fresh = $j['access_token'];
  }
  if ($fresh !== null) { return array($fresh, true); }
  /* запасной ход: старый токен, у которого запас меньше 2 минут, но он
     ещё формально жив — лучше попробовать, чем молча упасть */
  if (is_array($cache) && isset($cache['token'], $cache['exp']) &&
      (float)$cache['exp'] - $now > 5) {
    ai_log('OAUTH резерв', 'используем прежний токен из кеша (обновление не удалось)');
    return array($cache['token'], true);
  }
  return array(null, false);
}

/* --- Промпт: методика клуба + данные ответа --------------------- */
$SYSTEM_PROMPT = <<<TXT
Ты — методист бегового клуба «ВЖУХ» (Москва). Составляешь план подготовки
любителя к целевой дистанции. Методика клуба (жёстко соблюдай):
1. Техника прежде объёмов: сначала экономичная техника, потом километры.
2. Нагрузку контролируем по пульсовым зонам: зона 1 <70% ЧССмакс — база,
   зона 2 70-80% — темпо/маршруты, зона 3 80-90% — интервалы, зона 4 90%+ — развеска.
3. Типы тренировок — кодами, как у тренера клуба (в поле type тоже код):
   ВОС — восстановительный бег (очень легко, разговорный темп),
   Д — длительный бег, ЦМТ — бег в целевом темпе старта, П — пороговый,
   И — интервалы, Ф — фартлек, Горка — ускорения в подъём, отдых.
   В ПЛАН входят только беговые тренировки (ВОС/Д/ЦМТ/П/И/Ф/Горка):
   силовая, ОФП, растяжка, СБУ в план не входят — их место в советах.
4. Структура недели: большая часть объёма — ВОС; одна «Д» (в выходной);
   после «Д» — ВОС или отдых; максимум 1-2 интенсивных (ЦМТ/П/И/Ф) и не
   подряд; дни без отмеченной тренировки — отдых (никаких «Сила»/«СБУ»
   в типах); фартлек «Ф» и ускорения в горку «Горка» — обязательные
   элементы с середины Базы и в Специальной фазе,
   примерно раз в 1-2 недели; темповые отрезки — не более ~25% недельного объёма. Число тренировок
   в неделю и сами дни задаёт сервер («Тренировок в неделю» в данных
   и отметка «тренировка: true/false» у каждого дня) — соблюдай точно:
   неделя с 4 тренировками не может получить 7 тренировочных дней.
5. Резкие прибавки запрещены: объём растёт максимум ~10% в неделю;
   каждая 3-4-я неделя — разгрузочная: объём минус 20-40%, интенсивная
   максимум одна и короткая, длинный бег тоже короче.
6. Последние 7-10 дней перед стартом — тэйпер: объём падает до 40-60%,
   интенсивности короткие не позже чем за 3 дня до старта.
7. Темп пиши ДИАПАЗОНОМ ±5-10 сек, не одной цифрой: «6:20-6:40». Пульс —
   диапазоном в границах зоны из данных бегуна: «125-140». Отрезки —
   форматом тренера: «12 км (5×800 м И, 400 м отдых), раб. 4:50-5:05».
8. Темпы — ТОЛЬКО из «Таблицы темпов» данных бегуна (её посчитал сервер
   от целевого темпа и порогов). Цифр вне таблицы не существует:
   нельзя писать 7-минутный темп бегуну с целевым 5:40. Нет опоры —
   словами («чуть быстрее Д», «уровень порога») без цифр.
9. Если данных мало — делай консервативные предположения.
10. Самая длинная тренировка — за 2-3 недели до старта, примерно 75-80%
    от целевой дистанции (марафон — обычно 30-34 км). Рост длинного бега
    постепенный по ~2-3 км; после неё — тэйпер, длинный бег укорачивается.
11. Темп по зонам строго по возрастанию интенсивности: зона 2 медленнее
    зоны 3, зона 3 медленнее зоны 4 (в мин/км число УМЕНЬШАЕТСЯ между
    зонами). Никогда не пиши в зоне 2 темп быстрее зоны 3.

Объёмы недель, периодизация и фазы уже посчитаны сервером по методике
клуба (рост ≤10%, разгрузочная каждая 4-я, тэйпер в конце) — их НЕ
пересчитывай, отталкивайся от volume_km и фазы каждой недели.
TXT;

/* Общие правила формата для всех вызовов модели */
$FORMAT_RULES = 'Ты отвечаешь ТОЛЬКО валидным JSON без markdown и без тройных кавычек. '
 . 'Кавычки ТОЛЬКО двойные («ключ» = с парой двойных кавычек), как в JSON: '
 . 'НЕ Python-словарь с одинарными кавычками, без обрамления ``` и без слова json. '
 . 'Ключи строго латиницей (distance_km, volume_km — не «kм» и не «km» кириллицей). '
 . 'Закрывай все скобки. Ничего не пиши после закрывающей скобки JSON. '
 . 'Строки коротко: комментарии до 80 знаков.';
/* дни недели русскими сокращениями */
$DAY_RULES = 'Дни недели русскими сокращениями: ПН ВТ СР ЧТ ПТ СБ ВС. '
 . 'type дня строго из списка: отдых|ВОС|Д|ЦМТ|П|И|Ф|Горка '
 . '(коды методики клуба, расшифровка в правилах выше; других типов нет: '
 . 'силовая/ОФП/СБУ/растяжка/техника в план не входят). '
 . 'Ключ дня: day, date, workout, distance_km, intensity, comment. '
 . 'intensity — «зона N» или «зона N, темп A-B», где A-B диапазон мин/км '
 . 'ИЗ «Таблицы темпов» данных бегуна (других цифр темпа нет). '
 . 'Для отрезков workout в формате '
 . 'тренера: «12 км (5×800 м И, 400 м отдых), раб. 4:50-5:05». '
 . 'comment — 1 предложение: зачем тренировка или на что смотреть.';

/* 4 зоны — фиксируем перечнем, чтобы модель не «теряла» уровни */
$ZONE_RULES = 'zones — всегда 4 объекта: зона 1 восстановление, зона 2 аэробная, '
 . 'зона 3 скоростная (у порога), зона 4 анаэробная/скорость. Границы зон в пульсе '
 . 'уже заданы в данных бегуна — используй ИХ, не считай по формулам сам. '
 . 'В каждой зоне: name, hr (диапазон уд/мин из данных), pace (темп на км). '
 . 'Темп пиши от измеренных порогов и целевого результата: если пороги неизвестны, '
 . 'зона 2 = «легкий разговорный», зона 3 = «уровень аэробного порога», '
 . 'зона 4 = «выше порога». Не выдумывай цифры темпа без опоры на данные бегуна.';

$SKELETON_SCHEMA = 'Схема ответа (НЕДЕЛИ НЕ ПИШИ — их посчитал сервер):'
 . '{"race":{"name":"строка","distance_km":число,"date":"YYYY-MM-DD или пусто"},'
 . '"summary":"2-3 предложения: оценка готовности, главный акцент плана",'
 . '"zones":[{"name":"зона 1 восстановление","hr":"a-b уд/мин","pace":"темп из таблицы темпов"}],'
 . '"advice":["3-7 коротких советов: питание, сон, кросс, старт"]}';

$CHUNK_SCHEMA = 'Схема ответа (только дни перечисленных недель):'
 . '{"weeks":[{"n":число — как перечислено,"days":[{"day":"ПН","date":"YYYY-MM-DD",'
 . '"type":"отдых|ВОС|Д|ЦМТ|П|И|Ф|Горка","workout":"что делать",'
 . '"distance_km":число,"intensity":"зона/темп","comment":"1 предложение"}]}]} '
 . 'В days — ВСЕ дни недели из списка (календарь задан сервером), сколько указано '
 . 'в days_total; для дней с тренировка:false — type «отдых», distance_km 0. '
 . 'Ровно по одному объекту weeks на каждую перечисленную неделю, n точь-в-точь как в списке.';

/* измеренные пороги — в удобный для модели текст */
$thrText = array();
$thrText[] = 'Аэробный порог: ' . ($thrAeHr && $thrAePace
  ? 'пульс ' . $thrAeHr . ', темп ' . $thrAePace . ' на км'
  : ($thrAeHr ? 'пульс ' . $thrAeHr
    : ($thrAePace ? 'темп ' . $thrAePace . ' на км' : 'не измерен')));
$thrText[] = 'ПАНО: ' . ($thrPanoHr && $thrPanoPace
  ? 'пульс ' . $thrPanoHr . ', темп ' . $thrPanoPace . ' на км'
  : ($thrPanoHr ? 'пульс ' . $thrPanoHr
    : ($thrPanoPace ? 'темп ' . $thrPanoPace . ' на км' : 'не измерен')));

$u = array(
  'Целевая дистанция' => $dist . ' км',
  'Целевой длинный бег' => 'около ' . round(round($dist * 7.75) / 10, 1)
    . ' км (75-80% дистанции, за 2-3 недели до старта)',
  'Опыт' => $data['experience'],
  'Км в неделю сейчас' => $data['km_per_week'],
  'Самая длинная пробежка за месяц' => $data['longest_run'] . ' км',
  'Тренировок в неделю' => $data['days_per_week']
    . ' (дни: ' . $trainDaysTxt . ')',
  'Место тренировок' => ($data['place'] ?: 'не указано'),
  'Пол' => $gender,
  'Возраст' => $age,
  'Травмы' => ($data['injuries'] ?: 'нет данных'),
  'Часы с пульсом' => $data['hr_watch'],
  'ЧССмакс (расчёт по возрасту)' => $hrMax,
  'Границы пульсовых зон, уд/мин' => 'з1: до ' . $z1z2 . '; '
    . 'з2: ' . $z1z2 . '-' . $z2z3 . '; '
    . 'з3: ' . $z2z3 . '-' . $z3z4 . '; '
    . 'з4: выше ' . $z3z4 . ', максимум ' . $hrMax,
  'Пороги' => implode(' ', $thrText),
  'Планируемый результат' => ($targetTime ?: 'не задан'),
  'Недавний старт' => ($data['recent_race']['txt'] ?: 'нет данных'),
  'Целевой темп старта' => ($targetPace ?: 'не задан (результат не указан)'),
  'Таблица темпов (СТРОГО только эти темпы)' => $pacePlanTxt,
  'Дата старта' => ($raceDate ?: 'не определена'),
  'Начало тренировок' => ($startDate ?: 'выбери сам (ближайший разумный день)'),
  'Недель до старта' => ($weeks ?: 'по срокам сам выбери'),
);

/* (промпты вызовов собираются ниже, после определения call_model) */

/* --- Вызов модели (с одним ретраем при 401) -------------------- */
/* Журнал: последний ответ модели — для разбора проблем с разбором JSON.
   Не содержит ключа; хранится в папке data, наружу не отдаётся. */
function ai_log($label, $text) {
  $f = DIR_DATA . '/model-log.txt';
  /* FILE_APPEND | LOCK_EX — без чтения-переделки: два параллельных запроса
     (пользователь перетапливает кнопку, пока первый скрипт ещё работает)
     не стирали бы записи друг друга (реально терялись REQ и CONTENT) */
  if (@filesize($f) > 120000) { @file_put_contents($f, '', LOCK_EX); }
  @file_put_contents($f, "==== " . date('Y-m-d H:i:s') . " " . $label
    . " ====\n" . mcut((string)$text, 40000) . "\n\n", FILE_APPEND | LOCK_EX);
}

function call_model($payload) {
  global $SYSTEM_PROMPT;
  $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
  if ($body === false || $body === '') {
    /* json_encode падает на битом UTF-8 — обычно это plan.php, залитый
       на хостинг не в UTF-8 (все литералы промптов становятся битыми) */
    $hint = (function_exists('mb_check_encoding')
             && !mb_check_encoding($SYSTEM_PROMPT, 'UTF-8'))
      ? ' — plan.php на хостинге не в UTF-8, перезалейте файл в UTF-8'
      : ' — причина неясна, пришлите лог';
    ai_log('ENCODE FAIL', json_last_error_msg() . $hint);
    return array(null, 'ai_unavailable');
  }
  list($token, $ok) = oauth_token();
  if (!$ok) { return array(null, 'ai_unavailable'); }
  $resolved = false;   /* модель уже подбирали после 404 */
  for ($attempt = 0; $attempt < 3; $attempt++) {
    list($res, $code) = http_json('POST', GIGA_API_URL,
      array(
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
      ), $body, 180);
    if ($code === 401) {  /* токен отлетел — обновить и повторить */
      @unlink(TOKEN_FILE);
      list($token, $ok) = oauth_token();
      if (!$ok) { return array(null, 'ai_unavailable'); }
      continue;
    }
    if ($code === 200 && $res) {
      ai_log('API 200', (string)$res);
      $j = @json_decode($res, true);
      if ($j && isset($j['choices'][0]['message']['content'])) {
        return array($j['choices'][0]['message']['content'], null);
      }
    }
    if (($code === 404 || $code === 429) && !$resolved) {
      /* 404 «No such model» — хост мигрировал/поменял список моделей;
         429 — лимиты (прежде всего у «умной» Pro) исчерпаны. */
      ai_log('API code=' . $code . ' model=' . $payload['model'],
             (string)$res);
      $resolved = true;
      if ($code === 429) {
        sleep(10);
        if ((string)$payload['model'] !== (string)GIGA_MODEL_DAYS) {
          /* откат на лёгкую модель — план важнее премиальности */
          ai_log('MODEL SWITCH 429', GIGA_MODEL_DAYS);
          $payload['model'] = GIGA_MODEL_DAYS;
          continue;
        }
        break;   /* и так лёгкая — не перебиваем */
      }
      $resolved = true;
      list($mres, $mcode) = http_json('GET', GIGA_MODELS_URL,
        array('Authorization: Bearer ' . $token), null, 20);
      ai_log('MODELS ' . $mcode, (string)$mres);
      $mj = @json_decode((string)$mres, true);
      $ids = array();
      if ($mj && isset($mj['data']) && is_array($mj['data'])) {
        foreach ($mj['data'] as $d) {
          if (is_array($d) && isset($d['id'])) { $ids[] = (string)$d['id']; }
        }
      }
      $cand = '';
      /* предпочтение: модель запроса, затем вторая из пары, затем любая лёгкая */
      foreach (array($payload['model'], GIGA_MODEL_DAYS, GIGA_MODEL) as $p) {
        if (in_array($p, $ids, true)) { $cand = $p; break; }
      }
      if ($cand === '') {
        foreach ($ids as $m) {
          if (strpos($m, 'GigaChat') === 0) { $cand = $m; break; }
        }
      }
      if ($cand !== '' && $cand !== (string)$payload['model']) {
        ai_log('MODEL SWITCH', $cand);
        $payload['model'] = $cand;
        continue;
      }
    }
    ai_log('API code=' . $code . ' model=' . $payload['model']
           . ' body_len=' . strlen($body), (string)$res);
    break;  /* остальные коды — честный сбой без ретрая */
  }
  return array(null, 'ai_unavailable');
}

/* --- Разбор ответа модели -------------------------------------- */
/* Ремонт недозакрытого/повреждённого JSON от модели. Реальные глитчи
   (по логам GigaChat:2.0.28.2):
   а) не закрыт массив недель — после «},» сразу «"zones":»;
   б) не закрыт объект дня — «"comment": "...", {» без «}»;
   в) закрывающая кавычка строки заменяется «)»;
   г) обрыв по лимиту токенов — хвост без скобок.
   Сканер вставляет недостающие скобки с переносом запятых. */
function repair_json($s) {
  $strip_commas = function ($t) {
    return preg_replace('/,\\s*([}\\]])/', '$1', $t);
  };
  $s = $strip_commas($s);
  /* (в) «...")» → «..."» */
  $s = preg_replace('/"\\)/', '"', $s);

  $stack = array(); $inStr = false; $esc = false;
  $out = '';
  $lastSig = '';   /* последний значимый символ вне строк */
  $rootClosed = false;  /* глитч «е»: модель пишет ЛИШНЮЮ скобку после конца */
  $len = strlen($s);
  for ($i = 0; $i < $len; $i++) {
    $c = $s[$i];
    if ($rootClosed) { break; }   /* хвост после закрытия корня — мусор, игнорируем */
    if ($inStr) {
      $out .= $c;
      if ($esc) { $esc = false; }
      elseif ($c === '\\') { $esc = true; }
      elseif ($c === '"') { $inStr = false; $lastSig = '"'; }
      continue;
    }
    if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t") { $out .= $c; continue; }
    /* закрываем объект, если «забыли» } перед следующим значением;
       запятую переносим внутрь, при массиве-родителе добавляем разделитель */
    $closeObj = function () use (&$stack, &$out, &$rootClosed) {
      $needComma = false;
      $out = rtrim($out, " \t\n\r\0\x0B");
      if (substr($out, -1) === ',') { $out = substr($out, 0, -1); $needComma = true; }
      $out .= '}';
      array_pop($stack);
      if (!$stack) { $rootClosed = true; }
      if ($needComma) { $out .= ','; }
      return $needComma;
    };
    if ($c === '"') {
      $isKey = preg_match('/"(?:[^"\\\\]|\\\\.)*"\\s*:/', $s, $mm, 0, $i) === 1;
      if ($isKey && $stack && end($stack) === ']') {
        /* (а) массив не закрыли, а в нём уже пошёл ключ объекта */
        $needComma = false;
        $out = rtrim($out, " \t\n\r\0\x0B");
        if (substr($out, -1) === ',') { $out = substr($out, 0, -1); $needComma = true; }
        $out .= ']';
        array_pop($stack);
        if (!$stack) { $rootClosed = true; }
        if ($needComma) { $out .= ','; }
      } elseif (!$isKey && $stack && end($stack) === '}' && $lastSig !== ':') {
        /* (б) объект не закрыли перед следующим значением */
        $needComma = $closeObj();
        if (!$needComma && $stack && end($stack) === ']') { $out .= ','; }
      }
      if ($rootClosed) { break; }   /* и текущий символ уже мусор */
      $out .= $c;
      $inStr = true;
      continue;
    }
    if ($c === '{') {
      if ($stack && end($stack) === '}' && $lastSig !== ':') {
        $needComma = $closeObj();
        if (!$needComma && $stack && end($stack) === ']') { $out .= ','; }
      }
      if ($rootClosed) { break; }
      $stack[] = '}'; $out .= $c; $lastSig = '{'; continue;
    }
    if ($c === '[') { $stack[] = ']'; $out .= $c; $lastSig = '['; continue; }
    if ($c === ':' || $c === ',') { $out .= $c; $lastSig = $c; continue; }
    if ($c === '.' ) { $out .= $c; $lastSig = 'd'; continue; }  /* часть числа 21.1 */
    if ($c >= '0' && $c <= '9') {
      if ($stack && end($stack) === '}' && $lastSig !== ':' && $lastSig !== 'd') {
        $needComma = $closeObj();
        if (!$needComma && $stack && end($stack) === ']') { $out .= ','; }
      }
      $out .= $c; $lastSig = 'd'; continue;
    }
    if ($c === '}' || $c === ']') {
      /* закрытатель «чужого» уровня — дотянуться до своей скобки */
      while ($stack && end($stack) !== $c &&
             (end($stack) === '}' || end($stack) === ']')) {
        $out .= array_pop($stack);
      }
      if ($stack && end($stack) === $c) { array_pop($stack); }
      if (!$stack) { $rootClosed = true; }   /* корень закрыт — дальше мусор */
      $out .= $c; $lastSig = $c; continue;
    }
    $out .= $c;
  }
  if ($inStr) { $out .= '"'; }
  while ($stack) { $out .= array_pop($stack); }
  return $strip_commas($out);
}

/* нормализация ответа модели до строгого JSON: снять ```-фенсы, вырезать
   от первой { до последней }, и — реальный случай из лога 17:06 — если
   модель выдала Python-словарь (все строки в ОДИНАРНЫХ кавычках, двойных
   нет вовсе), заменить кавычки на двойные */
function to_jsonish($content) {
  $s = preg_replace('/```(?:json)?/i', '', (string)$content);
  $start = strpos($s, '{');
  $end = strrpos($s, '}');
  if ($start === false || $end === false || $end <= $start) { return ''; }
  $s = substr($s, $start, $end - $start + 1);
  if (strpos($s, '"') === false &&
      substr_count($s, "'") > 0 && substr_count($s, "'") % 2 === 0) {
    $s = str_replace("'", '"', $s);
  }
  return $s;
}

function extract_json($content) {
  $s = to_jsonish($content);
  if ($s === '') { return null; }
  $j = @json_decode($s, true);
  if (!is_array($j)) {
    $j = @json_decode(repair_json($s), true);
    if (is_array($j)) {
      ai_log('REPAIR OK', 'JSON отремонтирован (модель не закрыла скобки/запятые)');
    } else {
      ai_log('REPAIR FAIL', 'Не помог даже ремонт — см. предыдущий CONTENT');
    }
  }
  /* фаза 1 теперь отвечает только метой (race/summary/zones/advice) —
     недель в ответе нет и не должно быть */
  return (is_array($j) &&
          (isset($j['race']) || isset($j['summary']))) ? $j : null;
}

/* ответ фазы-2: {"weeks":[{"n":..,"days":[..]}]} — только наполнение дней */
function extract_days_json($content) {
  $s = to_jsonish($content);
  if ($s === '') { return null; }
  $j = @json_decode($s, true);
  if (!is_array($j)) { $j = @json_decode(repair_json($s), true); }
  if (!is_array($j) || !isset($j['weeks']) || !is_array($j['weeks'])) { return null; }
  $out = array();
  foreach ($j['weeks'] as $wr) {
    if (is_array($wr) && isset($wr['n']) &&
        isset($wr['days']) && is_array($wr['days']) && count($wr['days']) > 0) {
      $out[] = array('n' => (int)$wr['n'], 'days' => $wr['days']);
    }
  }
  return count($out) ? array('weeks' => $out) : null;
}

/* русское имя дня недели по метке времени (даты модели нормализуем сами) */
function ru_wd($ts) {
  $names = array(1 => 'ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС');
  $iso = (int)date('N', $ts);  /* 1=понедельник */
  return $names[$iso];
}

/* --- Календарь: старт, длина, фазы, объёмы — ВСЁ на сервере ---- */
/* Периодизация в стиле эталонного плана тренера (Plan-example):
   Восстановление → База I → База II (~55% плана) → Специальная (~45%)
   → Подводка 1-2 недели; разгрузочная каждая 4-я внутри базы/спец.,
   объём растёт ≤10% к потолку ≈ 1,5 от текущего км/нед. */
$raceTs = $raceDate !== '' ? strtotime($raceDate) : false;
$nAuto = $dist >= 40 ? 16 : ($dist >= 20 ? 12 : ($dist >= 9 ? 10 : 8));
$startTs0 = 0;
if ($startDate !== '') {
  $startTs0 = strtotime($startDate) ?: strtotime('next monday');
  $nTotal = $raceTs
    ? (int)ceil((($raceTs - $startTs0) / 86400 + 1) / 7)
    : ($weeks > 0 ? $weeks : $nAuto);
} elseif ($raceTs) {
  /* дата старта не задана — план разумной длины от дистанции */
  $nTotal = max(3, min(52, $nAuto));   /* до года подготовки */
  $startTs0 = $raceTs - ($nTotal * 7 - 1) * 86400;
} else {
  $startTs0 = strtotime('next monday');
  $nTotal = $weeks > 0 ? $weeks : $nAuto;
}
/* план обязан ДОХОДИТЬ до даты старта: раньше обрубался на 40 неделях,
   из-за чего годовой план (49 недель) кончался в июле, а не у гонки */
$nTotal = max(3, min(52, $nTotal));

/* Режим «Алгоритм»: без даты гонки зональные недели не замкнуты — синтезируем
   дату из «сколько недель до старта» (последний день последней недели) */
if ($algo && $raceDate === '') {
  $raceTs = $startTs0 + ($nTotal * 7 - 1) * 86400;
  $raceDate = date('Y-m-d', $raceTs);
}

/* --- Разметка недель: с понедельника ---------------------------- */
/* первая неделя начинается с выбранной даты старта (может быть
   неполной), далее недели строго с понедельника; при заданной дате
   гонки последняя неделя обрывается её датой */
$wkStarts = array($startTs0);
$offMo = (8 - (int)date('N', $startTs0)) % 7;
if ($offMo === 0) { $offMo = 7; }
$begMo = $startTs0 + $offMo * 86400;
$limMo = ($raceTs !== false) ? $raceTs : ($startTs0 + ($nTotal - 1) * 7 * 86400);
while ($begMo <= $limMo && count($wkStarts) < 55) {
  $wkStarts[] = $begMo;
  $begMo += 7 * 86400;
}
if ($raceTs !== false && count($wkStarts) > 1) {
  $nTotal = count($wkStarts);   /* неделя, в которой стоит старт */
}
/* минимум 3 недели — только когда гонка не задана: с гонкой план
   обязан закончиться её датой, даже если она ближе трёх недель */
$nTotal = ($raceTs !== false)
  ? max(2, min(52, $nTotal)) : max(3, min(52, $nTotal));
$wkStarts = array_slice($wkStarts, 0, $nTotal);
if ($raceTs !== false && count($wkStarts) < $nTotal) {
  $nTotal = count($wkStarts);   /* страховка: не вылезти за границы */
}

$nRec = ($nTotal >= 16) ? 2 : (($nTotal >= 10) ? 1 : 0);
$nTap = ($nTotal >= 10) ? 2 : 1;
if ($nRec + $nTap >= $nTotal) { $nRec = 0; $nTap = 1; }
$rest = $nTotal - $nRec - $nTap;
$nBase = (int)ceil($rest * 0.55);
$nB1 = (int)ceil($nBase / 2);
$vol = max($km, 8);                                  /* стартовый объём */
$cap = min(100, max(12, (int)round($vol * ($nTotal >= 24 ? 1.6 : 1.5))));
$detWk = array();
for ($i = 1; $i <= $nTotal; $i++) {
  $inRec = $i <= $nRec;
  $inTap = $i > $nTotal - $nTap;      /* подводка — ПОСЛЕДНИЕ недели плана */
  $inSpc = !$inRec && !$inTap && ($i - $nRec) > $nBase;
  $cut = false;
  if (!$inRec && !$inTap) {
    $within = $inSpc ? ($i - $nRec - $nBase) : ($i - $nRec);
    if ($within > 1 && $within % 4 === 0) { $cut = true; }
  }
  if ($inRec) {
    $period = 'Восстановление';
    $volW = (int)round($vol * 0.8);
    $focus = 'лёгкий объём, техника, адаптация';
  } elseif ($inTap) {
    $period = 'Подводка';
    $after = $nTotal - $i;
    $volW = (int)round($vol * ($after === 0 ? 0.45 : 0.7));
    $focus = $after === 0
      ? 'стартовая неделя: минимум объёма'
      : 'тэйпер: объём вниз, интенсивность короткая';
  } elseif ($cut) {
    $period = $inSpc ? 'Специальная'
      : ((($i - $nRec) <= $nB1) ? 'База I' : 'База II');
    $volW = (int)round($vol * 0.7);
    $focus = 'разгрузочная: объём −30%';
  } else {
    $period = $inSpc ? 'Специальная'
      : ((($i - $nRec) <= $nB1) ? 'База I' : 'База II');
    $vol = min($cap, (int)round($vol * ($inSpc ? 1.04 : 1.08)));
    $volW = $vol;
    $focus = $inSpc
      ? 'ЦМТ и пороговая работа, длинный к пику'
      : 'аэробный объём, длинный бег растёт';
  }
  /* бегун назвал свой текущий км/нед — стартовать заметно ниже его уровня
     нельзя: в восстановительных и базовых (и в разгрузочных) неделях
     объём не падает ниже названного; ниже текущего — только в подводке */
  if (!$inTap) { $volW = (int)max($volW, $km); }
  $detWk[] = array(
    'n' => $i,
    'start' => date('Y-m-d', $wkStarts[$i - 1]),
    'period' => $period,
    'volume_km' => $volW,
    'focus' => $focus,
  );
}

/* --- Восстановительная неделя после целевого старта ------------- */
/* план не обрывается на гонке: после 42,2 км идёт неделя полного
   отдыха; после промежуточных стартов — три дня отдыха ($raceRest) */
$recWeekN = 0;
if ($raceTs !== false) {
  $offRec = (8 - (int)date('N', $raceTs)) % 7;
  if ($offRec === 0) { $offRec = 7; }
  $recWeekN = $nTotal + 1;
  $detWk[] = array(
    'n' => $recWeekN,
    'start' => date('Y-m-d', $raceTs + $offRec * 86400),
    'period' => 'Восстановление',
    'volume_km' => 0,
    'focus' => 'неделя отдыха после целевого старта: без бега, сон и прогулки',
  );
}

/* --- Особые периоды: структурированные поля анкеты -------------- */
/* Перерывы и промежуточные старты приходят отдельными полями
   (breaks: start/end/ease, races: date/km) — свободный текст больше
   не разбираем: модель на нём терпела неудачи. Всё детерминировано:
   в недели перерыва — лёгкий бег; при флажке «снизить нагрузку до и
   после» — смягчённая неделя до и мягкий вход после; к стартам —
   подводка по их дистанции. Подводку целевой гонки не трогаем. */
$specialNotes = array();   /* для «Сводки», человекочитаемо */
$specialWeeks = array();   /* неделя n => виды 'vacation|race|race-taper|...' */
$raceMid = array();        /* дата Y-m-d => подпись промежуточного старта */
$midKmWeek = array();      /* неделя n => км промежуточного старта в ней */
$raceKmByDate = array();   /* дата Y-m-d => точная дистанция старта (для ячейки км) */
$yBase = (int)date('Y', $startTs0);
$endTs0 = $startTs0 + $nTotal * 7 * 86400;
$weekOf = null;
$weekOf = function ($ts) use ($wkStarts, $nTotal) {
  /* недели теперь идут по понедельникам (первая может быть неполной):
     неделя даты — последняя граница, не позже самого ts */
  $wi = 1;
  foreach ($wkStarts as $ixW => $wsW) {
    if ($wsW <= $ts) { $wi = $ixW + 1; } else { break; }
  }
  return max(1, min($nTotal, $wi));
};
/* разметка недели видом без дубликатов (много перерывов/стартов в одну) */
$sMark = null;
$sMark = function ($wi, $kind) use (&$specialWeeks, $nTotal) {
  if ($wi < 1 || $wi > $nTotal) { return; }
  if (!isset($specialWeeks[$wi])) { $specialWeeks[$wi] = array(); }
  if (!in_array($kind, $specialWeeks[$wi], true)) { $specialWeeks[$wi][] = $kind; }
};

/* промежуточные старты: дата и дистанция из анкеты */
foreach ($data['races'] as $rArr) {
  if (count($raceMid) >= 6) { break; }
  if (!is_array($rArr)) { continue; }
  $rd = isset($rArr['date']) ? clean($rArr['date'], 10) : '';
  $rTs = $rd !== '' ? strtotime($rd) : false;
  if ($rTs === false || $rTs > $endTs0) { continue; }
  $rTargetTs = $raceDate !== '' ? strtotime($raceDate) : false;
  if ($rTs < $startTs0 - 3 * 86400) { continue; }       /* раньше плана */
  if ($rTargetTs !== false && abs($rTargetTs - $rTs) <= 3 * 86400) {
    continue;                                           /* это целевой старт */
  }
  $rKm = (isset($rArr['km']) && is_numeric($rArr['km'])) ? (float)$rArr['km'] : 0;
  if ($rKm < 1 || $rKm > 500) { $rKm = 0; }
  $w1 = $weekOf($rTs);
  if ($rKm > 0) {
    $midKmWeek[$w1] = max(isset($midKmWeek[$w1]) ? $midKmWeek[$w1] : 0, $rKm);
    /* в ячейке км у дня старта пишем точную дистанцию (21, 42.2 и пр.) */
    $raceKmByDate[date('Y-m-d', $rTs)] = $rKm;
  }
  $rKmTxt = $rKm > 0
    ? (($rKm === (int)$rKm ? (int)$rKm : round($rKm, 1)) . ' км') : '';
  $raceMid[date('Y-m-d', $rTs)] = $rKmTxt !== ''
    ? 'Промежуточный старт ' . $rKmTxt : 'Промежуточный старт (дистанция не указана)';
  $sMark($w1, 'race');
  /* подводка по значимости старта: 32+ км — 2 нед, 15-31 км — 1 нед */
  $nTapMid = $rKm >= 32 ? 2 : ($rKm >= 15 ? 1 : 0);
  for ($tA = 1; $tA <= $nTapMid; $tA++) { $sMark($w1 - $tA, 'race-taper'); }
  $sMark($w1 + 1, 'race-after');
  $specialNotes[] = 'промежуточный старт ' . date('d.m', $rTs)
    . ($rKmTxt !== '' ? ' (' . $rKmTxt . ')' : '')
    . ($nTapMid > 0 ? ' — подводка ' . $nTapMid . ' нед' : '');
}

/* перерывы: период в неделях; флажок ease — смягчить до и после */
foreach ($data['breaks'] as $bArr) {
  if (count($specialNotes) >= 12) { break; }
  if (!is_array($bArr)) { continue; }
  $bS = isset($bArr['start']) ? clean($bArr['start'], 10) : '';
  $bE = isset($bArr['end']) ? clean($bArr['end'], 10) : '';
  $tS = $bS !== '' ? strtotime($bS) : false;
  $tE = $bE !== '' ? strtotime($bE) : false;
  if ($tS === false) { continue; }
  if ($tE === false || $tE < $tS) { $tE = $tS + 7 * 86400; }  /* без конца — неделя */
  if ($tS > $endTs0 || $tE < $startTs0 - 3 * 86400) { continue; }
  if ($tS < $startTs0) { $tS = $startTs0; }
  if ($tE > $endTs0) { $tE = $endTs0; }
  $bEaseRaw = isset($bArr['ease']) ? $bArr['ease'] : false;
  $bEase = $bEaseRaw === true || in_array((string)$bEaseRaw, array('1', 'true', 'on'), true);
  $w1 = $weekOf($tS);
  $w2 = $weekOf($tE);
  $nBrk = 0;
  for ($wi = $w1; $wi <= $w2; $wi++) { $sMark($wi, 'vacation'); $nBrk++; }
  $specialNotes[] = date('d.m', $tS) . '-' . date('d.m', $tE)
    . ' перерыв в тренировках (недель: ' . $nBrk . '); тренировок нет'
    . ($bEase ? '; сниженная нагрузка до и после' : '');
  if ($bEase) {
    $sMark($w1 - 1, 'vac-ease-pre');
    $sMark($w2 + 1, 'vac-ease-post');
  }
}
if (count($specialNotes)) {
  ai_log('SPECIAL', implode('; ', $specialNotes));
  $u['Особые периоды (из анкеты)'] = implode('; ', $specialNotes);
}

foreach ($specialWeeks as $wiX => $kinds) {
  if (!isset($detWk[$wiX - 1])) { continue; }
  $wX = &$detWk[$wiX - 1];
  if ($wX['period'] === 'Подводка') { unset($wX); continue; }
  $volX = (int)$wX['volume_km'];
  if (in_array('vacation', $kinds, true)) {
    /* перерыв = тренировок нет вовсе (правка от 16.10.2026),
       флажок ease и так смягчает неделю до и вход после */
    $wX['volume_km'] = 0;
    $wX['focus'] = 'перерыв в тренировках: беговых тренировок нет (отдых, прогулки)';
  } elseif (in_array('race', $kinds, true)) {
    /* чем значимее старт, тем сильнее снижаем: 15+ км — ×0.6, иначе ×0.8;
       объём недели старта ограничен достижимым: старт + закрутка + лёгкие */
    $rKm = isset($midKmWeek[$wiX]) ? $midKmWeek[$wiX] : 0;
    $volRace = (int)round($volX * ($rKm >= 15 ? 0.6 : 0.8));
    $volRace = min($volRace, (int)$rKm + 16);
    $wX['volume_km'] = max(5, $volRace);
    $wX['focus'] = 'неделя с промежуточным стартом'
      . ($rKm > 0 ? ' (' . ($rKm === (int)$rKm ? (int)$rKm : $rKm) . ' км):' : ':')
      . ' накануне — закрутка, за 2-3 дня только лёгкий';
  } elseif (in_array('race-taper', $kinds, true)) {
    $wX['volume_km'] = (int)round($volX * 0.7);
    $wX['focus'] = 'подводка к промежуточному старту: объём заметно ниже,'
      . ' интенсивность максимум одна короткая в начале недели';
  } elseif (in_array('race-after', $kinds, true)) {
    $wX['volume_km'] = (int)round($volX * 0.8);
    $wX['focus'] = 'восстановление после промежуточного старта';
  } elseif (in_array('vac-ease-pre', $kinds, true)) {
    $wX['volume_km'] = (int)round($volX * 0.85);
    $wX['focus'] = 'снижение нагрузки перед перерывом в тренировках: без ЦМТ, П и И';
  } elseif (in_array('vac-ease-post', $kinds, true)) {
    $wX['volume_km'] = (int)round($volX * 0.75);
    $wX['focus'] = 'мягкий вход после перерыва: объём ниже обычного, без ЦМТ и И';
  }
  unset($wX);
}

/* --- ФАЗА 1: модель пишет только оценку, зоны и советы --------- */
/* Календарь/объёмы/фазы готовы — модель не может их сломать, и никакой
   «скелет на 40 недель» больше не гоняется через токены.
   В режиме «Алгоритм» фаза пропускается: всё то же — с сервера. */
$planMeta = null;
if (!$algo) {
$skeletonPayload = array(
  'model' => $gigaModel,
  'messages' => array(
    array('role' => 'system',
      'content' => $SYSTEM_PROMPT . "\n\n" . $FORMAT_RULES . "\n\n"
                 . $ZONE_RULES . "\n\n" . $SKELETON_SCHEMA),
    array('role' => 'user',
      'content' => 'Данные бегуна (JSON): ' . json_encode($u, JSON_UNESCAPED_UNICODE) .
        "\nНЕ пиши недели — они уже составлены. Верни только race, summary," .
        "\nzones, advice по схеме. Название гонки — по дистанции (" . $dist .
        " км), date = " . ($raceDate !== '' ? $raceDate : 'пустая строка') . ".",
    ),
  ),
  'temperature' => 0.4,
  'max_tokens' => 1200,
);
$planMeta = null;
for ($skAtt = 0; $skAtt < 2 && !$planMeta; $skAtt++) {
  list($content, $err) = call_model($skeletonPayload);
  if ($err) { status_finish($jobId, false, $err); }
  ai_log('CONTENT', (string)$content);
  $planMeta = extract_json($content);
  if (!$planMeta && $skAtt === 0) {
    ai_log('EXTRACT FAIL', 'Мета не разобрана — ретрай с жёстким требованием формата');
    $skeletonPayload['messages'][1]['content'] .=
      "\nВАЖНО: прошлый ответ был нечитаемым. Это могла быть Python-запись с"
      . " одинарными кавычками. Верни только JSON: двойные кавычки, без "
      . "обрамления ``` и без текста вокруг.";
    sleep(1);
  }
}
} /* конец ИИ-фазы 1 */
/* даже если модель не ответила — план всё равно собирается: фазы,
   объёмы, темпы и зоны на сервере; дефолтные тексты честные */
$plan = array('weeks' => $detWk);
/* имя гонки — детерминированно от дистанции: модель в реальном прогоне
   назвала марафон 42,2 км «полумарафоном» */
$raceNameDet = $dist >= 40 ? 'Марафон'
  : ($dist >= 20 ? 'Полумарафон' : 'Забег');
$plan['race'] = array(
  'name' => $raceNameDet,
  'distance_km' => $dist,
  'date' => $raceDate,
);
$plan['summary'] = (!empty($planMeta['summary'] ?? null) && is_string($planMeta['summary']))
  ? clean($planMeta['summary'], 300)
  : 'План по методике клуба «ВЖУХ»: восстановление-база — специальная'
    . ' работа в целевом темпе — подводка. Объёмы и фазы рассчитаны'
    . ' автоматически: рост ≤10% в неделю, разгрузочная каждая 4-я,'
    . ' тэйпер перед стартом.';
$plan['zones'] = (!empty($planMeta['zones'] ?? null) && is_array($planMeta['zones']))
  ? $planMeta['zones'] : array();
$plan['advice'] = (!empty($planMeta['advice'] ?? null) && is_array($planMeta['advice']))
  ? $planMeta['advice'] : array(
    'Разминка и заминка до каждой тренировки, темпы — только из таблицы.',
    'Питание и питьё на длинных — репетировать заранее, до 30 мин.',
    'Сон 7-8 часов; если пульс покоя выше нормы — день отдыха вместо бега.',
    'Силовая, ОФП, растяжка и СБУ — отдельно от плана: в дни отдыха, 1-2 раза '
      . 'в неделю, без ударной нагрузки на ноги; в план они не входят.',
    'Кросс или плавание вместо бегового дня при усталости / плохой погоде.',
  );

/* --- Режим «Алгоритм»: оценка, зоны, советы — детерминированно --- */
if ($algoNoAnchor) {
  /* новичок без недавнего результата: темпы словесные, зоны пульса — по
     возрасту, якорь для пересчёта набирается контрольным тестом недели 1 */
  $algoZonesP = null;
  $plan['summary'] = 'План построен без недавнего результата: объёмы и фазы'
    . ' рассчитаны сервером, темпы — словесно (по усилию и пульсу). В первые'
    . ' 2 недели вписан контрольный тест 5К (или 30 мин на максимум): после'
    . ' него повторите генерацию с результатом теста в поле «Недавний'
    . ' старт» — план пересчитается с точными темпами.';
  /* лист «Зоны и советы»: числовых зон Ригеля нет — словесные строки */
  $plan['zones'] = array();
  $plan['advice'] = array(
    'В первые 2 недели в плане — контрольный тест 5К с усилием'
      . ' (или бег 30 минут на максимум времени): это замена недавнего старта.',
    'После теста сгенерируйте план повторно, указав результат теста в поле'
      . ' «Недавний старт» — все темпы пересчитаются точными цифрами.',
    'До точных темпов: лёгкий бег «на разговор», качественные — «комфортно-'
      . 'тяжело», интервалы/ускорения — коротко и не в полный отказ.',
    'Лёгкие дни — действительно легко: объём важнее скорости.',
    'Разминка и заминка до каждой качественной; страйды — после лёгких.',
    'Сон 7-8 часов; пульс покоя выше нормы — день отдыха вместо бега.',
    'Силовая, ОФП, растяжка и СБУ — отдельно от плана, в дни отдыха.',
  );
} else {
  $algoZonesP = algo_zones($rrKm, $rrTimeS);          /* E/M/T/I/R, сек/км */
  $algoFcS = algo_riegel($rrTimeS, $rrKm, $dist);     /* прогноз на дистанцию цели */
  $algoFcTxt = $algoFcS >= 3600
    ? sprintf('%d:%02d:%02d', (int)floor($algoFcS / 3600),
        (int)floor($algoFcS % 3600 / 60), (int)($algoFcS % 60))
    : algo_fmt_pace($algoFcS);
  $plan['summary'] = 'Прогноз по недавнему результату ('
    . ($data['recent_race']['txt'] ?: '—') . '): ~' . $algoFcTxt . ' на '
    . $dist . ' км. Фазы и объёмы рассчитаны сервером (рост ≤10% в неделю,'
    . ' разгрузочная каждая 4-я, тэйпер), темповые зоны — по формуле Ригеля,'
    . ' содержание недель — по алгоритму подготовки: база → конструкция'
    . ' → специализация → подводка.';
  /* лист «Зоны и советы» в этом режиме строится напрямую от $algoZonesP */
  $plan['zones'] = array();
  $plan['advice'] = array(
    'Зоны рассчитаны по формуле Ригеля от недавнего результата: после нового'
      . ' контрольного старта пересчитайте их (можно повторной генерацией).',
    'Лёгкие дни — действительно легко (разговорный темп): объём важнее скорости.',
    'Разминка и заминка до каждой качественной; страйды — после лёгких.',
    'Питание и питьё на длинных — репетировать заранее.',
    'Сон 7-8 часов; пульс покоя выше нормы — день отдыха вместо бега.',
    'Силовая, ОФП, растяжка и СБУ — отдельно от плана, в дни отдыха.',
  );
  if ($targetTime !== ''
      && preg_match('/^(\d{1,2}):([0-5]\d)(?::([0-5]\d))?$/', $targetTime, $ttM)) {
    $ttS = ((int)$ttM[1] * 60 + (int)$ttM[2]) * 60 + (!empty($ttM[3]) ? (int)$ttM[3] : 0);
    if ($ttS > 0 && $ttS < 0.95 * $algoFcS) {
      $algoNotes[] = 'цель (' . $targetTime . ') быстрее прогноза по текущей'
        . ' форме (' . $algoFcTxt . ') — реалистичность цели проверьте'
        . ' на контрольном старте 5-10 км';
    }
  }
}

/* --- Даты всех стартов: промежуточные + целевая ---------------- */
/* в эти дни план покажет сам старт, а не произвольную тренировку */
$raceOut = array();
foreach ($raceMid as $rmd => $rml) { $raceOut[$rmd] = $rml; }
if ($raceTs !== false && $raceDate !== '') {
  $raceOut[$raceDate] = 'Целевой старт — ' . $dist . ' км';
  /* в ячейке км у дня целевой гонки — точная дистанция (42.2 и пр.):
     пустая ячейка читалась как пропуски тренировки */
  $raceKmByDate[$raceDate] = $dist;
}
/* накануне каждого старта — детерминированная короткая закрутка:
   модель в реальном прогоне ставила перед промежуточным стартом
   длинную «Д 14 км», это запрещаем на уровне сшивки */
$raceEve = array();
foreach ($raceOut as $rmd => $rml) {
  $evTs = strtotime($rmd);
  if ($evTs !== false) {
    $evTs -= 86400;
    if ($evTs >= $startTs0 - 3 * 86400) { $raceEve[date('Y-m-d', $evTs)] = $rml; }
  }
}
/* три дня отдыха после каждого промежуточного старта; после целевой
   гонки вместо этого идёт целая восстановительная неделя */
$raceQuiet = array();      /* −3 и −2 дня до старта: только лёгкий бег */
foreach ($raceOut as $rmd => $rml) {
  $qTs = strtotime($rmd);
  if ($qTs === false) { continue; }
  for ($dQ = 3; $dQ >= 2; $dQ--) {
    $tsQ = $qTs - $dQ * 86400;
    if ($tsQ >= $startTs0 - 3 * 86400) { $raceQuiet[date('Y-m-d', $tsQ)] = $rml; }
  }
}
$raceRest = array();
foreach ($raceMid as $rmd => $rml) {
  $rrTs = strtotime($rmd);
  if ($rrTs === false) { continue; }
  for ($dR = 1; $dR <= 3; $dR++) {
    $raceRest[date('Y-m-d', $rrTs + $dR * 86400)] = $rml;
  }
}

/* --- Календарь недель: даты и число дней задаёт сервер --------- */
$wk = $detWk;
$cntW = count($wk);

/* число дней и календарь каждой недели: день j = start + j */
for ($i = 0; $i < $cntW; $i++) {
  $ts = strtotime($wk[$i]['start']);
  if ($ts === false) { $ts = time(); $wk[$i]['start'] = date('Y-m-d', $ts); }
  $len = 7;
  if ($i === $cntW - 1) {
    if ($raceTs && $raceTs >= $ts) {
      $len = (int)floor(($raceTs - $ts) / 86400) + 1;
    }
  } elseif (isset($wk[$i+1]['start'])) {
    $nxt = strtotime($wk[$i+1]['start']);
    if ($nxt && $nxt > $ts) { $len = min(7, (int)ceil(($nxt - $ts) / 86400)); }
  }
  $len = max(1, min(7, $len));
  $cal = array();
  for ($j = 0; $j < $len; $j++) {
    $dayTs = $ts + $j * 86400;
    $cal[] = array(
      'date' => date('Y-m-d', $dayTs),
      'day' => ru_wd($dayTs),
      /* день старта — тренировочный только в дату гонки (в своей неделе);
         даты промежуточных стартов — тоже тренировочные; три дня после
         промежуточного старта — принудительный отдых */
      'train' => !isset($raceRest[date('Y-m-d', $dayTs)])
        && (in_array((int)date('N', $dayTs), $trainDays, true)
        || isset($raceOut[date('Y-m-d', $dayTs)])),
    );
  }
  $wk[$i]['_cal'] = $cal;
}

/* ================================================================
   РЕЖИМ «АЛГОРИТМ (без ИИ)» — порт Work/plan_generator
   anketa → темповые зоны по Ригелю от недавнего результата → пул
   сессий по фазе × дистанции → раскладка недели. Календарь, фазы,
   объёмы, старты и перерывы — общие серверные ($wk/$detWk); алгоритм
   ставит содержание дней вместо модели. Слои ИИ-сшивки (D FIX и пр.)
   сюда не переносятся: алгоритм детерминирован сам.
   ================================================================ */

/* Темп сек/км → 'M:SS' */
function algo_fmt_pace($s) {
  $s = (int)round((float)$s);
  $m = (int)floor($s / 60);
  $ss = $s % 60;
  if ($ss === 60) { $m++; $ss = 0; }
  return $m . ':' . sprintf('%02d', $ss);
}
/* Прогноз по формуле Ригеля: время tS (сек) на dFrom км → на dTo км */
function algo_riegel($tS, $dFrom, $dTo, $exp = 1.06) {
  return $tS * pow($dTo / $dFrom, $exp);
}
/* Темповые зоны E/M/T/I/R от недавнего результата (zones.py):
   E = МП+60..90 с/км, M = МП, T = прогноз 10К+12, I = прогноз 3К,
   R = прогноз 1500 м. Возвращает список сек/км. */
function algo_zones($rrKm, $rrTimeS) {
  $mp  = algo_riegel($rrTimeS, $rrKm, 42.195) / 42.195;
  $p10 = algo_riegel($rrTimeS, $rrKm, 10.0) / 10.0;
  $p3  = algo_riegel($rrTimeS, $rrKm, 3.0, 1.08) / 3.0;
  $p15 = algo_riegel($rrTimeS, $rrKm, 1.5, 1.10) / 1.5;
  return array(
    array('code' => 'E', 'name' => 'лёгкий', 'pace' => $mp + 60, 'range_to' => $mp + 90),
    array('code' => 'M', 'name' => 'целевой темп старта', 'pace' => $mp, 'range_to' => $mp),
    array('code' => 'T', 'name' => 'порог', 'pace' => $p10 + 12, 'range_to' => $p10 + 12),
    array('code' => 'I', 'name' => 'интервалы (VO2max)', 'pace' => $p3, 'range_to' => $p3),
    array('code' => 'R', 'name' => 'скорость (повторения)', 'pace' => $p15, 'range_to' => $p15),
  );
}
/* Дистанция цели → ключ пула сессий; 15К и кастом — к ближайшей из 5/10/21,1/42,2 */
function algo_dist_key_of($km) {
  $table = array(
    array('10k', 10.0), array('5k', 5.0), array('half', 21.1), array('marathon', 42.2),
  );
  foreach ($table as $t) { if (abs($km - $t[1]) < 1.0) { return $t[0]; } }
  $best = '10k'; $bd = PHP_INT_MAX;
  foreach ($table as $t) {
    $d = abs($t[1] - $km);
    if ($d < $bd) { $bd = $d; $best = $t[0]; }
  }
  return $best;
}
/* Пик лонга и тапер-доли лонга по дистанции (volume.py) */
function algo_long_peak($key) {
  $peaks = array('5k' => 12, '10k' => 16, 'half' => 20, 'marathon' => 33);
  return $peaks[$key];
}
/* Пул качественных сессий: фаза × дистанция (sessions.py).
   Возвращает список ['type' (код клуба), 'zone', 'workout', 'est_km'];
   {E}{M}{T}{I}{R}{race} в тексте подставляются темпами из зон. */
function algo_quality_pool($ph, $key) {
  if ($ph === 'base' || $ph === 'recovery') {
    return array(
      array('type' => 'Ф', 'zone' => 'E', 'est_km' => 8,
        'workout' => 'Фартлек E: 8×1 мин быстро / 2 мин лёгкий джог, всего ~8 км'),
      array('type' => 'Ф', 'zone' => 'E', 'est_km' => 6,
        'workout' => 'Лёгкий бег 6 км + страйды 6×20 сек'),
    );
  }
  if ($ph === 'taper') {
    $op = array(
      '5k' => array('type' => 'И', 'zone' => 'I', 'est_km' => 7,
        'workout' => 'Открывашка: 3×1000 в гоночном темпе ({I}/км), отдых 400 м джог'),
      '10k' => array('type' => 'И', 'zone' => 'I', 'est_km' => 8,
        'workout' => 'Открывашка: 5К в целевом темпе ({race}/км)'),
      'half' => array('type' => 'П', 'zone' => 'T', 'est_km' => 9,
        'workout' => 'Открывашка: 2×3К в темпе цели ({race}/км), отдых 4 мин'),
      'marathon' => array('type' => 'ЦМТ', 'zone' => 'M', 'est_km' => 7,
        'workout' => 'Открывашка: 3К в целевом темпе ({M}/км) + 2К лёгкий'),
    );
    return array($op[$key]);
  }
  $pool = array(
    'build1' => array(
      '5k' => array(
        array('type' => 'И', 'zone' => 'I', 'est_km' => 10,
          'workout' => 'Интервалы I: 5×1000 в {I}/км, отдых 2-3 мин лёгкого'),
        array('type' => 'П', 'zone' => 'T', 'est_km' => 9,
          'workout' => 'Темпо T: 2×15 мин в {T}/км (отдых 3 мин)'),
      ),
      '10k' => array(
        array('type' => 'П', 'zone' => 'T', 'est_km' => 12,
          'workout' => 'Темпо T: 2×20 мин в {T}/км (отдых 3 мин)'),
        array('type' => 'И', 'zone' => 'I', 'est_km' => 10,
          'workout' => 'Интервалы I: 6×1000 в {I}/км, отдых 400 м джог'),
      ),
      'half' => array(
        array('type' => 'П', 'zone' => 'T', 'est_km' => 14,
          'workout' => 'Темпо T: 40-50 мин в {T}/км (или 3×15 мин)'),
        array('type' => 'П', 'zone' => 'T', 'est_km' => 12,
          'workout' => 'Темпо-прогрессия: 25 мин ровно + 15 мин в {T}/км'),
      ),
      'marathon' => array(
        array('type' => 'П', 'zone' => 'T', 'est_km' => 13,
          'workout' => 'Темпо T: 45 мин в {T}/км (или 3×15 мин)'),
        array('type' => 'П', 'zone' => 'T', 'est_km' => 12,
          'workout' => 'Круиз-интервалы T: 2×20 мин в {T}/км (отдых 3 мин)'),
      ),
    ),
    'build2' => array(
      '5k' => array(
        array('type' => 'И', 'zone' => 'I', 'est_km' => 11,
          'workout' => 'Интервалы I: 6-8×800 в {I}/км, отдых 2 мин'),
        array('type' => 'И', 'zone' => 'R', 'est_km' => 8,
          'workout' => 'Повторения R: 8×400 в {R}/км, отдых 400 м джог'),
        array('type' => 'И', 'zone' => 'I', 'est_km' => 8,
          'workout' => 'Репетиция гонки: 3К в целевом темпе ({race}/км)'),
      ),
      '10k' => array(
        array('type' => 'И', 'zone' => 'I', 'est_km' => 13,
          'workout' => 'Интервалы I: 5×1600 в {I}/км, отдых 2-3 мин'),
        array('type' => 'И', 'zone' => 'I', 'est_km' => 12,
          'workout' => 'Связки: 2×(3К в {I}/км + 1К в {T}/км)'),
        array('type' => 'И', 'zone' => 'I', 'est_km' => 10,
          'workout' => 'Репетиция гонки: 5К в целевом темпе ({race}/км)'),
      ),
      'half' => array(
        array('type' => 'П', 'zone' => 'T', 'est_km' => 15,
          'workout' => 'ТР-связки: 2×5К в темпе цели ({race}/км), отдых 4 мин'),
        array('type' => 'П', 'zone' => 'T', 'est_km' => 14,
          'workout' => 'Репетиция гонки: 10К в целевом темпе ({race}/км)'),
      ),
      'marathon' => array(
        array('type' => 'ЦМТ', 'zone' => 'M', 'est_km' => 18,
          'workout' => 'Связка: 3К в {T}/км → 5К лёгкий → 8К в целевом темпе ({M}/км)'),
        array('type' => 'П', 'zone' => 'T', 'est_km' => 11,
          'workout' => 'Темпо T: 40 мин в {T}/км'),
      ),
    ),
  );
  return $pool[$ph][$key];
}
/* Описание лонга по фазе × дистанции (sessions.py LONG_OVERRIDE) */
function algo_long_text($ph, $key, $km, $pt, $noAnchor = false) {
  $def = 'Длительный бег {km} км в зоне E ({E}/км)';
  $ov = array(
    'build1|half' => 'Длительный {km} км (E), финальные 20 мин в {T}/км',
    'build1|marathon' => 'Длительный {km} км, из них 8-12К в темпе целевого старта ({M}/км)',
    'build2|half' => 'Длительный {km} км с блоком 6-8К в темпе цели ({T}/км)',
    'build2|marathon' => 'Длительный {km} км, из них 10-15К в темпе целевого старта ({M}/км)',
  );
  $tpl = isset($ov[$ph . '|' . $key]) ? $ov[$ph . '|' . $key] : $def;
  return str_replace('{km}', (string)$km,
    $noAnchor ? algo_verbal_txt($tpl, $pt) : strtr($tpl, $pt));
}

/* «X/км» → «X»: у словесных темпов суффикс «/км» не нужен */
function algo_verbal_txt($txt, $pt) {
  return strtr(str_replace(
    array('{E}/км', '{T}/км', '{M}/км', '{I}/км', '{R}/км', '{race}/км'),
    array('{E}', '{T}', '{M}', '{I}', '{R}', '{race}'),
    $txt), $pt);
}

/* --- Сборка недель алгоритмом: слоты вместо модели ------------- */
function algo_build_plan(
  $wk, $specialWeeks, $recWeekN, $raceOut, $raceEve, $raceQuiet, $raceRest,
  $raceKmByDate, $raceDateTs, $raceDateStr, $trainDays, $kmAnketa,
  $dist, $pt, &$algoNotes, $noAnchor = false
) {
  $key = algo_dist_key_of($dist);
  $peakLong = algo_long_peak($key);
  $nBuild = 0;
  foreach ($wk as $w) {
    if (in_array($w['period'], array('База I', 'База II', 'Специальная'), true)) { $nBuild++; }
  }
  /* тапер-недели без самой стартовой недели: им достаются доли пика лонга */
  $tiperList = array();
  foreach ($wk as $w) {
    if ($w['period'] === 'Подводка'
        && !($raceDateTs !== false && $w['_cal'][0]['date'] !== ''
          && strtotime($w['start']) <= $raceDateTs
          && strtotime($w['start']) + 7 * 86400 > $raceDateTs)) {
      $tiperList[] = $w['n'];
    }
  }
  $fracsAll = array(1 => array(0.45), 2 => array(0.70, 0.45), 3 => array(0.75, 0.55, 0.40));
  $fracs = $fracsAll[max(1, min(3, count($tiperList)))];

  $weeksOut = array();
  $longPrev = 0.0;
  $buildDone = 0;
  $tapDone = 0;
  $cntLong = 0;   /* недель с лонгом >35% объёма (после потолка) */
  $cntIr = 0;     /* недель с долей I/R >25% объёма */
  foreach ($wk as $w) {
    $calN = count($w['_cal']);
    $kW = isset($specialWeeks[$w['n']]) ? $specialWeeks[$w['n']] : array();
    $isVacWk = in_array('vacation', $kW, true);
    $isRecWk = ($recWeekN > 0 && $w['n'] === $recWeekN);
    $isRaceWk = $raceDateTs !== false
      && strtotime($w['start']) <= $raceDateTs
      && strtotime($w['start']) + 7 * 86400 > $raceDateTs;
    $ph = 'base';
    switch ($w['period']) {
      case 'Восстановление': $ph = 'recovery'; break;
      case 'База I': $ph = 'base'; break;
      case 'База II': $ph = 'build1'; break;
      case 'Специальная': $ph = 'build2'; break;
      case 'Подводка': $ph = 'taper'; break;
    }
    $isDeload = (strpos((string)$w['focus'], 'разгрузочная') === 0);
    $vol = (float)$w['volume_km'];
    $buildPh = in_array($ph, array('base', 'build1', 'build2'), true) && !$isDeload;

    /* --- 1. Пины: отдых перерывов/рек-недели, старты и окрестности --- */
    $daysOut = array();
    $pinned = array();
    $slotDays = array();   /* ISO дня => индекс календаря */
    foreach ($w['_cal'] as $j => $c) {
      $dIso = (int)date('N', strtotime($c['date']));
      if ($isRecWk || $isVacWk) {
        $daysOut[$j] = array(
          'day' => $c['day'], 'date' => $c['date'], 'type' => 'отдых',
          'workout' => '', 'distance_km' => '', 'intensity' => '',
          'comment' => $isRecWk ? 'неделя отдыха после целевого старта'
            : 'перерыв в тренировках',
        );
        $pinned[$j] = true;
        continue;
      }
      if (isset($raceOut[$c['date']])) {
        $pinned[$j] = true;
        $rKmOut = isset($raceKmByDate[$c['date']])
          ? (fmod((float)$raceKmByDate[$c['date']], 1.0) === 0.0
            ? (int)$raceKmByDate[$c['date']] : round((float)$raceKmByDate[$c['date']], 1))
          : '';
        $daysOut[$j] = array(
          'day' => $c['day'], 'date' => $c['date'], 'type' => 'Д',
          'workout' => 'СТАРТ: ' . $raceOut[$c['date']],
          'distance_km' => $rKmOut,
          'intensity' => ($w['period'] === 'Подводка' && strpos($raceOut[$c['date']], 'Целевой') === 0)
            ? 'целевой темп' . ($pt['{race}'] !== '' ? ' ' . $pt['{race}'] : '')
            : 'контрольный темп',
          'comment' => 'ровный консервативный старт: первые километры чуть'
            . ' медленнее целевого темпа, разминка отдельной лёгкой пробежкой',
        );
        continue;
      }
      if (isset($raceQuiet[$c['date']]) && $c['train']) {
        $pinned[$j] = true;
        $daysOut[$j] = array(
          'day' => $c['day'], 'date' => $c['date'], 'type' => 'ВОС',
          'workout' => 'лёгкий бег — разгрузка перед стартом', 'distance_km' => 6,
          'intensity' => 'зона 1',
          'comment' => 'за 2-3 дня до старта (' . $raceQuiet[$c['date']]
            . '): только лёгкий, ни длинного, ни интенсивного',
        );
        continue;
      }
      if (isset($raceEve[$c['date']]) && $c['train']) {
        $pinned[$j] = true;
        $daysOut[$j] = array(
          'day' => $c['day'], 'date' => $c['date'], 'type' => 'ВОС',
          'workout' => 'лёгкая закрутка перед стартом', 'distance_km' => 3,
          'intensity' => 'зона 1',
          'comment' => 'накануне старта (' . $raceEve[$c['date']]
            . '): никакого длинного и интенсивного',
        );
        continue;
      }
      if (isset($raceRest[$c['date']])) {
        $pinned[$j] = true;
        $daysOut[$j] = array(
          'day' => $c['day'], 'date' => $c['date'], 'type' => 'отдых',
          'workout' => '', 'distance_km' => '', 'intensity' => '',
          'comment' => 'восстановление после старта (' . $raceRest[$c['date']] . ')',
        );
        continue;
      }
      if ($c['train']) { $slotDays[$dIso] = $j; }
    }

    /* --- 2. Слоты: лонг → качественные → easy-добор --------------- */
    $nSlots = count($slotDays);
    $nQual = ($nSlots <= 4 || $ph === 'taper' || $ph === 'recovery' || $isDeload)
      ? 1 : 2;
    if (isset($algoLongKm)) { unset($algoLongKm); }
    if (!$isRecWk && !$isVacWk && $nSlots > 0) {
      $slotIsos = array_keys($slotDays);
      sort($slotIsos);
      /* в разгрузочной неделе (−30% объёма) — базовый пул intensity:
         «связка 18 км» в неделю срезки объёма не ставится */
      $pool = algo_quality_pool($isDeload ? 'base' : $ph, $key);
      /* план новичка без якоря: в первой неделе качественный слот —
         контрольный тест (шаг 1 алгоритма), из него потом считаются зоны */
      $algotest = $noAnchor && $w['n'] === 1 && !$isDeload;
      if ($algotest) {
        $pool = array(array('type' => 'ЦМТ', 'zone' => 'M', 'est_km' => 8,
          'workout' => 'Контрольный тест: 5К с усилием (или бег 30 мин на'
            . ' максимум времени) — опора для пересчёта всех темпов'));
        $nQual = min(1, $nQual);
      }
      if ($isRaceWk) {
        /* стартовая неделя: лонг-слот уже занят пином СТАРТ; лёгкие слоты */
        $nQual = min(1, max(0, $nSlots - 1));
        $easyKm = max(2, min(14, (int)round(($vol - 15) / max(1, $nSlots - 1 - $nQual))));
        $qi = 0;
        foreach ($slotIsos as $iso) {
          $j = $slotDays[$iso];
          if ($qi < $nQual) {
            $s = $pool[0];
            $daysOut[$j] = array(
              'day' => $w['_cal'][$j]['day'], 'date' => $w['_cal'][$j]['date'],
              'type' => $s['type'],
              'workout' => $noAnchor
                ? algo_verbal_txt(str_replace('{km}', '5', $s['workout']), $pt)
                : strtr(str_replace('{km}', '5', $s['workout']), $pt),
              'distance_km' => min($s['est_km'], 5),
              'intensity' => ($s['zone'] === 'I' || $s['zone'] === 'R')
                ? 'зона 4' : 'зона 3',
              'comment' => 'открывающая лёгкая перед стартом',
            );
            $qi++;
          } else {
            $daysOut[$j] = array(
              'day' => $w['_cal'][$j]['day'], 'date' => $w['_cal'][$j]['date'],
              'type' => 'ВОС', 'workout' => 'Лёгкий бег ' . $easyKm . ' км',
              'distance_km' => $easyKm, 'intensity' => 'зона 1',
              'comment' => 'очень легко, за ' . (int)round((strtotime($raceDateStr)
                - strtotime($w['_cal'][$j]['date'])) / 86400) . ' дн до старта',
            );
          }
        }
        $weeksOut[] = array(
          'n' => $w['n'], 'start' => $w['start'], 'period' => $w['period'],
          'volume_km' => $w['volume_km'], 'focus' => $w['focus'],
          'days' => $daysOut,
        );
        continue;
      }
      /* лонг — в последний тренировочный день; качественные — с зазором
         ≥ 48 ч от лонга и друг от друга, ближе к (лонг − 3 дня) */
      $longIso = $slotIsos[count($slotIsos) - 1];
      /* кривая лонга: рост по строительным неделям, тапер — доли пика */
      if ($ph === 'taper') {
        $frac = $tapDone < count($fracs) ? $fracs[$tapDone] : 0.45;
        $algoLongKm = max(6, (int)round($peakLong * $frac));
        $tapDone++;
      } elseif ($buildPh) {
        $t = $nBuild > 1 ? ($buildDone / ($nBuild - 1)) : 0;
        $lg = max(8.0, 0.30 * max(1.0, (float)$kmAnketa))
          + $t * ($peakLong - max(8.0, 0.30 * max(1.0, (float)$kmAnketa)));
        $algoLongKm = (int)round($lg);
        $buildDone++;
      } else {   /* recovery / deload: держим прошлый уровень */
        $algoLongKm = $longPrev > 0 ? (int)round($longPrev) : max(6, (int)round(0.3 * (float)$kmAnketa));
      }
      /* ограничитель лонга: ≤35% недельного объёма во ВСЕХ неделях —
         в разгрузочные и подводные недели режется сам лонг */
      if ($vol > 0.001) { $algoLongKm = min($algoLongKm, (int)floor(0.35 * $vol)); }
      $algoLongKm = max(4, min($algoLongKm, (int)$vol));
      $longPrev = $algoLongKm;
      if ($vol > 0 && $algoLongKm > 0.37 * $vol) { $cntLong++; }
      /* качественный пул и выбор дней */
      $cand = array_values(array_filter($slotIsos, function ($iso) use ($longIso) {
        return abs($iso - $longIso) >= 2;
      }));
      usort($cand, function ($a, $b) use ($longIso) {
        return abs($a - ($longIso - 3)) <=> abs($b - ($longIso - 3));
      });
      $qualIso = $nQual > 0 && count($cand) > 0 ? $cand[0] : -1;
      $qualIso2 = -1;
      if ($nQual > 1) {
        foreach ($cand as $c2) {
          if ($qualIso >= 0 && abs($c2 - $qualIso) >= 2 && $c2 !== $longIso) {
            $qualIso2 = $c2;
            break;
          }
          $qualIso2 = -1;
        }
      } else { $qualIso2 = -1; }
      if ($qualIso < 0 && $nQual > 0 && count($cand) === 0 && count($slotIsos) > 1) {
        /* зазора нет: берём самый дальний от лонга с замечанием */
        $qualIso = $slotIsos[0];
        $algoNotes[] = 'неделя ' . $w['n'] . ': качественная ближе 48 ч к лонгу '
          . '— при усталости замените её лёгким бегом';
      }
      /* --- 3. Наполнение слотов ------------------------------------- */
      /* Пул качественных обязан учитывать контрольный тест новичка:
         раньше он пересобирался здесь заново, и подменённый выше $pool
         (неделя 1 без якоря) в план не попадал — тест обещался в
         сводке и советах, но в тренировках его не было. */
      $qualPool = $algotest
        ? $pool
        : algo_quality_pool($isDeload ? 'base' : $ph, $key);
      /* порядок качественных: первый день — первая сессия пула,
         второй день — вторая */
      $qualMap = array();
      if ($qualIso >= 0) { $qualMap[(int)$qualIso] = 0; }
      if ($qualIso2 >= 0) { $qualMap[(int)$qualIso2] = 1; }
      $qualEst = 0;
      foreach ($qualMap as $qi2) {
        $qualEst += $qualPool[min($qi2, count($qualPool) - 1)]['est_km'];
      }
      $easyIsos = array();
      foreach ($slotIsos as $iso) {
        if ((int)$iso === (int)$longIso || isset($qualMap[(int)$iso])) { continue; }
        $easyIsos[] = $iso;
      }
      $easyKm = max(2, min(14, (int)round(($vol - $algoLongKm - $qualEst)
        / max(1, count($easyIsos)))));
      /* добор недобора объёма: при 3 тренировках и большом плане easy-дней
         может не быть вовсе (или они у потолка 14 км) — остаток уходит
         в лонг до 45% объёма; если и это не помогло — честное примечание */
      $deficit = $vol - $algoLongKm - $qualEst - $easyKm * count($easyIsos);
      if ($deficit > 0 && $vol > 0.001) {
        $addLong = min($deficit, max(0, (int)floor(0.45 * $vol) - $algoLongKm));
        $algoLongKm += $addLong;
        $longPrev = $algoLongKm;
        $deficit -= $addLong;
        if ($deficit >= 3) {
          $algoNotes[] = 'неделя ' . $w['n'] . ': объём недели на '
            . (int)round($deficit) . ' км меньше планового — при 3 тренировках'
            . ' в неделю такой километраж не набирается';
        }
      }
      foreach ($slotIsos as $iso) {
        $j = $slotDays[$iso];
        if ((int)$iso === (int)$longIso) {
          $daysOut[$j] = array(
            'day' => $w['_cal'][$j]['day'], 'date' => $w['_cal'][$j]['date'],
            'type' => 'Д',
            'workout' => algo_long_text($ph, $key, $algoLongKm, $pt, $noAnchor),
            'distance_km' => $algoLongKm,
            'intensity' => ($ph === 'build2' || $ph === 'build1')
              ? 'зона 2-3 (часть в темпе)' : 'зона 1-2',
            'comment' => '',
          );
        } elseif (isset($qualMap[(int)$iso])) {
          $s = $qualPool[min($qualMap[(int)$iso], count($qualPool) - 1)];
          $txt = $noAnchor ? algo_verbal_txt($s['workout'], $pt)
            : strtr($s['workout'], $pt);
          $daysOut[$j] = array(
            'day' => $w['_cal'][$j]['day'], 'date' => $w['_cal'][$j]['date'],
            'type' => $s['type'],
            'workout' => $txt . ', всего ~' . $s['est_km'] . ' км',
            'distance_km' => $s['est_km'],
            'intensity' => ($s['zone'] === 'I' || $s['zone'] === 'R')
              ? 'зона 4' : ($s['zone'] === 'M'
                ? ($noAnchor ? 'зона 3, «комфортно-тяжело»'
                    : 'зона 3, целевой темп')
                : 'зона 3'),
            'comment' => $noAnchor && $w['n'] === 1
              ? 'результат теста введите при повторной генерации'
              : '',
          );
        } else {
          $daysOut[$j] = array(
            'day' => $w['_cal'][$j]['day'], 'date' => $w['_cal'][$j]['date'],
            'type' => 'ВОС',
            'workout' => 'Лёгкий бег ' . $easyKm . ' км'
              . ($noAnchor ? ', разговорный темп' : ' (' . $pt['{E}'] . '/км)'),
            'distance_km' => $easyKm, 'intensity' => 'зона 1-2',
            'comment' => 'разговорный темп, дыхание свободное',
          );
        }
      }
      /* валидация шага 6: зазор качественных и доля I+R */
      if ($qualIso >= 0 && $qualIso2 >= 0 && abs($qualIso - $qualIso2) < 2) {
        $algoNotes[] = 'неделя ' . $w['n'] . ': две качественные подряд';
      }
      $irKm = 0;
      foreach ($daysOut as $rV) {
        if (strpos((string)$rV['intensity'], 'зона 4') === 0) {
          $irKm += is_numeric($rV['distance_km']) ? (float)$rV['distance_km'] : 0;
        }
      }
      if ($vol > 0 && $irKm / $vol > 0.25) { $cntIr++; }
    }
    /* добивка: тренировочный день без строки → лёгкий бег (страховка) */
    foreach ($w['_cal'] as $j => $c) {
      if (!isset($daysOut[$j])) {
        $daysOut[$j] = $c['train']
          ? array(
              'day' => $c['day'], 'date' => $c['date'], 'type' => 'ВОС',
              'workout' => 'Лёгкий бег', 'distance_km' => 5,
              'intensity' => 'зона 1-2', 'comment' => '',
            )
          : array(
              'day' => $c['day'], 'date' => $c['date'], 'type' => 'отдых',
              'workout' => '', 'distance_km' => '', 'intensity' => '', 'comment' => '',
            );
      }
      ksort($daysOut);
    }
    $weeksOut[] = array(
      'n' => $w['n'], 'start' => $w['start'], 'period' => $w['period'],
      'volume_km' => $w['volume_km'], 'focus' => $w['focus'],
      'days' => array_values($daysOut),
    );
  }
  /* валидация: глубина подводки, минимум недель, агрегаты по неделям */
  if ($cntLong > 0) {
    $algoNotes[] = 'лонг больше 37% недельного объёма в ' . $cntLong
      . ' неделях — такой план стоит вести с тренером или снизить объём недели';
  }
  if ($cntIr > 0) {
    $algoNotes[] = 'доля интенсивности I/R выше 25% объёма в ' . $cntIr
      . ' неделях — для низкого км/нед это ожидаемо, следите за реакцией ног';
  }
  $buildVols = array();
  foreach ($weeksOut as $w) {
    if (in_array($w['period'], array('База I', 'База II', 'Специальная'), true)) {
      $buildVols[] = (float)$w['volume_km'];
    }
  }
  $minWk = array('5k' => 8, '10k' => 10, 'half' => 12, 'marathon' => 16);
  if (count($weeksOut) > 0 && count($weeksOut) < $minWk[$key]) {
    $algoNotes[] = 'недель в плане меньше нормы для дистанции ('
      . $minWk[$key] . ') — цель или срок стоит пересмотреть';
  }
  if (count($buildVols) > 0) {
    $peakV = max($buildVols);
    $lastVol = (float)$weeksOut[count($weeksOut) - 2]['volume_km'];
    if ($lastVol > 0 && $peakV > 0 && $lastVol < 0.3 * $peakV) {
      $algoNotes[] = 'последняя беговая неделя глубже -70% от пика — '
        . 'подъём объёма в подводке можно смягчить';
    }
  }
  return $weeksOut;
}

/* --- ФАЗА 2: дни по кускам недель ------------------------------ */
if (!$algo) {
$chunkSize = 7;   /* недель на один вызов модели */
$daysByWeek = array();
for ($off = 0; $off < $cntW; $off += $chunkSize) {
  if ($off > 0) { sleep(2); }   /* не долбить API частыми вызовами: бывает 429 */
  $slice = array_slice($wk, $off, $chunkSize);
  $metaList = array();
  foreach ($slice as $w) {
    $calList = array();
    foreach ($w['_cal'] as $c) {
      $calList[] = array(
        'день' => $c['day'], 'дата' => $c['date'],
        'тренировка' => $c['train'] ? true : false,
      );
    }
    /* гонки этой недели: модель должна видеть, где именно старт */
    $racesWk = array();
    $tW = strtotime($w['start']);
    if ($tW !== false) {
      for ($rj = 0; $rj < 7; $rj++) {
        $rIso = date('Y-m-d', $tW + $rj * 86400);
        if (isset($raceOut[$rIso])) {
          $racesWk[] = array('дата' => $rIso, 'что' => $raceOut[$rIso]);
        }
      }
    }
    $metaList[] = array(
      'n' => $w['n'], 'start' => $w['start'],
      'volume_km' => $w['volume_km'], 'focus' => $w['focus'],
      'фаза' => $w['period'],
      'тренировочных_дней' => count(array_filter($w['_cal'],
        function ($c) { return $c['train']; })),
      'days_total' => count($w['_cal']),
      'гонки' => $racesWk,
      'дни' => $calList,
    );
  }
  $raceInChunk = false;
  if ($raceTs) {
    foreach ($slice as $w) {
      $t = strtotime($w['start']);
      if ($raceTs >= $t && $raceTs < $t + 7 * 86400) { $raceInChunk = true; }
    }
  }
  $chunkPayload = array(
    'model' => $gigaModel,
    'messages' => array(
      array('role' => 'system',
        'content' => $SYSTEM_PROMPT . "\n\n" . $FORMAT_RULES . "\n\n"
                   . $ZONE_RULES . "\n\n" . $DAY_RULES . "\n\n" . $CHUNK_SCHEMA),
      array('role' => 'user',
        'content' => 'Данные (JSON): ' . json_encode(array(
          'Бегун' => $u,
          'Гонка' => array(
            'name' => (isset($plan['race']['name']) ? $plan['race']['name'] : 'подготовка'),
            'distance_km' => $dist, 'date' => $raceDate ?: '',
            'в_этом_куске' => $raceInChunk),
          'Недель' => $metaList,
        ), JSON_UNESCAPED_UNICODE) .
        "\nРаспиши дни только перечисленных недель по методике и объёму каждой недели." .
        "\nТренировки бывают ТОЛЬКО в днях с «тренировка: true» из списка дней недели;" .
        "\nих количество задано сервером и менять его нельзя — никаких добавленных" .
        "\nдней сверх отметки и никаких тренировок в дни с «тренировка: false»." .
        "\nДни с «тренировка: false» — type «отдых», distance_km 0, объём недели" .
        "\nраскидывай только по тренировочным дням; каждый тренировочный день —" .
        "\nс живым объёмом, никогда distance_km 0." .
        "\nВ неделе старта день гонки (последний день) — тоже тренировочный, тип «Д»." .
        "\nТемпы — строго из «Таблицы темпов» бегуна (она рассчитана сервером от" .
        "\nего целевого темпа и порогов); никаких других цифр темпа выдумывать нельзя," .
        "\nпульс — в границах пульсовой зоны из данных. В разгрузочной неделе" .
        "\n(«разгрузочная» в focus) — без И и ЦМТ, только лёгкие." .
        "\nВ неделях с пометкой отпуск/горы или перерыв — объём уже занижен сервером:" .
        "\nтолько лёгкий бег (ВОС/Д коротко), без ЦМТ/П/И/Ф; поход в горах считается" .
        "\nдополнительной нагрузкой. В неделе, где поле «гонки» непусто:" .
        "\nс даты гонки из поля «гонки» тренировка обязательно type «Д»," .
        "\nworkout «СТАРТ: <что>», comment о ровном консервативном старте;" .
        "\nза день до гонки — только отдых или закрутка до 5 км, НИКОГДА «Д»," .
        "\nбез интенсивности за 2-3 дня до гонки," .
        "\nпосле гонки — восстановительные дни." .
        "\nВ каждой неделе — ровно один длинный бег типа «Д» (в тренировочный день," .
        "\nобычно ближний к концу недели); в пиковых неделях он" .
        "\nдоходит до целевого длинного бега из данных, у старта резко укорачивается." .
        "\nСтруктура недели: после «Д» — ВОС или отдых; интенсивная (ЦМТ/П/И) максимум" .
        "\nодна-две и не подряд; только беговые типы: силовая/ОФП/СБУ/техника" .
        "\nв план не входят и вместо них лёгкий бег;" .
        "\nсумма дневных км должна быть близка к volume_km недели," .
        "\nи это действует С ПЕРВОЙ недели: текущий недельный объём бегуна" .
        "\nуже известен — никакой «раскачки» пустыми или нулевыми пробежками" .
        "\nв первые недели, каждый тренировочный день имеет конкретный км." .
        "\nКачественные работы ЧЕРЕДУЙТЕ и используйте все типы: фартлек «Ф»" .
        "\n(30-60 мин переменный: 1-3 мин темповых отрезков в зоне 3-4 + 1-2 мин" .
        "\nлёгкого), горки «Горка» (8-12 ускорений ~100 м в подъём, назад шагом)," .
        "\nинтервалы «И» и порог «П» — НЕЛЬЗЯ превращать все недели в фартлеки:" .
        "\nв Базе чередуйте Ф и Горку, в Базе II добавьте И и П, в Специальной" .
        "\nфазе основной объём качественной работы — ЦМТ, П и И, фартлек редко." .
        "\nПорог «П» описывай отрезками в МИНУТАХ, без «км» в описании: разминка" .
        "\nлёгким бегом, отрезки в пороговом темпе по 10-25 минут (между ними" .
        "\n2-3 мин лёгкого), заминка лёгким бегом. Суммарное время в пороге" .
        "\nдля марафона — от 20 минут к 60 по ходу плана; для более коротких" .
        "\nдистанций меньше (полумарафон к 40, 10 км к 25 минутам)." .
        "\nВ разгрузочных, отпускных и тэйпер-неделях интенсивность не ставить." .
        "\nВ КАЖДОЙ неделе, кроме восстановительной, разгрузочной, недель" .
        "\nотпуска/перерыва, тэйпера и недель со стартом, обязательно минимум" .
        "\nодна качественная работа: ЦМТ, П, И, Ф или Горка." .
        "\nЕсли volume_km не указан — определи его сам по прогрессии методики;" .
        "\nесли focus недели «неделя отдыха после целевого старта» — все дни" .
        "\ntype «отдых», distance_km 0, без исключений." .
        ($raceInChunk ? " Дата целевой гонки — " . $raceDate . ": в её день type «Д»,"
          . " workout «СТАРТ: Целевой старт — " . $dist . " км», comment о ровном"
          . " консервативном старте по целевому темпу из данных; накануне — отдых."
          . " Не давай интенсивность за 3 дня до гонки." : ''),
      ),
    ),
    'temperature' => 0.4,
    'max_tokens' => 6000,
  );
  $chunkRes = null;
  for ($att = 0; $att < 2; $att++) {
    list($c2, $e2) = call_model($chunkPayload);
    if ($e2) { continue; }   /* 401-ретрай внутри call_model; здесь — повтор целиком */
    $chunkRes = extract_days_json($c2);
    if ($chunkRes) { break; }
    if ($att === 0) {
      /* реальный случай из лога: «content» пустой при finish_reason stop —
         на повторном вызове явно требуем непустой строго-JSON ответ */
      $chunkPayload['messages'][1]['content'] .=
        "\nВАЖНО: твоё прошлое сообщение было пустым или нечитаемым."
        . " Верни только JSON по схеме, непустой, без текста вокруг.";
    }
  }
  if ($chunkRes) {
    foreach ($chunkRes['weeks'] as $wr) { $daysByWeek[$wr['n']] = $wr['days']; }
  } else {
    ai_log('CHUNK FAIL', 'Кусок недель ' . $metaList[0]['n'] . '–' .
      $metaList[count($metaList)-1]['n'] . ' не разобран');
  }
}

/* --- Сшивка: дни в недели, даты/день недели — серверные ---------- */
$plan['weeks'] = array();
$RUN_TYPES = array('ВОС', 'Д', 'ЦМТ', 'П', 'И', 'Ф', 'Горка');
/* дефолтные описания по типу — для строк, где модель не дала текст
   тренировки (в реальном прогоне отдавала «ВОС» пусто) */
$DEF_WORK = array(
  'ВОС'   => 'лёгкий бег ровным разговорным темпом',
  'Д'     => 'длительный бег ровным темпом, последние км чуть легче',
  'ЦМТ'   => 'бег в целевом темпе: 2-3 отрезка по 2-3 км через 2-3 мин лёгкого',
  'П'     => 'пороговый бег: разминка, отрезки в пороговом темпе в минутах, заминка',
  'И'     => 'интервалы: 6-8 отрезков по 400-800 м через 400 м лёгкого',
  'Ф'     => 'фартлек: 1-3 мин чуть быстрее целевого темпа, 1-2 мин лёгкий',
  'Горка' => '8-12 ускорений ~100 м в подъём, назад шагом',
);
$lastMaxWk = -99;   /* номер недели последнего «Д на максимум» */
foreach ($wk as $w) {
  $daysOut = array();
  $calN = count($w['_cal']);
  $got = isset($daysByWeek[$w['n']]) ? $daysByWeek[$w['n']] : array();
  $got = is_array($got) ? $got : array();
  $kW = isset($specialWeeks[$w['n']]) ? $specialWeeks[$w['n']] : array();
  /* модель вернула весь календарь (days_total) — маппим по позициям;
     вернула только тренировочные дни — раскладываем их по слотам подряд,
     а дни без календарной отметки «тренировка» всегда превращаем в отдых */
  $positional = count($got) === $calN;
  $ptr = 0;
  $trainFix = array();   /* индексы тренировочных слотов вне дат стартов */
  $pinned = array();     /* дни, строку которых сшили сами (старты и накануне) */
  $isVacWk = in_array('vacation', $kW, true);
  $isRecWk = ($recWeekN > 0 && $w['n'] === $recWeekN);
  for ($j = 0; $j < $calN; $j++) {
    $c = $w['_cal'][$j];
    /* перерыв в тренировках и неделя после целевого старта — все дни
       принудительный отдых: объёму модели здесь не верим (volume_km 0,
       слоты не в счёт, тренировок в перерыве нет вовсе) */
    if ($isRecWk || $isVacWk) {
      $daysOut[] = array(
        'day' => $c['day'], 'date' => $c['date'], 'type'   => 'отдых',
        'workout'=> '', 'distance_km' => '', 'intensity'   => '',
        'comment'     => $isRecWk ? 'неделя отдыха после целевого старта'
          : 'перерыв в тренировках',
      );
      continue;
    }
    /* даты стартов (промежуточных и целевого) не доверяем модели: в реальном
       прогоне она ставила в день промежуточного старта «отдых», а в день
       целевой гонки — «ВОС». Строку старта пишем сами, детерминированно */
    if (isset($raceOut[$c['date']])) {
      $pinned[$j] = true;
      /* в ячейке км — точная дистанция старта (21, 42.2 и пр.) */
      $rKmOut = isset($raceKmByDate[$c['date']])
        ? (fmod((float)$raceKmByDate[$c['date']], 1.0) === 0.0
          ? (int)$raceKmByDate[$c['date']] : round((float)$raceKmByDate[$c['date']], 1))
        : '';
      $daysOut[] = array(
        'day' => $c['day'],
        'date' => $c['date'],
        'type'   => 'Д',
        'workout'=> 'СТАРТ: ' . $raceOut[$c['date']],
        'distance_km' => $rKmOut,
        'intensity'   => $targetPace !== ''
          ? 'целевой темп ' . $targetPace : 'контрольный темп',
        'comment'     => 'ровный консервативный старт: первые километры чуть'
          . ' медленнее целевого темпа, разминка отдельной лёгкой пробежкой',
      );
      continue;
    }
    /* за 3 и 2 дня до старта — только лёгкий, никакой «Д» и интенсивных
       (модель ставила «Д 27 км» за несколько дней до промежуточного старта) */
    if (isset($raceQuiet[$c['date']]) && $c['train']) {
      $pinned[$j] = true;
      $daysOut[] = array(
        'day' => $c['day'],
        'date' => $c['date'],
        'type'   => 'ВОС',
        'workout'=> 'лёгкий бег — разгрузка перед стартом',
        'distance_km' => 6,
        'intensity'   => 'зона 1',
        'comment'     => 'за 2-3 дня до старта (' . $raceQuiet[$c['date']]
          . '): только лёгкий, ни длинного, ни интенсивного',
      );
      continue;
    }
    /* накануне старта — никогда длинного и интенсивного (реальный случай
       из плана: «Д 14 км» за день до промежуточного старта): если день
       тренировочный — только короткая закрутка */
    if (isset($raceEve[$c['date']]) && $c['train']) {
      $pinned[$j] = true;
      $daysOut[] = array(
        'day' => $c['day'],
        'date' => $c['date'],
        'type'   => 'ВОС',
        'workout'=> 'лёгкая закрутка перед стартом',
        'distance_km' => 3,
        'intensity'   => 'зона 1',
        'comment'     => 'накануне старта (' . $raceEve[$c['date']]
          . '): никакого длинного и интенсивного',
      );
      continue;
    }
    /* три дня после промежуточного старта — принудительный отдых:
       в этих датах календарь тренировок уже не ставит */
    if (isset($raceRest[$c['date']])) {
      $daysOut[] = array(
        'day' => $c['day'], 'date' => $c['date'], 'type'   => 'отдых',
        'workout'=> '', 'distance_km' => '', 'intensity'   => '',
        'comment'     => 'восстановление после старта (' . $raceRest[$c['date']] . ')',
      );
      continue;
    }
    $d = null;
    if ($c['train']) {
      if ($positional) {
        if (isset($got[$j]) && is_array($got[$j])) { $d = $got[$j]; }
      } else {
        while ($ptr < count($got) && !is_array($got[$ptr])) { $ptr++; }
        if ($ptr < count($got)) { $d = $got[$ptr++]; }
      }
    }
    $km = null;
    if ($d !== null) {
      foreach (array('distance_km', 'distance_kм', 'distance_km ') as $key) {
        if (isset($d[$key]) && is_numeric($d[$key])) { $km = $d[$key] + 0; break; }
      }
    }
    /* тренировочный слот ВСЕГДА остаётся тренировкой: сколько занятий в
       неделю задал бегун в анкете — столько и в разгрузочной, и в
       восстановительной неделе (в них снижаем объём и интенсивность,
       но не число тренировок). Слот с «отдых»/пусто от модели —
       детерминированный лёгкий бег с км из остатка недельного объёма */
    $tType = $c['train'] ? clean($d['type'] ?? '', 30) : 'отдых';
    $genKm = false;
    $isRest = !$c['train'] || $tType === '' || mb_strtolower($tType) === 'отдых';
    if ($isRest && $c['train']) {
      $tType = 'ВОС'; $isRest = false;
      $km = null; $genKm = true;
    }
    $forceEasy = false;
    if (!$isRest && !in_array($tType, $RUN_TYPES, true)) {
      /* «Сила»/«СБУ»/«техника» и прочие небеговые типы в план не входят:
         слот остаётся за лёгким бегом, объём модели не берём, чтобы
         не раздувать недельный объём; силовая — только в советы */
      $tType = 'ВОС'; $km = null; $forceEasy = true;
    }
    if ($isRest) { $tType = 'отдых'; }
    /* километраж тренировок округляем до целого (у строк стартов дистанция
       точная — 21.1 и 42.2 не округляем и не правим) */
    $kmOut = (!$isRest && $km !== null && $km > 0.001)
      ? (int)round($km) : '';
    /* фиксируем ЛЮБОЙ тренировочный слот: в него можно вставить лёгкий бег,
       если вся неделя вышла без беговых */
    if ($c['train']) { $trainFix[] = $j; }
    $daysOut[] = array(
      'day' => $c['day'],
      'date' => $c['date'],
      'type'   => $tType,
      'workout'=> $isRest ? '' : ($genKm ? 'лёгкий бег'
        : ($forceEasy
        ? 'лёгкий бег 20-30 мин (силовая/ОФП/СБУ в план не входят — только рекомендации)'
        : clean($d['workout']   ?? '', 200))),
      'distance_km' => $kmOut,
      'intensity'   => $isRest ? '' : ($genKm ? 'зона 1' : clean($d['intensity'] ?? '', 80)),
      /* перерыв в тренировках должен быть ВИДЕН в строках плана */
      'comment'     => !$isRest ? clean($d['comment'] ?? '', 200)
        : ($isVacWk ? 'перерыв в тренировках' : ''),
    );
  }
  /* пустой километраж у ЛЮБОЙ беговой строки недопустим: модель в реальном
     прогоне отдавала целые куски «ВОС» без км и без описания — дозаполняем
     из остатка недельного объёма; заодно даём дефолтное описание */
  $needKm = array();
  foreach ($daysOut as $ixK => $rK) {
    if (isset($pinned[$ixK])) { continue; }   /* у строк стартов км уже точный */
    if ($rK['type'] !== 'отдых' && in_array($rK['type'], $RUN_TYPES, true)
        && (!is_numeric($rK['distance_km']) || (float)$rK['distance_km'] <= 0)) {
      $needKm[] = $ixK;
    }
  }
  if (count($needKm) > 0) {
    $kmSum = 0.0;
    foreach ($daysOut as $rS) {
      if (is_numeric($rS['distance_km'])) { $kmSum += (float)$rS['distance_km']; }
    }
    $kmLeft = (float)$w['volume_km'] - $kmSum;
    $kmEach = max(3, min(14, (int)round($kmLeft / count($needKm))));
    foreach ($needKm as $nK) {
      $daysOut[$nK]['distance_km'] = $kmEach;
      if ($daysOut[$nK]['workout'] === ''
          || $daysOut[$nK]['workout'] === 'лёгкий бег') {
        $daysOut[$nK]['workout'] = $DEF_WORK[$daysOut[$nK]['type']];
      }
      if ($daysOut[$nK]['intensity'] === '' && $daysOut[$nK]['type'] === 'ВОС') {
        $daysOut[$nK]['intensity'] = 'зона 1';
      }
    }
    ai_log('KM FIX', 'неделя ' . $w['n'] . ': дозаполнен км у '
      . count($needKm) . ' тренировок (по ' . $kmEach . ' км)');
  }
  /* --- длинный бег: единственный «Д» обязателен в неделе --------- */
  /* модель пропускала «Д» целыми базовыми кусками плана (реальный прогон:
     первый длинный только в 21-й неделе): если в неделе нет «Д», самый
     длинный лёгкий день становится длительным */
  $volT = (int)round((float)$w['volume_km']);
  $capL = max(10, (int)round($volT * 0.4));   /* «Д» не выше 40% объёма */
  $capE = max(8, (int)round($volT * 0.3));    /* прочие беговые */
  $chunkK = max(1, (int)ceil($volT * 0.05));  /* шаг роста/срезки */
  if (in_array('race', $kW, true)) {
    $capL = max(8, (int)round($capL * 0.6));      /* в неделе старта длинный ещё короче */
  } elseif (in_array('race-taper', $kW, true)) {
    $capL = max(9, (int)round($capL * 0.7));      /* и в подводке к нему */
  }
  /* «Д на максимум» (для марафона 30-32 км) не в подряд идущих неделях:
     между такими длинными должно быть не менее 3 недель */
  $Dmax = $dist >= 40 ? 30 : ($dist >= 20 ? 21 : ($dist >= 9 ? 12 : 0));
  $maxOk = ($Dmax <= 0) || !isset($lastMaxWk) || ((int)$w['n'] - $lastMaxWk) >= 3;
  if (!$maxOk && $capL > max(10, (int)round($Dmax * 0.75))) {
    $capL = max(10, (int)round($Dmax * 0.75));    /* и рост не дотянется до максимума */
  }
  /* и сам максимум не выше коридора: 30-32 км для марафона */
  if ($maxOk && $Dmax > 0 && $capL > $Dmax + 2) {
    $capL = $Dmax + 2;
  }
  $hasD = false;
  foreach ($daysOut as $rD2) {
    if ($rD2['type'] === 'Д') { $hasD = true; break; }
  }
  if (!$hasD && $volT >= 20 && !in_array('race', $kW, true)
      && !preg_match('/стартовая неделя|неделя отдыха после целевого/u',
        (string)$w['focus'])) {
    $jD = -1; $kmDmax = 0.0; $qConv = false;
    foreach ($daysOut as $ixD2 => $rD2) {
      if (isset($pinned[$ixD2])) { continue; }
      if ($rD2['type'] === 'ВОС' && is_numeric($rD2['distance_km'])
          && (float)$rD2['distance_km'] > $kmDmax) {
        $kmDmax = (float)$rD2['distance_km']; $jD = $ixD2;
      }
    }
    /* лёгкого дня нет вовсе (например: ЦМТ+П+Ф+Горка) — тогда в длительную
       переходит наименее ценная качественная (фартлек → горка → И → П → ЦМТ);
       для этой замены подводные недели не годятся */
    if ($jD < 0 && !in_array('race-taper', $kW, true)) {
      $qConv = false;
      foreach (array('Ф', 'Горка', 'И', 'П', 'ЦМТ') as $qQ) {
        foreach ($daysOut as $ixD2 => $rD2) {
          if (isset($pinned[$ixD2])) { continue; }
          if ($rD2['type'] === $qQ) {
            $jD = $ixD2;
            $kmDmax = is_numeric($rD2['distance_km'])
              ? (float)$rD2['distance_km'] : 0.0;
            $qConv = true;
            break 2;
          }
        }
      }
    }
    if ($jD >= 0) {
      $daysOut[$jD]['type'] = 'Д';
      if ((float)$daysOut[$jD]['distance_km'] < $capL) {
        $daysOut[$jD]['distance_km'] = min($capL, max((int)$kmDmax,
          (int)round($volT * 0.3)));
      }
      $daysOut[$jD]['workout'] = $DEF_WORK['Д'];
      $daysOut[$jD]['intensity'] = 'зона 2, ровно';
      ai_log('D FIX', 'неделя ' . $w['n'] . ': в неделе не было длинного — '
        . ($qConv ? 'слабейшая из качественных заменена на «Д» '
          : 'самый длинный лёгкий день стал «Д» ')
        . $daysOut[$jD]['distance_km'] . ' км');
    }
  }
  /* --- второй длинный в одной неделе недопустим ---------------------- */
  /* модель (кусочно) ставила две «Д» в одной неделе по 18 км, а в стартовой
     неделе оставляла рядом со стартом «Д 14 км»: длинная в неделе одна —
     это сама гонка (если она есть) или самая длинная тренировка; прочие
     «Д» переводим в лёгкий бег, километраж сохраняем */
  $dList = array(); $jRaceU = -1;
  foreach ($daysOut as $ixU => $rU) {
    if ($rU['type'] !== 'Д' || !is_numeric($rU['distance_km'])) { continue; }
    if (strpos((string)$rU['workout'], 'СТАРТ') === 0) { $jRaceU = $ixU; }
    $dList[$ixU] = (float)$rU['distance_km'];
  }
  if (count($dList) > 1) {
    if ($jRaceU >= 0) {
      $jKeep = $jRaceU; $kmKeep = $dList[$jRaceU];
    } else {
      $jKeep = -1; $kmKeep = -1.0;
      foreach ($dList as $ixU => $kmU) {
        if ($kmU > $kmKeep) { $kmKeep = $kmU; $jKeep = $ixU; }
      }
    }
    foreach ($dList as $ixU => $kmU) {
      if ($ixU === $jKeep) { continue; }
      $daysOut[$ixU]['type'] = 'ВОС';
      $daysOut[$ixU]['workout'] = $DEF_WORK['ВОС'];
      $daysOut[$ixU]['intensity'] = 'зона 1';
      $daysOut[$ixU]['comment'] = 'длинная в этой неделе уже есть — день стал лёгким';
      ai_log('D DUP', 'неделя ' . $w['n'] . ': второй «Д» ' . (int)$kmU
        . ' км → лёгкий бег (длинная ' . (int)$kmKeep . ' км оставлена)');
    }
  }
  /* --- две одинаковые качественные в одной неделе недопустимы -------- */
  /* модель (Lite) ставила «ЦМТ + ЦМТ» в неделях спецфазы: это перегрузка
     одного качества и потеря слота у порога/интервалов. Один и тот же
     тип качества в неделе оставляем (первый по порядку дня), дубли →
     лёгкий бег; км сохраняем, чтобы не ломать свод недели */
  $qSeen = array();
  foreach ($daysOut as $ixQ => $rQ) {
    if (isset($pinned[$ixQ]) || $rQ['type'] === 'отдых'
        || $rQ['type'] === 'Д' || $rQ['type'] === 'ВОС') { continue; }
    $tQ = $rQ['type'];
    if (isset($qSeen[$tQ])) {
      $daysOut[$ixQ]['type'] = 'ВОС';
      $daysOut[$ixQ]['workout'] = $DEF_WORK['ВОС'];
      $daysOut[$ixQ]['intensity'] = 'зона 1';
      $daysOut[$ixQ]['comment'] = 'в неделе уже есть «' . $tQ
        . '» — дубль стал лёгким бегом';
      ai_log('QUAL DUP', 'неделя ' . $w['n'] . ': второй «' . $tQ
        . '» → лёгкий бег (' . (is_numeric($daysOut[$ixQ]['distance_km'])
          ? $daysOut[$ixQ]['distance_km'] . ' км' : 'км сохранён') . ')');
    } else {
      $qSeen[$tQ] = $ixQ;
    }
  }
  /* --- длинный бег — на выходные ----------------------------------- */
  /* если СБ или ВС входят в тренировочные дни, «Д» переносим туда: модель
     в реальном прогоне ставила длинные в понедельник — по методике длинный
     идёт в конец недели на выходном дне */
  $jDr = -1;
  foreach ($daysOut as $ixDm => $rDm) {
    if ($rDm['type'] === 'Д' && !isset($pinned[$ixDm])) { $jDr = $ixDm; break; }
  }
  if ($jDr >= 0) {
    $jTgt = -1;
    foreach (array('ВС', 'СБ') as $weName) {
      for ($jTm = $calN - 1; $jTm >= 0; $jTm--) {
        $cTm = $w['_cal'][$jTm];
        if ($cTm['day'] !== $weName || !$cTm['train'] || isset($pinned[$jTm])
            || isset($raceRest[$cTm['date']]) || $jTm === $jDr) { continue; }
        $jTgt = $jTm; break;
      }
      if ($jTgt >= 0) { break; }
    }
    if ($jTgt >= 0 && $jTgt !== $jDr) {
      /* меняем только содержимое строк: день и дата остаются на месте */
      $fieldsDm = array('type', 'workout', 'distance_km', 'intensity', 'comment');
      $tmpDm = array();
      foreach ($fieldsDm as $fK) { $tmpDm[$fK] = $daysOut[$jDr][$fK]; }
      foreach ($fieldsDm as $fK) {
        $daysOut[$jDr][$fK]   = $daysOut[$jTgt][$fK];
        $daysOut[$jTgt][$fK]  = $tmpDm[$fK];
      }
      ai_log('D MOVE', 'неделя ' . $w['n'] . ': длинный перенесён на '
        . $daysOut[$jTgt]['day'] . ' (' . $daysOut[$jTgt]['date'] . ')');
    }
  }
  /* --- «Д на максимум» не чаще 1 раза в 3 недели ------------------ */
  /* модель ставила 30-32 км в соседние недели: между максимальными
     длительными выдерживаем не менее 3 недель, «Д» в промежуточной
     неделе срезается до трёх четвертей максимума */
  if ($Dmax > 0) {
    $jMx = -1; $kmMx = 0.0;
    foreach ($daysOut as $ixMx => $rMx) {
      if (isset($pinned[$ixMx]) || $rMx['type'] !== 'Д'
          || !is_numeric($rMx['distance_km'])) { continue; }
      if ((float)$rMx['distance_km'] > $kmMx) { $kmMx = (float)$rMx['distance_km']; $jMx = $ixMx; }
    }
    if ($jMx >= 0 && $kmMx >= $Dmax && !$maxOk) {
      $kmNew = max(10, (int)round($Dmax * 0.75));
      ai_log('MAXD FIX', 'неделя ' . $w['n'] . ': Д ' . (int)$kmMx . ' → ' . $kmNew
        . ' км (на максимум не чаще 1 раза в 3 недели)');
      $daysOut[$jMx]['distance_km'] = $kmNew;
    }
  }
  /* --- объём недели: сумма дневных км = volume_km ----------------- */
  /* модель хронически недодаёт объём (реальный прогон: 21-22 км днями при
     плановых 50+): недостачу раскидываем по беговым строкам — сначала
     растим самую длинную, «Д» не выше 40% объёма, прочие не выше 30%;
     излишек — срезаем с самых длинных */
  $volT = (int)round((float)$w['volume_km']);
  if ($volT > 0) {
    /* сначала потолки, потом выравнивание: иначе «Д 27 км» в подводке к
       старту переживает выравнивание объёма (разница −3 срезает только 3 км) */
    foreach ($daysOut as $ixC => $rC) {
      if (isset($pinned[$ixC]) || $rC['type'] === 'отдых'
          || !is_numeric($rC['distance_km']) || (float)$rC['distance_km'] <= 0) { continue; }
      $capX = ($rC['type'] === 'Д') ? $capL : $capE;
      if ((float)$rC['distance_km'] > $capX) {
        $cut += (int)((float)$rC['distance_km'] - $capX);
        $daysOut[$ixC]['distance_km'] = $capX;
      }
    }
    $grew = 0; $cut = 0;
    $sumK = 0.0;
    foreach ($daysOut as $rL) {
      /* км целевой гонки — вне свода недели: volume_km стартовой недели
         (поэтому план 36 не должен «срезать» разгрузочные до минимума) */
      if (strpos((string)$rL['workout'], 'СТАРТ: Целевой старт') === 0) { continue; }
      if ($rL['type'] !== 'отдых' && is_numeric($rL['distance_km'])) {
        $sumK += (float)$rL['distance_km'];
      }
    }
    $diff = $volT - (int)round($sumK);
    while ($diff > 0) {
      $cands = array();
      foreach ($daysOut as $ixD => $rD) {
        if (isset($pinned[$ixD])) { continue; }   /* старты и накануне не раздуваем */
        if ($rD['type'] === 'отдых' || !is_numeric($rD['distance_km'])
            || (float)$rD['distance_km'] <= 0) { continue; }
        $capX = ($rD['type'] === 'Д') ? $capL : $capE;
        if ((float)$rD['distance_km'] < $capX) { $cands[$ixD] = (float)$rD['distance_km']; }
      }
      if (count($cands) === 0) { break; }
      arsort($cands);
      foreach ($cands as $ixD => $kmD) {
        if ($diff <= 0) { break; }
        $capX = ($daysOut[$ixD]['type'] === 'Д') ? $capL : $capE;
        $addX = min($diff, $capX - (int)$kmD, $chunkK);
        $daysOut[$ixD]['distance_km'] = (int)$daysOut[$ixD]['distance_km'] + $addX;
        $diff -= $addX; $grew += $addX;
      }
    }
    while ($diff < -1) {
      /* сначала срезаем тех, кто выше своего потолка («Д 27 км» в подводке
         к промежуточному старту), затем — любые самые длинные */
      $cands = array(); $cands2 = array();
      foreach ($daysOut as $ixD => $rD) {
        if (isset($pinned[$ixD]) || $rD['type'] === 'отдых'
            || !is_numeric($rD['distance_km']) || (float)$rD['distance_km'] <= 3) { continue; }
        $capX = ($rD['type'] === 'Д') ? $capL : $capE;
        if ((float)$rD['distance_km'] > $capX) { $cands[$ixD] = (float)$rD['distance_km']; }
        else { $cands2[$ixD] = (float)$rD['distance_km']; }
      }
      $use = (count($cands) > 0) ? $cands : $cands2;
      if (count($use) === 0) { break; }
      arsort($use);
      foreach ($use as $ixD => $kmD) {
        if ($diff >= 0) { break; }
        $capX = ($daysOut[$ixD]['type'] === 'Д') ? $capL : $capE;
        $cutX = (count($cands) > 0)
          ? min(-$diff, (int)$kmD - $capX)
          : min(-$diff, (int)$kmD - 3, $chunkK);
        if ($cutX <= 0) { continue; }
        $daysOut[$ixD]['distance_km'] = (int)$daysOut[$ixD]['distance_km'] - $cutX;
        $diff += $cutX; $cut += $cutX;
      }
    }
    if ($grew > 0) {
      ai_log('VOL FIX', 'неделя ' . $w['n'] . ': добавлено ' . $grew
        . ' км к плану недели ' . $volT);
    } elseif ($cut > 0) {
      ai_log('VOL FIX', 'неделя ' . $w['n'] . ': срезано ' . $cut
        . ' км излишка выше плана недели ' . $volT);
    }
  }
  /* --- минимум длительной в специальной фазе (после свода объёма) --- */
  /* модель могла «сжать» центр спецфазы до «Д 18 км» при объёме 80:
     длительная несёт главную марафонскую специфику — держим её не ниже
     3/4 максимума; недостающие км переносим с самых длинных лёгких дней,
     сумма недели не меняется. Слой стоит ПОСЛЕ свода объёма: иначе
     выравнивание объёма срезает поднятую «Д» обратно ниже минимума */
  $Dmin = ($Dmax > 0 && $w['period'] === 'Специальная')
    ? (int)round($Dmax * 0.75) : 0;
  if ($Dmin > 0 && !in_array('race', $kW, true)
      && !in_array('race-taper', $kW, true) && $capL >= $Dmin) {
    $jDm = -1; $kmDm = 0.0;
    foreach ($daysOut as $ixL => $rLx) {
      if (isset($pinned[$ixL]) || $rLx['type'] !== 'Д'
          || !is_numeric($rLx['distance_km'])) { continue; }
      if ((float)$rLx['distance_km'] > $kmDm) {
        $kmDm = (float)$rLx['distance_km']; $jDm = $ixL;
      }
    }
    if ($jDm >= 0 && $kmDm < $Dmin) {
      $needT = $Dmin - (int)$kmDm; $gotT = 0; $candL = array();
      foreach ($daysOut as $ixL2 => $rLx2) {
        if (isset($pinned[$ixL2]) || $ixL2 === $jDm
            || $rLx2['type'] === 'отдых' || !is_numeric($rLx2['distance_km'])
            || (float)$rLx2['distance_km'] <= 5) { continue; }
        $candL[$ixL2] = (float)$rLx2['distance_km'];
      }
      arsort($candL);
      foreach ($candL as $ixL2 => $kmL) {
        if ($gotT >= $needT) { break; }
        $take = min($needT - $gotT, (int)$kmL - 5);
        if ($take <= 0) { continue; }
        $daysOut[$ixL2]['distance_km'] = (int)$kmL - $take;
        $gotT += $take;
      }
      if ($gotT > 0) {
        $daysOut[$jDm]['distance_km'] = (int)$kmDm + $gotT;
        ai_log('D MIN', 'неделя ' . $w['n'] . ': Д ' . (int)$kmDm . ' → '
          . ((int)$kmDm + $gotT) . ' км (минимум длительной в спецфазе)');
      }
    }
  }
  /* --- км в описании тренировки = км в ячейке --------------------- */
  /* модель и наши страховочные объёмы расходились: в описании «6 км»,
     в ячейке 18. Для Д/ВОС «N км» в тексте — это всегда общий километраж,
     для Ф отрезки могут быть сегментами — только если «N км» одно */
  foreach ($daysOut as $ixS => $rS) {
    if ($rS['type'] === 'отдых' || isset($pinned[$ixS])) { continue; }
    if (!is_numeric($rS['distance_km']) || (float)$rS['distance_km'] <= 0) { continue; }
    if (!in_array($rS['type'], array('Д', 'ВОС', 'Ф'), true)) { continue; }
    $kmS = (int)$rS['distance_km'];
    $wS = (string)$rS['workout'];
    if ($wS === '' || strpos($wS, 'км') === false) { continue; }
    $patS = '/(\d{1,3}(?:[.,]\d)?)\s*км/u';
    if ($rS['type'] === 'Ф') {
      preg_match_all($patS, $wS, $mS);
      if (count($mS[0]) !== 1) { continue; }
    }
    $wS2 = preg_replace($patS, $kmS . ' км', $wS);
    if (is_string($wS2) && $wS2 !== $wS) { $daysOut[$ixS]['workout'] = $wS2; }
  }
  /* у ЦМТ/И «N км» в описании — рабочий отрезок, а км ячейки больше
     (разминка/заминка): числа не должны «спорить», дописываем общий км дня;
     если рабочий отрезок не влезает в день — день и есть работа */
  foreach ($daysOut as $ixS => $rS) {
    if ($rS['type'] !== 'ЦМТ' && $rS['type'] !== 'И') { continue; }
    if (isset($pinned[$ixS]) || !is_numeric($rS['distance_km'])
        || (float)$rS['distance_km'] <= 0) { continue; }
    $kmS = (int)$rS['distance_km'];
    $wS = (string)$rS['workout'];
    if ($wS === '' || strpos($wS, 'км') === false) { continue; }
    if (mb_stripos($wS, 'всего') !== false) { continue; }   /* уже дописано */
    preg_match('/(\d{1,3}(?:[.,]\d)?)\s*км/u', $wS, $mQ);
    if (!isset($mQ[1])) { continue; }
    $kmQ = (float)str_replace(',', '.', $mQ[1]);
    if ($kmQ >= $kmS - 2) {
      /* работа занимает весь день: «N км» в тексте = км ячейки */
      $wS2 = preg_replace('/(\d{1,3}(?:[.,]\d)?)\s*км/u', $kmS . ' км', $wS, 1);
    } else {
      $wS2 = rtrim($wS) . '; всего с разминкой и заминкой ' . $kmS . ' км';
    }
    if (is_string($wS2) && $wS2 !== $wS) { $daysOut[$ixS]['workout'] = $wS2; }
  }
  /* рабочая часть ЦМТ в спецфазе не ниже 3 км: модель в реальных прогонах
     сжимала отрезок в целевом темпе до «1 км» при заполненной ячейке дня —
     сессия превращалась в лёгкий бег с минутой темпа. Поднимаем отрезок до
     3 км; «всего ... км» не трогаем — погрешность уходит в разминку/заминку */
  if ($w['period'] === 'Специальная') {
    foreach ($daysOut as $ixC => $rC) {
      if ($rC['type'] !== 'ЦМТ' || isset($pinned[$ixC])) { continue; }
      $wC = (string)$rC['workout'];
      if ($wC === '' || strpos($wC, 'км') === false) { continue; }
      $kmCell = is_numeric($rC['distance_km']) ? (float)$rC['distance_km'] : 0.0;
      $hasVsego = mb_stripos($wC, 'всего') !== false;
      if (!$hasVsego && $kmCell < 3) { continue; }  /* весь день такой короткий — не сжатый отрезок */
      preg_match('/(\d{1,3}(?:[.,]\d)?)\s*км/u', $wC, $mC);
      if (!isset($mC[1]) || (float)str_replace(',', '.', $mC[1]) >= 3) { continue; }
      $wC2 = preg_replace('/(\d{1,3}(?:[.,]\d)?)\s*км/u', '3 км', $wC, 1);
      if (is_string($wC2) && $wC2 !== $wC) {
        $daysOut[$ixC]['workout'] = $wC2;
        ai_log('CMT MIN', 'неделя ' . $w['n'] . ': раб. отрезок ЦМТ '
          . $mC[1] . ' км → 3 км');
      }
    }
  }
  /* недель вообще без беговых тренировок не должно быть: если ВСЕ
     тренировочные слоты ушли в отдых (модель так ответила), последний из них
     принудительно становится лёгким бегом */
  $hasRun = false;
  foreach ($daysOut as $r) {
    if (in_array($r['type'], $RUN_TYPES, true)) { $hasRun = true; break; }
  }
  if (!$hasRun && count($trainFix) > 0) {
    $jF = $trainFix[count($trainFix) - 1];
    $kmF = max(3, min(10, round(((float)$w['volume_km']) * 0.2)));
    $daysOut[$jF] = array(
      'day' => $w['_cal'][$jF]['day'],
      'date' => $w['_cal'][$jF]['date'],
      'type'   => 'ВОС',
      'workout'=> 'лёгкий непрерывный бег',
      'distance_km' => $kmF,
      'intensity'   => 'зона 1',
      'comment'     => 'лёгкий бег: в неделе не должно остаться ни одного дня без беговой тренировки',
    );
    ai_log('WEEK FIX', 'неделя ' . $w['n'] . ' без беговых — вставлен лёгкий бег ' . $kmF . ' км');
  }
  /* во всех периодах, кроме восстановительного и смягчённых недель
     (разгрузка, отпуск/перерыв, тэйпер, недели со стартом), в неделе обязана
     быть минимум одна качественная работа — модель забивала план одними
     фартлеками, интервалы/темповые и горки не ставила вовсе: тип задаёт
     сервер ротацией по фазе плана */
  $wkExempt = ($w['period'] === 'Восстановление')
    || preg_match('/разгрузочн|отпуск|перерыв|подводк|промежуточн|стартовая|'
      . 'снижение нагрузки|мягкий вход|тэйпер|восстанов/u', (string)$w['focus']);
  $ROT_Q = array(
    'База I'      => array('Горка', 'Ф'),
    'База II'     => array('И', 'П', 'Горка', 'Ф'),
    'Специальная' => array('ЦМТ', 'П', 'И', 'П'),
  );
  $rotListQ = isset($ROT_Q[$w['period']]) ? $ROT_Q[$w['period']] : array('Ф');
  $rotPick = $rotListQ[(int)$w['n'] % count($rotListQ)];
  $intInts = array('ЦМТ' => 'зона 3, целевой темп старта',
    'П' => 'зона 3, темп порога', 'И' => 'зона 4, отрезки быстро',
    'Ф' => 'зона 3-4 отрезками', 'Горка' => 'ускорения в подъём');
  $hasInt = false;
  $hasNonF = false;
  foreach ($daysOut as $rI) {
    if (in_array($rI['type'], array('ЦМТ', 'П', 'И', 'Ф', 'Горка'), true)) { $hasInt = true; }
    if (in_array($rI['type'], array('ЦМТ', 'П', 'И', 'Горка'), true)) { $hasNonF = true; }
  }
  if (!$wkExempt && ($rotPick !== 'Ф' ? !$hasNonF : !$hasInt)) {
    /* приоритет: сам фартлек недели (если ротация требует другой тип), иначе
       последний лёгкий тренировочный день (ВОС с км); км не меняем */
    $jI = -1;
    if ($rotPick !== 'Ф') {
      foreach ($daysOut as $jI2 => $rQ) {
        if (isset($pinned[$jI2])) { continue; }
        if ($rQ['type'] === 'Ф') { $jI = $jI2; }
      }
    }
    if ($jI < 0) {
      foreach ($daysOut as $jI2 => $rQ) {
        if (isset($pinned[$jI2])) { continue; }
        if ($rQ['type'] === 'ВОС' && $rQ['distance_km'] !== ''
            && (float)$rQ['distance_km'] > 0) { $jI = $jI2; }
      }
    }
    if ($jI >= 0) {
      $kmI = $daysOut[$jI]['distance_km'];
      $daysOut[$jI]['type'] = $rotPick;
      $daysOut[$jI]['workout'] = $DEF_WORK[$rotPick];
      $daysOut[$jI]['intensity'] = $intInts[$rotPick];
      $daysOut[$jI]['comment'] = 'работа над качеством по ротации фазы плана';
      ai_log('INT FIX', 'неделя ' . $w['n'] . ': качественная работа → ' . $rotPick
        . ($kmI !== '' ? ' (' . $kmI . ' км)' : ''));
    }
  }
  /* запоминаем неделю с «Д на максимум» — отсчёт 3 недель для следующего */
  if ($Dmax > 0) {
    foreach ($daysOut as $rE) {
      if ($rE['type'] === 'Д' && is_numeric($rE['distance_km'])
          && (float)$rE['distance_km'] >= $Dmax) {
        if (!isset($lastMaxWk) || (int)$w['n'] > $lastMaxWk) { $lastMaxWk = (int)$w['n']; }
        break;
      }
    }
  }
  /* --- «П» пороговые: описание отрезками в минутах ----------------- */
  /* модель писала «пороговый бег отрезками» без минут; в пороге нужно
     набирать много времени: марафон 20 -> 60 мин по ходу подготовки,
     короче дистанции — меньше; в стартовые/подводные недели сжато */
  $thrEnd = $dist >= 40 ? 60 : ($dist >= 20 ? 40 : ($dist >= 9 ? 25 : 18));
  $thrBeg = $dist >= 40 ? 20 : ($dist >= 20 ? 15 : ($dist >= 9 ? 10 : 8));
  $wkAllT = ($recWeekN > 0) ? ($recWeekN - 1) : count($wk);
  $progT = max(0.0, min(1.0, ((int)$w['n'] - 1) / max(1, $wkAllT - 1)));
  $thrT = max(5, 5 * (int)round(($thrBeg + ($thrEnd - $thrBeg) * $progT) / 5));
  if (in_array('race', $kW, true)) {
    $thrT = max(10, 5 * (int)round($thrT * 0.6 / 5));   /* как кап длительного в неделе старта */
  } elseif (in_array('race-taper', $kW, true)) {
    $thrT = max(10, 5 * (int)round($thrT * 0.7 / 5));   /* и в подводке */
  }
  if ($thrT <= 20) {
    $thrTxt = 'порог: разминка 15 мин лёгким бегом, ' . $thrT
      . ' мин непрерывно в пороговом темпе, заминка 10 мин лёгким бегом';
  } else {
    $thrN = ($thrT <= 35) ? 2 : (($thrT <= 50) ? 3 : 4);
    $thrTxt = 'порог: разминка 15 мин лёгким бегом, ' . $thrN . 'x'
      . (int)round($thrT / $thrN) . ' мин в пороговом темпе (между отрезками'
      . ' 3 мин лёгкого), заминка 10 мин лёгким бегом';
  }
  $thrHit = 0;
  foreach ($daysOut as $jT => $rT) {
    if (isset($pinned[$jT]) || $rT['type'] !== 'П') { continue; }
    $daysOut[$jT]['workout'] = $thrTxt;
    if (!$wkExempt) { $daysOut[$jT]['intensity'] = 'зона 3, темп порога'; }
    $thrHit++;
  }
  if ($thrHit > 0) {
    ai_log('THR FIX', 'неделя ' . $w['n'] . ': порог описан в минутах ('
      . $thrT . ' мин в пороге, отрезков ' . ($thrT <= 20 ? 1 : $thrN) . ')');
  }
  $plan['weeks'][] = array(
    'n' => $w['n'], 'start' => $w['start'],
    'period' => $w['period'],
    'volume_km' => $w['volume_km'], 'focus' => $w['focus'],
    'days' => $daysOut,
  );
}

/* неделя без дней не должна тихо «пропасть» из Excel — видна пустой */
$missingDays = 0;
foreach ($plan['weeks'] as $w) { if (!count($w['days'])) { $missingDays++; } }
if ($missingDays === count($plan['weeks'])) {
  ai_log('EXTRACT FAIL', 'Ни один кусок дней не разобран — см. CHUNK FAIL');
  status_finish($jobId, false, 'ai_failed');
}
} /* конец ИИ-фазы 2 */

if ($algo) {
  /* режим алгоритма: каждая неделя несёт строку дней. Сброс счётчика —
     ТОЛЬКО здесь: раньше строка стояла вне if, затирала значение из
     ИИ-ветки, и предупреждение «недель без тренировок» (лист «Сводка»)
     не выводилось никогда. */
  $missingDays = 0;
  /* сборка плана алгоритмом: те же недели detWk, содержание дней — из пула */
  if ($algoNoAnchor) {
    /* темпы словесно: без недавнего результата считать зоны не из чего */
    $ptAlgo = array(
      '{E}' => 'лёгкий разговорный',
      '{M}' => 'целевой темп старта',
      '{T}' => 'пороговый («комфортно-тяжело»)',
      '{I}' => 'быстрый, выше порога',
      '{R}' => 'ускорение',
      '{race}' => 'целевой темп старта',
    );
  } else {
    $algoFcS = algo_riegel($rrTimeS, $rrKm, $dist);   /* прогноз на дистанцию цели */
    /* ключи — вместе с фигурными скобками: раньше strtr получал голые
       'E','M','T','I','R' и подменял ИМИ же буквы-метки в тексте
       («Интервалы I:» → «Интервалы 4:43:»), а {I} оставался с скобками.
       Шаблоны пишутся как {I}/км, {T}/км, {race}/км — так и подставляем. */
    $ptAlgo = array(
      '{E}' => algo_fmt_pace($algoZonesP[0]['pace']) . '-' . algo_fmt_pace($algoZonesP[0]['range_to']),
      '{M}' => algo_fmt_pace($algoZonesP[1]['pace']),
      '{T}' => algo_fmt_pace($algoZonesP[2]['pace']),
      '{I}' => algo_fmt_pace($algoZonesP[3]['pace']),
      '{R}' => algo_fmt_pace($algoZonesP[4]['pace']),
      '{race}' => algo_fmt_pace($algoFcS / $dist),
    );
  }
  $plan['weeks'] = algo_build_plan(
    $wk, $specialWeeks, $recWeekN, $raceOut, $raceEve, $raceQuiet, $raceRest,
    $raceKmByDate, $raceTs, $raceDate, $trainDays, $kmAnketa,
    $dist, $ptAlgo, $algoNotes, $algoNoAnchor
  );
}

/* --- Сборка .xlsx ---------------------------------------------- */
/* Минимальный Writer: inline-строки, 3 листа, 1 стиль (жирный) */

function xlsx_cell($col, $row, $value) {
  $isNum = is_int($value) || is_float($value);
  $type = $isNum ? '' : ' t="inlineStr"';
  $s = '';
  if ($row === 1) { $s = ' s="1"'; }  /* заголовочная строка жирная */
  $inner = $isNum ? '<v>' . (string)$value . '</v>' :
    '<is><t xml:space="preserve">' . htmlspecialchars((string)$value, ENT_XML1, 'UTF-8') . '</t></is>';
  return '<c r="' . $col . $row . '"' . $type . $s . '>' . $inner . '</c>';
}

function xlsx_sheet($header, $rows, $widths) {
  $cols = '';
  $i = 1;
  foreach ($widths as $w) {
    $cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $w . '" customWidth="1"/>';
    $i++;
  }
  $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<cols>' . $cols . '</cols><sheetData>';
  $letters = array('A','B','C','D','E','F','G','H','I','J','K','L');
  /* заголовок */
  $out .= '<row r="1">';
  foreach ($header as $ci => $v) {
    $out .= $ci < count($letters)
      ? xlsx_cell($letters[$ci], 1, $v) : '';
  }
  $out .= '</row>';
  $r = 2;
  foreach ($rows as $row) {
    $out .= '<row r="' . $r . '">';
    foreach ($row as $ci => $v) {
      if ($ci < count($letters)) {
        $out .= xlsx_cell($letters[$ci], $r, $v);
      }
    }
    $out .= '</row>';
    $r++;
  }
  $out .= '</sheetData></worksheet>';
  return $out;
}

$raceName = isset($plan['race']['name']) ? (string)$plan['race']['name'] : '';
$raceKm   = isset($plan['race']['distance_km']) ? $plan['race']['distance_km'] : $dist;
$raceDateOut = isset($plan['race']['date']) ? (string)$plan['race']['date'] : '';

/* Лист 1 «Сводка» */
$sheet1rows = array(
  array('Клуб', 'ВЖУХ — беговой клуб, Москва'),
  array('К чему готовимся', $raceName . ($raceKm ? (' · ' . $raceKm . ' км') : '')),
  array('Планируемый результат', $targetTime ?: 'не задан'),
  ($targetPace !== ''
    ? array('Целевой темп старта', $targetPace)
    : array('', '')),
  array('Пол, возраст', $gender . ', ' . $age . ' лет'),
  array('ЧССмакс (расчёт)', $hrMax . ' уд/мин'),
  array('Начало тренировок', $startDate ?: 'выбрано автоматически'),
  array('Дата старта', $raceDate ?: ($raceDateOut ?: 'не определена')),
  array('Недель в плане', count($plan['weeks'])),
  array('Тренировок в неделю',
    $data['days_per_week'] . ' по дням: ' . $trainDaysTxt),
  array('Объём на старте плана', $kmAnketa . ' км/нед (из анкеты): план не опускается'
    . ' ниже этого объёма до самой подводки'),
  ($data['recent_race']['txt'] !== ''
    ? array('Недавний старт', $data['recent_race']['txt'])
    : array('', '')),
  ($algo
    ? array('Прогноз результата (Ригель)', $algoNoAnchor
        ? 'после контрольного теста (неделя 1): результат введите при повторной генерации'
        : $algoFcTxt . ' на ' . $dist . ' км')
    : array('', '')),
  (count($specialNotes) > 0
    ? array('Особые периоды (из анкеты: перерывы и старты)', implode('; ', $specialNotes))
    : array('', '')),
  (count($algoNotes) > 0
    ? array('Замечания алгоритма', implode('; ', $algoNotes))
    : array('', '')),
  ($missingDays > 0
    ? array('Внимание: недель без тренировок',
        $missingDays . ' из ' . count($plan['weeks'])
        . ' — модель не сгенерировала этот кусок, повторите генерацию')
    : array('', '')),
  array('Краткая оценка готовности', isset($plan['summary']) ? (string)$plan['summary'] : ''),
  array('Дата составления', date('d.m.Y H:i')),
  array('Модель генерации', $algo
    ? 'Алгоритм (без ИИ): Ригель + периодизация, детерминированный сбор'
    : $gigaModel),
  array('Примечание', 'План составлен автоматически. Сверяйте его с тренером клуба.'
    . ' Не меняйте резко объёмы и следите за самочувствием.'),
);

/* Лист 2 «План»: как у тренера — строка-заголовок «Неделя N — фаза · суть»,
   под ней дни недели */
$sheet2rows = array();
$planWeeks = array_values($plan['weeks']);
foreach ($planWeeks as $w) {
  $period = (string)($w['period'] ?? '');
  $focus = (string)($w['focus'] ?? '');
  if ($period !== '' || $focus !== '') {
    /* плановый объём недели — в заголовок: без него «нулевые объёмы»
       и недостачи не видны при просмотре файла; неполные первая/последняя
       недели — пропорционально числу дней, иначе в заголовке «50 км»,
       а в неделе фактически 35 */
    $volHdr = isset($w['volume_km']) && is_numeric($w['volume_km'])
      ? (int)round((float)$w['volume_km']) : 0;
    $dCnt = isset($w['days']) && is_array($w['days']) ? count($w['days']) : 7;
    if ($volHdr > 0 && $dCnt > 0 && $dCnt < 7) {
      $volHdr = max(1, (int)round($volHdr * $dCnt / 7));
    }
    /* неделя со стартом: километраж гонки показываем отдельно */
    $raceHdr = 0.0;
    if (isset($w['days']) && is_array($w['days'])) {
      foreach ($w['days'] as $dH) {
        $wkS = (string)($dH['workout'] ?? '');
        if (strpos($wkS, 'СТАРТ') === 0
            && isset($dH['distance_km']) && is_numeric($dH['distance_km'])) {
          $raceHdr = (float)$dH['distance_km'];
        }
      }
    }
    $sheet2rows[] = array('Неделя ' . (isset($w['n']) ? $w['n'] : '')
      . ($period !== '' ? ' — ' . $period : '')
      . ($focus !== '' ? ' · ' . $focus : '')
      . ' · объём: ' . $volHdr . ' км'
      . ($raceHdr > 0 ? ' · старт: ' . (fmod($raceHdr, 1.0) === 0.0
          ? (int)$raceHdr : round($raceHdr, 1)) . ' км' : '')
      . ' · тренировки: ' . $trainDaysTxt, '', '', '', '', '', '', '');
  }
  $days = isset($w['days']) && is_array($w['days']) ? $w['days'] : array();
  foreach ($days as $d) {
    /* модель может прислать обе формы ключа (в реальном прогоне была
       «distance_kм» с кириллической «м») — берём любую */
    $kmDay = '';
    if (isset($d['distance_km']) && is_numeric($d['distance_km'])) {
      $kmDay = $d['distance_km'];
    } elseif (isset($d['distance_kм']) && is_numeric($d['distance_kм'])) {
      $kmDay = $d['distance_kм'];
    }
    $sheet2rows[] = array(
      isset($w['n']) ? $w['n'] : '',
      isset($d['date']) ? $d['date'] : '',
      isset($d['day']) ? $d['day'] : '',
      isset($d['type']) ? $d['type'] : '',
      isset($d['workout']) ? $d['workout'] : '',
      $kmDay,
      isset($d['intensity']) ? $d['intensity'] : '',
      isset($d['comment']) ? $d['comment'] : '',
    );
  }
}

/* --- Дисклеймеры: конец листа «План», «Сводка» и сайт ------------ */
/* текст разбит на короткие строки: одна строка = одна ячейка,
   чтобы абзац был виден на экране без горизонтального скролла */
$DCL1 = array(
  'План сгенерирован исключительно в ознакомительных и иллюстративных целях.',
  'Не является ни догмой, ни руководством к действию.',
  'Для эффективных и безопасных (без травм) тренировок',
  'настоятельно рекомендуется тренироваться с тренером.',
);
$DCL2 = array(
  'ВАЖНО!!! Перед тем, как начать тренировки,',
  'пройдите медицинское обследование и убедитесь,',
  'что не имеете противопоказаний к тренировкам.',
);
/* лист «План»: разделитель, затем по строке на каждую строку текста;
   ячейки справа пустые — текст переливается и читается целиком */
$sheet2rows[] = array('', '', '', '', '', '', '', '');
foreach ($DCL1 as $dcL) { $sheet2rows[] = array($dcL, '', '', '', '', '', '', ''); }
$sheet2rows[] = array('', '', '', '', '', '', '', '');
foreach ($DCL2 as $dcL) { $sheet2rows[] = array($dcL, '', '', '', '', '', '', ''); }
/* лист «Сводка»: текст в колонке «Значение», без слова «Дисклеймер» */
$sheet1rows[] = array('', '');
foreach ($DCL1 as $dcL) { $sheet1rows[] = array('', $dcL); }
$sheet1rows[] = array('', '');
$medLbl = true;
foreach ($DCL2 as $dcL) {
  $sheet1rows[] = array($medLbl ? 'Медицинский контроль' : '', $dcL);
  $medLbl = false;
}

/* Лист 3 «Зоны и советы». Границы пульса считаем на сервере (по возрасту
   и измеренным порогам) — цифры модели не полагаемся. Темпы в зонах
   берем у модели (они опираются на пороги и целевое время). */
$sheet3rows = array();
$sheet3rows[] = array('ЧССмакс (расчёт по возрасту)', $hrMax . ' уд/мин', '', '');
if ($targetTime !== '') { $sheet3rows[] = array('Планируемый результат', $targetTime, '', ''); }
if ($thrAeHr) { $sheet3rows[] = array('Аэробный порог (измерен)', $thrAeHr . ' уд/мин', $thrAePace, ''); }
if ($thrPanoHr) { $sheet3rows[] = array('ПАНО (измерен)', $thrPanoHr . ' уд/мин', $thrPanoPace, ''); }
$sheet3rows[] = array('', '', '', '');
/* темпы зон. Источники по надёжности:
   1) измеренные пороги — темпы строим на сервере, детерминированно;
   2) цифры модели — принимаем только если монотонны: зона 2 МЕДЛЕННЕЕ
      зоны 3, зона 3 медленнее зоны 4 (мин/км число убывает);
   3) словесные описания без цифр — модель перепутала темпы. */
$paceSec = function ($t) {
  if (preg_match('/(\d{1,2}):([0-5]\d)/', (string)$t, $m)) {
    return (int)$m[1] * 60 + (int)$m[2];
  }
  return 0;
};
/* цифры модели по зонам 1-4 (позиционно: порядок совпадает с правилом зон) */
$modelPace = array();
$kz = 0;
if (!empty($plan['zones']) && is_array($plan['zones'])) {
  foreach ($plan['zones'] as $z) {
    if ($kz > 3) { break; }
    $modelPace[$kz] = $paceSec($z['pace'] ?? '');
    $kz++;
  }
}
if ($algoNoAnchor) {
  /* без якоря числовых зон Ригеля нет — пульсовые границы по возрасту,
     темпы словесно; тест-строка объясняет, как получить точные темпы */
  $sheet3rows[] = array('Контрольный тест',
    'в первые 2 недели плана: 5К с усилием (или 30 мин на максимум времени)',
    'по усилию', 'результат теста введите при повторной генерации');
}
if ($algo && !$algoNoAnchor) {
  /* зоны E/M/T/I/R по Ригелю (от недавнего результата): пульс — по
     клубным границам зон, темп — детерминированный расчёт */
  $algoZoneRows = array(
    array('E', 'лёгкий: объём, восстановление, лонги', 'зона 1-2'),
    array('M', 'целевой темп старта', 'зона 3'),
    array('T', 'порог: «комфортно-тяжело»', 'зона 3'),
    array('I', 'интервалы VO2max', 'зона 4'),
    array('R', 'скорость, повторения', 'зона 4'),
  );
  foreach ($algoZoneRows as $ixA => $zrA) {
    $az = $algoZonesP[$ixA];
    $ptxA = algo_fmt_pace($az['pace']);
    if ($az['range_to'] > $az['pace'] + 0.001) {
      $ptxA .= '-' . algo_fmt_pace($az['range_to']);
    }
    $sheet3rows[] = array('Зона ' . $zrA[0], $zrA[1],
      $zrA[2] . ' уд/мин', $ptxA . ' мин/км');
  }
} elseif (!$algo && (!empty($thrAePace) || !empty($thrPanoPace))) {
  /* числовые зоны от порогов — только ИИ-режиму: у новичка в листе 3
     «Контрольный тест» уже стоит выше, дублировать его зонами не нужно */
  /* опорные пороги есть — темпы зон от них */
  foreach (array(
    array('зона 1', 'восстановление', 'до ' . $z1z2),
    array('зона 2', 'аэробная', $z1z2 . '-' . $z2z3),
    array('зона 3', 'скоростная (у порога)', $z2z3 . '-' . $z3z4),
    array('зона 4', 'анаэробная / скорость', 'выше ' . $z3z4 . ' (макс ' . $hrMax . ')'),
  ) as $zr) {
    switch ($zr[0]) {
      case 'зона 2':
        $paceTxt = 'разговорный, медленнее ' . ($thrAePace ?: $thrPanoPace);
        break;
      case 'зона 3':
        $paceTxt = ($thrAePace && $thrPanoPace)
          ? $thrAePace . '–' . $thrPanoPace : 'около ' . ($thrPanoPace ?: $thrAePace);
        break;
      case 'зона 4':
        $paceTxt = $thrPanoPace ? 'быстрее ' . $thrPanoPace . ', ускорения'
                                : 'выше порога, ускорения';
        break;
      default:
        $paceTxt = 'очень легко, разговаривать можно';
    }
    $sheet3rows[] = array($zr[0], $zr[1], $zr[2] . ' уд/мин', $paceTxt);
  }
} elseif (isset($modelPace[1], $modelPace[2]) && $modelPace[1] > $modelPace[2]
          && (!isset($modelPace[3]) || !$modelPace[3] || $modelPace[2] > $modelPace[3])) {
  /* цифр порогов нет, но темпы модели монотонны — доверяем им */
  foreach (array(
    array('зона 1', 'восстановление', 'до ' . $z1z2),
    array('зона 2', 'аэробная', $z1z2 . '-' . $z2z3),
    array('зона 3', 'скоростная (у порога)', $z2z3 . '-' . $z3z4),
    array('зона 4', 'анаэробная / скорость', 'выше ' . $z3z4 . ' (макс ' . $hrMax . ')'),
  ) as $zr) {
    $paceTxt = '';
    foreach ($plan['zones'] as $z) {
      $nm = strtolower((string)($z['name'] ?? ''));
      if (strpos($nm, $zr[0]) === 0) {
        $paceTxt = str_replace(array('уд/мин', 'bpm'), '', (string)($z['pace'] ?? ''));
        break;
      }
    }
    $sheet3rows[] = array($zr[0], $zr[1], $zr[2] . ' уд/мин', $paceTxt);
  }
} else {
  /* модель перепутала темпы или не дала цифр — без цифр не остаёмся */
  foreach (array(
    array('зона 1', 'восстановление', 'до ' . $z1z2, 'очень легко, разговаривать можно'),
    array('зона 2', 'аэробная', $z1z2 . '-' . $z2z3, 'разговорный темп'),
    array('зона 3', 'скоростная (у порога)', $z2z3 . '-' . $z3z4, 'уровень порога'),
    array('зона 4', 'анаэробная / скорость', 'выше ' . $z3z4 . ' (макс ' . $hrMax . ')',
      'выше порога, короткие ускорения'),
  ) as $zr) {
    $sheet3rows[] = array($zr[0], $zr[1], $zr[2] . ' уд/мин', $zr[3]);
  }
}
$sheet3rows[] = array('', '', '', '');
/* расшифровка кодов тренировок — как в планах тренера клуба */
$sheet3rows[] = array('Расшифровка', '', '', '');
foreach (array(
  array('ВОС', 'Восстановительный бег', 'до ' . $z1z2 . ' уд/мин',
    'очень легко, разговорный'),
  array('Д', 'Длительный бег', $z1z2 . '-' . $z2z3 . ' уд/мин', 'ровно, чуть быстрее ВОС'),
  array('ЦМТ', 'Бег в целевом темпе старта', $z2z3 . '-' . $z3z4 . ' уд/мин',
    $targetPace !== '' ? 'целевой темп ' . $targetPace : 'если задан результат'),
  array('П', 'Пороговый бег', $z2z3 . '-' . $z3z4 . ' уд/мин',
    $thrPanoPace !== '' ? 'около ' . $thrPanoPace : 'уровень порога'),
  array('И', 'Интервалы', 'выше ' . $z3z4 . ' уд/мин', 'короткие отрезки, быстрее П'),
  array('Ф', 'Фартлек', 'переменный', 'чередование быстрого и лёгкого'),
  array('Горка', 'Ускорения в подъём', 'до ' . $z3z4 . ' уд/мин', 'серии ~100 м в горку'),
  array('отдых', 'Без тренировки', '—', ''),
  array('—', 'Силовая / ОФП / растяжка / СБУ', '—',
    'в план не входят: отдельно в дни отдыха, 1-2 раза в неделю'),
) as $g) {
  $sheet3rows[] = $g;
}
$sheet3rows[] = array('', '', '', '');
$sheet3rows[] = array('Советы', '', '', '');
if (!empty($plan['advice']) && is_array($plan['advice'])) {
  foreach ($plan['advice'] as $a) { $sheet3rows[] = array('–', (string)$a, '', ''); }
}

/* --- ZipArchive: пишем xlsx ------------------------------------- */
function xlsx_xml($type) {
  switch ($type) {
    case 'ctypes':
      return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>';
    case 'root':
      return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';
    case 'workbook':
      return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Сводка" sheetId="1" r:id="rId1"/>'
        . '<sheet name="План" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Зоны и советы" sheetId="3" r:id="rId3"/></sheets></workbook>';
    case 'wbrels':
      return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    case 'styles':
      return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
        . '<fills count="2"><fill><patternFill patternType="none"/></fill>'
        . '<fill><patternFill patternType="gray125"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
        . '<cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
  }
  return '';
}

if (!class_exists('ZipArchive')) { http_response_code(500); respond(false, 'server'); }
if (!is_dir(DIR_OUT)) { @mkdir(DIR_OUT, 0755, true); }

$fName = 'plan-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.xlsx';
$fPath = DIR_OUT . '/' . $fName;

$zip = new ZipArchive();
if ($zip->open($fPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
  http_response_code(500); respond(false, 'server');
}
$zip->addFromString('[Content_Types].xml', xlsx_xml('ctypes'));
$zip->addFromString('_rels/.rels', xlsx_xml('root'));
$zip->addFromString('xl/workbook.xml', xlsx_xml('workbook'));
$zip->addFromString('xl/_rels/workbook.xml.rels', xlsx_xml('wbrels'));
$zip->addFromString('xl/styles.xml', xlsx_xml('styles'));
$zip->addFromString('xl/worksheets/sheet1.xml',
  xlsx_sheet(array('Что', 'Значение'), $sheet1rows, array(28, 80)));
$zip->addFromString('xl/worksheets/sheet2.xml',
  xlsx_sheet(array('Неделя','Дата','День','Тип','Тренировка','Км','Интенсивность','Комментарий'),
    $sheet2rows, array(28, 11, 6, 11, 52, 6, 24, 46)));
$zip->addFromString('xl/worksheets/sheet3.xml',
  xlsx_sheet(array('Зона','Назначение','Пульс, уд/мин','Темп'), $sheet3rows, array(10, 22, 16, 44)));
if ($zip->close() !== true) {
  /* запись архива сорвалась — битую ссылку отдавать нельзя */
  @unlink($fPath);
  status_finish($jobId, false, 'write_failed');
}

/* старые планы и статусы убирает plan_cleanup() — она вызывается в начале
   каждой генерации, поэтому мусор уходит и после упавших задач */
plan_cleanup();

/* --- Готово: ссылка -------------------------------------------- */
status_finish($jobId, true, '', array(
  'url'   => '/ai-plan/out/' . $fName,
  'file'  => $fName,
  'note1' => $DCL1,
  'note2' => $DCL2,
));