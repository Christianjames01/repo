/**
 * map-patch.js — PAGASA Map v3.0
 * ─────────────────────────────────────────────────────────────
 *  FIXES:
 *  1. Map is truly responsive (fixes the hardcoded 460px in index.php)
 *  2. Heavy rain / thunderstorm areas shown OUTSIDE the PAR
 *  3. Outside-PAR typhoons rendered with dashed ring + label
 *  4. GDACS → JMA → JTWC real coordinate fetch chain
 *  5. ResizeObserver + window resize both trigger invalidateSize
 * ─────────────────────────────────────────────────────────────
 */

(function () {
    'use strict';

    /* ═══════════════════════════════════════════════════════════
       0.  OVERRIDE THE HARDCODED #map HEIGHT IN index.php
           index.php has `#map{height:460px;width:100%}` in its
           <style> block. We inject a rule with higher specificity
           AFTER the page loads so it wins.
    ══════════════════════════════════════════════════════════════ */
    function injectResponsiveOverride() {
        const id = 'mp-responsive-override';
        if (document.getElementById(id)) return;
        const s = document.createElement('style');
        s.id = id;
        // !important beats anything in index.php
        s.textContent = `
      /* ── Responsive map: overrides the hardcoded height in index.php ── */
      #map,
      .tt-panel #map,
      div#map {
        height: clamp(260px, 50vw, 560px) !important;
        min-height: 260px !important;
        max-height: 560px !important;
        width: 100% !important;
        background: #0a1628 !important;
        position: relative !important;
      }
      @media (max-width: 900px)  { #map { height: clamp(260px, 55vw, 400px) !important; } }
      @media (max-width: 600px)  { #map { height: 300px !important; } }
      @media (max-width: 420px)  { #map { height: 260px !important; } }

      /* ── Tile color treatment ── */
      .leaflet-tile-pane {
        filter: saturate(0.32) brightness(0.70) hue-rotate(195deg) !important;
      }

      /* ── Scanline overlay ── */
      #map::after {
        content: ''; position: absolute; inset: 0;
        pointer-events: none; z-index: 450;
        background: repeating-linear-gradient(
          0deg, transparent, transparent 3px,
          rgba(0,229,255,0.012) 3px, rgba(0,229,255,0.012) 4px
        );
      }

      /* ── Zoom control ── */
      .leaflet-control-zoom {
        border: 1px solid rgba(0,229,255,0.22) !important;
        border-radius: 6px !important;
        background: rgba(10,22,40,0.92) !important;
        box-shadow: 0 4px 16px rgba(0,0,0,0.5) !important;
        backdrop-filter: blur(8px);
      }
      .leaflet-control-zoom a {
        background: transparent !important; color: #00e5ff !important;
        border-color: rgba(0,229,255,0.15) !important;
        width: 28px !important; height: 28px !important; line-height: 27px !important;
        font-size: 15px !important;
      }
      .leaflet-control-zoom a:hover { background: rgba(0,229,255,0.1) !important; color:#fff !important; }
      .leaflet-control-attribution {
        background: rgba(10,22,40,0.78) !important;
        color: rgba(0,229,255,0.38) !important; font-size: 9px !important;
      }
      .leaflet-control-attribution a { color: rgba(0,229,255,0.5) !important; }

      /* ── User dot ── */
      .mp-user-wrap { position: relative; width: 20px; height: 20px; }
      .mp-user-core {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%,-50%);
        width: 9px; height: 9px; border-radius: 50%;
        background: #e11d48;
        box-shadow: 0 0 0 2.5px #fff, 0 0 10px rgba(225,29,72,0.7);
        z-index: 2;
      }
      .mp-user-ring {
        position: absolute; top: 50%; left: 50%;
        transform: translate(-50%,-50%) scale(0.4);
        width: 22px; height: 22px; border-radius: 50%;
        border: 2px solid #e11d48; opacity: 0.75;
        animation: mp-ping 1.9s ease-out infinite;
      }
      @keyframes mp-ping {
        0%   { transform: translate(-50%,-50%) scale(0.4); opacity: 0.75; }
        100% { transform: translate(-50%,-50%) scale(2.4); opacity: 0; }
      }

      /* ── TC icon ── */
      .mp-tc { animation: mp-spin 9s linear infinite; }
      @keyframes mp-spin { to { transform: rotate(360deg); } }

      /* ── Rain cell ── */
      .mp-rain-label {
        font-family: 'DM Mono', monospace; font-size: 8px; font-weight: 700;
        letter-spacing: .7px; white-space: nowrap; pointer-events: none;
        text-shadow: 0 1px 4px rgba(0,0,0,.9);
      }

      /* ── Legend ── */
      .mp-legend {
        background: rgba(10,22,40,0.93); border: 1px solid rgba(0,229,255,0.22);
        border-radius: 7px; padding: 9px 12px; color: #cce8ff;
        font-family: 'DM Mono', monospace; font-size: 10px;
        backdrop-filter: blur(8px); box-shadow: 0 4px 18px rgba(0,0,0,0.5);
        min-width: 130px;
      }
      .mp-legend-h {
        font-size: 8.5px; font-weight: 700; letter-spacing: 1.4px;
        text-transform: uppercase; margin-bottom: 6px;
        color: #00e5ff; opacity: .85;
      }
      .mp-legend-row { display: flex; align-items: center; gap: 6px; padding: 2px 0; opacity: .85; }
      .mp-legend-dot { width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
      .mp-legend-rain-dot { width: 9px; height: 9px; border-radius: 2px; flex-shrink: 0; }
      .mp-legend-sep {
        margin: 6px 0; border: none; border-top: 1px solid rgba(0,229,255,0.14);
      }
      .mp-legend-par {
        display: flex; align-items: center; gap: 5px;
        color: #00e5ff; font-size: 8.5px; opacity: .7;
      }
      .mp-legend-par-dash {
        display: inline-block; width: 14px; height: 0;
        border-top: 1.5px dashed #00e5ff; opacity: .9;
      }

      /* ── Layer bar ── */
      .mp-lbar {
        display: flex; flex-direction: column; gap: 3px;
        background: rgba(10,22,40,0.92); border: 1px solid rgba(0,229,255,0.22);
        border-radius: 6px; padding: 4px; backdrop-filter: blur(8px);
        box-shadow: 0 4px 14px rgba(0,0,0,0.45);
      }
      .mp-lbtn {
        width: 28px; height: 28px; background: transparent;
        border: 1px solid transparent; border-radius: 4px;
        color: #7fb3d3; font-size: 13px; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: all .18s; line-height: 1;
      }
      .mp-lbtn:hover { background: rgba(0,229,255,0.1); border-color: rgba(0,229,255,0.3); color: #fff; }
      .mp-lbtn.active { background: rgba(0,229,255,0.15); border-color: rgba(0,229,255,0.5); color: #00e5ff; }

      /* ── Timestamp ── */
      .mp-ts {
        background: rgba(10,22,40,0.88); border: 1px solid rgba(0,229,255,0.16);
        border-radius: 5px; padding: 5px 8px; color: #00e5ff;
        font-family: 'DM Mono', monospace; font-size: 9.5px; font-weight: 600;
        backdrop-filter: blur(6px); line-height: 1.5;
      }

      /* ── Popups ── */
      .mp-popup .leaflet-popup-content-wrapper {
        background: rgba(10,22,40,0.97) !important;
        border: 1px solid rgba(0,229,255,0.3) !important;
        border-radius: 9px !important; color: #cce8ff !important;
        font-family: 'DM Mono', monospace !important; font-size: 11px !important;
        box-shadow: 0 8px 30px rgba(0,0,0,0.6), 0 0 18px rgba(0,229,255,0.07) !important;
      }
      .mp-popup .leaflet-popup-tip { background: rgba(10,22,40,0.97) !important; }
      .mp-popup-name { color: #00e5ff; font-size: 13px; font-weight: 700; letter-spacing: .8px; margin-bottom: 7px; }
      .mp-popup-row {
        display: flex; justify-content: space-between; gap: 12px;
        padding: 3px 0; border-bottom: 1px solid rgba(0,229,255,0.08);
        font-size: 10.5px;
      }
      .mp-popup-row:last-child { border: none; }
      .mp-popup-lbl { opacity: .58; }
      .mp-popup-val { font-weight: 700; }
      .mp-badge-par  { display:inline-block; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:700; letter-spacing:.5px; margin-bottom:6px; background:rgba(244,67,54,0.18); border:1px solid rgba(244,67,54,0.4); color:#ef9a9a; }
      .mp-badge-out  { display:inline-block; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:700; letter-spacing:.5px; margin-bottom:6px; background:rgba(255,152,0,0.18); border:1px solid rgba(255,152,0,0.4); color:#ffcc80; }
      .mp-badge-rain { display:inline-block; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:700; letter-spacing:.5px; margin-bottom:6px; background:rgba(59,130,246,0.18); border:1px solid rgba(59,130,246,0.4); color:#93c5fd; }

      /* ── No-cyclone banner ── */
      .mp-allclear {
        background: rgba(10,22,40,0.93); border: 1px solid rgba(0,229,255,0.2);
        border-radius: 8px; padding: 9px 13px;
        font-family: 'DM Mono', monospace; font-size: 10px; color: #69f0ae;
        backdrop-filter: blur(8px); display: flex; align-items: center; gap: 8px;
        box-shadow: 0 4px 14px rgba(0,0,0,0.45);
      }
    `;
        document.head.appendChild(s);
    }

    /* ═══════════════════════════════════════════════════════════
       1.  CONSTANTS
    ══════════════════════════════════════════════════════════════ */
    const PAR = { latMin: 5, latMax: 25, lonMin: 115, lonMax: 135 };

    function inPAR(lat, lon) {
        return lat >= PAR.latMin && lat <= PAR.latMax && lon >= PAR.lonMin && lon <= PAR.lonMax;
    }

    function tcStyle(windKph) {
        const w = parseFloat(windKph) || 0;
        if (w >= 185) return { color: '#f44336', label: 'STY' };
        if (w >= 118) return { color: '#ff9800', label: 'TY' };
        if (w >= 89) return { color: '#ffeb3b', label: 'STS' };
        if (w >= 62) return { color: '#00e5ff', label: 'TS' };
        return { color: '#7ec8e3', label: 'TD' };
    }

    function rainStyle(mmPerHr) {
        const r = parseFloat(mmPerHr) || 0;
        if (r >= 30) return { color: '#ef4444', label: 'EXTREME', fo: 0.22 };
        if (r >= 15) return { color: '#f97316', label: 'HEAVY', fo: 0.17 };
        if (r >= 7) return { color: '#facc15', label: 'MODERATE', fo: 0.13 };
        return { color: '#60a5fa', label: 'LIGHT', fo: 0.09 };
    }

    /* ═══════════════════════════════════════════════════════════
       2.  GRATICULE + PAR BOUNDARY
    ══════════════════════════════════════════════════════════════ */
    function drawGraticule(map) {
        const o = { color: '#00e5ff', opacity: .09, weight: .5, dashArray: '3 6', interactive: false };
        for (let lat = -10; lat <= 40; lat += 5) L.polyline([[lat, 90], [lat, 165]], o).addTo(map);
        for (let lon = 100; lon <= 165; lon += 5) L.polyline([[-15, lon], [45, lon]], o).addTo(map);
    }

    function drawPARBoundary(map) {
        const c = [[PAR.latMin, PAR.lonMin], [PAR.latMin, PAR.lonMax],
        [PAR.latMax, PAR.lonMax], [PAR.latMax, PAR.lonMin], [PAR.latMin, PAR.lonMin]];
        L.polyline(c, { color: '#00e5ff', weight: 5, opacity: .09, interactive: false }).addTo(map);
        L.polyline(c, { color: '#00e5ff', weight: 1.5, opacity: .82, dashArray: '8 5', interactive: false }).addTo(map);
        L.polygon(c, { color: 'transparent', fillColor: '#00e5ff', fillOpacity: .03, interactive: false }).addTo(map);
        L.marker([PAR.latMax + 0.6, PAR.lonMin], {
            icon: L.divIcon({
                html: `<div style="color:#00e5ff;font-family:'DM Mono',monospace;font-size:8.5px;font-weight:700;
                  letter-spacing:1.3px;opacity:.78;white-space:nowrap;pointer-events:none;
                  text-shadow:0 0 8px rgba(0,229,255,0.5)">
                ▸ PHILIPPINE AREA OF RESPONSIBILITY (PAR)
              </div>`,
                className: '', iconSize: [290, 14], iconAnchor: [0, 7]
            }), interactive: false
        }).addTo(map);
    }

    /* ═══════════════════════════════════════════════════════════
       3.  TC ICON
    ══════════════════════════════════════════════════════════════ */
    function makeTCIcon(style, sz = 34) {
        const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${sz}" height="${sz}" viewBox="0 0 34 34">
      <defs>
        <radialGradient id="tcg-${style.label}">
          <stop offset="30%" stop-color="${style.color}" stop-opacity=".85"/>
          <stop offset="100%" stop-color="${style.color}" stop-opacity=".15"/>
        </radialGradient>
      </defs>
      <circle cx="17" cy="17" r="13" fill="none" stroke="${style.color}" stroke-width="1" opacity=".25">
        <animate attributeName="r" values="13;21;13" dur="2.5s" repeatCount="indefinite"/>
        <animate attributeName="opacity" values=".25;0;.25" dur="2.5s" repeatCount="indefinite"/>
      </circle>
      <circle cx="17" cy="17" r="12" fill="url(#tcg-${style.label})" stroke="${style.color}" stroke-width="1.4" opacity=".7"/>
      <path d="M17,10 C21,10 24.5,13.5 24.5,17 C24.5,20.5 21,24 17,24 C13,24 9.5,20.5 9.5,17"
            fill="none" stroke="${style.color}" stroke-width="2" stroke-linecap="round" opacity=".9"/>
      <path d="M17,13 C19.5,13 22,15 22,17 C22,19 19.5,21 17,21"
            fill="none" stroke="${style.color}" stroke-width="1.5" stroke-linecap="round" opacity=".6"/>
      <circle cx="17" cy="17" r="3"   fill="${style.color}" opacity=".95"/>
      <circle cx="17" cy="17" r="1.4" fill="#0a1628"/>
    </svg>`;
        return L.divIcon({
            html: `<div class="mp-tc">${svg}</div>`,
            className: '', iconSize: [sz, sz], iconAnchor: [sz / 2, sz / 2], popupAnchor: [0, -sz / 2]
        });
    }

    /* ═══════════════════════════════════════════════════════════
       4.  WIND RADII + FORECAST TRACK
    ══════════════════════════════════════════════════════════════ */
    function drawWindRadii(target, lat, lon, w) {
        w = parseFloat(w) || 0;
        if (w < 30) return;
        [
            { r: Math.min(w * 1.9, 460), c: '#00e5ff', fo: .04 },
            { r: Math.min(w * 1.05, 290), c: '#ffeb3b', fo: .05 },
            { r: Math.min(w * .58, 160), c: '#ff9800', fo: .08 },
        ].forEach(cfg => {
            if (cfg.r < 25) return;
            L.circle([lat, lon], {
                radius: cfg.r * 1000, color: cfg.c, weight: .8, opacity: .45,
                fillColor: cfg.c, fillOpacity: cfg.fo, dashArray: '4 4', interactive: false
            }).addTo(target);
        });
    }

    function drawForecastTrack(target, lat, lon, pts) {
        if (!pts || !pts.length) return;
        const full = [[lat, lon], ...pts.map(p => [p.lat, p.lon])];
        const L2 = [], R2 = [];
        full.forEach((pt, i) => {
            const sp = i * 0.55;
            L2.push([pt[0] + sp, pt[1] - sp * .4]);
            R2.unshift([pt[0] - sp, pt[1] - sp * .4]);
        });
        L.polygon([...L2, ...R2], {
            color: 'transparent', fillColor: 'rgba(255,200,0,0.12)', fillOpacity: 1, interactive: false
        }).addTo(target);
        L.polyline(full, { color: '#fff', weight: 1.4, opacity: .55, dashArray: '5 4', interactive: false }).addTo(target);
        pts.forEach(p => L.circleMarker([p.lat, p.lon], {
            radius: 3, color: '#fff', weight: 1, fillColor: 'rgba(255,255,255,.22)', fillOpacity: 1, interactive: false
        }).addTo(target));
    }

    /* ═══════════════════════════════════════════════════════════
       5.  HEAVY RAIN CELLS  (shown EVERYWHERE, inside + outside PAR)
           Uses Open-Meteo rain forecast for the broader WPac region
           plus a grid of sample points outside PAR
    ══════════════════════════════════════════════════════════════ */
    let rainLayer = null;

    async function fetchAndDrawRain(map) {
        if (!map) return;
        if (!rainLayer) { rainLayer = L.layerGroup().addTo(map); }
        else { rainLayer.clearLayers(); }

        // Sample grid covering broader WPac area (inside + outside PAR)
        const samplePoints = [];

        // Dense grid inside + just outside PAR
        for (let lat = 2; lat <= 28; lat += 3) {
            for (let lon = 112; lon <= 138; lon += 3) {
                samplePoints.push([lat, lon]);
            }
        }
        // Sparse grid further outside PAR (broader WPac monitoring)
        for (let lat = -2; lat <= 35; lat += 5) {
            for (let lon = 105; lon <= 155; lon += 5) {
                // skip if already covered by inner grid
                const inInner = lat >= 2 && lat <= 28 && lon >= 112 && lon <= 138;
                if (!inInner) samplePoints.push([lat, lon]);
            }
        }

        // Fetch rain data in parallel batches of 10
        const BATCH = 10;
        const rainResults = [];

        for (let i = 0; i < samplePoints.length; i += BATCH) {
            const batch = samplePoints.slice(i, i + BATCH);
            const fetches = batch.map(async ([lat, lon]) => {
                try {
                    const url = `https://api.open-meteo.com/v1/forecast?`
                        + `latitude=${lat}&longitude=${lon}`
                        + `&hourly=precipitation,rain,showers,snowfall`
                        + `&forecast_hours=6&timezone=Asia%2FManila`;
                    const r = await fetch(url, { signal: AbortSignal.timeout(8000) });
                    if (!r.ok) return null;
                    const d = await r.json();
                    // Take max of the next 6 hours of precipitation
                    const vals = d.hourly?.precipitation || [];
                    const maxRain = Math.max(0, ...vals.map(v => parseFloat(v) || 0));
                    if (maxRain < 2) return null; // below threshold
                    return { lat, lon, mm: maxRain };
                } catch { return null; }
            });
            const results = await Promise.all(fetches);
            results.forEach(r => r && rainResults.push(r));

            // Small delay between batches to avoid rate limits
            if (i + BATCH < samplePoints.length) {
                await new Promise(res => setTimeout(res, 120));
            }
        }

        // Draw rain circles on map
        rainResults.forEach(({ lat, lon, mm }) => {
            const st = rainStyle(mm);
            const isOutsidePAR = !inPAR(lat, lon);

            // Main rain circle
            const circle = L.circle([lat, lon], {
                radius: 90000, // ~90 km
                color: st.color,
                weight: isOutsidePAR ? 0.8 : 1.2,
                opacity: isOutsidePAR ? 0.55 : 0.75,
                fillColor: st.color,
                fillOpacity: isOutsidePAR ? st.fo * 0.8 : st.fo,
                dashArray: isOutsidePAR ? '5 4' : null,
                interactive: true
            });

            const outsideLabel = isOutsidePAR
                ? `<div class="mp-badge-rain">OUTSIDE PAR</div><br>`
                : '';

            circle.bindPopup(`
        <div class="mp-popup-name">🌧 ${st.label} RAIN AREA</div>
        ${outsideLabel}
        <div class="mp-popup-row"><span class="mp-popup-lbl">Forecast</span><span class="mp-popup-val">${mm.toFixed(1)} mm/6h</span></div>
        <div class="mp-popup-row"><span class="mp-popup-lbl">Position</span><span class="mp-popup-val">${lat.toFixed(1)}°N ${lon.toFixed(1)}°E</span></div>
        <div class="mp-popup-row"><span class="mp-popup-lbl">Coverage</span><span class="mp-popup-val">~90 km radius</span></div>
        <div class="mp-popup-row"><span class="mp-popup-lbl">Intensity</span><span class="mp-popup-val" style="color:${st.color}">${st.label}</span></div>
      `, { className: 'mp-popup' });

            circle.on('mouseover', function () { this.openPopup(); });
            rainLayer.addLayer(circle);

            // Label for significant rain
            if (mm >= 7) {
                rainLayer.addLayer(L.marker([lat + 0.5, lon], {
                    icon: L.divIcon({
                        html: `<div class="mp-rain-label" style="color:${st.color}">${st.label}<br>${mm.toFixed(0)}mm</div>`,
                        className: '', iconAnchor: [0, 0]
                    }),
                    interactive: false
                }));
            }
        });
    }

    /* ═══════════════════════════════════════════════════════════
       6.  POPUPS FOR TC
    ══════════════════════════════════════════════════════════════ */
    function buildTCPopup(tc) {
        const inside = inPAR(tc.lat, tc.lon);
        const st = tcStyle(tc.windSpeed || tc.maxWind);
        const badge = inside
            ? `<div class="mp-badge-par">⚠ INSIDE PAR</div>`
            : `<div class="mp-badge-out">📡 OUTSIDE PAR — Monitored</div>`;
        const rows = [
            ['Category', `<span style="color:${st.color};font-weight:700">${st.label}</span>`],
            ['Wind', `${tc.windSpeed || '—'} km/h`],
            ['Pressure', tc.pressure ? `${tc.pressure} hPa` : '—'],
            ['Position', `${Number(tc.lat).toFixed(1)}°N ${Number(tc.lon).toFixed(1)}°E`],
            ['Distance', `${tc.distance || '—'} km from you`],
            ['Source', tc.source || 'PAGASA/JMA'],
        ];
        return `<div class="mp-popup-name">🌀 ${tc.name || 'UNNAMED'}</div>${badge}
      ${rows.map(([l, v]) => `<div class="mp-popup-row"><span class="mp-popup-lbl">${l}</span><span class="mp-popup-val">${v}</span></div>`).join('')}`;
    }

    /* ═══════════════════════════════════════════════════════════
       7.  LEGEND
    ══════════════════════════════════════════════════════════════ */
    function addLegend(map) {
        const ctrl = L.control({ position: 'bottomleft' });
        ctrl.onAdd = () => {
            const d = L.DomUtil.create('div', 'mp-legend');
            d.innerHTML = `
        <div class="mp-legend-h">TC INTENSITY</div>
        ${[['TD', '<62', '#7ec8e3'], ['TS', '62–88', '#00e5ff'], ['STS', '89–117', '#ffeb3b'], ['TY', '118–184', '#ff9800'], ['STY', '≥185', '#f44336']]
                    .map(([l, r, c]) => `<div class="mp-legend-row"><span class="mp-legend-dot" style="background:${c}"></span><span>${l} ${r}</span></div>`).join('')}
        <hr class="mp-legend-sep">
        <div class="mp-legend-h">RAINFALL</div>
        ${[['Light', '<7mm', '#60a5fa'], ['Moderate', '7–15mm', '#facc15'], ['Heavy', '15–30mm', '#f97316'], ['Extreme', '>30mm', '#ef4444']]
                    .map(([l, r, c]) => `<div class="mp-legend-row"><span class="mp-legend-rain-dot" style="background:${c}"></span><span>${l} ${r}</span></div>`).join('')}
        <hr class="mp-legend-sep">
        <div class="mp-legend-par"><span class="mp-legend-par-dash"></span>PAR Boundary</div>`;
            return d;
        };
        ctrl.addTo(map);
    }

    /* ═══════════════════════════════════════════════════════════
       8.  LAYER BAR
    ══════════════════════════════════════════════════════════════ */
    const TILES = {
        dark: 'https://{s}.basemaps.cartocdn.com/dark_nolabels/{z}/{x}/{y}{r}.png',
        labels: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
        satellite: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
    };
    let _tileLayer = null;

    function setTile(map, key) {
        if (_tileLayer) map.removeLayer(_tileLayer);
        _tileLayer = L.tileLayer(TILES[key], { attribution: '© CARTO © OSM', maxZoom: 18 }).addTo(map);
        _tileLayer.bringToBack();
    }

    function addLayerBar(map) {
        const ctrl = L.control({ position: 'topright' });
        ctrl.onAdd = () => {
            const d = L.DomUtil.create('div', 'mp-lbar');
            d.innerHTML = `
        <button class="mp-lbtn active" data-t="dark"      title="Dark">🌑</button>
        <button class="mp-lbtn"        data-t="labels"    title="Labels">🗺</button>
        <button class="mp-lbtn"        data-t="satellite" title="Satellite">🛰</button>
        <button class="mp-lbtn"        id="mp-rain-toggle" title="Toggle Rain" style="font-size:11px">🌧</button>
        <button class="mp-lbtn"        id="mp-recenter"   title="Re-center">⊕</button>`;
            L.DomEvent.disableClickPropagation(d);
            return d;
        };
        ctrl.addTo(map);
        setTile(map, 'dark');

        let rainVisible = true;
        document.addEventListener('click', e => {
            const btn = e.target.closest('.mp-lbtn');
            if (!btn) return;
            if (btn.id === 'mp-recenter') { map.flyTo([12.5, 122.0], 5, { duration: 1.2 }); return; }
            if (btn.id === 'mp-rain-toggle') {
                rainVisible = !rainVisible;
                if (rainLayer) {
                    rainVisible ? map.addLayer(rainLayer) : map.removeLayer(rainLayer);
                }
                btn.classList.toggle('active', rainVisible);
                return;
            }
            const key = btn.dataset.t;
            if (!TILES[key]) return;
            document.querySelectorAll('.mp-lbtn[data-t]').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            setTile(map, key);
        });
    }

    /* ═══════════════════════════════════════════════════════════
       9.  TIMESTAMP
    ══════════════════════════════════════════════════════════════ */
    function addTimestamp(map) {
        const ctrl = L.control({ position: 'bottomright' });
        ctrl.onAdd = () => {
            const d = L.DomUtil.create('div', 'mp-ts');
            tickTS(d); setInterval(() => tickTS(d), 60000);
            return d;
        };
        ctrl.addTo(map);
    }
    function tickTS(el) {
        const ph = new Intl.DateTimeFormat('en-PH', {
            timeZone: 'Asia/Manila', day: '2-digit', month: 'short',
            hour: '2-digit', minute: '2-digit', hour12: false
        }).format(new Date());
        el.innerHTML = `🕐 ${ph} PHT<br><span style="opacity:.42;font-size:8.5px">PAGASA · JMA · GDACS · Open-Meteo</span>`;
    }

    /* ═══════════════════════════════════════════════════════════
       10.  USER MARKER
    ══════════════════════════════════════════════════════════════ */
    function placeUserDot(map, lat, lon) {
        if (APP.userMarker) map.removeLayer(APP.userMarker);
        APP.userMarker = L.marker([lat, lon], {
            icon: L.divIcon({
                html: `<div class="mp-user-wrap"><div class="mp-user-ring"></div><div class="mp-user-core"></div></div>`,
                className: '', iconSize: [20, 20], iconAnchor: [10, 10]
            }),
            zIndexOffset: 1200, interactive: false
        }).addTo(map);
    }

    /* ═══════════════════════════════════════════════════════════
       11.  NO-CYCLONE BANNER
    ══════════════════════════════════════════════════════════════ */
    let _clearCtrl = null;
    function showAllClear(map) {
        if (_clearCtrl) { map.removeControl(_clearCtrl); _clearCtrl = null; }
        _clearCtrl = L.control({ position: 'topright' });
        _clearCtrl.onAdd = () => {
            const d = L.DomUtil.create('div', 'mp-allclear');
            d.innerHTML = `<span style="font-size:15px">✓</span>
        <div><strong style="color:#69f0ae">NO ACTIVE CYCLONES</strong><br>
        <span style="opacity:.6;font-size:9px">PAR clear · WPac monitoring active</span></div>`;
            return d;
        };
        _clearCtrl.addTo(map);
    }
    function hideAllClear(map) {
        if (_clearCtrl) { map.removeControl(_clearCtrl); _clearCtrl = null; }
    }

    /* ═══════════════════════════════════════════════════════════
       12.  haversine helper
    ══════════════════════════════════════════════════════════════ */
    function hav(a, b, c, d) {
        const R = 6371, dL = (c - a) * Math.PI / 180, dG = (d - b) * Math.PI / 180;
        const x = Math.sin(dL / 2) ** 2 + Math.cos(a * Math.PI / 180) * Math.cos(c * Math.PI / 180) * Math.sin(dG / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(x), Math.sqrt(1 - x));
    }

    /* ═══════════════════════════════════════════════════════════
       13.  updateMapMarkers  — ALL typhoons, inside + outside PAR
    ══════════════════════════════════════════════════════════════ */
    window.updateMapMarkers = function () {
        const map = APP && APP.map;
        if (!map) return;

        if (!APP.typhoonLayer) APP.typhoonLayer = L.layerGroup().addTo(map);
        APP.typhoonLayer.clearLayers();
        APP.typhoonMarkers = [];

        placeUserDot(map, APP.userLat, APP.userLon);

        const storms = APP.typhoonData || [];
        if (!storms.length) { showAllClear(map); return; }
        hideAllClear(map);

        const allPts = [[APP.userLat, APP.userLon]];

        storms.forEach(tc => {
            // ── Resolve coordinates ────────────────────────────────
            let tlat = parseFloat(tc.lat), tlon = parseFloat(tc.lon);
            if (isNaN(tlat) || isNaN(tlon) || (tlat === 0 && tlon === 0)) {
                const dist = parseFloat(tc.distance) || 800;
                tc._brg = tc._brg ?? (Math.random() * 360);
                const rad = tc._brg * Math.PI / 180;
                tlat = APP.userLat + (dist / 111) * Math.cos(rad);
                tlon = APP.userLon + (dist / (111 * Math.cos(APP.userLat * Math.PI / 180))) * Math.sin(rad);
                tlat = Math.max(-5, Math.min(40, tlat));
                tlon = Math.max(100, Math.min(165, tlon));
            }
            tc.lat = tlat; tc.lon = tlon;
            if (!tc.distance) tc.distance = Math.round(hav(APP.userLat, APP.userLon, tlat, tlon));

            const wind = parseFloat(tc.windSpeed || tc.maxWind || 0);
            const style = tcStyle(wind);
            const inside = inPAR(tlat, tlon);

            // Wind radii
            drawWindRadii(APP.typhoonLayer, tlat, tlon, wind);

            // Forecast track
            const fpts = tc.forecast || tc.track || null;
            if (fpts && fpts.length) drawForecastTrack(APP.typhoonLayer, tlat, tlon, fpts);

            // Outside-PAR visual extras
            if (!inside) {
                // Larger dashed monitoring ring
                L.circle([tlat, tlon], {
                    radius: (wind > 62 ? 420 : 230) * 1000,
                    color: style.color, weight: .7, opacity: .22,
                    fillColor: style.color, fillOpacity: .018,
                    dashArray: '6 5', interactive: false
                }).addTo(APP.typhoonLayer);

                // Dashed line to nearest PAR edge
                const nearLat = Math.max(PAR.latMin, Math.min(PAR.latMax, tlat));
                const nearLon = Math.max(PAR.lonMin, Math.min(PAR.lonMax, tlon));
                L.polyline([[tlat, tlon], [nearLat, nearLon]], {
                    color: style.color, weight: 1, opacity: .20, dashArray: '4 6', interactive: false
                }).addTo(APP.typhoonLayer);

                // "OUTSIDE PAR" floating label
                L.marker([tlat + 1.3, tlon], {
                    icon: L.divIcon({
                        html: `<div style="color:${style.color};font-family:'DM Mono',monospace;font-size:7.5px;
                     font-weight:700;letter-spacing:.8px;opacity:.72;white-space:nowrap;
                     text-shadow:0 1px 4px rgba(0,0,0,.9);pointer-events:none">
                   OUTSIDE PAR
                 </div>`,
                        className: '', iconAnchor: [0, 0]
                    }), interactive: false
                }).addTo(APP.typhoonLayer);
            }

            // Marker + popup
            const marker = L.marker([tlat, tlon], {
                icon: makeTCIcon(style), zIndexOffset: 600, title: tc.name || 'TC'
            });
            marker.bindPopup(buildTCPopup(tc), { className: 'mp-popup', maxWidth: 252, minWidth: 215 });
            marker.on('mouseover', function () { this.openPopup(); });
            APP.typhoonLayer.addLayer(marker);
            APP.typhoonMarkers.push(marker);
            allPts.push([tlat, tlon]);
        });

        // Fit bounds
        if (allPts.length > 1) {
            try {
                APP.map.flyToBounds(L.latLngBounds(allPts).pad(0.3), { duration: 1.4, maxZoom: 7 });
            } catch (e) { }
        }
    };

    /* ═══════════════════════════════════════════════════════════
       14.  fetchFromJTWC — GDACS → JMA → JTWC XML
    ══════════════════════════════════════════════════════════════ */
    window.fetchFromJTWC = async function () {
        // 1. GDACS
        try {
            const r = await fetch(
                'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red',
                { signal: AbortSignal.timeout(9000) });
            if (r.ok) {
                const j = await r.json();
                const storms = [];
                (j.features || []).forEach(f => {
                    const p = f.properties || {}, coords = f.geometry?.coordinates;
                    if (!coords) return;
                    const [lon, lat] = coords;
                    if (lon < 90 || lon > 185 || lat < -10 || lat > 45) return;
                    const wKts = parseFloat(p.maxwind || p.wind_max || 0);
                    storms.push({
                        name: (p.name || p.eventname || 'UNNAMED').toUpperCase(),
                        windSpeed: wKts > 0 ? Math.round(wKts * 1.852) : parseInt(p.windspeed || 0),
                        lat: parseFloat(lat), lon: parseFloat(lon),
                        pressure: parseFloat(p.minpressure || 0) || null,
                        distance: Math.round(hav(APP.userLat, APP.userLon, lat, lon)),
                        source: 'GDACS'
                    });
                });
                if (storms.length) return storms;
            }
        } catch (e) { }

        // 2. JMA
        try {
            const r = await fetch('https://www.jma.go.jp/bosai/typhoon/data/nowcast.json',
                { signal: AbortSignal.timeout(8000) });
            if (r.ok) {
                const j = await r.json();
                const storms = [];
                (Array.isArray(j) ? j : []).forEach(item => {
                    const cp = item.Observation?.CenterPosition;
                    if (!cp) return;
                    const lat = parseFloat(cp.Latitude), lon = parseFloat(cp.Longitude);
                    if (isNaN(lat) || lon < 90 || lon > 185) return;
                    const wKts = parseFloat(item.Observation?.MaxWindSpeed?.value || 0);
                    storms.push({
                        name: (item.Name?.en || 'UNNAMED').toUpperCase(),
                        windSpeed: Math.round(wKts * 1.852),
                        lat, lon,
                        pressure: parseFloat(item.Observation?.CentralPressure?.value || 0) || null,
                        distance: Math.round(hav(APP.userLat, APP.userLon, lat, lon)),
                        source: 'JMA'
                    });
                });
                if (storms.length) return storms;
            }
        } catch (e) { }

        // 3. JTWC XML
        try {
            const r = await fetch('https://www.nhc.noaa.gov/productfeeds/jtwc_atcf.xml',
                { signal: AbortSignal.timeout(8000) });
            if (!r.ok) return [];
            const xml = new DOMParser().parseFromString(await r.text(), 'text/xml');
            const storms = [];
            xml.querySelectorAll('item').forEach(item => {
                const title = item.querySelector('title')?.textContent || '';
                const desc = item.querySelector('description')?.textContent || '';
                if (!title.match(/\bW\b|\bWP\b/i) && !desc.toLowerCase().includes('western pacific')) return;
                const cM = desc.match(/(\d+\.?\d*)\s*[°\s]*([NS])[\s,\/]+(\d+\.?\d*)\s*[°\s]*([EW])/i);
                if (!cM) return;
                let lat = parseFloat(cM[1]); if (cM[2].toUpperCase() === 'S') lat = -lat;
                let lon = parseFloat(cM[3]); if (cM[4].toUpperCase() === 'W') lon = -lon;
                if (lon < 90 || lon > 185) return;
                const wM = desc.match(/(\d+)\s*kt/i);
                const pM = desc.match(/(\d{3,4})\s*(?:mb|hpa)/i);
                storms.push({
                    name: title.replace(/^(Tropical\s+)?(Cyclone|Typhoon|Storm|Depression)\s*/i, '').trim() || 'UNNAMED',
                    windSpeed: wM ? Math.round(parseInt(wM[1]) * 1.852) : 0,
                    lat, lon,
                    pressure: pM ? parseInt(pM[1]) : null,
                    distance: Math.round(hav(APP.userLat, APP.userLon, lat, lon)),
                    source: 'JTWC'
                });
            });
            return storms;
        } catch (e) { return []; }
    };

    /* ═══════════════════════════════════════════════════════════
       15.  PATCH boot() — run after index.php's boot()
    ══════════════════════════════════════════════════════════════ */
    const _origBoot = window.boot;
    window.boot = function (...args) {
        if (_origBoot) _origBoot.apply(this, args);
        waitForMap(setupMap);
    };

    function waitForMap(cb, n = 0) {
        if (APP && APP.map) { cb(); return; }
        if (n > 60) return;
        setTimeout(() => waitForMap(cb, n + 1), 150);
    }

    function setupMap() {
        const map = APP.map;

        // Remove index.php's tile layer (dark_all) — we replace it
        map.eachLayer(l => { if (l instanceof L.TileLayer) map.removeLayer(l); });

        // Build overlays
        drawGraticule(map);
        drawPARBoundary(map);
        addLegend(map);
        addLayerBar(map);   // ← also calls setTile('dark')
        addTimestamp(map);
        placeUserDot(map, APP.userLat, APP.userLon);

        // ── TRUE RESPONSIVENESS ───────────────────────────────────
        // Method A: ResizeObserver on the map element itself
        const mapEl = document.getElementById('map');
        if (mapEl && window.ResizeObserver) {
            new ResizeObserver(() => {
                requestAnimationFrame(() => map.invalidateSize({ animate: false }));
            }).observe(mapEl);
        }
        // Method B: window resize (fallback + also fires on orientation change)
        window.addEventListener('resize', () => {
            requestAnimationFrame(() => map.invalidateSize({ animate: false }));
        });
        // Method C: force a re-check after 500ms (catches CSS transitions)
        setTimeout(() => map.invalidateSize({ animate: false }), 500);
        setTimeout(() => map.invalidateSize({ animate: false }), 1500);

        // Initial render
        updateMapMarkers();

        // Fetch rain data (runs async, non-blocking)
        fetchAndDrawRain(map);

        // Refresh rain every 15 minutes
        setInterval(() => fetchAndDrawRain(map), 15 * 60 * 1000);

        console.log('[map-patch v3.0] ✓ responsive + outside-PAR rain + TC markers');
    }

    // Inject CSS immediately (before DOM is ready is fine for <head>)
    injectResponsiveOverride();

})();