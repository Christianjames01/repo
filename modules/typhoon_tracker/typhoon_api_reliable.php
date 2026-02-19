<?php
/**
 * RELIABLE AI Chat Backend v4.1
 * Fixed: Duplication issues, conversation handling, API reliability
 */

// Set proper headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// Only process POST requests with JSON
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (!$data || !isset($data['message'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request format']);
    exit();
}

// Extract request data
$userMessage = trim($data['message']);
$weatherData = $data['weatherData'] ?? null;
$typhoonData = $data['typhoonData'] ?? [];
$userLocation = $data['userLocation'] ?? 'Philippines';
$currentDateTime = $data['currentDateTime'] ?? date('F j, Y g:i A');
$forecastData = $data['forecastData'] ?? null;
$conversationHistory = $data['conversationHistory'] ?? [];

// Validate message
if (empty($userMessage)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Message cannot be empty']);
    exit();
}

// Your Google Gemini API Key
$GEMINI_API_KEY = 'AIzaSyAMsaXU84OTz-kKGgff5W5715vYGm1SkxA';

try {
    $aiResponse = callGeminiAPI(
        $GEMINI_API_KEY,
        $userMessage,
        $weatherData,
        $typhoonData,
        $userLocation,
        $currentDateTime,
        $forecastData,
        $conversationHistory
    );
    
    if ($aiResponse['success']) {
        echo json_encode([
            'success' => true,
            'response' => $aiResponse['text'],
            'model' => 'gemini-1.5-flash',
            'timestamp' => time()
        ]);
    } else {
        // Use fallback response
        $fallbackResponse = getFallbackResponse($userMessage, $weatherData, $typhoonData, $forecastData);
        echo json_encode([
            'success' => true,
            'response' => $fallbackResponse,
            'fallback' => true,
            'error_message' => $aiResponse['error'] ?? 'AI service unavailable',
            'timestamp' => time()
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'timestamp' => time()
    ]);
}

exit();

// ============================================================================
// GEMINI API CALL FUNCTION
// ============================================================================

function callGeminiAPI($apiKey, $message, $weatherData, $typhoonData, $userLocation, $currentDateTime, $forecastData, $conversationHistory) {
    if (empty($apiKey)) {
        return ['success' => false, 'error' => 'API key not configured'];
    }
    
    // Build system context
    $systemContext = buildSystemContext($userLocation, $currentDateTime, $weatherData, $typhoonData, $forecastData);
    
    // Build conversation for API
    $contents = [];
    
    // Add system context as initial user message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $systemContext]]
    ];
    
    // AI acknowledges the context
    $contents[] = [
        'role' => 'model',
        'parts' => [['text' => 'I understand. I am your AI Weather Safety Assistant with access to real-time data. I will provide accurate, data-driven responses citing specific values and explaining the meteorology clearly.']]
    ];
    
    // Add recent conversation history (last 8 messages to avoid duplication)
    if (!empty($conversationHistory)) {
        $recentMessages = array_slice($conversationHistory, -8);
        foreach ($recentMessages as $msg) {
            $role = ($msg['role'] === 'assistant' || $msg['role'] === 'model') ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]]
            ];
        }
    }
    
    // Add current user message
    $contents[] = [
        'role' => 'user',
        'parts' => [['text' => $message]]
    ];
    
    // Prepare API request
    $payload = [
        'contents' => $contents,
        'generationConfig' => [
            'temperature' => 0.7,
            'topK' => 40,
            'topP' => 0.95,
            'maxOutputTokens' => 2048,
            'candidateCount' => 1
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE']
        ]
    ];
    
    // Make API call
    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . $apiKey);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Handle successful response
    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return [
                'success' => true,
                'text' => trim($result['candidates'][0]['content']['parts'][0]['text'])
            ];
        }
        
        // Check for blocked content
        if (isset($result['candidates'][0]['finishReason']) && 
            $result['candidates'][0]['finishReason'] === 'SAFETY') {
            return [
                'success' => false,
                'error' => 'Response blocked by safety filters'
            ];
        }
    }
    
    // Handle errors
    $errorMsg = 'AI service temporarily unavailable';
    
    if ($response) {
        $errorData = json_decode($response, true);
        if (isset($errorData['error']['message'])) {
            $apiError = $errorData['error']['message'];
            
            if (stripos($apiError, 'quota') !== false || stripos($apiError, 'RESOURCE_EXHAUSTED') !== false) {
                $errorMsg = 'API quota exceeded';
            } elseif (stripos($apiError, 'API_KEY') !== false) {
                $errorMsg = 'API key invalid or missing';
            } else {
                $errorMsg = substr($apiError, 0, 100);
            }
        }
    } elseif ($curlError) {
        $errorMsg = 'Connection failed: ' . $curlError;
    } elseif ($httpCode !== 200) {
        $errorMsg = "HTTP error: $httpCode";
    }
    
    return ['success' => false, 'error' => $errorMsg];
}

// ============================================================================
// BUILD SYSTEM CONTEXT
// ============================================================================

function buildSystemContext($userLocation, $currentDateTime, $weatherData, $typhoonData, $forecastData) {
    $context = "# AI WEATHER SAFETY ASSISTANT\n\n";
    $context .= "You are an expert meteorologist for the Philippines. Provide data-driven responses that cite specific values.\n\n";
    
    $context .= "**Location:** $userLocation\n";
    $context .= "**Current Time:** $currentDateTime\n\n";
    
    // Current weather data
    if ($weatherData) {
        $humidity = floatval($weatherData['humidity']);
        $pressure = floatval($weatherData['pressure']);
        $wind = floatval($weatherData['windSpeed']);
        $temp = floatval($weatherData['temperature']);
        
        $context .= "## CURRENT WEATHER CONDITIONS\n\n";
        $context .= "- Wind: {$wind} km/h\n";
        $context .= "- Temperature: {$temp}°C\n";
        $context .= "- Pressure: {$pressure} hPa (normal: ~1012 hPa)\n";
        $context .= "- Humidity: {$humidity}%\n\n";
        
        // Analysis
        if ($humidity >= 90 && $pressure < 1010) {
            $context .= "**CRITICAL WEATHER PATTERN:**\n";
            $context .= "High humidity ({$humidity}%) + Low pressure ({$pressure} hPa) = Heavy rain conditions\n\n";
        }
        
        if ($wind > 60) {
            $signal = $wind > 118 ? '#4' : ($wind > 88 ? '#3' : '#2');
            $context .= "**STRONG WINDS:** {$wind} km/h = PAGASA Signal $signal equivalent\n\n";
        }
    }
    
    // Active typhoons
    if (!empty($typhoonData)) {
        $context .= "## ACTIVE TYPHOONS\n\n";
        foreach ($typhoonData as $idx => $t) {
            $context .= ($idx + 1) . ". **{$t['name']}**: {$t['windSpeed']} km/h, {$t['distance']} km away\n";
            
            if ($t['distance'] < 300) {
                $context .= "   - 🔴 CRITICAL: Direct impact expected\n";
            }
        }
        $context .= "\n";
    }
    
    // Tomorrow's forecast
    if ($forecastData && isset($forecastData['tomorrow'])) {
        $tmrw = $forecastData['tomorrow'];
        $context .= "## TOMORROW'S FORECAST\n\n";
        $context .= "- Temperature: {$tmrw['minTemp']}-{$tmrw['maxTemp']}°C\n";
        $context .= "- Rain Chance: {$tmrw['precipProb']}%\n";
        $context .= "- Expected Rainfall: {$tmrw['precip']}mm\n\n";
    }
    
    // Reference info
    $context .= "## GUIDELINES\n\n";
    $context .= "- ALWAYS cite specific data values in your responses\n";
    $context .= "- Explain WHY weather conditions matter\n";
    $context .= "- Use PAGASA standards for wind signals\n";
    $context .= "- Be conversational but accurate\n";
    $context .= "- Provide clear safety guidance when needed\n";
    
    return $context;
}

// ============================================================================
// FALLBACK RESPONSES
// ============================================================================

function getFallbackResponse($message, $weatherData, $typhoonData, $forecastData) {
    $msgLower = strtolower($message);
    
    // Safety questions
    if (strpos($msgLower, 'safe') !== false || strpos($msgLower, 'danger') !== false) {
        if (!empty($typhoonData)) {
            $t = $typhoonData[0];
            if ($t['distance'] < 300) {
                return "⚠️ **IMMEDIATE THREAT**\n\nTyphoon {$t['name']} is only {$t['distance']}km away with {$t['windSpeed']} km/h winds.\n\n**Actions needed:**\n1. Follow local evacuation orders\n2. Secure your property\n3. Prepare emergency kit\n4. Monitor PAGASA: (02) 8284-0800\n5. Emergency: 911";
            }
        }
        
        if ($weatherData) {
            $wind = floatval($weatherData['windSpeed']);
            if ($wind > 60) {
                return "⚠️ **STRONG WINDS**\n\nCurrent: {$wind} km/h\n\nThis is potentially dangerous. Stay indoors and secure loose objects.";
            }
            
            $humidity = floatval($weatherData['humidity']);
            $pressure = floatval($weatherData['pressure']);
            
            if ($humidity >= 90 && $pressure < 1010) {
                return "🌧️ **HEAVY RAIN CONDITIONS**\n\nHumidity: {$humidity}%\nPressure: {$pressure} hPa\n\nThese conditions indicate heavy rainfall. Stay alert for flooding.";
            }
        }
        
        return "✅ **Currently Safe**\n\nNo immediate threats detected. Stay weather-aware and keep your emergency kit ready.";
    }
    
    // Weather questions
    if (strpos($msgLower, 'weather') !== false || strpos($msgLower, 'rain') !== false) {
        if ($weatherData) {
            $response = "## Current Weather\n\n";
            $response .= "- Temperature: {$weatherData['temperature']}°C\n";
            $response .= "- Humidity: {$weatherData['humidity']}%\n";
            $response .= "- Pressure: {$weatherData['pressure']} hPa\n";
            $response .= "- Wind: {$weatherData['windSpeed']} km/h\n\n";
            
            $humidity = floatval($weatherData['humidity']);
            $pressure = floatval($weatherData['pressure']);
            
            if ($humidity >= 90 && $pressure < 1010) {
                $response .= "⚠️ High humidity + low pressure = heavy rain likely";
            } else {
                $response .= "Conditions are within normal range.";
            }
            
            return $response;
        }
    }
    
    // Default response
    return "I can help you with:\n\n✓ Current weather analysis\n✓ Typhoon threats and tracking\n✓ Safety assessments\n✓ Weather forecasts\n✓ Emergency guidance\n\nWhat would you like to know?";
}
?>