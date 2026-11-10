(function () {
    'use strict';

    var config = window.CHAT_CONFIG;

    // since = null означает «ещё не получали историю»; число - курсор новых сообщений.
    var since = null;
    var epoch = null;
    var pollingStarted = false;

    /*
     * Vue отвечает только за то, что видно на экране: форму входа, список
     * сообщений и список пользователей. Сеть, курсор и таймер живут ниже
     * обычным JavaScript и просто кладут результат в данные Vue.
     */
    var app = new Vue({
        el: '#app',
        data: {
            config: config,
            joined: false,
            name: '',
            nameInput: '',
            textInput: '',
            messages: [],
            users: [],
            status: '',
            sending: false
        },
        methods: {
            join: joinChat,
            send: sendMessage
        }
    });

    function twoDigits(value) {
        var text = '0' + value;
        return text.slice(-2);
    }

    function formatTime(milliseconds) {
        var date = new Date(milliseconds);
        return twoDigits(date.getHours())
            + ':' + twoDigits(date.getMinutes())
            + ':' + twoDigits(date.getSeconds());
    }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.set('csrf', config.csrf);
        Object.keys(fields).forEach(function (key) {
            body.set(key, fields[key]);
        });

        return fetch('/api.php?action=' + action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    if (data.error) {
                        throw new Error(data.error);
                    }
                    throw new Error('Ошибка запроса');
                }
                if (data.error) {
                    throw new Error(data.error);
                }
                return data;
            });
        });
    }

    function scrollToBottom() {
        var list = app.$refs.messageList;
        if (list) {
            list.scrollTop = list.scrollHeight;
        }
    }

    function applyData(data) {
        var needHistory = false;

        if (epoch !== null && data.epoch !== epoch) {
            // Счётчик seq начался заново: старый курсор больше не годится.
            app.messages = [];
            needHistory = true;
        }
        epoch = data.epoch;

        if (since === null) {
            // Ответ без since содержит историю за полчаса целиком.
            app.messages = [];
        }

        var added = 0;
        data.messages.forEach(function (message) {
            message.timeText = formatTime(message.time);
            if (message.type === 'system') {
                message.rowClass = 'message message-system';
            } else {
                message.rowClass = 'message';
            }
            app.messages.push(message);
            added = added + 1;
        });

        app.users = data.users;

        if (needHistory) {
            // Следующий опрос пойдёт без since и заберёт историю заново.
            since = null;
        } else {
            since = data.seq;
        }
        app.status = '';

        if (added > 0) {
            Vue.nextTick(scrollToBottom);
        }
    }

    /*
     * Короткий опрос: один запрос раз в секунду, следующий ставится только
     * после завершения предыдущего, поэтому запросы не накладываются.
     */
    function poll() {
        var url = '/api.php?action=fetch';
        if (since !== null) {
            url = url + '&since=' + since;
        }

        fetch(url, { credentials: 'same-origin' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Сервер вернул ошибку');
                }
                return response.json();
            })
            .then(function (data) {
                applyData(data);
            })
            .catch(function () {
                /*
                 * Сюда попадает ЛЮБАЯ ошибка опроса: и обрыв связи, и 500
                 * от живого сервера. applyData() не выполнялся, поэтому
                 * since и epoch остались прежними — следующий опрос пойдёт
                 * с тем же курсором и ничего не потеряет и не задвоит.
                 * Присутствие на сервере могло уже обновиться (оно пишется
                 * до снимка), но на курсор это не влияет.
                 */
                app.status = 'Не удалось обновить чат, повторяем…';
            })
            .then(function () {
                window.setTimeout(poll, config.pollMs);
            });
    }

    function startPolling() {
        if (pollingStarted) {
            return;
        }
        pollingStarted = true;
        poll();
    }

    function joinChat() {
        var enteredName = app.nameInput.trim();
        if (enteredName === '') {
            app.status = 'Введите имя';
            return;
        }

        post('join', { name: enteredName })
            .then(function (data) {
                app.name = data.name;
                app.joined = true;
                app.status = '';
                startPolling();
            })
            .catch(function (error) {
                app.status = error.message;
            });
    }

    function sendMessage() {
        var text = app.textInput.trim();
        if (text === '') {
            return;
        }
        if (app.sending) {
            return;
        }

        app.sending = true;
        post('send', { text: text })
            .then(function () {
                app.textInput = '';
                app.sending = false;
            })
            .catch(function (error) {
                app.sending = false;
                app.status = error.message;
            });
    }

    /*
     * best-effort: вкладку закрывают - сообщаем об уходе. Если браузер не
     * успеет отправить пакет, сервер всё равно заметит истечение TTL.
     * pagehide срабатывает и при F5, поэтому сервер даёт две секунды на возврат.
     */
    window.addEventListener('pagehide', function () {
        if (!app.joined) {
            return;
        }
        if (!navigator.sendBeacon) {
            return;
        }
        var body = new URLSearchParams();
        body.set('csrf', config.csrf);
        navigator.sendBeacon('/api.php?action=leave', body);
    });

    if (config.name !== '') {
        // Сессия уже знает имя: обновление страницы, входим без повторного «вошёл в чат».
        app.joined = true;
        app.name = config.name;
        startPolling();
    }
})();
