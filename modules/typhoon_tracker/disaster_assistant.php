<?php
/**
 * ENHANCED AI Disaster Safety Assistant v5.0
 * Improved conversational quality, memory, and response naturalness
 */

// ============================================================
// CONFIG LOADER
// ============================================================
function findConfig() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 6; $i++) {
        $candidate = $dir . '/config.ini';
        if (file_exists($candidate)) {
            $cfg = parse_ini_file($candidate);
            return $cfg;
        }
        $dir = dirname($dir);
    }
    $cfg = [];
    return $cfg;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['CONTENT_TYPE']) &&
    strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {

    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data || !isset($data['message'])) {
        echo json_encode(['success' => false, 'error' => 'Invalid request']);
        exit();
    }

    $userMessage     = trim($data['message']);
    $weatherData     = $data['weatherData']     ?? null;
    $typhoonData     = $data['typhoonData']     ?? [];
    $userLocation    = $data['userLocation']    ?? 'Philippines';
    $currentDateTime = $data['currentDateTime'] ?? null;
    $forecastData    = $data['forecastData']    ?? null;
    $sessionId       = $data['session_id']      ?? 'anonymous';
    if (!empty($data['resident_id'])) $sessionId = 'resident_' . $data['resident_id'];

    $config       = findConfig();
    $GROQ_API_KEY = $config['GROQ_API_KEY'] ?? $config['groq_api_key'] ?? '';

    // Load DB conversation history
    $dbHistory = loadDBHistory($sessionId);

    // Save incoming user message
    saveMessageToDB($sessionId, 'user', $userMessage, $weatherData, $typhoonData, $userLocation);

    // Call AI
    $aiResponse = callGroqAPI(
        $GROQ_API_KEY, $userMessage,
        $weatherData, $typhoonData, $userLocation,
        $currentDateTime, $forecastData,
        $dbHistory
    );

    if ($aiResponse['success']) {
        $responseText = $aiResponse['text'];
        saveMessageToDB($sessionId, 'assistant', $responseText, $weatherData, $typhoonData, $userLocation);
        echo json_encode([
            'success'      => true,
            'response'     => $responseText,
            'model'        => 'llama-3.3-70b',
            'session_id'   => $sessionId,
            'history_used' => count($dbHistory),
        ]);
    } else {
        $fallback = getFallbackResponse($userMessage, $weatherData, $typhoonData, $forecastData);
        saveMessageToDB($sessionId, 'assistant', $fallback, null, null, $userLocation);
        echo json_encode([
            'success'   => true,
            'response'  => $fallback,
            'fallback'  => true,
            'api_error' => $aiResponse['error'] ?? 'Service unavailable',
            'session_id'=> $sessionId,
        ]);
    }
    exit();
}

// ============================================================================
// DATABASE HELPERS
// ============================================================================

function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;

    $config = findConfig();
    $host   = $config['DB_HOST']     ?? 'localhost';
    $db     = $config['DB_NAME']     ?? '';
    $user   = $config['DB_USER']     ?? '';
    $pass   = $config['DB_PASS']     ?? $config['DB_PASSWORD'] ?? '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS typhoon_chat_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_id VARCHAR(128) NOT NULL,
                resident_id INT NULL,
                role ENUM('user','assistant') NOT NULL,
                content TEXT NOT NULL,
                weather_context JSON NULL,
                typhoon_context JSON NULL,
                location_context VARCHAR(255) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_session (session_id),
                INDEX idx_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e) {
        return null;
    }
    return $pdo;
}

function loadDBHistory($sessionId, $pairs = 20) {
    $pdo = getDB();
    if (!$pdo) return [];
    $limit = $pairs * 2;
    $stmt = $pdo->prepare("
        SELECT role, content FROM typhoon_chat_history
        WHERE session_id = :sid
        ORDER BY created_at DESC LIMIT :lim
    ");
    $stmt->bindValue(':sid', $sessionId, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function saveMessageToDB($sessionId, $role, $content, $weatherData = null, $typhoonData = null, $location = null) {
    $pdo = getDB();
    if (!$pdo) return;
    $residentId = null;
    if (preg_match('/^resident_(\d+)$/', $sessionId, $m)) $residentId = (int)$m[1];
    $stmt = $pdo->prepare("
        INSERT INTO typhoon_chat_history
            (session_id, resident_id, role, content, weather_context, typhoon_context, location_context)
        VALUES (:sid, :rid, :role, :content, :weather, :typhoon, :loc)
    ");
    $stmt->execute([
        ':sid'     => $sessionId,
        ':rid'     => $residentId,
        ':role'    => $role,
        ':content' => $content,
        ':weather' => $weatherData ? json_encode($weatherData) : null,
        ':typhoon' => $typhoonData ? json_encode($typhoonData) : null,
        ':loc'     => $location,
    ]);
}

// ============================================================================
// GROQ API — DRAMATICALLY IMPROVED SYSTEM PROMPT
// ============================================================================

function callGroqAPI($apiKey, $message, $weatherData, $typhoonData, $userLocation,
                     $currentDateTime = null, $forecastData = null, $conversationHistory = []) {

    if (empty($apiKey)) return ['success' => false, 'error' => 'API key not configured'];

    // ── DETERMINE CONVERSATION STATE ──────────────────────────────────────────
    $isFirstMessage   = count($conversationHistory) === 0;
    $isFollowUp       = count($conversationHistory) >= 2;
    $hasWeather       = $weatherData !== null;
    $hasTyphoon       = !empty($typhoonData);
    $messageIsQuestion = str_ends_with(trim($message), '?');
    $msgLower = strtolower($message);

    // Classify message intent
    $intent = 'general';
    if (preg_match('/\b(safe|danger|threat|risk|warning)\b/i', $message))    $intent = 'safety';
    if (preg_match('/\b(evacuate|evacuation|leave|shelter)\b/i', $message))   $intent = 'evacuation';
    if (preg_match('/\b(kit|prepare|supplies|ready)\b/i', $message))          $intent = 'preparation';
    if (preg_match('/\b(rain|flood|wind|storm|typhoon|weather|humid)\b/i', $message)) $intent = 'weather_query';
    if (preg_match('/\b(hello|hi|hey|kumusta|kamusta)\b/i', $message))        $intent = 'greeting';
    if (preg_match('/\b(what.*said|remember|last time|before|previous)\b/i', $message)) $intent = 'memory_query';

    // ── SYSTEM PROMPT ──────────────────────────────────────────────────────────
    $sys  = "You are Ligtas AI, a warm and intelligent weather safety assistant for the Philippines.\n";
    $sys .= "You work with the BarangayLink disaster management system to protect residents.\n\n";

    $sys .= "## YOUR CORE PERSONALITY\n";
    $sys .= "- You're a trusted neighbor who happens to know a lot about weather and safety\n";
    $sys .= "- You're honest, caring, and never alarmist — you give people the truth with empathy\n";
    $sys .= "- You speak in a warm, conversational tone — not like a robot or a government bulletin\n";
    $sys .= "- You use Filipino context naturally (barangay, PAGASA, NDRRMC, Tagalog terms when appropriate)\n";
    $sys .= "- You're confident but humble: you know what you know, and you're clear about uncertainty\n\n";

    $sys .= "## STRICT RULES — FOLLOW THESE EXACTLY\n";
    $sys .= "1. NEVER make up weather data. Only discuss data that is explicitly provided to you below.\n";
    $sys .= "2. NEVER say 'I've been analyzing' or 'since we last spoke, I noticed...' unless you actually have REAL previous data from the conversation history.\n";
    $sys .= "3. If the conversation is just starting, say so naturally — don't pretend to have history.\n";
    $sys .= "4. If someone asks 'what did I say last time' and there's NO history, be honest: say this is the start of our conversation.\n";
    $sys .= "5. Keep responses CONVERSATIONAL — aim for 2-4 sentences for simple questions, more detail only when genuinely needed.\n";
    $sys .= "6. Use plain language first, technical terms only when they add clarity.\n";
    $sys .= "7. Don't use excessive emojis or bullet points for simple answers.\n";
    $sys .= "8. When giving safety advice, be specific and actionable — not generic.\n\n";

    $sys .= "## HOW TO HANDLE DIFFERENT SITUATIONS\n\n";

    $sys .= "**Greetings / First message:**\n";
    $sys .= "Give a warm, brief hello. Mention 1-2 things you can help with. Don't be overly formal.\n\n";

    $sys .= "**Safety questions (Am I safe? Should I evacuate?):**\n";
    $sys .= "Lead with a direct answer based on actual current data. Explain WHY briefly. Give one clear action step.\n\n";

    $sys .= "**Weather queries:**\n";
    $sys .= "Interpret the numbers in plain language. What do they actually mean for daily life? Be specific.\n\n";

    $sys .= "**Memory queries (what did I say before?):**\n";
    $sys .= "If you have conversation history, summarize what was discussed naturally.\n";
    $sys .= "If you DON'T have history (first conversation), say clearly: 'This looks like the start of our conversation — I don't have any previous chats from you. What would you like to know?'\n\n";

    $sys .= "**Emergency situations:**\n";
    $sys .= "Be calm but urgent. Give the most important action first. Don't overwhelm with information.\n\n";

    // ── WEATHER CONTEXT ──────────────────────────────────────────────────────
    if ($hasWeather) {
        $wind = floatval($weatherData['windSpeed']);
        $pres = floatval($weatherData['pressure']);
        $hum  = floatval($weatherData['humidity']);
        $temp = floatval($weatherData['temperature']);

        $sys .= "## CURRENT VERIFIED WEATHER DATA (use this, don't make up other numbers)\n";
        $sys .= "Location: {$userLocation}\n";
        if ($currentDateTime) $sys .= "Time: {$currentDateTime}\n";
        $sys .= "• Wind: {$weatherData['windSpeed']} km/h";

        if ($wind > 118) $sys .= " ⚠️ TYPHOON FORCE (Signal #4+)";
        elseif ($wind > 88) $sys .= " ⚠️ STORM FORCE (Signal #3)";
        elseif ($wind > 62) $sys .= " ⚠️ STRONG (Signal #2)";
        elseif ($wind > 39) $sys .= " ⚠️ MODERATE (Signal #1)";
        else $sys .= " ✓ Normal";
        $sys .= "\n";

        $sys .= "• Temperature: {$weatherData['temperature']}°C\n";
        $sys .= "• Pressure: {$weatherData['pressure']} hPa";
        if ($pres < 1000)  $sys .= " ⚠️ CRITICALLY LOW (intense weather system)";
        elseif ($pres < 1005) $sys .= " ⚠️ Very low (active storm)";
        elseif ($pres < 1010) $sys .= " ⚠️ Below normal (unsettled)";
        else $sys .= " ✓ Normal range";
        $sys .= "\n";

        $sys .= "• Humidity: {$weatherData['humidity']}%";
        if ($hum >= 95) $sys .= " ⚠️ SATURATED (heavy rain forming)";
        elseif ($hum >= 90) $sys .= " ⚠️ Very high (rain very likely)";
        elseif ($hum >= 85) $sys .= " ⚠️ High (rain likely)";
        else $sys .= " ✓ Normal";
        $sys .= "\n";

        // Interpretation for AI
        $sys .= "\n**What this weather ACTUALLY means:**\n";
        if ($hum >= 90 && $pres < 1010) {
            $sys .= "Heavy rain conditions — atmosphere is nearly saturated with below-normal pressure driving upward air motion.\n";
        } elseif ($wind > 62) {
            $sys .= "Dangerous wind conditions — caution is warranted for outdoor activities.\n";
        } elseif ($hum > 80 && $pres < 1013) {
            $sys .= "Unsettled weather — expect rain and possibly strong wind gusts.\n";
        } else {
            $sys .= "Generally normal tropical weather conditions.\n";
        }
        $sys .= "\n";
    } else {
        $sys .= "## WEATHER DATA\nNo real-time weather data is available right now. Be honest about this if asked.\n\n";
    }

    // ── TYPHOON CONTEXT ───────────────────────────────────────────────────────
    if ($hasTyphoon) {
        $sys .= "## ACTIVE TYPHOON DATA\n";
        foreach ($typhoonData as $i => $t) {
            $closeness = $t['distance'] < 300 ? '⚠️ VERY CLOSE — IMMEDIATE DANGER' :
                        ($t['distance'] < 600 ? '⚠️ CLOSE — HIGH ALERT' : 'ℹ️ Monitoring');
            $sys .= ($i+1).". **{$t['name']}** — {$t['windSpeed']} km/h winds, {$t['distance']}km away [{$closeness}]\n";
        }
        $sys .= "\n";
    } else {
        $sys .= "## TYPHOON STATUS\nNo active typhoons detected near the Philippines right now.\n\n";
    }

    // ── REFERENCE INFO ────────────────────────────────────────────────────────
    $sys .= "## QUICK REFERENCE\n";
    $sys .= "PAGASA Signals: #1(39-61 km/h) #2(62-88 km/h) #3(89-117 km/h) #4(118-184 km/h) #5(185+ km/h)\n";
    $sys .= "Emergency: NDRRMC 911 | PAGASA (02)8284-0800 | Red Cross 143\n\n";

    // ── CONVERSATION CONTEXT ──────────────────────────────────────────────────
    if ($isFirstMessage) {
        $sys .= "## CONVERSATION STATE\n";
        $sys .= "This is the FIRST message in this conversation. There is NO previous history to reference.\n";
        $sys .= "Do NOT say things like 'since we last spoke' or 'I've been analyzing' — this is a fresh start.\n\n";
    } elseif (count($conversationHistory) >= 4) {
        $sys .= "## CONVERSATION STATE\n";
        $sys .= "You have " . count($conversationHistory) . " previous messages to reference. Use them naturally when relevant.\n";
        $sys .= "If asked what was discussed before, summarize accurately from the actual history.\n\n";
    }

    // ── BUILD MESSAGE ARRAY ───────────────────────────────────────────────────
    $messages = [['role' => 'system', 'content' => $sys]];

    // Include conversation history (last 20 messages max)
    $recentHistory = array_slice($conversationHistory, -20);
    foreach ($recentHistory as $msg) {
        $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
    }
    $messages[] = ['role' => 'user', 'content' => $message];

    // ── GROQ CALL ─────────────────────────────────────────────────────────────
    $payload = json_encode([
        'model'       => 'llama-3.3-70b-versatile',
        'messages'    => $messages,
        'temperature' => 0.65,
        'max_tokens'  => 600,
        'top_p'       => 0.9,
    ]);

    $ch = curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        if (isset($result['choices'][0]['message']['content'])) {
            return ['success' => true, 'text' => trim($result['choices'][0]['message']['content'])];
        }
    }

    $errorMsg = 'AI service error';
    if ($response) {
        $result = json_decode($response, true);
        if (isset($result['error']['message'])) {
            $apiError = $result['error']['message'];
            $errorMsg = (strpos($apiError, 'Rate limit') !== false || strpos($apiError, 'TPD') !== false)
                ? 'Nahihirapan ang AI ngayon. Sandali lang — subukan ulit pagkatapos ng ilang minuto.'
                : $apiError;
        }
    } elseif ($curlErr) {
        $errorMsg = 'Connection error: ' . $curlErr;
    }
    return ['success' => false, 'error' => $errorMsg];
}

// ============================================================================
// FALLBACK RESPONSES
// ============================================================================

function getFallbackResponse($message, $weatherData, $typhoonData, $forecastData) {
    $msgLower = strtolower($message);

    // Memory questions
    if (preg_match('/\b(said|remember|last time|before|previous)\b/i', $message)) {
        return "I don't have memory of a previous conversation right now — this might be a fresh session. But I'm here to help! What would you like to know about weather or safety in your area?";
    }

    // Greetings
    if (preg_match('/\b(hello|hi|hey|kumusta|kamusta|good morning|good afternoon)\b/i', $message)) {
        $greeting = "Kumusta! I'm Ligtas AI, your weather safety assistant. ";
        if ($weatherData) {
            $greeting .= "I can see your current weather data — wind at {$weatherData['windSpeed']} km/h, temperature {$weatherData['temperature']}°C. ";
        }
        $greeting .= "Ask me about typhoon safety, weather conditions, emergency preparation, or evacuation guidance!";
        return $greeting;
    }

    // Typhoon check
    if (!empty($typhoonData)) {
        $t = $typhoonData[0];
        if (preg_match('/\b(safe|danger|typhoon|storm|alert)\b/i', $message)) {
            if ($t['distance'] < 300) {
                return "⚠️ Typhoon {$t['name']} is only {$t['distance']}km away with {$t['windSpeed']} km/h winds — this is very close. You should be on high alert. Follow your barangay's evacuation orders immediately if issued. Emergency: call 911.";
            }
            return "Typhoon {$t['name']} is being tracked at {$t['distance']}km from your area with {$t['windSpeed']} km/h winds. Keep monitoring PAGASA updates and have your emergency kit ready.";
        }
    }

    // Current weather
    if ($weatherData && preg_match('/\b(weather|condition|outside|safe|rain|wind|humid)\b/i', $message)) {
        $wind = floatval($weatherData['windSpeed']);
        $hum  = floatval($weatherData['humidity']);
        $pres = floatval($weatherData['pressure']);

        if ($wind > 118) return "⚠️ DANGER: Typhoon-force winds at {$wind} km/h right now. Stay indoors in the strongest part of your house, away from windows. Do not go outside.";
        if ($wind > 62)  return "⚠️ Strong winds at {$wind} km/h. Avoid outdoor activities, secure loose items, and stay away from trees and power lines.";
        if ($hum >= 90 && $pres < 1010) return "Heavy rain is developing in your area — humidity is at {$weatherData['humidity']}% with low pressure ({$weatherData['pressure']} hPa). Expect sustained rain. Avoid flood-prone areas.";
        if ($hum >= 85) return "It's quite humid at {$weatherData['humidity']}% — rain is likely. Bring an umbrella and check if your drainage is clear.";
        return "Weather looks fairly normal right now: {$weatherData['windSpeed']} km/h winds, {$weatherData['temperature']}°C, {$weatherData['humidity']}% humidity. Nothing alarming at the moment.";
    }

    // Emergency kit
    if (preg_match('/\b(kit|prepare|supplies|emergency|bag|go.bag)\b/i', $message)) {
        return "Your emergency kit should have: at least 3 liters of water per person per day (for 3 days), non-perishable food, a first aid kit, flashlight with extra batteries, battery-powered radio, essential medications, copies of important documents in a waterproof bag, cash, and a power bank. Keep it somewhere accessible.";
    }

    // Evacuation
    if (preg_match('/\b(evacuate|evacuation|leave|flee|where to go)\b/i', $message)) {
        return "Evacuate when: your barangay issues a mandatory order, you're in a flood zone during heavy rain, a Signal #3+ typhoon is approaching, or your home feels unsafe. Contact your local DRRMO for the nearest evacuation center, or call 911 in an emergency.";
    }

    // PAGASA signals
    if (preg_match('/\b(signal|pagasa|warning|level)\b/i', $message)) {
        return "PAGASA Wind Signals: Signal #1 (39-61 km/h) — minimal threat, prepare. Signal #2 (62-88 km/h) — strong winds, secure property. Signal #3 (89-117 km/h) — dangerous, stay indoors. Signal #4 (118-184 km/h) — very destructive, seek shelter immediately. Signal #5 (185+ km/h) — catastrophic, extreme danger. PAGASA hotline: (02) 8284-0800.";
    }

    return "I'm here to help with weather and safety. You can ask me about current conditions, typhoon status, whether to evacuate, or how to prepare an emergency kit. NDRRMC: 911 | PAGASA: (02) 8284-0800";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🌀 Typhoon Tracker Philippines</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <a href="../dashboard/index.php" class="back-btn">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            <span>Back</span>
        </a>
        <div class="header-content">
            <h1>🌀 Typhoon Tracker Philippines</h1>
            <p>Real-time monitoring and AI safety assistance</p>
            <div class="datetime-display">
                <span id="currentDateTime">Loading...</span>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="card">
            <div class="card-header">
                <span class="card-title">🌀 Active Typhoons</span>
                <button class="refresh-btn" onclick="fetchTyphoons()">
                    <span id="refreshIcon">🔄</span> Refresh
                </button>
            </div>
            <div class="typhoon-list" id="typhoonList">
                <div class="empty-state">
                    <div class="empty-state-icon">🔍</div>
                    <div>Scanning for typhoons...</div>
                </div>
            </div>

            <div class="weather-section">
                <div class="weather-header">
                    <h3>Real-Time Weather</h3>
                    <span class="last-update" id="lastUpdate">Updating...</span>
                </div>
                <div class="weather-grid">
                    <div class="weather-card wind">
                        <div class="weather-icon-animated">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none">
                                <path d="M9.59 4.59A2 2 0 1111 8H2m10.59 11.41A2 2 0 1014 16H2m15.73-8.27A2.5 2.5 0 1119.5 12H2" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="weather-info">
                            <div class="weather-label">Wind Speed</div>
                            <div class="weather-value" id="windSpeed"><span class="value-number">--</span><span class="value-unit">km/h</span></div>
                            <div class="weather-status" id="windStatus">Checking...</div>
                        </div>
                    </div>
                    <div class="weather-card temp">
                        <div class="weather-icon-animated">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none">
                                <path d="M14 14.76V3.5a2.5 2.5 0 00-5 0v11.26a4.5 4.5 0 105 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="weather-info">
                            <div class="weather-label">Temperature</div>
                            <div class="weather-value" id="temperature"><span class="value-number">--</span><span class="value-unit">°C</span></div>
                            <div class="weather-status" id="tempStatus">Checking...</div>
                        </div>
                    </div>
                    <div class="weather-card pressure">
                        <div class="weather-icon-animated">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none">
                                <rect x="3" y="3" width="18" height="18" rx="2" stroke="currentColor" stroke-width="2"/>
                                <path d="M9 9h6M9 12h6M9 15h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                        </div>
                        <div class="weather-info">
                            <div class="weather-label">Pressure</div>
                            <div class="weather-value" id="pressure"><span class="value-number">--</span><span class="value-unit">hPa</span></div>
                            <div class="weather-status" id="pressureStatus">Checking...</div>
                        </div>
                    </div>
                    <div class="weather-card humidity">
                        <div class="weather-icon-animated">
                            <svg width="40" height="40" viewBox="0 0 24 24" fill="none">
                                <path d="M12 2.69l5.66 5.66a8 8 0 11-11.31 0z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                        <div class="weather-info">
                            <div class="weather-label">Humidity</div>
                            <div class="weather-value" id="humidity"><span class="value-number">--</span><span class="value-unit">%</span></div>
                            <div class="weather-status" id="humidityStatus">Checking...</div>
                        </div>
                    </div>
                </div>
                <div class="location-badge">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="display:inline-block;vertical-align:middle;margin-right:6px">
                        <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" stroke="currentColor" stroke-width="2"/>
                        <circle cx="12" cy="10" r="3" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <span id="userLocation">Detecting location...</span>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <span class="card-title">🗺️ Typhoon Map</span>
            </div>
            <div id="map" style="height:500px;width:100%;"></div>
        </div>

        <!-- ENHANCED CHAT BUBBLE -->
        <div class="chat-bubble-container" id="chatBubbleContainer">
            <button class="chat-bubble-button" id="chatBubbleBtn" onclick="toggleChatBubble()">
                <div class="chat-bubble-icon-wrap">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                    </svg>
                    <span class="chat-unread-dot" id="chatUnreadDot" style="display:none"></span>
                </div>
                <span class="chat-bubble-label">AI Assistant</span>
            </button>

            <div class="chat-bubble-window" id="chatBubbleWindow">
                <!-- Chat Header -->
                <div class="chat-header">
                    <div class="chat-header-info">
                        <div class="chat-avatar">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <path d="M12 16v-4M12 8h.01"/>
                            </svg>
                        </div>
                        <div>
                            <div class="chat-header-name">Ligtas AI</div>
                            <div class="chat-header-status">
                                <span class="status-dot"></span>
                                <span id="chatStatus">Online &amp; Ready</span>
                            </div>
                        </div>
                    </div>
                    <div class="chat-header-actions">
                        <button class="chat-icon-btn" onclick="clearChatHistory()" title="Clear history">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                            </svg>
                        </button>
                        <button class="chat-icon-btn" onclick="toggleChatBubble()" title="Close">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Chat Messages -->
                <div class="chat-messages" id="chatContainer"></div>

                <!-- Quick Actions -->
                <div class="chat-quick-actions" id="chatQuickActions">
                    <button class="quick-chip" onclick="askQuestion('Am I safe from typhoons right now?')">🛡️ Am I safe?</button>
                    <button class="quick-chip" onclick="askQuestion('What should be in my emergency kit?')">🎒 Emergency kit</button>
                    <button class="quick-chip" onclick="askQuestion('Should I evacuate?')">🚗 Evacuate?</button>
                    <button class="quick-chip" onclick="askQuestion('What do the current weather readings mean?')">🌦️ Explain weather</button>
                </div>

                <!-- Input Area -->
                <div class="chat-input-area">
                    <div class="chat-input-wrap">
                        <input type="text" id="messageInput"
                            placeholder="Ask about safety, weather, typhoons..."
                            autocomplete="off"
                            onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();sendMessage()}"
                        />
                        <button id="sendBtn" onclick="sendMessage()" class="send-btn" title="Send">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                        </button>
                    </div>
                    <div class="chat-footer-note">Powered by Groq AI · Emergency: 911</div>
                </div>
            </div>
        </div>

        <div class="card forecast-full">
            <div class="card-header">
                <span class="card-title">📅 7-Day Weather Forecast</span>
            </div>
            <div style="padding:1.5rem">
                <div id="forecastDays" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:1rem"></div>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <div id="forecastModal" class="modal">
        <div class="modal-content">
            <span class="modal-close" onclick="closeForecastModal()">&times;</span>
            <h2 id="modalDayName">Weather Details</h2>
            <div id="modalContent"></div>
        </div>
    </div>

    <div id="clearChatModal" class="modal">
        <div class="modal-content clear-chat-modal" style="padding:2rem">
            <div class="modal-icon-header">
                <div class="modal-icon-circle">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                </div>
            </div>
            <h2 class="modal-title-center">Clear Chat History?</h2>
            <p class="modal-description">This will delete your conversation history. The AI will start fresh without memory of past chats.</p>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-cancel" onclick="closeClearChatModal()">Cancel</button>
                <button class="modal-btn modal-btn-danger" onclick="confirmClearChat()">Clear History</button>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="typhoon_ml_system.js"></script>
    <script src="script.js"></script>
    <script src="chat_db.js"></script>
</body>
</html>