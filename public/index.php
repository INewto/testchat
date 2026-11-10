<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/../src/chat.php';

chat_security_headers();
header('Content-Type: text/html; charset=utf-8');

/*
 * HEX-флаги не дают строке из конфига закрыть тег <script> или испортить
 * атрибут. Все пользовательские строки на странице идут через {{ }},
 * поэтому Vue экранирует их сам; v-html здесь не используется.
 */
$flags = JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT;

/*
 * Имя в сессии - это «под каким именем заходили», а НЕ «сейчас в чате».
 * Признак присутствия лежит в Redis: именно там уборщик фиксирует уход
 * после закрытия вкладки. Если человека в чате уже нет, отдаём пустое
 * имя - chat.js покажет форму «Вход в чат» и не запустит опрос, а значит
 * не напишет «вошёл в чат» просто от открытия страницы.
 *
 * Сессию при этом НЕ чистим: имя нужно для F5 и для других живых вкладок
 * того же пользователя. Если Redis недоступен, проверку пропускаем и
 * оставляем имя как есть - страница должна открыться, 500 придёт от /fetch.
 */
$pageName = $name;
if ($pageName !== '') {
    try {
        if (!chat_is_present($redis, $uid)) {
            $pageName = '';
        }
    } catch (Throwable $e) {
        chat_log_error($e, 'index.php');
    }
}

$config = [
    'csrf' => $csrf,
    'name' => $pageName,
    'pollMs' => CHAT_POLL_MS,
    'nameMax' => CHAT_NAME_MAX,
    'textMax' => CHAT_TEXT_MAX,
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Чат</title>
    <link rel="stylesheet" href="/css/chat.css">
</head>
<body>
<div id="app" v-cloak>
    <div v-if="!joined" class="gate">
        <form class="gate-form" @submit.prevent="join">
            <h1>Вход в чат</h1>
            <label for="name">Как вас зовут?</label>
            <input id="name" type="text" v-model="nameInput" :maxlength="config.nameMax" autocomplete="off" autofocus>
            <button type="submit">Войти</button>
            <p v-if="status" class="status">{{ status }}</p>
        </form>
    </div>

    <div v-else class="chat">
        <header class="chat-header">
            <span>Чат</span>
            <span class="chat-me">{{ name }}</span>
            <span v-if="status" class="status">{{ status }}</span>
        </header>

        <div class="chat-body">
            <main class="chat-main">
                <div class="messages" ref="messageList">
                    <div v-for="message in messages" :key="message.seq" :class="message.rowClass">
                        <span class="time">{{ message.timeText }}</span>
                        <span v-if="message.type === 'system'" class="system-text">{{ message.name }} {{ message.text }}</span>
                        <span v-if="message.type === 'message'" class="author">{{ message.name }}:</span>
                        <span v-if="message.type === 'message'" class="text">{{ message.text }}</span>
                    </div>
                </div>

                <form class="composer" @submit.prevent="send">
                    <input type="text" v-model="textInput" :maxlength="config.textMax" placeholder="Сообщение" autocomplete="off">
                    <button type="submit" :disabled="sending">Отправить</button>
                </form>
            </main>

            <aside class="users">
                <h2>В чате</h2>
                <ul>
                    <li v-for="(user, index) in users" :key="index">{{ user }}</li>
                </ul>
            </aside>
        </div>
    </div>
</div>

<script>
    window.CHAT_CONFIG = <?= json_encode($config, $flags) ?>;
</script>
<script src="/js/vue.min.js"></script>
<script src="/js/chat.js"></script>
</body>
</html>
