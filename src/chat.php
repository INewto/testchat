<?php

declare(strict_types=1);

use Predis\Client;

/*
 * chat:seq       - счётчик сообщений, он же курсор клиента
 * chat:epoch     - идентификатор запуска Redis
 * chat:messages  - ZSET: score = seq, member = seq (ось порядка)
 * chat:timeline  - ZSET: score = время в мс, member = seq (ось времени)
 * chat:payloads  - HASH: field = seq, value = JSON сообщения
 * chat:online    - HASH: field = uid, value = имя
 * chat:leaving   - HASH: field = uid, value = момент начала ухода
 * chat:reap      - ключ с TTL на 1 секунду: право на обход chat:online
 * chat:seen:<uid> - ключ с TTL, который продлевает каждый опрос
 * chat:rate:<uid> - счётчик сообщений с TTL, ограничивает частоту
 */
define('CHAT_SEQ', 'chat:seq');
define('CHAT_EPOCH', 'chat:epoch');
define('CHAT_MESSAGES', 'chat:messages');
define('CHAT_TIMELINE', 'chat:timeline');
define('CHAT_PAYLOADS', 'chat:payloads');
define('CHAT_ONLINE', 'chat:online');
define('CHAT_LEAVING', 'chat:leaving');
define('CHAT_REAP', 'chat:reap');
define('CHAT_SEEN_PREFIX', 'chat:seen:');

function chat_seen_key(string $uid): string
{
    return CHAT_SEEN_PREFIX . $uid;
}

/*
 * Одна команда EVAL вместо четырёх: читатель не должен увидеть новый
 * курсор раньше, чем само сообщение окажется в chat:payloads.
 */
function chat_append(Client $redis, string $type, string $name, string $text): int
{
    $now = chat_now_ms();

    $payload = [
        'type' => $type,
        'name' => $name,
        'text' => $text,
        'time' => $now,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $script = <<<'LUA'
local seq = redis.call('INCR', KEYS[1])
local member = tostring(seq)
redis.call('ZADD', KEYS[2], seq, member)
redis.call('ZADD', KEYS[3], ARGV[1], member)
redis.call('HSET', KEYS[4], member, ARGV[2])
return seq
LUA;

    $seq = $redis->eval(
        $script,
        4,
        CHAT_SEQ,
        CHAT_MESSAGES,
        CHAT_TIMELINE,
        CHAT_PAYLOADS,
        $now,
        $json
    );

    return (int) $seq;
}

/*
 * Всё присутствие - один EVAL: вход/продление текущего пользователя,
 * системные сообщения и уборка ушедших. Redis исполняет скрипт целиком,
 * поэтому другой /fetch не может вклиниться между HDEL chat:online и
 * DEL пульса и получить «вошёл в чат» сразу перед «покинул чат».
 *
 * Шаг 0 разбирается с собственным незавершённым выходом ДО уборки и без
 * троттлинга: уборка запускается только из /fetch, а форма входа уже могла
 * быть показана по chat:leaving. Без шага 0 последняя вкладка чата теряет
 * «покинул чат» и при возврате не пишет «вошёл в чат».
 *
 * Ключи chat:seen:<uid> собираются внутри скрипта из префикса ARGV[6]:
 * Redis здесь один, без кластера, все ключи лежат в одной базе.
 *
 * KEYS: 1=seq, 2=messages, 3=timeline, 4=payloads, 5=online, 6=leaving,
 *       7=reap
 * ARGV: 1=uid, 2=имя, 3=сейчас в мс, 4=TTL пульса, 5=grace в мс,
 *       6=префикс ключа пульса, 7=интервал уборки в секундах
 */
function chat_presence(Client $redis, string $uid, string $name): void
{
    $script = <<<'LUA'
local function append(kind, who, text, now)
    local seq = redis.call('INCR', KEYS[1])
    local member = tostring(seq)
    redis.call('ZADD', KEYS[2], seq, member)
    redis.call('ZADD', KEYS[3], now, member)
    local payload = cjson.encode({
        type = kind,
        name = who,
        text = text,
        time = tonumber(now)
    })
    redis.call('HSET', KEYS[4], member, payload)
end

local now = tonumber(ARGV[3])
local grace = tonumber(ARGV[5])
local prefix = ARGV[6]

-- 0. Незавершённый выход САМОГО пользователя. Уборка ниже живёт только в
--    /fetch и ещё прикрыта троттлингом: если вкладка была последней, опрашивать
--    чат некому, «покинул чат» не напишется, а старый uid останется в
--    chat:online. Форму входа при этом уже показали (chat_is_present() видит
--    пометку ухода), человек жмёт «Войти» — и без этой ветки не получит ни
--    «покинул чат», ни нового «вошёл в чат».
--
--    Выход считаем состоявшимся, если пометка ухода старше grace либо пульс
--    истёк, а пометки нет (beacon не дошёл). Свежая пометка внутри grace —
--    это F5, её не трогаем. Проверки и записи все здесь, в том же EVAL.
local wasOnline = redis.call('HEXISTS', KEYS[5], ARGV[1])
if wasOnline == 1 then
    local leaveAt = redis.call('HGET', KEYS[6], ARGV[1])
    local gone = false
    if leaveAt then
        if now - tonumber(leaveAt) >= grace then
            gone = true
        end
    else
        if redis.call('EXISTS', prefix .. ARGV[1]) == 0 then
            gone = true
        end
    end
    if gone then
        local who = redis.call('HGET', KEYS[5], ARGV[1])
        append('system', who, 'покинул чат', ARGV[3])
        redis.call('HDEL', KEYS[5], ARGV[1])
        redis.call('HDEL', KEYS[6], ARGV[1])
        wasOnline = 0
    end
end

-- 1. Текущий пользователь. HEXISTS и HSET в одной команде: два параллельных
--    первых входа одного uid не напишут «вошёл в чат» дважды. wasOnline уже
--    мог стать нулём в шаге 0 — тогда это честный повторный вход.
redis.call('HSET', KEYS[5], ARGV[1], ARGV[2])
redis.call('SET', prefix .. ARGV[1], '1', 'EX', ARGV[4])
redis.call('HDEL', KEYS[6], ARGV[1])
if wasOnline == 0 then
    append('system', ARGV[2], 'вошёл в чат', ARGV[3])
end

-- 2. Уборка ушедших. Без троттлинга U онлайн-клиентов * U пользователей
--    дают ~2U² вызовов в секунду в одном потоке Redis. Право на обход
--    получает тот, кто первым создал chat:reap; ключ живёт одну секунду
--    и исчезает сам. Снимок HGETALL и ВСЕ проверки сделаны здесь же:
--    решение не опирается на данные, прочитанные в PHP до скрипта.
--    Касание текущего uid выше выполняется всегда, уборка может подождать.
local claimed = redis.call('SET', KEYS[7], '1', 'NX', 'EX', ARGV[7])
if claimed and claimed.ok then
    local users = redis.call('HGETALL', KEYS[5])
    for i = 1, #users, 2 do
        local user = users[i]
        local who = users[i + 1]
        local pulse = prefix .. user

        if redis.call('EXISTS', pulse) == 1 then
            -- Вкладка вернулась: отменяем начатый уход.
            redis.call('HDEL', KEYS[6], user)
        else
            local leaveAt = redis.call('HGET', KEYS[6], user)
            if not leaveAt then
                -- Пульс истёк: даём время на перезагрузку страницы.
                redis.call('HSET', KEYS[6], user, ARGV[3])
            elseif now - tonumber(leaveAt) >= grace then
                local removed = redis.call('HDEL', KEYS[5], user)
                if removed == 1 then
                    append('system', who, 'покинул чат', ARGV[3])
                    redis.call('HDEL', KEYS[6], user)
                    redis.call('DEL', pulse)
                end
            end
        end
    end
end

return 1
LUA;

    $redis->eval(
        $script,
        7,
        CHAT_SEQ,
        CHAT_MESSAGES,
        CHAT_TIMELINE,
        CHAT_PAYLOADS,
        CHAT_ONLINE,
        CHAT_LEAVING,
        CHAT_REAP,
        $uid,
        $name,
        chat_now_ms(),
        CHAT_PRESENCE_TTL,
        CHAT_LEAVE_GRACE_MS,
        CHAT_SEEN_PREFIX,
        CHAT_REAP_INTERVAL
    );
}

/*
 * Браузер закрывают - sendBeacon только ускоряет уход. Окончательное
 * решение всё равно принимает сервер в chat_presence(). Проверка и обе
 * записи здесь тоже одной командой, без окна между ними.
 */
function chat_mark_leaving(Client $redis, string $uid): void
{
    $script = <<<'LUA'
if redis.call('HEXISTS', KEYS[1], ARGV[1]) == 1 then
    redis.call('HSET', KEYS[2], ARGV[1], ARGV[2])
    redis.call('DEL', KEYS[3])
end
return 1
LUA;

    $redis->eval(
        $script,
        3,
        CHAT_ONLINE,
        CHAT_LEAVING,
        chat_seen_key($uid),
        $uid,
        chat_now_ms()
    );
}

/*
 * Считать ли пользователя вошедшим при загрузке страницы. Сессия хранит
 * имя, но не знает, что вкладку закрыли: «покинул чат» пишет Redis. Поэтому
 * форму входа показываем по состоянию Redis, а не по $_SESSION['name'].
 *
 * Три признака, все уже используются в chat_presence() / chat_mark_leaving():
 *   chat:online      - уборщик удалил отсюда, значит выход зафиксирован;
 *   chat:leaving     - пометка ухода от beacon или уборщика, время в мс;
 *   chat:seen:<uid>  - пульс живой вкладки, TTL = CHAT_PRESENCE_TTL.
 *
 * Отдельные команды здесь допустимы: это чтение на загрузку страницы, а не
 * снимок ответа /fetch. Худший исход гонки - лишний показ формы; курсор
 * живёт в JavaScript и при перезагрузке запрашивает историю заново, поэтому
 * сообщения не теряются.
 */
function chat_is_present(Client $redis, string $uid): bool
{
    // Уборщик уже написал «покинул чат» и убрал пользователя из списка.
    if ((int) $redis->hexists(CHAT_ONLINE, $uid) === 0) {
        return false;
    }

    $leaveAt = $redis->hget(CHAT_LEAVING, $uid);

    if ($leaveAt !== null) {
        // Пометка ухода. Быстрое возвращение (F5) ещё внутри grace: это не
        // выход, первый же /fetch сотрёт пометку и не напишет «вошёл» заново.
        if (chat_now_ms() - (int) $leaveAt < CHAT_LEAVE_GRACE_MS) {
            return true;
        }
        // Grace истёк, а вкладка так и не вернулась - выход состоялся.
        return false;
    }

    // Beacon не приходил (вкладку убили или пакет не успел). Живой пульс
    // означает открытую вкладку, истёкший - что чат давно не опрашивался.
    if ((int) $redis->exists(chat_seen_key($uid)) === 1) {
        return true;
    }
    return false;
}

/*
 * Все поля ответа читаются ОДНИМ EVAL. Redis исполняет скрипт целиком,
 * поэтому FLUSHDB или рестарт не могут вклиниться между чтением epoch,
 * курсора, сообщений и списка пользователей: ответ всегда собран из
 * одного состояния базы.
 *
 * Ветка since ограничена CHAT_FETCH_LIMIT: иначе вернувшийся через час
 * клиент заставит скрипт перебрать весь накопившийся хвост, а Redis в это
 * время недоступен остальным. Курсором становится seq последнего ОТДАННОГО
 * сообщения - с общим chat:seq клиент перепрыгнул бы через необработанный
 * хвост и потерял бы его навсегда; следующий опрос добирает остаток.
 *
 * Ветка history лимита НЕ имеет: она и так ограничена окном в 30 минут,
 * а любая обрезка там стала бы безвозвратной потерей - курсор ходит только
 * вперёд, переспросить более старые сообщения клиент не может.
 *
 * KEYS: 1=seq, 2=epoch, 3=messages, 4=timeline, 5=payloads, 6=online
 * ARGV: 1=режим, 2=since, 3=граница 30 минут, 4=новый epoch, 5=лимит
 *
 * Контракт «всё или ничего»: не собрав состояние, функция бросает
 * RuntimeException. Ловить его здесь и в chat_fetch() НЕЛЬЗЯ — подмена
 * ответа пустым набором полей выглядела бы как нормальный пустой чат.
 * Исключение доходит до catch (Throwable) в public/api.php: запись в лог
 * и 500 JSON. Клиент при такой ошибке не вызывает applyData(), хранит
 * прежние since и epoch и через интервал опроса повторяет тот же запрос;
 * ретрай на сервере не нужен.
 */
function chat_snapshot(Client $redis, ?int $since): array
{
    $mode = 'since';
    $sinceValue = '0';
    if ($since === null) {
        $mode = 'history';
    } else {
        $sinceValue = (string) $since;
    }

    $from = chat_now_ms() - CHAT_HISTORY_MS;
    $epochSeed = bin2hex(random_bytes(8));

    $script = <<<'LUA'
local epoch = redis.call('GET', KEYS[2])
if not epoch then
    redis.call('SETNX', KEYS[2], ARGV[4])
    epoch = redis.call('GET', KEYS[2])
end

local seq = redis.call('GET', KEYS[1])
if not seq then
    seq = '0'
end

local numbers = {}
if ARGV[1] == 'history' then
    -- История за полчаса приходит ЦЕЛИКОМ, одним запросом по времени.
    -- LIMIT здесь нельзя: при равных миллисекундах ZSET сортирует member
    -- строками, LIMIT отрезал бы лексикографический хвост, а отрезанное
    -- больше никто не спросит — курсор уходит только вперёд. Порядок
    -- наводит table.sort ниже, он числовой. Окно в 30 минут и есть граница
    -- работы скрипта; отдельный лимит нужен только ветке since.
    local found = redis.call('ZRANGEBYSCORE', KEYS[4], ARGV[3], '+inf')
    for i = 1, #found do
        numbers[#numbers + 1] = tonumber(found[i])
    end
else
    -- Догоняем курсор: первые N сообщений после since.
    local found = redis.call('ZRANGEBYSCORE', KEYS[3], '(' .. ARGV[2], '+inf', 'LIMIT', 0, ARGV[5])
    for i = 1, #found do
        numbers[#numbers + 1] = tonumber(found[i])
    end
end

-- ZRANGEBYSCORE вернул сообщения по времени, а не по seq: при равных
-- миллисекундах ZSET упорядочивает member строками ('99' > '251'). На экран
-- нужен числовой порядок, поэтому сортируем явно.
-- Фильтра «member <= chat:seq» нет: chat_append() пишет курсор и индекс
-- одним EVAL, больший member появиться не может.
table.sort(numbers)

local seqs = {}
local payloads = {}
for i = 1, #numbers do
    local raw = redis.call('HGET', KEYS[5], tostring(numbers[i]))
    if raw then
        seqs[#seqs + 1] = numbers[i]
        payloads[#payloads + 1] = raw
    end
end

-- История отдана целиком: её курсор - общий chat:seq, и всё, что запишется
-- дальше, и есть новые сообщения. seq последнего отданного здесь не годится:
-- отфильтрованное по времени сообщение с бОльшим seq вернулось бы следующим
-- опросом как «новое». В ветке since ответ может быть обрезан лимитом, там
-- курсор = seq последнего ОТДАННОГО, иначе хвост потеряется. При пустом
-- ответе в обеих ветках остаётся общий курсор: завышенный since не должен
-- заклинивать клиента на одном значении.
local cursor = tonumber(seq)
if ARGV[1] ~= 'history' and #seqs > 0 then
    cursor = seqs[#seqs]
end

return cjson.encode({
    epoch = epoch,
    seq = cursor,
    seqs = seqs,
    payloads = payloads,
    users = redis.call('HVALS', KEYS[6])
})
LUA;

    $raw = $redis->eval(
        $script,
        6,
        CHAT_SEQ,
        CHAT_EPOCH,
        CHAT_MESSAGES,
        CHAT_TIMELINE,
        CHAT_PAYLOADS,
        CHAT_ONLINE,
        $mode,
        $sinceValue,
        $from,
        $epochSeed,
        CHAT_FETCH_LIMIT
    );

    /*
     * Три проверки ниже — намеренный отказ от частичного ответа, а не место
     * для fallback. Про исключение, 500 и ретрай с тем же since смотри
     * контракт в комментарии к chat_snapshot().
     */
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Не удалось разобрать состояние чата из Redis');
    }
    if (!isset($data['epoch']) || !is_string($data['epoch']) || $data['epoch'] === '') {
        throw new RuntimeException('В Redis отсутствует идентификатор чата');
    }
    if (!isset($data['seq']) || !is_numeric($data['seq'])) {
        throw new RuntimeException('В Redis отсутствует курсор сообщений');
    }

    $seqs = [];
    if (isset($data['seqs']) && is_array($data['seqs'])) {
        $seqs = $data['seqs'];
    }
    $payloads = [];
    if (isset($data['payloads']) && is_array($data['payloads'])) {
        $payloads = $data['payloads'];
    }

    $messages = [];
    foreach ($seqs as $index => $seq) {
        if (!isset($payloads[$index]) || !is_string($payloads[$index])) {
            continue;
        }
        $message = json_decode($payloads[$index], true);
        if (!is_array($message)) {
            continue;
        }
        $message['seq'] = (int) $seq;
        $messages[] = $message;
    }

    $users = [];
    if (isset($data['users']) && is_array($data['users'])) {
        foreach ($data['users'] as $name) {
            $users[] = (string) $name;
        }
    }
    sort($users, SORT_STRING);

    return [
        'epoch' => $data['epoch'],
        'seq' => (int) $data['seq'],
        'messages' => $messages,
        'users' => $users,
    ];
}

/*
 * INCR и выставление TTL должны быть одной командой. Иначе падение между
 * ними оставит ключ без TTL, а счётчик навсегда застрянет выше лимита:
 * условие count == 1 больше не наступит и expire уже никто не поставит.
 * Проверка TTL < 0 вдобавок лечит ключи, оставшиеся от прежней версии.
 */
function chat_rate_ok(Client $redis, string $uid): bool
{
    $key = 'chat:rate:' . $uid;

    $script = <<<'LUA'
local count = redis.call('INCR', KEYS[1])
local ttl = redis.call('TTL', KEYS[1])
if ttl < 0 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return count
LUA;

    $count = (int) $redis->eval($script, 1, $key, CHAT_RATE_WINDOW);
    if ($count > CHAT_RATE_MAX) {
        return false;
    }
    return true;
}

function chat_fetch(Client $redis, string $uid, string $name, ?int $since): array
{
    // Один EVAL на присутствие: вход или продление пульса, затем уборка.
    chat_presence($redis, $uid, $name);

    // Отдельный EVAL только на чтение ответа из одного состояния базы.
    return chat_snapshot($redis, $since);
}
