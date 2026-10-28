<?php
/* ============================================================
   ВЖУХ — обработчик публикации расписания (publish.php)

   Назначение: кладётся в корень сайта вместе с остальными файлами.
   Принимает с editor.html POST-запрос и записывает его в
   data/schedule.js, предварительно сохранив старую версию
   в data/_backups/.

   УСТАНОВКА (один раз):
   1. Найдите строку  PUBLISH_PASSWORD ниже и замените текст
      ЗАДАЙТЕ-ПАРОЛЬ-ЗДЕСЬ на настоящий пароль издания.
      Пароль сообщите тренеру лично (не по открытым каналам).
   2. Убедитесь, что папка data и data/_backups доступны
      для записи (на Beget/Timeweb — обычно и так).
   3. Проверьте: откройте в браузере
      https://ваш-сайт/publish.php — должно ответить
      «{"ok":false,"error":"method"}» и больше ничего.
      Если видно ошибку PHP — проверьте версию PHP ≥ 7.4.

   Безопасность: пишет только один файл с фиксированным именем,
   путь из запроса не берётся, пароль сравнивается через
   hash_equals. CORS открыт, чтобы редактор, открытый как
   локальный файл, мог отправлять публикацию на сайт.
   ============================================================ */

header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

/* --- Публичный пароль издания — в secrets.php (в git не попадает) --- */
if (is_file(__DIR__ . '/secrets.php')) { require __DIR__ . '/secrets.php'; }
if (!defined('PUBLISH_PASSWORD')) { define('PUBLISH_PASSWORD', ''); }

/* Лимит размера содержимого расписания (КБ) */
define('MAX_CONTENT_BYTES', 100000);

function respond($ok, $error = '') {
  echo json_encode(array('ok' => $ok, 'error' => $error), JSON_UNESCAPED_UNICODE);
  exit;
}

/* Обслуживание OPTIONS-предзапроса браузера (CORS) */
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  header('Access-Control-Allow-Headers: Content-Type');
  respond(true);
}

/* Поддерживается только POST-запрос */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  respond(false, 'method');
}

$password   = isset($_POST['password']) ? (string)$_POST['password'] : '';
$content    = isset($_POST['content'])  ? (string)$_POST['content']  : '';

/* Проверка пароля (устойчиво к side-channel) */
if (!defined('PUBLISH_PASSWORD') ||
    PUBLISH_PASSWORD === 'ЗАДАЙТЕ-ПАРОЛЬ-ЗДЕСЬ') {
  http_response_code(500);
  respond(false, 'password_not_set');
}
if (!hash_equals(PUBLISH_PASSWORD, $password)) {
  sleep(1); /* небольшая пауза против перебора */
  http_response_code(403);
  respond(false, 'wrong_password');
}

/* Обеззараживание содержимого */
$content = str_replace("\r\n", "\n", $content);
if (strlen($content) === 0 || strlen($content) > MAX_CONTENT_BYTES) {
  respond(false, 'size');
}
/* Содержимое должно быть расписанием. Проверяем, что маркер есть
   ГДЕ-ТО ВНУТРИ: редактор формирует файл с заголовком-комментарием,
   поэтому маркер идёт не с самого начала файла. */
if (strpos($content, 'window.WZ_SCHEDULE') === false) {
  respond(false, 'format'); /* содержимое не похоже на расписание */
}

$target  = __DIR__ . '/data/schedule.js';
$backups = __DIR__ . '/data/_backups';

/* Папки для расписания и бэкапов */
if (!is_dir(dirname($target)))   { respond(false, 'no_data_dir'); }
if (!is_dir($backups))           { @mkdir($backups, 0755, true); }

/* Резервная копия прежней версии */
$backupPath = $backups . '/schedule-' . date('Ymd-His') . '.js';
if (is_file($target)) {
  @copy($target, $backupPath);
}

/* Запись */
$tmpPath  = $target . '.tmp';
$result   = @file_put_contents($tmpPath, $content, LOCK_EX);
$renamed  = $result !== false && @rename($tmpPath, $target);

if ($result === false || !$renamed) {
  @unlink($tmpPath);
  http_response_code(500);
  respond(false, 'write_failed');
}

respond(true);
