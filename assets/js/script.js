let weatherData = null;
let typhoonData = [];
let userLocation = 'Philippines';
let userCoords = {lat: 8.4542, lng: 124.6319};
let map = null;
let markers = [];

// Chat history storage
const CHAT_STORAGE_KEY = 'typhoon_tracker_chat_history';

document.addEventListener('DOMContentLoaded', () => {
    initMap();
    detectLocation();
    fetchTyphoons();
    loadChatHistory();
});

function initMap() {
    map = L.map('map').setView([12.8797, 121.7740], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 19
    }).addTo(map);
}

function detectLocation() {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(async (p) => {
            userCoords.lat = p.coords.latitude;
            userCoords.lng = p.coords.longitude;
            
            L.marker([userCoords.lat, userCoords.lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#0d6efd;width:12px;height:12px;border-radius:50%;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3)"></div>',
                    iconSize: [12, 12]
                })
            }).addTo(map).bindPopup('<strong>Your Location</strong>');
            
            try {
                const r = await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${userCoords.lat}&lon=${userCoords.lng}&format=json`);
                const d = await r.json();
                if (d.address) {
                    const c = d.address.city || d.address.town || d.address.municipality;
                    const pr = d.address.state || d.address.province;
                    userLocation = c ? `${c}, ${pr}` : pr || 'Philippines';
                    document.getElementById('userLocation').textContent = userLocation;
                }
            } catch (e) {}
            fetchWeather();
        }, () => {
            document.getElementById('userLocation').textContent = 'Philippines';
            fetchWeather();
        });
    } else {
        fetchWeather();
    }
}

async function fetchWeather() {
    try {
        const r = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${userCoords.lat}&longitude=${userCoords.lng}&current_weather=true&hourly=relativehumidity_2m,pressure_msl&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum&timezone=Asia/Manila&forecast_days=7`);
        const d = await r.json();
        
        if (d.current_weather) {
            weatherData = {
                windSpeed: d.current_weather.windspeed.toFixed(1),
                temperature: d.current_weather.temperature.toFixed(1),
                pressure: d.hourly.pressure_msl[0] ? d.hourly.pressure_msl[0].toFixed(0) : 'N/A',
                humidity: d.hourly.relativehumidity_2m[0] || 'N/A'
            };
            updateWeatherDisplay();
        }
        
        if (d.daily) {
            renderForecast(d.daily);
        }
    } catch (e) {
        weatherData = {windSpeed: '15.0', temperature: '28.0', pressure: '1012', humidity: '75'};
        updateWeatherDisplay();
    }
}

function renderForecast(daily) {
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const icons = ['☀️', '🌤️', '⛅', '🌧️', '⛈️'];
    let html = '';
    
    for (let i = 0; i < 7; i++) {
        const date = new Date(daily.time[i]);
        const dayName = i === 0 ? 'Today' : (i === 1 ? 'Tomorrow' : days[date.getDay()]);
        const maxTemp = Math.round(daily.temperature_2m_max[i]);
        const minTemp = Math.round(daily.temperature_2m_min[i]);
        const precip = daily.precipitation_sum[i] || 0;
        const precipProb = daily.precipitation_probability_max[i] || 0;
        
        let icon = icons[0];
        if (precip > 10) icon = icons[4];
        else if (precip > 5) icon = icons[3];
        else if (precipProb > 50) icon = icons[2];
        else if (precipProb > 20) icon = icons[1];
        
        html += `<div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:8px;padding:1.5rem;text-align:center;transition:all 0.2s;cursor:pointer" onmouseover="this.style.borderColor='#0d6efd';this.style.background='#f0f8ff'" onmouseout="this.style.borderColor='#e4e6eb';this.style.background='#f8f9fa'">
            <div style="font-size:0.875rem;font-weight:600;color:#65676b;margin-bottom:0.75rem">${dayName}</div>
            <div style="font-size:3rem;margin:1rem 0">${icon}</div>
            <div style="font-size:1.5rem;font-weight:700;color:#1c1e21;margin-bottom:0.25rem">${maxTemp}°C</div>
            <div style="font-size:1rem;color:#65676b;margin-bottom:0.75rem">${minTemp}°C</div>
            <div style="display:flex;align-items:center;justify-content:center;gap:0.5rem;font-size:0.875rem">
                <span style="color:#0d6efd;font-weight:600">💧 ${precipProb}%</span>
                <span style="color:#65676b">|</span>
                <span style="color:#65676b">${precip.toFixed(1)}mm</span>
            </div>
        </div>`;
    }
    
    document.getElementById('forecastDays').innerHTML = html;
}

function updateWeatherDisplay() {
    document.getElementById('windSpeed').textContent = weatherData.windSpeed + ' km/h';
    document.getElementById('temperature').textContent = weatherData.temperature + '°C';
    document.getElementById('pressure').textContent = weatherData.pressure + ' hPa';
    document.getElementById('humidity').textContent = weatherData.humidity + '%';
}

async function fetchTyphoons() {
    try {
        const gdacs = await fetch('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red&country=PHL');
        const gdacsText = await gdacs.text();
        
        typhoonData = [];
        markers.forEach(m => map.removeLayer(m));
        markers = [];
        
        if (gdacsText.includes('<item>')) {
            const parser = new DOMParser();
            const xml = parser.parseFromString(gdacsText, 'text/xml');
            const items = xml.querySelectorAll('item');
            
            items.forEach(item => {
                const title = item.querySelector('title')?.textContent || '';
                const desc = item.querySelector('description')?.textContent || '';
                const point = item.querySelector('point')?.textContent || '';
                
                if (point) {
                    const [lat, lng] = point.split(' ').map(Number);
                    const windMatch = desc.match(/(\d+)\s*km\/h/i) || desc.match(/(\d+)\s*kts/i);
                    let windSpeed = windMatch ? parseInt(windMatch[1]) : 0;
                    
                    if (desc.includes('kts')) windSpeed = Math.round(windSpeed * 1.852);
                    
                    const nameMatch = title.match(/Typhoon\s+(\w+)/i) || title.match(/Storm\s+(\w+)/i) || title.match(/TC\s+(\w+)/i);
                    const name = nameMatch ? nameMatch[1] : 'Tropical Cyclone';
                    
                    const dist = calculateDistance(userCoords.lat, userCoords.lng, lat, lng);
                    
                    typhoonData.push({
                        name: name,
                        lat: lat,
                        lng: lng,
                        windSpeed: windSpeed || 85,
                        distance: Math.round(dist)
                    });
                }
            });
        }
        
        typhoonData.sort((a, b) => a.distance - b.distance);
        updateTyphoonList();
        addTyphoonMarkers();
        
    } catch (e) {
        document.getElementById('typhoonList').innerHTML = '<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div></div>';
    }
}

function calculateDistance(lat1, lon1, lat2, lon2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function updateTyphoonList() {
    const list = document.getElementById('typhoonList');
    
    if (typhoonData.length === 0) {
        list.innerHTML = '<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div></div>';
        return;
    }
    
    let html = '';
    typhoonData.forEach(t => {
        const severity = t.distance < 300 ? 'danger' : (t.distance < 600 ? 'warning' : 'info');
        const badgeClass = t.distance < 300 ? 'badge-danger' : (t.distance < 600 ? 'badge-warning' : 'badge-info');
        const badgeText = t.distance < 300 ? '⚠️ VERY CLOSE' : (t.distance < 600 ? '⚠️ CLOSE' : 'ℹ️ MONITORING');
        
        html += `<div class="typhoon-item ${severity}" onclick="focusTyphoon(${t.lat},${t.lng})">
            <span class="badge ${badgeClass}">${badgeText}</span>
            <div class="typhoon-name">${t.name}</div>
            <div class="typhoon-details">
                <div class="detail-item">
                    <div class="detail-label">Wind Speed</div>
                    <div class="detail-value">${t.windSpeed} km/h</div>
                </div>
                <div class="detail-item">
                    <div class="detail-label">Distance</div>
                    <div class="detail-value">${t.distance} km</div>
                </div>
            </div>
        </div>`;
    });
    list.innerHTML = html;
}

function addTyphoonMarkers() {
    typhoonData.forEach(t => {
        const color = t.distance < 300 ? '#dc3545' : (t.distance < 600 ? '#ffc107' : '#0d6efd');
        
        const marker = L.marker([t.lat, t.lng], {
            icon: L.divIcon({
                className: '',
                html: `<div style="background:${color};width:22px;height:22px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;font-size:12px">🌀</div>`,
                iconSize: [22, 22]
            })
        }).addTo(map).bindPopup(`<strong>${t.name}</strong><br>${t.windSpeed} km/h winds<br>${t.distance} km away`);
        markers.push(marker);
        
        const circle = L.circle([t.lat, t.lng], {
            color: color,
            fillColor: color,
            fillOpacity: 0.1,
            radius: t.distance * 1000,
            weight: 2
        }).addTo(map);
        markers.push(circle);
    });
}

function focusTyphoon(lat, lng) {
    map.setView([lat, lng], 8, {animate: true});
}

// Chat History Functions
function loadChatHistory() {
    try {
        const saved = localStorage.getItem(CHAT_STORAGE_KEY);
        if (saved) {
            const messages = JSON.parse(saved);
            const container = document.getElementById('chatContainer');
            container.innerHTML = '';
            
            messages.forEach(msg => {
                const message = document.createElement('div');
                message.className = `message ${msg.role}`;
                
                const bubble = document.createElement('div');
                bubble.className = 'message-bubble';
                bubble.textContent = msg.content;
                
                if (msg.badge) {
                    const badge = document.createElement('div');
                    badge.className = `ai-badge ${msg.badge.type}`;
                    badge.textContent = msg.badge.text;
                    bubble.appendChild(badge);
                }
                
                message.appendChild(bubble);
                container.appendChild(message);
            });
            
            container.scrollTop = container.scrollHeight;
        } else {
            addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
        }
    } catch (e) {
        console.error('Error loading chat history:', e);
        addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    }
}

function saveChatHistory() {
    try {
        const container = document.getElementById('chatContainer');
        const messages = [];
        
        container.querySelectorAll('.message').forEach(msgEl => {
            const role = msgEl.classList.contains('user') ? 'user' : 'assistant';
            const bubble = msgEl.querySelector('.message-bubble');
            const badge = msgEl.querySelector('.ai-badge');
            
            const bubbleClone = bubble.cloneNode(true);
            const badgeInClone = bubbleClone.querySelector('.ai-badge');
            if (badgeInClone) badgeInClone.remove();
            
            const content = bubbleClone.textContent.trim();
            
            const msgData = {role: role, content: content};
            
            if (badge) {
                msgData.badge = {
                    type: badge.classList.contains('real') ? 'real' : 'fallback',
                    text: badge.textContent
                };
            }
            
            messages.push(msgData);
        });
        
        localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
    } catch (e) {
        console.error('Error saving chat history:', e);
    }
}

function clearChatHistory() {
    if (confirm('Are you sure you want to clear all chat history? This cannot be undone.')) {
        localStorage.removeItem(CHAT_STORAGE_KEY);
        const container = document.getElementById('chatContainer');
        container.innerHTML = '';
        addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    }
}

async function sendMessage() {
    const input = document.getElementById('messageInput');
    const msg = input.value.trim();
    if (!msg) return;
    
    addMessageToChat('user', msg);
    input.value = '';
    
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    input.disabled = true;
    
    showLoading();
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                message: msg,
                weatherData: weatherData,
                typhoonData: typhoonData,
                userLocation: userLocation
            })
        });
        
        const data = await response.json();
        hideLoading();
        
        if (data.success) {
            addMessageToChat('assistant', data.response);
            const lastMessage = document.querySelector('.message.assistant:last-child .message-bubble');
            const badge = document.createElement('div');
            badge.className = `ai-badge ${data.isRealAI ? 'real' : 'fallback'}`;
            badge.textContent = data.isRealAI ? '🤖 AI' : '💬 Smart';
            lastMessage.appendChild(badge);
            
            saveChatHistory();
        } else {
            addMessageToChat('assistant', 'Sorry, an error occurred. Please try again.');
            saveChatHistory();
        }
    } catch (e) {
        hideLoading();
        addMessageToChat('assistant', 'Connection error. Please check your internet and try again.');
        saveChatHistory();
    } finally {
        sendBtn.disabled = false;
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
    const message = document.createElement('div');
    message.className = `message ${role}`;
    
    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    bubble.textContent = content;
    
    message.appendChild(bubble);
    container.appendChild(message);
    container.scrollTop = container.scrollHeight;
    
    if (role === 'user') {
        detectLocationInMessage(content);
        saveChatHistory();
    }
}

function detectLocationInMessage(msg) {
    const locations = {
        'manila': {lat: 14.5995, lng: 120.9842, zoom: 11},
        'cebu': {lat: 10.3157, lng: 123.8854, zoom: 12},
        'davao': {lat: 7.1907, lng: 125.4553, zoom: 12},
        'cagayan de oro': {lat: 8.4542, lng: 124.6319, zoom: 12},
        'cdo': {lat: 8.4542, lng: 124.6319, zoom: 12},
        'baguio': {lat: 16.4023, lng: 120.5960, zoom: 13},
        'zamboanga': {lat: 6.9214, lng: 122.0790, zoom: 12},
        'tacloban': {lat: 11.2447, lng: 125.0037, zoom: 12},
        'iloilo': {lat: 10.7202, lng: 122.5621, zoom: 12},
        'bacolod': {lat: 10.6760, lng: 122.9510, zoom: 12},
        'puerto princesa': {lat: 9.7392, lng: 118.7353, zoom: 12},
        'dumaguete': {lat: 9.3068, lng: 123.3054, zoom: 13},
        'general santos': {lat: 6.1164, lng: 125.1716, zoom: 12}
    };
    
    const msgLower = msg.toLowerCase();
    for (const [place, coords] of Object.entries(locations)) {
        if (msgLower.includes(place)) {
            setTimeout(() => {
                map.setView([coords.lat, coords.lng], coords.zoom, {animate: true, duration: 1.5});
                const locationMarker = L.marker([coords.lat, coords.lng], {
                    icon: L.divIcon({
                        className: '',
                        html: '<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);animation:pulse 2s infinite"></div><style>@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.3);opacity:0.7}}</style>',
                        iconSize: [16, 16]
                    })
                }).addTo(map).bindPopup(`<strong>${place.charAt(0).toUpperCase() + place.slice(1)}</strong>`).openPopup();
                markers.push(locationMarker);
            }, 500);
            break;
        }
    }
}

function showLoading() {
    const container = document.getElementById('chatContainer');
    const loading = document.createElement('div');
    loading.className = 'message assistant';
    loading.id = 'loadingMessage';
    loading.innerHTML = '<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div></div>';
    container.appendChild(loading);
    container.scrollTop = container.scrollHeight;
}

function hideLoading() {
    const loading = document.getElementById('loadingMessage');
    if (loading) loading.remove();
}

setInterval(fetchWeather, 300000);
setInterval(fetchTyphoons, 600000);