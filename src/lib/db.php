<?php
declare(strict_types=1);

/**
 * Подключение к SQLite и автоматическая инициализация схемы + наполнение
 * демо-данными при первом запуске. Возвращает готовый объект PDO.
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = getenv('PHARMACY_DB_PATH') ?: __DIR__ . '/../../data/pharmacy.sqlite';
    $dir = dirname($path);

    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    // Понятная диагностика вместо криптичного «unable to open database file».
    if (!is_dir($dir)) {
        throw new RuntimeException(
            "Каталог для базы данных не существует и не может быть создан: {$dir}. " .
            "Создайте его и дайте права веб-серверу (www-data)."
        );
    }
    if (!is_writable($dir)) {
        $owner = function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : get_current_user();
        throw new RuntimeException(
            "Нет прав на запись в каталог БД: {$dir} (PHP работает от пользователя «{$owner}»). " .
            "Выполните на сервере: sudo chown -R www-data:www-data {$dir} && sudo chmod -R 775 {$dir}"
        );
    }
    if (file_exists($path) && !is_writable($path)) {
        throw new RuntimeException(
            "Нет прав на запись в файл БД: {$path}. " .
            "Выполните: sudo chown www-data:www-data {$path} && sudo chmod 664 {$path}"
        );
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode = WAL;');

    init_schema($pdo);
    seed_if_empty($pdo);

    return $pdo;
}

function init_schema(PDO $pdo): void
{
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS pharmacies (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            name          TEXT    NOT NULL,
            address       TEXT    NOT NULL,
            phone         TEXT,
            hours         TEXT,
            is_24h        INTEGER NOT NULL DEFAULT 0,
            lat           REAL    NOT NULL,
            lng           REAL    NOT NULL
        );
    SQL);

    // Лог визитов: местоположение, IP, регион/район и дата.
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS visits (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            ip                  TEXT,
            lat                 REAL,
            lng                 REAL,
            accuracy            REAL,
            country             TEXT,
            region              TEXT,   -- область / регион
            city                TEXT,   -- город
            district            TEXT,   -- район / микрорайон
            nearest_pharmacy_id INTEGER,
            nearest_distance_km REAL,
            device_type         TEXT,   -- mobile / tablet / desktop
            device_os           TEXT,   -- Android / iOS / Windows ...
            device_browser      TEXT,   -- Chrome / Safari / Firefox ...
            event               TEXT,   -- 'ping' или 'leave' (уход со страницы)
            user_agent          TEXT,
            created_at          TEXT NOT NULL   -- 'YYYY-MM-DD HH:MM:SS' (UTC)
        );
    SQL);
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_created_at ON visits(created_at);');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_visits_region ON visits(region);');

    // Блокировки входа в админку по IP (анти-брутфорс).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS admin_blocks (
            ip            TEXT PRIMARY KEY,
            fails         INTEGER NOT NULL DEFAULT 0,
            blocked_until TEXT
        );
    SQL);

    // Кэш обратного геокодирования (чтобы не дёргать Nominatim на каждый пинг).
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS geocode_cache (
            coord_key  TEXT PRIMARY KEY,   -- 'lat,lng' округлённые до 3 знаков
            country    TEXT,
            region     TEXT,
            city       TEXT,
            district   TEXT,
            created_at TEXT NOT NULL
        );
    SQL);

    // Мягкая миграция: добавляем новые столбцы, если база создана раньше.
    foreach (['device_type', 'device_os', 'device_browser', 'event'] as $col) {
        try {
            $pdo->exec("ALTER TABLE visits ADD COLUMN {$col} TEXT");
        } catch (Throwable $e) {
            // столбец уже существует — игнорируем
        }
    }
}

function seed_if_empty(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM pharmacies')->fetchColumn();
    if ($count > 0) {
        return;
    }

    // Демо-аптеки (координаты — г. Бишкек). Замените на реальные данные.
    $rows = [
        ['Аптека «Неман»',        'ул. Чуй, 124',                '+996 312 661122', 'Пн–Вс 08:00–22:00', 0, 42.876100, 74.603100],
        ['Аптека «Береке» 24ч',   'ул. Киевская, 95',            '+996 312 902030', 'Круглосуточно',     1, 42.874600, 74.612900],
        ['Аптека «Здоровье»',     'пр. Манаса, 40',              '+996 312 543210', 'Пн–Вс 09:00–21:00', 0, 42.869800, 74.598500],
        ['Аптека «Vita»',         'ул. Ибраимова, 115',          '+996 555 112233', 'Пн–Сб 08:30–20:00', 0, 42.866200, 74.612000],
        ['Аптека «Медицина» 24ч', 'ул. Льва Толстого, 17',       '+996 312 445566', 'Круглосуточно',     1, 42.859000, 74.606800],
        ['Аптека «Фармленд»',     'мкр. Восток-5, 12',           '+996 700 334455', 'Пн–Вс 08:00–23:00', 0, 42.875400, 74.640200],
        ['Аптека «Семейная»',     'ул. Юнусалиева, 200',         '+996 312 778899', 'Пн–Вс 09:00–20:00', 0, 42.855900, 74.598000],
        ['Аптека «Айгуль»',       'мкр. Джал, 23',               '+996 559 121314', 'Пн–Вс 08:00–22:00', 0, 42.829500, 74.567700],
        ['Аптека «Дары природы»', 'ул. Ахунбаева, 119',          '+996 312 151617', 'Пн–Сб 09:00–19:00', 0, 42.851700, 74.620400],
        ['Аптека «Неман» 24ч',    'пр. Чуй, 219 (ЦУМ)',          '+996 312 181920', 'Круглосуточно',     1, 42.877300, 74.616500],
    ];

    $stmt = $pdo->prepare(
        'INSERT INTO pharmacies (name, address, phone, hours, is_24h, lat, lng)
         VALUES (:name, :address, :phone, :hours, :is_24h, :lat, :lng)'
    );
    foreach ($rows as $r) {
        $stmt->execute([
            ':name'    => $r[0],
            ':address' => $r[1],
            ':phone'   => $r[2],
            ':hours'   => $r[3],
            ':is_24h'  => $r[4],
            ':lat'     => $r[5],
            ':lng'     => $r[6],
        ]);
    }
}
