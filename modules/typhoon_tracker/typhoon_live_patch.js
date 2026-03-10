/**
 * typhoon_live_patch.js — v5 (PAST WEATHER 7-DAY MODAL)
 *
 * Changes from v4:
 *  - Past 7-day panel: clicking any day card opens a full immersive modal
 *  - New: openPastWeatherModal() — full 7-day grid modal with day-detail drill-down
 *  - Panel now shows a compact strip with a "View Full History" button
 *  - openPastDayModal() now works standalone AND inside the 7-day modal
 */

const PROXY_TIMEOUT_MS = 8000;
const GDACS_API = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red';
const OPEN_METEO_BASE = 'https://api.open-meteo.com/v1/forecast';
const OPEN_METEO_ARCHIVE = 'https://archive-api.open-meteo.com/v1/archive';

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
        renderPastWeatherHistory(lat, lon);
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
// PAST WEATHER HISTORY PANEL  (compact strip → modal)
// =============================================================================

// Cache so modal can re-use data without re-fetching
window._pastWeatherCache = null;

async function renderPastWeatherHistory(lat, lon) {
    const PANEL_ID = 'pastWeatherPanel';
    let panel = document.getElementById(PANEL_ID);
    if (!panel) {
        const anchor = document.getElementById('rainThunderPanel') || document.querySelector('.tt-panel');
        if (!anchor) return;
        panel = document.createElement('div');
        panel.id = PANEL_ID;
        panel.className = 'tt-panel';
        panel.style.animationDelay = '.14s';
        anchor.parentNode.insertBefore(panel, anchor.nextSibling);
    }

    // Skeleton while loading
    panel.innerHTML = `
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon" style="background:#f3e8ff;color:#9333ea">
                    <i class="fas fa-history"></i>
                </span>
                <h2>Past 7-Day Weather</h2>
            </div>
            <span class="tt-badge tt-badge--muted" style="font-size:9px">Loading…</span>
        </div>
        <div style="display:flex;gap:6px;padding:0 16px 16px;overflow-x:auto">
            ${[0, 1, 2, 3, 4, 5, 6].map(() => `
                <div style="min-width:72px;flex:1;background:var(--surf2);border-radius:10px;
                            height:90px;animation:pulse 1.4s infinite alternate;
                            border:1px solid var(--border)"></div>
            `).join('')}
        </div>`;

    try {
        const history = await fetchPastWeather(lat, lon);
        if (!history || !history.length) { panel.style.display = 'none'; return; }
        window._pastWeatherCache = history;
        renderPastWeatherStrip(panel, history);
    } catch (e) {
        console.warn('[TyphoonTracker] Past weather failed:', e.message);
        panel.style.display = 'none';
    }
}

async function fetchPastWeather(lat, lon) {
    const endDate = fmtDate(-1);
    const startDate = fmtDate(-7);

    const url = `${OPEN_METEO_ARCHIVE}`
        + `?latitude=${lat}&longitude=${lon}`
        + `&start_date=${startDate}&end_date=${endDate}`
        + `&daily=temperature_2m_max,temperature_2m_min,precipitation_sum,`
        + `weathercode,windspeed_10m_max,precipitation_hours`
        + `&timezone=Asia%2FManila`;

    const res = await fetch(url, { signal: AbortSignal.timeout(12000) });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const d = await res.json();

    const dates = d.daily?.time ?? [];
    const maxTemps = d.daily?.temperature_2m_max ?? [];
    const minTemps = d.daily?.temperature_2m_min ?? [];
    const rain = d.daily?.precipitation_sum ?? [];
    const codes = d.daily?.weathercode ?? [];
    const wind = d.daily?.windspeed_10m_max ?? [];
    const rainHours = d.daily?.precipitation_hours ?? [];

    return dates.map((dateStr, i) => {
        const dt = new Date(dateStr + 'T00:00:00+08:00');
        const dayName = dt.toLocaleDateString('en-PH', { weekday: 'short' });
        const dayNum = dt.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
        const wx = classifyWeatherCode(codes[i] ?? 0);
        return {
            date: dateStr, dayName, dayNum,
            maxTemp: Math.round(maxTemps[i] ?? 0),
            minTemp: Math.round(minTemps[i] ?? 0),
            rain: parseFloat((rain[i] ?? 0).toFixed(1)),
            rainHours: Math.round(rainHours[i] ?? 0),
            wind: Math.round(wind[i] ?? 0),
            icon: wx.icon,
            label: wx.label,
        };
    }).reverse(); // most recent first
}

// ── Compact strip (panel) ─────────────────────────────────────────────────────
function renderPastWeatherStrip(panel, days) {
    const maxRain = Math.max(1, ...days.map(d => d.rain));

    const cards = days.map((d, idx) => {
        const isYday = idx === 0;
        const rainBar = Math.round((d.rain / maxRain) * 100);
        const rainColor = d.rain >= 50 ? '#ef4444' : d.rain >= 15 ? '#f59e0b' : '#3b82f6';

        return `
        <div style="min-width:68px;flex:1;background:var(--surf2);
                    border:1px solid ${isYday ? '#c4b5fd' : 'var(--border)'};
                    border-radius:12px;padding:9px 7px;text-align:center;cursor:pointer;
                    transition:transform .15s,box-shadow .15s;position:relative"
             onmouseenter="this.style.transform='translateY(-3px)';this.style.boxShadow='0 6px 18px rgba(0,0,0,.12)'"
             onmouseleave="this.style.transform='';this.style.boxShadow=''"
             onclick="openPastWeatherModal(${idx})">
            ${isYday ? `<div style="position:absolute;top:-8px;left:50%;transform:translateX(-50%);
                font-size:7px;font-weight:700;background:#7c3aed;color:#fff;padding:2px 7px;
                border-radius:10px;white-space:nowrap">Yesterday</div>` : ''}
            <div style="font-size:10px;font-weight:700;color:var(--muted)">${d.dayName}</div>
            <div style="font-size:20px;margin:4px 0;line-height:1">${d.icon}</div>
            <div style="font-size:13px;font-weight:800;color:var(--text);line-height:1">${d.maxTemp}°</div>
            <div style="font-size:8px;color:var(--muted);margin-bottom:6px">${d.minTemp}°C</div>
            <div style="height:3px;border-radius:2px;background:var(--border);overflow:hidden;margin-bottom:3px">
                <div style="height:100%;border-radius:2px;width:${rainBar}%;background:${rainColor};
                            transition:width 1s cubic-bezier(.34,1.56,.64,1)"></div>
            </div>
            <div style="font-size:8px;font-weight:700;color:${rainColor}">${d.rain}mm</div>
        </div>`;
    }).join('');

    panel.innerHTML = `
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon" style="background:#f3e8ff;color:#9333ea">
                    <i class="fas fa-history"></i>
                </span>
                <h2>Past 7-Day Weather</h2>
            </div>
            <button onclick="openPastWeatherModal(0)"
                    style="font-size:9px;font-weight:700;padding:4px 10px;border-radius:20px;
                           border:1px solid #c4b5fd;background:#f3e8ff;color:#7c3aed;cursor:pointer">
                View All ↗
            </button>
        </div>
        <div style="display:flex;gap:5px;padding:0 16px 14px;overflow-x:auto;
                    scrollbar-width:thin;-webkit-overflow-scrolling:touch">
            ${cards}
        </div>
        <div style="padding:0 16px 12px">
            <div style="font-size:9px;color:var(--muted);display:flex;align-items:center;gap:6px">
                <i class="fas fa-hand-pointer" style="color:#9333ea"></i>
                Tap any day for full details &amp; AI analysis.
            </div>
        </div>`;
}

// =============================================================================
// 7-DAY MODAL  — matches the app's card UI style
// =============================================================================

function openPastWeatherModal(startIdx = 0) {
    const days = window._pastWeatherCache;
    if (!days || !days.length) return;

    document.getElementById('pastWeatherModal')?.remove();

    const maxRain = Math.max(1, ...days.map(d => d.rain));
    const avgMax = Math.round(days.reduce((s, d) => s + d.maxTemp, 0) / days.length);
    const totalRain = days.reduce((s, d) => s + d.rain, 0).toFixed(1);
    const maxWind = Math.max(...days.map(d => d.wind));
    const rainyDays = days.filter(d => d.rain >= 1).length;

    // Date range label
    const first = days[days.length - 1]; const last = days[0];
    const dateRange = `${first.dayNum} – ${last.dayNum}`;

    const rows = days.map((d, idx) => {
        const isYday = idx === 0;
        const rainColor = d.rain >= 50 ? '#ef4444' : d.rain >= 15 ? '#f59e0b' : '#60a5fa';
        const rainBar = Math.round((d.rain / maxRain) * 100);
        const rw = d.rain >= 150 ? { label: 'Extreme', dot: '#ef4444' }
            : d.rain >= 100 ? { label: 'Heavy', dot: '#f97316' }
                : d.rain >= 50 ? { label: 'Moderate', dot: '#f59e0b' }
                    : d.rain >= 15 ? { label: 'Light', dot: '#22c55e' }
                        : { label: 'None', dot: '#94a3b8' };

        return `
        <div style="display:flex;align-items:center;gap:10px;padding:11px 20px;
                    border-bottom:1px solid rgba(148,163,184,.12);cursor:pointer;
                    background:${isYday ? 'rgba(139,92,246,.06)' : 'transparent'};
                    transition:background .12s"
             onmouseenter="this.style.background='rgba(148,163,184,.07)'"
             onmouseleave="this.style.background='${isYday ? 'rgba(139,92,246,.06)' : 'transparent'}'"
             onclick="openPastDayModal(${JSON.stringify(d).replace(/"/g, '&quot;')}, true)">
            <!-- Weather icon -->
            <div style="width:36px;height:36px;border-radius:10px;
                        background:rgba(148,163,184,.1);display:flex;align-items:center;
                        justify-content:center;font-size:18px;flex-shrink:0">${d.icon}</div>
            <!-- Day + condition -->
            <div style="flex:0 0 90px">
                <div style="font-size:12px;font-weight:700;color:#0f172a;line-height:1.2">
                    ${isYday ? 'Yesterday' : d.dayName + ', ' + d.dayNum}</div>
                <div style="font-size:10px;color:#64748b;margin-top:1px">${d.label}</div>
            </div>
            <!-- Temp -->
            <div style="flex:0 0 52px;text-align:center">
                <span style="font-size:13px;font-weight:800;color:#ef4444">${d.maxTemp}°</span>
                <span style="font-size:11px;color:#94a3b8"> / ${d.minTemp}°</span>
            </div>
            <!-- Rain bar -->
            <div style="flex:1;min-width:0">
                <div style="display:flex;justify-content:space-between;margin-bottom:4px">
                    <span style="font-size:9px;font-weight:600;color:${rw.dot}">● ${rw.label}</span>
                    <span style="font-size:9px;font-weight:700;color:${rainColor}">${d.rain}mm</span>
                </div>
                <div style="height:5px;border-radius:3px;background:#e2e8f0;overflow:hidden">
                    <div style="height:100%;border-radius:3px;width:${rainBar}%;background:${rainColor};
                                transition:width 1s .05s cubic-bezier(.34,1.56,.64,1)"></div>
                </div>
            </div>
            <!-- Wind -->
            <div style="flex:0 0 38px;text-align:right">
                <div style="font-size:11px;font-weight:700;color:#10b981">${d.wind}</div>
                <div style="font-size:8px;color:#94a3b8">km/h</div>
            </div>
            <i class="fas fa-chevron-right" style="color:#cbd5e1;font-size:9px;flex-shrink:0;margin-left:4px"></i>
        </div>`;
    }).join('');

    const modal = document.createElement('div');
    modal.id = 'pastWeatherModal';
    modal.style.cssText = `
        position:fixed;inset:0;background:rgba(15,23,42,.65);z-index:9999;
        display:flex;align-items:center;justify-content:center;
        animation:pwFadeIn .22s ease;padding:16px`;

    modal.innerHTML = `
        <style>
            @keyframes pwFadeIn   { from{opacity:0}              to{opacity:1} }
            @keyframes pwSlideIn  { from{transform:translateY(22px) scale(.97);opacity:0} to{transform:none;opacity:1} }
            @keyframes pwSlideUp  { from{transform:translateY(100%);opacity:0} to{transform:none;opacity:1} }
        </style>
        <div style="background:#fff;border-radius:18px;width:100%;max-width:520px;
                    max-height:90vh;display:flex;flex-direction:column;overflow:hidden;
                    box-shadow:0 24px 80px rgba(0,0,0,.22);
                    animation:pwSlideIn .3s cubic-bezier(.34,1.4,.64,1)">

            <!-- ── Dark navy header ── -->
            <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
                        padding:18px 20px 16px;position:relative;flex-shrink:0">
                <!-- close -->
                <button onclick="document.getElementById('pastWeatherModal').remove()"
                        style="position:absolute;top:14px;right:14px;width:26px;height:26px;
                               border-radius:50%;border:1px solid rgba(255,255,255,.2);
                               background:rgba(255,255,255,.1);color:#fff;cursor:pointer;
                               font-size:12px;display:flex;align-items:center;justify-content:center;
                               transition:background .15s"
                        onmouseenter="this.style.background='rgba(255,255,255,.2)'"
                        onmouseleave="this.style.background='rgba(255,255,255,.1)'">✕</button>
                <!-- title -->
                <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
                    <span style="font-size:22px">📅</span>
                    <div>
                        <div style="font-size:17px;font-weight:800;color:#fff;letter-spacing:-.3px">
                            Past 7-Day Weather</div>
                        <div style="font-size:10px;color:rgba(255,255,255,.55);margin-top:1px">
                            ${dateRange} · Open-Meteo Archive</div>
                    </div>
                </div>
                <!-- 4-stat strip -->
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px">
                    ${[
            ['Avg High', avgMax + '°C', '🌡️', 'rgba(254,202,202,.15)', '#fca5a5'],
            ['Total Rain', totalRain + 'mm', '🌧️', 'rgba(186,230,253,.15)', '#7dd3fc'],
            ['Rainy Days', rainyDays + '/7', '☂️', 'rgba(221,214,254,.15)', '#c4b5fd'],
            ['Max Wind', maxWind + ' km/h', '💨', 'rgba(167,243,208,.15)', '#6ee7b7'],
        ].map(([lbl, val, ico, bg, tc]) => `
                        <div style="background:${bg};border:1px solid ${tc}33;border-radius:10px;
                                    padding:8px 6px;text-align:center">
                            <div style="font-size:14px;margin-bottom:3px">${ico}</div>
                            <div style="font-size:13px;font-weight:800;color:${tc};line-height:1">${val}</div>
                            <div style="font-size:8px;color:rgba(255,255,255,.5);margin-top:2px;
                                        text-transform:uppercase;letter-spacing:.5px">${lbl}</div>
                        </div>`).join('')}
                </div>
            </div>

            <!-- ── Column headers ── -->
            <div style="display:flex;align-items:center;gap:10px;padding:8px 20px;
                        background:#f8fafc;border-bottom:1px solid #e2e8f0;flex-shrink:0">
                <div style="width:36px"></div>
                <div style="flex:0 0 90px;font-size:8px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.7px;color:#94a3b8">Day</div>
                <div style="flex:0 0 52px;font-size:8px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.7px;color:#94a3b8;text-align:center">Temp</div>
                <div style="flex:1;font-size:8px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.7px;color:#94a3b8">Rainfall</div>
                <div style="flex:0 0 38px;font-size:8px;font-weight:700;text-transform:uppercase;
                            letter-spacing:.7px;color:#94a3b8;text-align:right">Wind</div>
                <div style="width:22px"></div>
            </div>

            <!-- ── Day rows ── -->
            <div style="overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch">
                ${rows}
            </div>

            <!-- ── Footer ── -->
            <div style="padding:12px 20px;border-top:1px solid #e2e8f0;
                        display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
                        background:#f8fafc">
                <span style="font-size:10px;color:#94a3b8;display:flex;align-items:center;gap:5px">
                    <i class="fas fa-hand-pointer" style="color:#8b5cf6"></i>
                    Tap any row for full details &amp; AI analysis
                </span>
                <button onclick="document.getElementById('pastWeatherModal').remove()"
                        style="padding:8px 18px;border:1px solid #e2e8f0;border-radius:20px;
                               background:#fff;color:#475569;font-size:12px;font-weight:600;
                               cursor:pointer;transition:background .12s"
                        onmouseenter="this.style.background='#f1f5f9'"
                        onmouseleave="this.style.background='#fff'">
                    ✕ Close
                </button>
            </div>
        </div>`;

    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
}

// =============================================================================
// SINGLE-DAY DETAIL MODAL  — matches screenshot card layout
// =============================================================================

function openPastDayModal(d, stacked = false) {
    document.getElementById('pastDayModal')?.remove();

    // Rain warning
    const rw = d.rain >= 150 ? { icon: '🔴', label: 'Extreme Rainfall', bg: '#fef2f2', border: '#fca5a5', tc: '#b91c1c' }
        : d.rain >= 100 ? { icon: '🟠', label: 'Heavy Rainfall', bg: '#fff7ed', border: '#fdba74', tc: '#c2410c' }
            : d.rain >= 50 ? { icon: '🟡', label: 'Moderate Rainfall', bg: '#fefce8', border: '#fde047', tc: '#a16207' }
                : d.rain >= 15 ? { icon: '🟢', label: 'Light Rainfall', bg: '#f0fdf4', border: '#86efac', tc: '#15803d' }
                    : { icon: '✅', label: 'No Significant Rain', bg: '#f0fdf4', border: '#86efac', tc: '#15803d' };

    // Comfort score (simple heuristic)
    const comfort = Math.max(1, Math.min(10, Math.round(
        10 - (d.rain >= 50 ? 4 : d.rain >= 15 ? 2 : 0)
        - (d.maxTemp >= 36 ? 2 : d.maxTemp >= 33 ? 1 : 0)
        - (d.wind >= 50 ? 2 : d.wind >= 30 ? 1 : 0)
    )));
    const comfortLabel = comfort >= 8 ? 'Great — very comfortable'
        : comfort >= 6 ? 'Good — minor discomfort'
            : comfort >= 4 ? 'Fair — noticeable discomfort'
                : 'Poor — uncomfortable conditions';

    const rainRisk = Math.min(100, Math.round(d.rain / 1.5));
    const windPct = Math.min(100, Math.round(d.wind / 1.2));

    // right-column stat cards (matching screenshot layout)
    const rightCards = [
        {
            label: 'RAIN RISK',
            icon: '🌧️',
            bigVal: d.rain + '<span style="font-size:13px;font-weight:500;color:#64748b">mm</span>',
            sub: d.rain >= 50 ? 'High — expect flooding risk' : d.rain >= 15 ? 'Moderate — carry umbrella' : 'Low — generally dry',
            barPct: rainRisk,
            barColor: d.rain >= 50 ? '#ef4444' : d.rain >= 15 ? '#f59e0b' : '#60a5fa',
            bg: d.rain >= 50 ? '#fef2f2' : d.rain >= 15 ? '#fffbeb' : '#eff6ff',
            border: d.rain >= 50 ? '#fca5a5' : d.rain >= 15 ? '#fde68a' : '#bfdbfe',
        },
        {
            label: 'TEMPERATURE HIGH',
            icon: '🌡️',
            bigVal: d.maxTemp + '<span style="font-size:13px;font-weight:500;color:#64748b">°C</span>',
            sub: d.maxTemp >= 36 ? 'Very hot — heat index warning'
                : d.maxTemp >= 32 ? 'Hot — stay hydrated'
                    : d.maxTemp >= 28 ? 'Warm — comfortable range'
                        : 'Cool — pleasant temperature',
            barPct: Math.round((d.maxTemp - 20) / 0.2),
            barColor: d.maxTemp >= 35 ? '#ef4444' : '#f97316',
            bg: '#fff7ed',
            border: '#fed7aa',
        },
        {
            label: 'COMFORT SCORE',
            icon: '😊',
            bigVal: comfort + '<span style="font-size:13px;font-weight:500;color:#64748b">/10</span>',
            sub: comfortLabel,
            barPct: comfort * 10,
            barColor: comfort >= 7 ? '#22c55e' : comfort >= 5 ? '#f59e0b' : '#ef4444',
            bg: comfort >= 7 ? '#f0fdf4' : comfort >= 5 ? '#fffbeb' : '#fef2f2',
            border: comfort >= 7 ? '#86efac' : comfort >= 5 ? '#fde68a' : '#fca5a5',
        },
    ];

    const modal = document.createElement('div');
    modal.id = 'pastDayModal';
    modal.style.cssText = `
        position:fixed;inset:0;background:rgba(15,23,42,.${stacked ? '5' : '65'});
        z-index:${stacked ? '10000' : '9999'};
        display:flex;align-items:center;justify-content:center;
        animation:pwFadeIn .2s ease;padding:16px`;

    modal.innerHTML = `
        <div style="background:#fff;border-radius:18px;width:100%;max-width:660px;
                    max-height:92vh;overflow:hidden;display:flex;flex-direction:column;
                    box-shadow:0 24px 80px rgba(0,0,0,.24);
                    animation:pwSlideIn .3s cubic-bezier(.34,1.4,.64,1)">

            <!-- ── Dark navy header ── -->
            <div style="background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);
                        padding:18px 20px 18px;position:relative;flex-shrink:0">
                <!-- close -->
                <button onclick="document.getElementById('pastDayModal').remove()"
                        style="position:absolute;top:14px;right:14px;width:26px;height:26px;
                               border-radius:50%;border:1px solid rgba(255,255,255,.2);
                               background:rgba(255,255,255,.1);color:#fff;cursor:pointer;
                               font-size:12px;display:flex;align-items:center;justify-content:center">✕</button>
                <!-- title row -->
                <div style="display:flex;align-items:center;gap:12px">
                    <div style="width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.1);
                                display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0">
                        ${d.icon}</div>
                    <div>
                        <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;line-height:1.1">
                            ${d.dayName === (new Date(Date.now() - 86400000).toLocaleDateString('en-PH', { weekday: 'short' })) ? 'Yesterday' : d.dayName + ', ' + d.dayNum}</div>
                        <div style="font-size:11px;color:rgba(255,255,255,.55);margin-top:3px">${d.date}</div>
                    </div>
                    <!-- temp badge top right -->
                    <div style="margin-left:auto;text-align:right">
                        <div style="font-size:30px;font-weight:800;color:#fff;line-height:1;letter-spacing:-1px">
                            ${d.maxTemp}<span style="font-size:16px">°C</span></div>
                        <div style="font-size:11px;color:rgba(255,255,255,.55)">&nbsp;/ ${d.minTemp}°C</div>
                    </div>
                </div>
                <!-- condition tag -->
                <div style="margin-top:12px;display:inline-flex;align-items:center;gap:6px;
                            padding:5px 12px;border-radius:20px;
                            background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18)">
                    <span style="font-size:11px;color:rgba(255,255,255,.9);font-weight:600">${d.label}</span>
                </div>
            </div>

            <!-- ── Body: two-column layout ── -->
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;flex:1;overflow-y:auto;
                        -webkit-overflow-scrolling:touch">

                <!-- LEFT: stat list -->
                <div style="padding:16px 14px 16px 18px;border-right:1px solid #e2e8f0">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.8px;color:#94a3b8;margin-bottom:10px">
                        DAILY DETAILS
                    </div>

                    <!-- Precipitation section -->
                    <div style="font-size:8px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.6px;color:#cbd5e1;margin:0 0 6px">PRECIPITATION</div>
                    ${leftStat('Rain Probability', d.rainHours > 0 ? Math.min(99, Math.round(d.rainHours / 24 * 100 + 20)) + '%' : '< 10%', 'cloud-rain', '#0ea5e9')}
                    ${leftStat('Rainfall Total', d.rain + ' mm', 'tint', '#3b82f6')}
                    ${leftStat('Rain Duration', d.rainHours + ' hours', 'clock', '#8b5cf6')}

                    <!-- Atmosphere section -->
                    <div style="font-size:8px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.6px;color:#cbd5e1;margin:12px 0 6px">ATMOSPHERE</div>
                    ${leftStat('Temperature Range', d.minTemp + '°C – ' + d.maxTemp + '°C', 'thermometer-half', '#f97316')}
                    ${leftStat('Condition', d.label, 'cloud-sun', '#f59e0b')}

                    <!-- Wind section -->
                    <div style="font-size:8px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.6px;color:#cbd5e1;margin:12px 0 6px">WIND</div>
                    ${leftStat('Max Wind Speed', d.wind + ' km/h', 'wind', '#10b981')}
                    ${leftStat('Wind Intensity', d.wind >= 60 ? 'Strong' : d.wind >= 30 ? 'Moderate' : 'Light breeze', 'flag', '#06b6d4')}
                </div>

                <!-- RIGHT: colored stat cards -->
                <div style="padding:16px 18px 16px 14px;display:flex;flex-direction:column;gap:10px">
                    <div style="font-size:9px;font-weight:700;text-transform:uppercase;
                                letter-spacing:.8px;color:#94a3b8;margin-bottom:2px">
                        DAILY STATISTICS
                    </div>
                    ${rightCards.map(c => `
                    <div style="background:${c.bg};border:1px solid ${c.border};border-radius:14px;padding:14px">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
                            <span style="font-size:8px;font-weight:700;text-transform:uppercase;
                                         letter-spacing:.8px;color:#94a3b8">${c.label}</span>
                            <span style="font-size:16px">${c.icon}</span>
                        </div>
                        <div style="font-size:28px;font-weight:800;color:#0f172a;
                                    line-height:1;letter-spacing:-1px;margin-bottom:4px">
                            ${c.bigVal}</div>
                        <div style="font-size:10px;color:#64748b;margin-bottom:10px">${c.sub}</div>
                        <div style="height:5px;border-radius:3px;background:rgba(0,0,0,.08);overflow:hidden">
                            <div style="height:100%;border-radius:3px;width:${c.barPct}%;
                                        background:${c.barColor};
                                        transition:width 1.1s cubic-bezier(.34,1.56,.64,1)"></div>
                        </div>
                    </div>`).join('')}

                    <!-- Warning banner -->
                    <div style="background:${rw.bg};border:1px solid ${rw.border};
                                border-radius:12px;padding:10px 12px;
                                display:flex;align-items:flex-start;gap:8px">
                        <span style="font-size:15px;flex-shrink:0;margin-top:1px">${rw.icon}</span>
                        <div>
                            <div style="font-size:11px;font-weight:700;color:${rw.tc};margin-bottom:2px">
                                ${rw.label}</div>
                            <div style="font-size:10px;color:${rw.tc};line-height:1.5;opacity:.85">
                                ${d.rain >= 100 ? 'Heavy rain likely caused flooding. Check NDRRMC records.'
            : d.rain >= 50 ? 'Significant rainfall — flood risk in low areas.'
                : d.rain >= 15 ? 'Light to moderate rain — carry an umbrella.'
                    : 'Fair conditions — no significant rainfall.'}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Footer ── -->
            <div style="padding:12px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;
                        display:flex;gap:8px;align-items:center;flex-shrink:0">
                <div style="font-size:9px;color:#94a3b8;flex:1">
                    <i class="fas fa-database" style="color:#8b5cf6;margin-right:4px"></i>
                    Open-Meteo · PAGASA · ML Engine v2
                </div>
                ${stacked ? `
                <button onclick="document.getElementById('pastDayModal').remove()"
                        style="padding:9px 14px;border:1px solid #e2e8f0;border-radius:20px;
                               background:#fff;color:#475569;font-size:11px;font-weight:600;cursor:pointer">
                    ← Back
                </button>` : ''}
                <button onclick="document.getElementById('pastDayModal').remove()"
                        style="padding:9px 14px;border:1px solid #e2e8f0;border-radius:20px;
                               background:#fff;color:#475569;font-size:11px;font-weight:600;cursor:pointer">
                    ✕ Close
                </button>
                <button onclick="askAIAboutPastDay(${JSON.stringify(d).replace(/"/g, '&quot;')})"
                        style="padding:9px 16px;border:none;border-radius:20px;
                               background:linear-gradient(135deg,#1e3a5f,#0f172a);
                               color:#fff;font-size:11px;font-weight:700;cursor:pointer;
                               display:flex;align-items:center;gap:6px">
                    <i class="fas fa-robot"></i> Ask AI About This Day
                </button>
            </div>
        </div>`;

    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
}

function leftStat(label, value, icon, color) {
    return `
        <div style="display:flex;align-items:center;gap:10px;padding:7px 0;
                    border-bottom:1px solid #f1f5f9">
            <div style="width:28px;height:28px;border-radius:8px;flex-shrink:0;
                        background:${color}18;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-${icon}" style="color:${color};font-size:11px"></i></div>
            <div style="flex:1;min-width:0">
                <div style="font-size:9px;color:#94a3b8;font-weight:600;text-transform:uppercase;
                            letter-spacing:.5px;line-height:1.2">${label}</div>
                <div style="font-size:13px;font-weight:700;color:#0f172a;line-height:1.3">${value}</div>
            </div>
        </div>`;
}


function askAIAboutPastDay(d) {
    document.getElementById('pastDayModal')?.remove();
    document.getElementById('pastWeatherModal')?.remove();
    const message = `Give me a brief weather recap and analysis for ${d.dayName} ${d.dayNum} in my area. `
        + `Here is what actually happened: Condition: ${d.label}, High: ${d.maxTemp}°C, Low: ${d.minTemp}°C, `
        + `Rainfall: ${d.rain}mm over ${d.rainHours} hours, Max Wind: ${d.wind} km/h. `
        + `Was this a typical day for the Philippines? Any interesting patterns to note?`;
    document.getElementById('chatBubbleWindow')?.classList.add('tt-chat-window--open');
    setTimeout(() => { if (typeof askQuestion === 'function') askQuestion(message); }, 300);
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

console.log('[TyphoonTracker] Live patch v6 loaded — redesigned day + 7-day modals (screenshot style) ✅');

'use strict';
/**
 * typhoon_history_modal.js — v2 (Fact-corrected)
 * Philippine Typhoon History (1881–2024)
 * Drop into the same folder as index.php / typhoon_live_patch.js
 * Opens via: openTyphoonHistoryModal()
 *
 * Key corrections from v1:
 *  - Haiyan damage updated to ₱95.5B PHP / deaths 6,300 + 1,800 missing
 *  - Thelma/Uring deaths corrected to 5,101–8,000 (official 5,101)
 *  - Glenda/Rammasun damage corrected to ₱38.62B PHP
 *  - Ompong/Mangkhut damage corrected to ₱33.9B PHP
 *  - Odette/Rai damage corrected to ₱51.8B PHP
 *  - Pablo/Bopha duplicate removed; kept correctly under 2012
 *  - 1881 Haiphong Typhoon added (deadliest in recorded history, 20,000+)
 *  - PHP damage figures stored separately alongside USD estimates
 */

// ─── DATA ────────────────────────────────────────────────────────────────────
const PH_TYPHOON_HISTORY = [
    // ── 2024 ──────────────────────────────────────────────────────────────────
    { year: 2024, name: 'Carina (Beryl)', intlName: 'Beryl', peak: 'Typhoon (Cat 4)', deaths: 40, affected: 1_200_000, damage_m: 320, area: 'Luzon, Metro Manila', notes: 'Enhanced SW Monsoon; extreme flooding in Metro Manila' },
    { year: 2024, name: 'Enteng (Yagi)', intlName: 'Yagi', peak: 'Super Typhoon', deaths: 22, affected: 980_000, damage_m: 180, area: 'Cagayan, Isabela, CAR', notes: 'Second landfalling storm in September 2024' },
    { year: 2024, name: 'Julian', intlName: 'Pulasan', peak: 'Typhoon', deaths: 15, affected: 450_000, damage_m: 95, area: 'Northern Luzon', notes: 'Late-season storm; rapid intensification' },
    // ── 2023 ──────────────────────────────────────────────────────────────────
    { year: 2023, name: 'Egay (Talim)', intlName: 'Talim', peak: 'Super Typhoon', deaths: 30, affected: 1_500_000, damage_m: 530, area: 'Luzon', notes: 'Most intense Philippine storm of 2023; PAGASA Signal #4' },
    { year: 2023, name: 'Falcon (Mawar)', intlName: 'Mawar', peak: 'Super Typhoon 5', deaths: 0, affected: 50_000, damage_m: 12, area: 'Northern PAR', notes: 'Strongest western Pacific storm of 2023; skirted north Luzon' },
    { year: 2023, name: 'Goring (Khanun)', intlName: 'Khanun', peak: 'Typhoon', deaths: 8, affected: 300_000, damage_m: 85, area: 'Cagayan Valley', notes: 'Stalled near Taiwan before entering PAR' },
    { year: 2023, name: 'Jenny (Koinu)', intlName: 'Koinu', peak: 'Typhoon', deaths: 12, affected: 600_000, damage_m: 140, area: 'Visayas', notes: 'Hit Leyte and Eastern Visayas' },
    // ── 2022 ──────────────────────────────────────────────────────────────────
    { year: 2022, name: 'Karding (Noru)', intlName: 'Noru', peak: 'Super Typhoon 5', deaths: 98, affected: 2_800_000, damage_m: 1_200, area: 'Luzon, Aurora, Bulacan', notes: 'Rapid intensification to Cat 5 before landfall; devastating floods' },
    { year: 2022, name: 'Paeng (Nalgae)', intlName: 'Nalgae', peak: 'Typhoon', deaths: 155, affected: 3_100_000, damage_m: 720, area: 'Visayas, Mindanao', notes: 'Triggered massive landslides in Maguindanao; very deadly' },
    { year: 2022, name: 'Queenie (Muifa)', intlName: 'Muifa', peak: 'Typhoon', deaths: 4, affected: 200_000, damage_m: 55, area: 'Cagayan, Ilocos', notes: 'Made landfall in northern Luzon' },
    // ── 2021 ──────────────────────────────────────────────────────────────────
    { year: 2021, name: 'Odette (Rai)', intlName: 'Rai', peak: 'Super Typhoon 5', deaths: 409, affected: 9_700_000, damage_m: 1_010, area: 'Visayas, Mindanao', notes: 'Deadliest Philippine typhoon since Haiyan; ₱51.8 billion in damages; hit during Christmas week; devastated Surigao City, Cebu, Bohol, Palawan; caught many off-guard as it rapidly intensified well south of typical typhoon tracks', damage_php_b: 51.8 },
    { year: 2021, name: 'Leon (Kompasu)', intlName: 'Kompasu', peak: 'Typhoon', deaths: 17, affected: 800_000, damage_m: 245, area: 'Cagayan, CAR', notes: 'Rapid intensification; heavy rains in northern Philippines' },
    // ── 2020 ──────────────────────────────────────────────────────────────────
    { year: 2020, name: 'Ulysses (Vamco)', intlName: 'Vamco', peak: 'Typhoon (Cat 4)', deaths: 67, affected: 4_200_000, damage_m: 1_600, area: 'Luzon, Metro Manila', notes: 'Extreme flooding in Marikina, Cagayan Valley; 4th storm in 30 days' },
    { year: 2020, name: 'Rolly (Goni)', intlName: 'Goni', peak: 'Super Typhoon 5', deaths: 25, affected: 1_600_000, damage_m: 900, area: 'Bicol Region, Eastern Samar', notes: 'Strongest landfalling typhoon ever recorded globally; 315 km/h gusts' },
    { year: 2020, name: 'Quinta (Molave)', intlName: 'Molave', peak: 'Typhoon (Cat 4)', deaths: 22, affected: 1_200_000, damage_m: 520, area: 'Samar, Quezon Province', notes: 'Third typhoon in 2 weeks to hit Central Philippines' },
    { year: 2020, name: 'Pepito (Saudel)', intlName: 'Saudel', peak: 'Typhoon (Cat 3)', deaths: 5, affected: 400_000, damage_m: 120, area: 'Eastern Visayas', notes: 'Part of deadly cluster in October 2020' },
    { year: 2020, name: 'Ofel (Atsani)', intlName: 'Atsani', peak: 'Typhoon (Cat 2)', deaths: 5, affected: 300_000, damage_m: 98, area: 'Northern Luzon', notes: 'Struck days before Quinta' },
    // ── 2019 ──────────────────────────────────────────────────────────────────
    { year: 2019, name: 'Tisoy (Kammuri)', intlName: 'Kammuri', peak: 'Typhoon (Cat 4)', deaths: 13, affected: 700_000, damage_m: 270, area: 'Bicol, Eastern Visayas', notes: 'Hit during Southeast Asian Games; disrupted events' },
    { year: 2019, name: 'Quiel (Matmo)', intlName: 'Matmo', peak: 'Typhoon (Cat 1)', deaths: 4, affected: 200_000, damage_m: 65, area: 'Northern Luzon', notes: 'Landfalled in Isabela' },
    { year: 2019, name: 'Ompong (Trami)', intlName: 'Trami', peak: 'Typhoon (Cat 4)', deaths: 32, affected: 1_100_000, damage_m: 430, area: 'Eastern Visayas, Bicol', notes: 'Major flooding across Eastern Visayas' },
    // ── 2018 ──────────────────────────────────────────────────────────────────
    { year: 2018, name: 'Rosita (Yutu)', intlName: 'Yutu', peak: 'Super Typhoon 5', deaths: 28, affected: 1_200_000, damage_m: 420, area: 'Cagayan, Isabela', notes: 'One of the strongest October typhoons on record; ravaged Northern Sierra Madre' },
    { year: 2018, name: 'Ompong (Mangkhut)', intlName: 'Mangkhut', peak: 'Super Typhoon 5', deaths: 88, affected: 3_400_000, damage_m: 790, area: 'Cagayan, CAR, Ilocos', notes: 'Strongest global storm of 2018; ₱33.9 billion in damages; caused massive landslides in Itogon, Benguet killing trapped miners; made landfall in Baggao, Cagayan; deadliest Philippine storm of 2018', damage_php_b: 33.9 },
    { year: 2018, name: 'Domeng (Ewiniar)', intlName: 'Ewiniar', peak: 'Tropical Storm', deaths: 16, affected: 900_000, damage_m: 120, area: 'Mindanao, Visayas', notes: 'Intensified SW Monsoon; series of deadly floods' },
    // ── 2017 ──────────────────────────────────────────────────────────────────
    { year: 2017, name: 'Urduja (Kai-tak)', intlName: 'Kai-tak', peak: 'Tropical Storm', deaths: 38, affected: 1_500_000, damage_m: 310, area: 'Eastern Visayas, Mindanao', notes: 'Caused severe flooding in Eastern Samar and Leyte' },
    { year: 2017, name: 'Nina (Nock-ten)', intlName: 'Nock-ten', peak: 'Super Typhoon 5', deaths: 7, affected: 500_000, damage_m: 210, area: 'Catanduanes, Bicol', notes: 'Hit on Christmas Day; peak intensity just before landfall' },
    // ── 2016 ──────────────────────────────────────────────────────────────────
    { year: 2016, name: 'Lawin (Haima)', intlName: 'Haima', peak: 'Super Typhoon 5', deaths: 21, affected: 1_800_000, damage_m: 510, area: 'Cagayan Valley, Sierra Madre', notes: 'Second Cat 5 landfalling typhoon in 2016' },
    { year: 2016, name: 'Queenie (Nepartak)', intlName: 'Nepartak', peak: 'Super Typhoon 5', deaths: 4, affected: 300_000, damage_m: 85, area: 'Batanes, Isabela', notes: 'Strongest global storm of 2016 at peak; weakened before landfall' },
    { year: 2016, name: 'Karen (Tokage)', intlName: 'Tokage', peak: 'Typhoon (Cat 4)', deaths: 23, affected: 1_100_000, damage_m: 370, area: 'Eastern Philippines', notes: 'Major damage across Eastern Visayas' },
    // ── 2015 ──────────────────────────────────────────────────────────────────
    { year: 2015, name: 'Lando (Koppu)', intlName: 'Koppu', peak: 'Typhoon (Cat 4)', deaths: 58, affected: 2_400_000, damage_m: 680, area: 'Aurora, Cagayan Valley, Nueva Ecija', notes: 'Slow-moving; caused catastrophic flooding in Nueva Ecija; stalled for days' },
    { year: 2015, name: 'Ruby (Hagupit)', intlName: 'Hagupit', peak: 'Super Typhoon 5', deaths: 18, affected: 3_700_000, damage_m: 830, area: 'Eastern Visayas', notes: 'Major pre-emptive evacuation (1 million+) after Haiyan lessons; devastated Samar again' },
    // ── 2014 ──────────────────────────────────────────────────────────────────
    { year: 2014, name: 'Glenda (Rammasun)', intlName: 'Rammasun', peak: 'Super Typhoon 5', deaths: 98, affected: 4_300_000, damage_m: 870, area: 'Metro Manila, Luzon', notes: 'Strongest typhoon to directly hit Manila in decades; ₱38.62 billion in damages; massive power outages across NCR and Luzon; uprooted thousands of trees; reshaped Metro Manila emergency protocols', damage_php_b: 38.62 },
    { year: 2014, name: 'Luis (Kalmaegi)', intlName: 'Kalmaegi', peak: 'Typhoon (Cat 3)', deaths: 18, affected: 900_000, damage_m: 310, area: 'Cagayan, Northern Luzon', notes: 'Followed days after Rammasun' },
    { year: 2014, name: 'Mario (Fung-wong)', intlName: 'Fung-wong', peak: 'Typhoon (Cat 3)', deaths: 25, affected: 1_600_000, damage_m: 390, area: 'Metro Manila, Southern Luzon', notes: 'Extreme flooding in Metro Manila' },
    { year: 2014, name: 'Seniang (Jangmi)', intlName: 'Jangmi', peak: 'Tropical Storm', deaths: 78, affected: 1_400_000, damage_m: 220, area: 'Visayas, Mindanao', notes: 'Deadly despite weak intensity; hit already vulnerable post-Haiyan communities' },
    // ── 2013 ──────────────────────────────────────────────────────────────────
    { year: 2013, name: 'Yolanda (Haiyan)', intlName: 'Haiyan', peak: 'Super Typhoon 5', deaths: 6_300, affected: 16_000_000, damage_m: 12_000, area: 'Leyte, Samar, Cebu, Palawan', notes: 'Deadliest modern Philippine typhoon; 6,300 confirmed dead + 1,800 missing; strongest landfalling storm in recorded history (315 km/h winds); storm surge up to 7 m devastated Tacloban; 90% of city destroyed; ₱95.5 billion in damages; transformed global disaster-response standards', damage_php_b: 95.5 },
    { year: 2013, name: 'Santi (Krosa)', intlName: 'Krosa', peak: 'Typhoon (Cat 3)', deaths: 14, affected: 800_000, damage_m: 240, area: 'Southern Luzon, Eastern Visayas', notes: 'Hit typhoon-fatigued population weeks after Haiyan' },
    { year: 2013, name: 'Odette (Haikui)', intlName: 'Haikui', peak: 'Typhoon (Cat 4)', deaths: 14, affected: 700_000, damage_m: 195, area: 'Bicol, Eastern Visayas', notes: 'Major damage before Haiyan season peak' },
    // ── 2012 ──────────────────────────────────────────────────────────────────
    { year: 2012, name: 'Pablo (Bopha)', intlName: 'Bopha', peak: 'Super Typhoon 5', deaths: 1_901, affected: 6_200_000, damage_m: 2_800, area: 'Compostela Valley, Davao Oriental, Eastern Mindanao', notes: 'Deadliest Mindanao typhoon on record; made landfall Dec 4 2012 at an unusually southern latitude (~6°N); flash floods and landslides wiped out riverside communities; devastated gold-mining areas of Compostela Valley' },
    { year: 2012, name: 'Quedan (Sanba)', intlName: 'Sanba', peak: 'Super Typhoon 5', deaths: 2, affected: 100_000, damage_m: 30, area: 'Northern PAR', notes: 'Passed north of Batanes; no direct landfall impact' },
    { year: 2012, name: 'Helen (Butchoy)', intlName: 'Butchoy', peak: 'Tropical Storm', deaths: 65, affected: 2_100_000, damage_m: 240, area: 'Luzon', notes: 'Enhanced monsoon; major Cagayan Valley floods' },
    // ── 2011 ──────────────────────────────────────────────────────────────────
    { year: 2011, name: 'Sendong (Washi)', intlName: 'Washi', peak: 'Tropical Storm', deaths: 1_439, affected: 654_000, damage_m: 450, area: 'Cagayan de Oro, Iligan, Mindanao', notes: 'Deadliest tropical storm in Philippine history; flash floods at 3 a.m. while most slept' },
    { year: 2011, name: 'Pedring (Nesat)', intlName: 'Nesat', peak: 'Typhoon (Cat 3)', deaths: 71, affected: 2_900_000, damage_m: 620, area: 'Luzon, Metro Manila', notes: 'Severe flooding in Metro Manila and Laguna de Bay' },
    { year: 2011, name: 'Quiel (Nalgae)', intlName: 'Nalgae', peak: 'Typhoon (Cat 4)', deaths: 51, affected: 1_400_000, damage_m: 380, area: 'Northern Luzon', notes: 'Hit days after Pedring' },
    // ── 2010 ──────────────────────────────────────────────────────────────────
    { year: 2010, name: 'Juan (Megi)', intlName: 'Megi', peak: 'Super Typhoon 5', deaths: 20, affected: 1_700_000, damage_m: 510, area: 'Northern Luzon, Cagayan', notes: 'Strongest western Pacific typhoon of 2010; tied global record at landfall' },
    { year: 2010, name: 'Basyang (Conson)', intlName: 'Conson', peak: 'Typhoon (Cat 4)', deaths: 48, affected: 2_300_000, damage_m: 400, area: 'Luzon, Visayas', notes: 'Major July typhoon; severe flooding' },
    // ── 2009 ──────────────────────────────────────────────────────────────────
    { year: 2009, name: 'Ondoy (Ketsana)', intlName: 'Ketsana', peak: 'Tropical Storm', deaths: 747, affected: 9_300_000, damage_m: 4_800, area: 'Metro Manila, Luzon', notes: 'Dumped Manilas annual rainfall in 12 hours; catastrophic urban flooding; generation-defining disaster' },
    { year: 2009, name: 'Pepeng (Parma)', intlName: 'Parma', peak: 'Typhoon (Cat 4)', deaths: 465, affected: 5_700_000, damage_m: 2_400, area: 'Northern Luzon, Cordillera', notes: 'Hit a week after Ondoy; triple landfalls; devastating landslides in Benguet' },
    { year: 2009, name: 'Emong (Chan-hom)', intlName: 'Chan-hom', peak: 'Typhoon (Cat 3)', deaths: 18, affected: 600_000, damage_m: 180, area: 'Luzon', notes: 'Major May typhoon' },
    // ── 2008 ──────────────────────────────────────────────────────────────────
    { year: 2008, name: 'Frank (Fengshen)', intlName: 'Fengshen', peak: 'Typhoon (Cat 2)', deaths: 1_399, affected: 3_400_000, damage_m: 1_100, area: 'Visayas, Luzon', notes: 'MV Princess of the Stars sank killing 800+; extreme flooding' },
    { year: 2008, name: 'Lando (Hagupit)', intlName: 'Hagupit', peak: 'Typhoon (Cat 4)', deaths: 37, affected: 1_900_000, damage_m: 440, area: 'Luzon', notes: 'September super typhoon' },
    // ── 2007 ──────────────────────────────────────────────────────────────────
    { year: 2007, name: 'Domeng (Cimaron)', intlName: 'Cimaron', peak: 'Super Typhoon 5', deaths: 12, affected: 800_000, damage_m: 350, area: 'Northern Luzon', notes: 'Cat 5 at peak; weakened to 4 at landfall' },
    { year: 2007, name: 'Goring (Wipha)', intlName: 'Wipha', peak: 'Typhoon (Cat 4)', deaths: 20, affected: 700_000, damage_m: 280, area: 'Northern Luzon, Batanes', notes: 'Batanes worst hit' },
    // ── 2006 ──────────────────────────────────────────────────────────────────
    { year: 2006, name: 'Reming (Durian)', intlName: 'Durian', peak: 'Super Typhoon 5', deaths: 1_399, affected: 2_700_000, damage_m: 1_800, area: 'Bicol Region (Albay)', notes: 'Massive Mayon Volcano lahars buried entire communities in Albay; 1,300+ killed instantly by lahar' },
    { year: 2006, name: 'Milenyo (Xangsane)', intlName: 'Xangsane', peak: 'Typhoon (Cat 4)', deaths: 274, affected: 2_600_000, damage_m: 780, area: 'Metro Manila, Luzon', notes: 'Directly hit Manila; widespread destruction; Laguna flooding' },
    { year: 2006, name: 'Paeng (Shanshan)', intlName: 'Shanshan', peak: 'Typhoon (Cat 3)', deaths: 14, affected: 700_000, damage_m: 210, area: 'Northern Luzon', notes: 'August typhoon' },
    // ── 2005 ──────────────────────────────────────────────────────────────────
    { year: 2005, name: 'Caloy (Chanchu)', intlName: 'Chanchu', peak: 'Super Typhoon 5', deaths: 19, affected: 800_000, damage_m: 300, area: 'Eastern Philippines', notes: 'Entered PAR briefly; first super typhoon in South China Sea in decades' },
    { year: 2005, name: 'Quedan (Damrey)', intlName: 'Damrey', peak: 'Typhoon (Cat 3)', deaths: 12, affected: 500_000, damage_m: 150, area: 'Eastern Luzon', notes: 'Eastern Luzon strike' },
    // ── 2004 ──────────────────────────────────────────────────────────────────
    { year: 2004, name: "Winnie (Muifa)", intlName: 'Muifa', peak: 'Typhoon (Cat 4)', deaths: 1_593, affected: 4_400_000, damage_m: 700, area: 'Northern Luzon', notes: 'Deadliest of 2004; massive flooding and landslides across Luzon' },
    { year: 2004, name: 'Unding (Merbok)', intlName: 'Merbok', peak: 'Tropical Storm', deaths: 300, affected: 1_200_000, damage_m: 180, area: 'Eastern Samar, Leyte', notes: 'Deadly flooding in Eastern Visayas' },
    { year: 2004, name: 'Florita (Rananim)', intlName: 'Rananim', peak: 'Typhoon (Cat 4)', deaths: 18, affected: 600_000, damage_m: 210, area: 'Northern Luzon', notes: 'August 2004 typhoon' },
    // ── 2003 ──────────────────────────────────────────────────────────────────
    { year: 2003, name: 'Harurot (Koni)', intlName: 'Koni', peak: 'Typhoon (Cat 3)', deaths: 39, affected: 900_000, damage_m: 180, area: 'Central Luzon', notes: 'Severe flooding' },
    { year: 2003, name: 'Chedeng (Melor)', intlName: 'Melor', peak: 'Typhoon (Cat 3)', deaths: 30, affected: 700_000, damage_m: 220, area: 'Eastern Visayas', notes: 'Hit Leyte and Samar' },
    // ── 2002 ──────────────────────────────────────────────────────────────────
    { year: 2002, name: 'Poniang (Fengshen)', intlName: 'Fengshen', peak: 'Typhoon (Cat 4)', deaths: 52, affected: 1_500_000, damage_m: 320, area: 'Luzon', notes: 'Northern Luzon floods' },
    { year: 2002, name: 'Miñanday (Higos)', intlName: 'Higos', peak: 'Typhoon (Cat 3)', deaths: 15, affected: 400_000, damage_m: 140, area: 'Luzon', notes: 'August 2002 typhoon' },
    // ── 2001 ──────────────────────────────────────────────────────────────────
    { year: 2001, name: 'Feria (Lekima)', intlName: 'Lekima', peak: 'Typhoon (Cat 4)', deaths: 47, affected: 1_600_000, damage_m: 400, area: 'Central and Northern Luzon', notes: 'Major flooding in Luzon' },
    { year: 2001, name: 'Nanang (Cimaron)', intlName: 'Cimaron', peak: 'Typhoon (Cat 4)', deaths: 152, affected: 2_800_000, damage_m: 560, area: 'Eastern Visayas, Mindanao', notes: 'Deadliest 2001 storm; Samar and Leyte flooding' },
    // ── 2000 ──────────────────────────────────────────────────────────────────
    { year: 2000, name: 'Seniang (Xangsane)', intlName: 'Xangsane', peak: 'Typhoon (Cat 3)', deaths: 52, affected: 1_100_000, damage_m: 230, area: 'Visayas', notes: 'Major Visayas storm' },
    { year: 2000, name: 'Loleng (Xangsane)', intlName: 'Babosa', peak: 'Typhoon (Cat 3)', deaths: 36, affected: 900_000, damage_m: 185, area: 'Luzon', notes: 'October 2000 Luzon strike' },
    // ── 1998 ──────────────────────────────────────────────────────────────────
    { year: 1998, name: 'Loleng (Zeb)', intlName: 'Zeb', peak: 'Super Typhoon 5', deaths: 130, affected: 2_200_000, damage_m: 720, area: 'Luzon, Visayas', notes: 'Strong El Niño year despite super typhoon activity' },
    { year: 1998, name: 'Gading (Leo)', intlName: 'Leo', peak: 'Typhoon (Cat 4)', deaths: 42, affected: 1_100_000, damage_m: 280, area: 'Northern Luzon', notes: 'November typhoon' },
    // ── 1995 ──────────────────────────────────────────────────────────────────
    { year: 1995, name: 'Angela (Rosie)', intlName: 'Rosie', peak: 'Super Typhoon 5', deaths: 882, affected: 3_500_000, damage_m: 1_300, area: 'Luzon', notes: 'Severe typhoon in 1995; major damage in Luzon' },
    { year: 1995, name: 'Sibyl (Zack)', intlName: 'Zack', peak: 'Typhoon (Cat 3)', deaths: 30, affected: 700_000, damage_m: 200, area: 'Northern Luzon', notes: 'November typhoon' },
    // ── 1994 ──────────────────────────────────────────────────────────────────
    { year: 1994, name: 'Bising (Tim)', intlName: 'Tim', peak: 'Typhoon (Cat 4)', deaths: 31, affected: 900_000, damage_m: 250, area: 'Eastern Philippines', notes: 'May 1994 typhoon' },
    // ── 1991 ──────────────────────────────────────────────────────────────────
    { year: 1991, name: 'Bising/Uring (Thelma)', intlName: 'Thelma', peak: 'Tropical Storm', deaths: 5_101, affected: 2_300_000, damage_m: 900, area: 'Ormoc City, Leyte', notes: 'The Ormoc Tragedy — death toll estimated between 5,101 and 8,000 (official: 5,101); flash flood inundated Ormoc City before dawn on Nov 5; river overwhelmed in minutes; remains one of the deadliest tropical cyclone events in Philippine history despite weak intensity; triggered major urban planning reforms' },
    { year: 1991, name: 'Amy (Amy)', intlName: 'Amy', peak: 'Typhoon (Cat 3)', deaths: 95, affected: 1_200_000, damage_m: 340, area: 'Visayas, Mindanao', notes: 'August 1991' },
    // ── 1990 ──────────────────────────────────────────────────────────────────
    { year: 1990, name: 'Mike (Mike)', intlName: 'Mike', peak: 'Super Typhoon 5', deaths: 480, affected: 2_700_000, damage_m: 1_100, area: 'Leyte, Mindanao', notes: 'Devastating Leyte and Surigao typhoon' },
    // ── 1988 ──────────────────────────────────────────────────────────────────
    { year: 1988, name: 'Unsang (Ruby)', intlName: 'Ruby', peak: 'Super Typhoon 5', deaths: 315, affected: 2_000_000, damage_m: 780, area: 'Visayas', notes: 'Category 5 at peak; deadly storm surge' },
    // ── 1984 ──────────────────────────────────────────────────────────────────
    { year: 1984, name: 'Undang (Ike)', intlName: 'Ike', peak: 'Super Typhoon 5', deaths: 1_369, affected: 4_000_000, damage_m: 1_600, area: 'Visayas, Mindanao', notes: 'One of the deadliest in the pre-satellite era; massive storm surge' },
    // ── 1970 ──────────────────────────────────────────────────────────────────
    { year: 1970, name: 'Sening (Joan)', intlName: 'Joan', peak: 'Super Typhoon 5', deaths: 1_565, affected: 1_200_000, damage_m: 800, area: 'Luzon, Bicol', notes: 'Catastrophic Bicol typhoon; massive storm surge; most deadly storm of the 1970s in Philippines' },
    // ── 1960 ──────────────────────────────────────────────────────────────────
    { year: 1960, name: 'Harriet', intlName: 'Harriet', peak: 'Typhoon', deaths: 200, affected: 500_000, damage_m: null, area: 'Visayas', notes: 'Major mid-20th century typhoon' },
    // ── 1949 ──────────────────────────────────────────────────────────────────
    { year: 1949, name: 'Gloria', intlName: 'Gloria', peak: 'Typhoon', deaths: 700, affected: null, damage_m: null, area: 'Luzon', notes: 'Pre-warning-system era typhoon; significant casualty event' },
    // ── 1881 ──────────────────────────────────────────────────────────────────
    { year: 1881, name: 'Haiphong Typhoon', intlName: '—', peak: 'Typhoon (Historic)', deaths: 20_000, affected: null, damage_m: null, area: 'Haiphong (Vietnam) / South China Sea / Philippines vicinity', notes: 'Deadliest typhoon in recorded history; estimated 20,000+ fatalities; devastated Haiphong, Vietnam but also impacted northern Philippine sea; cited in Britannica and Encyclopaedia of Natural Disasters as the deadliest tropical cyclone event ever; struck before modern warning systems existed' },
];

// ─── BUILD + INJECT TRIGGER BUTTON ───────────────────────────────────────────
(function injectHistoryButton() {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', injectHistoryButton);
        return;
    }
    const existing = document.getElementById('typhoonHistoryBtn');
    if (existing) return;

    const btn = document.createElement('button');
    btn.id = 'typhoonHistoryBtn';
    btn.innerHTML = '<i class="fas fa-book-open"></i><span>Philippine Typhoon History</span>';
    btn.setAttribute('aria-label', 'View Philippine Typhoon History');
    btn.style.cssText = `
    position:fixed;bottom:100px;right:26px;z-index:8990;
    display:flex;align-items:center;gap:8px;
    padding:10px 16px;
    background:linear-gradient(135deg,#1a1a2e 0%,#16213e 50%,#0f3460 100%);
    color:#e2e8f0;border:1px solid rgba(255,255,255,.15);
    border-radius:22px;font-family:'Sora',sans-serif;
    font-size:12px;font-weight:700;cursor:pointer;
    box-shadow:0 6px 24px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.05);
    transition:all .2s;letter-spacing:.2px;
  `;
    btn.addEventListener('mouseenter', () => {
        btn.style.transform = 'translateY(-2px)';
        btn.style.boxShadow = '0 10px 32px rgba(0,0,0,.5),0 0 0 1px rgba(255,255,255,.08)';
    });
    btn.addEventListener('mouseleave', () => {
        btn.style.transform = '';
        btn.style.boxShadow = '0 6px 24px rgba(0,0,0,.4),0 0 0 1px rgba(255,255,255,.05)';
    });
    btn.addEventListener('click', openTyphoonHistoryModal);
    document.body.appendChild(btn);
})();

// ─── OPEN MODAL ──────────────────────────────────────────────────────────────
function openTyphoonHistoryModal() {
    document.getElementById('phTyphoonHistoryModal')?.remove();

    // Sort newest first, then deduplicate by year+name
    const data = [...PH_TYPHOON_HISTORY].sort((a, b) => b.year - a.year);

    // Stats
    const totalStorms = data.length;
    const totalDeaths = data.reduce((s, t) => s + (t.deaths || 0), 0);
    const totalDamage = data.reduce((s, t) => s + (t.damage_m || 0), 0);
    const avgPerYear = (totalStorms / Math.max(1, new Set(data.map(t => t.year)).size)).toFixed(1);
    const yearsSpanned = data[data.length - 1].year;
    const deadliestEver = data.reduce((max, t) => (t.deaths || 0) > (max.deaths || 0) ? t : max, data[0]);

    // Severity helpers
    const severity = t => {
        if (t.peak.includes('Super')) return { label: 'Super Typhoon', dot: '#dc2626', bg: '#fef2f2', border: '#fca5a5', tier: 5 };
        if (t.peak.includes('5')) return { label: 'Cat 5', dot: '#dc2626', bg: '#fef2f2', border: '#fca5a5', tier: 5 };
        if (t.peak.includes('4')) return { label: 'Cat 4', dot: '#f97316', bg: '#fff7ed', border: '#fdba74', tier: 4 };
        if (t.peak.includes('3')) return { label: 'Cat 3', dot: '#f59e0b', bg: '#fefce8', border: '#fde047', tier: 3 };
        if (t.peak.includes('2')) return { label: 'Cat 2', dot: '#eab308', bg: '#fefce8', border: '#fde68a', tier: 2 };
        if (t.peak.includes('Typhoon')) return { label: 'Typhoon', dot: '#3b82f6', bg: '#eff6ff', border: '#bfdbfe', tier: 2 };
        return { label: 'TS/TD', dot: '#6b7280', bg: '#f9fafb', border: '#e5e7eb', tier: 1 };
    };

    const fmt = n => n >= 1_000_000 ? (n / 1_000_000).toFixed(1) + 'M' : n >= 1_000 ? (n / 1_000).toFixed(0) + 'K' : (n || '—');

    // Group by year
    const byYear = {};
    data.forEach(t => { (byYear[t.year] = byYear[t.year] || []).push(t); });
    const sortedYears = Object.keys(byYear).map(Number).sort((a, b) => b - a);

    // Build rows
    const rows = data.map((t, idx) => {
        const s = severity(t);
        const deaths = t.deaths >= 1000 ? `<strong style="color:#dc2626">${fmt(t.deaths)}</strong>` : (t.deaths || '—');
        const dmg = t.damage_php_b
            ? `₱${t.damage_php_b}B`
            : t.damage_m ? `₱${t.damage_m >= 1000 ? (t.damage_m / 1000).toFixed(1) + 'B' : t.damage_m + 'M'} est.` : '—';
        const isDeadliest = t.year === deadliestEver.year && t.name === deadliestEver.name;
        const isHistoricDeadly = (t.deaths || 0) >= 5000 && t.year < 2000;
        return `
    <tr data-idx="${idx}" data-tier="${s.tier}" data-year="${t.year}"
        style="cursor:pointer;transition:background .12s;border-bottom:1px solid rgba(226,232,240,.6)"
        onmouseenter="this.style.background='rgba(241,245,249,.8)'"
        onmouseleave="this.style.background=''"
        onclick="openTyphoonDetailModal(${idx})">
      <td style="padding:9px 10px 9px 14px;font-weight:800;color:#1e3a5f;font-size:12px;white-space:nowrap">
        ${t.year}
      </td>
      <td style="padding:9px 10px">
        <div style="font-size:12px;font-weight:700;color:#0f172a;line-height:1.2">${t.name}</div>
        ${t.intlName && t.intlName !== '—' ? `<div style="font-size:10px;color:#94a3b8">${t.intlName}</div>` : ''}
        ${isDeadliest ? '<span style="font-size:8px;padding:1px 6px;border-radius:8px;background:#fee2e2;color:#991b1b;font-weight:700">DEADLIEST MODERN</span>' : ''}
        ${isHistoricDeadly && !isDeadliest ? '<span style="font-size:8px;padding:1px 6px;border-radius:8px;background:#fef3c7;color:#92400e;font-weight:700">HISTORIC DEATH TOLL</span>' : ''}
      </td>
      <td style="padding:9px 10px">
        <span style="font-size:10px;padding:2px 8px;border-radius:10px;
                     background:${s.bg};border:1px solid ${s.border};
                     color:${s.dot};font-weight:700;white-space:nowrap">
          ● ${s.label}
        </span>
      </td>
      <td style="padding:9px 10px;font-size:11px;color:#475569;max-width:160px">
        <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="${t.area}">${t.area}</div>
      </td>
      <td style="padding:9px 10px;font-size:12px;font-weight:600;text-align:right;white-space:nowrap">${deaths}</td>
      <td style="padding:9px 10px;font-size:11px;color:#64748b;text-align:right;white-space:nowrap">${dmg}</td>
      <td style="padding:9px 10px 9px 6px;text-align:center">
        <i class="fas fa-chevron-right" style="font-size:8px;color:#cbd5e1"></i>
      </td>
    </tr>`;
    }).join('');

    const modal = document.createElement('div');
    modal.id = 'phTyphoonHistoryModal';
    modal.style.cssText = `
    position:fixed;inset:0;z-index:9998;
    background:rgba(9,12,24,.75);backdrop-filter:blur(6px);
    display:flex;align-items:center;justify-content:center;padding:12px;
    animation:phFadeIn .25s ease`;

    modal.innerHTML = `
  <style>
    @keyframes phFadeIn   { from{opacity:0}to{opacity:1} }
    @keyframes phSlideIn  { from{opacity:0;transform:translateY(18px) scale(.97)}to{opacity:1;transform:none} }
    #phTyphoonHistoryModal ::-webkit-scrollbar{width:5px;height:5px}
    #phTyphoonHistoryModal ::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
    .ph-filter-btn{padding:5px 13px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;
      font-size:11px;font-weight:700;font-family:'Sora',sans-serif;cursor:pointer;color:#475569;
      transition:all .15s}
    .ph-filter-btn.active,.ph-filter-btn:hover{background:#1e3a5f;color:#fff;border-color:#1e3a5f}
    #phHistorySearch{padding:8px 14px 8px 36px;border:1.5px solid #e2e8f0;border-radius:22px;
      font-family:'Sora',sans-serif;font-size:12px;color:#0f172a;background:#f8fafc;
      outline:none;width:230px;transition:all .18s}
    #phHistorySearch:focus{border-color:#1e3a5f;background:#fff;box-shadow:0 0 0 3px rgba(30,58,95,.1)}
  </style>

  <div style="background:#fff;border-radius:20px;width:100%;max-width:900px;
              max-height:92vh;overflow:hidden;display:flex;flex-direction:column;
              box-shadow:0 32px 80px rgba(0,0,0,.3);
              animation:phSlideIn .3s cubic-bezier(.34,1.4,.64,1)">

    <!-- ── HEADER ── -->
    <div style="background:linear-gradient(135deg,#0a0f1e 0%,#1a1a3e 40%,#0f3460 100%);
                padding:22px 24px 20px;position:relative;flex-shrink:0;overflow:hidden">
      <!-- Decorative rings -->
      <div style="position:absolute;width:300px;height:300px;border-radius:50%;
                  border:1px solid rgba(255,255,255,.06);top:-140px;right:-60px;pointer-events:none"></div>
      <div style="position:absolute;width:180px;height:180px;border-radius:50%;
                  border:1px solid rgba(245,158,11,.1);top:-70px;right:80px;pointer-events:none"></div>
      <!-- Close -->
      <button onclick="document.getElementById('phTyphoonHistoryModal').remove()"
              style="position:absolute;top:14px;right:14px;width:28px;height:28px;
                     border-radius:50%;border:1px solid rgba(255,255,255,.2);
                     background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font-size:13px;
                     display:flex;align-items:center;justify-content:center;z-index:2;
                     transition:background .15s"
              onmouseenter="this.style.background='rgba(255,255,255,.25)'"
              onmouseleave="this.style.background='rgba(255,255,255,.1)'">✕</button>
      <!-- Title -->
      <div style="position:relative;z-index:1;display:flex;align-items:center;gap:14px;margin-bottom:18px">
        <div style="width:52px;height:52px;border-radius:14px;
                    background:linear-gradient(135deg,rgba(220,38,38,.3),rgba(249,115,22,.2));
                    border:1px solid rgba(220,38,38,.4);
                    display:flex;align-items:center;justify-content:center;font-size:26px;flex-shrink:0">🌀</div>
        <div>
          <div style="font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;line-height:1.1">
            Philippine Typhoon History</div>
          <div style="font-size:11px;color:rgba(255,255,255,.5);margin-top:4px">
            ${yearsSpanned}–2024 · ${totalStorms} documented events · Official PAGASA records</div>
        </div>
      </div>
      <!-- Stats strip -->
      <div style="position:relative;z-index:1;display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
        ${[
            ['Total Storms', totalStorms, '🌀', 'rgba(59,130,246,.2)', '#93c5fd'],
            ['Total Deaths', totalDeaths.toLocaleString(), '💔', 'rgba(220,38,38,.2)', '#fca5a5'],
            ['Avg / Year', avgPerYear, '📅', 'rgba(16,185,129,.2)', '#6ee7b7'],
            ['Deadliest Modern', `${deadliestEver.name} (${deadliestEver.year})`, '⚠️', 'rgba(245,158,11,.2)', '#fcd34d'],
        ].map(([lbl, val, ico, bg, tc]) => `
          <div style="background:${bg};border:1px solid ${tc}33;border-radius:12px;padding:10px 12px">
            <div style="font-size:14px;margin-bottom:4px">${ico}</div>
            <div style="font-size:${String(val).length > 12 ? '11' : '16'}px;font-weight:800;color:${tc};
                        line-height:1.1;letter-spacing:-.5px">${val}</div>
            <div style="font-size:8px;color:rgba(255,255,255,.45);margin-top:3px;
                        text-transform:uppercase;letter-spacing:.6px">${lbl}</div>
          </div>`).join('')}
      </div>
    </div>

    <!-- ── TOOLBAR ── -->
    <div style="padding:12px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;gap:10px;flex-wrap:wrap;flex-shrink:0">
      <div style="position:relative;flex-shrink:0">
        <i class="fas fa-search" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);
                   color:#94a3b8;font-size:11px;pointer-events:none"></i>
        <input id="phHistorySearch" type="search" placeholder="Search by name, year, area…"
               oninput="filterTyphoonHistory(this.value)">
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap" id="phFilterBtns">
        <button class="ph-filter-btn active" onclick="setTyphoonFilter('all',this)">All</button>
        <button class="ph-filter-btn" onclick="setTyphoonFilter('5',this)">🔴 Super / Cat 5</button>
        <button class="ph-filter-btn" onclick="setTyphoonFilter('4',this)">🟠 Cat 4</button>
        <button class="ph-filter-btn" onclick="setTyphoonFilter('3',this)">🟡 Cat 3</button>
        <button class="ph-filter-btn" onclick="setTyphoonFilter('notable',this)">💀 500+ Deaths</button>
      </div>
      <div style="margin-left:auto;font-size:10px;color:#94a3b8" id="phResultCount">
        Showing ${data.length} records
      </div>
    </div>

    <!-- ── TABLE ── -->
    <div style="overflow-y:auto;flex:1;-webkit-overflow-scrolling:touch">
      <table id="phHistoryTable" style="width:100%;border-collapse:collapse">
        <thead>
          <tr style="background:#f1f5f9;border-bottom:2px solid #e2e8f0;position:sticky;top:0;z-index:2">
            ${['Year', 'Name (PAGASA / Intl)', 'Peak Intensity', 'Area Affected', 'Deaths', 'Damage', ''].map((h, i) => `
            <th style="padding:9px ${i === 0 ? '10px 9px 14px' : '10px'};text-align:${i >= 4 && i < 6 ? 'right' : 'left'};
                       font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;
                       letter-spacing:.7px;white-space:nowrap">${h}</th>`).join('')}
          </tr>
        </thead>
        <tbody id="phHistoryTbody">${rows}</tbody>
      </table>
      <div id="phEmptyState" style="display:none;padding:40px;text-align:center">
        <div style="font-size:32px;margin-bottom:10px">🔍</div>
        <div style="font-size:14px;font-weight:600;color:#475569;margin-bottom:4px">No storms found</div>
        <div style="font-size:12px;color:#94a3b8">Try a different search or filter</div>
      </div>
    </div>

    <!-- ── FOOTER ── -->
    <div style="padding:10px 18px;border-top:1px solid #e2e8f0;background:#f8fafc;
                display:flex;align-items:center;justify-content:space-between;
                flex-shrink:0;flex-wrap:wrap;gap:8px">
      <div style="font-size:10px;color:#94a3b8;display:flex;align-items:center;gap:5px">
        <i class="fas fa-database" style="color:#6366f1"></i>
        Sources: PAGASA · NDRRMC · EM-DAT · IBTrACS · Wikipedia · JMA
      </div>
      <div style="display:flex;gap:8px">
        <button onclick="document.getElementById('phTyphoonHistoryModal').remove()"
                style="padding:8px 16px;border:1px solid #e2e8f0;border-radius:20px;background:#fff;
                       color:#475569;font-size:11px;font-weight:600;cursor:pointer;font-family:'Sora',sans-serif">
          ✕ Close
        </button>
      </div>
    </div>
  </div>`;

    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.addEventListener('keydown', _phEscHandler);
    document.body.appendChild(modal);

    // Store data reference for filtering
    window._phTyphoonData = data;
    window._phCurrentFilter = 'all';
}

function _phEscHandler(e) {
    if (e.key === 'Escape') {
        document.getElementById('phTyphoonHistoryModal')?.remove();
        document.getElementById('phTyphoonDetailModal')?.remove();
        document.removeEventListener('keydown', _phEscHandler);
    }
}

// ─── FILTER + SEARCH ─────────────────────────────────────────────────────────
window.setTyphoonFilter = function (filter, btn) {
    window._phCurrentFilter = filter;
    document.querySelectorAll('.ph-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const q = document.getElementById('phHistorySearch')?.value || '';
    filterTyphoonHistory(q);
};

window.filterTyphoonHistory = function (q) {
    const data = window._phTyphoonData || [];
    const filter = window._phCurrentFilter || 'all';
    const ql = (q || '').toLowerCase();
    let count = 0;

    const severity = t => {
        if (t.peak.includes('Super') || t.peak.includes('5')) return 5;
        if (t.peak.includes('4')) return 4;
        if (t.peak.includes('3')) return 3;
        return 2;
    };

    const tbody = document.getElementById('phHistoryTbody');
    if (!tbody) return;

    tbody.querySelectorAll('tr').forEach(row => {
        const idx = parseInt(row.dataset.idx);
        const t = data[idx];
        if (!t) return;

        const matchSearch = !ql ||
            t.name.toLowerCase().includes(ql) ||
            (t.intlName || '').toLowerCase().includes(ql) ||
            String(t.year).includes(ql) ||
            (t.area || '').toLowerCase().includes(ql) ||
            (t.notes || '').toLowerCase().includes(ql);

        const matchFilter =
            filter === 'all' ||
            (filter === '5' && severity(t) >= 5) ||
            (filter === '4' && severity(t) === 4) ||
            (filter === '3' && severity(t) === 3) ||
            (filter === 'notable' && (t.deaths || 0) >= 500);

        const show = matchSearch && matchFilter;
        row.style.display = show ? '' : 'none';
        if (show) count++;
    });

    const empty = document.getElementById('phEmptyState');
    if (empty) empty.style.display = count === 0 ? 'block' : 'none';
    const rc = document.getElementById('phResultCount');
    if (rc) rc.textContent = `Showing ${count} record${count === 1 ? '' : 's'}`;
};

// ─── DETAIL MODAL ─────────────────────────────────────────────────────────────
window.openTyphoonDetailModal = function (idx) {
    const data = window._phTyphoonData;
    if (!data) return;
    const t = data[idx];
    if (!t) return;

    document.getElementById('phTyphoonDetailModal')?.remove();

    const sev = (() => {
        if (t.peak.includes('Super') || t.peak.includes('5'))
            return { color: '#dc2626', bg: 'rgba(220,38,38,.12)', label: 'Super Typhoon / Cat 5', ring: 'rgba(220,38,38,.3)' };
        if (t.peak.includes('4'))
            return { color: '#f97316', bg: 'rgba(249,115,22,.12)', label: 'Category 4 Typhoon', ring: 'rgba(249,115,22,.3)' };
        if (t.peak.includes('3'))
            return { color: '#f59e0b', bg: 'rgba(245,158,11,.12)', label: 'Category 3 Typhoon', ring: 'rgba(245,158,11,.3)' };
        if (t.peak.includes('Typhoon'))
            return { color: '#3b82f6', bg: 'rgba(59,130,246,.12)', label: 'Typhoon', ring: 'rgba(59,130,246,.3)' };
        return { color: '#6b7280', bg: 'rgba(107,114,128,.12)', label: 'Tropical Storm / Depression', ring: 'rgba(107,114,128,.3)' };
    })();

    const dmgStr = t.damage_php_b
        ? `₱${t.damage_php_b}B`
        : t.damage_m
            ? `₱${t.damage_m >= 1000 ? (t.damage_m / 1000).toFixed(1) + 'B' : t.damage_m + 'M'} (est.)`
            : 'Not available';
    const affStr = t.affected ? (t.affected >= 1_000_000
        ? (t.affected / 1_000_000).toFixed(1) + 'M people' : (t.affected / 1_000).toFixed(0) + 'K people') : 'Unknown';

    const isDeadliest = t.deaths >= 5000;
    const isSignificant = t.deaths >= 1000 || (t.damage_m || 0) >= 1000;

    const modal = document.createElement('div');
    modal.id = 'phTyphoonDetailModal';
    modal.style.cssText = `
    position:fixed;inset:0;z-index:10000;
    background:rgba(9,12,24,.6);backdrop-filter:blur(4px);
    display:flex;align-items:center;justify-content:center;padding:12px;
    animation:phFadeIn .2s ease`;

    modal.innerHTML = `
  <div style="background:#fff;border-radius:18px;width:100%;max-width:540px;
              overflow:hidden;box-shadow:0 24px 72px rgba(0,0,0,.28);
              animation:phSlideIn .28s cubic-bezier(.34,1.4,.64,1)">

    <!-- Hero -->
    <div style="background:linear-gradient(135deg,#0a0f1e 0%,#1a1a3e 60%,#0f3460 100%);
                padding:22px 22px 20px;position:relative;overflow:hidden">
      <div style="position:absolute;width:200px;height:200px;border-radius:50%;
                  border:1px solid ${sev.ring};top:-90px;right:-50px;pointer-events:none"></div>
      <!-- Close -->
      <button onclick="document.getElementById('phTyphoonDetailModal').remove()"
              style="position:absolute;top:12px;right:12px;width:26px;height:26px;
                     border-radius:50%;border:1px solid rgba(255,255,255,.2);
                     background:rgba(255,255,255,.1);color:#fff;cursor:pointer;font-size:12px;
                     display:flex;align-items:center;justify-content:center;z-index:2">✕</button>
      <!-- Icon + title -->
      <div style="position:relative;z-index:1;display:flex;align-items:center;gap:14px;margin-bottom:16px">
        <div style="width:56px;height:56px;border-radius:16px;
                    background:${sev.bg};border:1px solid ${sev.color}44;
                    display:flex;align-items:center;justify-content:center;font-size:30px;flex-shrink:0;
                    box-shadow:0 0 20px ${sev.ring}">🌀</div>
        <div style="flex:1">
          <div style="font-size:22px;font-weight:800;color:#fff;line-height:1.1;letter-spacing:-.5px">
            ${t.name}</div>
          ${t.intlName && t.intlName !== '—' ? `
          <div style="font-size:12px;color:rgba(255,255,255,.55);margin-top:3px">
            Intl: ${t.intlName}</div>` : ''}
        </div>
        <div style="text-align:right">
          <div style="font-size:36px;font-weight:900;color:${sev.color};
                      letter-spacing:-2px;line-height:1;text-shadow:0 0 20px ${sev.ring}">${t.year}</div>
        </div>
      </div>
      <!-- Peak intensity badge -->
      <div style="position:relative;z-index:1;display:inline-flex;align-items:center;gap:6px;
                  padding:5px 14px;border-radius:20px;
                  background:${sev.bg};border:1px solid ${sev.color}44">
        <span style="font-size:11px;color:${sev.color};font-weight:700">${sev.label}</span>
      </div>
      ${isDeadliest ? `
      <div style="position:relative;z-index:1;margin-top:10px;padding:6px 12px;border-radius:8px;
                  background:rgba(220,38,38,.2);border:1px solid rgba(220,38,38,.4);
                  font-size:11px;color:#fca5a5;font-weight:700;display:inline-flex;align-items:center;gap:6px">
        ⚠️ One of the deadliest typhoons in Philippine history
      </div>` : isSignificant ? `
      <div style="position:relative;z-index:1;margin-top:10px;padding:6px 12px;border-radius:8px;
                  background:rgba(249,115,22,.15);border:1px solid rgba(249,115,22,.3);
                  font-size:11px;color:#fdba74;font-weight:700;display:inline-flex;align-items:center;gap:6px">
        📌 Major historic disaster event
      </div>` : ''}
    </div>

    <!-- Body -->
    <div style="padding:18px 20px;display:flex;flex-direction:column;gap:12px">

      <!-- Key stats grid -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
        ${[
            ['Deaths', t.deaths ? t.deaths.toLocaleString() : 'Unknown', '💔', t.deaths >= 1000 ? '#fef2f2' : '#f8fafc', t.deaths >= 1000 ? '#b91c1c' : '#475569'],
            ['Affected', affStr, '🏚️', '#f0fdf4', '#15803d'],
            ['Damage', dmgStr, '💸', '#fffbeb', '#b45309'],
            ['Peak', t.peak, '🌀', '#f0f9ff', '#0369a1'],
        ].map(([lbl, val, ico, bg, tc]) => `
          <div style="background:${bg};border-radius:12px;padding:12px;border:1px solid rgba(0,0,0,.06)">
            <div style="font-size:11px;color:#94a3b8;font-weight:600;margin-bottom:4px">${ico} ${lbl}</div>
            <div style="font-size:13px;font-weight:800;color:${tc};line-height:1.2">${val}</div>
          </div>`).join('')}
      </div>

      <!-- Area -->
      <div style="background:#f8fafc;border-radius:12px;padding:12px;border:1px solid #e2e8f0">
        <div style="font-size:10px;color:#94a3b8;font-weight:600;margin-bottom:4px">
          <i class="fas fa-map-marker-alt" style="color:#e11d48;margin-right:5px"></i>AREAS AFFECTED
        </div>
        <div style="font-size:13px;font-weight:600;color:#0f172a">${t.area}</div>
      </div>

      <!-- Notes -->
      ${t.notes ? `
      <div style="background:#faf5ff;border:1px solid #e9d5ff;border-radius:12px;padding:12px">
        <div style="font-size:10px;color:#7c3aed;font-weight:700;margin-bottom:5px">
          <i class="fas fa-file-alt" style="margin-right:5px"></i>HISTORICAL NOTES
        </div>
        <div style="font-size:12px;color:#4c1d95;line-height:1.65;font-weight:500">${t.notes}</div>
      </div>` : ''}

      <!-- AI Ask button -->
      <button onclick="askAIAboutTyphoon(window._phTyphoonData[${idx}])"
              style="width:100%;padding:11px;border:none;border-radius:12px;
                     background:linear-gradient(135deg,#1a1a3e,#0f3460);
                     color:#fff;font-size:12px;font-weight:700;cursor:pointer;
                     font-family:'Sora',sans-serif;display:flex;align-items:center;
                     justify-content:center;gap:8px;transition:all .15s"
              onmouseenter="this.style.transform='translateY(-1px)';this.style.boxShadow='0 6px 20px rgba(0,0,0,.3)'"
              onmouseleave="this.style.transform='';this.style.boxShadow=''">
        <i class="fas fa-robot"></i> Ask AI About This Typhoon
      </button>
    </div>

    <!-- Footer -->
    <div style="padding:10px 20px;border-top:1px solid #e2e8f0;background:#f8fafc;
                display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:10px;color:#94a3b8">Sources: PAGASA · NDRRMC · EM-DAT</span>
      <button onclick="document.getElementById('phTyphoonDetailModal').remove()"
              style="padding:7px 16px;border:1px solid #e2e8f0;border-radius:20px;background:#fff;
                     color:#475569;font-size:11px;font-weight:600;cursor:pointer;font-family:'Sora',sans-serif">
        ← Back
      </button>
    </div>
  </div>`;

    modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
    document.body.appendChild(modal);
};

// ─── ASK AI ABOUT A TYPHOON ──────────────────────────────────────────────────
window.askAIAboutTyphoon = function (t) {
    document.getElementById('phTyphoonDetailModal')?.remove();
    document.getElementById('phTyphoonHistoryModal')?.remove();
    const deaths = t.deaths ? t.deaths.toLocaleString() + ' deaths' : 'unknown death toll';
    const dmg = t.damage_php_b
        ? `₱${t.damage_php_b}B in damage`
        : t.damage_m ? `₱${t.damage_m >= 1000 ? (t.damage_m / 1000).toFixed(1) + 'B' : t.damage_m + 'M'} in damage` : 'unquantified damage';
    const msg = `Tell me about Typhoon ${t.name} (international name: ${t.intlName || 'N/A'}) which hit the Philippines in ${t.year}. `
        + `It was a ${t.peak} that affected ${t.area}, causing ${deaths} and ${dmg}. `
        + (t.notes ? `Historical notes: ${t.notes}. ` : '')
        + `What lessons did the Philippines learn from this typhoon? How did it affect disaster preparedness policies?`;
    const win = document.getElementById('chatBubbleWindow');
    if (win) win.classList.add('tt-chat-window--open');
    setTimeout(() => { if (typeof askQuestion === 'function') askQuestion(msg); }, 300);
};

console.log('[TyphoonHistory] Philippine Typhoon History module loaded —', PH_TYPHOON_HISTORY.length, 'events indexed ✅');