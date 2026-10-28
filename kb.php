<?php
/* ============================================================
   ВЖУХ — сохранение базы знаний (kb.php)

   Назначение: кладётся в корень сайта рядом с publish.php.
   Редактор базы знаний (kb-editor.html) отправляет POST и файл
   data/knowledge.js перезаписывается целиком.

   Проверка работоспособности: открыть в браузере
   https://ваш-сайт/kb.php — должно ответить
   «{"ok":false,"error":"method"}».

   Безопасность: пароль сравнивается через hash_equals; путь из
   запроса не берётся (пишется только data/knowledge.js);
   атомарная запись (tmp + rename) под flock; две ротируемые
   копии предыдущих версий в data/_backups/.
   ============================================================ */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

/* --- Пароль (тот же, что для публикации) — в secrets.php ------- */
if (is_file(__DIR__ . '/secrets.php')) { require __DIR__ . '/secrets.php'; }
if (!defined('KB_PASSWORD')) { define('KB_PASSWORD', ''); }

/* Лимит размера базы знаний (байт). Текущая база ~1,9 МБ */
define('MAX_CONTENT_BYTES', 3000000);

function respond($ok, $error = '') {
  echo json_encode(array('ok' => $ok, 'error' => $error), JSON_UNESCAPED_UNICODE);
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

$password = isset($_POST['password']) ? (string)$_POST['password'] : '';
$content  = isset($_POST['content'])  ? (string)$_POST['content']  : '';

if (!defined('KB_PASSWORD') || KB_PASSWORD === '') {
  http_response_code(500);
  respond(false, 'password_not_set');
}
if (!hash_equals(KB_PASSWORD, $password)) {
  sleep(1);
  http_response_code(403);
  respond(false, 'wrong_password');
}

/* Нормализация переносов и лимит размера */
$content = str_replace("\r\n", "\n", $content);
if (strlen($content) === 0 || strlen($content) > MAX_CONTENT_BYTES) {
  respond(false, 'size');
}
/* Содержимое должно быть базой знаний (маркер где-то в файле) */
if (strpos($content, 'window.WZ_KNOWLEDGE') === false) {
  respond(false, 'format');
}

/* «</» внутри JSON-строк разорвал бы <script>-тег страницы:
   экранируем по всему файлу (\/ в JS-строках валиден) */
$content = str_replace('</', '<\\/', $content);

$target  = __DIR__ . '/data/knowledge.js';
$backups = __DIR__ . '/data/_backups';

if (!is_dir(dirname($target))) { respond(false, 'no_data_dir'); }
if (!is_dir($backups))         { @mkdir($backups, 0755, true); }

/* Блокировка одновременных записей */
$lockPath = __DIR__ . '/data/knowledge.lock';
$lock = @fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX)) {
  if ($lock) { @fclose($lock); }
  http_response_code(500);
  respond(false, 'busy');
}

function kbfail($code, $http = null) {
  global $lock;
  flock($lock, LOCK_UN);
  @fclose($lock);
  if ($http) { http_response_code($http); }
  respond(false, $code);
}

/* Ротация бэкапов: prev → prev-2 → затирается */
$tmpPath  = $target . '.tmp';
$written  = @file_put_contents($tmpPath, $content, LOCK_EX);
$renamed  = $written !== false && @rename($tmpPath, $target);

if ($written === false || !$renamed) {
  @unlink($tmpPath);
  kbfail('write_failed', 500);
}

if (is_file($target)) {
  if (is_file($backups . '/knowledge-prev.js')) {
    @copy($backups . '/knowledge-prev.js', $backups . '/knowledge-prev-2.js');
  }
  @copy($target, $backups . '/knowledge-prev.js');
}

flock($lock, LOCK_UN);
@fclose($lock);

respond(true);