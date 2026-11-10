<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/chat.php';

$action = '';
if (isset($_GET['action']) && is_string($_GET['action'])) {
    $action = $_GET['action'];
}

try {
    if ($action === 'fetch') {
        if ($name === '') {
            chat_json(['error' => 'Сначала войдите в чат'], 403);
        }

        $since = null;
        if (isset($_GET['since'])) {
            $since = chat_since($_GET['since']);
        }

        chat_json(chat_fetch($redis, $uid, $name, $since));

    } elseif ($action === 'join') {
        chat_require_post();
        chat_require_csrf();

        $enteredName = '';
        if (isset($_POST['name'])) {
            $enteredName = chat_clean_text($_POST['name'], CHAT_NAME_MAX);
        }
        if ($enteredName === '') {
            chat_json(['error' => 'Введите имя'], 400);
        }

        chat_presence($redis, $uid, $enteredName);

        /*
         * Сессию меняем только после успешной записи в Redis. Если вход упал,
         * имя не должно остаться в сессии: иначе страница решит, что человек
         * уже в чате, и не покажет форму входа.
         */
        session_regenerate_id(true);
        $_SESSION['name'] = $enteredName;
        chat_json(['ok' => true, 'name' => $enteredName]);

    } elseif ($action === 'send') {
        chat_require_post();
        chat_require_csrf();

        if ($name === '') {
            chat_json(['error' => 'Сначала войдите в чат'], 403);
        }
        if (!$redis->hexists(CHAT_ONLINE, $uid)) {
            chat_json(['error' => 'Сначала войдите в чат'], 403);
        }

        $text = '';
        if (isset($_POST['text'])) {
            $text = chat_clean_text($_POST['text'], CHAT_TEXT_MAX);
        }
        if ($text === '') {
            chat_json(['error' => 'Пустое сообщение'], 400);
        }
        if (!chat_rate_ok($redis, $uid)) {
            chat_json(['error' => 'Слишком много сообщений'], 429);
        }

        chat_append($redis, 'message', $name, $text);
        chat_json(['ok' => true]);

    } elseif ($action === 'leave') {
        chat_require_post();
        chat_require_csrf();

        chat_mark_leaving($redis, $uid);
        chat_json(['ok' => true]);

    } else {
        chat_json(['error' => 'Неизвестное действие'], 404);
    }
} catch (Throwable $e) {
    /*
     * Штатный конец для исключений чтения состояния: chat_snapshot() не
     * собрал ответ. Пишем лог и отдаём 500 JSON, клиент повторит опрос
     * с тем же since — applyData() при ошибке не вызывается, курсор и
     * epoch остаются прежними. НЕ подменять это на 200 с пустыми
     * messages/users и epoch = null: в клиенте null означает «ещё не
     * инициализирован», такой ответ выключит проверку смены epoch и
     * после рестарта Redis новые сообщения потеряются молча.
     */
    chat_log_error($e, 'api.php');
    chat_json(['error' => 'Ошибка сервера'], 500);
}
