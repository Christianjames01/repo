'use strict';
/**
 * typhoon_location_patch.js  v4
 *
 * Strategy: no APP proxy needed.
 * 1. Show GPS permission modal immediately on load (or auto-fire if previously allowed).
 * 2. Once GPS resolves, poll every 200ms for the Leaflet map to exist, then reposition it.
 * 3. Override the location label text directly via DOM.
 * 4. Re-fetch weather + hourly data at the real coords.
 */

// ── Persist choice ────────────────────────────────────────────────────────────
const LS_KEY = 'tt_gps_v4';

// ── If user previously denied, block geolocation so live patch can't place a dot ──
(function blockGeoIfDenied() {
    const choice = localStorage.getItem('tt_gps_v4');
    if (choice !== 'deny') return;   // only block when denied

    // Override getCurrentPosition to silently fail for any script other than ours
    const _realGet = navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
    let _ourCallActive = false;

    navigator.geolocation.getCurrentPosition = function (success, error, opts) {
        if (_ourCallActive) {
            _realGet(success, error, opts);
        } else {
            // Simulate denial for other scripts
            setTimeout(() => error?.({ code: 1, message: 'Blocked by location patch' }), 0);
        }
    };

    // Expose a way for our own code to bypass the block
    window._gpsUnblockedCall = function (success, error, opts) {
        _ourCallActive = true;
        _realGet(
            pos => { _ourCallActive = false; success(pos); },
            err => { _ourCallActive = false; error?.(err); },
            opts
        );
    };
})();


(function injectCSS() {
    const s = document.createElement('style');
    s.id = '__loc_patch_css';
    s.textContent = `
/* ── GPS modal ────────────────────────────── */
#ttGpsModal {
    display: none; position: fixed; inset: 0; z-index: 99999;
    background: rgba(9,18,38,.88); backdrop-filter: blur(14px);
    align-items: center; justify-content: center; padding: 20px;
}
#ttGpsModal.open { display: flex !important; }

@keyframes gpsBoxIn {
    from { opacity: 0; transform: scale(.86) translateY(30px); }
    to   { opacity: 1; transform: scale(1)   translateY(0);    }
}
.tt-gps-box {
    background: #fff; border-radius: 24px; width: 100%; max-width: 360px;
    overflow: hidden; box-shadow: 0 40px 100px rgba(9,18,38,.5);
    animation: gpsBoxIn .32s cubic-bezier(.34,1.56,.64,1) both;
    font-family: 'Sora', sans-serif;
}

/* hero */
.tt-gps-hero {
    background: linear-gradient(135deg, #0d1b36 0%, #1e3a8a 60%, #1c3461 100%);
    padding: 30px 24px 24px; text-align: center; position: relative; overflow: hidden;
}
.tt-gps-hero__bg {
    position: absolute; inset: 0; display: flex;
    align-items: center; justify-content: center; pointer-events: none;
}
.tt-gps-ring {
    position: absolute; border-radius: 50%;
    border: 1px solid rgba(255,255,255,.07);
}
.tt-gps-ring:nth-child(1) { width: 150px; height: 150px; }
.tt-gps-ring:nth-child(2) { width: 230px; height: 230px; }
.tt-gps-ring:nth-child(3) { width: 310px; height: 310px; }

@keyframes gpsPulse {
    0%   { transform: scale(.7);  opacity: .7; }
    100% { transform: scale(2.6); opacity: 0;  }
}
.tt-gps-icon-wrap {
    position: relative; z-index: 1;
    width: 68px; height: 68px; margin: 0 auto 14px;
    display: flex; align-items: center; justify-content: center;
}
.tt-gps-icon-wrap::before {
    content: ''; position: absolute; inset: 0; border-radius: 18px;
    background: rgba(255,255,255,.12);
    animation: gpsPulse 2.4s ease-out infinite;
}
.tt-gps-icon-wrap::after {
    content: ''; position: absolute; inset: 0; border-radius: 18px;
    background: rgba(255,255,255,.08);
    animation: gpsPulse 2.4s ease-out infinite .7s;
}
.tt-gps-icon {
    position: relative; z-index: 1;
    width: 68px; height: 68px; border-radius: 18px;
    background: rgba(255,255,255,.14); border: 1.5px solid rgba(255,255,255,.2);
    display: flex; align-items: center; justify-content: center;
    font-size: 30px; box-shadow: 0 6px 24px rgba(0,0,0,.3);
}
.tt-gps-title {
    position: relative; z-index: 1;
    font-size: 19px; font-weight: 800; color: #fff;
    letter-spacing: -.4px; margin-bottom: 7px;
}
.tt-gps-subtitle {
    position: relative; z-index: 1;
    font-size: 12px; color: rgba(255,255,255,.6); line-height: 1.65;
}

/* body */
.tt-gps-body { padding: 20px 22px 22px; }
.tt-gps-info {
    display: flex; gap: 10px; align-items: flex-start;
    background: #f0f9ff; border: 1px solid #bae6fd;
    border-radius: 10px; padding: 12px 14px; margin-bottom: 18px;
}
.tt-gps-info i { color: #0284c7; font-size: 13px; margin-top: 2px; flex-shrink: 0; }
.tt-gps-info p { font-size: 11px; color: #075985; line-height: 1.65; margin: 0; }
.tt-gps-info strong { font-weight: 700; display: block; margin-bottom: 2px; }

.tt-gps-btn-allow, .tt-gps-btn-deny {
    width: 100%; padding: 12px 16px; border-radius: 10px;
    font-size: 13px; font-weight: 700; font-family: 'Sora', sans-serif;
    cursor: pointer; border: none;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    transition: all .18s;
}
.tt-gps-btn-allow {
    background: linear-gradient(135deg, #0d1b36, #1c3461);
    color: #fff; margin-bottom: 9px;
    box-shadow: 0 4px 14px rgba(13,27,54,.25);
}
.tt-gps-btn-allow:hover { transform: translateY(-2px); box-shadow: 0 8px 22px rgba(13,27,54,.35); }
.tt-gps-btn-deny {
    background: #f8fafc; border: 1.5px solid #e2e8f0; color: #64748b;
}
.tt-gps-btn-deny:hover { background: #f1f5f9; }

.tt-gps-footer {
    margin-top: 12px; text-align: center;
    font-size: 10px; color: #94a3b8; line-height: 1.6;
}

/* ── Location status bar ──────────────────── */
#ttLocBar {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 20px 9px; font-size: 11px;
    transition: background .4s, color .4s;
    border-top: 1px solid var(--border, #e2e8f0);
}
#ttLocBar.st-wait    { background: #f8fafc; color: #64748b; }
#ttLocBar.st-fetch   { background: #eff6ff; color: #1e40af; }
#ttLocBar.st-ok      { background: #f0fdf4; color: #065f46; }
#ttLocBar.st-err     { background: #fff5f5; color: #7f1d1d; }
#ttLocBar.st-denied  { background: #f8fafc; color: #64748b; }

.tt-lb-dot {
    width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    transition: background .3s;
}
.st-wait  .tt-lb-dot { background: #94a3b8; }
.st-fetch .tt-lb-dot { background: #38bdf8; animation: lbP 1.2s ease infinite; }
.st-ok    .tt-lb-dot { background: #10b981; animation: lbPG 1.5s ease infinite; }
.st-err   .tt-lb-dot { background: #ef4444; }
.st-denied .tt-lb-dot{ background: #94a3b8; }

@keyframes lbP  { 0%,100%{box-shadow:0 0 0 0 rgba(56,189,248,.5)} 60%{box-shadow:0 0 0 6px rgba(56,189,248,0)} }
@keyframes lbPG { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.5)} 60%{box-shadow:0 0 0 6px rgba(16,185,129,0)} }

#ttLocBar .lb-text  { flex: 1; font-weight: 600; }
#ttLocBar .lb-coord { font-family: 'DM Mono', monospace; font-size: 9px; opacity: .6; }
#ttLocBar .lb-acc   { font-family: 'DM Mono', monospace; font-size: 9px; opacity: .7; }
#ttLocBar .lb-retry {
    margin-left: auto; padding: 2px 9px;
    background: transparent; border: 1px solid currentColor;
    border-radius: 5px; font-size: 10px; font-weight: 700;
    font-family: 'Sora', sans-serif; color: inherit; cursor: pointer;
}
#ttLocBar .lb-retry:hover { background: rgba(0,0,0,.06); }
    `;
    document.head.appendChild(s);
})();

// ── Build modal DOM ───────────────────────────────────────────────────────────
function buildModal() {
    if (document.getElementById('ttGpsModal')) return;
    const el = document.createElement('div');
    el.id = 'ttGpsModal';
    el.innerHTML = `
    <div class="tt-gps-box">
        <div class="tt-gps-hero">
            <div class="tt-gps-hero__bg">
                <div class="tt-gps-ring"></div>
                <div class="tt-gps-ring"></div>
                <div class="tt-gps-ring"></div>
            </div>
            <div class="tt-gps-icon-wrap">
                <div class="tt-gps-icon">📡</div>
            </div>
            <div class="tt-gps-title">Location Access</div>
            <div class="tt-gps-subtitle">
                Allow this Barangay Safety System to use<br>
                your real-time GPS location?
            </div>
        </div>
        <div class="tt-gps-body">
            <div class="tt-gps-info">
                <i class="fas fa-shield-alt"></i>
                <p>
                    <strong>Why your location is needed</strong>
                    Your GPS pin shows your exact position on the typhoon map,
                    calculates storm distances to your barangay, and loads weather
                    data specific to your area. <em>Location data is never stored or sent to any server.</em>
                </p>
            </div>
            <button class="tt-gps-btn-allow" id="ttGpsAllow">
                <i class="fas fa-map-marker-alt"></i> Allow Location Access
            </button>
            <button class="tt-gps-btn-deny" id="ttGpsDeny">
                <i class="fas fa-times"></i> Continue Without GPS
            </button>
            <div class="tt-gps-footer">
                🔒 Private &amp; secure · stays on your device only<br>
                You can change this anytime in your browser settings.
            </div>
        </div>
    </div>`;
    document.body.appendChild(el);

    document.getElementById('ttGpsAllow').onclick = onAllow;
    document.getElementById('ttGpsDeny').onclick = onDeny;
}

// ── Status bar ────────────────────────────────────────────────────────────────
function buildBar() {
    if (document.getElementById('ttLocBar')) return;

    // Try multiple selectors since the two patch files use different HTML structure
    const anchor =
        document.querySelector('.tt-location') ||
        document.querySelector('#userLocation')?.closest('div') ||
        document.querySelector('#userLocation')?.parentElement;

    if (!anchor) { setTimeout(buildBar, 300); return; }

    const bar = document.createElement('div');
    bar.id = 'ttLocBar';
    bar.className = 'st-wait';
    bar.innerHTML = `
        <div class="tt-lb-dot"></div>
        <i class="fas fa-crosshairs" style="font-size:10px;opacity:.55"></i>
        <span class="lb-text" id="ttLbText">GPS not yet requested</span>
        <span class="lb-coord" id="ttLbCoord"></span>
        <span class="lb-acc"   id="ttLbAcc"></span>`;

    // Insert after the anchor element
    anchor.parentNode?.insertBefore(bar, anchor.nextSibling) || document.body.appendChild(bar);
}

function setBar(state, text, coord, acc) {
    const bar = document.getElementById('ttLocBar');
    if (!bar) return;
    bar.className = state;
    const t = document.getElementById('ttLbText'); if (t) t.textContent = text;
    const c = document.getElementById('ttLbCoord'); if (c) c.textContent = coord || '';
    const a = document.getElementById('ttLbAcc'); if (a) a.textContent = acc ? `±${Math.round(acc)}m` : '';

    // Retry button
    document.querySelector('#ttLocBar .lb-retry')?.remove();
    if (state === 'st-err') {
        const btn = document.createElement('button');
        btn.className = 'lb-retry';
        btn.textContent = '↺ Retry GPS';
        btn.onclick = () => { setBar('st-fetch', 'Retrying GPS…', ''); startGPS(); };
        bar.appendChild(btn);
    }
}

// ── Shared state ─────────────────────────────────────────────────────────────
let _confirmedLabel = null;

// ── Apply GPS position to everything ─────────────────────────────────────────
async function applyGPS(lat, lon, acc) {
    setBar('st-fetch', 'Looking up address…', `${lat.toFixed(5)}°N, ${lon.toFixed(5)}°E`, acc);

    // Geocode
    const label = await reverseGeocode(lat, lon) || `${lat.toFixed(4)}°N, ${lon.toFixed(4)}°E`;

    setBar('st-ok', `📍 ${label}`, `${lat.toFixed(5)}°N, ${lon.toFixed(5)}°E`, acc);

    // Save for the overwrite guard
    _confirmedLabel = label;

    if (window.APP) {
        window.APP.userLat = lat;
        window.APP.userLon = lon;
        window.APP.userLocation = label;
    }

    // ── Update the location text element (both possible IDs)
    ['userLocation', 'locationText'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.textContent = label;
    });
    // Also update the .tt-location span text node
    document.querySelectorAll('.tt-location').forEach(el => {
        const span = el.querySelector('span') || el;
        if (span) span.textContent = label;
    });

    // ── Wait for Leaflet map + rebuild marker (poll)
    waitForMap(lat, lon, label, acc);

    // ── Re-fetch weather
    if (typeof window.fetchWeather === 'function') window.fetchWeather(lat, lon);
    if (typeof window.fetchHourlyData === 'function') {
        window._hourlyFetched = false;
        window._hourlyByDate = {};
        window.fetchHourlyData(lat, lon);
    }
}

// ── Poll for map readiness ────────────────────────────────────────────────────
function waitForMap(lat, lon, label, acc, tries = 0) {
    const map = window.APP?.map || (window.map && typeof window.map.setView === 'function' ? window.map : null);
    if (!map) {
        if (tries < 50) setTimeout(() => waitForMap(lat, lon, label, acc, tries + 1), 200);
        return;
    }
    placeMarker(map, lat, lon, label, acc);
    map.flyTo([lat, lon], 15, { animate: true, duration: 1.8 });
}

function placeMarker(map, lat, lon, label, acc) {
    // Remove old user marker
    const appMarker = window.APP?.userMarker;
    if (appMarker) { try { map.removeLayer(appMarker); } catch (e) { } if (window.APP) window.APP.userMarker = null; }

    // Remove old accuracy circle
    if (window._gpsPatchCircle) { try { map.removeLayer(window._gpsPatchCircle); } catch (e) { } window._gpsPatchCircle = null; }

    // Accuracy ring
    if (acc && acc < 5000) {
        window._gpsPatchCircle = L.circle([lat, lon], {
            radius: acc, color: '#e11d48', fillColor: '#e11d48',
            fillOpacity: .07, weight: 1, dashArray: '5,4', interactive: false,
        }).addTo(map);
    }

    // Pulsing user dot
    const icon = L.divIcon({
        html: `
        <div style="position:relative;width:24px;height:24px;display:flex;align-items:center;justify-content:center">
            <div style="position:absolute;width:24px;height:24px;border-radius:50%;
                background:rgba(225,29,72,.2);animation:_gpsRing 2s ease-out infinite"></div>
            <div style="position:absolute;width:24px;height:24px;border-radius:50%;
                background:rgba(225,29,72,.12);animation:_gpsRing 2s ease-out infinite .5s"></div>
            <div style="width:13px;height:13px;border-radius:50%;background:#e11d48;
                border:2.5px solid #fff;
                box-shadow:0 0 0 3px rgba(225,29,72,.35),0 2px 10px rgba(225,29,72,.6);
                position:relative;z-index:1"></div>
        </div>
        <style>
          @keyframes _gpsRing{0%{transform:scale(.6);opacity:.8}100%{transform:scale(3);opacity:0}}
        </style>`,
        className: '',
        iconAnchor: [12, 12],
    });

    const marker = L.marker([lat, lon], { icon, zIndexOffset: 1000 })
        .addTo(map)
        .bindPopup(`
        <div style="font-family:'Sora',sans-serif;min-width:185px">
            <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:4px">📍 Your Location</div>
            <div style="font-size:11px;color:#334155;margin-bottom:4px">${label}</div>
            <div style="font-family:'DM Mono',monospace;font-size:10px;color:#94a3b8">
                ${lat.toFixed(5)}°N, ${lon.toFixed(5)}°E
            </div>
            ${acc ? `<div style="font-size:10px;color:#94a3b8;margin-top:2px">GPS accuracy: ±${Math.round(acc)}m</div>` : ''}
        </div>`)
        .openPopup();

    if (window.APP) window.APP.userMarker = marker;

    // Also call main script's updateMapMarkers if available
    if (typeof window.updateMapMarkers === 'function') {
        setTimeout(() => window.updateMapMarkers(), 100);
    }
}

// ── Reverse geocode ───────────────────────────────────────────────────────────
async function reverseGeocode(lat, lon) {
    try {
        const r = await fetch(
            `https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json&accept-language=en`,
            { headers: { 'Accept-Language': 'en' }, signal: AbortSignal.timeout(8000) }
        );
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const d = await r.json();
        const a = d.address || {};
        const parts = [
            a.village || a.suburb || a.neighbourhood || a.hamlet || a.quarter,
            a.city_district || a.borough,
            a.city || a.town || a.municipality,
        ].filter(Boolean);
        return parts.length ? parts.join(', ') : d.display_name?.split(',').slice(0, 3).join(', ') || null;
    } catch { return null; }
}

// ── Remove the wrong default marker placed by main script ────────────────────
let _sweepInterval = null;

function removeDefaultMarker(tries = 0) {
    const map = window.APP?.map ||
        (window.map && typeof window.map.setView === 'function' ? window.map : null);

    if (!map) {
        if (tries < 40) setTimeout(() => removeDefaultMarker(tries + 1), 250);
        return;
    }

    // Do one sweep now
    _sweepMap(map);

    // Then keep sweeping every 400ms for 8 seconds to catch late-placed markers
    // (typhoon_live_patch.js places its own marker after DOMContentLoaded)
    if (_sweepInterval) clearInterval(_sweepInterval);
    let sweepCount = 0;
    _sweepInterval = setInterval(() => {
        _sweepMap(map);
        if (++sweepCount >= 20) {   // 20 × 400ms = 8 seconds
            clearInterval(_sweepInterval);
            _sweepInterval = null;
        }
    }, 400);
}

function _sweepMap(map) {
    // Remove APP.userMarker
    if (window.APP?.userMarker) {
        try { map.removeLayer(window.APP.userMarker); } catch (e) { }
        window.APP.userMarker = null;
    }

    // Remove our own accuracy circle
    if (window._gpsPatchCircle) {
        try { map.removeLayer(window._gpsPatchCircle); } catch (e) { }
        window._gpsPatchCircle = null;
    }

    // Remove ANY layer (marker or circle) near the two known wrong default coords:
    //   Main PHP:  7.0800, 125.6200
    //   Live patch fallback: 7.09298, 125.63504
    const WRONG_PINS = [
        { lat: 7.0800, lon: 125.6200 },
        { lat: 7.09298, lon: 125.63504 },
    ];
    const THRESH = 0.08;   // ~8km radius — catches any rounding

    map.eachLayer(layer => {
        try {
            const ll = layer.getLatLng?.();
            if (!ll) return;
            for (const pin of WRONG_PINS) {
                if (Math.abs(ll.lat - pin.lat) < THRESH && Math.abs(ll.lng - pin.lon) < THRESH) {
                    map.removeLayer(layer);
                    return;
                }
            }
        } catch (e) { }
    });

    // Also null out any geolocation watcher the live patch may have started
    if (window._locationWatchId != null) {
        try { navigator.geolocation.clearWatch(window._locationWatchId); } catch (e) { }
        window._locationWatchId = null;
    }

    // Keep map at Philippines overview
    // Only force this on first few sweeps (don't fight the user panning later)
    if (!window._gpsDeniedViewSet) {
        map.setView([12.5, 122.0], 6);
        window._gpsDeniedViewSet = true;
    }
}

// ── GPS request ───────────────────────────────────────────────────────────────
function startGPS() {
    if (!navigator.geolocation) {
        setBar('st-err', 'GPS not supported by this browser.', '');
        return;
    }
    setBar('st-fetch', 'Acquiring GPS signal…', '');

    const gpsCall = window._gpsUnblockedCall || navigator.geolocation.getCurrentPosition.bind(navigator.geolocation);
    gpsCall(
        pos => applyGPS(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy),
        err => {
            const msgs = {
                1: 'Location access denied. Allow in browser settings, then tap Retry.',
                2: 'GPS position unavailable. Move near a window and retry.',
                3: 'GPS request timed out. Tap Retry.',
            };
            setBar('st-err', msgs[err.code] || 'GPS error: ' + err.message, '');
            removeDefaultMarker();   // ← clear the wrong pin
            // If browser blocked permission, reset choice so modal re-shows on next visit
            if (err.code === 1) localStorage.removeItem(LS_KEY);
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

// ── Modal button callbacks ────────────────────────────────────────────────────
function onAllow() {
    document.getElementById('ttGpsModal')?.classList.remove('open');
    localStorage.setItem(LS_KEY, 'allow');
    // If geolocation was blocked, restore it
    window._gpsUnblockedCall = null;
    startGPS();
}

function onDeny() {
    document.getElementById('ttGpsModal')?.classList.remove('open');
    localStorage.setItem(LS_KEY, 'deny');
    window._locationDenied = true;
    setBar('st-denied', '📍 Location access declined — map shows full Philippines view', '');
    removeDefaultMarker();   // ← clear wrong pin, zoom out to PH
}

// ── Decide what to do based on stored choice ──────────────────────────────────
function decideAction() {
    buildBar();   // always inject the bar first

    const choice = localStorage.getItem(LS_KEY);

    if (choice === 'allow') {
        // Previously allowed — go straight to GPS, no modal
        setBar('st-fetch', 'Acquiring GPS signal…', '');
        startGPS();
    } else if (choice === 'deny') {
        window._locationDenied = true;
        setBar('st-denied', '📍 Location access declined — map shows full Philippines view', '');
        removeDefaultMarker();
    } else {
        // First time — show the modal
        setBar('st-wait', 'Waiting for location permission…', '');
        setTimeout(() => {
            document.getElementById('ttGpsModal')?.classList.add('open');
        }, 800);   // short delay so user sees the page first
    }
}

// ── Guard: watch for other scripts overwriting the location label ─────────────
function watchLocationOverwrite() {
    const getTargets = () => [
        document.getElementById('userLocation'),
        document.querySelector('.tt-location span'),
        document.querySelector('.tt-location'),
    ].filter(Boolean);

    let targets = getTargets();
    if (!targets.length) { setTimeout(watchLocationOverwrite, 400); return; }

    const restore = () => {
        if (!_confirmedLabel) return;
        getTargets().forEach(el => {
            const txt = (el.textContent || '').trim();
            if (!txt || txt === 'Philippines' ||
                txt.startsWith('Detecting') || txt.startsWith('📍 Nagsil') ||
                txt.includes('Enable GPS') || txt.includes('default')) {
                el.textContent = _confirmedLabel;
            }
        });
    };

    const obs = new MutationObserver(restore);
    targets.forEach(el => obs.observe(el, { childList: true, characterData: true, subtree: true }));
}

// ── Boot ──────────────────────────────────────────────────────────────────────
buildModal();

function fullBoot() {
    decideAction();
    setTimeout(watchLocationOverwrite, 600);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', fullBoot);
} else {
    setTimeout(fullBoot, 50);
}