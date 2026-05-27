'use strict';

const $ = (id) => document.getElementById(id);

const els = {
    locateBtn:  $('locate-btn'),
    status:     $('status'),
    skeleton:   $('skeleton'),
    nearestWrap:$('nearest-wrap'),
    listWrap:   $('list-wrap'),
    listGrid:   $('list-grid'),
    listCount:  $('list-count'),
    errorBox:   $('error-box'),
    errorText:  $('error-text'),
    modal:      $('geo-modal'),
    modalCard:  $('geo-modal-card'),
    backdrop:   $('geo-backdrop'),
    allowBtn:   $('geo-allow'),
    denyBtn:    $('geo-deny'),
};

let map = null;
let userPos = null;
let lastNearest = null;     // последняя ближайшая аптека (для логов)
let trackTimer = null;      // setInterval отправки логов
let watchId = null;         // id watchPosition
const device = detectDevice();

document.addEventListener('DOMContentLoaded', () => {
    // Кнопка в hero — принудительно запросить заново.
    els.locateBtn.addEventListener('click', () => locate());

    // Кнопки внутри модального окна.
    els.allowBtn.addEventListener('click', () => {
        closeModal();
        locate();                 // только теперь покажется системный запрос браузера
    });
    els.denyBtn.addEventListener('click', () => {
        closeModal();
        showError('Без доступа к местоположению показываем общий список аптек — выберите удобную.');
        fetchPharmacies(null, null);
    });
    els.backdrop.addEventListener('click', () => els.denyBtn.click());
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !els.modal.classList.contains('hidden')) {
            els.denyBtn.click();
        }
    });

    startFlow();
});

/**
 * Решает, показывать ли наше окно:
 *  - доступ уже дан → НЕ спрашиваем повторно, сразу определяем;
 *  - доступ запрещён → системно спросить нельзя, показываем общий список;
 *  - ещё не решал → показываем наше модальное окно.
 */
async function startFlow() {
    const state = await geoPermissionState();

    if (state === 'granted' || localStorage.getItem('geo-allowed') === '1') {
        locate();
    } else if (state === 'denied') {
        showError('Доступ к геолокации запрещён в настройках браузера. Показываем общий список аптек.');
        fetchPharmacies(null, null);
    } else {
        openModal();
    }
}

/**
 * Состояние разрешения геолокации: 'granted' | 'prompt' | 'denied' | null.
 */
async function geoPermissionState() {
    if (!navigator.permissions || !navigator.permissions.query) return null;
    try {
        const res = await navigator.permissions.query({ name: 'geolocation' });
        return res.state;
    } catch (e) {
        return null;
    }
}

/**
 * Определяет тип устройства, ОС и браузер из user-agent.
 */
function detectDevice() {
    const ua = navigator.userAgent || '';

    let type = 'desktop';
    if (/iPad|Tablet|PlayBook|Silk/i.test(ua)) type = 'tablet';
    else if (/Mobi|Android|iPhone|iPod|Opera Mini|IEMobile/i.test(ua)) type = 'mobile';

    let os = 'Неизвестно';
    if (/Windows/i.test(ua)) os = 'Windows';
    else if (/Android/i.test(ua)) os = 'Android';
    else if (/iPhone|iPad|iPod/i.test(ua)) os = 'iOS';
    else if (/Mac OS X/i.test(ua)) os = 'macOS';
    else if (/Linux/i.test(ua)) os = 'Linux';

    let browser = 'Неизвестно';
    if (/Edg\//i.test(ua)) browser = 'Edge';
    else if (/OPR\/|Opera/i.test(ua)) browser = 'Opera';
    else if (/YaBrowser/i.test(ua)) browser = 'Yandex';
    else if (/Chrome\//i.test(ua)) browser = 'Chrome';
    else if (/Firefox\//i.test(ua)) browser = 'Firefox';
    else if (/Safari\//i.test(ua)) browser = 'Safari';

    return { type, os, browser };
}

function openModal() {
    els.modal.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    // запуск анимации появления
    requestAnimationFrame(() => {
        els.modalCard.classList.remove('translate-y-4', 'opacity-0');
    });
}

function closeModal() {
    els.modalCard.classList.add('translate-y-4', 'opacity-0');
    document.body.style.overflow = '';
    setTimeout(() => els.modal.classList.add('hidden'), 250);
}

function setStatus(text) {
    els.status.textContent = text || '';
}

function showError(text) {
    els.errorText.textContent = text;
    els.errorBox.classList.remove('hidden');
}

function hideError() {
    els.errorBox.classList.add('hidden');
}

function locate() {
    hideError();
    els.locateBtn.classList.remove('pulse');

    if (!('geolocation' in navigator)) {
        setStatus('');
        showError('Ваш браузер не поддерживает геолокацию. Показываем общий список аптек.');
        fetchPharmacies(null, null);
        return;
    }

    setStatus('Определяем местоположение…');
    els.skeleton.classList.remove('hidden');

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            userPos = {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                accuracy: pos.coords.accuracy,
            };
            // Запоминаем, что доступ дан — больше наше окно не показываем.
            localStorage.setItem('geo-allowed', '1');
            setStatus('Местоположение определено ✓');
            fetchPharmacies(userPos.lat, userPos.lng);
            startTracking();
        },
        (err) => {
            setStatus('');
            if (err.code === err.PERMISSION_DENIED) {
                localStorage.removeItem('geo-allowed');
            }
            const msg = err.code === err.PERMISSION_DENIED
                ? 'Доступ к геолокации отклонён. Показываем общий список аптек — выберите удобную.'
                : 'Не удалось определить местоположение. Показываем общий список аптек.';
            showError(msg);
            fetchPharmacies(null, null);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 }
    );
}

async function fetchPharmacies(lat, lng) {
    try {
        const params = new URLSearchParams({ limit: '10' });
        if (lat != null && lng != null) {
            params.set('lat', lat);
            params.set('lng', lng);
        }
        const res = await fetch('/api/nearby.php?' + params.toString());
        const data = await res.json();
        els.skeleton.classList.add('hidden');

        if (!data.ok || !data.pharmacies.length) {
            showError('Аптеки не найдены.');
            return;
        }
        render(data);
    } catch (e) {
        els.skeleton.classList.add('hidden');
        showError('Ошибка загрузки данных. Попробуйте обновить страницу.');
    }
}

function render(data) {
    const list = data.pharmacies;
    const nearest = list[0];

    renderNearest(nearest, data.has_location);
    renderList(list.slice(1));

    if (data.has_location && nearest) {
        lastNearest = nearest;   // используется в периодических логах
    }
}

/**
 * Запускает слежение за местоположением и отправку лога каждые 3 секунды.
 */
function startTracking() {
    if (trackTimer) return; // уже запущено

    // Держим userPos актуальным при перемещении пользователя.
    if (navigator.geolocation.watchPosition) {
        watchId = navigator.geolocation.watchPosition(
            (pos) => {
                userPos = {
                    lat: pos.coords.latitude,
                    lng: pos.coords.longitude,
                    accuracy: pos.coords.accuracy,
                };
            },
            () => { /* ошибки слежения игнорируем */ },
            { enableHighAccuracy: true, maximumAge: 2000, timeout: 8000 }
        );
    }

    sendTrack();                              // первый лог сразу
    trackTimer = setInterval(sendTrack, 3000); // далее — каждые 3 секунды
    // Примечание: лог идёт и когда вкладка в фоне (мы НЕ ставим на паузу).

    // Финальный лог в момент ухода/закрытия вкладки (sendBeacon успевает уйти).
    const sendLeave = () => sendTrack('leave');
    window.addEventListener('pagehide', sendLeave);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) sendLeave();
    });
}

/**
 * Отправляет один лог визита (IP добавит сервер) в фоне.
 * @param {string} event - 'ping' (по умолчанию) или 'leave' (уход со страницы)
 */
function sendTrack(event = 'ping') {
    if (!userPos) return;

    const payload = JSON.stringify({
        lat: userPos.lat,
        lng: userPos.lng,
        accuracy: userPos.accuracy ?? null,
        nearest_pharmacy_id: lastNearest ? lastNearest.id : null,
        nearest_distance_km: lastNearest ? lastNearest.distance_km : null,
        device_type: device.type,
        device_os: device.os,
        device_browser: device.browser,
        event: event,
    });

    try {
        if (navigator.sendBeacon) {
            navigator.sendBeacon('/api/track.php', new Blob([payload], { type: 'application/json' }));
        } else {
            fetch('/api/track.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true,
            });
        }
    } catch (e) { /* трекинг не критичен */ }
}

function renderNearest(p, hasLocation) {
    $('n-name').textContent = p.name;
    $('n-address').textContent = p.address;

    const dist = $('n-distance');
    if (hasLocation && p.distance_text) {
        dist.textContent = '📍 ' + p.distance_text + ' от вас';
        dist.classList.remove('hidden');
    } else {
        dist.classList.add('hidden');
    }

    $('n-24h').classList.toggle('hidden', !p.is_24h);

    const hours = $('n-hours');
    if (p.hours && !p.is_24h) {
        hours.textContent = '🕐 ' + p.hours;
        hours.classList.remove('hidden');
    } else {
        hours.classList.add('hidden');
    }

    // Маршрут через OpenStreetMap
    const route = $('n-route');
    if (userPos) {
        route.href = `https://www.openstreetmap.org/directions?from=${userPos.lat},${userPos.lng}&to=${p.lat},${p.lng}`;
    } else {
        route.href = `https://www.openstreetmap.org/?mlat=${p.lat}&mlon=${p.lng}#map=17/${p.lat}/${p.lng}`;
    }

    const phone = $('n-phone');
    if (p.phone) {
        phone.href = 'tel:' + p.phone.replace(/\s/g, '');
        phone.classList.remove('hidden');
    } else {
        phone.classList.add('hidden');
    }

    els.nearestWrap.classList.remove('hidden');
    renderMap(p);
}

function renderMap(p) {
    if (!map) {
        map = L.map('map', { zoomControl: true, attributionControl: true });
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '© OpenStreetMap',
        }).addTo(map);
    } else {
        // Очищаем старые маркеры
        map.eachLayer((layer) => {
            if (layer instanceof L.Marker) map.removeLayer(layer);
        });
    }

    const pharmIcon = L.divIcon({
        className: '',
        html: '<div style="font-size:28px;line-height:1">💊</div>',
        iconSize: [28, 28],
        iconAnchor: [14, 28],
    });

    const pharmMarker = L.marker([p.lat, p.lng], { icon: pharmIcon })
        .addTo(map)
        .bindPopup(`<b>${p.name}</b><br>${p.address}`);

    const points = [[p.lat, p.lng]];

    if (userPos) {
        const meIcon = L.divIcon({
            className: '',
            html: '<div style="width:16px;height:16px;background:#2563eb;border:3px solid #fff;border-radius:50%;box-shadow:0 0 0 4px rgba(37,99,235,.3)"></div>',
            iconSize: [16, 16],
            iconAnchor: [8, 8],
        });
        L.marker([userPos.lat, userPos.lng], { icon: meIcon })
            .addTo(map)
            .bindPopup('Вы здесь');
        points.push([userPos.lat, userPos.lng]);
    }

    if (points.length > 1) {
        map.fitBounds(points, { padding: [50, 50], maxZoom: 16 });
    } else {
        map.setView([p.lat, p.lng], 15);
    }

    // Карта вставлена в скрытый ранее контейнер — пересчитываем размер.
    setTimeout(() => map.invalidateSize(), 100);
    pharmMarker.openPopup();
}

function renderList(rest) {
    els.listGrid.innerHTML = '';
    if (!rest.length) {
        els.listWrap.classList.add('hidden');
        return;
    }

    for (const p of rest) {
        const distHtml = p.distance_text
            ? `<span class="shrink-0 px-2.5 py-1 rounded-lg bg-brand-50 text-brand-700 text-sm font-bold">${p.distance_text}</span>`
            : '';
        const badge24 = p.is_24h
            ? '<span class="px-2 py-0.5 rounded bg-amber-50 text-amber-700 text-xs font-semibold">24ч</span>'
            : '';

        const card = document.createElement('div');
        card.className = 'flex items-start justify-between gap-3 p-4 rounded-2xl bg-white border border-slate-100 hover:border-brand-200 hover:shadow-md transition';
        card.innerHTML = `
            <div class="min-w-0">
                <div class="flex items-center gap-2">
                    <h4 class="font-semibold text-slate-900 truncate">${escapeHtml(p.name)}</h4>
                    ${badge24}
                </div>
                <p class="text-sm text-slate-500 truncate">${escapeHtml(p.address)}</p>
                ${p.phone ? `<a href="tel:${p.phone.replace(/\s/g, '')}" class="inline-block mt-1 text-sm text-brand-600 font-medium">${escapeHtml(p.phone)}</a>` : ''}
            </div>
            ${distHtml}
        `;
        els.listGrid.appendChild(card);
    }

    els.listCount.textContent = rest.length + ' шт.';
    els.listWrap.classList.remove('hidden');
}

function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
}
