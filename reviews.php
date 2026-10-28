<?php
/* ============================================================
   ВЖУХ — приём отзывов посетителей (reviews.php)

   Назначение: кладётся в корень сайта рядом с publish.php.
   Форма на сайте отправляет POST — скрипт добавляет отзыв
   к data/reviews.js, и он сразу виден всем посетителям.

   Защита (пароля нет — отзывы открыты всем):
   1. Honeypot-поле «website» — заполнили боты → отказ «spam».
   2. Лимит 3 отзыва в час с одного адреса (data/_rate/).
   3. Санитизация: обрезка длины, вырезание управляющих символов.
   4. Капа: не более 300 отзывов / 200 КБ, самые старые удаляются.
   5. Запись под flock, атомарно (tmp + rename); прежняя версия
      файла сохраняется в data/_backups/reviews-prev.js.

   Проверка работоспособности: открыть в браузере
   https://ваш-сайт/reviews.php — должно ответить
   «{"ok":false,"error":"method"}».
   ============================================================ */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

/* Лимиты */
define('MAX_REVIEWS', 300);          /* максимум отзывов в файле */
define('MAX_FILE_BYTES', 200000);    /* максимум размера файла */
define('RATE_LIMIT', 3);             /* отзывов с одного адреса */
define('RATE_WINDOW', 3600);         /* за N секунд (час) */

/* Одиночный ответ и выход */
function respond($ok, $error = '', $extra = array()) {
  $out = array_merge(array('ok' => $ok, 'error' => $error), $extra);
  echo json_encode($out, JSON_UNESCAPED_UNICODE);
  exit;
}

/* CORS-предзапрос браузера */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  respond(true);
}

/* Только POST */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  respond(false, 'method');
}

/* --- Санитизация полей -------------------------------------- */
function clean($s, $maxLen) {
  /* управляющие символы прочь, переносы нормализуем */
  $s = str_replace(array("\r\n", "\r"), "\n", (string)$s);
  $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
  $s = trim($s);
  /* mb_substr может отсутствовать, тогда режем байтово */
  if (function_exists('mb_substr')) { $s = mb_substr($s, 0, $maxLen); }
  else { $s = substr($s, 0, $maxLen * 4); }
  return $s;
}

$website = isset($_POST['website']) ? trim((string)$_POST['website']) : '';
$name    = clean(isset($_POST['name']) ? $_POST['name'] : '', 40);
$text    = clean(isset($_POST['text']) ? $_POST['text'] : '', 800);

/* Honeypot: человек это поле не видит и не заполняет */
if ($website !== '') {
  respond(false, 'spam');
}

/* Валидация */
if (strlen($name) < 1 || strlen($text) < 10) {
  respond(false, 'format');
}

/* --- Ограничение частоты по адресу --------------------------- */
date_default_timezone_set('Europe/Moscow');

$rateDir = __DIR__ . '/data/_rate';
if (!is_dir($rateDir)) { @mkdir($rateDir, 0755, true); }

$salt = __FILE__ . '|' . php_uname();
$ipHash = md5((isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '') . $salt);
$rateFile = $rateDir . '/' . $ipHash . '.json';

$lockPath = __DIR__ . '/data/reviews.lock';
$target   = __DIR__ . '/data/reviews.js';
$backups  = __DIR__ . '/data/_backups';

if (!is_dir(dirname($target))) { respond(false, 'no_data_dir'); }

$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
  if ($lock) { @fclose($lock); }
  http_response_code(500);
  respond(false, 'busy');
}

function fail($code, $http = null) {
  global $lock;
  flock($lock, LOCK_UN);
  @fclose($lock);
  if ($http) { http_response_code($http); }
  respond(false, $code);
}

/* Частота: не больше RATE_LIMIT за RATE_WINDOW */
if (is_dir($rateDir)) {
  $now = time();
  $stamps = array();
  if (is_file($rateFile)) {
    $stamps = @json_decode(@file_get_contents($rateFile), true);
    if (!is_array($stamps)) { $stamps = array(); }
  }
  $stamps = array_values(array_filter($stamps, function ($t) use ($now) {
    return is_numeric($t) && ($now - (int)$t) < RATE_WINDOW;
  }));
  if (count($stamps) >= RATE_LIMIT) { fail('rate'); }
  $stamps[] = $now;
  @file_put_contents($rateFile, json_encode($stamps), LOCK_EX);
}

/* --- Читаем текущие отзывы ------------------------------------ */
$reviews = array();
if (is_file($target)) {
  $raw = @file_get_contents($target);
  /* формируем из строки window.WZ_REVIEWS = {…}; */
  $pos = strpos((string)$raw, 'window.WZ_REVIEWS');
  if ($pos !== false) {
    $jsonPart = trim(substr($raw, strcspn($raw, '{', $pos)), " \t\n\r;\x00");
    $decoded  = json_decode($jsonPart, true);
    if (is_array($decoded) && isset($decoded['reviews']) && is_array($decoded['reviews'])) {
      $reviews = $decoded['reviews'];
    }
  }
}

/* --- Дата по-русски ------------------------------------------- */
$months = array(1 => 'января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря');
$ts     = time();
$date   = (int)date('j') . ' ' . $months[(int)date('n')] . ' ' . date('Y');

/* --- Добавляем, режем капу ------------------------------------- */
$review = array(
  'name' => $name,
  'date' => $date,
  'text' => $text,
  'ts'   => $ts
);
$reviews[] = $review;

/* Капа размера: убираем самые старые */
while (count($reviews) > MAX_REVIEWS) { array_shift($reviews); }

/* --- Записываем файл ------------------------------------------- */
$json = json_encode(
  array('reviews' => array_values($reviews)),
  JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);
if ($json === false) { fail('write_failed', 500); }

/* Отзыв пользователя не должен разорвать файл-скрипт:
   «</» внутри строки экранируем (\/ в JSON валиден) */
$configScript = "window.WZ_REVIEWS = " . str_replace('</', '<\\/', $json) . ";\n";

if (strlen($configScript) > MAX_FILE_BYTES) {
  /* ещё раз ужимаем по капе байтов */
  while (count($reviews) > 1 && strlen($configScript) > MAX_FILE_BYTES) {
    array_shift($reviews);
    $json = json_encode(array('reviews' => array_values($reviews)),
      JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $configScript = "window.WZ_REVIEWS = " . str_replace('</', '<\\/', $json) . ";\n";
  }
}

$tmpPath = $target . '.tmp';
$written = @file_put_contents($tmpPath, $configScript, LOCK_EX);
$renamed = $written !== false && @rename($tmpPath, $target);
if (!$written || !$renamed) {
  @unlink($tmpPath);
  fail('write_failed', 500);
}

/* Ротируемый бэкап предыдущей версии */
if (!is_dir($backups)) { @mkdir($backups, 0755, true); }
if (is_dir($backups) && is_file($backups . '/reviews-prev.js')) {
  @copy($backups . '/reviews-prev.js', $backups . '/reviews-prev-2.js');
}
if (is_dir($backups)) { @copy($target, $backups . '/reviews-prev.js'); }

flock($lock, LOCK_UN);
@fclose($lock);

/* Клиенту возвращаем серверную копию отзыва (дата уже по-нашему) */
respond(true, '', array('review' => array(
  'name' => $review['name'],
  'date' => $review['date'],
  'text' => $review['text']
)));