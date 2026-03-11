'use strict';
/**
 * typhoon_hourly_patch.js  v3
 * – Adds scrollable hourly strip to the forecast modal
 * – Clicking any hour card opens a detailed hour modal on top
 */

// ── Global store ──────────────────────────────────────────────────────────────
window._hourlyByDate = {};
window._hourlyFetched = false;
window._hourlyFetching = false;

// ── Independent hourly fetch ──────────────────────────────────────────────────
async function fetchHourlyData(lat, lon) {
    if (window._hourlyFetching) return;
    window._hourlyFetching = true;
    try {
        const params = new URLSearchParams({
            latitude: lat, longitude: lon,
            hourly: [
                'temperature_2m', 'apparent_temperature', 'relative_humidity_2m',
                'precipitation_probability', 'precipitation',
                'windspeed_10m', 'surface_pressure', 'weathercode',
            ].join(','),
            forecast_days: 7, timezone: 'Asia/Manila',
        });
        const res = await fetch('https://api.open-meteo.com/v1/forecast?' + params,
            { signal: AbortSignal.timeout(15000) });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!data.hourly) throw new Error('No hourly');

        window._hourlyByDate = {};
        (data.hourly.time || []).forEach((iso, i) => {
            const dk = iso.slice(0, 10);
            const hour = parseInt(iso.slice(11, 13), 10);
            if (!window._hourlyByDate[dk]) window._hourlyByDate[dk] = [];
            window._hourlyByDate[dk].push({
                hour,
                temp: Math.round(data.hourly.temperature_2m?.[i] ?? 0),
                feels: Math.round(data.hourly.apparent_temperature?.[i] ?? 0),
                hum: Math.round(data.hourly.relative_humidity_2m?.[i] ?? 0),
                rainProb: Math.round(data.hourly.precipitation_probability?.[i] ?? 0),
                rain: Math.round((data.hourly.precipitation?.[i] ?? 0) * 10) / 10,
                wind: Math.round(data.hourly.windspeed_10m?.[i] ?? 0),
                pres: Math.round(data.hourly.surface_pressure?.[i] ?? 1013),
                code: data.hourly.weathercode?.[i] ?? 0,
            });
        });
        window._hourlyFetched = true;
        console.log('[hourly_patch] loaded', Object.keys(window._hourlyByDate).length, 'days ✓');
    } catch (e) {
        console.warn('[hourly_patch] fetch failed:', e.message);
    } finally {
        window._hourlyFetching = false;
    }
}

(function startHourlyFetch() {
    const go = () => fetchHourlyData(window.APP?.userLat ?? 7.09298, window.APP?.userLon ?? 125.63504);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => setTimeout(go, 500));
    else setTimeout(go, 500);
    setInterval(() => fetchHourlyData(window.APP?.userLat ?? 7.09298, window.APP?.userLon ?? 125.63504), 600000);
})();

// ── Helpers ───────────────────────────────────────────────────────────────────
const _fmt = h => h === 0 ? '12 AM' : h < 12 ? h + ' AM' : h === 12 ? '12 PM' : (h - 12) + ' PM';

function _wIcon(c) {
    if (c >= 95) return '⛈️'; if (c >= 80) return '🌦️'; if (c >= 71) return '❄️';
    if (c >= 61) return '🌧️'; if (c >= 51) return '🌦️'; if (c >= 45) return '🌫️';
    if (c === 3) return '☁️'; if (c === 2) return '⛅'; if (c === 1) return '🌤️';
    return '☀️';
}
function _wLabel(c) {
    if (c >= 95) return 'Thunderstorm'; if (c >= 80) return 'Rain Showers';
    if (c >= 71) return 'Snow'; if (c >= 61) return 'Rain';
    if (c >= 51) return 'Drizzle'; if (c >= 45) return 'Fog';
    if (c === 3) return 'Overcast'; if (c === 2) return 'Partly Cloudy';
    if (c === 1) return 'Mainly Clear'; return 'Clear / Sunny';
}
function _rainDesc(p) {
    if (p >= 80) return 'High – expect rain'; if (p >= 50) return 'Moderate chance';
    if (p >= 20) return 'Slight chance'; return 'Very unlikely';
}
function _windDesc(w) {
    if (w > 118) return 'Typhoon-force'; if (w > 88) return 'Storm-force';
    if (w > 62) return 'Strong'; if (w > 39) return 'Moderate';
    if (w > 20) return 'Light breeze'; return 'Calm';
}
function _presDesc(p) {
    if (p < 1005) return 'Critical low – storm risk'; if (p < 1010) return 'Below normal';
    if (p > 1020) return 'High pressure – fair'; return 'Normal range';
}
function _humDesc(h) {
    if (h >= 90) return 'Very high'; if (h >= 75) return 'Humid'; if (h < 40) return 'Dry'; return 'Comfortable';
}
function _comfortScore(h) {
    const rp = Math.min(4, h.rainProb / 25);
    const wp = Math.min(3, h.wind / 40);
    const hp = Math.max(0, (h.hum - 75) / 25);
    const tp = Math.max(0, (h.feels - 34) / 4);
    return Math.max(1, Math.min(10, Math.round(10 - rp - wp - hp - tp)));
}

// ── CSS ───────────────────────────────────────────────────────────────────────
(function injectCSS() {
    if (document.getElementById('__hourly_patch_css')) return;
    const s = document.createElement('style');
    s.id = '__hourly_patch_css';
    s.textContent = `
/* ── Hourly section ─────────────────────────────── */
.tt-hourly-wrap{border-top:1px solid var(--border);padding:18px 24px 20px;background:var(--surf2);}
.tt-hourly-section-label{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);display:flex;align-items:center;gap:7px;margin-bottom:12px;}
.tt-hourly-section-label::after{content:'';flex:1;height:1px;background:var(--border);}
.tt-hourly-scroll{display:flex;gap:7px;overflow-x:auto;padding-bottom:6px;scroll-snap-type:x mandatory;scrollbar-width:thin;scrollbar-color:var(--border) transparent;}
.tt-hourly-scroll::-webkit-scrollbar{height:4px;}
.tt-hourly-scroll::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}

/* ── Hour card ──────────────────────────────────── */
.tt-hc{flex-shrink:0;width:72px;background:var(--surf);border:1px solid var(--border);border-radius:12px;padding:9px 5px 8px;text-align:center;scroll-snap-align:start;cursor:pointer;transition:transform .18s,box-shadow .18s,border-color .18s;position:relative;}
.tt-hc:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(13,27,54,.14);border-color:var(--navy-light);}
.tt-hc:hover::after{content:'tap for details';position:absolute;bottom:-20px;left:50%;transform:translateX(-50%);white-space:nowrap;font-size:8px;color:var(--muted);font-family:'DM Mono',monospace;pointer-events:none;}
.tt-hc--now{background:linear-gradient(160deg,#1c3461,#0d1b36);border-color:#3b5bdb;}
.tt-hc__now-pill{display:inline-block;font-size:7px;font-weight:700;background:var(--amber);color:#000;border-radius:8px;padding:1px 5px;margin-bottom:3px;text-transform:uppercase;letter-spacing:.5px;}
.tt-hc__time{font-size:9px;font-weight:700;letter-spacing:.3px;text-transform:uppercase;color:var(--muted);margin-bottom:5px;}
.tt-hc--now .tt-hc__time{color:rgba(255,255,255,.5);}
.tt-hc__icon{font-size:19px;margin-bottom:4px;line-height:1;}
.tt-hc__temp{font-size:14px;font-weight:800;color:var(--text);letter-spacing:-.5px;margin-bottom:2px;}
.tt-hc--now .tt-hc__temp{color:#fff;}
.tt-hc__rain{font-size:9px;color:var(--sky);font-family:'DM Mono',monospace;margin-bottom:3px;}
.tt-hc--now .tt-hc__rain{color:#7dd3fc;}
.tt-hc__wind{font-size:9px;color:var(--muted);}
.tt-hc--now .tt-hc__wind{color:rgba(255,255,255,.45);}
.tt-hc__bar-wrap{margin-top:5px;height:3px;border-radius:2px;background:rgba(0,0,0,.07);overflow:hidden;}
.tt-hc--now .tt-hc__bar-wrap{background:rgba(255,255,255,.15);}
.tt-hc__bar-fill{height:100%;border-radius:2px;width:0;background:linear-gradient(90deg,var(--sky),#38bdf8);transition:width .9s cubic-bezier(.34,1.56,.64,1);}

/* ── Skeleton ───────────────────────────────────── */
@keyframes hShimmer{0%{background-position:-400px 0}100%{background-position:400px 0}}
.tt-hc-skeleton{flex-shrink:0;width:72px;height:118px;border-radius:12px;background:linear-gradient(90deg,var(--border) 25%,#e8edf2 50%,var(--border) 75%);background-size:400px 100%;scroll-snap-align:start;animation:hShimmer 1.4s infinite linear;}

/* ── Summary stat row ───────────────────────────── */
.tt-hourly-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px;}
@media(max-width:620px){.tt-hourly-stats{grid-template-columns:repeat(2,1fr);}}
.tt-hs-cell{background:var(--surf);border:1px solid var(--border);border-radius:9px;padding:9px 10px;display:flex;align-items:center;gap:8px;}
.tt-hs-icon{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;}
.tt-hs-body{flex:1;min-width:0;}
.tt-hs-lbl{font-size:8px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:1px;}
.tt-hs-val{font-size:13px;font-weight:800;color:var(--text);letter-spacing:-.4px;}
.tt-hs-sub{font-size:9px;color:var(--muted);}

/* ── Hour detail modal ──────────────────────────── */
#ttHourModal{display:none;position:fixed;inset:0;background:rgba(9,18,38,.75);backdrop-filter:blur(10px);z-index:10500;align-items:center;justify-content:center;padding:16px;}
#ttHourModal.open{display:flex !important;}
@keyframes ttHourIn{from{opacity:0;transform:scale(.88) translateY(24px)}to{opacity:1;transform:scale(1) translateY(0)}}
.tt-hm-box{background:var(--surf);border-radius:22px;width:100%;max-width:420px;overflow:hidden;box-shadow:0 32px 80px rgba(9,18,38,.4),0 4px 16px rgba(9,18,38,.14);animation:ttHourIn .28s cubic-bezier(.34,1.56,.64,1) both;}

/* hero */
.tt-hm-hero{padding:24px 26px 20px;position:relative;overflow:hidden;}
.tt-hm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.07);pointer-events:none;}
.tt-hm-hero__ring--1{width:240px;height:240px;top:-110px;right:-60px;}
.tt-hm-hero__ring--2{width:130px;height:130px;top:-40px;right:60px;border-color:rgba(245,158,11,.14);}
.tt-hm-hero__close{position:absolute;top:12px;right:12px;width:28px;height:28px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:7px;color:rgba(255,255,255,.8);cursor:pointer;font-size:12px;display:flex;align-items:center;justify-content:center;transition:background .15s;z-index:2;}
.tt-hm-hero__close:hover{background:rgba(255,255,255,.22);color:#fff;}
.tt-hm-hero__top{position:relative;z-index:1;display:flex;align-items:center;gap:14px;}
.tt-hm-hero__icon{width:56px;height:56px;border-radius:14px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:28px;backdrop-filter:blur(8px);flex-shrink:0;box-shadow:0 4px 18px rgba(0,0,0,.2);}
.tt-hm-hero__info{flex:1;}
.tt-hm-hero__time{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:2px;}
.tt-hm-hero__date{font-size:11px;color:rgba(255,255,255,.5);font-family:'DM Mono',monospace;margin-bottom:5px;}
.tt-hm-hero__cond{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:20px;font-size:10px;color:rgba(255,255,255,.85);font-weight:600;}
.tt-hm-hero__temps{text-align:right;}
.tt-hm-hero__temps .big{font-size:36px;font-weight:800;color:#fff;letter-spacing:-2px;line-height:1;}
.tt-hm-hero__temps .big .u{font-size:16px;font-weight:400;opacity:.65;}
.tt-hm-hero__temps .feels{font-size:12px;color:rgba(255,255,255,.5);font-family:'DM Mono',monospace;margin-top:2px;}

/* body grid */
.tt-hm-body{padding:18px 22px 22px;display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.tt-hm-row{background:var(--surf2);border:1px solid var(--border);border-radius:11px;padding:11px 13px;display:flex;align-items:center;gap:10px;transition:transform .15s,box-shadow .15s;}
.tt-hm-row:hover{transform:translateX(3px);box-shadow:var(--shadow);}
.tt-hm-row__icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;}
.tt-hm-row__body{flex:1;}
.tt-hm-row__lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:1px;}
.tt-hm-row__val{font-size:14px;font-weight:800;color:var(--text);letter-spacing:-.4px;}
.tt-hm-row__sub{font-size:10px;color:var(--muted);margin-top:1px;}

/* comfort bar */
.tt-hm-comfort{grid-column:1/-1;background:var(--surf2);border:1px solid var(--border);border-radius:11px;padding:13px 15px;}
.tt-hm-comfort__top{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.tt-hm-comfort__lbl{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);}
.tt-hm-comfort__score{font-size:18px;font-weight:800;color:var(--text);letter-spacing:-.5px;}
.tt-hm-comfort__bar{height:6px;border-radius:3px;background:var(--border);overflow:hidden;margin-bottom:4px;}
.tt-hm-comfort__fill{height:100%;border-radius:3px;transition:width 1s cubic-bezier(.34,1.56,.64,1);}
.tt-hm-comfort__desc{font-size:10px;color:var(--muted);}

/* advice */
.tt-hm-advice{grid-column:1/-1;border-radius:11px;padding:12px 14px;display:flex;gap:9px;align-items:flex-start;border:1px solid;}
.tt-hm-advice--safe{background:var(--success-light);border-color:#6ee7b7;color:#065f46;}
.tt-hm-advice--caution{background:var(--warning-light);border-color:#fcd34d;color:#92400e;}
.tt-hm-advice--danger{background:var(--danger-light);border-color:#fca5a5;color:#7f1d1d;}
.tt-hm-advice i{font-size:14px;flex-shrink:0;margin-top:1px;}
.tt-hm-advice__title{font-weight:700;font-size:11px;margin-bottom:2px;}
.tt-hm-advice__text{font-size:11px;line-height:1.6;}

/* footer */
.tt-hm-footer{border-top:1px solid var(--border);padding:11px 22px;background:var(--surf2);display:flex;gap:8px;justify-content:flex-end;}
.tt-hm-btn{padding:7px 14px;border-radius:7px;font-size:11px;font-weight:600;font-family:'Sora',sans-serif;cursor:pointer;border:none;transition:all .15s;}
.tt-hm-btn--ghost{background:var(--surf);border:1px solid var(--border);color:var(--text);}
.tt-hm-btn--ghost:hover{background:var(--border);}
.tt-hm-btn--ai{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;}
.tt-hm-btn--ai:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(13,27,54,.3);}

/* nav arrows */
.tt-hm-nav{display:flex;gap:6px;}
.tt-hm-nav-btn{width:28px;height:28px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:7px;color:rgba(255,255,255,.8);cursor:pointer;font-size:11px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.tt-hm-nav-btn:hover{background:rgba(255,255,255,.22);color:#fff;}
.tt-hm-nav-btn:disabled{opacity:.3;cursor:not-allowed;}
`;
    document.head.appendChild(s);
})();

// ── Build & inject the hour detail modal DOM (once) ───────────────────────────
function ensureHourModal() {
    if (document.getElementById('ttHourModal')) return;
    const el = document.createElement('div');
    el.id = 'ttHourModal';
    el.innerHTML = `
    <div class="tt-hm-box" id="ttHourBox">
        <div class="tt-hm-hero" id="ttHmHero">
            <div class="tt-hm-hero__ring tt-hm-hero__ring--1"></div>
            <div class="tt-hm-hero__ring tt-hm-hero__ring--2"></div>
            <button class="tt-hm-hero__close" onclick="closeHourModal()"><i class="fas fa-times"></i></button>
            <div class="tt-hm-hero__top">
                <div class="tt-hm-hero__icon" id="ttHmIcon">⛅</div>
                <div class="tt-hm-hero__info">
                    <div class="tt-hm-hero__time" id="ttHmTime">—</div>
                    <div class="tt-hm-hero__date" id="ttHmDate">—</div>
                    <div class="tt-hm-hero__cond" id="ttHmCond">—</div>
                </div>
                <div class="tt-hm-hero__temps">
                    <div class="big" id="ttHmTemp">—<span class="u">°C</span></div>
                    <div class="feels" id="ttHmFeels">feels —°C</div>
                </div>
            </div>
        </div>
        <div class="tt-hm-body">
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:var(--sky-light);color:var(--sky)"><i class="fas fa-tint"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Rain Probability</div>
                    <div class="tt-hm-row__val" id="ttHmRainProb">—</div>
                    <div class="tt-hm-row__sub" id="ttHmRainSub">—</div>
                </div>
            </div>
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:var(--info-light);color:var(--info)"><i class="fas fa-cloud-rain"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Rainfall</div>
                    <div class="tt-hm-row__val" id="ttHmRain">—</div>
                    <div class="tt-hm-row__sub" id="ttHmRainAmtSub">—</div>
                </div>
            </div>
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:var(--indigo-light);color:var(--indigo)"><i class="fas fa-wind"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Wind Speed</div>
                    <div class="tt-hm-row__val" id="ttHmWind">—</div>
                    <div class="tt-hm-row__sub" id="ttHmWindSub">—</div>
                </div>
            </div>
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:var(--teal-light);color:var(--teal)"><i class="fas fa-water"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Humidity</div>
                    <div class="tt-hm-row__val" id="ttHmHum">—</div>
                    <div class="tt-hm-row__sub" id="ttHmHumSub">—</div>
                </div>
            </div>
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:#fdf4ff;color:#7c3aed"><i class="fas fa-tachometer-alt"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Pressure</div>
                    <div class="tt-hm-row__val" id="ttHmPres">—</div>
                    <div class="tt-hm-row__sub" id="ttHmPresSub">—</div>
                </div>
            </div>
            <div class="tt-hm-row">
                <div class="tt-hm-row__icon" style="background:var(--amber-light);color:var(--amber-dark)"><i class="fas fa-thermometer-half"></i></div>
                <div class="tt-hm-row__body">
                    <div class="tt-hm-row__lbl">Feels Like</div>
                    <div class="tt-hm-row__val" id="ttHmFeels2">—</div>
                    <div class="tt-hm-row__sub" id="ttHmFeelsSub">—</div>
                </div>
            </div>
            <div class="tt-hm-comfort">
                <div class="tt-hm-comfort__top">
                    <span class="tt-hm-comfort__lbl">Comfort Score</span>
                    <span class="tt-hm-comfort__score" id="ttHmComfort">—<span style="font-size:11px;font-weight:400;color:var(--muted)">/10</span></span>
                </div>
                <div class="tt-hm-comfort__bar"><div class="tt-hm-comfort__fill" id="ttHmComfortBar" style="width:0"></div></div>
                <div class="tt-hm-comfort__desc" id="ttHmComfortDesc">—</div>
            </div>
            <div class="tt-hm-advice tt-hm-advice--caution" id="ttHmAdvice">
                <i class="fas fa-exclamation-circle"></i>
                <div>
                    <div class="tt-hm-advice__title" id="ttHmAdvTitle">Hourly Advisory</div>
                    <div class="tt-hm-advice__text" id="ttHmAdvText">—</div>
                </div>
            </div>
        </div>
        <div class="tt-hm-footer">
            <div class="tt-hm-nav">
                <button class="tt-hm-nav-btn" id="ttHmPrev" title="Previous hour" onclick="navigateHour(-1)"><i class="fas fa-chevron-left"></i></button>
                <button class="tt-hm-nav-btn" id="ttHmNext" title="Next hour"     onclick="navigateHour(+1)"><i class="fas fa-chevron-right"></i></button>
            </div>
            <button class="tt-hm-btn tt-hm-btn--ghost" onclick="closeHourModal()"><i class="fas fa-times" style="margin-right:4px"></i>Close</button>
            <button class="tt-hm-btn tt-hm-btn--ai"    onclick="askAIAboutHour()"><i class="fas fa-robot" style="margin-right:4px"></i>Ask AI</button>
        </div>
    </div>`;
    document.body.appendChild(el);

    // Close on backdrop click
    el.addEventListener('click', e => { if (e.target === el) closeHourModal(); });
}

// ── State for the open hour modal ─────────────────────────────────────────────
window._hmState = { dateKey: null, hours: [], idx: 0 };

// ── Open hour detail modal ────────────────────────────────────────────────────
window.openHourModal = function (dateKey, hourIndex) {
    ensureHourModal();
    const hours = window._hourlyByDate?.[dateKey] ?? [];
    if (!hours.length) return;
    window._hmState = { dateKey, hours, idx: hourIndex };
    _renderHourModal();
    document.getElementById('ttHourModal').classList.add('open');
};

function _renderHourModal() {
    const { dateKey, hours, idx } = window._hmState;
    const h = hours[idx];
    if (!h) return;

    const todayKey = new Date().toLocaleDateString('en-CA');
    const isToday = dateKey === todayKey;
    const nowHour = new Date().getHours();
    const isNow = isToday && h.hour === nowHour;

    // Hero gradient by risk
    const heroEl = document.getElementById('ttHmHero');
    const danger = h.rainProb >= 80 || h.wind >= 88;
    const caution = h.rainProb >= 50 || h.wind >= 40;
    heroEl.style.background = danger
        ? 'linear-gradient(135deg,#7f1d1d,#be123c)'
        : caution
            ? 'linear-gradient(135deg,#78350f,#b45309)'
            : 'linear-gradient(135deg,var(--navy),#1e3a8a)';

    // Date label
    let dateLbl;
    try {
        const d = new Date(dateKey + 'T00:00:00');
        dateLbl = d.toLocaleDateString('en-PH', { weekday: 'long', month: 'short', day: 'numeric', year: 'numeric' });
    } catch { dateLbl = dateKey; }

    document.getElementById('ttHmIcon').textContent = _wIcon(h.code);
    document.getElementById('ttHmTime').textContent = isNow ? _fmt(h.hour) + ' · Now' : _fmt(h.hour);
    document.getElementById('ttHmDate').textContent = dateLbl;
    document.getElementById('ttHmCond').textContent = _wLabel(h.code);
    document.getElementById('ttHmTemp').innerHTML = `${h.temp}<span class="u">°C</span>`;
    document.getElementById('ttHmFeels').textContent = `feels ${h.feels}°C`;

    // Rows
    document.getElementById('ttHmRainProb').textContent = h.rainProb + '%';
    document.getElementById('ttHmRainSub').textContent = _rainDesc(h.rainProb);
    document.getElementById('ttHmRain').textContent = h.rain + ' mm';
    document.getElementById('ttHmRainAmtSub').textContent = h.rain > 10 ? 'Heavy rain' : h.rain > 3 ? 'Moderate' : 'Light / trace';
    document.getElementById('ttHmWind').textContent = h.wind + ' km/h';
    document.getElementById('ttHmWindSub').textContent = _windDesc(h.wind);
    document.getElementById('ttHmHum').textContent = h.hum + '%';
    document.getElementById('ttHmHumSub').textContent = _humDesc(h.hum);
    document.getElementById('ttHmPres').textContent = h.pres + ' hPa';
    document.getElementById('ttHmPresSub').textContent = _presDesc(h.pres);
    document.getElementById('ttHmFeels2').textContent = h.feels + '°C';
    document.getElementById('ttHmFeelsSub').textContent = h.feels >= 40 ? 'Danger heat index' : h.feels >= 35 ? 'Very hot' : h.feels >= 30 ? 'Warm' : 'Comfortable';

    // Comfort
    const comfort = _comfortScore(h);
    const comfColor = comfort >= 8 ? 'var(--success)' : comfort >= 5 ? 'var(--amber)' : 'var(--danger)';
    document.getElementById('ttHmComfort').innerHTML = `${comfort}<span style="font-size:11px;font-weight:400;color:var(--muted)">/10</span>`;
    document.getElementById('ttHmComfortDesc').textContent =
        comfort >= 8 ? 'Excellent outdoor conditions' :
            comfort >= 6 ? 'Good – minor discomfort' :
                comfort >= 4 ? 'Moderate – limit outdoor time' :
                    comfort >= 2 ? 'Poor – stay indoors if possible' : 'Very poor – avoid going out';
    const barEl = document.getElementById('ttHmComfortBar');
    barEl.style.background = comfColor; barEl.style.width = '0';
    setTimeout(() => { barEl.style.width = (comfort * 10) + '%'; }, 80);

    // Advice
    const advEl = document.getElementById('ttHmAdvice');
    const advT = document.getElementById('ttHmAdvTitle');
    const advTx = document.getElementById('ttHmAdvText');
    if (danger) {
        advEl.className = 'tt-hm-advice tt-hm-advice--danger';
        advT.textContent = '⚠️ Adverse Conditions';
        advTx.textContent = h.wind >= 88
            ? `Storm-force winds of ${h.wind} km/h expected. Stay indoors and secure loose objects.`
            : `Heavy rain likely (${h.rainProb}%). Flooding possible in low-lying areas. Avoid travel.`;
    } else if (caution) {
        advEl.className = 'tt-hm-advice tt-hm-advice--caution';
        advT.textContent = '☔ Use Caution';
        advTx.textContent = `Rain probability ${h.rainProb}% — bring an umbrella. Wind at ${h.wind} km/h.`;
    } else {
        advEl.className = 'tt-hm-advice tt-hm-advice--safe';
        advT.textContent = '✅ Conditions Favorable';
        advTx.textContent = `Low rain risk (${h.rainProb}%), light winds (${h.wind} km/h). Good to go outdoors.`;
    }

    // Nav arrows
    document.getElementById('ttHmPrev').disabled = idx === 0;
    document.getElementById('ttHmNext').disabled = idx === hours.length - 1;

    // Highlight the active card in the hourly strip
    document.querySelectorAll('.tt-hc').forEach(c => c.style.outline = '');
    const activeCard = document.getElementById(`hc_${dateKey}_${h.hour}`);
    if (activeCard) activeCard.style.outline = '2px solid var(--navy-light)';
}

// ── Navigate prev/next hour ───────────────────────────────────────────────────
window.navigateHour = function (dir) {
    const s = window._hmState;
    const next = s.idx + dir;
    if (next < 0 || next >= s.hours.length) return;
    s.idx = next;
    _renderHourModal();
};

// ── Close hour modal ──────────────────────────────────────────────────────────
window.closeHourModal = function () {
    document.getElementById('ttHourModal')?.classList.remove('open');
    document.querySelectorAll('.tt-hc').forEach(c => c.style.outline = '');
};

// ── Ask AI about selected hour ────────────────────────────────────────────────
window.askAIAboutHour = function () {
    const { dateKey, hours, idx } = window._hmState;
    const h = hours[idx];
    if (!h) return;
    closeHourModal();
    let dateLbl = dateKey;
    try { dateLbl = new Date(dateKey + 'T00:00:00').toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric' }); } catch { }
    const msg = `What should I know about the weather at ${_fmt(h.hour)} on ${dateLbl}? `
        + `Conditions: ${_wLabel(h.code)}, Temp: ${h.temp}°C (feels ${h.feels}°C), `
        + `Rain probability: ${h.rainProb}%, Rainfall: ${h.rain}mm, Wind: ${h.wind} km/h, `
        + `Humidity: ${h.hum}%, Pressure: ${h.pres} hPa. Give me practical hourly advice.`;
    // Open the chat bubble and send
    const chatWin = document.getElementById('chatBubbleWindow');
    if (chatWin) chatWin.classList.add('tt-chat-window--open');
    setTimeout(() => {
        if (typeof window.askQuestion === 'function') window.askQuestion(msg);
        else if (typeof window.sendMessage === 'function') {
            const inp = document.getElementById('messageInput');
            if (inp) { inp.value = msg; window.sendMessage(); }
        }
    }, 350);
};

// ── Keyboard: arrow keys to navigate, Escape to close ────────────────────────
document.addEventListener('keydown', e => {
    if (!document.getElementById('ttHourModal')?.classList.contains('open')) return;
    if (e.key === 'ArrowLeft') { e.stopPropagation(); window.navigateHour(-1); }
    if (e.key === 'ArrowRight') { e.stopPropagation(); window.navigateHour(+1); }
    if (e.key === 'Escape') window.closeHourModal();
});

// ── Build the hourly section HTML ─────────────────────────────────────────────
function buildHourlySection(dateKey) {
    const hours = window._hourlyByDate?.[dateKey] ?? [];
    const nowHour = new Date().getHours();
    const todayKey = new Date().toLocaleDateString('en-CA');
    const isToday = dateKey === todayKey;

    if (!window._hourlyFetched) {
        const sk = Array(9).fill('<div class="tt-hc-skeleton"></div>').join('');
        return `<div class="tt-hourly-wrap">
            <div class="tt-hourly-section-label"><i class="fas fa-clock"></i>Hourly Forecast <span style="font-size:9px;color:var(--muted);font-weight:400;margin-left:2px">· fetching…</span></div>
            <div class="tt-hourly-scroll">${sk}</div>
        </div>`;
    }
    if (!hours.length) {
        return `<div class="tt-hourly-wrap">
            <div class="tt-hourly-section-label"><i class="fas fa-clock"></i>Hourly Forecast</div>
            <div style="text-align:center;padding:18px 0;color:var(--muted);font-size:11px">
                <i class="fas fa-cloud" style="display:block;font-size:22px;margin-bottom:6px;opacity:.3"></i>No hourly data available.
            </div></div>`;
    }

    const cards = hours.map((h, i) => {
        const isNow = isToday && h.hour === nowHour;
        return `<div class="tt-hc${isNow ? ' tt-hc--now' : ''}" id="hc_${dateKey}_${h.hour}"
            onclick="openHourModal('${dateKey}', ${i})" title="Click for details">
            ${isNow ? '<div class="tt-hc__now-pill">Now</div>' : ''}
            <div class="tt-hc__time">${_fmt(h.hour)}</div>
            <div class="tt-hc__icon">${_wIcon(h.code)}</div>
            <div class="tt-hc__temp">${h.temp}°</div>
            <div class="tt-hc__rain"><i class="fas fa-tint" style="font-size:7px;margin-right:1px"></i>${h.rainProb}%</div>
            <div class="tt-hc__wind"><i class="fas fa-wind" style="font-size:7px;margin-right:1px"></i>${h.wind}</div>
            <div class="tt-hc__bar-wrap"><div class="tt-hc__bar-fill" data-pct="${h.rainProb}" style="width:0"></div></div>
        </div>`;
    }).join('');

    const peakRain = hours.reduce((a, b) => b.rainProb > a.rainProb ? b : a, hours[0]);
    const peakWind = hours.reduce((a, b) => b.wind > a.wind ? b : a, hours[0]);
    const avgHum = Math.round(hours.reduce((s, h) => s + h.hum, 0) / hours.length);
    const avgFeels = Math.round(hours.reduce((s, h) => s + h.feels, 0) / hours.length);

    const statsHTML = [
        { icon: 'fa-umbrella', bg: 'var(--sky-light)', color: 'var(--sky)', lbl: 'Peak Rain', val: peakRain.rainProb + '%', sub: 'at ' + _fmt(peakRain.hour) },
        { icon: 'fa-wind', bg: 'var(--indigo-light)', color: 'var(--indigo)', lbl: 'Peak Wind', val: peakWind.wind + ' km/h', sub: 'at ' + _fmt(peakWind.hour) },
        { icon: 'fa-tint', bg: 'var(--teal-light)', color: 'var(--teal)', lbl: 'Avg Humidity', val: avgHum + '%', sub: avgHum > 85 ? 'Very humid' : avgHum > 70 ? 'Humid' : 'Comfortable' },
        { icon: 'fa-thermometer-half', bg: 'var(--amber-light)', color: 'var(--amber-dark)', lbl: 'Feels Like', val: avgFeels + '°C', sub: avgFeels >= 36 ? 'Danger heat' : avgFeels >= 32 ? 'Very warm' : 'Comfortable' },
    ].map(c => `<div class="tt-hs-cell">
        <div class="tt-hs-icon" style="background:${c.bg};color:${c.color}"><i class="fas ${c.icon}"></i></div>
        <div class="tt-hs-body"><div class="tt-hs-lbl">${c.lbl}</div><div class="tt-hs-val">${c.val}</div><div class="tt-hs-sub">${c.sub}</div></div>
    </div>`).join('');

    return `<div class="tt-hourly-wrap">
        <div class="tt-hourly-section-label"><i class="fas fa-clock"></i>Hourly Forecast
            <span style="font-size:9px;color:var(--muted);font-weight:500;margin-left:2px">· click any hour for details</span>
        </div>
        <div class="tt-hourly-scroll" id="hourlyScroll_${dateKey}">${cards}</div>
        <div class="tt-hourly-stats">${statsHTML}</div>
    </div>`;
}

// ── Animate bars + scroll to now ──────────────────────────────────────────────
function animateHourlyBars(dateKey) {
    requestAnimationFrame(() => requestAnimationFrame(() => {
        document.querySelectorAll('.tt-hc__bar-fill').forEach(el => {
            el.style.width = (el.dataset.pct || 0) + '%';
        });
        const nowCard = document.getElementById(`hc_${dateKey}_${new Date().getHours()}`);
        const scroll = document.getElementById(`hourlyScroll_${dateKey}`);
        if (nowCard && scroll) {
            setTimeout(() => scroll.scrollTo({ left: Math.max(0, nowCard.offsetLeft - 16), behavior: 'smooth' }), 150);
        }
    }));
}

// ── Inject section into open forecast modal ───────────────────────────────────
function injectHourlyIntoModal(dateKey) {
    document.getElementById('tt-hourly-section')?.remove();
    const wrap = document.createElement('div');
    wrap.id = 'tt-hourly-section';
    wrap.innerHTML = buildHourlySection(dateKey);
    const footer = document.querySelector('#forecastModal .tt-modal__footer');
    if (footer) footer.parentNode.insertBefore(wrap, footer);
    else document.querySelector('#forecastModal .tt-modal__box')?.appendChild(wrap);
    animateHourlyBars(dateKey);
    ensureHourModal(); // pre-create the modal DOM so first click is instant
}

// ── Derive YYYY-MM-DD from forecast day index ─────────────────────────────────
function getDateKey(idx) {
    const day = window.APP?.forecastDays?.[idx];
    if (day?.date) {
        if (/^\d{4}-\d{2}-\d{2}$/.test(day.date)) return day.date;
        const p = new Date(day.date);
        if (!isNaN(p)) return p.toLocaleDateString('en-CA');
    }
    const d = new Date(); d.setDate(d.getDate() + idx);
    return d.toLocaleDateString('en-CA');
}

// ── Patch openForecastModal ───────────────────────────────────────────────────
(function patchModal() {
    let tries = 0;
    const attempt = () => {
        const orig = window.openForecastModal;
        if (typeof orig !== 'function') {
            if (++tries < 20) setTimeout(attempt, 300);
            else console.warn('[hourly_patch] openForecastModal not found');
            return;
        }
        window.openForecastModal = function (idx) {
            orig.call(this, idx);
            const dateKey = getDateKey(idx);
            if (window._hourlyFetched) { injectHourlyIntoModal(dateKey); return; }
            injectHourlyIntoModal(dateKey); // skeleton
            const poll = setInterval(() => {
                if (!window._hourlyFetched) return;
                clearInterval(poll);
                if (document.getElementById('tt-hourly-section')) injectHourlyIntoModal(dateKey);
            }, 300);
            setTimeout(() => clearInterval(poll), 20000);
        };
        console.log('[hourly_patch] ✓ patched (v3 — clickable hours)');
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', attempt);
    else attempt();
})();