// ═══════════════════════════════════════════════════════════════════
// TYPHOON TRACKER — MAP PATCH v2
// Apply to index.php: find each function by name and replace it.
//
//  1. Replace: async function fetchFromJTWC()   → lines below ↓
//  2. Replace: function updateMapMarkers()       → lines below ↓
//  3. Add NEW: function buildConePolygon()       → part of block 2
//
// These changes make typhoons appear at REAL coordinates so storms
// OUTSIDE the PAR boundary are tracked and plotted correctly.
// ═══════════════════════════════════════════════════════════════════


// ─── REPLACEMENT 1 of 2 ─────────────────────────────────────────────────────
// Find: async function fetchFromJTWC() {
// Replace the ENTIRE function (up to its closing brace) with this:

async function fetchFromJTWC() {
    // ── Try GDACS first — reliable GeoJSON with real lat/lon
    try {
        const gRes = await fetch(
            'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red',
            { signal: AbortSignal.timeout(8000) }
        );
        if (gRes.ok) {
            const gJson = await gRes.json();
            const storms = [];
            (gJson.features || []).forEach(f => {
                const p = f.properties || {};
                const coords = f.geometry?.coordinates;
                if (!coords) return;
                const [lon, lat] = coords;
                if (lon < 95 || lon > 185 || lat < -5 || lat > 45) return;
                const windKts = parseFloat(p.maxwind || p.wind_max || 0);
                const windKmh = windKts > 0 ? Math.round(windKts * 1.852) : parseInt(p.windspeed || 0);
                storms.push({
                    name: (p.name || p.eventname || 'UNNAMED').toUpperCase(),
                    windSpeed: windKmh,
                    lat: parseFloat(lat),
                    lon: parseFloat(lon),
                    distance: Math.round(haversineKm(APP.userLat, APP.userLon, lat, lon)),
                    direction: null,
                    source: 'GDACS'
                });
            });
            if (storms.length > 0) return storms;
        }
    } catch (e) { /* fall through to JTWC */ }

    // ── Fallback: JTWC ATCF XML with coordinate parsing
    try {
        const res = await fetch(
            'https://www.nhc.noaa.gov/productfeeds/jtwc_atcf.xml',
            { signal: AbortSignal.timeout(8000) }
        );
        if (!res.ok) return null;
        const text = await res.text();
        const parser = new DOMParser();
        const xml = parser.parseFromString(text, 'text/xml');
        const items = xml.querySelectorAll('item');
        if (!items.length) return [];
        const storms = [];
        items.forEach(item => {
            const title = item.querySelector('title')?.textContent || '';
            const desc = item.querySelector('description')?.textContent || '';
            if (!title.match(/\bW\b|\bWP\b/i) &&
                !desc.toLowerCase().includes('western pacific') &&
                !desc.toLowerCase().includes('philippine')) return;
            // Parse real coordinates from the description text
            const cM = desc.match(/(\d+\.?\d*)\s*[°\s]*([NS])[\s,\/]+(\d+\.?\d*)\s*[°\s]*([EW])/i);
            let tLat = null, tLon = null;
            if (cM) {
                tLat = parseFloat(cM[1]); if (cM[2].toUpperCase() === 'S') tLat = -tLat;
                tLon = parseFloat(cM[3]); if (cM[4].toUpperCase() === 'W') tLon = -tLon;
            }
            const wM = desc.match(/(\d+)\s*kt/i);
            const pM = desc.match(/(\d{3,4})\s*(?:mb|hpa)/i);
            storms.push({
                name: title.replace(/^(Tropical\s+)?(Cyclone|Typhoon|Storm|Depression)\s*/i, '').trim() || 'UNNAMED',
                windSpeed: wM ? Math.round(parseInt(wM[1]) * 1.852) : 0,
                lat: tLat,
                lon: tLon,
                distance: (tLat !== null && tLon !== null)
                    ? Math.round(haversineKm(APP.userLat, APP.userLon, tLat, tLon))
                    : 9999,
                direction: extractDirection(desc),
                pressure: pM ? parseInt(pM[1]) : null,
                source: 'JTWC'
            });
        });
        return storms;
    } catch (e) { return null; }
}

// ─── REPLACEMENT 2 of 2 ─────────────────────────────────────────────────────
// Find: function updateMapMarkers() {
// Replace the ENTIRE function (up to its closing brace) with this.
// NOTE: buildConePolygon() is included here — paste it right after updateMapMarkers.

function updateMapMarkers() {
    // ── User location dot
    if (APP.userMarker) APP.map.removeLayer(APP.userMarker);
    const userIcon = L.divIcon({
        html: `<div style="width:12px;height:12px;border-radius:50%;background:#e11d48;
                   border:2.5px solid #fff;
                   box-shadow:0 0 0 5px rgba(225,29,72,.2),0 2px 8px rgba(225,29,72,.5)"></div>`,
        className: '', iconAnchor: [6, 6]
    });
    APP.userMarker = L.marker([APP.userLat, APP.userLon], { icon: userIcon, zIndexOffset: 1000 })
        .addTo(APP.map)
        .bindPopup(`<div style="font-family:'Sora',sans-serif">
            <strong>📍 Your Location</strong><br>
            <span style="font-size:11px;color:#64748b">${APP.userLocation}</span><br>
            <span style="font-size:10px;font-family:'DM Mono',monospace">
                ${APP.userLat.toFixed(4)}°N, ${APP.userLon.toFixed(4)}°E
            </span></div>`);

    // ── Clear previous typhoon overlays
    if (APP.typhoonLayer) APP.typhoonLayer.clearLayers();
    else APP.typhoonLayer = L.layerGroup().addTo(APP.map);
    APP.typhoonMarkers = [];

    if (!APP.typhoonData || !APP.typhoonData.length) return;

    APP.typhoonData.forEach(t => {
        const dist = parseFloat(t.distance);
        const wind = parseFloat(t.windSpeed);

        // ── Use REAL lat/lon if available; estimate from bearing only as last resort
        let tLat, tLon;
        if (t.lat != null && t.lon != null && !isNaN(t.lat) && !isNaN(t.lon)) {
            tLat = parseFloat(t.lat);
            tLon = parseFloat(t.lon);
        } else {
            if (t._bearing === undefined) t._bearing = Math.random() * 360;
            const rad = t._bearing * Math.PI / 180;
            tLat = APP.userLat + (dist / 111) * Math.cos(rad);
            tLon = APP.userLon + (dist / (111 * Math.cos(APP.userLat * Math.PI / 180))) * Math.sin(rad);
        }

        // ── Intensity classification
        const isSuper = wind >= 185;
        const isTyphoon = wind >= 118;
        const isStorm = wind >= 62;
        const color = isSuper ? '#ff3860' : isTyphoon ? '#f97316' : isStorm ? '#f59e0b' : '#60a5fa';
        const glow = isSuper ? 'rgba(255,56,96,.55)' : isTyphoon ? 'rgba(249,115,22,.5)'
            : isStorm ? 'rgba(245,158,11,.45)' : 'rgba(96,165,250,.4)';
        const sz = isSuper ? 38 : isTyphoon ? 32 : isStorm ? 26 : 20;
        const label = isSuper ? 'SUPER TYPHOON' : isTyphoon ? 'TYPHOON'
            : isStorm ? 'TROPICAL STORM' : 'TROPICAL DEPRESSION';

        // ── Is it inside PAR? (5°N–25°N, 115°E–135°E)
        const inPAR = (tLat >= 5 && tLat <= 25 && tLon >= 115 && tLon <= 135);
        const parTag = inPAR
            ? `<span style="color:#ef4444;font-size:9px;font-weight:700">⚠ INSIDE PAR</span>`
            : `<span style="color:#94a3b8;font-size:9px">Outside PAR — Monitoring</span>`;

        // ── Animated eye icon
        const tIcon = L.divIcon({
            html: `<div style="width:${sz}px;height:${sz}px;border-radius:50%;
                background:radial-gradient(circle at 38% 38%,${color}cc,${color}44);
                border:2px solid ${color};
                box-shadow:0 0 0 5px ${glow},0 0 20px ${glow};
                display:flex;align-items:center;justify-content:center;
                animation:tt-spin 4s linear infinite;cursor:pointer">
                <div style="width:${Math.round(sz * .32)}px;height:${Math.round(sz * .32)}px;
                    border-radius:50%;background:#fff;opacity:.9"></div>
            </div>`,
            className: '', iconAnchor: [sz / 2, sz / 2]
        });

        const popup = `<div style="font-family:'Sora',sans-serif;min-width:210px">
            <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:4px">🌀 ${t.name}</div>
            <div style="display:flex;align-items:center;gap:6px;margin-bottom:8px;flex-wrap:wrap">
                <span style="padding:2px 8px;border-radius:20px;
                    background:${color}22;border:1px solid ${color}66;
                    color:${color};font-size:10px;font-weight:700">${label}</span>
                ${parTag}
            </div>
            <div style="display:flex;flex-direction:column;gap:4px;font-size:11px;color:#475569">
                <div>💨 <strong>${wind} km/h</strong> max winds</div>
                <div>📍 <strong>${dist} km</strong> from your location</div>
                <div>🗺️ <strong>${tLat.toFixed(1)}°N, ${tLon.toFixed(1)}°E</strong></div>
                ${t.direction ? `<div>🧭 Moving: <strong>${t.direction}</strong></div>` : ''}
                ${t.pressure ? `<div>🌡️ <strong>${t.pressure} hPa</strong></div>` : ''}
            </div>
            <div style="margin-top:6px;padding-top:5px;border-top:1px solid #e2e8f0;
                font-size:10px;color:#94a3b8">Source: ${t.source || 'PAGASA/JTWC'}</div>
        </div>`;

        const marker = L.marker([tLat, tLon], { icon: tIcon, zIndexOffset: 900 }).bindPopup(popup);
        APP.typhoonLayer.addLayer(marker);
        APP.typhoonMarkers.push(marker);

        // ── Past track (solid line + fading dots)
        if (t.track && t.track.length > 1) {
            APP.typhoonLayer.addLayer(L.polyline(
                t.track.map(p => [p.lat, p.lon]),
                { color, weight: 2.5, opacity: 0.8 }
            ));
            t.track.forEach((pt, idx) => {
                if (idx === t.track.length - 1) return;
                const s = 6;
                APP.typhoonLayer.addLayer(L.marker([pt.lat, pt.lon], {
                    icon: L.divIcon({
                        html: `<div style="width:${s}px;height:${s}px;border-radius:50%;
                                   background:${color};
                                   opacity:${(0.25 + (idx / t.track.length) * 0.55).toFixed(2)};
                                   border:1px solid ${color}88"></div>`,
                        className: '', iconAnchor: [s / 2, s / 2]
                    }),
                    interactive: false
                }));
            });
        }

        // ── Forecast track (dashed) + cone + time markers
        if (t.forecast && t.forecast.length > 0) {
            const fCoords = [[tLat, tLon], ...t.forecast.map(p => [p.lat, p.lon])];
            APP.typhoonLayer.addLayer(L.polyline(fCoords,
                { color, weight: 2, opacity: 0.5, dashArray: '6,5' }
            ));
            if (t.forecast.length >= 2) {
                const cone = buildConePolygon([{ lat: tLat, lon: tLon }, ...t.forecast]);
                if (cone.length > 2) APP.typhoonLayer.addLayer(L.polygon(cone, {
                    color, fillColor: color, fillOpacity: 0.07,
                    weight: 0.5, opacity: 0.3, interactive: false
                }));
            }
            t.forecast.forEach((pt, idx) => {
                const hrs = (idx + 1) * 24;
                APP.typhoonLayer.addLayer(L.marker([pt.lat, pt.lon], {
                    icon: L.divIcon({
                        html: `<div style="display:flex;flex-direction:column;align-items:center;pointer-events:none">
                            <div style="width:9px;height:9px;border-radius:50%;background:transparent;
                                border:2px solid ${color};opacity:.7"></div>
                            <div style="font-size:8px;font-weight:700;color:${color};
                                font-family:'DM Mono',monospace;margin-top:1px;
                                text-shadow:0 1px 3px rgba(0,0,0,.9);white-space:nowrap">+${hrs}h</div>
                        </div>`,
                        className: '', iconAnchor: [4, 4]
                    }),
                    interactive: false
                }));
            });
        }

        // ── Wind radius danger circle
        const wRadKm = wind >= 185 ? 280 : wind >= 118 ? 200 : wind >= 88 ? 140 : wind >= 62 ? 90 : 55;
        APP.typhoonLayer.addLayer(L.circle([tLat, tLon], {
            radius: wRadKm * 1000, color, fillColor: color,
            fillOpacity: 0.04, weight: 1, dashArray: '3,5', opacity: 0.4, interactive: false
        }));

        // ── Dotted line from user to typhoon when close enough
        if (dist < 800) {
            APP.typhoonLayer.addLayer(L.polyline(
                [[APP.userLat, APP.userLon], [tLat, tLon]],
                { color, weight: 1, opacity: 0.2, dashArray: '4,7' }
            ));
        }
    });

    // ── Auto-fit map to show ALL typhoons + user position
    const pts = [
        [APP.userLat, APP.userLon],
        ...APP.typhoonData
            .filter(t => t.lat != null && t.lon != null && !isNaN(t.lat))
            .map(t => [parseFloat(t.lat), parseFloat(t.lon)])
    ];
    if (pts.length > 1) {
        try {
            APP.map.fitBounds(L.latLngBounds(pts), { padding: [70, 70], maxZoom: 7, animate: true });
        } catch (e) { }
    }
}

function buildConePolygon(points) {
    if (points.length < 2) return [];
    const L = [], R = [];
    points.forEach((pt, i) => {
        const spread = (50 + i * 40) / 111;
        const next = points[Math.min(i + 1, points.length - 1)];
        const angle = Math.atan2(next.lon - pt.lon, next.lat - pt.lat);
        L.push([pt.lat + Math.cos(angle) * spread, pt.lon - Math.sin(angle) * spread]);
        R.push([pt.lat - Math.cos(angle) * spread, pt.lon + Math.sin(angle) * spread]);
    });
    return [...L, ...R.reverse()];
}