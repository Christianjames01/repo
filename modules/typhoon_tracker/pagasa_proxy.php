<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ── Cache ──────────────────────────────────────────────────────────────────────
define('CACHE_FILE', sys_get_temp_dir() . '/pagasa_tc_v2_cache.json');
define('CACHE_TTL',  600); // 10 minutes

if (file_exists(CACHE_FILE) && (time() - filemtime(CACHE_FILE)) < CACHE_TTL) {
    $cached = @file_get_contents(CACHE_FILE);
    if ($cached) {
        $data = json_decode($cached, true);
        if ($data) {
            $data['cached']    = true;
            $data['cache_age'] = time() - filemtime(CACHE_FILE);
            echo json_encode($data);
            exit();
        }
    }
}

// ── Run sources in order ───────────────────────────────────────────────────────
$result = null;

// 1. Try PAGASA bulletin.json (undocumented real-time endpoint)
if (!$result) $result = fetchPAGASAJson();

// 2. Try PAGASA bulletin.pdf text extraction
if (!$result || empty($result['typhoons'])) $result = fetchPAGASAPdf();

// 3. Try PAGASA HTML scrape (original method, improved)
if (!$result || empty($result['typhoons'])) $result = fetchPAGASAHtml();

// 4. Try GDACS
if (!$result || empty($result['typhoons'])) $result = fetchGDACS();

// 5. Try JMA RSS
if (!$result || empty($result['typhoons'])) $result = fetchJMA();

// Final fallback
if (!$result) {
    $result = [
        'success'    => false,
        'typhoons'   => [],
        'bulletin'   => null,
        'source'     => 'none',
        'error'      => 'All data sources unavailable. Visit https://bagong.pagasa.dost.gov.ph for official info.',
        'fetched_at' => date('c'),
    ];
}

// Cache and output
@file_put_contents(CACHE_FILE, json_encode($result));
$result['cached'] = false;
echo json_encode($result);


// ═════════════════════════════════════════════════════════════════════════════
// SOURCE 1: PAGASA bulletin.json
// Discovered from PAGASA's pubfiles server - provides structured data when
// an active cyclone bulletin is being issued.
// ═════════════════════════════════════════════════════════════════════════════
function fetchPAGASAJson() {
    // These are the known PAGASA public JSON endpoints
    $endpoints = [
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/bulletin.json',
        'https://pubfiles.pagasa.dost.gov.ph/pagasaweb/files/tamss/weather/bulletin.json',
    ];

    $ctx = makeContext();

    foreach ($endpoints as $url) {
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) continue;

        $data = json_decode($json, true);
        if (!$data) continue;

        // Parse PAGASA's JSON structure
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
    }

    return null;
}

function parsePAGASAJsonData($data) {
    $typhoons = [];

    // Handle different possible JSON structures PAGASA might use
    $systems = $data['tropical_cyclones']
        ?? $data['cyclones']
        ?? $data['systems']
        ?? $data['data']
        ?? [];

    // Sometimes it's a single object, not array
    if (isset($data['name']) || isset($data['wind_speed'])) {
        $systems = [$data];
    }

    if (empty($systems)) return null;

    foreach ($systems as $sys) {
        $name      = strtoupper($sys['name'] ?? $sys['philippine_name'] ?? $sys['pagasa_name'] ?? 'UNNAMED');
        $windSpeed = intval($sys['wind_speed'] ?? $sys['max_wind'] ?? $sys['winds'] ?? 0);
        $lat       = floatval($sys['lat'] ?? $sys['latitude'] ?? $sys['position_lat'] ?? 12.88);
        $lon       = floatval($sys['lon'] ?? $sys['longitude'] ?? $sys['position_lon'] ?? 121.77);
        $signal    = intval($sys['signal'] ?? $sys['wind_signal'] ?? $sys['tcws'] ?? 0);

        if (empty($name) || $name === 'UNNAMED') continue;

        $distance = intval(haversineKm(12.8797, 121.7740, $lat, $lon));

        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windSpeed),
            'windSpeed' => $windSpeed ?: estimateWind($signal),
            'lat'       => $lat,
            'lon'       => $lon,
            'distance'  => $distance,
            'direction' => $sys['movement_direction'] ?? $sys['direction'] ?? null,
            'signal'    => $signal,
            'source'    => 'PAGASA-JSON',
        ];
    }

    return empty($typhoons) ? null : $typhoons;
}


// ═════════════════════════════════════════════════════════════════════════════
// SOURCE 2: PAGASA bulletin.pdf - fetch and extract text
// PAGASA always publishes an active bulletin PDF at this URL when a typhoon
// is present in the PAR.
// ═════════════════════════════════════════════════════════════════════════════
function fetchPAGASAPdf() {
    $pdfUrls = [
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/bulletin.pdf',
        'https://pubfiles.pagasa.dost.gov.ph/tamss/weather/tcadvisory.pdf',
    ];

    $ctx = makeContext();

    foreach ($pdfUrls as $url) {
        $pdfBytes = @file_get_contents($url, false, $ctx);
        if (!$pdfBytes || strlen($pdfBytes) < 1000) continue;

        // Check it's actually a PDF
        if (substr($pdfBytes, 0, 4) !== '%PDF') continue;

        // Check for "no active" bulletin
        // PDFs encode text; do a basic string search for common phrases
        $textChunks = extractPdfTextChunks($pdfBytes);
        $text = implode(' ', $textChunks);

        if (stripos($text, 'no active') !== false ||
            stripos($text, 'no tropical cyclone') !== false) {
            return [
                'success'    => true,
                'typhoons'   => [],
                'bulletin'   => 'No active tropical cyclones in the Philippine Area of Responsibility.',
                'source'     => 'PAGASA-PDF',
                'fetched_at' => date('c'),
            ];
        }

        // Try to extract typhoon info from PDF text chunks
        $parsed = parsePDFText($text, $url);
        if ($parsed) return $parsed;
    }

    return null;
}

/**
 * Extract readable text strings from a PDF binary.
 * Works on most PAGASA PDFs which use standard Type1/TrueType fonts.
 */
function extractPdfTextChunks($bytes) {
    $chunks = [];

    // Method 1: Extract strings between parentheses (PDF string objects)
    preg_match_all('/\(([^)]{3,200})\)/', $bytes, $m1);
    foreach ($m1[1] as $s) {
        $clean = preg_replace('/[^\x20-\x7E]/', ' ', $s);
        $clean = trim($clean);
        if (strlen($clean) > 2) $chunks[] = $clean;
    }

    // Method 2: Extract readable ASCII sequences
    preg_match_all('/[A-Z][A-Za-z0-9 \.,\-\/°%]{10,}/', $bytes, $m2);
    foreach ($m2[0] as $s) {
        $chunks[] = trim($s);
    }

    // Method 3: Try pdftotext if available on server
    if (function_exists('exec')) {
        $tmpPdf = tempnam(sys_get_temp_dir(), 'pagasa_') . '.pdf';
        $tmpTxt = $tmpPdf . '.txt';
        file_put_contents($tmpPdf, $bytes);
        @exec("pdftotext -layout '{$tmpPdf}' '{$tmpTxt}' 2>/dev/null");
        if (file_exists($tmpTxt)) {
            $chunks[] = file_get_contents($tmpTxt);
            @unlink($tmpTxt);
        }
        @unlink($tmpPdf);
    }

    return $chunks;
}

function parsePDFText($text, $sourceUrl) {
    $typhoons = [];

    // No active typhoon check
    if (stripos($text, 'No Active') !== false ||
        stripos($text, 'no tropical cyclone') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No active tropical cyclones within the PAR.',
            'source'     => 'PAGASA-PDF',
            'fetched_at' => date('c'),
        ];
    }

    // Extract typhoon name
    preg_match_all(
        '/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+([A-Z]{2,20}(?:\s+\([A-Z]+\))?)/i',
        $text, $nameMatches
    );

    // Extract wind speed
    preg_match('/(?:maximum sustained winds?(?:\s+of)?|winds?\s+of\s+up\s+to)\s+(\d{2,3})\s*(?:km\/h|kph)/i', $text, $windMatch);

    // Extract coordinates
    preg_match('/(\d{1,2}\.?\d*)\s*[°º]?\s*N[,\s]+(\d{2,3}\.?\d*)\s*[°º]?\s*E/i', $text, $posMatch);

    // Extract signal
    preg_match_all('/(?:Wind\s+Signal\s+(?:No\.?|#)\s*([1-5]))/i', $text, $signalMatches);
    $maxSignal = !empty($signalMatches[1]) ? max(array_map('intval', $signalMatches[1])) : 0;

    // Extract distance
    preg_match('/(\d{3,4})\s*km\s+((?:East|West|North|South|NE|NW|SE|SW|ENE|WNW|NNE|SSW)[a-z\-\s]*)\s+of\s+([^\.]{3,40})/i', $text, $distMatch);

    if (empty($nameMatches[0])) return null;

    $seen = [];
    foreach (array_unique($nameMatches[0]) as $fullName) {
        $justName = preg_replace('/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+/i', '', $fullName);
        $justName = trim($justName);
        if (in_array($justName, $seen) || strlen($justName) < 2) continue;
        $seen[] = $justName;

        $windSpeed = isset($windMatch[1]) ? intval($windMatch[1]) : 0;
        $lat = isset($posMatch[1]) ? floatval($posMatch[1]) : 12.88;
        $lon = isset($posMatch[2]) ? floatval($posMatch[2]) : 121.77;
        $distance = isset($distMatch[1]) ? intval($distMatch[1]) : intval(haversineKm(12.8797, 121.7740, $lat, $lon));

        $typhoons[] = [
            'name'      => strtoupper($justName),
            'category'  => windToCategory($windSpeed),
            'windSpeed' => $windSpeed,
            'lat'       => $lat,
            'lon'       => $lon,
            'distance'  => $distance,
            'direction' => isset($distMatch[2]) ? ucwords(strtolower(trim($distMatch[2]))) : null,
            'signal'    => $maxSignal,
            'source'    => 'PAGASA-PDF',
        ];
    }

    if (empty($typhoons)) return null;

    return [
        'success'    => true,
        'typhoons'   => $typhoons,
        'bulletin'   => null,
        'source'     => 'PAGASA-PDF',
        'fetched_at' => date('c'),
    ];
}


// ═════════════════════════════════════════════════════════════════════════════
// SOURCE 3: PAGASA HTML scrape (improved)
// ═════════════════════════════════════════════════════════════════════════════
function fetchPAGASAHtml() {
    $urls = [
        'https://bagong.pagasa.dost.gov.ph/tropical-cyclone/severe-weather-bulletin',
        'https://bagong.pagasa.dost.gov.ph/tropical-cyclone',
        'https://www.pagasa.dost.gov.ph/tropical-cyclone/severe-weather-bulletin',
    ];

    $ctx = makeContext();

    foreach ($urls as $url) {
        $html = @file_get_contents($url, false, $ctx);
        if (!$html || strlen($html) < 500) continue;

        $result = parsePAGASAHtml($html);
        if ($result) return $result;
    }

    return null;
}

function parsePAGASAHtml($html) {
    // No active check
    if (stripos($html, 'No Active') !== false ||
        stripos($html, 'no tropical cyclone') !== false ||
        stripos($html, 'no active warning') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No active tropical cyclones within the Philippine Area of Responsibility.',
            'source'     => 'PAGASA-HTML',
            'fetched_at' => date('c'),
        ];
    }

    $text = strip_tags(preg_replace(['/<script[^>]*>.*?<\/script>/si', '/<style[^>]*>.*?<\/style>/si'], '', $html));
    $text = preg_replace('/\s+/', ' ', $text);

    // Name pattern
    preg_match_all(
        '/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+([A-Z]{2,20}(?:\s+\([A-Z]+\))?)/i',
        $text, $nameMatches
    );
    preg_match('/(?:maximum sustained winds?(?:\s+of)?|winds?\s+of\s+up\s+to)\s+(\d{2,3})\s*(?:km\/h|kph)/i', $text, $windMatch);
    preg_match('/(\d{1,2}\.?\d*)\s*[°º]?\s*N[,\s]+(\d{2,3}\.?\d*)\s*[°º]?\s*E/i', $text, $posMatch);
    preg_match_all('/(?:Wind\s+Signal\s+(?:No\.?|#)\s*([1-5]))/i', $text, $signalMatches);
    $maxSignal = !empty($signalMatches[1]) ? max(array_map('intval', $signalMatches[1])) : 0;
    preg_match('/(\d{3,4})\s*km\s+((?:East|West|North|South|NE|NW|SE|SW)[a-z\-\s]*)\s+of/i', $text, $distMatch);

    if (empty($nameMatches[0])) return null;

    $typhoons = [];
    $seen = [];
    foreach (array_unique($nameMatches[0]) as $fullName) {
        $justName = preg_replace('/(?:Super\s+)?(?:Typhoon|Severe\s+Tropical\s+Storm|Tropical\s+Storm|Tropical\s+Depression)\s+/i', '', $fullName);
        $justName = trim($justName);
        if (in_array($justName, $seen) || strlen($justName) < 2) continue;
        $seen[] = $justName;

        $windSpeed = isset($windMatch[1]) ? intval($windMatch[1]) : 0;
        $lat = isset($posMatch[1]) ? floatval($posMatch[1]) : 12.88;
        $lon = isset($posMatch[2]) ? floatval($posMatch[2]) : 121.77;
        $distance = isset($distMatch[1]) ? intval($distMatch[1]) : intval(haversineKm(12.8797, 121.7740, $lat, $lon));

        $typhoons[] = [
            'name'      => strtoupper($justName),
            'category'  => windToCategory($windSpeed),
            'windSpeed' => $windSpeed,
            'lat'       => $lat,
            'lon'       => $lon,
            'distance'  => $distance,
            'direction' => isset($distMatch[2]) ? ucwords(strtolower(trim($distMatch[2]))) : null,
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


// ═════════════════════════════════════════════════════════════════════════════
// SOURCE 4: GDACS — Global Disaster Alert and Coordination System
// Public JSON API, no auth required, reliable international source
// ═════════════════════════════════════════════════════════════════════════════
function fetchGDACS() {
    $url = 'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH'
         . '?eventtype=TC&alertlevel=Orange;Red'
         . '&fromDate=' . date('Y-m-d', strtotime('-7 days'))
         . '&toDate='   . date('Y-m-d', strtotime('+1 day'));

    $ctx = makeContext(10);
    $json = @file_get_contents($url, false, $ctx);
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

        // Filter to Western Pacific / PAR region
        if ($lon !== null && ($lon < 100 || $lon > 180)) continue;
        if ($lat !== null && ($lat < 0   || $lat > 40))  continue;

        $name     = strtoupper($p['name'] ?? $p['eventname'] ?? 'UNNAMED');
        // GDACS reports wind in knots; convert to km/h
        $windKts  = floatval($p['maxwind'] ?? $p['wind_max'] ?? 0);
        $windKmh  = $windKts > 0 ? intval($windKts * 1.852) : intval($p['windspeed'] ?? 0);
        $distance = ($lat && $lon) ? intval(haversineKm(12.8797, 121.7740, $lat, $lon)) : 999;

        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat ? floatval($lat) : 12.88,
            'lon'       => $lon ? floatval($lon) : 121.77,
            'distance'  => $distance,
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


// ═════════════════════════════════════════════════════════════════════════════
// SOURCE 5: JMA — Japan Meteorological Agency RSS
// Covers the Western Pacific basin including PAR
// ═════════════════════════════════════════════════════════════════════════════
function fetchJMA() {
    $urls = [
        'https://www.jma.go.jp/bosai/typhoon/data/nowcast.json',
        'https://www.jma.go.jp/en/typh/index.html',
    ];

    $ctx = makeContext(8);

    // Try JMA nowcast JSON
    $json = @file_get_contents($urls[0], false, $ctx);
    if ($json) {
        $data = json_decode($json, true);
        if ($data && is_array($data)) {
            $typhoons = parseJMAJson($data);
            if ($typhoons !== null) {
                return [
                    'success'    => true,
                    'typhoons'   => $typhoons,
                    'bulletin'   => null,
                    'source'     => 'JMA',
                    'fetched_at' => date('c'),
                ];
            }
        }
    }

    // Try JMA HTML
    $html = @file_get_contents($urls[1], false, $ctx);
    if (!$html) return null;

    // Check "No typhoon"
    if (stripos($html, 'No typhoon') !== false || stripos($html, 'no tropical') !== false) {
        return [
            'success'    => true,
            'typhoons'   => [],
            'bulletin'   => 'No typhoons in the Western Pacific according to JMA.',
            'source'     => 'JMA',
            'fetched_at' => date('c'),
        ];
    }

    // Extract from JMA HTML
    preg_match_all(
        '/(?:Typhoon|TS|STS|TD)\s+(?:No\.\s*\d+\s+)?(?:INTERNATIONAL:\s*)?([A-Z]{2,20})/i',
        $html, $nameMatches
    );
    preg_match_all('/(\d{1,2}\.?\d)\s*N[,\s]+(\d{2,3}\.?\d)\s*E/', $html, $posMatches);
    preg_match_all('/(\d{2,3})\s*kt/', $html, $windMatches);

    if (empty($nameMatches[1])) return null;

    $typhoons = [];
    foreach ($nameMatches[1] as $i => $name) {
        $lat = isset($posMatches[1][$i]) ? floatval($posMatches[1][$i]) : 12.88;
        $lon = isset($posMatches[2][$i]) ? floatval($posMatches[2][$i]) : 135.0;

        // Only include if in PAR vicinity
        if ($lon < 100 || $lon > 180) continue;

        $windKts  = isset($windMatches[1][$i]) ? intval($windMatches[1][$i]) : 40;
        $windKmh  = intval($windKts * 1.852);
        $distance = intval(haversineKm(12.8797, 121.7740, $lat, $lon));

        $typhoons[] = [
            'name'      => strtoupper($name),
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat,
            'lon'       => $lon,
            'distance'  => $distance,
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

function parseJMAJson($data) {
    // JMA nowcast JSON structure varies; attempt common keys
    $systems = $data['forecast'] ?? $data['typhoon'] ?? $data['typhoons'] ?? [];
    if (empty($systems)) return null;

    $typhoons = [];
    foreach ((array)$systems as $sys) {
        $lat  = floatval($sys['lat'] ?? $sys['latitude'] ?? 0);
        $lon  = floatval($sys['lon'] ?? $sys['longitude'] ?? 0);
        if ($lon < 100 || $lon > 180 || $lat < 0 || $lat > 40) continue;

        $windKts = floatval($sys['wind'] ?? $sys['max_wind'] ?? $sys['winds'] ?? 40);
        $windKmh = intval($windKts * 1.852);
        $name    = strtoupper($sys['name'] ?? $sys['id'] ?? 'UNNAMED');

        $typhoons[] = [
            'name'      => $name,
            'category'  => windToCategory($windKmh),
            'windSpeed' => $windKmh,
            'lat'       => $lat,
            'lon'       => $lon,
            'distance'  => intval(haversineKm(12.8797, 121.7740, $lat, $lon)),
            'direction' => null,
            'signal'    => windToSignal($windKmh),
            'source'    => 'JMA',
        ];
    }

    return empty($typhoons) ? null : $typhoons;
}


// ═════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════════════════════

function makeContext($timeout = 12) {
    return stream_context_create([
        'http' => [
            'timeout'       => $timeout,
            'ignore_errors' => true,
            'header'        => implode("\r\n", [
                'User-Agent: Mozilla/5.0 (compatible; PhilippineTyphoonTracker/2.0)',
                'Accept: application/json,text/html,application/xhtml+xml,*/*',
                'Accept-Language: en-US,en;q=0.9',
                'Cache-Control: no-cache',
            ]),
        ],
        'ssl'  => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ],
    ]);
}

function haversineKm($lat1, $lon1, $lat2, $lon2) {
    $R    = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a    = sin($dLat/2) ** 2
          + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) ** 2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
    $estimates = [5 => 185, 4 => 130, 3 => 100, 2 => 75, 1 => 50];
    return $estimates[$signal] ?? 0;
}