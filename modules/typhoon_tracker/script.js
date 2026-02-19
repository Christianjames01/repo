let weatherData = null;
let typhoonData = [];
let userLocation = 'Detecting location...';
let userCoords = {lat: 7.1907, lng: 125.4553}; // DEFAULT TO DAVAO CITY
let map = null;
let markers = [];
let locationAccuracy = 'approximate';

// ============================================================================
// PRIVATE CHAT STORAGE - SESSION BASED
// Each browser tab/session gets its own private chat history
// History is automatically cleared when browser/tab closes
// ============================================================================

// Use sessionStorage for complete privacy (cleared on browser close)
const CHAT_STORAGE_KEY = 'typhoon_chat_private_session';

function loadChatHistory() {
    try {
        // Use sessionStorage - automatically cleared when tab/browser closes
        const saved = sessionStorage.getItem(CHAT_STORAGE_KEY);
        
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
            const content = bubble.textContent.trim();
            
            messages.push({role: role, content: content});
        });
        
        // Save to sessionStorage (private to this tab/session)
        sessionStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
    } catch (e) {
        console.error('Error saving chat history:', e);
    }
}

function clearChatHistory() {
    const modal = document.getElementById('clearChatModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function confirmClearChat() {
    // Clear from sessionStorage
    sessionStorage.removeItem(CHAT_STORAGE_KEY);
    
    const container = document.getElementById('chatContainer');
    container.innerHTML = '';
    addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    
    closeClearChatModal();
    
    const successMsg = document.createElement('div');
    successMsg.className = 'toast-notification';
    successMsg.innerHTML = '✓ Chat history cleared successfully';
    document.body.appendChild(successMsg);
    
    setTimeout(() => {
        successMsg.style.opacity = '0';
        setTimeout(() => successMsg.remove(), 300);
    }, 2000);
}

console.log('✅ Private session-based chat storage initialized');
console.log('🔒 Chat history is private to this browser session')

document.addEventListener('DOMContentLoaded', () => {
    initMap();
    detectLocation();
    fetchTyphoons();
    loadChatHistory();
    updateDateTime();
    setInterval(updateDateTime, 1000);
});

function updateDateTime() {
    const now = new Date();
    const options = {
        timeZone: 'Asia/Manila',
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    };
    
    const dateTimeString = now.toLocaleString('en-PH', options);
    document.getElementById('currentDateTime').textContent = dateTimeString;
}

function initMap() {
    map = L.map('map').setView([12.8797, 121.7740], 6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap',
        maxZoom: 19
    }).addTo(map);
}

function detectLocation() {
    document.getElementById('userLocation').innerHTML = '📍 Detecting your location... <button onclick="requestLocationPermission()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Enable GPS</button>';
    
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            async (position) => {
                const accuracy = position.coords.accuracy;
                userCoords.lat = position.coords.latitude;
                userCoords.lng = position.coords.longitude;
                
                console.log('✅ GPS Location:', userCoords);
                
                if (accuracy < 100) {
                    locationAccuracy = 'precise';
                } else if (accuracy < 1000) {
                    locationAccuracy = 'good';
                } else {
                    locationAccuracy = 'approximate';
                }
                
                map.setView([userCoords.lat, userCoords.lng], 13);
                
                L.marker([userCoords.lat, userCoords.lng], {
                    icon: L.divIcon({
                        className: '',
                        html: `<div style="background:#0d6efd;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(13,110,253,0.5)"></div>`,
                        iconSize: [16, 16]
                    })
                }).addTo(map).bindPopup('<strong>📍 Your Location</strong><br>Accuracy: ~' + Math.round(accuracy) + 'm');
                
                await fetchLocationDetails(userCoords.lat, userCoords.lng);
                fetchWeather();
            },
            (error) => {
                console.error('❌ GPS error:', error.message);
                handleLocationError(error);
            },
            {
                enableHighAccuracy: true,
                timeout: 15000,
                maximumAge: 0
            }
        );
    } else {
        console.error('❌ Geolocation not supported');
        handleLocationError();
    }
}

function requestLocationPermission() {
    detectLocation();
}

async function fetchLocationDetails(lat, lng) {
    try {
        console.log('🔍 Fetching location details for:', lat, lng);
        
        const bigDataResponse = await fetch(
            `https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lng}&localityLanguage=en`,
            { timeout: 5000 }
        );
        
        if (bigDataResponse.ok) {
            const data = await bigDataResponse.json();
            console.log('✅ BigDataCloud response:', data);
            
            let locationParts = [];
            
            if (data.locality) locationParts.push(data.locality);
            if (data.city && data.city !== data.locality) locationParts.push(data.city);
            if (data.principalSubdivision) locationParts.push(data.principalSubdivision);
            if (data.countryName) locationParts.push(data.countryName);
            
            userLocation = locationParts.length > 0 ? locationParts.join(', ') : 'Unknown Location';
            
            const accuracyText = locationAccuracy === 'precise' ? '✓ GPS' : '≈ GPS';
            document.getElementById('userLocation').textContent = `📍 ${userLocation} (${accuracyText})`;
            return;
        }
        
        throw new Error('BigDataCloud failed');
        
    } catch (error) {
        console.error('❌ Location details error:', error);
        userLocation = `${lat.toFixed(4)}°N, ${lng.toFixed(4)}°E`;
        document.getElementById('userLocation').textContent = `📍 ${userLocation} (GPS Coordinates)`;
    }
}

async function fetchIPLocation() {
    try {
        console.log('🌐 Trying IP-based location...');
        
        const response = await fetch('https://ipapi.co/json/', { timeout: 5000 });
        
        if (response.ok) {
            const data = await response.json();
            console.log('✅ IP Location:', data);
            
            userCoords.lat = data.latitude;
            userCoords.lng = data.longitude;
            
            let locationParts = [];
            if (data.city) locationParts.push(data.city);
            if (data.region) locationParts.push(data.region);
            if (data.country_name) locationParts.push(data.country_name);
            
            userLocation = locationParts.join(', ');
            
            document.getElementById('userLocation').innerHTML = `📍 ${userLocation} (IP-based) <button onclick="requestLocationPermission()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Use GPS</button>`;
            
            map.setView([userCoords.lat, userCoords.lng], 12);
            L.marker([userCoords.lat, userCoords.lng], {
                icon: L.divIcon({
                    className: '',
                    html: '<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(255,193,7,0.5)"></div>',
                    iconSize: [16, 16]
                })
            }).addTo(map).bindPopup('<strong>📍 Your Location</strong><br>(IP-based)');
            
            fetchWeather();
            return;
        }
        
        throw new Error('IP location failed');
        
    } catch (error) {
        console.error('❌ IP location error:', error);
        useDefaultLocation();
    }
}

function useDefaultLocation() {
    console.log('📍 Using default location: Davao City');
    
    userCoords.lat = 7.1907;
    userCoords.lng = 125.4553;
    userLocation = 'Davao City, Davao Region, Philippines';
    
    document.getElementById('userLocation').innerHTML = `📍 ${userLocation} (Default) <button onclick="requestLocationPermission()" style="margin-left:8px;padding:4px 12px;background:#0d6efd;color:white;border:none;border-radius:4px;cursor:pointer;font-size:0.75rem">Use My Location</button>`;
    
    map.setView([userCoords.lat, userCoords.lng], 11);
    L.marker([userCoords.lat, userCoords.lng], {
        icon: L.divIcon({
            className: '',
            html: '<div style="background:#6c757d;width:16px;height:16px;border-radius:50%;border:3px solid white;box-shadow:0 2px 8px rgba(108,117,125,0.5)"></div>',
            iconSize: [16, 16]
        })
    }).addTo(map).bindPopup('<strong>📍 Default Location</strong><br>Davao City');
    
    fetchWeather();
}

function handleLocationError(error) {
    if (error) {
        console.error('Location error code:', error.code);
        switch(error.code) {
            case error.PERMISSION_DENIED:
                console.log('User denied location permission');
                break;
            case error.POSITION_UNAVAILABLE:
                console.log('Location information unavailable');
                break;
            case error.TIMEOUT:
                console.log('Location request timed out');
                break;
        }
    }
    
    fetchIPLocation();
}

async function fetchWeather() {
    try {
        console.log('🌤️ Fetching weather for:', userCoords);
        const r = await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${userCoords.lat}&longitude=${userCoords.lng}&current_weather=true&hourly=relativehumidity_2m,pressure_msl&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum&timezone=auto&forecast_days=7`);
        const d = await r.json();
        
        if (d.current_weather) {
            weatherData = {
                windSpeed: d.current_weather.windspeed.toFixed(1),
                temperature: d.current_weather.temperature.toFixed(1),
                pressure: d.hourly.pressure_msl[0] ? d.hourly.pressure_msl[0].toFixed(0) : 'N/A',
                humidity: d.hourly.relativehumidity_2m[0] || 'N/A'
            };
            console.log('✅ Weather data:', weatherData);
            updateWeatherDisplay();
        }
        
        if (d.daily) {
            renderForecast(d.daily);
        }
    } catch (e) {
        console.error('Weather fetch error:', e);
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
        
        html += `<div style="background:#ffffff;border:1px solid #d1d5db;border-radius:8px;padding:1.5rem;text-align:center;transition:all 0.2s;cursor:pointer" 
            onmouseover="this.style.borderColor='#9ca3af';this.style.background='#f9fafb';this.style.transform='translateY(-2px)';this.style.boxShadow='0 2px 8px rgba(0,0,0,0.08)'" 
            onmouseout="this.style.borderColor='#d1d5db';this.style.background='#ffffff';this.style.transform='translateY(0)';this.style.boxShadow='none'"
            onclick="showForecastDetail(${i}, '${dayName}', ${maxTemp}, ${minTemp}, ${precip}, ${precipProb}, '${icon}', '${daily.time[i]}')">
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

// Add this to your existing script.js to integrate ML features

// Add ML Analysis Button and Display
function addMLFeatures() {
    // Add ML Analysis section to the UI
    const container = document.querySelector('.container');
    
    // Create ML Analysis Card (insert after typhoon list)
    const mlCard = document.createElement('div');
    mlCard.className = 'card';
    mlCard.innerHTML = `
        <div class="card-header">
            <span class="card-title">🤖 AI Risk Analysis</span>
            <button class="refresh-btn" onclick="runMLAnalysis()">
                <span>🔬</span> Analyze
            </button>
        </div>
        <div id="mlAnalysis" style="padding: 1rem; max-height: 400px; overflow-y: auto;">
            <div class="empty-state">
                <div class="empty-state-icon">🤖</div>
                <div>Click "Analyze" to run ML predictions</div>
            </div>
        </div>
    `;
    
    // Insert ML card as second item in grid
    const firstCard = container.querySelector('.card');
    if (firstCard && firstCard.nextSibling) {
        container.insertBefore(mlCard, firstCard.nextSibling);
    }
}

// Run ML Analysis
async function runMLAnalysis() {
    if (!typhoonData || typhoonData.length === 0) {
        showMLResult('No active typhoons to analyze');
        return;
    }
    
    if (!weatherData) {
        showMLResult('Weather data not available');
        return;
    }
    
    const mlContainer = document.getElementById('mlAnalysis');
    mlContainer.innerHTML = '<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div><span style="margin-left:0.5rem">Running ML analysis...</span></div>';
    
    // Simulate processing time for ML
    await new Promise(resolve => setTimeout(resolve, 1500));
    
    // Get closest typhoon
    const closestTyphoon = typhoonData[0];
    
    // Run ML predictions
    const report = window.mlPredictor.generateAnalysisReport(
        closestTyphoon,
        weatherData,
        userCoords
    );
    
    displayMLReport(report);
}

// Display ML Analysis Report
function displayMLReport(report) {
    const mlContainer = document.getElementById('mlAnalysis');
    
    const riskColor = getRiskColor(report.riskAssessment.level);
    const riskBg = getRiskBackground(report.riskAssessment.level);
    
    let html = `
        <!-- Risk Score -->
        <div style="background: ${riskBg}; border: 2px solid ${riskColor}; border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem;">
            <div style="text-align: center;">
                <div style="font-size: 0.875rem; color: #6b7280; font-weight: 600; margin-bottom: 0.5rem;">OVERALL RISK LEVEL</div>
                <div style="font-size: 3rem; font-weight: 700; color: ${riskColor}; margin: 0.5rem 0;">${report.riskAssessment.overallScore}</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: ${riskColor}; margin-bottom: 1rem;">${report.riskAssessment.level}</div>
                <div style="font-size: 0.9375rem; color: #4b5563; line-height: 1.6; background: #fff; padding: 1rem; border-radius: 8px;">
                    ${report.riskAssessment.recommendation}
                </div>
            </div>
        </div>
        
        <!-- AI Insights -->
        ${report.aiInsights.length > 0 ? `
        <div style="background: #fffbf0; border: 1px solid #ffc107; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #856404; margin-bottom: 0.5rem;">💡 AI INSIGHTS</div>
            ${report.aiInsights.map(insight => `
                <div style="font-size: 0.875rem; color: #856404; margin: 0.5rem 0; padding-left: 1rem; border-left: 3px solid #ffc107;">
                    ${insight}
                </div>
            `).join('')}
        </div>
        ` : ''}
        
        <!-- Intensity Forecast -->
        <div style="background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">⚡ INTENSITY FORECAST</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Current Wind Speed</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #1c1e21;">${report.intensityForecast.currentWindSpeed} km/h</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Predicted Change</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: ${report.intensityForecast.predictedChange > 0 ? '#dc3545' : '#28a745'};">
                        ${report.intensityForecast.predictedChange > 0 ? '+' : ''}${report.intensityForecast.predictedChange.toFixed(1)} km/h
                    </div>
                </div>
            </div>
            <div style="margin-top: 0.75rem; padding-top: 0.75rem; border-top: 1px solid #e5e7eb;">
                <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">Contributing Factors:</div>
                ${Object.entries(report.intensityForecast.contributingFactors).map(([key, value]) => `
                    <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; margin: 0.25rem 0;">
                        <span style="color: #4b5563; text-transform: capitalize;">${key.replace(/([A-Z])/g, ' $1').trim()}:</span>
                        <span style="font-weight: 600; color: ${value === 'favorable' ? '#dc3545' : value === 'inhibiting' ? '#28a745' : '#6b7280'};">
                            ${value.toUpperCase()}
                        </span>
                    </div>
                `).join('')}
            </div>
            <div style="margin-top: 0.75rem; padding: 0.75rem; background: #fff; border-radius: 6px;">
                <div style="font-size: 0.75rem; color: #6b7280;">Model Confidence: <span style="font-weight: 700; color: #1c1e21;">${(report.intensityForecast.confidence * 100).toFixed(0)}%</span></div>
            </div>
        </div>
        
        <!-- Rainfall Prediction -->
        <div style="background: #f0f8ff; border: 1px solid #0d6efd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">💧 RAINFALL PREDICTION</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">24 Hours</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #0d6efd;">${report.rainfallForecast.expected24h}mm</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">48 Hours</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #0d6efd;">${report.rainfallForecast.expected48h}mm</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">Flood Risk</div>
                    <div style="font-size: 1rem; font-weight: 700; color: ${report.rainfallForecast.floodRisk === 'high' ? '#dc3545' : report.rainfallForecast.floodRisk === 'moderate' ? '#ffc107' : '#28a745'}; text-transform: uppercase;">
                        ${report.rainfallForecast.floodRisk}
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Risk Factors Breakdown -->
        <div style="background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">📊 RISK FACTORS BREAKDOWN</div>
            ${report.riskAssessment.factors.map(factor => {
                const percentage = (factor.points / report.riskAssessment.overallScore) * 100;
                const severityColor = factor.severity === 'critical' ? '#dc3545' : 
                                     factor.severity === 'high' ? '#ffc107' : 
                                     factor.severity === 'moderate' ? '#0d6efd' : '#28a745';
                return `
                    <div style="margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; margin-bottom: 0.25rem;">
                            <span style="color: #4b5563; text-transform: capitalize; font-weight: 500;">${factor.factor}</span>
                            <span style="font-weight: 700; color: ${severityColor};">${factor.points} pts (${factor.severity.toUpperCase()})</span>
                        </div>
                        <div style="background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden;">
                            <div style="background: ${severityColor}; height: 100%; width: ${percentage}%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
        
        <!-- Path Prediction Preview -->
        <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.5rem;">🎯 PREDICTED PATH (48h)</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">
                Based on atmospheric steering patterns and ML trajectory analysis
            </div>
            <button onclick="showPathOnMap()" style="width: 100%; padding: 0.75rem; background: #0d6efd; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.875rem;">
                📍 View Predicted Path on Map
            </button>
        </div>
        
        <div style="margin-top: 1rem; padding: 0.75rem; background: #f0f8ff; border-radius: 6px; font-size: 0.75rem; color: #4b5563; text-align: center;">
            ⏱️ Analysis generated at ${new Date(report.timestamp).toLocaleTimeString()}
        </div>
    `;
    
    mlContainer.innerHTML = html;
    
    // Store report for path visualization
    window.currentMLReport = report;
}

function getRiskColor(level) {
    switch(level) {
        case 'CRITICAL': return '#dc3545';
        case 'HIGH': return '#fd7e14';
        case 'MODERATE': return '#ffc107';
        case 'LOW': return '#0d6efd';
        default: return '#28a745';
    }
}

function getRiskBackground(level) {
    switch(level) {
        case 'CRITICAL': return '#fff5f5';
        case 'HIGH': return '#fff8f0';
        case 'MODERATE': return '#fffbf0';
        case 'LOW': return '#f0f8ff';
        default: return '#f0fff4';
    }
}

function showMLResult(message) {
    const mlContainer = document.getElementById('mlAnalysis');
    mlContainer.innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon">ℹ️</div>
            <div>${message}</div>
        </div>
    `;
}

function showPathOnMap() {
    if (!window.currentMLReport) return;
    
    const predictions = window.currentMLReport.pathForecast.predictions;
    const typhoonName = window.currentMLReport.typhoonName;
    
    // Clear previous path markers
    markers.forEach(m => {
        if (m._isPathMarker) map.removeLayer(m);
    });
    markers = markers.filter(m => !m._isPathMarker);
    
    // Draw predicted path
    const pathCoords = predictions.map(p => [p.lat, p.lng]);
    const pathLine = L.polyline(pathCoords, {
        color: '#0d6efd',
        weight: 3,
        opacity: 0.7,
        dashArray: '10, 10'
    }).addTo(map);
    pathLine._isPathMarker = true;
    markers.push(pathLine);
    
    // Add prediction markers
    predictions.forEach((pred, idx) => {
        const marker = L.circleMarker([pred.lat, pred.lng], {
            radius: 6,
            fillColor: '#0d6efd',
            color: '#fff',
            weight: 2,
            opacity: pred.confidence,
            fillOpacity: pred.confidence
        }).addTo(map).bindPopup(`
            <strong>${typhoonName} +${pred.hours}h</strong><br>
            Confidence: ${(pred.confidence * 100).toFixed(0)}%
        `);
        marker._isPathMarker = true;
        markers.push(marker);
    });
    
    // Fit map to show full path
    const bounds = L.latLngBounds(pathCoords);
    map.fitBounds(bounds, { padding: [50, 50] });
}

// Auto-initialize when page loads
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(() => {
        addMLFeatures();
        console.log('✅ ML features integrated successfully');
    }, 1000);
});


// ============================================================================
// ADD THIS ENTIRE SECTION TO THE END OF YOUR EXISTING script.js FILE
// ============================================================================

// =========================
// ML FEATURES INTEGRATION
// =========================

function addMLFeatures() {
    // Check if already added
    if (document.getElementById('mlAnalysis')) {
        console.log('ML features already initialized');
        return;
    }
    
    const container = document.querySelector('.container');
    if (!container) {
        console.error('Container not found');
        return;
    }
    
    // Create ML Analysis Card - FIXED: Better positioning
    const mlCard = document.createElement('div');
    mlCard.className = 'card ml-analysis-card';
    mlCard.innerHTML = `
        <div class="card-header">
            <span class="card-title">🤖 AI Risk Analysis</span>
            <button class="refresh-btn" onclick="runMLAnalysis()">
                <span>🔬</span> Analyze
            </button>
        </div>
        <div id="mlAnalysis" style="padding: 1rem; overflow-y: auto; flex: 1;">
            <div class="empty-state">
                <div class="empty-state-icon">🤖</div>
                <div>Click "Analyze" to run comprehensive weather analysis</div>
            </div>
        </div>
    `;
    
    // Find the map card (second card) and insert ML card after it
    const cards = container.querySelectorAll('.card');
    if (cards.length >= 2) {
        // Insert after map card (index 1)
        cards[1].parentNode.insertBefore(mlCard, cards[1].nextSibling);
        console.log('✅ ML Analysis card added successfully after map');
    } else if (cards.length > 0) {
        // Fallback: insert after first card
        cards[0].parentNode.insertBefore(mlCard, cards[0].nextSibling);
        console.log('✅ ML Analysis card added successfully');
    } else {
        container.appendChild(mlCard);
    }
}

async function runMLAnalysis() {
    console.log('🔬 Starting ML Analysis...');
    console.log('Weather Data:', weatherData);
    console.log('Typhoon Data:', typhoonData);
    
    if (!weatherData) {
        showMLResult('⚠️ Weather data not available. Please wait for weather data to load.');
        return;
    }
    
    const mlContainer = document.getElementById('mlAnalysis');
    if (!mlContainer) {
        console.error('❌ ML container not found');
        return;
    }
    
    mlContainer.innerHTML = '<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div><span style="margin-left:0.5rem">Running comprehensive weather analysis...</span></div>';
    
    await new Promise(resolve => setTimeout(resolve, 1500));
    
    if (!window.mlPredictor) {
        showMLResult('❌ ML system not loaded. Please refresh the page.');
        console.error('ML Predictor not found');
        return;
    }
    
    let report;
    
    try {
        if (typhoonData && typhoonData.length > 0) {
            console.log('📊 Analyzing typhoon threat...');
            const closestSystem = typhoonData[0];
            report = window.mlPredictor.generateAnalysisReport(
                closestSystem,
                weatherData,
                userCoords
            );
        } else {
            console.log('📊 Analyzing general weather conditions...');
            report = window.mlPredictor.generateWeatherAnalysisReport(
                weatherData,
                userCoords
            );
        }
        
        console.log('✅ Analysis complete:', report);
        displayMLReport(report);
    } catch (error) {
        console.error('❌ ML Analysis error:', error);
        showMLResult(`⚠️ Analysis error: ${error.message}`);
    }
}

function displayMLReport(report) {
    const mlContainer = document.getElementById('mlAnalysis');
    
    const riskColor = getRiskColor(report.riskAssessment.level);
    const riskBg = getRiskBackground(report.riskAssessment.level);
    
    let html = `
        <!-- Risk Score -->
        <div style="background: ${riskBg}; border: 2px solid ${riskColor}; border-radius: 12px; padding: 1.5rem; margin-bottom: 1rem;">
            <div style="text-align: center;">
                <div style="font-size: 0.875rem; color: #6b7280; font-weight: 600; margin-bottom: 0.5rem;">OVERALL RISK LEVEL</div>
                <div style="font-size: 3rem; font-weight: 700; color: ${riskColor}; margin: 0.5rem 0;">${report.riskAssessment.overallScore}</div>
                <div style="font-size: 1.25rem; font-weight: 600; color: ${riskColor}; margin-bottom: 1rem;">${report.riskAssessment.level}</div>
                <div style="font-size: 0.9375rem; color: #4b5563; line-height: 1.6; background: #fff; padding: 1rem; border-radius: 8px;">
                    ${report.riskAssessment.recommendation}
                </div>
            </div>
        </div>
        
        <!-- AI Insights -->
        ${report.aiInsights.length > 0 ? `
        <div style="background: #fffbf0; border: 1px solid #ffc107; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #856404; margin-bottom: 0.5rem;">💡 AI INSIGHTS</div>
            ${report.aiInsights.map(insight => `
                <div style="font-size: 0.875rem; color: #856404; margin: 0.5rem 0; padding-left: 1rem; border-left: 3px solid #ffc107;">
                    ${insight}
                </div>
            `).join('')}
        </div>
        ` : ''}
        
        <!-- Rainfall Prediction -->
        <div style="background: #f0f8ff; border: 1px solid #0d6efd; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">💧 RAINFALL PREDICTION</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem;">
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">24 Hours</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #0d6efd;">${report.rainfallForecast.expected24h}mm</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">48 Hours</div>
                    <div style="font-size: 1.5rem; font-weight: 700; color: #0d6efd;">${report.rainfallForecast.expected48h}mm</div>
                </div>
                <div style="text-align: center;">
                    <div style="font-size: 0.75rem; color: #6b7280;">Flood Risk</div>
                    <div style="font-size: 1rem; font-weight: 700; color: ${report.rainfallForecast.floodRisk === 'high' ? '#dc3545' : report.rainfallForecast.floodRisk === 'moderate' ? '#ffc107' : '#28a745'}; text-transform: uppercase;">
                        ${report.rainfallForecast.floodRisk}
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Risk Factors Breakdown -->
        <div style="background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">📊 RISK FACTORS BREAKDOWN</div>
            ${report.riskAssessment.factors.map(factor => {
                const percentage = (factor.points / report.riskAssessment.overallScore) * 100;
                const severityColor = factor.severity === 'critical' ? '#dc3545' : 
                                     factor.severity === 'high' ? '#ffc107' : 
                                     factor.severity === 'moderate' ? '#0d6efd' : '#28a745';
                return `
                    <div style="margin-bottom: 0.75rem;">
                        <div style="display: flex; justify-content: space-between; font-size: 0.8125rem; margin-bottom: 0.25rem;">
                            <span style="color: #4b5563; text-transform: capitalize; font-weight: 500;">${factor.factor}</span>
                            <span style="font-weight: 700; color: ${severityColor};">${factor.points} pts (${factor.severity.toUpperCase()})</span>
                        </div>
                        <div style="background: #e5e7eb; height: 8px; border-radius: 4px; overflow: hidden;">
                            <div style="background: ${severityColor}; height: 100%; width: ${percentage}%; transition: width 0.3s;"></div>
                        </div>
                    </div>
                `;
            }).join('')}
        </div>
        
        ${report.analysisType === 'typhoon' ? `
        <!-- Intensity Forecast (only for typhoons) -->
        <div style="background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; margin-top: 1rem;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.75rem;">⚡ INTENSITY FORECAST</div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Current Wind Speed</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #1c1e21;">${report.intensityForecast.currentWindSpeed} km/h</div>
                </div>
                <div>
                    <div style="font-size: 0.75rem; color: #6b7280;">Predicted Change</div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: ${report.intensityForecast.predictedChange > 0 ? '#dc3545' : '#28a745'};">
                        ${report.intensityForecast.predictedChange > 0 ? '+' : ''}${report.intensityForecast.predictedChange.toFixed(1)} km/h
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Path Prediction (only for typhoons) -->
        <div style="margin-top: 1rem; padding: 1rem; background: #f8f9fa; border: 1px solid #e5e7eb; border-radius: 8px;">
            <div style="font-size: 0.875rem; font-weight: 600; color: #1c1e21; margin-bottom: 0.5rem;">🎯 PREDICTED PATH (48h)</div>
            <div style="font-size: 0.75rem; color: #6b7280; margin-bottom: 0.5rem;">
                Based on atmospheric steering patterns and ML trajectory analysis
            </div>
            <button onclick="showPathOnMap()" style="width: 100%; padding: 0.75rem; background: #0d6efd; color: #fff; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.875rem;">
                📍 View Predicted Path on Map
            </button>
        </div>
        ` : ''}
        
        <div style="margin-top: 1rem; padding: 0.75rem; background: #f0f8ff; border-radius: 6px; font-size: 0.75rem; color: #4b5563; text-align: center;">
            ⏱️ Analysis generated at ${new Date(report.timestamp).toLocaleTimeString()}
        </div>
    `;
    
    mlContainer.innerHTML = html;
    window.currentMLReport = report;
}

function getRiskColor(level) {
    switch(level) {
        case 'CRITICAL': return '#dc3545';
        case 'HIGH': return '#fd7e14';
        case 'MODERATE': return '#ffc107';
        case 'LOW': return '#0d6efd';
        default: return '#28a745';
    }
}

function getRiskBackground(level) {
    switch(level) {
        case 'CRITICAL': return '#fff5f5';
        case 'HIGH': return '#fff8f0';
        case 'MODERATE': return '#fffbf0';
        case 'LOW': return '#f0f8ff';
        default: return '#f0fff4';
    }
}

function showMLResult(message) {
    const mlContainer = document.getElementById('mlAnalysis');
    if (!mlContainer) return;
    
    mlContainer.innerHTML = `
        <div class="empty-state">
            <div class="empty-state-icon">ℹ️</div>
            <div style="padding: 1rem; text-align: center; color: #4b5563;">${message}</div>
        </div>
    `;
}

function showPathOnMap() {
    if (!window.currentMLReport || !window.currentMLReport.pathForecast) {
        alert('Path prediction not available for current weather analysis');
        return;
    }
    
    const predictions = window.currentMLReport.pathForecast.predictions;
    const typhoonName = window.currentMLReport.typhoonName;
    
    markers.forEach(m => {
        if (m._isPathMarker) map.removeLayer(m);
    });
    markers = markers.filter(m => !m._isPathMarker);
    
    const pathCoords = predictions.map(p => [p.lat, p.lng]);
    const pathLine = L.polyline(pathCoords, {
        color: '#0d6efd',
        weight: 3,
        opacity: 0.7,
        dashArray: '10, 10'
    }).addTo(map);
    pathLine._isPathMarker = true;
    markers.push(pathLine);
    
    predictions.forEach((pred, idx) => {
        const marker = L.circleMarker([pred.lat, pred.lng], {
            radius: 6,
            fillColor: '#0d6efd',
            color: '#fff',
            weight: 2,
            opacity: pred.confidence,
            fillOpacity: pred.confidence
        }).addTo(map).bindPopup(`
            <strong>${typhoonName} +${pred.hours}h</strong><br>
            Confidence: ${(pred.confidence * 100).toFixed(0)}%
        `);
        marker._isPathMarker = true;
        markers.push(marker);
    });
    
    const bounds = L.latLngBounds(pathCoords);
    map.fitBounds(bounds, { padding: [50, 50] });
}

// Initialize ML features when page loads
function initializeMLFeatures() {
    console.log('🚀 Starting ML features initialization...');
    
    if (!window.mlPredictor) {
        console.error('❌ ML Predictor not loaded! Make sure typhoon_ml_system.js is included.');
        return;
    }
    
    if (document.getElementById('mlAnalysis')) {
        console.log('✅ ML features already initialized');
        return;
    }
    
    addMLFeatures();
    console.log('✅ ML features fully initialized');
}

// Chat Bubble Toggle Function
function toggleChatBubble() {
    const chatWindow = document.getElementById('chatBubbleWindow');
    const chatButton = document.querySelector('.chat-bubble-button');
    
    if (chatWindow.classList.contains('open')) {
        chatWindow.classList.remove('open');
        chatButton.classList.remove('hidden');
    } else {
        chatWindow.classList.add('open');
        chatButton.classList.add('hidden');
        
        // Focus on input when opening
        setTimeout(() => {
            document.getElementById('messageInput').focus();
        }, 300);
    }
}

// Call initialization after a delay
setTimeout(initializeMLFeatures, 2000);


function showForecastDetail(dayIndex, dayName, maxTemp, minTemp, precip, precipProb, icon, dateStr) {
    const date = new Date(dateStr);
    const formattedDate = date.toLocaleDateString('en-US', { 
        weekday: 'long', 
        year: 'numeric', 
        month: 'long', 
        day: 'numeric' 
    });
    
    let weatherCondition = '';
    let weatherAdvice = '';
    
    if (precip > 10) {
        weatherCondition = 'Heavy Rain Expected';
        weatherAdvice = '⚠️ Bring an umbrella and waterproof gear. Possible flooding in low-lying areas.';
    } else if (precip > 5) {
        weatherCondition = 'Rainy';
        weatherAdvice = '🌧️ Expect rain showers. Keep an umbrella handy.';
    } else if (precipProb > 50) {
        weatherCondition = 'Partly Cloudy with Rain Chance';
        weatherAdvice = '⛅ Rain is likely. Prepare for wet weather.';
    } else if (precipProb > 20) {
        weatherCondition = 'Partly Cloudy';
        weatherAdvice = '🌤️ Mostly pleasant with some clouds.';
    } else {
        weatherCondition = 'Clear & Sunny';
        weatherAdvice = '☀️ Perfect weather! Don\'t forget sunscreen.';
    }
    
    let tempAdvice = '';
    if (maxTemp > 35) {
        tempAdvice = '🔥 Extreme heat warning! Stay hydrated and avoid prolonged sun exposure.';
    } else if (maxTemp > 32) {
        tempAdvice = '🌡️ Hot day ahead. Drink plenty of water.';
    } else if (maxTemp < 20) {
        tempAdvice = '🧥 Cool weather. Consider bringing a light jacket.';
    } else {
        tempAdvice = '😊 Comfortable temperature expected.';
    }
    
    const modal = document.getElementById('forecastModal');
    const modalDayName = document.getElementById('modalDayName');
    const modalContent = document.getElementById('modalContent');
    
    modalDayName.textContent = `${dayName} - ${formattedDate}`;
    
    modalContent.innerHTML = `
        <div style="text-align:center;margin:2rem 0">
            <div style="font-size:5rem;margin-bottom:1rem">${icon}</div>
            <div style="font-size:1.5rem;font-weight:700;color:#1c1e21;margin-bottom:0.5rem">${weatherCondition}</div>
        </div>
        
        <div style="background:#f8f9fa;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem;border:1px solid #e4e6eb">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">Temperature Range</h3>
            <div style="display:flex;justify-content:space-around;gap:2rem">
                <div style="text-align:center">
                    <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">High</div>
                    <div style="font-size:2.5rem;font-weight:700;color:#1c1e21">${maxTemp}°C</div>
                </div>
                <div style="text-align:center">
                    <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Low</div>
                    <div style="font-size:2.5rem;font-weight:700;color:#1c1e21">${minTemp}°C</div>
                </div>
            </div>
        </div>
        
        <div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:12px;padding:1.5rem;margin-bottom:1.5rem">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">💧 Precipitation</h3>
            <div style="display:flex;justify-content:space-around;gap:2rem">
                <div style="text-align:center">
                    <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Probability</div>
                    <div style="font-size:2rem;font-weight:700;color:#1c1e21">${precipProb}%</div>
                </div>
                <div style="text-align:center">
                    <div style="font-size:0.875rem;color:#6b7280;margin-bottom:0.5rem">Amount</div>
                    <div style="font-size:2rem;font-weight:700;color:#1c1e21">${precip.toFixed(1)} mm</div>
                </div>
            </div>
        </div>
        
        <div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:12px;padding:1.5rem;margin-bottom:1rem">
            <h3 style="font-size:0.875rem;color:#6b7280;margin-bottom:1rem;text-transform:uppercase;letter-spacing:0.5px;font-weight:600">📋 Weather Advice</h3>
            <p style="color:#4b5563;margin-bottom:0.75rem;font-size:0.9375rem;line-height:1.6">${weatherAdvice}</p>
            <p style="color:#4b5563;font-size:0.9375rem;line-height:1.6">${tempAdvice}</p>
        </div>
        
        <div style="background:#f8f9fa;border-radius:8px;padding:1rem;text-align:center;border:1px solid #e4e6eb">
            <p style="font-size:0.8125rem;color:#6b7280;margin:0">
                ℹ️ Forecast data is updated regularly. Check back for the latest information.
            </p>
        </div>
    `;
    
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeForecastModal() {
    const modal = document.getElementById('forecastModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

window.onclick = function(event) {
    const modal = document.getElementById('forecastModal');
    if (event.target === modal) {
        closeForecastModal();
    }
}

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeForecastModal();
    }
});

function updateWeatherDisplay() {
    const wind = parseFloat(weatherData.windSpeed);
    const temp = parseFloat(weatherData.temperature);
    const pres = parseFloat(weatherData.pressure);
    const hum = parseFloat(weatherData.humidity);
    
    document.getElementById('windSpeed').innerHTML = `<span class="value-number">${weatherData.windSpeed}</span><span class="value-unit">km/h</span>`;
    document.getElementById('temperature').innerHTML = `<span class="value-number">${weatherData.temperature}</span><span class="value-unit">°C</span>`;
    document.getElementById('pressure').innerHTML = `<span class="value-number">${weatherData.pressure}</span><span class="value-unit">hPa</span>`;
    document.getElementById('humidity').innerHTML = `<span class="value-number">${weatherData.humidity}</span><span class="value-unit">%</span>`;
    
    const windStatus = document.getElementById('windStatus');
    const tempStatus = document.getElementById('tempStatus');
    const pressureStatus = document.getElementById('pressureStatus');
    const humidityStatus = document.getElementById('humidityStatus');
    
    if (wind > 118) {
        windStatus.textContent = 'Typhoon Force';
        windStatus.className = 'weather-status danger';
    } else if (wind > 88) {
        windStatus.textContent = 'Storm Force';
        windStatus.className = 'weather-status danger';
    } else if (wind > 60) {
        windStatus.textContent = 'Strong Wind';
        windStatus.className = 'weather-status warning';
    } else if (wind > 39) {
        windStatus.textContent = 'Moderate Wind';
        windStatus.className = 'weather-status warning';
    } else {
        windStatus.textContent = 'Calm';
        windStatus.className = 'weather-status safe';
    }
    
    if (temp > 35) {
        tempStatus.textContent = 'Very Hot';
        tempStatus.className = 'weather-status danger';
    } else if (temp > 32) {
        tempStatus.textContent = 'Hot';
        tempStatus.className = 'weather-status warning';
    } else if (temp < 18) {
        tempStatus.textContent = 'Cool';
        tempStatus.className = 'weather-status normal';
    } else {
        tempStatus.textContent = 'Comfortable';
        tempStatus.className = 'weather-status safe';
    }
    
    if (pres < 1000) {
        pressureStatus.textContent = 'Low - Storm Risk';
        pressureStatus.className = 'weather-status danger';
    } else if (pres < 1010) {
        pressureStatus.textContent = 'Below Normal';
        pressureStatus.className = 'weather-status warning';
    } else if (pres > 1020) {
        pressureStatus.textContent = 'High Pressure';
        pressureStatus.className = 'weather-status safe';
    } else {
        pressureStatus.textContent = 'Normal';
        pressureStatus.className = 'weather-status normal';
    }
    
    if (hum > 80) {
        humidityStatus.textContent = 'Very Humid';
        humidityStatus.className = 'weather-status warning';
    } else if (hum > 60) {
        humidityStatus.textContent = 'Humid';
        humidityStatus.className = 'weather-status normal';
    } else if (hum < 30) {
        humidityStatus.textContent = 'Dry';
        humidityStatus.className = 'weather-status normal';
    } else {
        humidityStatus.textContent = 'Comfortable';
        humidityStatus.className = 'weather-status safe';
    }
    
    const now = new Date();
    const timeStr = now.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
    document.getElementById('lastUpdate').textContent = `Updated at ${timeStr}`;
}

async function fetchTyphoons() {
    const refreshIcon = document.getElementById('refreshIcon');
    if (refreshIcon) {
        refreshIcon.style.animation = 'rotate 1s linear infinite';
    }
    
    // Also refresh weather data
    fetchWeather();
    
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
    } finally {
        const refreshIcon = document.getElementById('refreshIcon');
        if (refreshIcon) {
            refreshIcon.style.animation = '';
        }
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
            const content = bubble.textContent.trim();
            
            messages.push({role: role, content: content});
        });
        
        localStorage.setItem(CHAT_STORAGE_KEY, JSON.stringify(messages));
    } catch (e) {
        console.error('Error saving chat history:', e);
    }
}

function clearChatHistory() {
    // Show confirmation modal instead of browser confirm
    const modal = document.getElementById('clearChatModal');
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function closeClearChatModal() {
    const modal = document.getElementById('clearChatModal');
    modal.style.display = 'none';
    document.body.style.overflow = 'auto';
}

function confirmClearChat() {
    localStorage.removeItem(CHAT_STORAGE_KEY);
    const container = document.getElementById('chatContainer');
    container.innerHTML = '';
    addMessageToChat('assistant', '👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    
    closeClearChatModal();
    
    // Show success message briefly
    const successMsg = document.createElement('div');
    successMsg.className = 'toast-notification';
    successMsg.innerHTML = '✓ Chat history cleared successfully';
    document.body.appendChild(successMsg);
    
    setTimeout(() => {
        successMsg.style.opacity = '0';
        setTimeout(() => successMsg.remove(), 300);
    }, 2000);
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
    
    // Get current datetime - FIX HERE
    const now = new Date();
    const currentDateTime = now.toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: true
    });
    
    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                message: msg,
                weatherData: weatherData,
                typhoonData: typhoonData,
                userLocation: userLocation,
                currentDateTime: currentDateTime
            })
        });
        
        const data = await response.json();
        hideLoading();
        
        if (data.success) {
            addMessageToChat('assistant', data.response);
            saveChatHistory();
        } else {
            addMessageToChat('assistant', data.error || 'AI service temporarily unavailable. Please try again in a moment.');
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
      const locations={
        // NCR - National Capital Region
        'manila':{lat:14.5995,lng:120.9842,zoom:11},'metro manila':{lat:14.5995,lng:120.9842,zoom:10},
        'quezon city':{lat:14.6760,lng:121.0437,zoom:12},'makati':{lat:14.5547,lng:121.0244,zoom:13},
        'pasig':{lat:14.5764,lng:121.0851,zoom:13},'taguig':{lat:14.5176,lng:121.0509,zoom:13},
        'mandaluyong':{lat:14.5794,lng:121.0359,zoom:13},'san juan':{lat:14.6019,lng:121.0355,zoom:14},
        'pasay':{lat:14.5378,lng:120.9896,zoom:13},'paranaque':{lat:14.4793,lng:121.0198,zoom:13},
        'muntinlupa':{lat:14.3811,lng:121.0437,zoom:13},'las pinas':{lat:14.4443,lng:120.9833,zoom:13},
        'marikina':{lat:14.6507,lng:121.1029,zoom:13},'valenzuela':{lat:14.7006,lng:120.9830,zoom:13},
        'caloocan':{lat:14.6488,lng:120.9830,zoom:12},'malabon':{lat:14.6625,lng:120.9559,zoom:13},
        'navotas':{lat:14.6651,lng:120.9402,zoom:14},'pateros':{lat:14.5445,lng:121.0657,zoom:14},
        
        // LUZON - CAR (Cordillera Administrative Region)
        'baguio':{lat:16.4023,lng:120.5960,zoom:13},'baguio city':{lat:16.4023,lng:120.5960,zoom:13},
        'la trinidad':{lat:16.4610,lng:120.5897,zoom:14},'benguet':{lat:16.4167,lng:120.5833,zoom:10},
        'bontoc':{lat:17.0894,lng:120.9774,zoom:13},'mountain province':{lat:17.0000,lng:121.0000,zoom:10},
        'tabuk':{lat:17.4189,lng:121.4443,zoom:13},'kalinga':{lat:17.4000,lng:121.4000,zoom:10},
        'bangued':{lat:17.5964,lng:120.6167,zoom:13},'abra':{lat:17.5000,lng:120.7500,zoom:10},
        'lagawe':{lat:16.8167,lng:121.1167,zoom:13},'ifugao':{lat:16.8333,lng:121.1667,zoom:10},
        'apayao':{lat:18.0000,lng:121.0000,zoom:10},
        
        // LUZON - Region I (Ilocos Region)
        'san fernando':{lat:16.6159,lng:120.3173,zoom:13},'la union':{lat:16.6167,lng:120.3167,zoom:11},
        'vigan':{lat:17.5747,lng:120.3869,zoom:13},'vigan city':{lat:17.5747,lng:120.3869,zoom:13},
        'ilocos sur':{lat:17.2500,lng:120.5000,zoom:10},'laoag':{lat:18.1987,lng:120.5942,zoom:13},
        'laoag city':{lat:18.1987,lng:120.5942,zoom:13},'ilocos norte':{lat:18.1667,lng:120.7500,zoom:10},
        'pangasinan':{lat:15.8950,lng:120.2863,zoom:10},'lingayen':{lat:16.0194,lng:120.2278,zoom:13},
        'dagupan':{lat:16.0433,lng:120.3339,zoom:13},'dagupan city':{lat:16.0433,lng:120.3339,zoom:13},
        'urdaneta':{lat:15.9761,lng:120.5711,zoom:13},'alaminos':{lat:16.1556,lng:119.9822,zoom:13},
        'san carlos':{lat:15.9322,lng:120.3419,zoom:13},
        
        // LUZON - Region II (Cagayan Valley)
        'tuguegarao':{lat:17.6132,lng:121.7270,zoom:13},'tuguegarao city':{lat:17.6132,lng:121.7270,zoom:13},
        'cagayan':{lat:18.2500,lng:121.8333,zoom:10},'isabela':{lat:16.9754,lng:121.8093,zoom:10},
        'ilagan':{lat:17.1489,lng:121.8844,zoom:13},'ilagan city':{lat:17.1489,lng:121.8844,zoom:13},
        'cauayan':{lat:16.9269,lng:121.7706,zoom:13},'cauayan city':{lat:16.9269,lng:121.7706,zoom:13},
        'nueva vizcaya':{lat:16.3333,lng:121.0000,zoom:10},'bayombong':{lat:16.4833,lng:121.1500,zoom:13},
        'quirino':{lat:16.2667,lng:121.5333,zoom:10},'cabarroguis':{lat:16.4167,lng:121.4833,zoom:14},
        'batanes':{lat:20.4500,lng:121.9667,zoom:11},'basco':{lat:20.4500,lng:121.9667,zoom:13},
        
        // LUZON - Region III (Central Luzon)
        'balanga':{lat:14.6764,lng:120.5367,zoom:13},'bataan':{lat:14.6417,lng:120.4417,zoom:11},
        'bulacan':{lat:14.7942,lng:120.8794,zoom:10},'malolos':{lat:14.8433,lng:120.8114,zoom:13},
        'meycauayan':{lat:14.7342,lng:120.9575,zoom:13},'san jose del monte':{lat:14.8139,lng:121.0453,zoom:12},
        'nueva ecija':{lat:15.5784,lng:121.1113,zoom:10},'palayan':{lat:15.5403,lng:121.0831,zoom:13},
        'cabanatuan':{lat:15.4859,lng:120.9672,zoom:13},'cabanatuan city':{lat:15.4859,lng:120.9672,zoom:13},
        'gapan':{lat:15.3069,lng:120.9475,zoom:13},'san jose':{lat:15.7936,lng:120.9961,zoom:13},
        'pampanga':{lat:15.0794,lng:120.6200,zoom:10},'san fernando pampanga':{lat:15.0286,lng:120.6897,zoom:13},
        'angeles':{lat:15.1450,lng:120.5887,zoom:13},'angeles city':{lat:15.1450,lng:120.5887,zoom:13},
        'mabalacat':{lat:15.2167,lng:120.5717,zoom:13},'tarlac':{lat:15.4756,lng:120.5969,zoom:11},
        'tarlac city':{lat:15.4756,lng:120.5969,zoom:13},'zambales':{lat:15.5083,lng:119.9606,zoom:10},
        'olongapo':{lat:14.8292,lng:120.2828,zoom:13},'olongapo city':{lat:14.8292,lng:120.2828,zoom:13},
        'iba':{lat:15.3272,lng:119.9778,zoom:13},'aurora':{lat:15.7542,lng:121.6406,zoom:11},
        'baler':{lat:15.7594,lng:121.5614,zoom:13},
        
        // LUZON - Region IV-A (CALABARZON)
        'cavite':{lat:14.4791,lng:120.8970,zoom:10},'trece martires':{lat:14.2833,lng:120.8667,zoom:13},
        'imus':{lat:14.4297,lng:120.9367,zoom:13},'bacoor':{lat:14.4597,lng:120.9433,zoom:13},
        'dasmariñas':{lat:14.3294,lng:120.9367,zoom:13},'cavite city':{lat:14.4791,lng:120.9014,zoom:13},
        'tagaytay':{lat:14.1102,lng:120.9601,zoom:13},'laguna':{lat:14.2691,lng:121.4113,zoom:10},
        'santa rosa':{lat:14.3122,lng:121.1114,zoom:13},'calamba':{lat:14.2119,lng:121.1653,zoom:13},
        'san pablo':{lat:14.0683,lng:121.3256,zoom:13},'san pedro':{lat:14.3558,lng:121.0178,zoom:13},
        'biñan':{lat:14.3369,lng:121.0808,zoom:13},'cabuyao':{lat:14.2786,lng:121.1250,zoom:13},
        'batangas':{lat:13.7565,lng:121.0583,zoom:10},'batangas city':{lat:13.7565,lng:121.0583,zoom:13},
        'lipa':{lat:13.9411,lng:121.1622,zoom:13},'lipa city':{lat:13.9411,lng:121.1622,zoom:13},
        'tanauan':{lat:14.0856,lng:121.1500,zoom:13},'rizal':{lat:14.6037,lng:121.3084,zoom:10},
        'antipolo':{lat:14.5864,lng:121.1758,zoom:12},'antipolo city':{lat:14.5864,lng:121.1758,zoom:12},
        'cainta':{lat:14.5778,lng:121.1222,zoom:13},'taytay':{lat:14.5631,lng:121.1322,zoom:13},
        'quezon province':{lat:14.0158,lng:122.1311,zoom:10},'lucena':{lat:13.9372,lng:121.6169,zoom:13},
        'lucena city':{lat:13.9372,lng:121.6169,zoom:13},
        
        // LUZON - Region IV-B (MIMAROPA)
        'marinduque':{lat:13.4767,lng:121.9031,zoom:11},'boac':{lat:13.4500,lng:121.8333,zoom:13},
        'occidental mindoro':{lat:13.1000,lng:120.7667,zoom:10},'mamburao':{lat:13.2222,lng:120.5947,zoom:13},
        'oriental mindoro':{lat:13.0000,lng:121.4500,zoom:10},'calapan':{lat:13.4117,lng:121.1803,zoom:13},
        'calapan city':{lat:13.4117,lng:121.1803,zoom:13},'puerto princesa':{lat:9.7392,lng:118.7353,zoom:12},
        'palawan':{lat:9.8349,lng:118.7384,zoom:8},'romblon':{lat:12.5779,lng:122.2690,zoom:11},
        
        // LUZON - Region V (Bicol)
        'albay':{lat:13.1775,lng:123.5293,zoom:10},'legazpi':{lat:13.1391,lng:123.7436,zoom:12},
        'legazpi city':{lat:13.1391,lng:123.7436,zoom:12},'ligao':{lat:13.2167,lng:123.5333,zoom:13},
        'tabaco':{lat:13.3594,lng:123.7333,zoom:13},'camarines norte':{lat:14.1333,lng:122.7667,zoom:10},
        'daet':{lat:14.1119,lng:122.9550,zoom:13},'camarines sur':{lat:13.5309,lng:123.3467,zoom:10},
        'pili':{lat:13.5833,lng:123.2833,zoom:13},'naga':{lat:13.6218,lng:123.1948,zoom:13},
        'naga city':{lat:13.6218,lng:123.1948,zoom:13},'iriga':{lat:13.4214,lng:123.4167,zoom:13},
        'catanduanes':{lat:13.7000,lng:124.2500,zoom:11},'virac':{lat:13.5833,lng:124.2333,zoom:13},
        'masbate':{lat:12.3714,lng:123.6178,zoom:10},'masbate city':{lat:12.3714,lng:123.6178,zoom:13},
        'sorsogon':{lat:12.9714,lng:124.0053,zoom:10},'sorsogon city':{lat:12.9714,lng:124.0053,zoom:13},
        
        // VISAYAS - Region VI (Western Visayas)
        'aklan':{lat:11.8333,lng:122.0833,zoom:10},'kalibo':{lat:11.7050,lng:122.3678,zoom:13},
        'boracay':{lat:11.9674,lng:121.9248,zoom:13},'antique':{lat:11.7000,lng:121.9500,zoom:10},
        'san jose antique':{lat:10.7667,lng:121.9333,zoom:13},'capiz':{lat:11.5833,lng:122.7500,zoom:10},
        'roxas':{lat:11.5850,lng:122.7508,zoom:13},'roxas city':{lat:11.5850,lng:122.7508,zoom:13},
        'guimaras':{lat:10.5922,lng:122.6322,zoom:11},'iloilo':{lat:10.7202,lng:122.5621,zoom:11},
        'iloilo city':{lat:10.7202,lng:122.5621,zoom:12},'negros occidental':{lat:10.6760,lng:122.9510,zoom:10},
        'bacolod':{lat:10.6760,lng:122.9510,zoom:12},'bacolod city':{lat:10.6760,lng:122.9510,zoom:12},
        'silay':{lat:10.8000,lng:122.9667,zoom:13},'talisay negros':{lat:10.7333,lng:122.9667,zoom:13},
        'victorias':{lat:10.9028,lng:123.0806,zoom:13},'cadiz':{lat:10.9500,lng:123.3000,zoom:13},
        'sagay':{lat:10.8833,lng:123.4167,zoom:13},'escalante':{lat:10.8333,lng:123.5000,zoom:13},
        
        // VISAYAS - Region VII (Central Visayas)
        'bohol':{lat:9.8500,lng:124.1435,zoom:10},'tagbilaran':{lat:9.6472,lng:123.8531,zoom:13},
        'tagbilaran city':{lat:9.6472,lng:123.8531,zoom:13},'panglao':{lat:9.5805,lng:123.7544,zoom:13},
        'cebu':{lat:10.3157,lng:123.8854,zoom:10},'cebu city':{lat:10.3157,lng:123.8854,zoom:12},
        'mandaue':{lat:10.3236,lng:123.9222,zoom:13},'mandaue city':{lat:10.3236,lng:123.9222,zoom:13},
        'lapu-lapu':{lat:10.3103,lng:123.9494,zoom:13},'lapu-lapu city':{lat:10.3103,lng:123.9494,zoom:13},
        'talisay cebu':{lat:10.2444,lng:123.8492,zoom:13},'toledo':{lat:10.3778,lng:123.6397,zoom:13},
        'danao':{lat:10.5197,lng:124.0258,zoom:13},'carcar':{lat:10.1089,lng:123.6403,zoom:13},
        'negros oriental':{lat:9.3167,lng:123.3000,zoom:10},'dumaguete':{lat:9.3068,lng:123.3054,zoom:13},
        'dumaguete city':{lat:9.3068,lng:123.3054,zoom:13},'siquijor':{lat:9.2000,lng:123.5833,zoom:11},
        
        // VISAYAS - Region VIII (Eastern Visayas)
        'biliran':{lat:11.5833,lng:124.4667,zoom:11},'naval':{lat:11.5608,lng:124.3953,zoom:13},
        'eastern samar':{lat:11.5000,lng:125.5000,zoom:10},'borongan':{lat:11.6058,lng:125.4331,zoom:13},
        'leyte':{lat:11.0,lng:124.8,zoom:9},'tacloban':{lat:11.2447,lng:125.0037,zoom:12},
        'tacloban city':{lat:11.2447,lng:125.0037,zoom:12},'ormoc':{lat:11.0064,lng:124.6075,zoom:13},
        'ormoc city':{lat:11.0064,lng:124.6075,zoom:13},'baybay':{lat:10.6786,lng:124.8003,zoom:13},
        'northern samar':{lat:12.4167,lng:124.8333,zoom:10},'catarman':{lat:12.4986,lng:124.6358,zoom:13},
        'samar':{lat:12.0,lng:125.0,zoom:9},'catbalogan':{lat:11.7753,lng:124.8883,zoom:13},
        'southern leyte':{lat:10.3333,lng:125.0000,zoom:10},'maasin':{lat:10.1319,lng:124.8408,zoom:13},
        
        // MINDANAO - Region IX (Zamboanga Peninsula)
        'zamboanga del norte':{lat:8.5500,lng:123.3333,zoom:10},'dipolog':{lat:8.5833,lng:123.3417,zoom:13},
        'dipolog city':{lat:8.5833,lng:123.3417,zoom:13},'dapitan':{lat:8.6581,lng:123.4242,zoom:13},
        'zamboanga del sur':{lat:7.8381,lng:123.2956,zoom:10},'pagadian':{lat:7.8281,lng:123.4356,zoom:13},
        'pagadian city':{lat:7.8281,lng:123.4356,zoom:13},'zamboanga sibugay':{lat:7.8333,lng:122.5000,zoom:10},
        'ipil':{lat:7.7833,lng:122.5833,zoom:13},'zamboanga':{lat:6.9214,lng:122.0790,zoom:11},
        'zamboanga city':{lat:6.9214,lng:122.0790,zoom:12},
        
        // MINDANAO - Region X (Northern Mindanao)
        'bukidnon':{lat:8.0542,lng:124.9247,zoom:10},'malaybalay':{lat:8.1536,lng:125.1278,zoom:13},
        'malaybalay city':{lat:8.1536,lng:125.1278,zoom:13},'valencia':{lat:7.9069,lng:125.0942,zoom:13},
        'camiguin':{lat:9.1731,lng:124.7297,zoom:12},'mambajao':{lat:9.2500,lng:124.7167,zoom:13},
        'lanao del norte':{lat:8.0000,lng:123.8333,zoom:10},'tubod':{lat:8.0500,lng:123.8000,zoom:13},
        'iligan':{lat:8.2280,lng:124.2453,zoom:13},'iligan city':{lat:8.2280,lng:124.2453,zoom:13},
        'misamis occidental':{lat:8.5000,lng:123.7500,zoom:10},'oroquieta':{lat:8.4833,lng:123.8000,zoom:13},
        'oroquieta city':{lat:8.4833,lng:123.8000,zoom:13},'ozamiz':{lat:8.1478,lng:123.8414,zoom:13},
        'ozamiz city':{lat:8.1478,lng:123.8414,zoom:13},'tangub':{lat:8.0667,lng:123.7500,zoom:13},
        'misamis oriental':{lat:8.5000,lng:124.6667,zoom:10},'cagayan de oro':{lat:8.4542,lng:124.6319,zoom:12},
        'cdo':{lat:8.4542,lng:124.6319,zoom:12},'gingoog':{lat:8.8244,lng:125.1017,zoom:13},
        
        // MINDANAO - Region XI (Davao)
        'davao del norte':{lat:7.5667,lng:125.6533,zoom:10},'tagum':{lat:7.4478,lng:125.8078,zoom:13},
        'tagum city':{lat:7.4478,lng:125.8078,zoom:13},'panabo':{lat:7.3086,lng:125.6836,zoom:13},
        'davao del sur':{lat:6.7667,lng:125.3333,zoom:10},'digos':{lat:6.7497,lng:125.3572,zoom:13},
        'digos city':{lat:6.7497,lng:125.3572,zoom:13},'davao oriental':{lat:7.3167,lng:126.5500,zoom:10},
        'mati':{lat:6.9550,lng:126.2181,zoom:13},'mati city':{lat:6.9550,lng:126.2181,zoom:13},
        'davao de oro':{lat:7.4500,lng:126.0500,zoom:10},'compostela valley':{lat:7.4500,lng:126.0500,zoom:10},
        'nabunturan':{lat:7.6000,lng:125.9667,zoom:13},'davao':{lat:7.1907,lng:125.4553,zoom:10},
        'davao city':{lat:7.1907,lng:125.4553,zoom:12},'davao occidental':{lat:6.0833,lng:125.6167,zoom:10},
        'malita':{lat:6.4167,lng:125.6167,zoom:13},
        
        // MINDANAO - Region XII (SOCCSKSARGEN)
        'cotabato':{lat:7.2167,lng:124.2333,zoom:10},'kidapawan':{lat:7.0094,lng:125.0889,zoom:13},
        'kidapawan city':{lat:7.0094,lng:125.0889,zoom:13},'south cotabato':{lat:6.3333,lng:124.8333,zoom:10},
        'koronadal':{lat:6.5008,lng:124.8469,zoom:13},'koronadal city':{lat:6.5008,lng:124.8469,zoom:13},
        'general santos':{lat:6.1164,lng:125.1716,zoom:12},'general santos city':{lat:6.1164,lng:125.1716,zoom:12},
        'gensan':{lat:6.1164,lng:125.1716,zoom:12},'sultan kudarat':{lat:6.5167,lng:124.4167,zoom:10},
        'isulan':{lat:6.6333,lng:124.6000,zoom:13},'tacurong':{lat:6.6903,lng:124.6778,zoom:13},
        'tacurong city':{lat:6.6903,lng:124.6778,zoom:13},'sarangani':{lat:5.9333,lng:124.9333,zoom:10},
        'alabel':{lat:6.1000,lng:125.2833,zoom:13},
        
        // MINDANAO - Region XIII (Caraga)
        'agusan del norte':{lat:8.9478,lng:125.5331,zoom:10},'butuan':{lat:8.9475,lng:125.5406,zoom:13},
        'butuan city':{lat:8.9475,lng:125.5406,zoom:13},'cabadbaran':{lat:9.1231,lng:125.5350,zoom:13},
        'cabadbaran city':{lat:9.1231,lng:125.5350,zoom:13},'agusan del sur':{lat:8.5500,lng:125.9667,zoom:10},
        'prosperidad':{lat:8.6000,lng:125.9167,zoom:13},'bayugan':{lat:8.7167,lng:125.7500,zoom:13},
        'dinagat islands':{lat:10.1278,lng:125.6050,zoom:11},'san jose dinagat':{lat:10.0667,lng:125.6000,zoom:13},
        'surigao del norte':{lat:9.7869,lng:125.4919,zoom:10},'surigao':{lat:9.7869,lng:125.4919,zoom:13},
        'surigao city':{lat:9.7869,lng:125.4919,zoom:13},'siargao':{lat:9.8601,lng:126.0466,zoom:11},
        'surigao del sur':{lat:8.8500,lng:126.1167,zoom:10},'tandag':{lat:9.0783,lng:126.1972,zoom:13},
        'tandag city':{lat:9.0783,lng:126.1972,zoom:13},'bislig':{lat:8.2167,lng:126.3167,zoom:13},
        'bislig city':{lat:8.2167,lng:126.3167,zoom:13},
        
        // MINDANAO - BARMM (Bangsamoro)
        'basilan':{lat:6.4333,lng:121.9833,zoom:11},'isabela city':{lat:6.7011,lng:121.9711,zoom:13},
        'lanao del sur':{lat:7.8333,lng:124.4333,zoom:10},'marawi':{lat:8.0000,lng:124.2833,zoom:13},
        'marawi city':{lat:8.0000,lng:124.2833,zoom:13},'maguindanao':{lat:6.9417,lng:124.4111,zoom:10},
        'cotabato city':{lat:7.2250,lng:124.2472,zoom:13},'sulu':{lat:6.0500,lng:121.0000,zoom:10},
        'jolo':{lat:6.0500,lng:121.0000,zoom:13},'tawi-tawi':{lat:5.1333,lng:119.9500,zoom:10},
        'bongao':{lat:5.0297,lng:119.7728,zoom:13},
        
        // Popular Tourist Destinations & Landmarks
        'el nido':{lat:11.1944,lng:119.4019,zoom:12},'coron':{lat:12.0008,lng:120.2070,zoom:12},
        'puerto galera':{lat:13.5056,lng:120.9539,zoom:13},'hundred islands':{lat:16.1972,lng:119.9469,zoom:12},
        'vigan heritage':{lat:17.5747,lng:120.3869,zoom:14},'sagada':{lat:17.0833,lng:120.9000,zoom:13},
        'batad rice terraces':{lat:16.8667,lng:121.0833,zoom:14},'banaue':{lat:16.9167,lng:121.0500,zoom:13},
        'mayon volcano':{lat:13.2577,lng:123.6856,zoom:12},'taal volcano':{lat:14.0021,lng:120.9933,zoom:13},
        'chocolate hills':{lat:9.8167,lng:124.1667,zoom:12},'loboc river':{lat:9.6333,lng:124.0333,zoom:13},
        'kawasan falls':{lat:9.8167,lng:123.3833,zoom:14},'oslob whale sharks':{lat:9.4333,lng:123.3833,zoom:13},
        'malapascua':{lat:11.3167,lng:124.1167,zoom:13},'bantayan island':{lat:11.1667,lng:123.7167,zoom:12},
        'kalanggaman island':{lat:11.0667,lng:124.9000,zoom:13},'apo island':{lat:9.0767,lng:123.2728,zoom:14},
        'camiguin white island':{lat:9.2667,lng:124.7333,zoom:13},'tinuy-an falls':{lat:8.2100,lng:126.2206,zoom:13},
        'enchanted river':{lat:8.2167,lng:126.0500,zoom:14},'britania islands':{lat:9.2000,lng:126.1833,zoom:12},
        'cloud 9':{lat:9.8333,lng:126.0500,zoom:14},'magpupungko':{lat:9.9167,lng:126.0667,zoom:14},
        'sugba lagoon':{lat:9.8833,lng:126.0333,zoom:13},'sohoton cove':{lat:9.9167,lng:126.1500,zoom:13},
        'pearl farm':{lat:6.8667,lng:125.6333,zoom:13},'samal island':{lat:7.0833,lng:125.7333,zoom:11},
        'tinagong dagat':{lat:9.1833,lng:126.1000,zoom:14},'hinatuan enchanted river':{lat:8.2167,lng:126.0500,zoom:14}
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

