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