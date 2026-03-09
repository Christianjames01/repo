<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── User coordinates ──────────────────────────────────────────────────────────
$userLat = isset($_GET['lat']) ? (float)$_GET['lat'] : 12.8797;
$userLon = isset($_GET['lon']) ? (float)$_GET['lon'] : 121.7740;

// ── Debug log (returned in response so JS can print it) ──────────────────────
$debugLog = ['attempts' => []];

// ── cURL check ────────────────────────────────────────────────────────────────
if (!function_exists('curl_init')) {
    echo json_encode([
        'success'    => false,
        'typhoons'   => [],
        'bulletin'   => null,
        'error'      => 'cURL is not enabled. Enable curl in php.ini.',
        'source'     => 'none',
        'fetched_at' => date('c'),
        'debug'      => ['attempts' => ['cURL extension missing']],
    ]);
    exit();
}

// ── Cache ─────────────────────────────────────────────────────────────────────
define('CACHE_FILE', sys_get_temp_dir() . '/pagasa_tc_v4_cache.json');
define('CACHE_TTL',  300);

if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    $cached = @file_get_contents(CACHE_FILE);
    if ($cached) {
        $data = json_decode($cached, true);
        if ($data) {
            if (!empty($data['typhoons'])) {
                foreach ($data['typhoons'] as &$tc) {
                    $tc['distance']  = intval(haversineKm($userLat, $userLon, (float)$tc['lat'], (float)$tc['lon']));
                    $tc['risk']      = distanceToRisk($tc['distance']);
                    $tc['direction'] = cardinalDirection($userLat, $userLon, (float)$tc['lat'], (float)$tc['lon']);
                }
                unset($tc);
                usort($data['typhoons'], fn($a, $b) => $a['distance'] <=> $b['distance']);
            }
            $data['cached']    = true;
            $data['cache_age'] = time() - filemtime(CACHE_FILE);
            $rain = fetchOpenMeteoRain($userLat, $userLon);
            $data['rain']            = $rain['rain'];
            $data['thunderstorm']    = $rain['thunderstorm'];
            $data['current_weather'] = $rain['current_weather'];
            $data['user_location']   = ['lat' => $userLat, 'lon' => $userLon];
            $data['debug']           = ['attempts' => ['served from cache']];
            echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            exit();
        }
    }
}

// ── Try sources in order ──────────────────────────────────────────────────────
$result = null;

$sources = [
    'PAGASA-JSON' => 'fetchPAGASAJson',
    'PAGASA-PDF'  => 'fetchPAGASAPdf',
    'PAGASA-HTML' => 'fetchPAGASAHtml',
    'GDACS'       => 'fetchGDACS',
    'JMA'         => 'fetchJMA',
];

foreach ($sources as $label => $fn) {
    $attempt = ['source' => $label, 'tried' => true];
    try {
        $res = $fn();
        if ($res !== null) {
            $attempt['result'] = 'success';
            $attempt['typhoon_count'] = count($res['typhoons'] ?? []);
            $debugLog['attempts'][] = $attempt;
            $result = $res;
            break;
        } else {
            $attempt['result'] = 'null (no data or unreachable)';
        }
    } catch (Throwable $e) {
        $attempt['result'] = 'exception: ' . $e->getMessage();
    }
    $debugLog['attempts'][] = $attempt;
}

// ── If all sources failed, return a clean "no data" response ─────────────────
// This is NOT the same as "no active typhoons" — it means the network failed.
if (!$result) {
    $result = [
        'success'    => false,
        'typhoons'   => [],
        'bulletin'   => null,
        'source'     => 'none',
        'error'      => 'All data sources unreachable. Check server outbound HTTP access.',
        'fetched_at' => date('c'),
    ];
    $result['debug'] = $debugLog;

    // Still fetch rain data (Open-Meteo is usually reachable)
    $rain = fetchOpenMeteoRain($userLat, $userLon);
    $result['rain']            = $rain['rain'];
    $result['thunderstorm']    = $rain['thunderstorm'];
    $result['current_weather'] = $rain['current_weather'];
    $result['user_location']   = ['lat' => $userLat, 'lon' => $userLon];

    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}

// ── Enrich typhoons with distance / risk / direction ─────────────────────────
if (!empty($result['typhoons'])) {
    foreach ($result['typhoons'] as &$tc) {
        $lat = (float)($tc['lat'] ?? 12.88);
        $lon = (float)($tc['lon'] ?? 121.77);
        $tc['distance']  = intval(haversineKm($userLat, $userLon, $lat, $lon));
        $tc['risk']      = distanceToRisk($tc['distance']);
        $tc['direction'] = cardinalDirection($userLat, $userLon, $lat, $lon);
        $tc['signal']    = $tc['signal']   ?? windToSignal((float)($tc['windSpeed'] ?? 0));
        $tc['category']  = $tc['category'] ?? windToCategory((float)($tc['windSpeed'] ?? 0));
    }
    unset($tc);
    usort($result['typhoons'], fn($a, $b) => $a['distance'] <=> $b['distance']);
}

// ── Rain + thunderstorm (always fresh) ───────────────────────────────────────
$rainData = fetchOpenMeteoRain($userLat, $userLon);
$result['rain']            = $rainData['rain'];
$result['thunderstorm']    = $rainData['thunderstorm'];
$result['current_weather'] = $rainData['current_weather'];
$result['user_location']   = ['lat' => $userLat, 'lon' => $userLon];
$result['debug']           = $debugLog;

// ── Cache result (without rain — that's always fresh) ────────────────────────
$toCache = $result;
unset($toCache['rain'], $toCache['thunderstorm'], $toCache['current_weather'], $toCache['debug']);
@file_put_contents(CACHE_FILE, json_encode($toCache));

$result['cached'] = false;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);


// =============================================================================
// CURL HELPER
// =============================================================================
function curlGet(string $url, int $timeout = 12): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (compatible; PhilippineTyphoonTracker/4.0)',
            'Accept: application/json, text/html, */*',
            'Accept-Language: en-US,en;q=0.9',
        ],
        CURLOPT_ENCODING => 'gzip, deflate',
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $code < 200 || $code >= 400) {
        // Log the actual error for debugging
        error_log("[pagasa_proxy] cURL failed for $url — HTTP $code — $err");
        return null;
    }
    return $resp;
}


// =============================================================================
// OPEN-METEO — Rain + thunderstorm
// =============================================================================
function fetchOpenMeteoRain(float $lat, float $lon): array {
    $url = "https://api.open-meteo.com/v1/forecast?"
         . "latitude={$lat}&longitude={$lon}"
         . "&current_weather=true"
         . "&hourly=precipitation_probability,weathercode,precipitation"
         . "&daily=precipitation_probability_max,precipitation_sum,weathercode,windspeed_10m_max"
         . "&timezone=Asia%2FManila&forecast_days=3";

    $raw   = curlGet($url, 10);
    $empty = ['rain' => null, 'thunderstorm' => null, 'current_weather' => null];
    if (!$raw) return $empty;
    $d = json_decode($raw, true);
    if (!$d) return $empty;

    $hour           = (int)date('G');
    $hourlyRainProbs = array_slice($d['hourly']['precipitation_probability'] ?? [], $hour, 12);
    $maxRainProb    = !empty($hourlyRainProbs) ? (int)max($hourlyRainProbs) : 0;
    $hourlyCodes    = array_slice($d['hourly']['weathercode'] ?? [], $hour, 12);
    $thunderCount   = count(array_filter($hourlyCodes, fn($c) => (int)$c >= 95));
    $thunderProb    = !empty($hourlyCodes) ? (int)round(($thunderCount / count($hourlyCodes)) * 100) : 0;
    $rain24h        = round((float)($d['daily']['precipitation_sum'][0] ?? 0), 1);
    $dailyCode      = (int)($d['daily']['weathercode'][0] ?? 0);
    $currentCode    = (int)($d['current_weather']['weathercode'] ?? 0);
    $currentWind    = round((float)($d['current_weather']['windspeed'] ?? 0), 1);
    $currentTemp    = round((float)($d['current_weather']['temperature'] ?? 0), 1);
    $currentWx      = classifyWeatherCode($currentCode);
    $dailyWx        = classifyWeatherCode($dailyCode);
    $warning        = pagasaRainWarning($rain24h, $thunderProb, $maxRainProb);

    return [
        'rain' => [
            'probability_pct' => $maxRainProb,
            'accumulation_mm' => $rain24h,
            'warning_level'   => $warning['level'],
            'warning_action'  => $warning['action'],
            'warning_color'   => $warning['color'],
            'warning_bg'      => $warning['bg'],
            'warning_icon'    => $warning['icon'],
            'current_code'    => $currentCode,
            'current_label'   => $currentWx['label'],
            'current_icon'    => $currentWx['icon'],
            'current_type'    => $currentWx['type'],
            'daily_code'      => $dailyCode,
            'daily_label'     => $dailyWx['label'],
            'daily_icon'      => $dailyWx['icon'],
        ],
        'thunderstorm' => [
            'probability_pct' => $thunderProb,
            'active'          => $currentCode >= 95,
            'expected_today'  => $thunderProb >= 20 || $dailyCode >= 95,
            'label'           => thunderLabel($thunderProb),
        ],
        'current_weather' => [
            'wind_kmh'  => $currentWind,
            'temp_c'    => $currentTemp,
            'condition' => $currentWx['label'],
            'icon'      => $currentWx['icon'],
        ],
    ];
}

function pagasaRainWarning(float $r, int $tp, int $rp): array {
    if ($r >= 150 || $tp >= 70) return [
        'level' => 'Extreme', 'color' => '#7f1d1d', 'bg' => '#fee2e2', 'icon' => '🔴',
        'action' => 'Imminent danger of flooding. Evacuate immediately if in low-lying areas.',
    ];
    if ($r >= 100 || ($tp >= 50 && $rp >= 60)) return [
        'level' => 'Heavy', 'color' => '#92400e', 'bg' => '#fef3c7', 'icon' => '🟠',
        'action' => 'Heavy rainfall expected. Serious flooding possible. Prepare to evacuate.',
    ];
    if ($r >= 50 || $tp >= 30) return [
        'level' => 'Moderate', 'color' => '#1e40af', 'bg' => '#dbeafe', 'icon' => '🟡',
        'action' => 'Moderate rain expected. Light flooding possible in low-lying areas.',
    ];
    if ($r >= 15 || $rp >= 30) return [
        'level' => 'Light', 'color' => '#065f46', 'bg' => '#d1fae5', 'icon' => '🟢',
        'action' => 'Light rain expected. Carry an umbrella. Monitor updates.',
    ];
    return [
        'level' => 'None', 'color' => '#374151', 'bg' => '#f3f4f6', 'icon' => '✅',
        'action' => 'No significant rainfall expected. Conditions are generally fair.',
    ];
}

function classifyWeatherCode(int $code): array {
    if ($code >= 95) return ['icon' => '⛈️',  'label' => 'Thunderstorm',   'type' => 'thunder'];
    if ($code >= 80) return ['icon' => '🌦️', 'label' => 'Rain Showers',   'type' => 'rain'];
    if ($code >= 71) return ['icon' => '❄️',  'label' => 'Snow',           'type' => 'snow'];
    if ($code >= 61) return ['icon' => '🌧️', 'label' => 'Rain',           'type' => 'rain'];
    if ($code >= 51) return ['icon' => '🌦️', 'label' => 'Drizzle',        'type' => 'drizzle'];
    if ($code >= 45) return ['icon' => '🌫️', 'label' => 'Fog',            'type' => 'fog'];
    if ($code == 3)  return ['icon' => '☁️',  'label' => 'Overcast',       'type' => 'cloudy'];
    if ($code == 2)  return ['icon' => '⛅',  'label' => 'Partly Cloudy',  'type' => 'partly_cloudy'];
    if ($code == 1)  return ['icon' => '🌤️', 'label' => 'Mainly Clear',   'type' => 'mainly_clear'];
    return              ['icon' => '☀️',  'label' => 'Clear / Sunny',  'type' => 'clear'];
}

function thunderLabel(int $pct): string {
    if ($pct >= 60) return 'High Thunderstorm Risk';
    if ($pct >= 30) return 'Moderate Thunderstorm Risk';
    if ($pct >= 10) return 'Slight Chance of Thunderstorm';
    return 'No Thunderstorm Expected';
}


// =============================================================================
// SOURCE 1: PAGASA JSON
// =============================================================================
function fetchPAGASAJson(): ?array {
    foreach ([
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/bulletin.json',
        'https://pubfiles.pagasa.dost.gov.ph/pagasaweb/files/tamss/weather/bulletin.json',
    ] as $url) {
        $json = curlGet($url);
        if (!$json) continue;
        $data = json_decode($json, true);
        if (!$data) continue;
        $typhoons = parsePAGASAJsonData($data);
        if ($typhoons !== null) {
            return [
                'success'    => true,
                'typhoons'   => $typhoons,
                'bulletin'   => $data['bulletin_text'] ?? $data['bulletin'] ?? null,
                'source'     => 'PAGASA-JSON',
                'fetched_at' => date('c'),
            ];
        }
        // PAGASA returned valid JSON but no typhoons — that's "all clear"
        if (is_array($data)) {
            return [
                'success'    => true,
                'typhoons'   => [],
                'bulletin'   => 'No active tropical cyclones within the PAR.',
                'source'     => 'PAGASA-JSON',
                'fetched_at' => date('c'),
            ];
        }
    }
    return null;
}

function parsePAGASAJsonData($data): ?array {
    $systems = $data['tropical_cyclones'] ?? $data['cyclones'] ?? $data['systems'] ?? $data['data'] ?? [];
    if (isset($data['name']) || isset($data['wind_speed'])) $systems = [$data];
    if (empty($systems)) return null;
    $typhoons = [];
    foreach ($systems as $sys) {
        $name      = strtoupper($sys['name'] ?? $sys['philippine_name'] ?? $sys['pagasa_name'] ?? 'UNNAMED');
        $windSpeed = intval($sys['wind_speed'] ?? $sys['max_wind'] ?? $sys['winds'] ?? 0);
        $lat       = floatval($sys['lat'] ?? $sys['latitude'] ?? $sys['position_lat'] ?? 12.88);
        $lon       = floatval($sys['lon'] ?? $sys['longitude'] ?? $sys['position_lon'] ?? 121.77);
        $signal    = intval($sys['signal'] ?? $sys['wind_signal'] ?? $sys['tcws'] ?? 0);
        if (empty($name) || $name === 'UNNAMED') continue;
        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windSpeed),
            'windSpeed' => $windSpeed ?: estimateWind($signal),
            'lat'       => $lat,
            'lon'       => $lon,
            'signal'    => $signal,
            'source'    => 'PAGASA-JSON',
        ];
    }
    return empty($typhoons) ? null : $typhoons;
}


// =============================================================================
// SOURCE 2: PAGASA PDF
// =============================================================================
function fetchPAGASAPdf(): ?array {
    foreach ([
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/bulletin.pdf',
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/tcadvisory.pdf',
    ] as $url) {
        $bytes = curlGet($url, 14);
        if (!$bytes || strlen($bytes) < 1000 || substr($bytes, 0, 4) !== '%PDF') continue;
        $text = implode(' ', extractPdfTextChunks($bytes));
        if (stripos($text, 'no active') !== false || stripos($text, 'no tropical cyclone') !== false) {
            return [
                'success'    => true,
                'typhoons'   => [],
                'bulletin'   => 'No active tropical cyclones within the PAR.',
                'source'     => 'PAGASA-PDF',
                'fetched_at' => date('c'),
            ];
        }
        $parsed = parsePDFText($text);
        if ($parsed) return $parsed;
    }
    return null;
}

function extractPdfTextChunks($bytes): array {
    $chunks = [];
    preg_match_all('/\(([^)]{3,200})\)/', $bytes, $m1);
    foreach ($m1[1] as $s) {
        $c = trim(preg_replace('/[^\x20-\x7E]/', ' ', $s));
        if (strlen($c) > 2) $chunks[] = $c;
    }
    preg_match_all('/[A-Z][A-Za-z0-9 \.,\-\/°%]{10,}/', $bytes, $m2);
    foreach ($m2[0] as $s) $chunks[] = trim($s);
    return $chunks;
}

function parsePDFText($text): ?array {
    if (stripos($text, 'No Active') !== false || stripos($text, 'no tropical cyclone') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No active tropical cyclones within the PAR.',
            'source'     => 'PAGASA-PDF',
            'fetched_at' => date('c'),
        ];
    }
    preg_match_all(
        '/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+([A-Z]{2,20}(?:\s+\([A-Z]+\))?)/i',
        $text, $nameMatches
    );
    preg_match('/(?:maximum sustained winds?(?:\s+of)?|winds?\s+of\s+up\s+to)\s+(\d{2,3})\s*(?:km\/h|kph)/i', $text, $windMatch);
    preg_match('/(\d{1,2}\.?\d*)\s*[°º]?\s*N[,\s]+(\d{2,3}\.?\d*)\s*[°º]?\s*E/i', $text, $posMatch);
    preg_match_all('/(?:Wind\s+Signal\s+(?:No\.?|#)\s*([1-5]))/i', $text, $signalMatches);
    $maxSignal = !empty($signalMatches[1]) ? max(array_map('intval', $signalMatches[1])) : 0;

    if (empty($nameMatches[0])) return null;
    $typhoons = [];
    $seen     = [];
    foreach (array_unique($nameMatches[0]) as $fullName) {
        $justName = trim(preg_replace('/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+/i', '', $fullName));
        if (in_array($justName, $seen) || strlen($justName) < 2) continue;
        $seen[] = $justName;
        $typhoons[] = [
            'name'      => strtoupper($justName),
            'category'  => windToCategory(isset($windMatch[1]) ? intval($windMatch[1]) : 0),
            'windSpeed' => isset($windMatch[1]) ? intval($windMatch[1]) : 0,
            'lat'       => isset($posMatch[1]) ? floatval($posMatch[1]) : 12.88,
            'lon'       => isset($posMatch[2]) ? floatval($posMatch[2]) : 121.77,
            'signal'    => $maxSignal,
            'source'    => 'PAGASA-PDF',
        ];
    }
    return empty($typhoons) ? null : [
        'success'    => true,
        'typhoons'   => $typhoons,
        'bulletin'   => null,
        'source'     => 'PAGASA-PDF',
        'fetched_at' => date('c'),
    ];
}


// =============================================================================
// SOURCE 3: PAGASA HTML
// =============================================================================
function fetchPAGASAHtml(): ?array {
    foreach ([
        'https://bagong.pagasa.dost.gov.ph/tropical-cyclone/severe-weather-bulletin',
        'https://bagong.pagasa.dost.gov.ph/tropical-cyclone',
        'https://www.pagasa.dost.gov.ph/tropical-cyclone/severe-weather-bulletin',
    ] as $url) {
        $html = curlGet($url);
        if (!$html || strlen($html) < 500) continue;
        $result = parsePAGASAHtml($html);
        if ($result) return $result;
    }
    return null;
}

function parsePAGASAHtml($html): ?array {
    if (stripos($html, 'No Active') !== false
        || stripos($html, 'no tropical cyclone') !== false
        || stripos($html, 'no active warning') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No active tropical cyclones within the PAR.',
            'source'     => 'PAGASA-HTML',
            'fetched_at' => date('c'),
        ];
    }
    $text = preg_replace('/\s+/', ' ', strip_tags(
        preg_replace(['/<script[^>]*>.*?<\/script>/si', '/<style[^>]*>.*?<\/style>/si'], '', $html)
    ));
    preg_match_all(
        '/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+([A-Z]{2,20}(?:\s+\([A-Z]+\))?)/i',
        $text, $nameMatches
    );
    preg_match('/(?:maximum sustained winds?(?:\s+of)?|winds?\s+of\s+up\s+to)\s+(\d{2,3})\s*(?:km\/h|kph)/i', $text, $windMatch);
    preg_match('/(\d{1,2}\.?\d*)\s*[°º]?\s*N[,\s]+(\d{2,3}\.?\d*)\s*[°º]?\s*E/i', $text, $posMatch);
    preg_match_all('/(?:Wind\s+Signal\s+(?:No\.?|#)\s*([1-5]))/i', $text, $signalMatches);
    $maxSignal = !empty($signalMatches[1]) ? max(array_map('intval', $signalMatches[1])) : 0;

    if (empty($nameMatches[0])) return null;
    $typhoons = [];
    $seen     = [];
    foreach (array_unique($nameMatches[0]) as $fullName) {
        $justName = trim(preg_replace('/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+/i', '', $fullName));
        if (in_array($justName, $seen) || strlen($justName) < 2) continue;
        $seen[] = $justName;
        $typhoons[] = [
            'name'      => strtoupper($justName),
            'category'  => windToCategory(isset($windMatch[1]) ? intval($windMatch[1]) : 0),
            'windSpeed' => isset($windMatch[1]) ? intval($windMatch[1]) : 0,
            'lat'       => isset($posMatch[1]) ? floatval($posMatch[1]) : 12.88,
            'lon'       => isset($posMatch[2]) ? floatval($posMatch[2]) : 121.77,
            'signal'    => $maxSignal,
            'source'    => 'PAGASA-HTML',
        ];
    }
    return empty($typhoons) ? null : [
        'success'    => true,
        'typhoons'   => $typhoons,
        'bulletin'   => null,
        'source'     => 'PAGASA-HTML',
        'fetched_at' => date('c'),
    ];
}


// =============================================================================
// SOURCE 4: GDACS
// =============================================================================
function fetchGDACS(): ?array {
    $url = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red'
         . '&fromDate=' . date('Y-m-d', strtotime('-7 days'))
         . '&toDate='   . date('Y-m-d', strtotime('+1 day'));
    $json = curlGet($url, 10);
    if (!$json) return null;
    $data = json_decode($json, true);
    if (!$data) return null;

    $features = $data['features'] ?? $data['Features'] ?? [];
    if (empty($features)) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No active tropical cyclones. Source: GDACS.',
            'source'     => 'GDACS',
            'fetched_at' => date('c'),
        ];
    }

    $typhoons = [];
    foreach ($features as $f) {
        $p   = $f['properties'] ?? [];
        $geo = $f['geometry']   ?? [];
        $lat = $geo['coordinates'][1] ?? ($p['lat'] ?? null);
        $lon = $geo['coordinates'][0] ?? ($p['lon'] ?? null);
        if ($lon !== null && ($lon < 100 || $lon > 180)) continue;
        if ($lat !== null && ($lat < 0  || $lat > 40))  continue;
        $name    = strtoupper($p['name'] ?? $p['eventname'] ?? 'UNNAMED');
        $windKts = floatval($p['maxwind'] ?? $p['wind_max'] ?? 0);
        $windKmh = $windKts > 0 ? intval($windKts * 1.852) : intval($p['windspeed'] ?? 0);
        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat ? floatval($lat) : 12.88,
            'lon'       => $lon ? floatval($lon) : 121.77,
            'direction' => null,
            'signal'    => windToSignal($windKmh),
            'source'    => 'GDACS',
        ];
    }
    return [
        'success'    => true,
        'typhoons'   => $typhoons,
        'bulletin'   => null,
        'source'     => 'GDACS',
        'fetched_at' => date('c'),
    ];
}


// =============================================================================
// SOURCE 5: JMA
// =============================================================================
function fetchJMA(): ?array {
    $json = curlGet('https://www.jma.go.jp/bosai/typhoon/data/nowcast.json', 8);
    if ($json) {
        $data = json_decode($json, true);
        if ($data && is_array($data)) {
            $t = parseJMAJson($data);
            if ($t !== null) {
                return [
                    'success'    => true,
                    'typhoons'   => $t,
                    'bulletin'   => null,
                    'source'     => 'JMA',
                    'fetched_at' => date('c'),
                ];
            }
        }
    }

    $html = curlGet('https://www.jma.go.jp/en/typh/index.html', 8);
    if (!$html) return null;

    if (stripos($html, 'No typhoon') !== false || stripos($html, 'no tropical') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No typhoons in the Western Pacific (JMA).',
            'source'     => 'JMA',
            'fetched_at' => date('c'),
        ];
    }

    preg_match_all('/(?:Typhoon|TS|STS|TD)\s+(?:No\.\s*\d+\s+)?(?:INTERNATIONAL:\s*)?([A-Z]{2,20})/i', $html, $nm);
    preg_match_all('/(\d{1,2}\.?\d)\s*N[,\s]+(\d{2,3}\.?\d)\s*E/', $html, $pm);
    preg_match_all('/(\d{2,3})\s*kt/', $html, $wm);

    if (empty($nm[1])) return null;

    $typhoons = [];
    foreach ($nm[1] as $i => $name) {
        $lat    = isset($pm[1][$i]) ? floatval($pm[1][$i]) : 12.88;
        $lon    = isset($pm[2][$i]) ? floatval($pm[2][$i]) : 135.0;
        if ($lon < 100 || $lon > 180) continue;
        $windKmh = intval((isset($wm[1][$i]) ? intval($wm[1][$i]) : 40) * 1.852);
        $typhoons[] = [
            'name'      => strtoupper($name),
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat,
            'lon'       => $lon,
            'direction' => null,
            'signal'    => windToSignal($windKmh),
            'source'    => 'JMA',
        ];
    }
    return empty($typhoons) ? null : [
        'success'    => true,
        'typhoons'   => $typhoons,
        'bulletin'   => null,
        'source'     => 'JMA',
        'fetched_at' => date('c'),
    ];
}

function parseJMAJson($data): ?array {
    $systems  = $data['forecast'] ?? $data['typhoon'] ?? $data['typhoons'] ?? [];
    if (empty($systems)) return null;
    $typhoons = [];
    foreach ((array)$systems as $sys) {
        $lat    = floatval($sys['lat'] ?? $sys['latitude']  ?? 0);
        $lon    = floatval($sys['lon'] ?? $sys['longitude'] ?? 0);
        if ($lon < 100 || $lon > 180 || $lat < 0 || $lat > 40) continue;
        $windKmh = intval(floatval($sys['wind'] ?? $sys['max_wind'] ?? $sys['winds'] ?? 40) * 1.852);
        $name    = strtoupper($sys['name'] ?? $sys['id'] ?? 'UNNAMED');
        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat,
            'lon'       => $lon,
            'direction' => null,
            'signal'    => windToSignal($windKmh),
            'source'    => 'JMA',
        ];
    }
    return empty($typhoons) ? null : $typhoons;
}


// =============================================================================
// SHARED HELPERS
// =============================================================================
function haversineKm($lat1, $lon1, $lat2, $lon2) {
    $R    = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function cardinalDirection($fromLat, $fromLon, $toLat, $toLon) {
    $angle = rad2deg(atan2($toLon - $fromLon, $toLat - $fromLat));
    $dirs  = ['N', 'NE', 'E', 'SE', 'S', 'SW', 'W', 'NW', 'N'];
    return $dirs[round($angle / 45) + 4] ?? 'N';
}

function distanceToRisk($dist) {
    if ($dist < 200) return 'CRITICAL';
    if ($dist < 400) return 'HIGH';
    if ($dist < 700) return 'MODERATE';
    return 'LOW';
}

function windToCategory($kmh) {
    if ($kmh >= 185) return 'Super Typhoon';
    if ($kmh >= 118) return 'Typhoon';
    if ($kmh >= 89)  return 'Severe Tropical Storm';
    if ($kmh >= 62)  return 'Tropical Storm';
    return 'Tropical Depression';
}

function windToSignal($kmh) {
    if ($kmh >= 185) return 5;
    if ($kmh >= 118) return 4;
    if ($kmh >= 89)  return 3;
    if ($kmh >= 62)  return 2;
    if ($kmh >= 39)  return 1;
    return 0;
}

function estimateWind($signal) {
    return [5 => 185, 4 => 130, 3 => 100, 2 => 75, 1 => 50][$signal] ?? 0;
}