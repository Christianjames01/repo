/**
 * typhoon_gps_reenable_patch.js — v3
 *
 * Wires the existing "Retry" button in the denied status bar so that
 * clicking it:
 *   1. Clears the stored deny flag (tt_gps_v4)
 *   2. Fires a real browser geolocation request
 *   3. On success → calls applyGPS(lat, lon, acc) from typhoon_location_patch.js
 *      which updates the bar state, label, map marker, weather & typhoon data
 *   4. On hard-block → updates the bar text to guide the user to browser settings
 */

(function gpsReEnablePatch() {
    'use strict';

    const LS_KEY = 'tt_gps_v4';

    /* ── Find and wire the Retry button ──────────────────────────────────── */
    function wireRetryButton(tries) {
        tries = tries || 0;

        // The existing button rendered by typhoon_location_patch.js
        // Try common selectors — adjust if your button has a different id/class
        const btn =
            document.getElementById('ttRetryBtn') ||
            document.getElementById('gpsRetryBtn') ||
            document.querySelector('[data-action="gps-retry"]') ||
            // fallback: any button inside the denied bar containing "Retry"
            (() => {
                const bar = document.getElementById('ttLocBar');
                if (!bar) return null;
                return [...bar.querySelectorAll('button, a')]
                    .find(el => /retry/i.test(el.textContent));
            })();

        if (!btn) {
            if (tries < 40) setTimeout(() => wireRetryButton(tries + 1), 300);
            return;
        }

        // Replace whatever the old handler was
        btn.onclick = null;
        btn.addEventListener('click', handleRetry, { once: false });
    }

    /* ── Also watch for the bar switching to denied state later ─────────── */
    function watchBar() {
        const bar = document.getElementById('ttLocBar');
        if (!bar) { setTimeout(watchBar, 500); return; }

        const obs = new MutationObserver(() => {
            if (bar.classList.contains('st-denied') || bar.classList.contains('st-err')) {
                setTimeout(() => wireRetryButton(0), 100);
            }
        });
        obs.observe(bar, { attributes: true, attributeFilter: ['class'] });
    }

    /* ── The actual retry handler ────────────────────────────────────────── */
    function handleRetry() {
        // Clear the stored denial so applyGPS / location patch proceeds
        localStorage.removeItem(LS_KEY);
        window._locationDenied = false;

        // Update bar text to show we're trying
        setBarText('<i class="fas fa-circle-notch fa-spin"></i> Requesting GPS…');

        if (!navigator.geolocation) {
            setBarText('❌ Geolocation not supported by this browser.');
            return;
        }

        // Check if browser has hard-blocked this origin
        if (navigator.permissions) {
            navigator.permissions.query({ name: 'geolocation' }).then(result => {
                if (result.state === 'denied') {
                    onHardBlocked();
                } else {
                    doGPS();
                }
            }).catch(doGPS);
        } else {
            doGPS();
        }
    }

    /* ── Fire the actual geolocation request ─────────────────────────────── */
    function doGPS() {
        navigator.geolocation.getCurrentPosition(onSuccess, onError, {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        });
    }

    /* ── GPS success ─────────────────────────────────────────────────────── */
    function onSuccess(pos) {
        const lat = pos.coords.latitude;
        const lon = pos.coords.longitude;
        const acc = pos.coords.accuracy;

        localStorage.setItem(LS_KEY, 'allow');
        window._locationDenied = false;

        // Primary: delegate to typhoon_location_patch.js — it handles everything:
        // bar state → st-allow, label, map marker, reverse geocode, weather, typhoons
        if (typeof window.applyGPS === 'function') {
            window.applyGPS(lat, lon, acc);
            return;
        }

        // Fallback if patch isn't loaded
        fallbackActivate(lat, lon, acc);
    }

    /* ── GPS error ───────────────────────────────────────────────────────── */
    function onError(err) {
        if (err.code === 1 /* PERMISSION_DENIED */) {
            onHardBlocked();
        } else if (err.code === 2) {
            setBarText('📡 Position unavailable. Move near a window and tap Retry.');
            wireRetryButton(0); // re-wire so they can try again
        } else {
            setBarText('⏱ GPS timed out. Tap Retry to try again.');
            wireRetryButton(0);
        }
    }

    /* ── Browser has hard-blocked the origin ─────────────────────────────── */
    function onHardBlocked() {
        // Update the bar message in-place — no new modal needed
        const ua = navigator.userAgent;
        const mob = /Android|iPhone|iPad/.test(ua);
        const ff = /Firefox/.test(ua);

        let guide;
        if (mob && /Android/.test(ua)) {
            guide = 'Tap the 🔒 icon → Site settings → Location → Allow, then tap Retry.';
        } else if (mob) {
            guide = 'Go to Settings → Safari → Location → Allow, then tap Retry.';
        } else if (ff) {
            guide = 'Click 🔒 in address bar → remove the Location block → reload & allow.';
        } else {
            guide = 'Click 🔒 in the address bar → Site settings → Location → Allow, then tap Retry.';
        }

        setBarText(`🔒 Location blocked. ${guide}`);
        wireRetryButton(0); // keep Retry button wired for after they fix it
    }

    /* ── Helper: update the bar text without changing its class/state ──── */
    function setBarText(html) {
        // Try the text span first, then the whole bar
        const el =
            document.getElementById('ttLbText') ||
            document.querySelector('#ttLocBar .bar-text') ||
            document.querySelector('#ttLocBar span');
        if (el) el.innerHTML = html;
    }

    /* ── Fallback activation when applyGPS isn't available ───────────────── */
    async function fallbackActivate(lat, lon, acc) {
        if (window.APP) { window.APP.userLat = lat; window.APP.userLon = lon; }

        const label = await reverseGeocode(lat, lon)
            || `${lat.toFixed(4)}°N, ${lon.toFixed(4)}°E`;

        if (window.APP) window.APP.userLocation = label;

        // Switch bar state
        const bar = document.getElementById('ttLocBar');
        if (bar) bar.className = bar.className.replace(/\bst-\S+/g, '').trim() + ' st-allow';

        const lbText = document.getElementById('ttLbText');
        const lbCoord = document.getElementById('ttLbCoord');
        if (lbText) lbText.textContent = `📍 ${label}`;
        if (lbCoord) lbCoord.textContent = `${lat.toFixed(5)}°N, ${lon.toFixed(5)}°E`;

        placeMarker(lat, lon, label, acc);
        if (typeof window.fetchWeather === 'function') window.fetchWeather(lat, lon);
        if (typeof window.fetchTyphoons === 'function') setTimeout(() => window.fetchTyphoons(), 500);
        if (typeof window.updateMapMarkers === 'function') setTimeout(window.updateMapMarkers, 400);
    }

    /* ── Place pulsing GPS marker ─────────────────────────────────────────── */
    function placeMarker(lat, lon, label, acc, tries) {
        tries = tries || 0;
        const map = window.APP?.map || null;
        if (!map) { if (tries < 30) setTimeout(() => placeMarker(lat, lon, label, acc, tries + 1), 250); return; }

        if (window.APP?.userMarker) { try { map.removeLayer(window.APP.userMarker); } catch (_) { } }

        const icon = L.divIcon({
            html: `<div style="position:relative;width:24px;height:24px;display:flex;align-items:center;justify-content:center">
                <div style="position:absolute;width:24px;height:24px;border-radius:50%;background:rgba(225,29,72,.18);animation:_up 2s ease-out infinite"></div>
                <div style="position:absolute;width:24px;height:24px;border-radius:50%;background:rgba(225,29,72,.1);animation:_up 2s ease-out .5s infinite"></div>
                <div style="width:13px;height:13px;border-radius:50%;background:#e11d48;border:2.5px solid #fff;box-shadow:0 0 0 3px rgba(225,29,72,.32),0 2px 10px rgba(225,29,72,.55);position:relative;z-index:1"></div>
            </div><style>@keyframes _up{0%{transform:scale(.6);opacity:.8}100%{transform:scale(3);opacity:0}}</style>`,
            className: '', iconAnchor: [12, 12],
        });

        const marker = L.marker([lat, lon], { icon, zIndexOffset: 1000 }).addTo(map)
            .bindPopup(`<div style="font-family:'Sora',sans-serif;min-width:175px">
                <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:4px">📍 Your Location</div>
                <div style="font-size:11px;color:#334155;margin-bottom:3px">${label}</div>
                <div style="font-size:10px;color:#94a3b8">${lat.toFixed(5)}°N, ${lon.toFixed(5)}°E</div>
                <div style="font-size:10px;color:#94a3b8">±${Math.round(acc)} m accuracy</div>
            </div>`).openPopup();

        if (window.APP) window.APP.userMarker = marker;
        map.flyTo([lat, lon], 13, { animate: true, duration: 1.6 });
    }

    /* ── Reverse geocode ──────────────────────────────────────────────────── */
    async function reverseGeocode(lat, lon) {
        try {
            const r = await fetch(
                `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json`,
                { headers: { 'Accept-Language': 'en' }, signal: AbortSignal.timeout(7000) }
            );
            const d = await r.json();
            const a = d.address || {};
            const parts = [
                a.village || a.suburb || a.neighbourhood || a.hamlet,
                a.city_district, a.city || a.town || a.municipality,
            ].filter(Boolean);
            return parts.length ? parts.join(', ') : d.display_name?.split(',').slice(0, 3).join(', ');
        } catch { return null; }
    }

    /* ── Boot ─────────────────────────────────────────────────────────────── */
    function boot() {
        wireRetryButton(0);
        watchBar();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        setTimeout(boot, 50);
    }

})();