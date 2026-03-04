'use strict';

// ─── GLOBAL STATE ────────────────────────────────────────────────────────────
let weatherData = null;
let typhoonData = [];
let userLocation = 'Detecting location...';
let userCoords = { lat: 7.0972, lng: 125.6089 }; // Nagsil Village, Brgy Centro Agdao
let map = null;
let markers = [];
let locationAccuracy = 'approximate';

const CHAT_STORAGE_KEY = 'typhoon_chat_private_session';

// ─── BOOT ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    initMap();
    detectLocation();
    fetchTyphoons();
    loadChatHistory();
    updateDateTime();
    setInterval(updateDateTime, 1000);
    setTimeout(initializeMLFeatures, 2500);
});

// ─── DATETIME ────────────────────────────────────────────────────────────────
function updateDateTime() {
    const el = document.getElementById('currentDateTime');
    if (!el) return;
    el.textContent = new Date().toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', weekday: 'long', year: 'numeric',
        month: 'long', day: 'numeric', hour: '2-digit',
        minute: '2-digit', second: '2-digit', hour12: true
    });
}

// ─── MAP ─────────────────────────────────────────────────────────────────────
function initMap() {
    map = L.map('map').setView([7.0972, 125.6089], 15);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap', maxZoom: 19
    }).addTo(map);
}

function detectLocation() {
    const el = document.getElementById('userLocation');
    if (el) el.innerHTML = '📍 Nagsil Village, Brgy Centro Agdao, Davao City <button onclick="enableGPS()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Use GPS</button>';
    
    userCoords = { lat: 7.0972, lng: 125.6089 };
    userLocation = 'Nagsil Village, Brgy Centro Agdao, Davao City';
    
    map.setView([7.0972, 125.6089], 15);
    L.marker([7.0972, 125.6089], {
        icon: L.divIcon({
            className: '',
            html: `<div style="background:#0d6efd;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(13,110,253,0.5)"></div>`,
            iconSize: [16, 16]
        })
    }).addTo(map).bindPopup('<strong>📍 Nagsil Village, Brgy Centro Agdao</strong><br>Davao City').openPopup();
    
    fetchWeather();
}

function enableGPS() {
    if (!navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(
        async (position) => {
            const { latitude: lat, longitude: lng, accuracy } = position.coords;
            userCoords = { lat, lng };
            map.setView([lat, lng], 15);
            L.marker([lat, lng], {
                icon: L.divIcon({
                    className: '',
                    html: `<div style="background:#28a745;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(40,167,69,0.5)"></div>`,
                    iconSize: [16, 16]
                })
            }).addTo(map).bindPopup(`<strong>📍 GPS Location</strong><br>Accuracy: ~${Math.round(accuracy)}m`).openPopup();
            await fetchLocationDetails(lat, lng);
            fetchWeather();
        },
        (err) => console.error('GPS error:', err),
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
    );
}

function requestLocationPermission() { enableGPS(); }

function requestLocationPermission() { detectLocation(); }

async function fetchLocationDetails(lat, lng) {
    try {
        const r = await fetch(`https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lng}&localityLanguage=en`);
        if (!r.ok) throw new Error('failed');
        const d = await r.json();
        const parts = [];
        if (d.locality) parts.push(d.locality);
        if (d.city && d.city !== d.locality) parts.push(d.city);
        if (d.principalSubdivision) parts.push(d.principalSubdivision);
        if (d.countryName) parts.push(d.countryName);
        userLocation = parts.join(', ') || 'Unknown Location';
        const acc = locationAccuracy === 'precise' ? '✓ GPS' : '≈ GPS';
        document.getElementById('userLocation').textContent = `📍 ${userLocation} (${acc})`;
    } catch {
        userLocation = `${lat.toFixed(4)}°N, ${lng.toFixed(4)}°E`;
        document.getElementById('userLocation').textContent = `📍 ${userLocation} (GPS)`;
    }
}

async function fetchIPLocation() {
    try {
        const r = await fetch('https://ipapi.co/json/');
        if (!r.ok) throw new Error('failed');
        const d = await r.json();
        userCoords = { lat: d.latitude, lng: d.longitude };
        userLocation = [d.city, d.region, d.country_name].filter(Boolean).join(', ');
        document.getElementById('userLocation').innerHTML = `📍 ${userLocation} (IP-based) <button onclick="requestLocationPermission()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Use GPS</button>`;
        map.setView([userCoords.lat, userCoords.lng], 12);
        L.marker([userCoords.lat, userCoords.lng], {
            icon: L.divIcon({ className: '', html: '<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(255,193,7,0.5)"></div>', iconSize: [16, 16] })
        }).addTo(map).bindPopup('<strong>📍 Your Location</strong><br>(IP-based)');
        fetchWeather();
    } catch { useDefaultLocation(); }
}

function useDefaultLocation() {
    userCoords = { lat: 7.0972, lng: 125.6089 };
    userLocation = 'Nagsil Village, Brgy Centro Agdao, Davao City';
    document.getElementById('userLocation').innerHTML = `📍 ${userLocation} (Default) <button onclick="requestLocationPermission()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Use My Location</button>`;
    map.setView([userCoords.lat, userCoords.lng], 11);
    L.marker([userCoords.lat, userCoords.lng], {
        icon: L.divIcon({ className: '', html: '<div style="background:#6c757d;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(108,117,125,0.5)"></div>', iconSize: [16, 16] })
    }).addTo(map).bindPopup('<strong>📍 Default Location</strong><br>Davao City');
    fetchWeather();
}

function handleLocationError(error) {
    if (error) console.error('Location error:', error.message);
    fetchIPLocation();
}

// ─── WEATHER ─────────────────────────────────────────────────────────────────
async function fetchWeather() {
    try {
        const r = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${userCoords.lat}&longitude=${userCoords.lng}&current_weather=true&hourly=relativehumidity_2m,pressure_msl&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum&timezone=auto&forecast_days=7`);
        const d = await r.json();
        if (d.current_weather) {
            weatherData = {
                windSpeed: d.current_weather.windspeed.toFixed(1),
                temperature: d.current_weather.temperature.toFixed(1),
                pressure: d.hourly?.pressure_msl?.[0]?.toFixed(0) ?? '1012',
                humidity: d.hourly?.relativehumidity_2m?.[0] ?? '75'
            };
            updateWeatherDisplay();
        }
        if (d.daily) renderForecast(d.daily);
    } catch (e) {
        console.error('Weather fetch error:', e);
        weatherData = { windSpeed: '15.0', temperature: '28.0', pressure: '1012', humidity: '75' };
        updateWeatherDisplay();
    }
}

function updateWeatherDisplay() {
    if (!weatherData) return;
    const wind = parseFloat(weatherData.windSpeed);
    const temp = parseFloat(weatherData.temperature);
    const pres = parseFloat(weatherData.pressure);
    const hum = parseFloat(weatherData.humidity);

    const set = (id, val, unit) => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = `<span class="value-number">${val}</span><span class="value-unit">${unit}</span>`;
    };
    set('windSpeed', weatherData.windSpeed, 'km/h');
    set('temperature', weatherData.temperature, '°C');
    set('pressure', weatherData.pressure, 'hPa');
    set('humidity', weatherData.humidity, '%');

    const setStatus = (id, text, cls) => {
        const el = document.getElementById(id);
        if (el) { el.textContent = text; el.className = 'weather-status ' + cls; }
    };

    if (wind > 118) setStatus('windStatus', 'Typhoon Force', 'danger');
    else if (wind > 88) setStatus('windStatus', 'Storm Force', 'danger');
    else if (wind > 60) setStatus('windStatus', 'Strong Wind', 'warning');
    else if (wind > 39) setStatus('windStatus', 'Moderate Wind', 'warning');
    else setStatus('windStatus', 'Calm', 'safe');

    if (temp > 35) setStatus('tempStatus', 'Very Hot', 'danger');
    else if (temp > 32) setStatus('tempStatus', 'Hot', 'warning');
    else if (temp < 18) setStatus('tempStatus', 'Cool', 'normal');
    else setStatus('tempStatus', 'Comfortable', 'safe');

    if (pres < 1000) setStatus('pressureStatus', 'Low - Storm Risk', 'danger');
    else if (pres < 1010) setStatus('pressureStatus', 'Below Normal', 'warning');
    else if (pres > 1020) setStatus('pressureStatus', 'High Pressure', 'safe');
    else setStatus('pressureStatus', 'Normal', 'normal');

    if (hum > 80) setStatus('humidityStatus', 'Very Humid', 'warning');
    else if (hum > 60) setStatus('humidityStatus', 'Humid', 'normal');
    else if (hum < 30) setStatus('humidityStatus', 'Dry', 'normal');
    else setStatus('humidityStatus', 'Comfortable', 'safe');

    const lu = document.getElementById('lastUpdate');
    if (lu && !lu.textContent.startsWith('Updated')) {
        lu.textContent = `Updated at ${new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })}`;
    }
}

// ─── FORECAST ────────────────────────────────────────────────────────────────
function renderForecast(daily) {
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    let html = '';
    for (let i = 0; i < 7; i++) {
        const date = new Date(daily.time[i]);
        const dayName = i === 0 ? 'Today' : i === 1 ? 'Tomorrow' : days[date.getDay()];
        const maxTemp = Math.round(daily.temperature_2m_max[i]);
        const minTemp = Math.round(daily.temperature_2m_min[i]);
        const precip = daily.precipitation_sum[i] || 0;
        const precipProb = daily.precipitation_probability_max[i] || 0;
        const icon = precip > 10 ? '⛈️' : precip > 5 ? '🌧️' : precipProb > 50 ? '⛅' : precipProb > 20 ? '🌤️' : '☀️';

        html += `<div style="background:#ffffff;border:1px solid #d1d5db;border-radius:8px;padding:1.5rem;text-align:center;transition:all 0.2s;cursor:pointer"
            onmouseover="this.style.borderColor='#9ca3af';this.style.transform='translateY(-2px)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'"
            onmouseout="this.style.borderColor='#d1d5db';this.style.transform='translateY(0)';this.style.boxShadow='none'"
            onclick="showForecastDetail(${i},'${dayName}',${maxTemp},${minTemp},${precip},${precipProb},'${icon}','${daily.time[i]}')">
            <div style="font-size:0.875rem;font-weight:600;color:#6b7280;margin-bottom:0.75rem">${dayName}</div>
            <div style="font-size:3rem;margin:1rem 0">${icon}</div>
            <div style="font-size:1.5rem;font-weight:700;color:#111827;margin-bottom:0.25rem">${maxTemp}°C</div>
            <div style="font-size:1rem;color:#6b7280;margin-bottom:0.75rem">${minTemp}°C</div>
            <div style="display:flex;align-items:center;justify-content:center;gap:0.5rem;font-size:0.875rem">
                <span style="color:#6b7280;font-weight:600">💧 ${precipProb}%</span>
                <span style="color:#9ca3af">|</span>
                <span style="color:#6b7280">${precip.toFixed(1)}mm</span>
            </div>
            <div style="margin-top:0.75rem;font-size:0.75rem;color:#9ca3af;font-weight:500">Click for details →</div>
        </div>`;
    }
    document.getElementById('forecastDays').innerHTML = html;
}

// ─── TYPHOON FETCH (via PHP proxy — no CORS) ──────────────────────────────────
async function fetchTyphoons() {
    const listEl = document.getElementById('typhoonList');
    if (!listEl) return;

    listEl.innerHTML = `
        <div class="empty-state" style="padding:20px;text-align:center">
            <div style="font-size:24px;margin-bottom:8px">🔄</div>
            <div style="font-size:12px;color:#64748b">Fetching live data from PAGASA…</div>
        </div>`;

    try {
        const res = await fetch('pagasa_proxy.php', {
            signal: AbortSignal.timeout(20000)
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();

        // Update timestamp badge
        const lu = document.getElementById('lastUpdate');
        if (lu) {
            const t = data.fetched_at ? new Date(data.fetched_at).toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' }) : new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit' });
            const src = data.source || 'Unknown';
            const cached = data.cached ? ' · cached' : '';
            lu.textContent = `Updated ${t} · ${src}${cached}`;
        }

        if (data.typhoons && data.typhoons.length > 0) {
            typhoonData = data.typhoons;
            renderTyphoonList(typhoonData);
            addTyphoonMarkers();
        } else {
            typhoonData = [];
            const bulletin = data.bulletin || 'No active tropical cyclones in or near the Philippine Area of Responsibility.';
            listEl.innerHTML = `
                <div style="padding:14px">
                    <div style="display:flex;align-items:flex-start;gap:10px;padding:13px 15px;
                                background:#d1fae5;border:1px solid #6ee7b7;border-radius:10px;margin-bottom:10px">
                        <span style="font-size:16px;flex-shrink:0">✅</span>
                        <div>
                            <div style="font-size:12px;font-weight:700;color:#065f46;margin-bottom:3px">All Clear — No Active Typhoons</div>
                            <div style="font-size:11px;color:#065f46;line-height:1.6">${bulletin}</div>
                        </div>
                    </div>
                    <div style="display:flex;gap:6px;flex-wrap:wrap">
                        <a href="https://bagong.pagasa.dost.gov.ph" target="_blank" style="flex:1;text-align:center;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#1c3461;font-size:11px;font-weight:600">PAGASA ↗</a>
                        <a href="https://www.jma.go.jp/en/typh/" target="_blank" style="flex:1;text-align:center;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#1c3461;font-size:11px;font-weight:600">JMA ↗</a>
                        <a href="https://www.jtwc.navy.mil" target="_blank" style="flex:1;text-align:center;padding:8px 10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;text-decoration:none;color:#1c3461;font-size:11px;font-weight:600">JTWC ↗</a>
                    </div>
                </div>`;
        }

    } catch (err) {
        console.error('fetchTyphoons error:', err.message);
        typhoonData = [];
        listEl.innerHTML = `
            <div style="padding:14px">
                <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;
                            background:#fff5f5;border:1px solid #fca5a5;border-radius:10px;margin-bottom:10px">
                    <span style="font-size:14px;flex-shrink:0">⚠️</span>
                    <div>
                        <div style="font-size:11px;font-weight:700;color:#7f1d1d;margin-bottom:3px">Could Not Reach Proxy</div>
                        <div style="font-size:11px;color:#7f1d1d;line-height:1.55">${err.message === 'Failed to fetch'
                ? 'pagasa_proxy.php not found. Make sure it\'s in the same folder as this page.'
                : err.message
            }</div>
                    </div>
                </div>
                <button onclick="fetchTyphoons()" style="width:100%;padding:9px;background:#0d1b36;color:#fff;border:none;border-radius:8px;font-size:12px;font-weight:600;cursor:pointer;margin-bottom:8px">
                    🔄 Retry
                </button>
                <div style="display:flex;gap:6px">
                    <a href="https://bagong.pagasa.dost.gov.ph" target="_blank" style="flex:1;text-align:center;padding:7px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;text-decoration:none;color:#1c3461;font-size:10px;font-weight:600">PAGASA ↗</a>
                    <a href="https://www.jtwc.navy.mil" target="_blank" style="flex:1;text-align:center;padding:7px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:7px;text-decoration:none;color:#1c3461;font-size:10px;font-weight:600">JTWC ↗</a>
                </div>
            </div>`;
    }
}

function renderTyphoonList(list) {
    const el = document.getElementById('typhoonList');
    if (!el) return;
    if (!list || !list.length) {
        el.innerHTML = '<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div></div>';
        return;
    }
    el.innerHTML = list.map(t => {
        const d = parseFloat(t.distance);
        const lvl = d < 300 ? 'danger' : d < 600 ? 'warning' : 'info';
        const badgeText = d < 300 ? '⚠️ VERY CLOSE' : d < 600 ? '⚠️ CLOSE' : 'ℹ️ MONITORING';
        const badgeCls = d < 300 ? 'badge-danger' : d < 600 ? 'badge-warning' : 'badge-info';
        const sig = t.signal > 0 ? `<span style="font-size:10px;margin-left:6px;padding:1px 6px;border-radius:6px;background:${t.signal >= 4 ? '#fee2e2' : t.signal >= 3 ? '#fef3c7' : '#dbeafe'};color:${t.signal >= 4 ? '#7f1d1d' : t.signal >= 3 ? '#92400e' : '#1e40af'};font-weight:700">Signal #${t.signal}</span>` : '';
        const src = t.source ? `<span style="font-size:9px;color:#94a3b8;margin-left:4px">via ${t.source}</span>` : '';
        return `<div class="typhoon-item ${lvl}" onclick="focusTyphoon(${t.lat || 12.88},${t.lon || t.lng || 121.77})" style="cursor:pointer">
            <span class="badge ${badgeCls}">${badgeText}</span>
            <div class="typhoon-name">🌀 ${t.name}${sig}${src}</div>
            <div class="typhoon-details">
                <div class="detail-item"><div class="detail-label">Wind Speed</div><div class="detail-value">${t.windSpeed} km/h</div></div>
                <div class="detail-item"><div class="detail-label">Distance</div><div class="detail-value">${Math.round(d)} km</div></div>
                ${t.direction ? `<div class="detail-item"><div class="detail-label">Direction</div><div class="detail-value">${t.direction}</div></div>` : ''}
            </div>
        </div>`;
    }).join('');
}

function addTyphoonMarkers() {
    // Clear old typhoon markers
    markers.forEach(m => map.removeLayer(m));
    markers = [];

    typhoonData.forEach(t => {
        const lat = parseFloat(t.lat) || 12.88;
        const lng = parseFloat(t.lon || t.lng) || 121.77;
        const color = t.distance < 300 ? '#dc3545' : t.distance < 600 ? '#ffc107' : '#0d6efd';

        const m = L.marker([lat, lng], {
            icon: L.divIcon({
                className: '',
                html: `<div style="background:${color};width:22px;height:22px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;font-size:12px">🌀</div>`,
                iconSize: [22, 22]
            })
        }).addTo(map).bindPopup(`<strong>${t.name}</strong><br>${t.windSpeed} km/h winds<br>${Math.round(t.distance)} km away${t.source ? '<br><small>Source: ' + t.source + '</small>' : ''}`);
        markers.push(m);

        const circle = L.circle([lat, lng], {
            color, fillColor: color, fillOpacity: 0.08,
            radius: Math.min(t.distance * 800, 500000), weight: 2
        }).addTo(map);
        markers.push(circle);
    });
}

function focusTyphoon(lat, lng) {
    map.setView([lat, lng], 8, { animate: true });
}

function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371, dLat = (lat2 - lat1) * Math.PI / 180, dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

// ─── FORECAST MODAL ───────────────────────────────────────────────────────────
function showForecastDetail(dayIndex, dayName, maxTemp, minTemp, precip, precipProb, icon, dateStr) {
    const date = new Date(dateStr);
    const formattedDate = date.toLocaleDateString('en-US', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

    let weatherCondition, weatherAdvice;
    if (precip > 10) { weatherCondition = 'Heavy Rain Expected'; weatherAdvice = '⚠️ Bring an umbrella and waterproof gear. Possible flooding in low-lying areas.'; }
    else if (precip > 5) { weatherCondition = 'Rainy'; weatherAdvice = '🌧️ Expect rain showers. Keep an umbrella handy.'; }
    else if (precipProb > 50) { weatherCondition = 'Partly Cloudy with Rain Chance'; weatherAdvice = '⛅ Rain is likely. Prepare for wet weather.'; }
    else if (precipProb > 20) { weatherCondition = 'Partly Cloudy'; weatherAdvice = '🌤️ Mostly pleasant with some clouds.'; }
    else { weatherCondition = 'Clear & Sunny'; weatherAdvice = '☀️ Perfect weather! Don\'t forget sunscreen.'; }

    let tempAdvice;
    if (maxTemp > 35) tempAdvice = '🔥 Extreme heat warning! Stay hydrated and avoid prolonged sun exposure.';
    else if (maxTemp > 32) tempAdvice = '🌡️ Hot day ahead. Drink plenty of water.';
    else if (maxTemp < 20) tempAdvice = '🧥 Cool weather. Consider bringing a light jacket.';
    else tempAdvice = '😊 Comfortable temperature expected.';

    const modal = document.getElementById('forecastModal');
    document.getElementById('modalDayName').textContent = `${dayName} - ${formattedDate}`;
    document.getElementById('modalContent').innerHTML = `
        <div style="text-align:center;margin:2rem 0">
            <div style="font-size:5rem;margin-bottom:1rem">${icon}</div>
            <div style="font-size:1.5rem;font-weight:700;color:#1c1e21;margin-bottom:0.5rem">${weatherCondition}</div>
        </div>
        <div style="background:#f8f9fa;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;border:1px solid #e4e6eb">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">Temperature Range</h3>
            <div style="display:flex;justify-content:space-around">
                <div style="text-align:center"><div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">High</div><div style="font-size:2.5rem;font-weight:700;color:#1c1e21">${maxTemp}°C</div></div>
                <div style="text-align:center"><div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Low</div><div style="font-size:2.5rem;font-weight:700;color:#1c1e21">${minTemp}°C</div></div>
            </div>
        </div>
        <div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">💧 Precipitation</h3>
            <div style="display:flex;justify-content:space-around">
                <div style="text-align:center"><div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Probability</div><div style="font-size:2rem;font-weight:700;color:#1c1e21">${precipProb}%</div></div>
                <div style="text-align:center"><div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Amount</div><div style="font-size:2rem;font-weight:700;color:#1c1e21">${precip.toFixed(1)} mm</div></div>
            </div>
        </div>
        <div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:12px;padding:1.5rem;margin-bottom:1rem">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">📋 Weather Advice</h3>
            <p style="color:#4b5563;margin-bottom:0.75rem;font-size:0.9375rem;line-height:1.6">${weatherAdvice}</p>
            <p style="color:#4b5563;font-size:0.9375rem;line-height:1.6">${tempAdvice}</p>
        </div>
        <div style="background:#f8f9fa;border-radius:8px;padding:1rem;text-align:center;border:1px solid #e4e6eb">
            <p style="font-size:0.8125rem;color:#6b7280;margin:0">ℹ️ Forecast data is updated regularly. Check back for the latest information.</p>
        </div>`;

    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeForecastModal() {
    document.getElementById('forecastModal').style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.onclick = e => { if (e.target === document.getElementById('forecastModal')) closeForecastModal(); };
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeForecastModal(); closeClearChatModal(); } });

// ─── ML FEATURES ─────────────────────────────────────────────────────────────
function initializeMLFeatures() {
    if (!window.mlPredictor) { console.error('❌ ML Predictor not loaded'); return; }
    console.log('✅ ML features ready');
}

async function runMLAnalysis() {
    if (!weatherData) { showMLResult('⚠️ Weather data not available yet.'); return; }
    const mlContainer = document.getElementById('mlAnalysis');
    if (!mlContainer) return;

    mlContainer.innerHTML = '<div style="padding:1rem;text-align:center;color:#64748b"><div style="font-size:24px;margin-bottom:8px">⏳</div>Running comprehensive weather analysis...</div>';
    await new Promise(r => setTimeout(r, 1200));

    if (!window.mlPredictor) { showMLResult('❌ ML system not loaded. Refresh the page.'); return; }

    try {
        const report = typhoonData.length > 0
            ? window.mlPredictor.generateAnalysisReport(typhoonData[0], weatherData, userCoords)
            : window.mlPredictor.generateWeatherAnalysisReport(weatherData, userCoords);
        displayMLReport(report);
    } catch (err) {
        showMLResult(`⚠️ Analysis error: ${err.message}`);
    }
}

function displayMLReport(report) {
    const mlContainer = document.getElementById('mlAnalysis');
    if (!mlContainer) return;
    const riskColor = getRiskColor(report.riskAssessment.level);
    const riskBg = getRiskBackground(report.riskAssessment.level);

    mlContainer.innerHTML = `
        <div style="background:${riskBg};border:2px solid ${riskColor};border-radius:12px;padding:1.5rem;margin-bottom:1rem;text-align:center">
            <div style="font-size:0.875rem;color:#6b7280;font-weight:600;margin-bottom:0.5rem">OVERALL RISK LEVEL</div>
            <div style="font-size:3rem;font-weight:700;color:${riskColor};margin:0.5rem 0">${report.riskAssessment.overallScore}</div>
            <div style="font-size:1.25rem;font-weight:600;color:${riskColor};margin-bottom:1rem">${report.riskAssessment.level}</div>
            <div style="font-size:0.9375rem;color:#4b5563;line-height:1.6;background:#fff;padding:1rem;border-radius:8px">${report.riskAssessment.recommendation}</div>
        </div>
        ${report.aiInsights?.length > 0 ? `
        <div style="background:#fffbf0;border:1px solid #ffc107;border-radius:8px;padding:1rem;margin-bottom:1rem">
            <div style="font-size:0.875rem;font-weight:600;color:#856404;margin-bottom:0.5rem">💡 AI INSIGHTS</div>
            ${report.aiInsights.map(i => `<div style="font-size:0.875rem;color:#856404;margin:0.5rem 0;padding-left:1rem;border-left:3px solid #ffc107">${i}</div>`).join('')}
        </div>` : ''}
        <div style="background:#f0f8ff;border:1px solid #0d6efd;border-radius:8px;padding:1rem;margin-bottom:1rem">
            <div style="font-size:0.875rem;font-weight:600;color:#1c1e21;margin-bottom:0.75rem">💧 RAINFALL PREDICTION</div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:0.75rem;text-align:center">
                <div><div style="font-size:0.75rem;color:#6b7280">24 Hours</div><div style="font-size:1.5rem;font-weight:700;color:#0d6efd">${report.rainfallForecast.expected24h}mm</div></div>
                <div><div style="font-size:0.75rem;color:#6b7280">48 Hours</div><div style="font-size:1.5rem;font-weight:700;color:#0d6efd">${report.rainfallForecast.expected48h}mm</div></div>
                <div><div style="font-size:0.75rem;color:#6b7280">Flood Risk</div><div style="font-size:1rem;font-weight:700;color:${report.rainfallForecast.floodRisk === 'high' ? '#dc3545' : report.rainfallForecast.floodRisk === 'moderate' ? '#ffc107' : '#28a745'};text-transform:uppercase">${report.rainfallForecast.floodRisk}</div></div>
            </div>
        </div>
        <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;padding:1rem">
            <div style="font-size:0.875rem;font-weight:600;color:#1c1e21;margin-bottom:0.75rem">📊 RISK FACTORS</div>
            ${report.riskAssessment.factors.map(f => {
        const pct = Math.min(100, (f.points / Math.max(report.riskAssessment.overallScore, 1)) * 100);
        const sc = f.severity === 'critical' ? '#dc3545' : f.severity === 'high' ? '#ffc107' : f.severity === 'moderate' ? '#0d6efd' : '#28a745';
        return `<div style="margin-bottom:0.75rem">
                    <div style="display:flex;justify-content:space-between;font-size:0.8125rem;margin-bottom:0.25rem">
                        <span style="color:#4b5563;font-weight:500">${f.factor}</span>
                        <span style="font-weight:700;color:${sc}">${f.points} pts (${f.severity.toUpperCase()})</span>
                    </div>
                    <div style="background:#e5e7eb;height:8px;border-radius:4px;overflow:hidden">
                        <div style="background:${sc};height:100%;width:${pct}%;transition:width 0.3s"></div>
                    </div>
                </div>`;
    }).join('')}
        </div>
        ${report.analysisType === 'typhoon' && report.intensityForecast ? `
        <div style="background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px;padding:1rem;margin-top:1rem">
            <div style="font-size:0.875rem;font-weight:600;color:#1c1e21;margin-bottom:0.75rem">⚡ INTENSITY FORECAST</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.75rem">
                <div><div style="font-size:0.75rem;color:#6b7280">Current Wind</div><div style="font-size:1.25rem;font-weight:700">${report.intensityForecast.currentWindSpeed} km/h</div></div>
                <div><div style="font-size:0.75rem;color:#6b7280">Predicted Change</div><div style="font-size:1.25rem;font-weight:700;color:${report.intensityForecast.predictedChange > 0 ? '#dc3545' : '#28a745'}">${report.intensityForecast.predictedChange > 0 ? '+' : ''}${report.intensityForecast.predictedChange.toFixed(1)} km/h</div></div>
            </div>
        </div>
        <div style="margin-top:1rem;padding:1rem;background:#f8f9fa;border:1px solid #e5e7eb;border-radius:8px">
            <div style="font-size:0.875rem;font-weight:600;margin-bottom:0.5rem">🎯 PREDICTED PATH (48h)</div>
            <button onclick="showPathOnMap()" style="width:100%;padding:0.75rem;background:#0d6efd;color:#fff;border:none;border-radius:6px;font-weight:600;cursor:pointer;font-size:0.875rem">📍 View Predicted Path on Map</button>
        </div>` : ''}
        <div style="margin-top:1rem;padding:0.75rem;background:#f0f8ff;border-radius:6px;font-size:0.75rem;color:#4b5563;text-align:center">
            ⏱️ Analysis generated at ${new Date(report.timestamp).toLocaleTimeString()}
        </div>`;

    window.currentMLReport = report;
}

function getRiskColor(level) {
    return { CRITICAL: '#dc3545', HIGH: '#fd7e14', MODERATE: '#ffc107', LOW: '#0d6efd' }[level] || '#28a745';
}
function getRiskBackground(level) {
    return { CRITICAL: '#fff5f5', HIGH: '#fff8f0', MODERATE: '#fffbf0', LOW: '#f0f8ff' }[level] || '#f0fff4';
}
function showMLResult(msg) {
    const el = document.getElementById('mlAnalysis');
    if (el) el.innerHTML = `<div style="padding:1rem;text-align:center;color:#64748b">${msg}</div>`;
}

function showPathOnMap() {
    if (!window.currentMLReport?.pathForecast) { alert('Path prediction not available'); return; }
    const { predictions, typhoonName } = window.currentMLReport.pathForecast;

    markers.forEach(m => { if (m._isPathMarker) map.removeLayer(m); });
    markers = markers.filter(m => !m._isPathMarker);

    const coords = predictions.map(p => [p.lat, p.lng]);
    const line = L.polyline(coords, { color: '#0d6efd', weight: 3, opacity: 0.7, dashArray: '10,10' }).addTo(map);
    line._isPathMarker = true; markers.push(line);

    predictions.forEach(p => {
        const m = L.circleMarker([p.lat, p.lng], { radius: 6, fillColor: '#0d6efd', color: '#fff', weight: 2, opacity: p.confidence, fillOpacity: p.confidence })
            .addTo(map).bindPopup(`<strong>${window.currentMLReport.typhoonName} +${p.hours}h</strong><br>Confidence: ${(p.confidence * 100).toFixed(0)}%`);
        m._isPathMarker = true; markers.push(m);
    });
    map.fitBounds(L.latLngBounds(coords), { padding: [50, 50] });
}

// ─── CHAT ─────────────────────────────────────────────────────────────────────
function loadChatHistory() {
    try {
        const saved = sessionStorage.getItem(CHAT_STORAGE_KEY);
        if (saved) {
            const messages = JSON.parse(saved);
            const container = document.getElementById('chatContainer');
            container.innerHTML = '';
            messages.forEach(msg => {
                const d = document.createElement('div');
                d.className = `message ${msg.role}`;
                const b = document.createElement('div');
                b.className = 'message-bubble';
                b.textContent = msg.content;
                d.appendChild(b); container.appendChild(d);
            });
            container.scrollTop = container.scrollHeight;
        } else {
            addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
        }
    } catch { addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant.'); }
}

function saveChatHistory() {
    try {
        const container = document.getElementById('chatContainer');
        const messages = [];
        container.querySelectorAll('.message').forEach(el => {
            const role = el.classList.contains('user') ? 'user' : 'assistant';
            const content = el.querySelector('.message-bubble')?.textContent?.trim();
            if (content) messages.push({ role, content });
        });
        sessionStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
    } catch { }
}

function clearChatHistory() {
    const modal = document.getElementById('clearChatModal');
    if (modal) { modal.style.display = 'block'; document.body.style.overflow = 'hidden'; }
}

function closeClearChatModal() {
    const modal = document.getElementById('clearChatModal');
    if (modal) { modal.style.display = 'none'; document.body.style.overflow = 'auto'; }
}

function confirmClearChat() {
    sessionStorage.removeItem(CHAT_STORAGE_KEY);
    const container = document.getElementById('chatContainer');
    container.innerHTML = '';
    addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    closeClearChatModal();
    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.innerHTML = '✓ Chat history cleared';
    document.body.appendChild(toast);
    setTimeout(() => { toast.style.opacity = '0'; setTimeout(() => toast.remove(), 300); }, 2000);
}

function toggleChatBubble() {
    const w = document.getElementById('chatBubbleWindow');
    const b = document.querySelector('.chat-bubble-button');
    if (w.classList.contains('open')) { w.classList.remove('open'); b?.classList.remove('hidden'); }
    else { w.classList.add('open'); b?.classList.add('hidden'); setTimeout(() => document.getElementById('messageInput')?.focus(), 300); }
}

async function sendMessage() {
    const input = document.getElementById('messageInput');
    const msg = input.value.trim();
    if (!msg) return;

    addMessageToChat('user', msg);
    input.value = '';
    document.getElementById('sendBtn').disabled = true;
    input.disabled = true;
    showLoading();

    const currentDateTime = new Date().toLocaleString('en-PH', {
        timeZone: 'Asia/Manila', weekday: 'long', year: 'numeric', month: 'long',
        day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true
    });

    try {
        const r = await fetch(window.location.href, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: msg, weatherData, typhoonData, userLocation, currentDateTime })
        });
        const d = await r.json();
        hideLoading();
        addMessageToChat('assistant', d.success ? d.response : (d.error || 'AI service temporarily unavailable.'));
        saveChatHistory();
    } catch {
        hideLoading();
        addMessageToChat('assistant', 'Connection error. Please check your internet and try again.');
        saveChatHistory();
    } finally {
        document.getElementById('sendBtn').disabled = false;
        input.disabled = false;
        input.focus();
    }
}

function askQuestion(question) {
    document.getElementById('messageInput').value = question;
    sendMessage();
}

function addMessageToChat(role, content) {
    const container = document.getElementById('chatContainer');
    if (!container) return;
    const d = document.createElement('div');
    d.className = `message ${role}`;
    const b = document.createElement('div');
    b.className = 'message-bubble';
    b.textContent = content;
    d.appendChild(b);
    container.appendChild(d);
    container.scrollTop = container.scrollHeight;
    if (role === 'user') { detectLocationInMessage(content); saveChatHistory(); }
}

function detectLocationInMessage(msg) {
    const locations = {
        // NCR - National Capital Region
        'manila': { lat: 14.5995, lng: 120.9842, zoom: 11 }, 'metro manila': { lat: 14.5995, lng: 120.9842, zoom: 10 },
        'quezon city': { lat: 14.6760, lng: 121.0437, zoom: 12 }, 'makati': { lat: 14.5547, lng: 121.0244, zoom: 13 },
        'pasig': { lat: 14.5764, lng: 121.0851, zoom: 13 }, 'taguig': { lat: 14.5176, lng: 121.0509, zoom: 13 },
        'mandaluyong': { lat: 14.5794, lng: 121.0359, zoom: 13 }, 'san juan': { lat: 14.6019, lng: 121.0355, zoom: 14 },
        'pasay': { lat: 14.5378, lng: 120.9896, zoom: 13 }, 'paranaque': { lat: 14.4793, lng: 121.0198, zoom: 13 },
        'muntinlupa': { lat: 14.3811, lng: 121.0437, zoom: 13 }, 'las pinas': { lat: 14.4443, lng: 120.9833, zoom: 13 },
        'marikina': { lat: 14.6507, lng: 121.1029, zoom: 13 }, 'valenzuela': { lat: 14.7006, lng: 120.9830, zoom: 13 },
        'caloocan': { lat: 14.6488, lng: 120.9830, zoom: 12 }, 'malabon': { lat: 14.6625, lng: 120.9559, zoom: 13 },
        'navotas': { lat: 14.6651, lng: 120.9402, zoom: 14 }, 'pateros': { lat: 14.5445, lng: 121.0657, zoom: 14 },

        // LUZON - CAR (Cordillera Administrative Region)
        'baguio': { lat: 16.4023, lng: 120.5960, zoom: 13 }, 'baguio city': { lat: 16.4023, lng: 120.5960, zoom: 13 },
        'la trinidad': { lat: 16.4610, lng: 120.5897, zoom: 14 }, 'benguet': { lat: 16.4167, lng: 120.5833, zoom: 10 },
        'bontoc': { lat: 17.0894, lng: 120.9774, zoom: 13 }, 'mountain province': { lat: 17.0000, lng: 121.0000, zoom: 10 },
        'tabuk': { lat: 17.4189, lng: 121.4443, zoom: 13 }, 'kalinga': { lat: 17.4000, lng: 121.4000, zoom: 10 },
        'bangued': { lat: 17.5964, lng: 120.6167, zoom: 13 }, 'abra': { lat: 17.5000, lng: 120.7500, zoom: 10 },
        'lagawe': { lat: 16.8167, lng: 121.1167, zoom: 13 }, 'ifugao': { lat: 16.8333, lng: 121.1667, zoom: 10 },
        'apayao': { lat: 18.0000, lng: 121.0000, zoom: 10 },

        // LUZON - Region I (Ilocos Region)
        'san fernando': { lat: 16.6159, lng: 120.3173, zoom: 13 }, 'la union': { lat: 16.6167, lng: 120.3167, zoom: 11 },
        'vigan': { lat: 17.5747, lng: 120.3869, zoom: 13 }, 'vigan city': { lat: 17.5747, lng: 120.3869, zoom: 13 },
        'ilocos sur': { lat: 17.2500, lng: 120.5000, zoom: 10 }, 'laoag': { lat: 18.1987, lng: 120.5942, zoom: 13 },
        'laoag city': { lat: 18.1987, lng: 120.5942, zoom: 13 }, 'ilocos norte': { lat: 18.1667, lng: 120.7500, zoom: 10 },
        'pangasinan': { lat: 15.8950, lng: 120.2863, zoom: 10 }, 'lingayen': { lat: 16.0194, lng: 120.2278, zoom: 13 },
        'dagupan': { lat: 16.0433, lng: 120.3339, zoom: 13 }, 'dagupan city': { lat: 16.0433, lng: 120.3339, zoom: 13 },
        'urdaneta': { lat: 15.9761, lng: 120.5711, zoom: 13 }, 'alaminos': { lat: 16.1556, lng: 119.9822, zoom: 13 },
        'san carlos': { lat: 15.9322, lng: 120.3419, zoom: 13 },

        // LUZON - Region II (Cagayan Valley)
        'tuguegarao': { lat: 17.6132, lng: 121.7270, zoom: 13 }, 'tuguegarao city': { lat: 17.6132, lng: 121.7270, zoom: 13 },
        'cagayan': { lat: 18.2500, lng: 121.8333, zoom: 10 }, 'isabela': { lat: 16.9754, lng: 121.8093, zoom: 10 },
        'ilagan': { lat: 17.1489, lng: 121.8844, zoom: 13 }, 'ilagan city': { lat: 17.1489, lng: 121.8844, zoom: 13 },
        'cauayan': { lat: 16.9269, lng: 121.7706, zoom: 13 }, 'cauayan city': { lat: 16.9269, lng: 121.7706, zoom: 13 },
        'nueva vizcaya': { lat: 16.3333, lng: 121.0000, zoom: 10 }, 'bayombong': { lat: 16.4833, lng: 121.1500, zoom: 13 },
        'quirino': { lat: 16.2667, lng: 121.5333, zoom: 10 }, 'cabarroguis': { lat: 16.4167, lng: 121.4833, zoom: 14 },
        'batanes': { lat: 20.4500, lng: 121.9667, zoom: 11 }, 'basco': { lat: 20.4500, lng: 121.9667, zoom: 13 },

        // LUZON - Region III (Central Luzon)
        'balanga': { lat: 14.6764, lng: 120.5367, zoom: 13 }, 'bataan': { lat: 14.6417, lng: 120.4417, zoom: 11 },
        'bulacan': { lat: 14.7942, lng: 120.8794, zoom: 10 }, 'malolos': { lat: 14.8433, lng: 120.8114, zoom: 13 },
        'meycauayan': { lat: 14.7342, lng: 120.9575, zoom: 13 }, 'san jose del monte': { lat: 14.8139, lng: 121.0453, zoom: 12 },
        'nueva ecija': { lat: 15.5784, lng: 121.1113, zoom: 10 }, 'palayan': { lat: 15.5403, lng: 121.0831, zoom: 13 },
        'cabanatuan': { lat: 15.4859, lng: 120.9672, zoom: 13 }, 'cabanatuan city': { lat: 15.4859, lng: 120.9672, zoom: 13 },
        'gapan': { lat: 15.3069, lng: 120.9475, zoom: 13 }, 'san jose': { lat: 15.7936, lng: 120.9961, zoom: 13 },
        'pampanga': { lat: 15.0794, lng: 120.6200, zoom: 10 }, 'san fernando pampanga': { lat: 15.0286, lng: 120.6897, zoom: 13 },
        'angeles': { lat: 15.1450, lng: 120.5887, zoom: 13 }, 'angeles city': { lat: 15.1450, lng: 120.5887, zoom: 13 },
        'mabalacat': { lat: 15.2167, lng: 120.5717, zoom: 13 }, 'tarlac': { lat: 15.4756, lng: 120.5969, zoom: 11 },
        'tarlac city': { lat: 15.4756, lng: 120.5969, zoom: 13 }, 'zambales': { lat: 15.5083, lng: 119.9606, zoom: 10 },
        'olongapo': { lat: 14.8292, lng: 120.2828, zoom: 13 }, 'olongapo city': { lat: 14.8292, lng: 120.2828, zoom: 13 },
        'iba': { lat: 15.3272, lng: 119.9778, zoom: 13 }, 'aurora': { lat: 15.7542, lng: 121.6406, zoom: 11 },
        'baler': { lat: 15.7594, lng: 121.5614, zoom: 13 },

        // LUZON - Region IV-A (CALABARZON)
        'cavite': { lat: 14.4791, lng: 120.8970, zoom: 10 }, 'trece martires': { lat: 14.2833, lng: 120.8667, zoom: 13 },
        'imus': { lat: 14.4297, lng: 120.9367, zoom: 13 }, 'bacoor': { lat: 14.4597, lng: 120.9433, zoom: 13 },
        'dasmariñas': { lat: 14.3294, lng: 120.9367, zoom: 13 }, 'cavite city': { lat: 14.4791, lng: 120.9014, zoom: 13 },
        'tagaytay': { lat: 14.1102, lng: 120.9601, zoom: 13 }, 'laguna': { lat: 14.2691, lng: 121.4113, zoom: 10 },
        'santa rosa': { lat: 14.3122, lng: 121.1114, zoom: 13 }, 'calamba': { lat: 14.2119, lng: 121.1653, zoom: 13 },
        'san pablo': { lat: 14.0683, lng: 121.3256, zoom: 13 }, 'san pedro': { lat: 14.3558, lng: 121.0178, zoom: 13 },
        'biñan': { lat: 14.3369, lng: 121.0808, zoom: 13 }, 'cabuyao': { lat: 14.2786, lng: 121.1250, zoom: 13 },
        'batangas': { lat: 13.7565, lng: 121.0583, zoom: 10 }, 'batangas city': { lat: 13.7565, lng: 121.0583, zoom: 13 },
        'lipa': { lat: 13.9411, lng: 121.1622, zoom: 13 }, 'lipa city': { lat: 13.9411, lng: 121.1622, zoom: 13 },
        'tanauan': { lat: 14.0856, lng: 121.1500, zoom: 13 }, 'rizal': { lat: 14.6037, lng: 121.3084, zoom: 10 },
        'antipolo': { lat: 14.5864, lng: 121.1758, zoom: 12 }, 'antipolo city': { lat: 14.5864, lng: 121.1758, zoom: 12 },
        'cainta': { lat: 14.5778, lng: 121.1222, zoom: 13 }, 'taytay': { lat: 14.5631, lng: 121.1322, zoom: 13 },
        'quezon province': { lat: 14.0158, lng: 122.1311, zoom: 10 }, 'lucena': { lat: 13.9372, lng: 121.6169, zoom: 13 },
        'lucena city': { lat: 13.9372, lng: 121.6169, zoom: 13 },

        // LUZON - Region IV-B (MIMAROPA)
        'marinduque': { lat: 13.4767, lng: 121.9031, zoom: 11 }, 'boac': { lat: 13.4500, lng: 121.8333, zoom: 13 },
        'occidental mindoro': { lat: 13.1000, lng: 120.7667, zoom: 10 }, 'mamburao': { lat: 13.2222, lng: 120.5947, zoom: 13 },
        'oriental mindoro': { lat: 13.0000, lng: 121.4500, zoom: 10 }, 'calapan': { lat: 13.4117, lng: 121.1803, zoom: 13 },
        'calapan city': { lat: 13.4117, lng: 121.1803, zoom: 13 }, 'puerto princesa': { lat: 9.7392, lng: 118.7353, zoom: 12 },
        'palawan': { lat: 9.8349, lng: 118.7384, zoom: 8 }, 'romblon': { lat: 12.5779, lng: 122.2690, zoom: 11 },

        // LUZON - Region V (Bicol)
        'albay': { lat: 13.1775, lng: 123.5293, zoom: 10 }, 'legazpi': { lat: 13.1391, lng: 123.7436, zoom: 12 },
        'legazpi city': { lat: 13.1391, lng: 123.7436, zoom: 12 }, 'ligao': { lat: 13.2167, lng: 123.5333, zoom: 13 },
        'tabaco': { lat: 13.3594, lng: 123.7333, zoom: 13 }, 'camarines norte': { lat: 14.1333, lng: 122.7667, zoom: 10 },
        'daet': { lat: 14.1119, lng: 122.9550, zoom: 13 }, 'camarines sur': { lat: 13.5309, lng: 123.3467, zoom: 10 },
        'pili': { lat: 13.5833, lng: 123.2833, zoom: 13 }, 'naga': { lat: 13.6218, lng: 123.1948, zoom: 13 },
        'naga city': { lat: 13.6218, lng: 123.1948, zoom: 13 }, 'iriga': { lat: 13.4214, lng: 123.4167, zoom: 13 },
        'catanduanes': { lat: 13.7000, lng: 124.2500, zoom: 11 }, 'virac': { lat: 13.5833, lng: 124.2333, zoom: 13 },
        'masbate': { lat: 12.3714, lng: 123.6178, zoom: 10 }, 'masbate city': { lat: 12.3714, lng: 123.6178, zoom: 13 },
        'sorsogon': { lat: 12.9714, lng: 124.0053, zoom: 10 }, 'sorsogon city': { lat: 12.9714, lng: 124.0053, zoom: 13 },

        // VISAYAS - Region VI (Western Visayas)
        'aklan': { lat: 11.8333, lng: 122.0833, zoom: 10 }, 'kalibo': { lat: 11.7050, lng: 122.3678, zoom: 13 },
        'boracay': { lat: 11.9674, lng: 121.9248, zoom: 13 }, 'antique': { lat: 11.7000, lng: 121.9500, zoom: 10 },
        'san jose antique': { lat: 10.7667, lng: 121.9333, zoom: 13 }, 'capiz': { lat: 11.5833, lng: 122.7500, zoom: 10 },
        'roxas': { lat: 11.5850, lng: 122.7508, zoom: 13 }, 'roxas city': { lat: 11.5850, lng: 122.7508, zoom: 13 },
        'guimaras': { lat: 10.5922, lng: 122.6322, zoom: 11 }, 'iloilo': { lat: 10.7202, lng: 122.5621, zoom: 11 },
        'iloilo city': { lat: 10.7202, lng: 122.5621, zoom: 12 }, 'negros occidental': { lat: 10.6760, lng: 122.9510, zoom: 10 },
        'bacolod': { lat: 10.6760, lng: 122.9510, zoom: 12 }, 'bacolod city': { lat: 10.6760, lng: 122.9510, zoom: 12 },
        'silay': { lat: 10.8000, lng: 122.9667, zoom: 13 }, 'talisay negros': { lat: 10.7333, lng: 122.9667, zoom: 13 },
        'victorias': { lat: 10.9028, lng: 123.0806, zoom: 13 }, 'cadiz': { lat: 10.9500, lng: 123.3000, zoom: 13 },
        'sagay': { lat: 10.8833, lng: 123.4167, zoom: 13 }, 'escalante': { lat: 10.8333, lng: 123.5000, zoom: 13 },

        // VISAYAS - Region VII (Central Visayas)
        'bohol': { lat: 9.8500, lng: 124.1435, zoom: 10 }, 'tagbilaran': { lat: 9.6472, lng: 123.8531, zoom: 13 },
        'tagbilaran city': { lat: 9.6472, lng: 123.8531, zoom: 13 }, 'panglao': { lat: 9.5805, lng: 123.7544, zoom: 13 },
        'cebu': { lat: 10.3157, lng: 123.8854, zoom: 10 }, 'cebu city': { lat: 10.3157, lng: 123.8854, zoom: 12 },
        'mandaue': { lat: 10.3236, lng: 123.9222, zoom: 13 }, 'mandaue city': { lat: 10.3236, lng: 123.9222, zoom: 13 },
        'lapu-lapu': { lat: 10.3103, lng: 123.9494, zoom: 13 }, 'lapu-lapu city': { lat: 10.3103, lng: 123.9494, zoom: 13 },
        'talisay cebu': { lat: 10.2444, lng: 123.8492, zoom: 13 }, 'toledo': { lat: 10.3778, lng: 123.6397, zoom: 13 },
        'danao': { lat: 10.5197, lng: 124.0258, zoom: 13 }, 'carcar': { lat: 10.1089, lng: 123.6403, zoom: 13 },
        'negros oriental': { lat: 9.3167, lng: 123.3000, zoom: 10 }, 'dumaguete': { lat: 9.3068, lng: 123.3054, zoom: 13 },
        'dumaguete city': { lat: 9.3068, lng: 123.3054, zoom: 13 }, 'siquijor': { lat: 9.2000, lng: 123.5833, zoom: 11 },

        // VISAYAS - Region VIII (Eastern Visayas)
        'biliran': { lat: 11.5833, lng: 124.4667, zoom: 11 }, 'naval': { lat: 11.5608, lng: 124.3953, zoom: 13 },
        'eastern samar': { lat: 11.5000, lng: 125.5000, zoom: 10 }, 'borongan': { lat: 11.6058, lng: 125.4331, zoom: 13 },
        'leyte': { lat: 11.0, lng: 124.8, zoom: 9 }, 'tacloban': { lat: 11.2447, lng: 125.0037, zoom: 12 },
        'tacloban city': { lat: 11.2447, lng: 125.0037, zoom: 12 }, 'ormoc': { lat: 11.0064, lng: 124.6075, zoom: 13 },
        'ormoc city': { lat: 11.0064, lng: 124.6075, zoom: 13 }, 'baybay': { lat: 10.6786, lng: 124.8003, zoom: 13 },
        'northern samar': { lat: 12.4167, lng: 124.8333, zoom: 10 }, 'catarman': { lat: 12.4986, lng: 124.6358, zoom: 13 },
        'samar': { lat: 12.0, lng: 125.0, zoom: 9 }, 'catbalogan': { lat: 11.7753, lng: 124.8883, zoom: 13 },
        'southern leyte': { lat: 10.3333, lng: 125.0000, zoom: 10 }, 'maasin': { lat: 10.1319, lng: 124.8408, zoom: 13 },

        // MINDANAO - Region IX (Zamboanga Peninsula)
        'zamboanga del norte': { lat: 8.5500, lng: 123.3333, zoom: 10 }, 'dipolog': { lat: 8.5833, lng: 123.3417, zoom: 13 },
        'dipolog city': { lat: 8.5833, lng: 123.3417, zoom: 13 }, 'dapitan': { lat: 8.6581, lng: 123.4242, zoom: 13 },
        'zamboanga del sur': { lat: 7.8381, lng: 123.2956, zoom: 10 }, 'pagadian': { lat: 7.8281, lng: 123.4356, zoom: 13 },
        'pagadian city': { lat: 7.8281, lng: 123.4356, zoom: 13 }, 'zamboanga sibugay': { lat: 7.8333, lng: 122.5000, zoom: 10 },
        'ipil': { lat: 7.7833, lng: 122.5833, zoom: 13 }, 'zamboanga': { lat: 6.9214, lng: 122.0790, zoom: 11 },
        'zamboanga city': { lat: 6.9214, lng: 122.0790, zoom: 12 },

        // MINDANAO - Region X (Northern Mindanao)
        'bukidnon': { lat: 8.0542, lng: 124.9247, zoom: 10 }, 'malaybalay': { lat: 8.1536, lng: 125.1278, zoom: 13 },
        'malaybalay city': { lat: 8.1536, lng: 125.1278, zoom: 13 }, 'valencia': { lat: 7.9069, lng: 125.0942, zoom: 13 },
        'camiguin': { lat: 9.1731, lng: 124.7297, zoom: 12 }, 'mambajao': { lat: 9.2500, lng: 124.7167, zoom: 13 },
        'lanao del norte': { lat: 8.0000, lng: 123.8333, zoom: 10 }, 'tubod': { lat: 8.0500, lng: 123.8000, zoom: 13 },
        'iligan': { lat: 8.2280, lng: 124.2453, zoom: 13 }, 'iligan city': { lat: 8.2280, lng: 124.2453, zoom: 13 },
        'misamis occidental': { lat: 8.5000, lng: 123.7500, zoom: 10 }, 'oroquieta': { lat: 8.4833, lng: 123.8000, zoom: 13 },
        'oroquieta city': { lat: 8.4833, lng: 123.8000, zoom: 13 }, 'ozamiz': { lat: 8.1478, lng: 123.8414, zoom: 13 },
        'ozamiz city': { lat: 8.1478, lng: 123.8414, zoom: 13 }, 'tangub': { lat: 8.0667, lng: 123.7500, zoom: 13 },
        'misamis oriental': { lat: 8.5000, lng: 124.6667, zoom: 10 }, 'cagayan de oro': { lat: 8.4542, lng: 124.6319, zoom: 12 },
        'cdo': { lat: 8.4542, lng: 124.6319, zoom: 12 }, 'gingoog': { lat: 8.8244, lng: 125.1017, zoom: 13 },

        // MINDANAO - Region XI (Davao)
        'davao del norte': { lat: 7.5667, lng: 125.6533, zoom: 10 }, 'tagum': { lat: 7.4478, lng: 125.8078, zoom: 13 },
        'tagum city': { lat: 7.4478, lng: 125.8078, zoom: 13 }, 'panabo': { lat: 7.3086, lng: 125.6836, zoom: 13 },
        'davao del sur': { lat: 6.7667, lng: 125.3333, zoom: 10 }, 'digos': { lat: 6.7497, lng: 125.3572, zoom: 13 },
        'digos city': { lat: 6.7497, lng: 125.3572, zoom: 13 }, 'davao oriental': { lat: 7.3167, lng: 126.5500, zoom: 10 },
        'mati': { lat: 6.9550, lng: 126.2181, zoom: 13 }, 'mati city': { lat: 6.9550, lng: 126.2181, zoom: 13 },
        'davao de oro': { lat: 7.4500, lng: 126.0500, zoom: 10 }, 'compostela valley': { lat: 7.4500, lng: 126.0500, zoom: 10 },
        'nabunturan': { lat: 7.6000, lng: 125.9667, zoom: 13 }, 'davao': { lat: 7.1907, lng: 125.4553, zoom: 10 },
        'agdao': { lat: 7.0972, lng: 125.6089, zoom: 15 },
'centro agdao': { lat: 7.0972, lng: 125.6089, zoom: 15 },
'nagsil': { lat: 7.0972, lng: 125.6089, zoom: 16 },
'nagsil village': { lat: 7.0972, lng: 125.6089, zoom: 16 },
'brgy centro agdao': { lat: 7.0972, lng: 125.6089, zoom: 15 },'davao occidental': { lat: 6.0833, lng: 125.6167, zoom: 10 },
        'malita': { lat: 6.4167, lng: 125.6167, zoom: 13 },

        // MINDANAO - Region XII (SOCCSKSARGEN)
        'cotabato': { lat: 7.2167, lng: 124.2333, zoom: 10 }, 'kidapawan': { lat: 7.0094, lng: 125.0889, zoom: 13 },
        'kidapawan city': { lat: 7.0094, lng: 125.0889, zoom: 13 }, 'south cotabato': { lat: 6.3333, lng: 124.8333, zoom: 10 },
        'koronadal': { lat: 6.5008, lng: 124.8469, zoom: 13 }, 'koronadal city': { lat: 6.5008, lng: 124.8469, zoom: 13 },
        'general santos': { lat: 6.1164, lng: 125.1716, zoom: 12 }, 'general santos city': { lat: 6.1164, lng: 125.1716, zoom: 12 },
        'gensan': { lat: 6.1164, lng: 125.1716, zoom: 12 }, 'sultan kudarat': { lat: 6.5167, lng: 124.4167, zoom: 10 },
        'isulan': { lat: 6.6333, lng: 124.6000, zoom: 13 }, 'tacurong': { lat: 6.6903, lng: 124.6778, zoom: 13 },
        'tacurong city': { lat: 6.6903, lng: 124.6778, zoom: 13 }, 'sarangani': { lat: 5.9333, lng: 124.9333, zoom: 10 },
        'alabel': { lat: 6.1000, lng: 125.2833, zoom: 13 },

        // MINDANAO - Region XIII (Caraga)
        'agusan del norte': { lat: 8.9478, lng: 125.5331, zoom: 10 }, 'butuan': { lat: 8.9475, lng: 125.5406, zoom: 13 },
        'butuan city': { lat: 8.9475, lng: 125.5406, zoom: 13 }, 'cabadbaran': { lat: 9.1231, lng: 125.5350, zoom: 13 },
        'cabadbaran city': { lat: 9.1231, lng: 125.5350, zoom: 13 }, 'agusan del sur': { lat: 8.5500, lng: 125.9667, zoom: 10 },
        'prosperidad': { lat: 8.6000, lng: 125.9167, zoom: 13 }, 'bayugan': { lat: 8.7167, lng: 125.7500, zoom: 13 },
        'dinagat islands': { lat: 10.1278, lng: 125.6050, zoom: 11 }, 'san jose dinagat': { lat: 10.0667, lng: 125.6000, zoom: 13 },
        'surigao del norte': { lat: 9.7869, lng: 125.4919, zoom: 10 }, 'surigao': { lat: 9.7869, lng: 125.4919, zoom: 13 },
        'surigao city': { lat: 9.7869, lng: 125.4919, zoom: 13 }, 'siargao': { lat: 9.8601, lng: 126.0466, zoom: 11 },
        'surigao del sur': { lat: 8.8500, lng: 126.1167, zoom: 10 }, 'tandag': { lat: 9.0783, lng: 126.1972, zoom: 13 },
        'tandag city': { lat: 9.0783, lng: 126.1972, zoom: 13 }, 'bislig': { lat: 8.2167, lng: 126.3167, zoom: 13 },
        'bislig city': { lat: 8.2167, lng: 126.3167, zoom: 13 },

        // MINDANAO - BARMM (Bangsamoro)
        'basilan': { lat: 6.4333, lng: 121.9833, zoom: 11 }, 'isabela city': { lat: 6.7011, lng: 121.9711, zoom: 13 },
        'lanao del sur': { lat: 7.8333, lng: 124.4333, zoom: 10 }, 'marawi': { lat: 8.0000, lng: 124.2833, zoom: 13 },
        'marawi city': { lat: 8.0000, lng: 124.2833, zoom: 13 }, 'maguindanao': { lat: 6.9417, lng: 124.4111, zoom: 10 },
        'cotabato city': { lat: 7.2250, lng: 124.2472, zoom: 13 }, 'sulu': { lat: 6.0500, lng: 121.0000, zoom: 10 },
        'jolo': { lat: 6.0500, lng: 121.0000, zoom: 13 }, 'tawi-tawi': { lat: 5.1333, lng: 119.9500, zoom: 10 },
        'bongao': { lat: 5.0297, lng: 119.7728, zoom: 13 },

        // Popular Tourist Destinations & Landmarks
        'el nido': { lat: 11.1944, lng: 119.4019, zoom: 12 }, 'coron': { lat: 12.0008, lng: 120.2070, zoom: 12 },
        'puerto galera': { lat: 13.5056, lng: 120.9539, zoom: 13 }, 'hundred islands': { lat: 16.1972, lng: 119.9469, zoom: 12 },
        'vigan heritage': { lat: 17.5747, lng: 120.3869, zoom: 14 }, 'sagada': { lat: 17.0833, lng: 120.9000, zoom: 13 },
        'batad rice terraces': { lat: 16.8667, lng: 121.0833, zoom: 14 }, 'banaue': { lat: 16.9167, lng: 121.0500, zoom: 13 },
        'mayon volcano': { lat: 13.2577, lng: 123.6856, zoom: 12 }, 'taal volcano': { lat: 14.0021, lng: 120.9933, zoom: 13 },
        'chocolate hills': { lat: 9.8167, lng: 124.1667, zoom: 12 }, 'loboc river': { lat: 9.6333, lng: 124.0333, zoom: 13 },
        'kawasan falls': { lat: 9.8167, lng: 123.3833, zoom: 14 }, 'oslob whale sharks': { lat: 9.4333, lng: 123.3833, zoom: 13 },
        'malapascua': { lat: 11.3167, lng: 124.1167, zoom: 13 }, 'bantayan island': { lat: 11.1667, lng: 123.7167, zoom: 12 },
        'kalanggaman island': { lat: 11.0667, lng: 124.9000, zoom: 13 }, 'apo island': { lat: 9.0767, lng: 123.2728, zoom: 14 },
        'camiguin white island': { lat: 9.2667, lng: 124.7333, zoom: 13 }, 'tinuy-an falls': { lat: 8.2100, lng: 126.2206, zoom: 13 },
        'enchanted river': { lat: 8.2167, lng: 126.0500, zoom: 14 }, 'britania islands': { lat: 9.2000, lng: 126.1833, zoom: 12 },
        'cloud 9': { lat: 9.8333, lng: 126.0500, zoom: 14 }, 'magpupungko': { lat: 9.9167, lng: 126.0667, zoom: 14 },
        'sugba lagoon': { lat: 9.8833, lng: 126.0333, zoom: 13 }, 'sohoton cove': { lat: 9.9167, lng: 126.1500, zoom: 13 },
        'pearl farm': { lat: 6.8667, lng: 125.6333, zoom: 13 }, 'samal island': { lat: 7.0833, lng: 125.7333, zoom: 11 },
        'tinagong dagat': { lat: 9.1833, lng: 126.1000, zoom: 14 }, 'hinatuan enchanted river': { lat: 8.2167, lng: 126.0500, zoom: 14 }
    };
    const lower = msg.toLowerCase();
    for (const [place, coords] of Object.entries(locations)) {
        if (lower.includes(place)) {
            setTimeout(() => {
                map.setView([coords.lat, coords.lng], coords.zoom, { animate: true, duration: 1.5 });
                const m = L.marker([coords.lat, coords.lng], {
                    icon: L.divIcon({ className: '', html: '<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3)"></div>', iconSize: [16, 16] })
                }).addTo(map).bindPopup(`<strong>${place.charAt(0).toUpperCase() + place.slice(1)}</strong>`).openPopup();
                markers.push(m);
            }, 500);
            break;
        }
    }
}

function showLoading() {
    const c = document.getElementById('chatContainer');
    const d = document.createElement('div');
    d.className = 'message assistant'; d.id = 'loadingMessage';
    d.innerHTML = '<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div></div>';
    c.appendChild(d); c.scrollTop = c.scrollHeight;
}
function hideLoading() { document.getElementById('loadingMessage')?.remove(); }

// ─── AUTO REFRESH ─────────────────────────────────────────────────────────────
setInterval(fetchWeather, 300000); // 5 min
setInterval(fetchTyphoons, 600000); // 10 min