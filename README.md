# 💊 АптекаРядом

Веб-сайт, который определяет местоположение посетителя и показывает **ближайшую аптеку**
с расстоянием, картой и кнопкой построения маршрута.

## Стек
Docker · PHP 8.3 · SQLite · HTML · Tailwind CSS · Vanilla JS · Leaflet (OpenStreetMap)

## Запуск

```bash
cp .env.example .env      # порт и пароль админки (можно изменить)
docker compose up --build
```

Откройте **http://localhost:8080** (или ваш `PORT` из `.env`) и разрешите доступ к геолокации.

Настройки в `.env`: `PORT` (порт сайта), `ADMIN_PASSWORD` (пароль `/admin.php`),
`DISABLE_GEOCODING` (1 — offline-режим).

База SQLite создаётся автоматически и наполняется демо-аптеками (г. Бишкек) при
первом запуске. Файл хранится в `./data/pharmacy.sqlite`.

## Структура

```
src/index.php         — главная страница
src/api/nearby.php    — JSON-API ближайших аптек
src/lib/db.php        — подключение к SQLite + сидинг
src/lib/geo.php       — расчёт расстояния (haversine)
src/assets/js/app.js  — геолокация и отрисовка
```

## Документация для разработки
См. [`CLAUDE.md`](./CLAUDE.md) — там архитектура, API, схема БД и дорожная карта.

> ℹ️ Геолокация в браузере доступна только на `http://localhost` или по HTTPS.
