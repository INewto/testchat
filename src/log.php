<?php

declare(strict_types=1);

/*
 * Папка логов лежит в корне проекта, рядом с public/ и src/. Docroot
 * сервера — public/, поэтому прочитать эти файлы из браузера нельзя.
 * Путь считаем от __DIR__, без буквы диска и имени машины.
 */
define('CHAT_LOG_DIR', dirname(__DIR__) . '/logs');

function chat_log_line(string $level, string $message): void
{
    if (!is_dir(CHAT_LOG_DIR)) {
        // @ не даёт warning'у уйти в тело HTTP-ответа. Результат всё равно
        // проверяется ниже: подавление здесь не значит «промолчать».
        @mkdir(CHAT_LOG_DIR, 0775, true);
    }

    if (!is_dir(CHAT_LOG_DIR)) {
        // Параллельный запрос мог создать папку между проверкой и mkdir,
        // поэтому смотрим не код возврата mkdir, а факт существования.
        error_log('chat: каталог логов недоступен: ' . CHAT_LOG_DIR);
        return;
    }

    // Один файл на день: логи не сваливаются в один бесконечно растущий файл.
    $file = CHAT_LOG_DIR . '/chat-' . date('Y-m-d') . '.log';
    $line = date('Y-m-d H:i:s') . ' [' . $level . '] ' . $message . PHP_EOL;

    // LOCK_EX не даёт двум запросам перемешать строки в одном файле.
    $written = @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

    if ($written === false) {
        // Свой лог не записался — оставляем хотя бы след в системном логе PHP.
        error_log('chat: не удалось записать лог в ' . $file);
    }
}

/*
 * getTraceAsString() включает аргументы вызовов, а среди них бывают uid из
 * сессии, имя и текст сообщения. Собираем трассировку сами: только место
 * вызова и его имя, без значений.
 */
function chat_log_trace(Throwable $error): string
{
    $lines = [];
    foreach ($error->getTrace() as $index => $frame) {
        $where = '[внутренний вызов]';
        if (isset($frame['file'])) {
            $where = $frame['file'] . ':' . $frame['line'];
        }

        $call = '';
        if (isset($frame['class'])) {
            $call = $frame['class'] . $frame['type'];
        }
        if (isset($frame['function'])) {
            $call = $call . $frame['function'] . '()';
        }

        $lines[] = '#' . $index . ' ' . $where . ' ' . $call;
    }
    return implode(PHP_EOL, $lines);
}

/*
 * В лог идут метод и адрес запроса, класс исключения, сообщение, файл со
 * строкой и трассировка. Тело POST (там CSRF-токен и текст сообщения) и
 * содержимое сессии не пишем.
 */
function chat_log_error(Throwable $error, string $place): void
{
    $method = '';
    if (isset($_SERVER['REQUEST_METHOD'])) {
        $method = (string) $_SERVER['REQUEST_METHOD'];
    }

    $uri = '';
    if (isset($_SERVER['REQUEST_URI'])) {
        $uri = (string) $_SERVER['REQUEST_URI'];
    }

    $text = $place
        . ' | ' . $method . ' ' . $uri
        . ' | ' . get_class($error) . ': ' . $error->getMessage()
        . ' | ' . $error->getFile() . ':' . $error->getLine()
        . PHP_EOL . chat_log_trace($error);

    chat_log_line('error', $text);
}

/*
 * Страховка на случай ошибки вне блока try в api.php: при загрузке
 * bootstrap, автозагрузчика Composer или при отдаче страницы. Без этого
 * обработчика такая ошибка попала бы только в системный лог PHP.
 */
function chat_install_error_handlers(): void
{
    set_exception_handler(function (Throwable $error): void {
        chat_log_error($error, 'необработанное исключение');

        $isApi = false;
        if (isset($_SERVER['SCRIPT_NAME'])) {
            if (basename((string) $_SERVER['SCRIPT_NAME']) === 'api.php') {
                $isApi = true;
            }
        }

        if (!headers_sent()) {
            http_response_code(500);
            if ($isApi) {
                header('Content-Type: application/json; charset=utf-8');
            } else {
                header('Content-Type: text/plain; charset=utf-8');
            }
        }

        if ($isApi) {
            echo json_encode(['error' => 'Ошибка сервера'], JSON_UNESCAPED_UNICODE);
        } else {
            echo 'Ошибка сервера';
        }
    });

    register_shutdown_function(function (): void {
        $last = error_get_last();
        if ($last === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
            E_RECOVERABLE_ERROR,
        ];
        if (!in_array($last['type'], $fatalTypes, true)) {
            return;
        }

        chat_log_line('error', 'фатальная ошибка | ' . $last['message']
            . ' | ' . $last['file'] . ':' . $last['line']);
    });
}
