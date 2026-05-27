<!DOCTYPE html>
<html lang="ru" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>АптекаРядом — ближайшая аптека рядом с вами</title>
    <meta name="description" content="Найдите ближайшую аптеку по вашему местоположению за пару секунд.">

    <!-- Tailwind CSS (CDN, режим разработки) -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50:  '#eefdf3', 100: '#d6f9e2', 200: '#b0f0ca',
                            300: '#7ce2ab', 400: '#43cb86', 500: '#1fb069',
                            600: '#138f54', 700: '#127146',
                            800: '#13593a', 900: '#114931', 950: '#06281b',
                        },
                    },
                    fontFamily: {
                        sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                    },
                },
            },
        };
    </script>

    <!-- Leaflet (карта на OpenStreetMap) -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>💊</text></svg>">

    <style>
        body { font-family: 'Inter', sans-serif; }
        #map { z-index: 0; }
        .pulse { box-shadow: 0 0 0 0 rgba(31,176,105,.6); animation: pulse 2s infinite; }
        @keyframes pulse {
            70%  { box-shadow: 0 0 0 14px rgba(31,176,105,0); }
            100% { box-shadow: 0 0 0 0 rgba(31,176,105,0); }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 antialiased">

    <!-- Шапка -->
    <header class="sticky top-0 z-20 bg-white/80 backdrop-blur border-b border-slate-200">
        <div class="max-w-5xl mx-auto px-4 h-16 flex items-center justify-between">
            <a href="/" class="flex items-center gap-2 font-extrabold text-lg text-brand-700">
                <span class="grid place-items-center w-9 h-9 rounded-xl bg-brand-500 text-white text-xl">💊</span>
                АптекаРядом
            </a>
            <a href="#list-wrap" class="text-sm font-medium text-slate-500 hover:text-brand-600 transition">
                Все аптеки
            </a>
        </div>
    </header>

    <!-- Геро / поиск -->
    <section class="relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-brand-50 via-white to-brand-100"></div>
        <div class="relative max-w-5xl mx-auto px-4 pt-14 pb-10 text-center">
            <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-100 text-brand-700 text-xs font-semibold">
                <span class="w-1.5 h-1.5 rounded-full bg-brand-500"></span>
                Работает по вашей геолокации
            </span>
            <h1 class="mt-5 text-3xl sm:text-5xl font-extrabold tracking-tight text-slate-900">
                Ближайшая аптека<br class="hidden sm:block"> — рядом с вами
            </h1>
            <p class="mt-4 text-slate-500 max-w-xl mx-auto">
                Разрешите доступ к местоположению, и мы мгновенно покажем, до какой аптеки идти ближе всего.
            </p>

            <div class="mt-7 flex flex-col sm:flex-row items-center justify-center gap-3">
                <button id="locate-btn"
                    class="pulse inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold shadow-lg shadow-brand-500/30 transition active:scale-95">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z" />
                    </svg>
                    Найти ближайшую аптеку
                </button>
                <span id="status" class="text-sm text-slate-500 h-5"></span>
            </div>
        </div>
    </section>

    <main class="max-w-5xl mx-auto px-4 pb-20 -mt-2">

        <!-- Карточка ближайшей аптеки -->
        <section id="nearest-wrap" class="hidden">
            <div id="nearest-card"
                 class="relative rounded-3xl bg-white border border-brand-100 shadow-xl shadow-slate-200/70 overflow-hidden">
                <div class="grid md:grid-cols-2">
                    <div class="p-6 sm:p-8">
                        <div class="flex items-center gap-2 text-brand-600 text-xs font-bold uppercase tracking-wide">
                            <span class="w-2 h-2 rounded-full bg-brand-500 animate-pulse"></span>
                            Ближайшая к вам
                        </div>
                        <h2 id="n-name" class="mt-2 text-2xl font-extrabold text-slate-900"></h2>
                        <p id="n-address" class="mt-1 text-slate-500"></p>

                        <div class="mt-5 flex flex-wrap items-center gap-2">
                            <span id="n-distance" class="px-3 py-1.5 rounded-lg bg-brand-50 text-brand-700 font-bold text-sm"></span>
                            <span id="n-24h" class="hidden px-3 py-1.5 rounded-lg bg-amber-50 text-amber-700 font-semibold text-sm">🕐 Круглосуточно</span>
                            <span id="n-hours" class="px-3 py-1.5 rounded-lg bg-slate-100 text-slate-600 text-sm"></span>
                        </div>

                        <div class="mt-6 flex flex-wrap gap-3">
                            <a id="n-route" target="_blank" rel="noopener"
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold transition active:scale-95">
                                🧭 Маршрут
                            </a>
                            <a id="n-phone"
                               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl border border-slate-200 hover:border-brand-300 text-slate-700 font-semibold transition">
                                📞 Позвонить
                            </a>
                        </div>
                    </div>
                    <div id="map" class="min-h-[260px] md:min-h-full"></div>
                </div>
            </div>
        </section>

        <!-- Скелетон загрузки -->
        <section id="skeleton" class="hidden">
            <div class="rounded-3xl bg-white border border-slate-100 p-8 animate-pulse">
                <div class="h-3 w-32 bg-slate-200 rounded"></div>
                <div class="h-7 w-2/3 bg-slate-200 rounded mt-4"></div>
                <div class="h-4 w-1/2 bg-slate-200 rounded mt-3"></div>
                <div class="h-10 w-48 bg-slate-200 rounded mt-6"></div>
            </div>
        </section>

        <!-- Список остальных аптек -->
        <section id="list-wrap" class="hidden mt-10 scroll-mt-20">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-bold text-slate-900">Другие аптеки поблизости</h3>
                <span id="list-count" class="text-sm text-slate-400"></span>
            </div>
            <div id="list-grid" class="mt-4 grid gap-3 sm:grid-cols-2"></div>
        </section>

        <!-- Заглушка-ошибка -->
        <section id="error-box" class="hidden mt-6">
            <div class="rounded-2xl bg-amber-50 border border-amber-200 p-5 text-amber-800">
                <p id="error-text" class="font-medium"></p>
            </div>
        </section>
    </main>

    <!-- Наше модальное окно: запрос доступа к геолокации (priming) -->
    <div id="geo-modal" class="hidden fixed inset-0 z-50 flex items-end sm:items-center justify-center p-4">
        <!-- затемнение фона -->
        <div id="geo-backdrop" class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm"></div>

        <!-- карточка -->
        <div class="relative w-full max-w-md rounded-3xl bg-white shadow-2xl p-7 sm:p-8
                    translate-y-4 opacity-0 transition-all duration-300"
             id="geo-modal-card" role="dialog" aria-modal="true" aria-labelledby="geo-modal-title">

            <div class="mx-auto grid place-items-center w-16 h-16 rounded-2xl bg-brand-50 text-3xl">
                📍
            </div>

            <h2 id="geo-modal-title" class="mt-5 text-center text-xl font-extrabold text-slate-900">
                Найти ближайшую аптеку?
            </h2>
            <p class="mt-2 text-center text-slate-500 leading-relaxed">
                Чтобы подобрать самую <b>подходящую и ближайшую</b> к вам аптеку,
                нам нужен доступ к вашему местоположению. Разрешаете?
            </p>

            <ul class="mt-5 space-y-2 text-sm text-slate-600">
                <li class="flex items-center gap-2"><span class="text-brand-500">✓</span> Покажем аптеку рядом с вами</li>
                <li class="flex items-center gap-2"><span class="text-brand-500">✓</span> Рассчитаем расстояние и маршрут</li>
                <li class="flex items-center gap-2"><span class="text-brand-500">✓</span> Данные о местоположении никуда не сохраняются</li>
            </ul>

            <div class="mt-7 flex flex-col gap-2">
                <button id="geo-allow"
                    class="w-full px-5 py-3 rounded-xl bg-brand-500 hover:bg-brand-600 text-white font-semibold shadow-lg shadow-brand-500/30 transition active:scale-95">
                    Да, разрешить доступ
                </button>
                <button id="geo-deny"
                    class="w-full px-5 py-3 rounded-xl text-slate-500 hover:text-slate-700 font-semibold transition">
                    Нет, не сейчас
                </button>
            </div>

            <p class="mt-3 text-center text-xs text-slate-400">
                После нажатия «Да» браузер покажет своё системное окно — там тоже подтвердите доступ.
            </p>
        </div>
    </div>

    <footer class="border-t border-slate-200 py-8 text-center text-sm text-slate-400">
        Демо-проект «АптекаРядом» · PHP · SQLite · Tailwind · Leaflet
    </footer>

    <script src="/assets/js/app.js" defer></script>
</body>
</html>
