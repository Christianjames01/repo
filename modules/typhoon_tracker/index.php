<?php
/**
 * ENHANCED AI Disaster Safety Assistant v3.0
 * Now with advanced conversational intelligence and context awareness
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
    $currentDateTime = $data['currentDateTime'] ?? null;
    $forecastData = $data['forecastData'] ?? null;
    $conversationHistory = $data['conversationHistory'] ?? [];
    
    $config = parse_ini_file(dirname(__FILE__) . '/../../config.ini');
$GROQ_API_KEY = $config['GROQ_API_KEY'];
    
    $aiResponse = callGroqAPI($GROQ_API_KEY, $userMessage, $weatherData, $typhoonData, $userLocation, $currentDateTime, $forecastData, $conversationHistory);
    
    if ($aiResponse['success']) {
        echo json_encode(['success' => true, 'response' => $aiResponse['text'], 'model' => 'llama-3.3-70b']);
    } else {
        $fallbackResponse = getFallbackResponse($userMessage, $weatherData, $typhoonData, $forecastData);
        echo json_encode([
            'success' => true, 
            'response' => $fallbackResponse,
            'fallback' => true,
            'api_error' => $aiResponse['error'] ?? 'Service unavailable'
        ]);
    }
    exit();
}

function callGroqAPI($apiKey, $message, $weatherData, $typhoonData, $userLocation, $currentDateTime = null, $forecastData = null, $conversationHistory = []) {
    if (empty($apiKey)) return ['success' => false, 'error' => 'API key not configured'];
    
    // ============================================================================
    // ENHANCED CONVERSATIONAL AI SYSTEM PROMPT
    // ============================================================================
    
    $context = "You are an advanced AI weather assistant specializing in Philippine tropical weather and disaster preparedness. ";
    $context .= "Think of yourself as a knowledgeable meteorologist who can explain complex weather patterns in an accessible, conversational way.\n\n";
    
    $context .= "User location: {$userLocation}.\n";
    if ($currentDateTime) {
        $context .= "Current date and time: {$currentDateTime}\n\n";
    }
    
    $context .= "=== YOUR PERSONALITY AND APPROACH ===\n";
    $context .= "• Be conversational and natural - talk like a helpful expert, not a robot\n";
    $context .= "• Show understanding and empathy when people are concerned about weather\n";
    $context .= "• Explain the 'why' behind weather phenomena when relevant\n";
    $context .= "• Use analogies and examples to make complex weather concepts clear\n";
    $context .= "• Remember context from previous messages in the conversation\n";
    $context .= "• Be honest about uncertainty - if you're not certain, say so\n";
    $context .= "• Prioritize safety always, but don't be alarmist about normal conditions\n\n";
    
    $context .= "=== RESPONSE GUIDELINES ===\n";
    $context .= "• For simple questions: Give concise, direct answers (2-3 sentences)\n";
    $context .= "• For complex questions: Provide detailed explanations with context\n";
    $context .= "• For follow-up questions: Reference previous context naturally\n";
    $context .= "• When explaining weather: Break down the atmospheric processes happening\n";
    $context .= "• When giving safety advice: Explain why certain actions are recommended\n";
    $context .= "• Use first-person perspective ('I'm analyzing...', 'I'm seeing...') to be more personal\n\n";
    
    $context .= "=== CONVERSATIONAL INTELLIGENCE ===\n";
    $context .= "• Track the conversation flow - if someone asks 'what about tomorrow?' understand they mean the weather\n";
    $context .= "• Pick up on emotional cues - if someone seems worried, be reassuring while being truthful\n";
    $context .= "• Anticipate follow-up questions and sometimes address them preemptively\n";
    $context .= "• Use natural transitions: 'Building on that...', 'To add to what I mentioned...', 'You're right to ask about...'\n";
    $context .= "• Acknowledge good questions: 'That's an excellent question...', 'I'm glad you asked...'\n\n";
    
    // ============================================================================
    // WEATHER DATA ANALYSIS (Enhanced with intelligent interpretation)
    // ============================================================================
    
    if ($weatherData) {
        $wind = floatval($weatherData['windSpeed']);
        $pressure = floatval($weatherData['pressure']);
        $humidity = floatval($weatherData['humidity']);
        $temp = floatval($weatherData['temperature']);
        
        $context .= "=== CURRENT WEATHER CONDITIONS (Real-Time Analysis) ===\n";
        $context .= "Wind Speed: {$weatherData['windSpeed']} km/h\n";
        $context .= "Temperature: {$weatherData['temperature']}°C\n";
        $context .= "Atmospheric Pressure: {$weatherData['pressure']} hPa\n";
        $context .= "Humidity: {$weatherData['humidity']}%\n\n";
        
        $context .= "=== INTELLIGENT WEATHER INTERPRETATION ===\n";
        
        // Advanced humidity analysis with context
        if ($humidity >= 95) {
            $context .= "🌧️ CRITICAL HUMIDITY ANALYSIS:\n";
            $context .= "• Current: {$humidity}% - Atmosphere is completely saturated\n";
            $context .= "• What this means: Air can't hold any more moisture - rain is forming or imminent\n";
            $context .= "• Physical process: When humidity reaches this level in tropical regions, condensation is active\n";
            $context .= "• Expected outcome: Heavy rainfall very likely or already occurring\n\n";
        } else if ($humidity >= 90) {
            $context .= "⚠️ VERY HIGH HUMIDITY ANALYSIS:\n";
            $context .= "• Current: {$humidity}% - Near saturation point\n";
            $context .= "• Interpretation: The atmosphere is holding maximum moisture for current temperature\n";
            $context .= "• Implication: Small pressure/temperature changes will trigger significant rainfall\n\n";
        } else if ($humidity >= 85) {
            $context .= "💧 HIGH HUMIDITY ANALYSIS:\n";
            $context .= "• Current: {$humidity}% - Moisture-laden atmosphere\n";
            $context .= "• Context: This level typically precedes rain formation in the Philippines\n";
            $context .= "• Probability: Rain is probable, especially with any pressure changes\n\n";
        } else if ($humidity >= 75) {
            $context .= "Normal tropical humidity: {$humidity}% - Typical for your region\n\n";
        }
        
        // Advanced pressure analysis with meteorological context
        if ($pressure < 1005) {
            $context .= "📉 CRITICAL PRESSURE ANALYSIS:\n";
            $context .= "• Current: {$pressure} hPa - Significantly below normal (1012 hPa)\n";
            $context .= "• What's happening: A strong low-pressure system is present\n";
            $context .= "• Atmospheric dynamics: Low pressure creates rising air motion → cooling → condensation → heavy rain\n";
            $context .= "• Weather expectation: Active weather system producing or about to produce heavy rainfall\n\n";
        } else if ($pressure < 1009) {
            $context .= "⚠️ LOW PRESSURE ANALYSIS:\n";
            $context .= "• Current: {$pressure} hPa - Below normal range\n";
            $context .= "• Meteorological significance: Unsettled weather pattern established\n";
            $context .= "• Combined with high humidity: Creates ideal rain-producing conditions\n\n";
        } else if ($pressure < 1012) {
            $context .= "Pressure slightly below normal: {$pressure} hPa - Minor weather activity possible\n\n";
        } else {
            $context .= "Pressure normal to high: {$pressure} hPa - Generally stable atmospheric conditions\n\n";
        }
        
        // Intelligent combined analysis (this is what makes it smart)
        if ($humidity >= 88 && $pressure < 1010) {
            $context .= "🌧️💧 CRITICAL COMBINED ANALYSIS:\n";
            $context .= "I'm detecting a powerful rain-producing weather pattern:\n";
            $context .= "1. High humidity ({$humidity}%) = Maximum atmospheric moisture\n";
            $context .= "2. Low pressure ({$pressure} hPa) = Rising air motion\n";
            $context .= "3. Combination effect = Perfect conditions for sustained heavy rainfall\n\n";
            $context .= "Scientific explanation: The low pressure forces moist air upward. As it rises and cools, ";
            $context .= "the near-saturated air ({$humidity}%) rapidly condenses into heavy rain. This is the classic ";
            $context .= "heavy rainfall mechanism in tropical regions.\n\n";
            $context .= "IMPORTANT: This isn't just prediction - these conditions are ACTIVELY producing rain right now ";
            $context .= "or will very soon (within hours).\n\n";
        }
        
        // Wind analysis with context
        if ($wind > 118) {
            $context .= "🌪️ TYPHOON-FORCE WINDS: {$wind} km/h (PAGASA Signal #4+)\n";
            $context .= "This represents extreme danger. Structural damage is expected.\n\n";
        } elseif ($wind > 88) {
            $context .= "⚠️ STORM-FORCE WINDS: {$wind} km/h (PAGASA Signal #3)\n";
            $context .= "Severe weather conditions. Widespread damage to light structures possible.\n\n";
        } elseif ($wind > 62) {
            $context .= "⚠️ STRONG WINDS: {$wind} km/h (PAGASA Signal #2)\n";
            $context .= "Potentially dangerous for outdoor activities. Secure loose objects.\n\n";
        } elseif ($wind > 39) {
            $context .= "Moderate winds: {$wind} km/h (PAGASA Signal #1) - Minor impacts possible\n\n";
        } else if ($wind < 10 && $humidity > 90) {
            $context .= "⚠️ CALM WINDS + HIGH HUMIDITY PATTERN:\n";
            $context .= "• This combination is typical of heavy rain conditions\n";
            $context .= "• The calm winds allow moisture to concentrate rather than disperse\n";
            $context .= "• Often seen in convergence zones that produce sustained rainfall\n\n";
        }
        
        $context .= "\n";
    }
    
    // ============================================================================
    // TYPHOON DATA (if present)
    // ============================================================================
    
    if (!empty($typhoonData)) {
        $context .= "=== ACTIVE TYPHOON INFORMATION ===\n";
        foreach ($typhoonData as $idx => $t) {
            $context .= ($idx + 1) . ". {$t['name']}\n";
            $context .= "   - Wind Speed: {$t['windSpeed']} km/h\n";
            $context .= "   - Distance from user: {$t['distance']} km\n";
            
            if ($t['distance'] < 300) {
                $context .= "   - ⚠️ IMMEDIATE DANGER: Direct impact likely\n";
            } elseif ($t['distance'] < 600) {
                $context .= "   - ⚠️ HIGH ALERT: Significant impact expected\n";
            } else {
                $context .= "   - ℹ️ MONITORING: Indirect effects possible\n";
            }
            $context .= "\n";
        }
    }
    
    // ============================================================================
    // REFERENCE INFORMATION
    // ============================================================================
    
    $context .= "=== PAGASA WIND SIGNAL REFERENCE ===\n";
    $context .= "Signal #1: 39-61 km/h → Minimal to minor threat\n";
    $context .= "Signal #2: 62-88 km/h → Minor to moderate threat\n";
    $context .= "Signal #3: 89-117 km/h → Moderate to significant threat\n";
    $context .= "Signal #4: 118-184 km/h → Significant to severe threat\n";
    $context .= "Signal #5: 185+ km/h → Extreme catastrophic threat\n\n";
    
    $context .= "=== RAINFALL INTENSITY THRESHOLDS ===\n";
    $context .= "Light: 15-35 mm/24h → Minor impacts, travel disruption\n";
    $context .= "Moderate: 35-65 mm/24h → Flooding in poor drainage areas\n";
    $context .= "Heavy: 65-100 mm/24h → Serious flooding, travel dangerous\n";
    $context .= "Intense: 100-150 mm/24h → Major flooding, evacuations likely\n";
    $context .= "Torrential: 150+ mm/24h → Catastrophic flooding, life-threatening\n\n";
    
    $context .= "=== HUMIDITY-RAINFALL RELATIONSHIP ===\n";
    $context .= "95%+ humidity → Atmosphere at saturation, heavy rain forming\n";
    $context .= "88-95% humidity → Very high moisture, heavy rain likely\n";
    $context .= "80-88% humidity → High moisture, rain probable\n";
    $context .= "Pressure <1009 hPa → Low pressure system, expect rain\n";
    $context .= "Combined (high humidity + low pressure) → Heavy rainfall conditions\n\n";
    
    $context .= "=== EMERGENCY CONTACTS ===\n";
    $context .= "NDRRMC Hotline: 911\n";
    $context .= "PAGASA Weather: (02) 8284-0800\n";
    $context .= "Red Cross: 143\n\n";
    
    // ============================================================================
    // CONVERSATIONAL CONTEXT MEMORY
    // ============================================================================
    
    if (!empty($conversationHistory)) {
        $context .= "=== RECENT CONVERSATION CONTEXT ===\n";
        $context .= "Remember these recent exchanges to maintain conversation flow:\n";
        
        // Include last 3 exchanges for context
        $recentHistory = array_slice($conversationHistory, -6); // Last 3 user + 3 assistant messages
        foreach ($recentHistory as $msg) {
            $role = ucfirst($msg['role']);
            $content = substr($msg['content'], 0, 200); // Limit length
            $context .= "{$role}: {$content}\n";
        }
        $context .= "\n";
    }
    
    // ============================================================================
    // SPECIAL INSTRUCTIONS
    // ============================================================================
    
    $context .= "=== SPECIAL HANDLING INSTRUCTIONS ===\n";
    $context .= "• NO HISTORICAL DATA: You don't have access to weather from yesterday/last week. ";
    $context .= "If asked about past weather, politely explain you only have current conditions, then analyze what the current conditions suggest.\n\n";
    
    $context .= "• FOLLOW-UP QUESTIONS: When someone asks a follow-up like 'what about tomorrow?' or 'and the wind?', ";
    $context .= "understand the context from previous messages. Don't ask 'what about tomorrow?' - infer they mean weather.\n\n";
    
    $context .= "• EMOTIONAL INTELLIGENCE: If someone sounds worried (e.g., 'am I safe?', 'should I be concerned?'), ";
    $context .= "acknowledge their concern, give honest assessment, explain why, then provide clear actionable guidance.\n\n";
    
    $context .= "• EXPLAINING COMPLEX CONCEPTS: When explaining meteorology, use everyday analogies:\n";
    $context .= "  - Low pressure = vacuum effect pulling air upward\n";
    $context .= "  - High humidity = sponge that's completely full, one more drop causes overflow (rain)\n";
    $context .= "  - Typhoon = giant spinning engine powered by warm ocean water\n\n";
    
    $context .= "• CONVERSATIONAL FLOW: Use transitions naturally:\n";
    $context .= "  - 'Building on what I mentioned about the humidity...'\n";
    $context .= "  - 'To add more context to that...'\n";
    $context .= "  - 'You're absolutely right to ask about...'\n";
    $context .= "  - 'That's a great follow-up question...'\n\n";
    
    $context .= "Remember: You're not just providing data - you're helping someone understand and prepare for weather. ";
    $context .= "Be their knowledgeable, trustworthy weather expert who explains things clearly and cares about their safety.\n\n";
    
    // ============================================================================
    // BUILD MESSAGES ARRAY WITH CONVERSATION HISTORY
    // ============================================================================
    
    $messages = [
        ['role' => 'system', 'content' => $context]
    ];
    
    // Add conversation history
    if (!empty($conversationHistory)) {
        foreach ($conversationHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'],
                'content' => $msg['content']
            ];
        }
    }
    
    // Add current user message
    $messages[] = ['role' => 'user', 'content' => $message];
    
    // ============================================================================
    // MAKE API CALL
    // ============================================================================
    
    $payload = json_encode([
        'model' => 'llama-3.3-70b-versatile',
        'messages' => $messages,
        'temperature' => 0.7,
        'max_tokens' => 800,
        'top_p' => 0.9
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
    $curlError = curl_error($ch);
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
            
            if (strpos($apiError, 'Rate limit') !== false || strpos($apiError, 'TPD') !== false) {
                $errorMsg = 'Rate limit reached. Please wait a few minutes and try again.';
            } else {
                $errorMsg = $apiError;
            }
        }
    } elseif ($curlError) {
        $errorMsg = 'Connection error: ' . $curlError;
    }
    
    return ['success' => false, 'error' => $errorMsg];
}

function getFallbackResponse($message, $weatherData, $typhoonData, $forecastData) {
    $msgLower = strtolower($message);
    
    // Historical weather questions
    if (strpos($msgLower, 'yesterday') !== false || strpos($msgLower, 'last night') !== false || 
        strpos($msgLower, 'last week') !== false || strpos($msgLower, 'past') !== false) {
        
        if ($weatherData) {
            $humidity = floatval($weatherData['humidity']);
            $pressure = floatval($weatherData['pressure']);
            
            $response = "I don't have access to historical weather data from previous days. However, let me analyze your current conditions to provide context:\n\n";
            $response .= "🌡️ Current Conditions:\n";
            $response .= "• Humidity: {$weatherData['humidity']}%\n";
            $response .= "• Pressure: {$weatherData['pressure']} hPa\n\n";
            
            if ($humidity >= 90 && $pressure < 1010) {
                $response .= "What I can tell you is that right now, you have conditions ({$humidity}% humidity with {$pressure} hPa pressure) that typically produce heavy rain. ";
                $response .= "If you experienced heavy rain recently, it was likely due to similar atmospheric patterns - high moisture combined with low pressure.";
            } else if ($humidity >= 85) {
                $response .= "Your current high humidity levels ({$humidity}%) suggest wet weather patterns. For historical data, I'd recommend checking PAGASA's website.";
            }
            
            return $response;
        }
    }
    
    // Rain forecast
    if (strpos($msgLower, 'rain') !== false || strpos($msgLower, 'tomorrow') !== false) {
        if ($forecastData && isset($forecastData['tomorrow'])) {
            $tmrw = $forecastData['tomorrow'];
            $response = "🌤️ Tomorrow's Forecast:\n\n";
            $response .= "• Temperature: {$tmrw['minTemp']}°C - {$tmrw['maxTemp']}°C\n";
            $response .= "• Rain Probability: {$tmrw['precipProb']}%\n";
            $response .= "• Expected Rainfall: {$tmrw['precip']}mm\n\n";
            
            if ($tmrw['precipProb'] > 70 || $tmrw['precip'] > 10) {
                $response .= "☔ Yes, I'm seeing a high likelihood of rain tomorrow. The forecast indicates significant precipitation, so definitely bring an umbrella and plan accordingly.";
            } elseif ($tmrw['precipProb'] > 40 || $tmrw['precip'] > 5) {
                $response .= "🌦️ There's a moderate chance of rain. I'd suggest having an umbrella handy - better prepared than caught in a downpour!";
            } else {
                $response .= "☀️ Looking fairly clear! Low chance of rain tomorrow based on current forecasts.";
            }
            
            return $response;
        }
    }
    
    // Current weather
    if ($weatherData && (strpos($msgLower, 'weather') !== false || strpos($msgLower, 'current') !== false)) {
        $humidity = floatval($weatherData['humidity']);
        $pressure = floatval($weatherData['pressure']);
        $wind = floatval($weatherData['windSpeed']);
        
        $response = "Let me break down your current weather situation:\n\n";
        $response .= "📊 Conditions Right Now:\n";
        $response .= "• Humidity: {$weatherData['humidity']}% | Pressure: {$weatherData['pressure']} hPa\n";
        $response .= "• Wind: {$weatherData['windSpeed']} km/h | Temp: {$weatherData['temperature']}°C\n\n";
        
        if ($humidity >= 95 && $pressure < 1010) {
            $response .= "🌧️ HEAVY RAIN CONDITIONS: I'm seeing a critical combination here - extremely high humidity ({$humidity}%) with low pressure ({$pressure} hPa). ";
            $response .= "This is the classic setup for heavy rainfall in the Philippines. The atmosphere is saturated and the low pressure is forcing that moisture upward, causing it to condense into rain. ";
            $response .= "If it's not raining yet where you are, it very likely will be soon. Stay alert for flooding in low-lying areas.";
        } else if ($humidity >= 90 && $pressure < 1010) {
            $response .= "⚠️ Active Rain Pattern: The combination of high humidity ({$humidity}%) and below-normal pressure ({$pressure} hPa) means your area is under an active weather system. Heavy rain is likely or already occurring.";
        } else if ($wind > 60) {
            $response .= "💨 Strong Winds Alert: {$wind} km/h winds require caution. Secure loose objects and avoid unnecessary outdoor activities.";
        } else {
            $response .= "✓ Conditions are within normal range for a tropical region. Stay weather-aware as always.";
        }
        
        return $response;
    }
    
    // Safety questions
    if (strpos($msgLower, 'safe') !== false) {
        if (!empty($typhoonData)) {
            $closest = $typhoonData[0];
            if ($closest['distance'] < 300) {
                return "⚠️ SAFETY ALERT: I need to be direct with you - Typhoon {$closest['name']} is only {$closest['distance']}km away with {$closest['windSpeed']} km/h winds. This is very close and represents a direct threat. Please follow any evacuation orders from local authorities immediately. If you haven't already, secure your property and prepare your emergency supplies. Monitor PAGASA updates constantly at (02) 8284-0800 or call 911 for emergencies.";
            } else {
                return "Currently monitoring: Typhoon {$closest['name']} is {$closest['distance']}km away. While not in immediate danger zone, I recommend completing your typhoon preparations and staying informed through PAGASA bulletins.";
            }
        } else {
            return "✓ Good news - no active typhoons are threatening your area right now. However, this is typhoon season in the Philippines, so it's always smart to have your emergency kit ready and know your evacuation routes. Stay weather-aware!";
        }
    }
    
    // Default
    return "I'm here to help with weather and safety information. Right now, I can tell you about:\n\n• Current weather conditions and what they mean\n• Typhoon threats and safety guidance\n• How to prepare and stay safe\n\nWhat would you like to know?";
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
                            <div class="weather-value" id="windSpeed">
                                <span class="value-number">--</span>
                                <span class="value-unit">km/h</span>
                            </div>
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
                            <div class="weather-value" id="temperature">
                                <span class="value-number">--</span>
                                <span class="value-unit">°C</span>
                            </div>
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
                            <div class="weather-value" id="pressure">
                                <span class="value-number">--</span>
                                <span class="value-unit">hPa</span>
                            </div>
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
                            <div class="weather-value" id="humidity">
                                <span class="value-number">--</span>
                                <span class="value-unit">%</span>
                            </div>
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
            <div id="map" style="height: 500px; width: 100%;"></div>
        </div>
        
<div class="chat-bubble-container" id="chatBubbleContainer">
    <button class="chat-bubble-button" onclick="toggleChatBubble()">
        <span class="chat-bubble-icon">💬</span>
        <span class="chat-bubble-text">AI Assistant</span>
    </button>
    
    <div class="chat-bubble-window" id="chatBubbleWindow" style="overflow: visible !important;">
        <div style="background: #374151; color: white; padding: 1.5rem; display: flex; justify-content: space-between; align-items: center; min-height: 85px; flex-shrink: 0; border-radius: 16px 16px 0 0;">
            <div style="display: flex; flex-direction: column; gap: 0.75rem; flex: 1;">
                <div style="font-size: 1.25rem; font-weight: 700; color: #ffffff; margin: 0; padding: 0; line-height: 1.2;">
                    🤖 AI Safety Assistant
                </div>
                <div style="font-size: 0.875rem; color: #e5e7eb; font-weight: 500; display: flex; align-items: center; gap: 0.5rem;">
                    <span style="color: #10b981;">●</span>
                    <span>Online & Ready</span>
                </div>
            </div>
            <div style="display: flex; gap: 0.5rem;">
                <button onclick="clearChatHistory()" title="Clear chat" style="background: rgba(255,255,255,0.1); border: none; color: white; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">🗑️</button>
                <button onclick="toggleChatBubble()" title="Close" style="background: rgba(255,255,255,0.1); border: none; color: white; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">✕</button>
            </div>
        </div>
        
        <div class="chat-container" id="chatContainer" style="flex: 1; overflow-y: auto; padding: 1.5rem; background: #f9fafb;"></div>
        
        <div class="input-area" style="padding: 1rem 1.5rem; background: white; border-top: 1px solid #e5e7eb; flex-shrink: 0;">
            <div class="quick-questions" style="margin-bottom: 0.75rem;">
                <div class="quick-btns" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <button class="quick-btn" onclick="askQuestion('Am I safe from typhoons?')">Am I safe?</button>
                    <button class="quick-btn" onclick="askQuestion('What should be in my emergency kit?')">Emergency kit</button>
                    <button class="quick-btn" onclick="askQuestion('Should I evacuate?')">Evacuate?</button>
                    <button class="quick-btn" onclick="askQuestion('Explain PAGASA signals')">Signals</button>
                </div>
            </div>
            <div class="input-group" style="display: flex; gap: 0.5rem;">
                <input type="text" id="messageInput" placeholder="Ask about typhoons, safety, weather..." autocomplete="off" onkeypress="if(event.key==='Enter')sendMessage()" style="flex: 1; padding: 0.875rem 1.25rem; border: 2px solid #e5e7eb; border-radius: 25px; font-size: 0.9375rem; outline: none; background: #f9fafb;">
                <button id="sendBtn" onclick="sendMessage()" style="width: 48px; height: 48px; background: #374151; color: white; border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center;">
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
    <div class="modal-content clear-chat-modal">
        <div class="modal-icon-header">
            <div class="modal-icon-circle">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                </svg>
            </div>
        </div>
        <h2 class="modal-title-center">Clear Chat History?</h2>
        <p class="modal-description">This will permanently delete all your conversation history with the AI assistant. This action cannot be undone.</p>
        <div class="modal-actions">
            <button class="modal-btn modal-btn-cancel" onclick="closeClearChatModal()">Cancel</button>
            <button class="modal-btn modal-btn-danger" onclick="confirmClearChat()">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right: 0.5rem;">
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
</body>
</html>