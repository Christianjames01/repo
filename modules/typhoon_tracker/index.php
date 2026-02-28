<?php
/**
 * ENHANCED AI Disaster Safety Assistant v4.0
 * Now with PERSISTENT DATABASE CHAT STORAGE
 * AI recalls previous conversations and generates new insights
 */

// ============================================================
// CONFIG LOADER — walks up directory tree to find config.ini
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

    $userMessage      = trim($data['message']);
    $weatherData      = $data['weatherData']      ?? null;
    $typhoonData      = $data['typhoonData']       ?? [];
    $userLocation     = $data['userLocation']      ?? 'Philippines';
    $currentDateTime  = $data['currentDateTime']   ?? null;
    $forecastData     = $data['forecastData']      ?? null;
    $sessionId        = $data['session_id']        ?? ($data['resident_id'] ?? 'anonymous');
    if (!empty($data['resident_id'])) $sessionId = 'resident_' . $data['resident_id'];

    $config       = findConfig();
    $GROQ_API_KEY = $config['GROQ_API_KEY'] ?? $config['groq_api_key'] ?? '';

    // ── 1. Load DB conversation history ──────────────────────────────────────
    $dbHistory = loadDBHistory($sessionId);

    // ── 2. Save the incoming user message to DB ───────────────────────────────
    saveMessageToDB($sessionId, 'user', $userMessage, $weatherData, $typhoonData, $userLocation);

    // ── 3. Call AI with full history ──────────────────────────────────────────
    $aiResponse = callGroqAPI(
        $GROQ_API_KEY, $userMessage,
        $weatherData, $typhoonData, $userLocation,
        $currentDateTime, $forecastData,
        $dbHistory   // ← pass DB history instead of client history
    );

    if ($aiResponse['success']) {
        $responseText = $aiResponse['text'];
        // ── 4. Save assistant reply to DB ─────────────────────────────────────
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
            'success'    => true,
            'response'   => $fallback,
            'fallback'   => true,
            'api_error'  => $aiResponse['error'] ?? 'Service unavailable',
            'session_id' => $sessionId,
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
    $host   = $config['DB_HOST']      ?? $config['db_host']      ?? 'localhost';
    $db     = $config['DB_NAME']      ?? $config['db_name']      ?? '';
    $user   = $config['DB_USER']      ?? $config['db_user']      ?? '';
    $pass   = $config['DB_PASS']      ?? $config['db_pass']      ?? $config['DB_PASSWORD'] ?? '';

    try {
        $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Auto-create table if missing
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
        // Return null — app degrades gracefully without DB
        return null;
    }
    return $pdo;
}

/**
 * Load last N message pairs from DB for this session.
 * Returns array of ['role' => ..., 'content' => ...] suitable for Groq.
 */
function loadDBHistory($sessionId, $pairs = 15) {
    $pdo = getDB();
    if (!$pdo) return [];

    $limit = $pairs * 2; // each "pair" = 1 user + 1 assistant message
    $stmt = $pdo->prepare("
        SELECT role, content, weather_context, created_at
        FROM typhoon_chat_history
        WHERE session_id = :sid
        ORDER BY created_at DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':sid', $sessionId, PDO::PARAM_STR);
    $stmt->bindValue(':lim', $limit,     PDO::PARAM_INT);
    $stmt->execute();

    $rows = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'role'    => $row['role'],
            'content' => $row['content'],
        ];
    }
    return $history;
}

/**
 * Persist one message (user or assistant) to DB.
 */
function saveMessageToDB($sessionId, $role, $content, $weatherData = null, $typhoonData = null, $location = null) {
    $pdo = getDB();
    if (!$pdo) return;

    // Extract numeric resident_id if session_id looks like "resident_123"
    $residentId = null;
    if (preg_match('/^resident_(\d+)$/', $sessionId, $m)) {
        $residentId = (int)$m[1];
    }

    $stmt = $pdo->prepare("
        INSERT INTO typhoon_chat_history
            (session_id, resident_id, role, content, weather_context, typhoon_context, location_context)
        VALUES
            (:sid, :rid, :role, :content, :weather, :typhoon, :loc)
    ");
    $stmt->execute([
        ':sid'     => $sessionId,
        ':rid'     => $residentId,
        ':role'    => $role,
        ':content' => $content,
        ':weather' => $weatherData  ? json_encode($weatherData)  : null,
        ':typhoon' => $typhoonData  ? json_encode($typhoonData)   : null,
        ':loc'     => $location,
    ]);
}

// ============================================================================
// GROQ API  (same as before but uses DB history)
// ============================================================================

function callGroqAPI($apiKey, $message, $weatherData, $typhoonData, $userLocation,
                     $currentDateTime = null, $forecastData = null, $conversationHistory = []) {

    if (empty($apiKey)) return ['success' => false, 'error' => 'API key not configured'];

    // ── SYSTEM PROMPT ─────────────────────────────────────────────────────────
    $context  = "You are an advanced AI weather assistant specializing in Philippine tropical weather and disaster preparedness.\n";
    $context .= "Think of yourself as a knowledgeable meteorologist who can explain complex weather patterns in an accessible, conversational way.\n\n";
    $context .= "User location: {$userLocation}.\n";
    if ($currentDateTime) $context .= "Current date and time: {$currentDateTime}\n\n";

    $context .= "=== YOUR PERSONALITY AND APPROACH ===\n";
    $context .= "• Be conversational and natural — talk like a helpful expert, not a robot\n";
    $context .= "• Show understanding and empathy when people are concerned about weather\n";
    $context .= "• Explain the 'why' behind weather phenomena when relevant\n";
    $context .= "• Use analogies and examples to make complex weather concepts clear\n";
    $context .= "• IMPORTANT: You have access to this resident's FULL conversation history stored in the database.\n";
    $context .= "  Reference past conversations naturally — e.g., 'Last time you asked about...' or 'Compared to when we last spoke...'\n";
    $context .= "• Generate NEW INSIGHTS by comparing current conditions to previous conversations\n";
    $context .= "• If weather has changed since last conversation, proactively highlight what changed\n";
    $context .= "• Be honest about uncertainty — if you're not certain, say so\n";
    $context .= "• Prioritize safety always, but don't be alarmist about normal conditions\n\n";

    $context .= "=== RESPONSE GUIDELINES ===\n";
    $context .= "• For simple questions: Give concise, direct answers (2-3 sentences)\n";
    $context .= "• For complex questions: Provide detailed explanations with context\n";
    $context .= "• For follow-up questions: Reference previous context naturally\n";
    $context .= "• Use first-person perspective ('I'm analyzing...', 'I'm seeing...') to be more personal\n\n";

    $context .= "=== CONVERSATIONAL MEMORY INSTRUCTIONS ===\n";
    $context .= "• You have access to this resident's PAST CONVERSATIONS from the database\n";
    $context .= "• When the resident returns after a gap, acknowledge it: 'Welcome back! Since we last spoke...'\n";
    $context .= "• If their location or situation has changed, note it\n";
    $context .= "• Generate insights by comparing: 'Last week the pressure was X, now it's Y — this suggests...'\n";
    $context .= "• Proactively offer insights: 'Based on our conversation history, I notice...'\n";
    $context .= "• If they seem to be checking regularly during a storm, acknowledge their vigilance\n\n";

    // ── WEATHER DATA ──────────────────────────────────────────────────────────
    if ($weatherData) {
        $wind     = floatval($weatherData['windSpeed']);
        $pressure = floatval($weatherData['pressure']);
        $humidity = floatval($weatherData['humidity']);
        $temp     = floatval($weatherData['temperature']);

        $context .= "=== CURRENT WEATHER CONDITIONS ===\n";
        $context .= "Wind Speed: {$weatherData['windSpeed']} km/h\n";
        $context .= "Temperature: {$weatherData['temperature']}°C\n";
        $context .= "Atmospheric Pressure: {$weatherData['pressure']} hPa\n";
        $context .= "Humidity: {$weatherData['humidity']}%\n\n";

        $context .= "=== INTELLIGENT WEATHER INTERPRETATION ===\n";

        if ($humidity >= 95) {
            $context .= "🌧️ CRITICAL HUMIDITY: {$humidity}% — Atmosphere at saturation, heavy rain forming\n";
        } elseif ($humidity >= 90) {
            $context .= "⚠️ VERY HIGH HUMIDITY: {$humidity}% — Near saturation\n";
        } elseif ($humidity >= 85) {
            $context .= "💧 HIGH HUMIDITY: {$humidity}% — Rain probable\n";
        }

        if ($pressure < 1005) {
            $context .= "📉 CRITICAL PRESSURE: {$pressure} hPa — Active storm system present\n";
        } elseif ($pressure < 1009) {
            $context .= "⚠️ LOW PRESSURE: {$pressure} hPa — Unsettled weather\n";
        } elseif ($pressure < 1012) {
            $context .= "Pressure slightly below normal: {$pressure} hPa\n";
        }

        if ($humidity >= 88 && $pressure < 1010) {
            $context .= "🌧️💧 CRITICAL COMBINED: High humidity ({$humidity}%) + Low pressure ({$pressure} hPa) = Active heavy rain conditions\n";
        }

        if ($wind > 118) $context .= "🌪️ TYPHOON-FORCE WINDS: {$wind} km/h (Signal #4+)\n";
        elseif ($wind > 88) $context .= "⚠️ STORM-FORCE WINDS: {$wind} km/h (Signal #3)\n";
        elseif ($wind > 62) $context .= "⚠️ STRONG WINDS: {$wind} km/h (Signal #2)\n";
        elseif ($wind > 39) $context .= "Moderate winds: {$wind} km/h (Signal #1)\n";

        $context .= "\n";
    }

    // ── TYPHOON DATA ──────────────────────────────────────────────────────────
    if (!empty($typhoonData)) {
        $context .= "=== ACTIVE TYPHOON INFORMATION ===\n";
        foreach ($typhoonData as $idx => $t) {
            $context .= ($idx + 1) . ". {$t['name']} — Wind: {$t['windSpeed']} km/h, Distance: {$t['distance']} km\n";
            if ($t['distance'] < 300)       $context .= "   ⚠️ IMMEDIATE DANGER\n";
            elseif ($t['distance'] < 600)   $context .= "   ⚠️ HIGH ALERT\n";
            else                            $context .= "   ℹ️ MONITORING\n";
        }
        $context .= "\n";
    }

    // ── REFERENCE DATA ────────────────────────────────────────────────────────
    $context .= "=== PAGASA WIND SIGNAL REFERENCE ===\n";
    $context .= "Signal #1: 39-61 km/h | #2: 62-88 km/h | #3: 89-117 km/h | #4: 118-184 km/h | #5: 185+ km/h\n\n";
    $context .= "=== EMERGENCY CONTACTS ===\n";
    $context .= "NDRRMC: 911 | PAGASA: (02) 8284-0800 | Red Cross: 143\n\n";
    $context .= "=== SPECIAL HANDLING ===\n";
    $context .= "• NO HISTORICAL DATA access beyond what's in conversation history\n";
    $context .= "• If asked about past weather, reference the conversation history context\n";
    $context .= "• EMOTIONAL INTELLIGENCE: Acknowledge concern, give honest assessment, provide clear guidance\n\n";

    // ── BUILD MESSAGES ARRAY WITH DB HISTORY ─────────────────────────────────
    $messages = [['role' => 'system', 'content' => $context]];

    if (!empty($conversationHistory)) {
        foreach ($conversationHistory as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }
    }

    $messages[] = ['role' => 'user', 'content' => $message];

    // ── GROQ API CALL ─────────────────────────────────────────────────────────
    $payload = json_encode([
        'model'       => 'llama-3.3-70b-versatile',
        'messages'    => $messages,
        'temperature' => 0.7,
        'max_tokens'  => 800,
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
                ? 'Rate limit reached. Please wait a few minutes and try again.'
                : $apiError;
        }
    } elseif ($curlErr) {
        $errorMsg = 'Connection error: ' . $curlErr;
    }

    return ['success' => false, 'error' => $errorMsg];
}

// ============================================================================
// FALLBACK (unchanged from v3)
// ============================================================================

function getFallbackResponse($message, $weatherData, $typhoonData, $forecastData) {
    $msgLower = strtolower($message);

    if (strpos($msgLower, 'yesterday') !== false || strpos($msgLower, 'last night') !== false ||
        strpos($msgLower, 'last week')  !== false || strpos($msgLower, 'past')       !== false) {
        if ($weatherData) {
            $humidity = floatval($weatherData['humidity']);
            $pressure = floatval($weatherData['pressure']);
            $response = "I don't have access to historical weather data from previous days. However, let me analyze your current conditions to provide context:\n\n";
            $response .= "🌡️ Current Conditions:\n• Humidity: {$weatherData['humidity']}%\n• Pressure: {$weatherData['pressure']} hPa\n\n";
            if ($humidity >= 90 && $pressure < 1010) {
                $response .= "Right now you have conditions ({$humidity}% humidity with {$pressure} hPa pressure) that typically produce heavy rain. If you experienced heavy rain recently, it was likely due to similar atmospheric patterns.";
            } elseif ($humidity >= 85) {
                $response .= "Your current high humidity levels ({$humidity}%) suggest wet weather patterns. For historical data, check PAGASA's website.";
            }
            return $response;
        }
    }

    if (strpos($msgLower, 'rain') !== false || strpos($msgLower, 'tomorrow') !== false) {
        if ($forecastData && isset($forecastData['tomorrow'])) {
            $tmrw = $forecastData['tomorrow'];
            $response = "🌤️ Tomorrow's Forecast:\n• Temperature: {$tmrw['minTemp']}°C - {$tmrw['maxTemp']}°C\n";
            $response .= "• Rain Probability: {$tmrw['precipProb']}%\n• Expected Rainfall: {$tmrw['precip']}mm\n\n";
            if ($tmrw['precipProb'] > 70 || $tmrw['precip'] > 10) {
                $response .= "☔ High likelihood of rain tomorrow. Bring an umbrella!";
            } elseif ($tmrw['precipProb'] > 40 || $tmrw['precip'] > 5) {
                $response .= "🌦️ Moderate chance of rain. Have an umbrella handy.";
            } else {
                $response .= "☀️ Low chance of rain tomorrow.";
            }
            return $response;
        }
    }

    if ($weatherData && (strpos($msgLower, 'weather') !== false || strpos($msgLower, 'current') !== false)) {
        $humidity = floatval($weatherData['humidity']);
        $pressure = floatval($weatherData['pressure']);
        $wind     = floatval($weatherData['windSpeed']);
        $response = "📊 Conditions Right Now:\n";
        $response .= "• Humidity: {$weatherData['humidity']}% | Pressure: {$weatherData['pressure']} hPa\n";
        $response .= "• Wind: {$weatherData['windSpeed']} km/h | Temp: {$weatherData['temperature']}°C\n\n";
        if ($humidity >= 95 && $pressure < 1010) {
            $response .= "🌧️ HEAVY RAIN CONDITIONS: Critical combination of extreme humidity and low pressure.";
        } elseif ($humidity >= 90 && $pressure < 1010) {
            $response .= "⚠️ Active Rain Pattern: High humidity and below-normal pressure — heavy rain likely.";
        } elseif ($wind > 60) {
            $response .= "💨 Strong Winds Alert: {$wind} km/h. Secure loose objects.";
        } else {
            $response .= "✓ Conditions within normal range for a tropical region.";
        }
        return $response;
    }

    if (strpos($msgLower, 'safe') !== false && !empty($typhoonData)) {
        $t = $typhoonData[0];
        if ($t['distance'] < 300) {
            return "⚠️ SAFETY ALERT: Typhoon {$t['name']} is only {$t['distance']}km away with {$t['windSpeed']} km/h winds. Follow evacuation orders. Call 911 for emergencies.";
        }
        return "Currently monitoring Typhoon {$t['name']} at {$t['distance']}km. Prepare emergency kit and monitor PAGASA.";
    }

    return "I'm here to help with weather and safety information. Ask me about current conditions, typhoon threats, or emergency preparedness. NDRRMC: 911 | PAGASA: (02) 8284-0800";
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

        <div class="chat-bubble-container" id="chatBubbleContainer">
            <button class="chat-bubble-button" onclick="toggleChatBubble()">
                <span class="chat-bubble-icon">💬</span>
                <span class="chat-bubble-text">AI Assistant</span>
            </button>

            <div class="chat-bubble-window" id="chatBubbleWindow" style="overflow:visible!important">
                <div style="background:#374151;color:white;padding:1.5rem;display:flex;justify-content:space-between;align-items:center;min-height:85px;flex-shrink:0;border-radius:16px 16px 0 0">
                    <div style="display:flex;flex-direction:column;gap:0.75rem;flex:1">
                        <div style="font-size:1.25rem;font-weight:700;color:#ffffff">🤖 AI Safety Assistant</div>
                        <div style="font-size:0.875rem;color:#e5e7eb;display:flex;align-items:center;gap:0.5rem">
                            <span style="color:#10b981">●</span>
                            <span id="chatStatus">Online &amp; Ready</span>
                            <span id="historyBadge" style="display:none;background:rgba(255,255,255,0.15);padding:2px 8px;border-radius:10px;font-size:0.75rem"></span>
                        </div>
                    </div>
                    <div style="display:flex;gap:0.5rem">
                        <button onclick="clearChatHistory()" title="Clear chat" style="background:rgba(255,255,255,0.1);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center">🗑️</button>
                        <button onclick="toggleChatBubble()" title="Close" style="background:rgba(255,255,255,0.1);border:none;color:white;width:36px;height:36px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center">✕</button>
                    </div>
                </div>

                <div class="chat-container" id="chatContainer" style="flex:1;overflow-y:auto;padding:1.5rem;background:#f9fafb"></div>

                <div class="input-area" style="padding:1rem 1.5rem;background:white;border-top:1px solid #e5e7eb;flex-shrink:0">
                    <div class="quick-questions" style="margin-bottom:0.75rem">
                        <div class="quick-btns" style="display:flex;gap:0.5rem;flex-wrap:wrap">
                            <button class="quick-btn" onclick="askQuestion('Am I safe from typhoons?')">Am I safe?</button>
                            <button class="quick-btn" onclick="askQuestion('What should be in my emergency kit?')">Emergency kit</button>
                            <button class="quick-btn" onclick="askQuestion('Should I evacuate?')">Evacuate?</button>
                            <button class="quick-btn" onclick="askQuestion('What insights do you have from our previous conversations?')">💡 Recall insights</button>
                        </div>
                    </div>
                    <div class="input-group" style="display:flex;gap:0.5rem">
                        <input type="text" id="messageInput" placeholder="Ask about typhoons, safety, weather..." autocomplete="off"
                               onkeypress="if(event.key==='Enter')sendMessage()"
                               style="flex:1;padding:0.875rem 1.25rem;border:2px solid #e5e7eb;border-radius:25px;font-size:0.9375rem;outline:none;background:#f9fafb">
                        <button id="sendBtn" onclick="sendMessage()" style="width:48px;height:48px;background:#374151;color:white;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/>
                            </svg>
                        </button>
                    </div>
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
            <p class="modal-description">This will permanently delete all your conversation history from the database. The AI will no longer be able to recall past conversations. This action cannot be undone.</p>
            <div class="modal-actions">
                <button class="modal-btn modal-btn-cancel" onclick="closeClearChatModal()">Cancel</button>
                <button class="modal-btn modal-btn-danger" onclick="confirmClearChat()">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:0.5rem">
                        <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                    Clear History
                </button>
            </div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="typhoon_ml_system.js"></script>
    <script src="script.js"></script>
    <script src="chat_db.js"></script>
</body>
</html>