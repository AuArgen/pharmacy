<?php
declare(strict_types=1);

/**
 * Админ-панель с отчётами по визитам.
 * Простая защита паролем (переменная окружения ADMIN_PASSWORD, по умолчанию "admin123").
 */

require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/visit.php';   // client_ip()

session_start();

$ADMIN_PASSWORD = getenv('ADMIN_PASSWORD') ?: 'admin123';
$BLOCK_SECONDS  = 60; // блокировка IP после неудачной попытки

$pdo = get_db();
$clientIp = client_ip();

// --- Выход ---
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit;
}

/**
 * Сколько секунд осталось до конца блокировки IP (0 — не заблокирован).
 */
function block_remaining(PDO $pdo, string $ip): int
{
    $stmt = $pdo->prepare('SELECT blocked_until FROM admin_blocks WHERE ip = :ip');
    $stmt->execute([':ip' => $ip]);
    $until = $stmt->fetchColumn();
    if (!$until) {
        return 0;
    }
    $diff = strtotime($until . ' UTC') - time();
    return $diff > 0 ? $diff : 0;
}

$loginError = '';
$blockLeft = block_remaining($pdo, $clientIp);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['password'])) {
    if ($blockLeft > 0) {
        // IP заблокирован — пароль даже не проверяем.
        $loginError = "Слишком много попыток. Повторите через {$blockLeft} сек.";
    } elseif (hash_equals($ADMIN_PASSWORD, (string) $_POST['password'])) {
        // Успех — снимаем блокировку и пускаем.
        $pdo->prepare('DELETE FROM admin_blocks WHERE ip = :ip')->execute([':ip' => $clientIp]);
        $_SESSION['admin'] = true;
        header('Location: admin.php');
        exit;
    } else {
        // Неудача — блокируем этот IP на BLOCK_SECONDS.
        $until = gmdate('Y-m-d H:i:s', time() + $BLOCK_SECONDS);
        $pdo->prepare(
            'INSERT INTO admin_blocks (ip, fails, blocked_until)
             VALUES (:ip, 1, :until)
             ON CONFLICT(ip) DO UPDATE SET fails = fails + 1, blocked_until = :until'
        )->execute([':ip' => $clientIp, ':until' => $until]);

        $blockLeft = $BLOCK_SECONDS;
        $loginError = "Неверный пароль. IP заблокирован на {$BLOCK_SECONDS} сек.";
    }
}

$isAuthed = !empty($_SESSION['admin']);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

// --- Страница логина ---
if (!$isAuthed) {
    ?>
    <!DOCTYPE html>
    <html lang="ru"><head>
        <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Вход — Админка АптекаРядом</title>
        <script src="https://cdn.tailwindcss.com"></script>
    </head>
    <body class="bg-slate-100 min-h-screen grid place-items-center font-sans">
        <form method="post" class="w-full max-w-sm bg-white rounded-2xl shadow-xl p-8 m-4">
            <div class="text-3xl text-center"><?= $blockLeft > 0 ? '⛔' : '🔒' ?></div>
            <h1 class="mt-3 text-xl font-extrabold text-center text-slate-900">Админ-панель</h1>
            <p class="text-center text-slate-500 text-sm mt-1">Отчёты по визитам</p>
            <?php if ($loginError): ?>
                <p class="mt-4 text-sm text-red-600 bg-red-50 rounded-lg px-3 py-2 text-center"><?= h($loginError) ?></p>
            <?php endif; ?>

            <?php if ($blockLeft > 0): ?>
                <div class="mt-5 text-center">
                    <p class="text-slate-500 text-sm">Вход временно заблокирован для вашего IP.</p>
                    <div id="countdown" class="mt-2 text-4xl font-extrabold text-red-500" data-left="<?= (int) $blockLeft ?>">
                        <?= (int) $blockLeft ?> сек
                    </div>
                </div>
                <button disabled
                        class="mt-5 w-full px-4 py-3 rounded-xl bg-slate-200 text-slate-400 font-semibold cursor-not-allowed">
                    Подождите…
                </button>
                <script>
                    (function () {
                        const el = document.getElementById('countdown');
                        let left = parseInt(el.dataset.left, 10);
                        const t = setInterval(() => {
                            left--;
                            if (left <= 0) { clearInterval(t); location.reload(); return; }
                            el.textContent = left + ' сек';
                        }, 1000);
                    })();
                </script>
            <?php else: ?>
                <input type="password" name="password" placeholder="Пароль" autofocus
                       class="mt-5 w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none">
                <button class="mt-3 w-full px-4 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-semibold transition">
                    Войти
                </button>
                <p class="mt-4 text-xs text-slate-400 text-center">Пароль задаётся переменной ADMIN_PASSWORD</p>
            <?php endif; ?>
        </form>
    </body></html>
    <?php
    exit;
}

// --- Данные отчётов ---
$pdo = get_db();

$totalVisits = (int) $pdo->query('SELECT COUNT(*) FROM visits')->fetchColumn();
$today = gmdate('Y-m-d');
$stmt = $pdo->prepare("SELECT COUNT(*) FROM visits WHERE created_at >= :d");
$stmt->execute([':d' => $today . ' 00:00:00']);
$visitsToday = (int) $stmt->fetchColumn();

$uniqueIps = (int) $pdo->query('SELECT COUNT(DISTINCT ip) FROM visits')->fetchColumn();

$byRegion = $pdo->query(
    "SELECT COALESCE(NULLIF(region,''),'— не определён —') AS label, COUNT(*) AS cnt
     FROM visits GROUP BY label ORDER BY cnt DESC LIMIT 12"
)->fetchAll();

$byDistrict = $pdo->query(
    "SELECT COALESCE(NULLIF(district,''), COALESCE(NULLIF(city,''),'— не определён —')) AS label, COUNT(*) AS cnt
     FROM visits GROUP BY label ORDER BY cnt DESC LIMIT 12"
)->fetchAll();

$byDate = $pdo->query(
    "SELECT substr(created_at,1,10) AS d, COUNT(*) AS cnt
     FROM visits GROUP BY d ORDER BY d DESC LIMIT 14"
)->fetchAll();
$byDate = array_reverse($byDate);

$topPharm = $pdo->query(
    "SELECT p.name AS label, COUNT(*) AS cnt
     FROM visits v JOIN pharmacies p ON p.id = v.nearest_pharmacy_id
     GROUP BY v.nearest_pharmacy_id ORDER BY cnt DESC LIMIT 10"
)->fetchAll();

$byDevice = $pdo->query(
    "SELECT COALESCE(NULLIF(device_type,''),'—') AS label, COUNT(*) AS cnt
     FROM visits GROUP BY label ORDER BY cnt DESC"
)->fetchAll();

$byBrowser = $pdo->query(
    "SELECT COALESCE(NULLIF(device_browser,''),'—') AS label, COUNT(*) AS cnt
     FROM visits GROUP BY label ORDER BY cnt DESC LIMIT 8"
)->fetchAll();

// --- Журнал визитов: поиск + сортировка + пагинация ---
$q = trim((string) ($_GET['q'] ?? ''));

// Белый список сортируемых колонок (защита от SQL-инъекций).
$sortable = [
    'time'     => 'created_at',
    'ip'       => 'ip',
    'device'   => 'device_browser',
    'location' => 'city',
    'accuracy' => 'accuracy',
    'distance' => 'nearest_distance_km',
];
$sort = $_GET['sort'] ?? 'time';
if (!isset($sortable[$sort])) {
    $sort = 'time';
}
$dir = (strtolower($_GET['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$orderBy = $sortable[$sort] . ' ' . $dir;

$perPage = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

// Условие поиска: IP, местоположение, устройство, время.
$where = '';
$params = [];
if ($q !== '') {
    $where = "WHERE (ip LIKE :q OR region LIKE :q OR city LIKE :q OR district LIKE :q
              OR device_type LIKE :q OR device_os LIKE :q OR device_browser LIKE :q
              OR created_at LIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM visits {$where}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$logStmt = $pdo->prepare(
    "SELECT id, created_at, ip, region, city, district, lat, lng, accuracy, nearest_distance_km,
            device_type, device_os, device_browser, event
     FROM visits {$where}
     ORDER BY {$orderBy}, id DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$logStmt->execute($params);
$recent = $logStmt->fetchAll();

// Хелпер: ссылка с сохранением параметров поиска/сортировки.
function log_url(array $overrides = []): string
{
    $base = ['q' => $_GET['q'] ?? '', 'sort' => $_GET['sort'] ?? 'time',
             'dir' => $_GET['dir'] ?? 'desc', 'page' => $_GET['page'] ?? 1];
    $p = array_merge($base, $overrides);
    $p = array_filter($p, fn($v) => $v !== '' && $v !== null);
    return 'admin.php?' . http_build_query($p) . '#log';
}

// Заголовок сортируемой колонки.
function sort_link(string $key, string $label, string $curSort, string $curDir): string
{
    $isActive = $curSort === $key;
    $nextDir = ($isActive && strtolower($curDir) === 'asc') ? 'desc' : 'asc';
    $arrow = $isActive ? (strtolower($curDir) === 'asc' ? ' ▲' : ' ▼') : '';
    $url = log_url(['sort' => $key, 'dir' => $nextDir, 'page' => 1]);
    return '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="hover:text-emerald-600">'
        . htmlspecialchars($label) . $arrow . '</a>';
}

$maxRegion = max(1, ...array_map(fn($r) => (int) $r['cnt'], $byRegion ?: [['cnt' => 1]]));
$maxDate   = max(1, ...array_map(fn($r) => (int) $r['cnt'], $byDate ?: [['cnt' => 1]]));
?>
<!DOCTYPE html>
<html lang="ru"><head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчёты — Админка АптекаРядом</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>body{font-family:ui-sans-serif,system-ui,sans-serif} #admin-map{z-index:0}</style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

    <header class="bg-white border-b border-slate-200 sticky top-0 z-10">
        <div class="max-w-6xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-2 font-extrabold text-emerald-700">
                <span class="grid place-items-center w-8 h-8 rounded-lg bg-emerald-600 text-white">📊</span>
                Отчёты · АптекаРядом
            </div>
            <div class="flex items-center gap-4 text-sm">
                <a href="/" class="text-slate-500 hover:text-emerald-600">На сайт</a>
                <a href="?logout=1" class="text-red-500 hover:text-red-600 font-medium">Выйти</a>
            </div>
        </div>
    </header>

    <main class="max-w-6xl mx-auto px-4 py-8 space-y-8">

        <!-- KPI -->
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-4">
            <?php
            $kpis = [
                ['Всего визитов', $totalVisits, 'bg-emerald-50 text-emerald-700'],
                ['Сегодня (UTC)', $visitsToday, 'bg-sky-50 text-sky-700'],
                ['Уникальных IP', $uniqueIps, 'bg-violet-50 text-violet-700'],
                ['Регионов', count($byRegion), 'bg-amber-50 text-amber-700'],
            ];
            foreach ($kpis as [$label, $val, $cls]): ?>
                <div class="rounded-2xl bg-white p-5 shadow-sm">
                    <div class="inline-block px-2 py-0.5 rounded-md text-xs font-semibold <?= $cls ?>"><?= h($label) ?></div>
                    <div class="mt-2 text-3xl font-extrabold text-slate-900"><?= (int) $val ?></div>
                </div>
            <?php endforeach; ?>
        </section>

        <div class="grid lg:grid-cols-2 gap-6">
            <!-- По регионам -->
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900">Клиенты по регионам</h2>
                <p class="text-sm text-slate-400 mb-4">Откуда приходят посетители</p>
                <?php if (!$byRegion): ?>
                    <p class="text-slate-400 text-sm">Пока нет данных.</p>
                <?php else: foreach ($byRegion as $r): ?>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-700 truncate pr-2"><?= h($r['label']) ?></span>
                            <span class="font-semibold text-slate-900"><?= (int) $r['cnt'] ?></span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-emerald-500 rounded-full" style="width: <?= round($r['cnt'] / $maxRegion * 100) ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </section>

            <!-- По районам/городам -->
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900">Клиенты по районам / городам</h2>
                <p class="text-sm text-slate-400 mb-4">Где больше всего клиентов</p>
                <?php if (!$byDistrict): ?>
                    <p class="text-slate-400 text-sm">Пока нет данных.</p>
                <?php else: foreach ($byDistrict as $r):
                    $w = round($r['cnt'] / max(1, (int) $byDistrict[0]['cnt']) * 100); ?>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-700 truncate pr-2"><?= h($r['label']) ?></span>
                            <span class="font-semibold text-slate-900"><?= (int) $r['cnt'] ?></span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-sky-500 rounded-full" style="width: <?= $w ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </section>
        </div>

        <!-- По датам -->
        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900">Визиты по дням (последние 14)</h2>
            <?php if (!$byDate): ?>
                <p class="text-slate-400 text-sm mt-3">Пока нет данных.</p>
            <?php else: ?>
                <div class="mt-5 flex items-end gap-2 h-40">
                    <?php foreach ($byDate as $d): $hgt = round($d['cnt'] / $maxDate * 100); ?>
                        <div class="flex-1 flex flex-col items-center justify-end h-full">
                            <span class="text-xs text-slate-500 mb-1"><?= (int) $d['cnt'] ?></span>
                            <div class="w-full bg-emerald-500/80 hover:bg-emerald-500 rounded-t-md transition"
                                 style="height: <?= max(4, $hgt) ?>%" title="<?= h($d['d']) ?>: <?= (int) $d['cnt'] ?>"></div>
                            <span class="mt-1 text-[10px] text-slate-400"><?= h(substr($d['d'], 5)) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <!-- Топ аптек -->
        <?php if ($topPharm): ?>
        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <h2 class="font-bold text-slate-900 mb-4">Самые востребованные аптеки (как ближайшие)</h2>
            <div class="grid sm:grid-cols-2 gap-x-8 gap-y-2">
                <?php foreach ($topPharm as $i => $p): ?>
                    <div class="flex justify-between text-sm py-1 border-b border-slate-50">
                        <span class="text-slate-700"><?= ($i + 1) ?>. <?= h($p['label']) ?></span>
                        <span class="font-semibold text-emerald-700"><?= (int) $p['cnt'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>

        <!-- По устройствам -->
        <?php if ($byDevice || $byBrowser): ?>
        <div class="grid lg:grid-cols-2 gap-6">
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900 mb-4">Типы устройств</h2>
                <?php
                $deviceLabels = ['mobile' => '📱 Телефон', 'tablet' => '📱 Планшет', 'desktop' => '💻 Компьютер'];
                foreach ($byDevice as $d):
                    $lbl = $deviceLabels[$d['label']] ?? $d['label'];
                    $w = round($d['cnt'] / max(1, (int) $byDevice[0]['cnt']) * 100); ?>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-700"><?= h($lbl) ?></span>
                            <span class="font-semibold text-slate-900"><?= (int) $d['cnt'] ?></span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-violet-500 rounded-full" style="width: <?= $w ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
            <section class="rounded-2xl bg-white p-6 shadow-sm">
                <h2 class="font-bold text-slate-900 mb-4">Браузеры</h2>
                <?php foreach ($byBrowser as $d):
                    $w = round($d['cnt'] / max(1, (int) $byBrowser[0]['cnt']) * 100); ?>
                    <div class="mb-3">
                        <div class="flex justify-between text-sm mb-1">
                            <span class="text-slate-700"><?= h($d['label']) ?></span>
                            <span class="font-semibold text-slate-900"><?= (int) $d['cnt'] ?></span>
                        </div>
                        <div class="h-2 rounded-full bg-slate-100 overflow-hidden">
                            <div class="h-full bg-amber-500 rounded-full" style="width: <?= $w ?>%"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </section>
        </div>
        <?php endif; ?>

        <!-- Карта местоположения клиента -->
        <section class="rounded-2xl bg-white p-6 shadow-sm">
            <div class="flex items-center justify-between mb-4">
                <h2 class="font-bold text-slate-900">Местоположение клиента на карте</h2>
                <span id="map-hint" class="text-sm text-slate-400">Нажмите «На карте» в таблице ниже</span>
            </div>
            <div id="admin-map" class="w-full h-[360px] rounded-xl bg-slate-100"></div>
            <p class="mt-3 text-sm text-slate-500">
                Синяя точка — координаты клиента, круг — радиус точности геолокации.
            </p>
        </section>

        <!-- Журнал визитов: поиск + сортировка + пагинация -->
        <section id="log" class="rounded-2xl bg-white p-6 shadow-sm scroll-mt-20">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-4">
                <h2 class="font-bold text-slate-900">Журнал визитов <span class="text-slate-400 font-normal">(<?= (int) $totalRows ?>)</span></h2>
                <form method="get" action="admin.php" class="flex items-center gap-2">
                    <input type="hidden" name="sort" value="<?= h($sort) ?>">
                    <input type="hidden" name="dir" value="<?= h(strtolower($dir)) ?>">
                    <input type="search" name="q" value="<?= h($q) ?>"
                           placeholder="Поиск: IP, город, устройство, дата…"
                           class="w-64 max-w-full px-4 py-2 rounded-xl border border-slate-200 focus:border-emerald-500 focus:ring-2 focus:ring-emerald-200 outline-none text-sm">
                    <button class="px-4 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold">Найти</button>
                    <?php if ($q !== ''): ?>
                        <a href="<?= h(log_url(['q' => '', 'page' => 1])) ?>" class="text-sm text-slate-400 hover:text-slate-600">Сброс</a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-slate-400 border-b border-slate-100">
                        <th class="py-2 pr-4 font-medium"><?= sort_link('time', 'Дата (UTC)', $sort, $dir) ?></th>
                        <th class="py-2 pr-4 font-medium"><?= sort_link('ip', 'IP', $sort, $dir) ?></th>
                        <th class="py-2 pr-4 font-medium"><?= sort_link('device', 'Устройство', $sort, $dir) ?></th>
                        <th class="py-2 pr-4 font-medium"><?= sort_link('location', 'Местоположение', $sort, $dir) ?></th>
                        <th class="py-2 pr-4 font-medium"><?= sort_link('accuracy', 'Точность', $sort, $dir) ?></th>
                        <th class="py-2 pr-4 font-medium"><?= sort_link('distance', 'До аптеки', $sort, $dir) ?></th>
                        <th class="py-2 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="7" class="py-4 text-slate-400">Ничего не найдено.</td></tr>
                    <?php else: foreach ($recent as $v):
                        $dev = trim(($v['device_browser'] ?: '') . ' · ' . ($v['device_os'] ?: ''), ' ·');
                        $place = trim(($v['region'] ? $v['region'] . ', ' : '') . trim(($v['city'] ?: '') . ' ' . ($v['district'] ?: ''))) ?: '';
                        $hasCoords = $v['lat'] !== null && $v['lng'] !== null;
                        $isLeave = ($v['event'] ?? '') === 'leave'; ?>
                        <tr class="border-b border-slate-50 hover:bg-slate-50/60">
                            <td class="py-2 pr-4 whitespace-nowrap text-slate-600">
                                <?= h($v['created_at']) ?>
                                <?php if ($isLeave): ?><span class="ml-1 px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 text-[10px]">ушёл</span><?php endif; ?>
                            </td>
                            <td class="py-2 pr-4 text-slate-500"><?= h($v['ip']) ?></td>
                            <td class="py-2 pr-4 text-slate-600 whitespace-nowrap"><?= h($dev ?: '—') ?></td>
                            <td class="py-2 pr-4 text-slate-700"><?= h($place ?: '—') ?></td>
                            <td class="py-2 pr-4 text-slate-500"><?= $v['accuracy'] !== null ? '±' . h((string) round((float) $v['accuracy'])) . ' м' : '—' ?></td>
                            <td class="py-2 pr-4 text-slate-600"><?= $v['nearest_distance_km'] !== null ? h(number_format((float) $v['nearest_distance_km'], 2)) . ' км' : '—' ?></td>
                            <td class="py-2 whitespace-nowrap">
                                <?php if ($hasCoords): ?>
                                    <button type="button"
                                        class="show-on-map px-3 py-1.5 rounded-lg bg-emerald-50 text-emerald-700 font-medium hover:bg-emerald-100 transition"
                                        data-lat="<?= h((string) $v['lat']) ?>"
                                        data-lng="<?= h((string) $v['lng']) ?>"
                                        data-acc="<?= $v['accuracy'] !== null ? h((string) $v['accuracy']) : '' ?>"
                                        data-label="<?= h(($v['created_at']) . ($place ? ' · ' . $place : '')) ?>">
                                        📍 На карте
                                    </button>
                                <?php else: ?>
                                    <span class="text-slate-300">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>

            <!-- Пагинация -->
            <?php if ($totalPages > 1): ?>
            <div class="mt-5 flex items-center justify-between">
                <span class="text-sm text-slate-400">Стр. <?= $page ?> из <?= $totalPages ?></span>
                <div class="flex items-center gap-1">
                    <?php
                    $mkBtn = function (int $p, string $text, bool $disabled = false, bool $active = false) {
                        if ($disabled) {
                            return '<span class="px-3 py-1.5 rounded-lg text-slate-300">' . $text . '</span>';
                        }
                        $cls = $active
                            ? 'bg-emerald-600 text-white'
                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200';
                        return '<a href="' . htmlspecialchars(log_url(['page' => $p]), ENT_QUOTES) . '" class="px-3 py-1.5 rounded-lg ' . $cls . '">' . $text . '</a>';
                    };
                    echo $mkBtn($page - 1, '‹', $page <= 1);
                    $start = max(1, $page - 2);
                    $end = min($totalPages, $start + 4);
                    $start = max(1, $end - 4);
                    for ($i = $start; $i <= $end; $i++) {
                        echo $mkBtn($i, (string) $i, false, $i === $page);
                    }
                    echo $mkBtn($page + 1, '›', $page >= $totalPages);
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </section>

    </main>

    <script>
        (function () {
            const mapEl = document.getElementById('admin-map');
            if (!mapEl || typeof L === 'undefined') return;

            // Центр по умолчанию — первый визит с координатами или Бишкек.
            const first = document.querySelector('.show-on-map');
            const defLat = first ? parseFloat(first.dataset.lat) : 42.8746;
            const defLng = first ? parseFloat(first.dataset.lng) : 74.6129;

            const map = L.map('admin-map').setView([defLat, defLng], 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19, attribution: '© OpenStreetMap',
            }).addTo(map);

            let marker = null, circle = null;

            function show(lat, lng, acc, label) {
                if (marker) map.removeLayer(marker);
                if (circle) map.removeLayer(circle);

                marker = L.circleMarker([lat, lng], {
                    radius: 7, color: '#fff', weight: 2,
                    fillColor: '#2563eb', fillOpacity: 1,
                }).addTo(map).bindPopup('<b>Клиент</b><br>' + label +
                    (acc ? '<br>Точность: ±' + Math.round(acc) + ' м' : ''));

                if (acc && acc > 0) {
                    circle = L.circle([lat, lng], {
                        radius: acc, color: '#2563eb', weight: 1,
                        fillColor: '#3b82f6', fillOpacity: 0.15,
                    }).addTo(map);
                    map.fitBounds(circle.getBounds(), { padding: [40, 40], maxZoom: 17 });
                } else {
                    map.setView([lat, lng], 16);
                }
                marker.openPopup();
                document.getElementById('map-hint').textContent = label;
                mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }

            document.querySelectorAll('.show-on-map').forEach((btn) => {
                btn.addEventListener('click', () => {
                    show(
                        parseFloat(btn.dataset.lat),
                        parseFloat(btn.dataset.lng),
                        btn.dataset.acc ? parseFloat(btn.dataset.acc) : null,
                        btn.dataset.label || ''
                    );
                });
            });

            // Сразу показать первый визит, если он есть.
            if (first) first.click();
            setTimeout(() => map.invalidateSize(), 150);
        })();
    </script>
</body></html>
