/**
 * typhoon_live_patch.js — v3 (CLIENT-SIDE FALLBACK)
 *
 * Strategy:
 *  1. Try pagasa_proxy.php (server-side) — 8s timeout
 *  2. If server times out/fails → fetch GDACS + Open-Meteo directly from browser (CORS-open)
 *  3. Empty typhoons → green All Clear (never "unavailable" just because it's quiet season)
 */

const PROXY_TIMEOUT_MS = 8000;
const GDACS_API = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red';
const OPEN_METEO_BASE = 'https://api.open-meteo.com/v1/forecast';

// ── Main entry ────────────────────────────────────────────────────────────────
async function fetchTyphoons() {
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.classList.add('tt-refresh-btn--spinning');
    renderTyphoonSkeleton();

    const minDelay = new Promise(r => setTimeout(r, 1800));
    const lat = (typeof APP !== 'undefined' ? APP.userLat : null) || 12.8797;
    const lon = (typeof APP !== 'undefined' ? APP.userLon : null) || 121.7740;

    let result = null;

    // Step 1: server proxy
    try {
        const ctrl = new AbortController();
        const timer = setTimeout(() => ctrl.abort(), PROXY_TIMEOUT_MS);
        const res = await fetch(`pagasa_proxy.php?lat=${lat}&lon=${lon}`, { signal: ctrl.signal });
        clearTimeout(timer);
        if (res.ok) {
            const data = await res.json();
            if (data && data.success !== false) {
                result = data;
                console.info('[TyphoonTracker] Server proxy OK ✅', data.source);
            }
        }
    } catch (e) {
        console.warn('[TyphoonTracker] Server proxy failed — using browser fallback…', e.message);
    }

    // Step 2: browser-side (GDACS + Open-Meteo directly)
    if (!result) result = await fetchClientSide(lat, lon);

    await minDelay;

    if (result) {
        const typhoons = result.typhoons || [];
        if (typeof APP !== 'undefined') APP.typhoonData = typhoons;
        if (typeof window.typhoonData !== 'undefined') window.typhoonData = typhoons;

        const lu = document.getElementById('lastUpdate');
        if (lu) {
            const t = new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
            lu.textContent = `Updated ${t} · ${result.source || 'Live'}`;
        }

        if (typeof renderTyphoonList === 'function') renderTyphoonList(typhoons, result.bulletin);
        renderRainThunderPanel(result.rain, result.thunderstorm, result.current_weather);
        if (typeof updateMapMarkers === 'function') updateMapMarkers();
        if (typeof addTyphoonMarkers === 'function') addTyphoonMarkers();
    } else {
        renderTyphoonListUnavailable('All data sources unreachable.');
    }

    if (btn) btn.classList.remove('tt-refresh-btn--spinning');
}

// =============================================================================
// BROWSER-SIDE FETCHERS
// =============================================================================

async function fetchClientSide(lat, lon) {
    const [tcResult, rainResult] = await Promise.allSettled([
        fetchGDACSFromBrowser(),
        fetchOpenMeteoFromBrowser(lat, lon),
    ]);

    const typhoons = tcResult.status === 'fulfilled' ? (tcResult.value || []) : [];
    const rainData = rainResult.status === 'fulfilled' ? (rainResult.value || {}) : {};

    const enriched = typhoons.map(tc => ({
        ...tc,
        distance: Math.round(haversineKm(lat, lon, tc.lat, tc.lon)),
        risk: distanceToRisk(haversineKm(lat, lon, tc.lat, tc.lon)),
        direction: cardinalDirection(lat, lon, tc.lat, tc.lon),
    })).sort((a, b) => a.distance - b.distance);

    return {
        success: true,
        typhoons: enriched,
        bulletin: enriched.length === 0
            ? 'No active tropical cyclones in the Western Pacific. (GDACS)'
            : null,
        source: 'GDACS (browser)',
        fetched_at: new Date().toISOString(),
        rain: rainData.rain || null,
        thunderstorm: rainData.thunderstorm || null,
        current_weather: rainData.current_weather || null,
    };
}

async function fetchGDACSFromBrowser() {
    try {
        const today = fmtDate(0);
        const tomorrow = fmtDate(1);
        const weekAgo = fmtDate(-7);
        const url = `${GDACS_API}&fromDate=${weekAgo}&toDate=${tomorrow}`;
        const res = await fetch(url, { signal: AbortSignal.timeout(12000) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const data = await res.json();

        const features = data.features || [];
        console.info(`[TyphoonTracker] GDACS: ${features.length} event(s)`);

        return features.reduce((acc, f) => {
            const p = f.properties || {};
            const geo = f.geometry || {};
            const lon = geo.coordinates?.[0] ?? p.lon;
            const lat = geo.coordinates?.[1] ?? p.lat;
            if (typeof lon !== 'number' || lon < 100 || lon > 180) return acc;
            if (typeof lat !== 'number' || lat < 0 || lat > 40) return acc;
            const windKmh = Math.round((parseFloat(p.maxwind ?? 0) || 0) * 1.852) || (p.windspeed ?? 0);
            acc.push({
                name: ((p.name ?? p.eventname ?? 'UNNAMED') + '').toUpperCase(),
                category: windToCategory(windKmh),
                windSpeed: windKmh,
                lat: parseFloat(lat),
                lon: parseFloat(lon),
                signal: windToSignal(windKmh),
                source: 'GDACS',
            });
            return acc;
        }, []);
    } catch (e) {
        console.warn('[TyphoonTracker] GDACS fetch failed:', e.message);
        return [];
    }
}

async function fetchOpenMeteoFromBrowser(lat, lon) {
    try {
        const url = `${OPEN_METEO_BASE}?latitude=${lat}&longitude=${lon}`
            + '&current_weather=true'
            + '&hourly=precipitation_probability,weathercode,precipitation'
            + '&daily=precipitation_probability_max,precipitation_sum,weathercode'
            + '&timezone=Asia%2FManila&forecast_days=3';
        const res = await fetch(url, { signal: AbortSignal.timeout(10000) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        const d = await res.json();

        const hour = new Date().getHours();
        const rainProbs = (d.hourly?.precipitation_probability ?? []).slice(hour, hour + 12);
        const codes = (d.hourly?.weathercode ?? []).slice(hour, hour + 12);
        const maxRain = rainProbs.length ? Math.max(...rainProbs) : 0;
        const tCount = codes.filter(c => c >= 95).length;
        const tProb = codes.length ? Math.round(tCount / codes.length * 100) : 0;
        const rain24h = parseFloat(d.daily?.precipitation_sum?.[0] ?? 0);
        const curCode = d.current_weather?.weathercode ?? 0;
        const curWx = classifyWeatherCode(curCode);
        const dailyCode = d.daily?.weathercode?.[0] ?? 0;
        const warning = pagasaRainWarning(rain24h, tProb, maxRain);

        return {
            rain: {
                probability_pct: maxRain,
                accumulation_mm: rain24h,
                warning_level: warning.level,
                warning_action: warning.action,
                warning_color: warning.color,
                warning_bg: warning.bg,
                warning_icon: warning.icon,
                current_label: curWx.label,
                current_icon: curWx.icon,
            },
            thunderstorm: {
                probability_pct: tProb,
                active: curCode >= 95,
                expected_today: tProb >= 20 || dailyCode >= 95,
                label: thunderLabel(tProb),
            },
            current_weather: {
                wind_kmh: parseFloat(d.current_weather?.windspeed ?? 0),
                temp_c: parseFloat(d.current_weather?.temperature ?? 0),
                condition: curWx.label,
                icon: curWx.icon,
            },
        };
    } catch (e) {
        console.warn('[TyphoonTracker] Open-Meteo fetch failed:', e.message);
        return { rain: null, thunderstorm: null, current_weather: null };
    }
}

// =============================================================================
// UNAVAILABLE STATE
// =============================================================================
function renderTyphoonListUnavailable(msg) {
    const el = document.getElementById('typhoonList');
    if (!el) return;
    el.innerHTML = `
        <div style="padding:14px 18px;opacity:0;transform:translateY(8px);
                    transition:opacity .5s,transform .5s" id="unavailInner">
            <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;
                        background:var(--info-light,#eff6ff);border:1px solid #93c5fd;
                        border-radius:10px;margin-bottom:10px">
                <i class="fas fa-satellite-dish" style="color:#3b82f6;margin-top:1px;flex-shrink:0"></i>
                <div>
                    <div style="font-size:11px;font-weight:700;color:#1e40af;margin-bottom:3px">Live Data Unavailable</div>
                    <div style="font-size:11px;color:#1e40af;line-height:1.55">${msg || 'Could not reach any data source.'}</div>
                </div>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                ${[['PAGASA', 'https://bagong.pagasa.dost.gov.ph'], ['JMA', 'https://www.jma.go.jp/en/typh/'],
        ['JTWC', 'https://www.jtwc.navy.mil'], ['GDACS', 'https://www.gdacs.org']]
            .map(([l, u]) => `<a href="${u}" target="_blank"
                    style="font-size:11px;font-weight:700;padding:5px 12px;border-radius:20px;
                           background:var(--navy-light,#1e40af);color:#fff;text-decoration:none">${l} ↗</a>`).join('')}
            </div>
        </div>`;
    requestAnimationFrame(() => requestAnimationFrame(() => {
        const n = document.getElementById('unavailInner');
        if (n) { n.style.opacity = '1'; n.style.transform = 'translateY(0)'; }
    }));
}

// =============================================================================
// RAIN + THUNDER PANEL
// =============================================================================
function renderRainThunderPanel(rain, thunder, currentWx) {
    const PANEL_ID = 'rainThunderPanel';
    let panel = document.getElementById(PANEL_ID);
    if (!panel) {
        const fp = document.querySelector('.tt-panel');
        if (!fp) return;
        panel = document.createElement('div');
        panel.id = PANEL_ID;
        panel.className = 'tt-panel';
        panel.style.animationDelay = '.07s';
        fp.parentNode.insertBefore(panel, fp.nextSibling);
    }
    if (!rain) { panel.style.display = 'none'; return; }
    panel.style.display = '';

    const rp = rain.probability_pct ?? 0, r24 = rain.accumulation_mm ?? 0,
        tp = thunder?.probability_pct ?? 0, tNow = thunder?.active ?? false,
        tDay = thunder?.expected_today ?? false, tLbl = thunder?.label ?? 'No Thunderstorm Expected',
        wi = rain.warning_icon ?? '✅', wl = rain.warning_level ?? 'None',
        wa = rain.warning_action ?? '', wb = rain.warning_bg ?? '#f3f4f6',
        wc = rain.warning_color ?? '#374151', ci = rain.current_icon ?? '☀️', cl = rain.current_label ?? 'Clear';

    const mc = (lbl, val, unit, c1, c2, bar) => `
        <div style="background:var(--surf2);border:1px solid var(--border);border-radius:12px;padding:13px;text-align:center">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px">${lbl}</div>
            <div style="font-size:26px;font-weight:800;color:${c1};letter-spacing:-1px;line-height:1">
                ${val}<span style="font-size:12px;font-weight:500;color:var(--muted)">${unit}</span></div>
            <div style="margin-top:8px;height:5px;border-radius:3px;background:var(--border);overflow:hidden">
                <div style="height:100%;border-radius:3px;width:${bar}%;background:linear-gradient(90deg,${c1},${c2});transition:width 1.2s cubic-bezier(.34,1.56,.64,1)"></div>
            </div>
        </div>`;

    panel.innerHTML = `
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon" style="background:#e0f2fe;color:#0ea5e9"><i class="fas fa-cloud-rain"></i></span>
                <h2>Rain &amp; Thunderstorm</h2>
            </div>
            <span class="tt-badge tt-badge--muted tt-mono" style="font-size:9px">${ci} ${cl}</span>
        </div>
        <div style="margin:0 16px 14px;padding:12px 14px;border-radius:10px;background:${wb};border:1px solid ${wc}44;display:flex;align-items:flex-start;gap:10px">
            <span style="font-size:20px;flex-shrink:0;line-height:1.2">${wi}</span>
            <div>
                <div style="font-size:12px;font-weight:700;color:${wc};margin-bottom:2px">${wl} Rainfall
                    ${tNow ? '<span style="margin-left:8px;font-size:10px;padding:1px 7px;border-radius:10px;background:#fef3c7;color:#92400e">⚡ THUNDER NOW</span>' : ''}</div>
                <div style="font-size:11px;color:${wc};line-height:1.6">${wa}</div>
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;padding:0 16px 14px">
            ${mc('Rain Prob.', rp, '%', '#0ea5e9', '#38bdf8', rp)}
            ${mc('24h Rain', r24, 'mm', '#3b82f6', '#60a5fa', Math.min(100, r24 / 1.5))}
            <div style="background:${tNow || tDay ? '#fffbeb' : 'var(--surf2)'};border:1px solid ${tNow ? '#fcd34d' : 'var(--border)'};border-radius:12px;padding:13px;text-align:center">
                <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px">Thunder</div>
                <div style="font-size:26px;font-weight:800;letter-spacing:-1px;line-height:1;color:${tNow ? '#92400e' : tDay ? '#b45309' : 'var(--muted)'}">
                    ${tp}<span style="font-size:12px;font-weight:500;color:var(--muted)">%</span></div>
                <div style="margin-top:5px;font-size:9px;font-weight:700;color:${tNow ? '#92400e' : tDay ? '#b45309' : '#94a3b8'}">
                    ${tNow ? '⚡ ACTIVE NOW' : tDay ? '⚡ Expected Today' : 'Not Expected'}</div>
                <div style="margin-top:6px;height:5px;border-radius:3px;background:var(--border);overflow:hidden">
                    <div style="height:100%;border-radius:3px;width:${tp}%;background:linear-gradient(90deg,#f59e0b,#fbbf24);transition:width 1.2s cubic-bezier(.34,1.56,.64,1)"></div>
                </div>
            </div>
        </div>
        ${tp >= 10 ? `<div style="margin:0 16px 12px;padding:8px 13px;border-radius:8px;
            background:${tp >= 50 ? '#fef3c7' : '#f0f9ff'};border:1px solid ${tp >= 50 ? '#fcd34d' : '#bae6fd'};
            font-size:11px;font-weight:600;color:${tp >= 50 ? '#92400e' : '#0369a1'};
            display:flex;align-items:center;gap:7px"><i class="fas fa-bolt"></i> ${tLbl}</div>` : ''}
        <div style="padding:0 16px 14px">
            <div style="font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin-bottom:6px;display:flex;align-items:center;gap:6px">
                PAGASA Rain Scale<span style="flex:1;height:1px;background:var(--border);display:block"></span></div>
            <div style="display:flex;gap:4px">
                ${[['✅', 'None', '<15mm', '#065f46', '#d1fae5'], ['🟢', 'Light', '15–50', '#065f46', '#dcfce7'],
        ['🟡', 'Mod.', '50–100', '#92400e', '#fef3c7'], ['🟠', 'Heavy', '100–150', '#92400e', '#fef3c7'],
        ['🔴', 'Extreme', '>150mm', '#7f1d1d', '#fee2e2']].map(([ico, lbl, range, c, bg]) => `
                    <div style="flex:1;text-align:center;padding:5px 3px;border-radius:7px;background:${bg};border:1px solid ${c}33">
                        <div style="font-size:13px">${ico}</div>
                        <div style="font-size:8px;font-weight:700;color:${c};line-height:1.2">${lbl}</div>
                        <div style="font-size:7.5px;color:${c};font-family:'DM Mono',monospace;line-height:1.4">${range}</div>
                    </div>`).join('')}
            </div>
        </div>`;
}

// =============================================================================
// HELPERS
// =============================================================================
function haversineKm(lat1, lon1, lat2, lon2) { const R = 6371, dLat = (lat2 - lat1) * Math.PI / 180, dLon = (lon2 - lon1) * Math.PI / 180, a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2; return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)); }
function cardinalDirection(fLat, fLon, tLat, tLon) { const a = Math.atan2(tLon - fLon, tLat - fLat) * 180 / Math.PI, d = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'N']; return d[Math.round(a / 45) + 4] || 'N'; }
function distanceToRisk(d) { return d < 200 ? 'CRITICAL' : d < 400 ? 'HIGH' : d < 700 ? 'MODERATE' : 'LOW'; }
function windToCategory(k) { return k >= 185 ? 'Super Typhoon' : k >= 118 ? 'Typhoon' : k >= 89 ? 'Severe Tropical Storm' : k >= 62 ? 'Tropical Storm' : 'Tropical Depression'; }
function windToSignal(k) { return k >= 185 ? 5 : k >= 118 ? 4 : k >= 89 ? 3 : k >= 62 ? 2 : k >= 39 ? 1 : 0; }
function classifyWeatherCode(c) { if (c >= 95) return { icon: '⛈️', label: 'Thunderstorm' }; if (c >= 80) return { icon: '🌦️', label: 'Rain Showers' }; if (c >= 61) return { icon: '🌧️', label: 'Rain' }; if (c >= 51) return { icon: '🌦️', label: 'Drizzle' }; if (c >= 45) return { icon: '🌫️', label: 'Fog' }; if (c === 3) return { icon: '☁️', label: 'Overcast' }; if (c === 2) return { icon: '⛅', label: 'Partly Cloudy' }; if (c === 1) return { icon: '🌤️', label: 'Mainly Clear' }; return { icon: '☀️', label: 'Clear / Sunny' }; }
function pagasaRainWarning(r, tp, rp) { if (r >= 150 || tp >= 70) return { level: 'Extreme', color: '#7f1d1d', bg: '#fee2e2', icon: '🔴', action: 'Imminent danger of flooding. Evacuate immediately if in low-lying areas.' }; if (r >= 100 || (tp >= 50 && rp >= 60)) return { level: 'Heavy', color: '#92400e', bg: '#fef3c7', icon: '🟠', action: 'Heavy rainfall expected. Serious flooding possible. Prepare to evacuate.' }; if (r >= 50 || tp >= 30) return { level: 'Moderate', color: '#1e40af', bg: '#dbeafe', icon: '🟡', action: 'Moderate rain expected. Light flooding possible in low-lying areas.' }; if (r >= 15 || rp >= 30) return { level: 'Light', color: '#065f46', bg: '#d1fae5', icon: '🟢', action: 'Light rain expected. Carry an umbrella. Monitor updates.' }; return { level: 'None', color: '#374151', bg: '#f3f4f6', icon: '✅', action: 'No significant rainfall expected. Conditions are generally fair.' }; }
function thunderLabel(p) { return p >= 60 ? 'High Thunderstorm Risk' : p >= 30 ? 'Moderate Thunderstorm Risk' : p >= 10 ? 'Slight Chance of Thunderstorm' : 'No Thunderstorm Expected'; }
function fmtDate(offset) { const d = new Date(); d.setDate(d.getDate() + offset); return d.toISOString().slice(0, 10); }

console.log('[TyphoonTracker] Live patch v3 loaded — client-side fallback active ✅');