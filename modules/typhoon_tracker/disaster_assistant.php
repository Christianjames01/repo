<?php
/**
 * AI Disaster Safety Assistant with Typhoon Tracking
 * Simple Clean UI Version with Chat History
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) && 
    strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    
    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data || !isset($data['message'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }
    
    $userMessage = trim($data['message']);
    $weatherData = $data['weatherData'] ?? null;
    $typhoonData = $data['typhoonData'] ?? [];
    $userLocation = $data['userLocation'] ?? 'Philippines';
    
  $config = parse_ini_file(dirname(__FILE__) . '/../../config.ini');
$GROQ_API_KEY = $config['GROQ_API_KEY'];
    
    $aiResponse = callGroqAPI($GROQ_API_KEY, $userMessage, $weatherData, $typhoonData, $userLocation);
    
    if ($aiResponse['success']) {
        echo json_encode(['success' => true, 'response' => $aiResponse['text'], 'model' => 'llama-3.3-70b', 'isRealAI' => true]);
    } else {
        $fallback = getIntelligentFallback($userMessage, $weatherData, $typhoonData, $userLocation);
        echo json_encode(['success' => true, 'response' => $fallback, 'model' => 'intelligent_fallback', 'isRealAI' => false]);
    }
    exit();
}

function callGroqAPI($apiKey, $message, $weatherData, $typhoonData, $userLocation) {
    if (empty($apiKey)) return ['success' => false];
    
    $context = "You are a disaster preparedness assistant for the Philippines. User is in {$userLocation}. Provide clear, practical safety advice in 2-4 sentences.\n\n";
    
    if ($weatherData) {
        $context .= "Current weather: {$weatherData['windSpeed']} km/h winds, {$weatherData['temperature']}°C, {$weatherData['pressure']} hPa pressure.\n\n";
    }
    
    if (!empty($typhoonData)) {
        $context .= "ACTIVE TYPHOONS:\n";
        foreach ($typhoonData as $t) {
            $context .= "- {$t['name']}: {$t['windSpeed']} km/h winds, {$t['distance']} km away\n";
        }
    }
    
    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => [
            ['role' => 'system', 'content' => $context],
            ['role' => 'user', 'content' => $message]
        ],
        'temperature' => 0.7,
        'max_tokens' => 500
    ]);
    
    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => trim($result['choices'][0]['message']['content'])];
        }
    }
    
    return ['success' => false];
}

function getIntelligentFallback($message, $weatherData, $typhoonData, $userLocation) {
    $msg = strtolower($message);
    
    if (!empty($typhoonData)) {
        $t = $typhoonData[0];
        if (preg_match('/\b(typhoon|storm|alert|status|danger)\b/i', $msg)) {
            if ($t['distance'] < 300) {
                return "⚠️ TYPHOON ALERT: {$t['name']} is {$t['distance']} km from you with {$t['windSpeed']} km/h winds. This is VERY close! Prepare to evacuate if ordered. Secure your home, gather emergency supplies, and monitor PAGASA updates constantly.";
            } else {
                return "🌀 Active Typhoon: {$t['name']} is {$t['distance']} km away with {$t['windSpeed']} km/h winds. Monitor PAGASA for updates and prepare your emergency kit.";
            }
        }
    }
    
    if (preg_match('/\b(safe|outside|go out)\b/i', $msg) && $weatherData) {
        $wind = floatval($weatherData['windSpeed']);
        if ($wind > 118) return "⚠️ EXTREME DANGER - DO NOT go outside! Typhoon-force winds ({$wind} km/h). Stay indoors, away from windows.";
        if ($wind > 60) return "⚠️ DANGEROUS - Strong winds ({$wind} km/h). Stay indoors, secure loose objects.";
        if ($wind > 39) return "⚠️ CAUTION - Moderate winds ({$wind} km/h). Limit outdoor activities.";
        return "✅ Safe in {$userLocation} - Calm winds ({$wind} km/h). " . (!empty($typhoonData) ? "Active typhoons in region - stay alert." : "");
    }
    
    if (preg_match('/\b(prepare|kit|supplies)\b/i', $msg)) {
        return "Emergency Kit: 3L water/person daily, 3-day non-perishable food, first aid kit, flashlight, batteries, radio, medications, waterproof document copies, cash, power bank.";
    }
    
    if (preg_match('/\b(evacuate|evacuation)\b/i', $msg)) {
        return "Evacuate if: Mandatory order issued, flood/landslide risk area, Signal #3+ typhoon approaching, or unsafe home. Contact local DRRMO or call 911.";
    }
    
    if (preg_match('/\b(signal|pagasa)\b/i', $msg)) {
        return "PAGASA Signals: #1(39-61 km/h), #2(62-88 km/h), #3(89-117 km/h), #4(118-184 km/h), #5(185+ km/h). Call (02) 8284-0800.";
    }
    
    return "Kumusta! Ask about: typhoon status, safety, emergency prep, evacuation, PAGASA signals. NDRRMC: 911 | PAGASA: (02) 8284-0800";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌀 Typhoon Tracker Philippines</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #1c1e21;
        }
        
       .header {
    background: #fff;
    border-bottom: 1px solid #e4e6eb;
    padding: 1.5rem 2rem;
    box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    text-align: center;
    position: relative;
}
        
        .header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            color: #1c1e21;
            margin-bottom: 0.25rem;
        }
        
        .header p {
            font-size: 0.875rem;
            color: #65676b;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem;
            display: grid;
            grid-template-columns: 320px 1fr 380px;
            gap: 1.5rem;
        }
        
        .forecast-full {
            grid-column: 1 / -1;
        }
        
        @media (max-width: 1200px) {
            .container {
                grid-template-columns: 1fr;
            }
        }
        
        .card {
            background: #fff;
            border-radius: 8px;
            border: 1px solid #e4e6eb;
            overflow: hidden;
        }
        
        .card-header {
            padding: 1rem 1.25rem;
            border-bottom: 1px solid #e4e6eb;
            display: flex;
            justify-content: space-between;
            
            align-items: center;
            background: #f8f9fa;
        }
        
        .card-title {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #1c1e21;
        }
        
        .refresh-btn {
            background: #fff;
            border: 1px solid #ccd0d5;
            color: #1c1e21;
            padding: 0.375rem 0.875rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all 0.2s;
        }
        
        .refresh-btn:hover {
            background: #f2f3f5;
        }

        .clear-chat-btn {
            background: #fff;
            border: 1px solid #dc3545;
            color: #dc3545;
            padding: 0.375rem 0.875rem;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.8125rem;
            font-weight: 500;
            transition: all 0.2s;
            margin-left: 0.5rem;
        }
        
        .clear-chat-btn:hover {
            background: #dc3545;
            color: #fff;
        }
        
        .typhoon-list {
            padding: 1rem;
            max-height: 350px;
            overflow-y: auto;
        }
        
        .typhoon-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 0.75rem;
            border: 1px solid #e4e6eb;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .typhoon-item:hover {
            border-color: #1877f2;
            background: #f0f8ff;
        }
        
        .typhoon-item.danger {
            border-left: 3px solid #dc3545;
            background: #fff5f5;
        }
        
        .typhoon-item.warning {
            border-left: 3px solid #ffc107;
            background: #fffbf0;
        }
        
        .typhoon-item.info {
            border-left: 3px solid #0d6efd;
        }
        
        .typhoon-name {
            font-size: 1rem;
            font-weight: 600;
            color: #1c1e21;
            margin-bottom: 0.5rem;
        }
        
        .typhoon-details {
            display: flex;
            gap: 1.5rem;
            font-size: 0.875rem;
        }
        
        .detail-item {
            flex: 1;
        }
        
        .detail-label {
            color: #65676b;
            font-size: 0.75rem;
            margin-bottom: 0.125rem;
        }
        
        .detail-value {
            color: #1c1e21;
            font-weight: 600;
        }
        
        .badge {
            display: inline-block;
            padding: 0.25rem 0.625rem;
            border-radius: 4px;
            font-size: 0.6875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .badge-danger {
            background: #dc3545;
            color: #fff;
        }
        
        .badge-warning {
            background: #ffc107;
            color: #000;
        }
        
        .badge-info {
            background: #0d6efd;
            color: #fff;
        }
        
        .weather-section {
            padding: 1rem;
        }
        
        .weather-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        
        .weather-card {
            background: #f8f9fa;
            border: 1px solid #e4e6eb;
            padding: 1rem;
            border-radius: 6px;
            text-align: center;
        }
        
        .weather-icon {
            font-size: 1.75rem;
            margin-bottom: 0.5rem;
        }
        
        .weather-label {
            font-size: 0.75rem;
            color: #65676b;
            margin-bottom: 0.25rem;
        }
        
        .weather-value {
            font-size: 1.25rem;
            font-weight: 600;
            color: #1c1e21;
        }
        
        .location-badge {
            background: #fff3cd;
            border: 1px solid #ffc107;
            color: #856404;
            padding: 0.75rem;
            border-radius: 6px;
            text-align: center;
            font-size: 0.875rem;
        }
        
        #map {
            height: 500px;
        }
        
        .chat-container {
            height: 400px;
            overflow-y: auto;
            padding: 1rem;
            background: #f8f9fa;
        }
        
        .message {
            margin-bottom: 1rem;
            display: flex;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message-bubble {
            max-width: 75%;
            padding: 0.75rem 1rem;
            border-radius: 18px;
            font-size: 0.9375rem;
            line-height: 1.5;
        }
        
        .message.user .message-bubble {
            background: #0d6efd;
            color: #fff;
            border-bottom-right-radius: 4px;
        }
        
        .message.assistant .message-bubble {
            background: #e4e6eb;
            color: #1c1e21;
            border-bottom-left-radius: 4px;
        }
        
        .input-area {
            padding: 1rem;
            background: #fff;
            border-top: 1px solid #e4e6eb;
        }
        
        .quick-questions {
            margin-bottom: 0.75rem;
        }
        
        .quick-questions h3 {
            font-size: 0.8125rem;
            color: #65676b;
            margin-bottom: 0.5rem;
            font-weight: 500;
        }
        
        .quick-btns {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
        }
        
        .quick-btn {
            padding: 0.5rem 0.875rem;
            background: #f0f2f5;
            border: 1px solid #ccd0d5;
            border-radius: 16px;
            font-size: 0.8125rem;
            cursor: pointer;
            transition: all 0.2s;
            color: #1c1e21;
        }
        
        .quick-btn:hover {
            background: #e4e6eb;
        }
        
        .input-group {
            display: flex;
            gap: 0.5rem;
        }
        
        #messageInput {
            flex: 1;
            padding: 0.75rem 1rem;
            border: 1px solid #ccd0d5;
            border-radius: 20px;
            font-size: 0.9375rem;
            outline: none;
            background: #f0f2f5;
        }
        
        #messageInput:focus {
            background: #fff;
            border-color: #1877f2;
        }
        
        #sendBtn {
            padding: 0.75rem 1.5rem;
            background: #0d6efd;
            color: #fff;
            border: none;
            border-radius: 20px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9375rem;
        }
        
        #sendBtn:hover {
            background: #0b5ed7;
        }
        
        #sendBtn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        .empty-state {
            text-align: center;
            padding: 2rem 1rem;
            color: #65676b;
        }
        
        .empty-state-icon {
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
        }
        
        .loading {
            display: none;
        }
        
        .loading.active {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .loading-dots {
            display: flex;
            gap: 0.25rem;
        }
        
        .loading-dots span {
            width: 8px;
            height: 8px;
            background: #65676b;
            border-radius: 50%;
            animation: bounce 1.4s infinite ease-in-out both;
        }
        
        .loading-dots span:nth-child(1) {
            animation-delay: -0.32s;
        }
        
        .loading-dots span:nth-child(2) {
            animation-delay: -0.16s;
        }
        
        @keyframes bounce {
            0%, 80%, 100% {
                transform: scale(0);
            }
            40% {
                transform: scale(1);
            }
        }
        
        .ai-badge {
            display: inline-block;
            margin-top: 0.375rem;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.6875rem;
            font-weight: 600;
        }
        
        .ai-badge.real {
            background: #d1e7dd;
            color: #0f5132;
        }
        
        .ai-badge.fallback {
            background: #fff3cd;
            color: #856404;
        }

        .back-btn {
    position: absolute;
    left: 2rem;
    top: 50%;
    transform: translateY(-50%);
    background: #b0afaf;
    color: #fff;
    border: none;
    padding: 0.75rem 1.5rem;
    border-radius: 8px;
    font-size: 0.9375rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    text-decoration: none;
}

.back-btn:hover {
    background: #676a6f;
    transform: translateY(-50%) translateY(-2px);
    box-shadow: 0 4px 12px rgba(13, 110, 253, 0.3);
}

.back-btn i {
    font-size: 1rem;
}

@media (max-width: 768px) {
    .back-btn {
        position: static;
        transform: none;
        margin: 0 auto 1rem;
        width: fit-content;
    }
    
    .header {
        padding: 1rem;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
}

    </style>
</head>
<body>
   <div class="header">
    <a href="../modules/dashboard/index.php" class="back-btn">
        <i class="fas fa-arrow-left"></i>
        <span>Back</span>
    </a>
    <h1>🌀 Typhoon Tracker Philippines</h1>
    <p>Real-time monitoring and AI safety assistance</p>
</div>
    
    <div class="container">
        <!-- Left: Typhoons & Weather -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🌀 Active Typhoons</span>
                <button class="refresh-btn" onclick="fetchTyphoons()">🔄 Refresh</button>
            </div>
            <div class="typhoon-list" id="typhoonList">
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <div>Scanning for typhoons...</div>
                </div>
            </div>
            
            <div class="weather-section">
                <div class="weather-grid">
                    <div class="weather-card">
                        <div class="weather-icon">🌬️</div>
                        <div class="weather-label">Wind Speed</div>
                        <div class="weather-value" id="windSpeed">--</div>
                    </div>
                    <div class="weather-card">
                        <div class="weather-icon">🌡️</div>
                        <div class="weather-label">Temperature</div>
                        <div class="weather-value" id="temperature">--</div>
                    </div>
                    <div class="weather-card">
                        <div class="weather-icon">📊</div>
                        <div class="weather-label">Pressure</div>
                        <div class="weather-value" id="pressure">--</div>
                    </div>
                    <div class="weather-card">
                        <div class="weather-icon">💧</div>
                        <div class="weather-label">Humidity</div>
                        <div class="weather-value" id="humidity">--</div>
                    </div>
                </div>
                <div class="location-badge">
                    📍 <span id="userLocation">Detecting location...</span>
                </div>
            </div>
        </div>
        
        <!-- Center: Map -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🗺️ Typhoon Map</span>
            </div>
            <div id="map"></div>
        </div>
        
        <!-- Right: AI Chat -->
        <div class="card">
            <div class="card-header">
                <span class="card-title">🤖 AI Safety Assistant</span>
                <button class="clear-chat-btn" onclick="clearChatHistory()">🗑️ Clear</button>
            </div>
            <div class="chat-container" id="chatContainer">
                <!-- Chat messages will be loaded here -->
            </div>
            <div class="input-area">
                <div class="quick-questions">
                    <h3>Quick questions:</h3>
                    <div class="quick-btns">
                        <button class="quick-btn" onclick="askQuestion('Am I safe from typhoons?')">Am I safe?</button>
                        <button class="quick-btn" onclick="askQuestion('Emergency kit needed?')">Emergency kit</button>
                        <button class="quick-btn" onclick="askQuestion('Should I evacuate?')">Evacuate?</button>
                        <button class="quick-btn" onclick="askQuestion('PAGASA signals?')">Signals</button>
                    </div>
                </div>
                <div class="input-group">
                    <input type="text" id="messageInput" placeholder="Ask about typhoons, safety, weather..." autocomplete="off" onkeypress="if(event.key==='Enter')sendMessage()">
                    <button id="sendBtn" onclick="sendMessage()">Send</button>
                </div>
            </div>
        </div>
        
        <!-- Full Width: 7-Day Forecast -->
        <div class="card forecast-full">
            <div class="card-header">
                <span class="card-title">📅 7-Day Weather Forecast</span>
            </div>
            <div style="padding:1.5rem">
                <div id="forecastDays" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem"></div>
            </div>
        </div>
    </div>
    
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script>
let weatherData=null,typhoonData=[],userLocation='Philippines',userCoords={lat:8.4542,lng:124.6319},map=null,markers=[];

// Chat history storage
const CHAT_STORAGE_KEY = 'typhoon_tracker_chat_history';

document.addEventListener('DOMContentLoaded',()=>{
    initMap();
    detectLocation();
    fetchTyphoons();
    loadChatHistory();
});

function initMap(){
    map=L.map('map').setView([12.8797,121.7740],6);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{
        attribution:'© OpenStreetMap',
        maxZoom:19
    }).addTo(map);
}

function detectLocation(){
    if(navigator.geolocation){
        navigator.geolocation.getCurrentPosition(async(p)=>{
            userCoords.lat=p.coords.latitude;
            userCoords.lng=p.coords.longitude;
            
            L.marker([userCoords.lat,userCoords.lng],{
                icon:L.divIcon({
                    className:'',
                    html:'<div style="background:#0d6efd;width:12px;height:12px;border-radius:50%;border:2px solid white;box-shadow:0 2px 6px rgba(0,0,0,0.3)"></div>',
                    iconSize:[12,12]
                })
            }).addTo(map).bindPopup('<strong>Your Location</strong>');
            
            try{
                const r=await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${userCoords.lat}&lon=${userCoords.lng}&format=json`);
                const d=await r.json();
                if(d.address){
                    const c=d.address.city||d.address.town||d.address.municipality;
                    const pr=d.address.state||d.address.province;
                    userLocation=c?`${c}, ${pr}`:pr||'Philippines';
                    document.getElementById('userLocation').textContent=userLocation;
                }
            }catch(e){}
            fetchWeather();
        },()=>{
            document.getElementById('userLocation').textContent='Philippines';
            fetchWeather();
        });
    }else{
        fetchWeather();
    }
}

async function fetchWeather(){
    try{
        const r=await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${userCoords.lat}&longitude=${userCoords.lng}&current_weather=true&hourly=relativehumidity_2m,pressure_msl&daily=temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum&timezone=Asia/Manila&forecast_days=7`);
        const d=await r.json();
        
        if(d.current_weather){
            weatherData={
                windSpeed:d.current_weather.windspeed.toFixed(1),
                temperature:d.current_weather.temperature.toFixed(1),
                pressure:d.hourly.pressure_msl[0]?d.hourly.pressure_msl[0].toFixed(0):'N/A',
                humidity:d.hourly.relativehumidity_2m[0]||'N/A'
            };
            updateWeatherDisplay();
        }
        
        if(d.daily){
            renderForecast(d.daily);
        }
    }catch(e){
        weatherData={windSpeed:'15.0',temperature:'28.0',pressure:'1012',humidity:'75'};
        updateWeatherDisplay();
    }
}

function renderForecast(daily){
    const days=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
    const icons=['☀️','🌤️','⛅','🌧️','⛈️'];
    let html='';
    
    for(let i=0;i<7;i++){
        const date=new Date(daily.time[i]);
        const dayName=i===0?'Today':(i===1?'Tomorrow':days[date.getDay()]);
        const maxTemp=Math.round(daily.temperature_2m_max[i]);
        const minTemp=Math.round(daily.temperature_2m_min[i]);
        const precip=daily.precipitation_sum[i]||0;
        const precipProb=daily.precipitation_probability_max[i]||0;
        
        let icon=icons[0];
        if(precip>10)icon=icons[4];
        else if(precip>5)icon=icons[3];
        else if(precipProb>50)icon=icons[2];
        else if(precipProb>20)icon=icons[1];
        
        html+=`<div style="background:#f8f9fa;border:1px solid #e4e6eb;border-radius:8px;padding:1.5rem;text-align:center;transition:all 0.2s;cursor:pointer" onmouseover="this.style.borderColor='#0d6efd';this.style.background='#f0f8ff'" onmouseout="this.style.borderColor='#e4e6eb';this.style.background='#f8f9fa'">
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
    
    document.getElementById('forecastDays').innerHTML=html;
}

function updateWeatherDisplay(){
    document.getElementById('windSpeed').textContent=weatherData.windSpeed+' km/h';
    document.getElementById('temperature').textContent=weatherData.temperature+'°C';
    document.getElementById('pressure').textContent=weatherData.pressure+' hPa';
    document.getElementById('humidity').textContent=weatherData.humidity+'%';
}

async function fetchTyphoons(){
    try{
        const gdacs=await fetch('https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red&country=PHL');
        const gdacsText=await gdacs.text();
        
        typhoonData=[];
        markers.forEach(m=>map.removeLayer(m));
        markers=[];
        
        if(gdacsText.includes('<item>')){
            const parser=new DOMParser();
            const xml=parser.parseFromString(gdacsText,'text/xml');
            const items=xml.querySelectorAll('item');
            
            items.forEach(item=>{
                const title=item.querySelector('title')?.textContent||'';
                const desc=item.querySelector('description')?.textContent||'';
                const point=item.querySelector('point')?.textContent||'';
                
                if(point){
                    const[lat,lng]=point.split(' ').map(Number);
                    const windMatch=desc.match(/(\d+)\s*km\/h/i)||desc.match(/(\d+)\s*kts/i);
                    let windSpeed=windMatch?parseInt(windMatch[1]):0;
                    
                    if(desc.includes('kts'))windSpeed=Math.round(windSpeed*1.852);
                    
                    const nameMatch=title.match(/Typhoon\s+(\w+)/i)||title.match(/Storm\s+(\w+)/i)||title.match(/TC\s+(\w+)/i);
                    const name=nameMatch?nameMatch[1]:'Tropical Cyclone';
                    
                    const dist=calculateDistance(userCoords.lat,userCoords.lng,lat,lng);
                    
                    typhoonData.push({
                        name:name,
                        lat:lat,
                        lng:lng,
                        windSpeed:windSpeed||85,
                        distance:Math.round(dist)
                    });
                }
            });
        }
        
        typhoonData.sort((a,b)=>a.distance-b.distance);
        updateTyphoonList();
        addTyphoonMarkers();
        
    }catch(e){
        document.getElementById('typhoonList').innerHTML='<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div></div>';
    }
}

function calculateDistance(lat1,lon1,lat2,lon2){
    const R=6371;
    const dLat=(lat2-lat1)*Math.PI/180;
    const dLon=(lon2-lon1)*Math.PI/180;
    const a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)*Math.sin(dLon/2);
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function updateTyphoonList(){
    const list=document.getElementById('typhoonList');
    
    if(typhoonData.length===0){
        list.innerHTML='<div class="empty-state"><div class="empty-state-icon">☀️</div><div>No active typhoons detected</div></div>';
        return;
    }
    
    let html='';
    typhoonData.forEach(t=>{
        const severity=t.distance<300?'danger':(t.distance<600?'warning':'info');
        const badgeClass=t.distance<300?'badge-danger':(t.distance<600?'badge-warning':'badge-info');
        const badgeText=t.distance<300?'⚠️ VERY CLOSE':(t.distance<600?'⚠️ CLOSE':'ℹ️ MONITORING');
        
        html+=`<div class="typhoon-item ${severity}" onclick="focusTyphoon(${t.lat},${t.lng})">
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
    list.innerHTML=html;
}

function addTyphoonMarkers(){
    typhoonData.forEach(t=>{
        const color=t.distance<300?'#dc3545':(t.distance<600?'#ffc107':'#0d6efd');
        
        const marker=L.marker([t.lat,t.lng],{
            icon:L.divIcon({
                className:'',
                html:`<div style="background:${color};width:22px;height:22px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);display:flex;align-items:center;justify-content:center;font-size:12px">🌀</div>`,
                iconSize:[22,22]
            })
        }).addTo(map).bindPopup(`<strong>${t.name}</strong><br>${t.windSpeed} km/h winds<br>${t.distance} km away`);
        markers.push(marker);
        
        const circle=L.circle([t.lat,t.lng],{
            color:color,
            fillColor:color,
            fillOpacity:0.1,
            radius:t.distance*1000,
            weight:2
        }).addTo(map);
        markers.push(circle);
    });
}

function focusTyphoon(lat,lng){
    map.setView([lat,lng],8,{animate:true});
}

// Chat History Functions
function loadChatHistory(){
    try{
        const saved=localStorage.getItem(CHAT_STORAGE_KEY);
        if(saved){
            const messages=JSON.parse(saved);
            const container=document.getElementById('chatContainer');
            container.innerHTML='';
            
            messages.forEach(msg=>{
                const message=document.createElement('div');
                message.className=`message ${msg.role}`;
                
                const bubble=document.createElement('div');
                bubble.className='message-bubble';
                bubble.textContent=msg.content;
                
                if(msg.badge){
                    const badge=document.createElement('div');
                    badge.className=`ai-badge ${msg.badge.type}`;
                    badge.textContent=msg.badge.text;
                    bubble.appendChild(badge);
                }
                
                message.appendChild(bubble);
                container.appendChild(message);
            });
            
            container.scrollTop=container.scrollHeight;
        }else{
            // Show default welcome message if no history
            addMessageToChat('assistant','👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
        }
    }catch(e){
        console.error('Error loading chat history:',e);
        addMessageToChat('assistant','👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    }
}

function saveChatHistory(){
    try{
        const container=document.getElementById('chatContainer');
        const messages=[];
        
        container.querySelectorAll('.message').forEach(msgEl=>{
            const role=msgEl.classList.contains('user')?'user':'assistant';
            const bubble=msgEl.querySelector('.message-bubble');
            const badge=msgEl.querySelector('.ai-badge');
            
            // Clone the bubble and remove badge to get clean text
            const bubbleClone=bubble.cloneNode(true);
            const badgeInClone=bubbleClone.querySelector('.ai-badge');
            if(badgeInClone)badgeInClone.remove();
            
            const content=bubbleClone.textContent.trim();
            
            const msgData={role:role,content:content};
            
            if(badge){
                msgData.badge={
                    type:badge.classList.contains('real')?'real':'fallback',
                    text:badge.textContent
                };
            }
            
            messages.push(msgData);
        });
        
        localStorage.setItem(CHAT_STORAGE_KEY,JSON.stringify(messages));
    }catch(e){
        console.error('Error saving chat history:',e);
    }
}

function clearChatHistory(){
    if(confirm('Are you sure you want to clear all chat history? This cannot be undone.')){
        localStorage.removeItem(CHAT_STORAGE_KEY);
        const container=document.getElementById('chatContainer');
        container.innerHTML='';
        addMessageToChat('assistant','👋 Hello! I\'m your AI Safety Assistant. I can help with typhoon information, safety advice, and emergency guidance. What would you like to know?');
    }
}

async function sendMessage(){
    const input=document.getElementById('messageInput');
    const msg=input.value.trim();
    if(!msg)return;
    
    addMessageToChat('user',msg);
    input.value='';
    
    const sendBtn=document.getElementById('sendBtn');
    sendBtn.disabled=true;
    input.disabled=true;
    
    showLoading();
    
    try{
        const response=await fetch(window.location.href,{
            method:'POST',
            headers:{'Content-Type':'application/json'},
            body:JSON.stringify({
                message:msg,
                weatherData:weatherData,
                typhoonData:typhoonData,
                userLocation:userLocation
            })
        });
        
        const data=await response.json();
        hideLoading();
        
        if(data.success){
            addMessageToChat('assistant',data.response);
            const lastMessage=document.querySelector('.message.assistant:last-child .message-bubble');
            const badge=document.createElement('div');
            badge.className=`ai-badge ${data.isRealAI?'real':'fallback'}`;
            badge.textContent=data.isRealAI?'🤖 AI':'💬 Smart';
            lastMessage.appendChild(badge);
            
            // Save to history after adding badge
            saveChatHistory();
        }else{
            addMessageToChat('assistant','Sorry, an error occurred. Please try again.');
            saveChatHistory();
        }
    }catch(e){
        hideLoading();
        addMessageToChat('assistant','Connection error. Please check your internet and try again.');
        saveChatHistory();
    }finally{
        sendBtn.disabled=false;
        input.disabled=false;
        input.focus();
    }
}

function askQuestion(question){
    document.getElementById('messageInput').value=question;
    sendMessage();
}

function addMessageToChat(role,content){
    const container=document.getElementById('chatContainer');
    const message=document.createElement('div');
    message.className=`message ${role}`;
    
    const bubble=document.createElement('div');
    bubble.className='message-bubble';
    bubble.textContent=content;
    
    message.appendChild(bubble);
    container.appendChild(message);
    container.scrollTop=container.scrollHeight;
    
    if(role==='user'){
        detectLocationInMessage(content);
        saveChatHistory();
    }
}

function detectLocationInMessage(msg){
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
    const msgLower=msg.toLowerCase();
    for(const[place,coords]of Object.entries(locations)){
        if(msgLower.includes(place)){
            setTimeout(()=>{
                map.setView([coords.lat,coords.lng],coords.zoom,{animate:true,duration:1.5});
                const locationMarker=L.marker([coords.lat,coords.lng],{
                    icon:L.divIcon({
                        className:'',
                        html:'<div style="background:#ffc107;width:16px;height:16px;border-radius:50%;border:2px solid white;box-shadow:0 2px 8px rgba(0,0,0,0.3);animation:pulse 2s infinite"></div><style>@keyframes pulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.3);opacity:0.7}}</style>',
                        iconSize:[16,16]
                    })
                }).addTo(map).bindPopup(`<strong>${place.charAt(0).toUpperCase()+place.slice(1)}</strong>`).openPopup();
                markers.push(locationMarker);
            },500);
            break;
        }
    }
}

function showLoading(){
    const container=document.getElementById('chatContainer');
    const loading=document.createElement('div');
    loading.className='message assistant';
    loading.id='loadingMessage';
    loading.innerHTML='<div class="loading active"><div class="loading-dots"><span></span><span></span><span></span></div></div>';
    container.appendChild(loading);
    container.scrollTop=container.scrollHeight;
}

function hideLoading(){
    const loading=document.getElementById('loadingMessage');
    if(loading)loading.remove();
}

setInterval(fetchWeather,300000);
setInterval(fetchTyphoons,600000);
    </script>
</body>
</html>