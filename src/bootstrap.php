<?php

declare(strict_types=1);

use Predis\Client;

/*
 * Логирование подключается первым: тогда ошибка в самом bootstrap или в
 * автозагрузчике Composer тоже попадёт в файл лога.
 */
require_once __DIR__ . '/log.php';

chat_install_error_handlers();

require_once __DIR__ . '/../vendor/autoload.php';

/*
 * Настройки чата. Адрес Redis можно переопределить переменными окружения
 * REDIS_HOST и REDIS_PORT, если Redis слушает не на 127.0.0.1:6379.
 */
define('CHAT_HISTORY_MS', 30 * 60 * 1000);
define('CHAT_POLL_MS', 1000);
define('CHAT_PRESENCE_TTL', 6);
define('CHAT_LEAVE_GRACE_MS', 2000);
/*
 * Как часто ВООБЩЕ обходить chat:online в поисках ушедших. Не на клиента, а
 * на весь чат: без этого каждый опрос каждого пользователя перебирал бы всех.
 */
define('CHAT_REAP_INTERVAL', 1);
define('CHAT_NAME_MAX', 32);
define('CHAT_TEXT_MAX', 1000);
define('CHAT_RATE_WINDOW', 10);
define('CHAT_RATE_MAX', 20);

/*
 * Сколько сообщений максимум отдаёт догон по since за один /fetch. Без
 * ограничения клиент, вернувшийся через час, заставит Lua перебрать весь
 * накопившийся хвост, а Redis в это время не отвечает остальным. Хвост не
 * теряется: курсором становится seq последнего отданного сообщения, и
 * следующий опрос добирает остаток. К истории при первом входе лимит НЕ
 * применяется: там окно уже задано временем (CHAT_HISTORY_MS), а обрезка
 * означала бы потерю сообщений без возможности их переспросить.
 */
define('CHAT_FETCH_LIMIT', 100);

$redisHost = getenv('REDIS_HOST');
if ($redisHost === false || $redisHost === '') {
    $redisHost = '127.0.0.1';
}

$redisPort = getenv('REDIS_PORT');
if ($redisPort === false || $redisPort === '') {
    $redisPort = '6379';
}

$redis = new Client([
    'scheme' => 'tcp',
    'host' => $redisHost,
    'port' => (int) $redisPort,
]);

$isHttps = false;
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $isHttps = true;
}

/*
 * Имя, CSRF-токен и внутренний идентификатор живут в сессии. Клиент не
 * присылает их при отправке сообщения: сервер берёт их из $_SESSION.
 */
session_name('CHATSESS');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure' => $isHttps,
]);
ini_set('session.use_strict_mode', '1');
session_start();

if (!isset($_SESSION['uid'])) {
    $_SESSION['uid'] = bin2hex(random_bytes(16));
}
if (!isset($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
if (!isset($_SESSION['name'])) {
    $_SESSION['name'] = '';
}

$uid = (string) $_SESSION['uid'];
$csrf = (string) $_SESSION['csrf'];
$name = (string) $_SESSION['name'];

function chat_security_headers(): void
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
}

function chat_json(array $data, int $status = 200): void
{
    chat_security_headers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function chat_require_post(): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        chat_json(['error' => 'Нужен POST-запрос'], 405);
    }
}

function chat_require_csrf(): void
{
    $given = '';
    if (isset($_POST['csrf']) && is_string($_POST['csrf'])) {
        $given = $_POST['csrf'];
    }
    if (!hash_equals($_SESSION['csrf'], $given)) {
        chat_json(['error' => 'Недействительный CSRF-токен'], 403);
    }
}

/*
 * Обрезаем текст по символам, а не по байтам: иначе emoji и кириллица
 * на границе превращаются в битую строку. Проверка кодировки отсекает
 * не-UTF-8, который не смог бы пройти через json_encode.
 */
function chat_clean_text(mixed $value, int $max): string
{
    if (!is_string($value)) {
        return '';
    }
    $text = trim($value);
    if (!mb_check_encoding($text, 'UTF-8')) {
        return '';
    }
    return mb_substr($text, 0, $max, 'UTF-8');
}

function chat_since(mixed $value): ?int
{
    if ($value === null) {
        return null;
    }
    if (!is_string($value) && !is_int($value)) {
        return null;
    }
    if (!ctype_digit((string) $value)) {
        return null;
    }
    return (int) $value;
}

function chat_now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}
