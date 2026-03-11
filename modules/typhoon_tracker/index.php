<?php
/**
 * ENHANCED AI Disaster Safety Assistant v5.0
 * Self-contained: no external JS dependencies
 * Fixed modal • Inline ML • Inline map • Inline weather fetch
 */

// ── CONFIG LOADER ──────────────────────────────────────────
function findConfig() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $dir = dirname(__FILE__);
    for ($i = 0; $i < 6; $i++) {
        $f = $dir . '/config.ini';
        if (file_exists($f)) { $cfg = parse_ini_file($f); return $cfg; }
        $dir = dirname($dir);
    }
    $cfg = []; return $cfg;
}

// ── AJAX: chat ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['CONTENT_TYPE']) &&
    strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {

    header('Content-Type: application/json');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data || !isset($data['message'])) {
        echo json_encode(['success'=>false,'error'=>'Invalid request']); exit();
    }

    $userMessage     = trim($data['message']);
    $weatherData     = $data['weatherData']    ?? null;
    $typhoonData     = $data['typhoonData']     ?? [];
    $userLocation    = $data['userLocation']    ?? 'Philippines';
    $currentDateTime = $data['currentDateTime'] ?? null;
    $forecastData    = $data['forecastData']    ?? null;
    $sessionId       = $data['session_id']      ?? ($data['resident_id'] ?? 'anonymous');
    if (!empty($data['resident_id'])) $sessionId = 'resident_' . $data['resident_id'];

    $config       = findConfig();
    $GROQ_API_KEY = $config['GROQ_API_KEY'] ?? $config['groq_api_key'] ?? '';

    $dbHistory  = loadDBHistory($sessionId);
    saveMessageToDB($sessionId, 'user', $userMessage, $weatherData, $typhoonData, $userLocation);
    $aiResponse = callGroqAPI($GROQ_API_KEY, $userMessage, $weatherData, $typhoonData,
                              $userLocation, $currentDateTime, $forecastData, $dbHistory);

    if ($aiResponse['success']) {
        $rt = $aiResponse['text'];
        saveMessageToDB($sessionId, 'assistant', $rt, $weatherData, $typhoonData, $userLocation);
        echo json_encode(['success'=>true,'response'=>$rt,'model'=>'llama-3.3-70b',
                          'session_id'=>$sessionId,'history_used'=>count($dbHistory)]);
    } else {
        $fb = getFallbackResponse($userMessage, $weatherData, $typhoonData, $forecastData);
        saveMessageToDB($sessionId, 'assistant', $fb, null, null, $userLocation);
        echo json_encode(['success'=>true,'response'=>$fb,'fallback'=>true,
                          'api_error'=>$aiResponse['error']??'Service unavailable','session_id'=>$sessionId]);
    }
    exit();
}

// ── DB HELPERS ─────────────────────────────────────────────
function getDB() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = findConfig();
    $h = $c['DB_HOST']??$c['db_host']??'localhost';
    $d = $c['DB_NAME']??$c['db_name']??'';
    $u = $c['DB_USER']??$c['db_user']??'';
    $p = $c['DB_PASS']??$c['db_pass']??$c['DB_PASSWORD']??'';
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$d;charset=utf8mb4",$u,$p);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE IF NOT EXISTS typhoon_chat_history (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (PDOException $e) { return null; }
    return $pdo;
}
function loadDBHistory($sid, $pairs=15) {
    $pdo=getDB(); if(!$pdo)return[];
    $lim=$pairs*2;
    $st=$pdo->prepare("SELECT role,content FROM typhoon_chat_history WHERE session_id=:s ORDER BY created_at DESC LIMIT :l");
    $st->bindValue(':s',$sid,PDO::PARAM_STR); $st->bindValue(':l',$lim,PDO::PARAM_INT); $st->execute();
    $rows=array_reverse($st->fetchAll(PDO::FETCH_ASSOC));
    $h=[]; foreach($rows as $r) $h[]=['role'=>$r['role'],'content'=>$r['content']]; return $h;
}
function saveMessageToDB($sid,$role,$content,$wd=null,$td=null,$loc=null) {
    $pdo=getDB(); if(!$pdo)return;
    $rid=null; if(preg_match('/^resident_(\d+)$/',$sid,$m))$rid=(int)$m[1];
    $st=$pdo->prepare("INSERT INTO typhoon_chat_history (session_id,resident_id,role,content,weather_context,typhoon_context,location_context) VALUES (:s,:r,:ro,:c,:w,:t,:l)");
    $st->execute([':s'=>$sid,':r'=>$rid,':ro'=>$role,':c'=>$content,':w'=>$wd?json_encode($wd):null,':t'=>$td?json_encode($td):null,':l'=>$loc]);
}
function callGroqAPI($apiKey,$msg,$wd,$td,$loc,$dt=null,$fd=null,$hist=[]) {
    if(empty($apiKey))return['success'=>false,'error'=>'API key not configured'];
    $ctx ="You are an advanced AI weather assistant specializing in Philippine tropical weather and disaster preparedness.\n";
    $ctx.="User location: {$loc}.\n";
    if($dt)$ctx.="Current date/time: {$dt}\n";
    $ctx.="\nBe conversational, empathetic, safety-focused. Reference past conversations naturally.\n\n";
    if($wd){
        $ctx.="=== CURRENT WEATHER ===\nWind:{$wd['windSpeed']}km/h|Temp:{$wd['temperature']}°C|Pressure:{$wd['pressure']}hPa|Humidity:{$wd['humidity']}%\n";
        if($wd['humidity']>=95)$ctx.="🌧️ CRITICAL HUMIDITY\n";
        if($wd['pressure']<1005)$ctx.="📉 CRITICAL PRESSURE\n";
        if($wd['windSpeed']>118)$ctx.="🌪️ TYPHOON-FORCE WINDS (Signal #4+)\n";
        elseif($wd['windSpeed']>88)$ctx.="⚠️ STORM-FORCE WINDS (Signal #3)\n";
        elseif($wd['windSpeed']>62)$ctx.="⚠️ STRONG WINDS (Signal #2)\n";
        $ctx.="\n";
    }
    if(!empty($td)){
        $ctx.="=== ACTIVE TYPHOONS ===\n";
        foreach($td as $i=>$t){
            $ctx.=($i+1).". {$t['name']} — Wind:{$t['windSpeed']}km/h, Distance:{$t['distance']}km\n";
            if($t['distance']<300)$ctx.="   ⚠️ IMMEDIATE DANGER\n";
            elseif($t['distance']<600)$ctx.="   ⚠️ HIGH ALERT\n";
        }
    }
    $ctx.="=== PAGASA SIGNALS ===\n#1:39-61|#2:62-88|#3:89-117|#4:118-184|#5:185+\n";
    $ctx.="=== EMERGENCY ===\nNDRRMC:911|PAGASA:(02)8284-0800|RedCross:143\n";
    $msgs=[['role'=>'system','content'=>$ctx]];
    foreach($hist as $h)$msgs[]=['role'=>$h['role'],'content'=>$h['content']];
    $msgs[]=['role'=>'user','content'=>$msg];
    $payload=json_encode(['model'=>'llama-3.3-70b-versatile','messages'=>$msgs,'temperature'=>0.7,'max_tokens'=>800,'top_p'=>0.9]);
    $ch=curl_init("https://api.groq.com/openai/v1/chat/completions");
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30]);
    $resp=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
    if($code===200&&$resp){
        $r=json_decode($resp,true);
        if(isset($r['choices'][0]['message']['content']))return['success'=>true,'text'=>trim($r['choices'][0]['message']['content'])];
    }
    $em='AI service error';
    if($resp){$r=json_decode($resp,true);if(isset($r['error']['message'])){$ae=$r['error']['message'];$em=(strpos($ae,'Rate limit')!==false||strpos($ae,'TPD')!==false)?'Rate limit. Wait a few minutes.':$ae;}}
    elseif($err)$em='Connection error: '.$err;
    return['success'=>false,'error'=>$em];
}
function getFallbackResponse($msg,$wd,$td,$fd) {
    $ml=strtolower($msg);
    if($wd&&(strpos($ml,'weather')!==false||strpos($ml,'current')!==false)){
        $h=floatval($wd['humidity']);$p=floatval($wd['pressure']);$w=floatval($wd['windSpeed']);
        $r="📊 Now: {$wd['humidity']}% humidity · {$wd['pressure']} hPa · {$wd['windSpeed']} km/h · {$wd['temperature']}°C. ";
        if($h>=95&&$p<1010)$r.="🌧️ Critical: heavy rain forming.";
        elseif($h>=90&&$p<1010)$r.="⚠️ Heavy rain likely.";
        elseif($w>60)$r.="💨 Strong winds — secure loose objects.";
        else $r.="✓ Conditions within normal range."; return $r;
    }
    if(strpos($ml,'safe')!==false&&!empty($td)){$t=$td[0];return $t['distance']<300?"⚠️ ALERT: Typhoon {$t['name']} is {$t['distance']}km away. Follow evacuation orders. Call 911.":"Monitoring Typhoon {$t['name']} at {$t['distance']}km. Prepare your emergency kit.";}
    return "I'm here to help with weather and safety. Ask about current conditions, typhoon threats, or emergency preparedness. NDRRMC: 911 | PAGASA: (02) 8284-0800";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Typhoon Tracker — Barangay Safety</title>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root {
    --navy:#0d1b36; --navy-mid:#152849; --navy-light:#1c3461;
    --amber:#f59e0b; --amber-light:#fef3c7; --amber-dark:#b45309;
    --teal:#0d9488; --teal-light:#ccfbf1;
    --rose:#e11d48; --rose-light:#ffe4e6;
    --sky:#0ea5e9; --sky-light:#e0f2fe;
    --indigo:#6366f1; --indigo-light:#e0e7ff;
    --success:#10b981; --success-light:#d1fae5;
    --danger:#ef4444; --danger-light:#fee2e2;
    --info:#3b82f6; --info-light:#dbeafe;
    --warning:#f59e0b; --warning-light:#fef3c7;
    --bg:#eef2f7; --surf:#fff; --surf2:#f8fafc;
    --border:#e2e8f0; --text:#0f172a; --muted:#64748b;
    --r:14px; --rsm:8px; --rlg:20px;
    --shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html,body{height:100%;}
body{font-family:'Sora',sans-serif;background:var(--bg);color:var(--text);font-size:13.5px;line-height:1.6;}

/* ── HERO ── */
.tt-hero{background:linear-gradient(135deg,var(--navy) 0%,var(--navy-light) 65%,#1a3a8a 100%);padding:22px 32px;position:relative;overflow:hidden;}
.tt-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.tt-hero__ring--1{width:340px;height:340px;top:-150px;right:-90px;}
.tt-hero__ring--2{width:200px;height:200px;top:-60px;right:70px;border-color:rgba(245,158,11,.12);}
.tt-hero__ring--3{width:110px;height:110px;bottom:-40px;left:30%;border-color:rgba(14,165,233,.12);}
.tt-hero__inner{position:relative;z-index:1;display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:16px;max-width:1400px;margin:0 auto;}
@media(max-width:768px){.tt-hero__inner{grid-template-columns:1fr;justify-items:center;}}
.tt-hero__left{display:flex;align-items:center;gap:14px;}
.tt-hero__icon{width:48px;height:48px;border-radius:13px;background:linear-gradient(135deg,var(--sky),#0284c7);box-shadow:0 4px 14px rgba(14,165,233,.4);display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff;flex-shrink:0;animation:tt-spin 12s linear infinite;}
@keyframes tt-spin{to{transform:rotate(360deg);}}
.tt-hero__title{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:2px;}
.tt-hero__sub{font-size:12px;color:rgba(255,255,255,.55);}
.tt-hero__right{display:flex;align-items:center;justify-content:flex-end;gap:10px;}
.tt-datetime{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);border-radius:10px;padding:10px 18px;text-align:center;backdrop-filter:blur(10px);}
.tt-datetime__label{font-size:9px;color:rgba(255,255,255,.5);font-family:'DM Mono',monospace;text-transform:uppercase;letter-spacing:.8px;margin-bottom:3px;}
.tt-datetime__val{font-family:'DM Mono',monospace;font-size:14px;font-weight:500;color:#fff;letter-spacing:.6px;}
.tt-back-btn{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.18);border-radius:var(--rsm);color:#fff;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;text-decoration:none;transition:background .18s;}
.tt-back-btn:hover{background:rgba(255,255,255,.2);color:#fff;text-decoration:none;}

/* ── LAYOUT ── */
.tt-container{max-width:1400px;margin:0 auto;padding:22px 22px 40px;}
.tt-grid{display:grid;grid-template-columns:1fr 380px;gap:18px;align-items:start;}
@media(max-width:1100px){.tt-grid{grid-template-columns:1fr;}}

/* ── PANEL ── */
.tt-panel{background:var(--surf);border-radius:var(--rlg);border:1px solid var(--border);box-shadow:var(--shadow);margin-bottom:18px;overflow:hidden;animation:fadeUp .35s ease both;}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.tt-panel__header{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;border-bottom:1px solid var(--border);gap:10px;flex-wrap:wrap;}
.tt-panel__title{display:flex;align-items:center;gap:10px;}
.tt-panel__title h2{font-size:13.5px;font-weight:700;letter-spacing:-.2px;margin:0;}
.tt-panel__icon{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.tt-panel__icon--sky{background:var(--sky-light);color:var(--sky);}
.tt-panel__icon--amber{background:var(--amber-light);color:var(--amber-dark);}
.tt-panel__icon--rose{background:var(--rose-light);color:var(--rose);}
.tt-panel__icon--teal{background:var(--teal-light);color:var(--teal);}
.tt-panel__icon--indigo{background:var(--indigo-light);color:var(--indigo);}
.tt-panel__icon--success{background:var(--success-light);color:var(--success);}

/* ── WEATHER CARDS ── */
.tt-weather-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;padding:16px 20px;}
@media(max-width:700px){.tt-weather-grid{grid-template-columns:repeat(2,1fr);}}
.tt-wc{border-radius:var(--r);padding:15px;display:flex;flex-direction:column;gap:9px;border:1px solid transparent;transition:transform .2s,box-shadow .2s;}
.tt-wc:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg);}
.tt-wc--wind{background:linear-gradient(135deg,#eff6ff,#dbeafe);border-color:#bfdbfe;}
.tt-wc--temp{background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;}
.tt-wc--pres{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#bbf7d0;}
.tt-wc--humi{background:linear-gradient(135deg,#f0f9ff,#e0f2fe);border-color:#bae6fd;}
.tt-wc__icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.tt-wc--wind .tt-wc__icon{background:rgba(59,130,246,.15);color:var(--info);}
.tt-wc--temp .tt-wc__icon{background:rgba(245,158,11,.15);color:var(--amber-dark);}
.tt-wc--pres .tt-wc__icon{background:rgba(16,185,129,.15);color:var(--teal);}
.tt-wc--humi .tt-wc__icon{background:rgba(14,165,233,.15);color:var(--sky);}
.tt-wc__val{font-size:24px;font-weight:800;line-height:1;letter-spacing:-1px;color:var(--text);}
.tt-wc__unit{font-size:11px;font-weight:500;color:var(--muted);margin-left:2px;}
.tt-wc__label{font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;}
.tt-wc__status{font-size:10px;font-weight:600;padding:2px 7px;border-radius:10px;display:inline-block;margin-top:1px;}
.tt-wc__spark{height:3px;border-radius:2px;opacity:.4;margin-top:1px;}
.tt-wc--wind .tt-wc__spark{background:linear-gradient(90deg,var(--info),transparent);}
.tt-wc--temp .tt-wc__spark{background:linear-gradient(90deg,var(--amber),transparent);}
.tt-wc--pres .tt-wc__spark{background:linear-gradient(90deg,var(--teal),transparent);}
.tt-wc--humi .tt-wc__spark{background:linear-gradient(90deg,var(--sky),transparent);}
.tt-location{display:flex;align-items:center;gap:6px;padding:6px 20px 12px;font-size:11px;color:var(--muted);font-family:'DM Mono',monospace;}
.tt-location i{color:var(--rose);}

/* ── BADGE ── */
.tt-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.tt-badge--danger{background:var(--danger-light);color:#7f1d1d;}
.tt-badge--warning{background:var(--warning-light);color:#92400e;}
.tt-badge--info{background:var(--info-light);color:#1e40af;}
.tt-badge--success{background:var(--success-light);color:#065f46;}
.tt-badge--muted{background:var(--surf2);color:var(--muted);border:1px solid var(--border);}
.tt-badge--pulse{animation:pulse 1.5s ease infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.5}}

/* ── ML PANEL ── */
.ml-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;padding:16px 20px;}
@media(max-width:700px){.ml-grid{grid-template-columns:1fr 1fr;}}
.ml-card{border-radius:12px;padding:14px;border:1px solid var(--border);background:var(--surf2);transition:transform .2s,box-shadow .2s;}
.ml-card:hover{transform:translateY(-2px);box-shadow:var(--shadow);}
.ml-card__top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;}
.ml-card__label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);}
.ml-card__icon{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;}
.ml-card__val{font-size:22px;font-weight:800;letter-spacing:-1px;color:var(--text);line-height:1;}
.ml-card__unit{font-size:11px;color:var(--muted);margin-left:2px;}
.ml-card__sub{font-size:10px;color:var(--muted);margin-top:3px;}
.ml-card__bar{height:4px;border-radius:2px;background:rgba(0,0,0,.08);overflow:hidden;margin-top:8px;}
.ml-card__bar-fill{height:100%;border-radius:2px;transition:width 1s cubic-bezier(.34,1.56,.64,1);}
.ml-confidence{padding:14px 20px 18px;display:flex;flex-direction:column;gap:8px;}
.ml-conf-row{display:flex;align-items:center;gap:10px;}
.ml-conf-label{font-size:11px;font-weight:600;color:var(--text);min-width:140px;}
.ml-conf-bar{flex:1;height:7px;border-radius:4px;background:var(--border);overflow:hidden;}
.ml-conf-fill{height:100%;border-radius:4px;transition:width 1.2s cubic-bezier(.34,1.56,.64,1);}
.ml-conf-val{font-size:10px;font-family:'DM Mono',monospace;color:var(--muted);min-width:38px;text-align:right;}
.ml-alert{margin:0 20px 16px;padding:12px 14px;border-radius:10px;display:flex;gap:10px;align-items:flex-start;border:1px solid;}
.ml-alert--danger{background:var(--danger-light);border-color:#fca5a5;color:#7f1d1d;}
.ml-alert--warning{background:var(--warning-light);border-color:#fcd34d;color:#92400e;}
.ml-alert--success{background:var(--success-light);border-color:#6ee7b7;color:#065f46;}
.ml-alert--info{background:var(--info-light);border-color:#93c5fd;color:#1e40af;}
.ml-alert i{flex-shrink:0;margin-top:1px;}
.ml-alert__title{font-weight:700;font-size:11px;margin-bottom:2px;}
.ml-alert__text{font-size:11px;line-height:1.55;}

/* ── MAP ── */
#map{height:460px;width:100%;}

/* ── TYPHOON LIST ── */
.tt-typhoon-list{padding:10px 20px 16px;display:flex;flex-direction:column;gap:10px;}
.tt-typhoon-item{display:flex;border-radius:var(--r);overflow:hidden;border:1px solid var(--border);transition:transform .2s;}
.tt-typhoon-item:hover{transform:translateX(3px);}
.tt-typhoon-item__stripe{width:4px;flex-shrink:0;}
.tt-typhoon-item--danger .tt-typhoon-item__stripe{background:var(--danger);}
.tt-typhoon-item--warning .tt-typhoon-item__stripe{background:var(--amber);}
.tt-typhoon-item--info .tt-typhoon-item__stripe{background:var(--info);}
.tt-typhoon-item__body{flex:1;padding:11px 14px;}
.tt-typhoon-item--danger .tt-typhoon-item__body{background:#fff5f5;}
.tt-typhoon-item--warning .tt-typhoon-item__body{background:#fffbeb;}
.tt-typhoon-item--info .tt-typhoon-item__body{background:var(--surf2);}
.tt-typhoon-item__top{display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;}
.tt-typhoon-item__name{font-size:13px;font-weight:700;}
.tt-typhoon-item__meta{display:flex;flex-wrap:wrap;gap:8px;font-family:'DM Mono',monospace;font-size:10px;color:var(--muted);}

/* ── FORECAST STRIP ── */
.tt-forecast-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;padding:16px 20px;}
@media(max-width:900px){.tt-forecast-grid{grid-template-columns:repeat(4,1fr);}}
@media(max-width:500px){.tt-forecast-grid{grid-template-columns:repeat(2,1fr);}}
.tt-fc-day{background:var(--surf2);border:1px solid var(--border);border-radius:var(--r);padding:12px 8px;text-align:center;cursor:pointer;transition:transform .2s,box-shadow .2s,border-color .2s;}
.tt-fc-day:hover{transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--navy-light);}
.tt-fc-day.tt-fc-day--active{border-color:var(--navy-light);background:var(--indigo-light);}
.tt-fc-day__name{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:7px;}
.tt-fc-day__icon{font-size:22px;margin-bottom:7px;}
.tt-fc-day__temps{font-size:11px;font-weight:700;color:var(--text);margin-bottom:3px;}
.tt-fc-day__rain{font-size:10px;color:var(--sky);font-family:'DM Mono',monospace;}

/* ── REFRESH BTN ── */
.tt-refresh-btn{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;background:var(--surf2);border:1px solid var(--border);border-radius:var(--rsm);font-family:'Sora',sans-serif;font-size:11px;font-weight:600;color:var(--text);cursor:pointer;transition:all .15s;}
.tt-refresh-btn:hover{background:var(--navy);color:#fff;border-color:var(--navy);}
.tt-refresh-btn i{transition:transform .4s;}
.tt-refresh-btn:hover i{transform:rotate(180deg);}
.tt-refresh-btn.tt-refresh-btn--spinning i{animation:spinOnce 1.8s linear infinite;}
@keyframes spinOnce{to{transform:rotate(360deg);}}

/* ── EMPTY STATE ── */
.tt-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:32px 16px;text-align:center;gap:8px;}
.tt-empty i{font-size:30px;color:var(--border);}
.tt-empty p{font-size:12px;color:var(--muted);max-width:220px;}

/* ── TYPHOON SKELETON LOADER ── */
@keyframes shimmer {
    0%   { background-position: -600px 0; }
    100% { background-position: 600px 0; }
}
@keyframes skeletonFadeIn {
    from { opacity: 0; transform: translateX(-6px); }
    to   { opacity: 1; transform: translateX(0); }
}
.tt-skeleton-list { display: flex; flex-direction: column; gap: 10px; padding: 10px 20px 16px; }
.tt-skeleton-item {
    display: flex; border-radius: var(--r); overflow: hidden;
    border: 1px solid var(--border); background: var(--surf);
    opacity: 0;
    animation: skeletonFadeIn .3s ease forwards;
}
.tt-skeleton-item:nth-child(1) { animation-delay: 0s; }
.tt-skeleton-item:nth-child(2) { animation-delay: .1s; }
.tt-skeleton-item:nth-child(3) { animation-delay: .2s; }
.tt-skeleton-stripe {
    width: 4px; flex-shrink: 0;
    background: linear-gradient(90deg, var(--border) 25%, #e8edf2 50%, var(--border) 75%);
    background-size: 600px 100%;
    animation: shimmer 1.4s infinite linear;
}
.tt-skeleton-body { flex: 1; padding: 11px 14px; display: flex; flex-direction: column; gap: 8px; }
.tt-skeleton-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.tt-skeleton-row--meta { gap: 10px; justify-content: flex-start; }
.tt-skeleton-block {
    border-radius: 6px; height: 12px;
    background: linear-gradient(90deg, var(--border) 25%, #e8edf2 50%, var(--border) 75%);
    background-size: 600px 100%;
    animation: shimmer 1.4s infinite linear;
}
.tt-skeleton-block--name  { width: 38%; height: 14px; border-radius: 7px; }
.tt-skeleton-block--badge { width: 26%; height: 18px; border-radius: 20px; animation-delay: .1s; }
.tt-skeleton-block--meta  { width: 22%; height: 10px; border-radius: 5px; animation-delay: .15s; }

/* ── ALL CLEAR STATE ── */
@keyframes clearPulseRing {
    0%   { transform: scale(1);   opacity: .5; }
    100% { transform: scale(1.6); opacity: 0;  }
}
.tt-allclear {
    display: flex; flex-direction: column; align-items: center;
    padding: 28px 16px 24px; text-align: center;
    opacity: 0; transform: translateY(10px);
    transition: opacity .5s ease, transform .5s ease;
}
.tt-allclear.tt-allclear--visible {
    opacity: 1; transform: translateY(0);
}
.tt-allclear__ring-wrap {
    position: relative; width: 64px; height: 64px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 14px;
}
.tt-allclear__ring {
    position: absolute; inset: 0; border-radius: 50%;
    border: 2px solid var(--success); opacity: 0;
    animation: clearPulseRing 2s ease-out infinite;
}
.tt-allclear__ring:nth-child(2) { animation-delay: .6s; }
.tt-allclear__ring:nth-child(3) { animation-delay: 1.2s; }
.tt-allclear__icon-circle {
    width: 56px; height: 56px; border-radius: 50%;
    background: var(--success-light);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 0 0 6px rgba(16,185,129,.1);
    position: relative; z-index: 1;
}
.tt-allclear__icon-circle i {
    font-size: 24px; color: var(--success);
}
.tt-allclear__title {
    font-size: 14px; font-weight: 700; color: var(--text); margin-bottom: 4px;
}
.tt-allclear__desc {
    font-size: 11px; color: var(--muted); max-width: 210px; line-height: 1.6; margin-bottom: 10px;
}

/* ══════════════════════════════════════
   ENHANCED FORECAST MODAL
══════════════════════════════════════ */
.tt-modal{display:none;position:fixed;inset:0;background:rgba(9,18,38,.7);backdrop-filter:blur(8px);z-index:9999;align-items:center;justify-content:center;padding:16px;}
.tt-modal--open{display:flex !important;}
.tt-modal__box{background:var(--surf);border-radius:22px;width:100%;max-width:880px;max-height:94vh;overflow:hidden;box-shadow:0 32px 80px rgba(9,18,38,.35),0 4px 16px rgba(9,18,38,.12);display:flex;flex-direction:column;animation:modalIn .3s cubic-bezier(.34,1.56,.64,1) both;}
.tt-modal__box--sm{max-width:420px;}
@keyframes modalIn{from{opacity:0;transform:scale(.9) translateY(20px);}to{opacity:1;transform:scale(1) translateY(0);}}
/* Hero */
.tt-modal__hero{background:linear-gradient(135deg,var(--navy) 0%,#1e3a8a 55%,var(--navy-light) 100%);padding:26px 30px 22px;position:relative;overflow:hidden;flex-shrink:0;}
.tt-modal__hero-ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.07);pointer-events:none;}
.tt-modal__hero-ring--1{width:280px;height:280px;top:-130px;right:-50px;}
.tt-modal__hero-ring--2{width:160px;height:160px;top:-55px;right:80px;border-color:rgba(245,158,11,.14);}
.tt-modal__hero-ring--3{width:80px;height:80px;bottom:-25px;left:38%;border-color:rgba(14,165,233,.14);}
.tt-modal__hero-inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:14px;}
.tt-modal__hero-left{display:flex;align-items:center;gap:16px;}
.tt-modal__day-icon{width:64px;height:64px;border-radius:16px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:32px;backdrop-filter:blur(8px);flex-shrink:0;box-shadow:0 4px 18px rgba(0,0,0,.2);}
.tt-modal__day-name{font-size:20px;font-weight:800;color:#fff;letter-spacing:-.3px;margin-bottom:3px;}
.tt-modal__day-date{font-size:11px;color:rgba(255,255,255,.55);font-family:'DM Mono',monospace;}
.tt-modal__condition{display:inline-flex;align-items:center;gap:5px;margin-top:5px;padding:3px 10px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);border-radius:20px;font-size:10px;color:rgba(255,255,255,.85);font-weight:600;}
.tt-modal__hero-temps{text-align:right;}
.tt-modal__hero-temps .high{font-size:36px;font-weight:800;color:#fff;letter-spacing:-2px;line-height:1;}
.tt-modal__hero-temps .high .unit{font-size:16px;font-weight:400;opacity:.7;}
.tt-modal__hero-temps .low{font-size:15px;color:rgba(255,255,255,.5);font-weight:500;margin-top:3px;font-family:'DM Mono',monospace;}
.tt-modal__close-btn{position:absolute;top:14px;right:14px;width:30px;height:30px;background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:7px;color:rgba(255,255,255,.8);cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:background .15s;z-index:2;}
.tt-modal__close-btn:hover{background:rgba(255,255,255,.22);color:#fff;}
/* Two-column body */
.tt-modal__body{display:grid;grid-template-columns:1fr 1fr;gap:0;overflow-y:auto;flex:1;}
.tt-modal__body::-webkit-scrollbar{width:4px;}
.tt-modal__body::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
@media(max-width:620px){.tt-modal__body{grid-template-columns:1fr;}}
/* Details col */
.tt-modal__details{padding:22px 24px;border-right:1px solid var(--border);display:flex;flex-direction:column;gap:5px;}
.tt-modal__section-label{font-size:9px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);margin:9px 0 5px;display:flex;align-items:center;gap:6px;}
.tt-modal__section-label::after{content:'';flex:1;height:1px;background:var(--border);}
.tt-modal__section-label:first-child{margin-top:0;}
.tt-modal__detail-row{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:9px;background:var(--surf2);border:1px solid var(--border);transition:transform .15s,box-shadow .15s;}
.tt-modal__detail-row:hover{transform:translateX(3px);box-shadow:var(--shadow);}
.tt-modal__detail-icon{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0;}
.tt-modal__detail-text{flex:1;}
.tt-modal__detail-label{font-size:9px;color:var(--muted);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:1px;}
.tt-modal__detail-val{font-size:13px;font-weight:700;color:var(--text);}
.tt-modal__detail-sub{font-size:10px;color:var(--muted);margin-top:1px;}
/* Stats col */
.tt-modal__stats{padding:22px 24px;display:flex;flex-direction:column;gap:12px;}
.tt-stat-card{border-radius:11px;padding:14px;border:1px solid transparent;}
.tt-stat-card--rain{background:linear-gradient(135deg,#eff6ff,#dbeafe);border-color:#bfdbfe;}
.tt-stat-card--heat{background:linear-gradient(135deg,#fef3c7,#fde68a);border-color:#fcd34d;}
.tt-stat-card--comfort{background:linear-gradient(135deg,#f0fdf4,#dcfce7);border-color:#bbf7d0;}
.tt-stat-card__top{display:flex;align-items:center;justify-content:space-between;margin-bottom:7px;}
.tt-stat-card__label{font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:.7px;color:var(--muted);}
.tt-stat-card__icon{width:26px;height:26px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;}
.tt-stat-card--rain .tt-stat-card__icon{background:rgba(59,130,246,.15);color:var(--info);}
.tt-stat-card--heat .tt-stat-card__icon{background:rgba(245,158,11,.15);color:var(--amber-dark);}
.tt-stat-card--comfort .tt-stat-card__icon{background:rgba(16,185,129,.15);color:var(--teal);}
.tt-stat-card__val{font-size:24px;font-weight:800;letter-spacing:-1px;color:var(--text);line-height:1;}
.tt-stat-card__unit{font-size:11px;font-weight:500;color:var(--muted);margin-left:2px;}
.tt-stat-card__sub{font-size:10px;color:var(--muted);margin-top:3px;}
.tt-stat-bar{margin-top:9px;height:5px;border-radius:3px;background:rgba(0,0,0,.07);overflow:hidden;}
.tt-stat-bar__fill{height:100%;border-radius:3px;transition:width .9s cubic-bezier(.34,1.56,.64,1);width:0;}
.tt-stat-card--rain .tt-stat-bar__fill{background:linear-gradient(90deg,var(--info),#60a5fa);}
.tt-stat-card--heat .tt-stat-bar__fill{background:linear-gradient(90deg,var(--amber-dark),var(--amber));}
.tt-stat-card--comfort .tt-stat-bar__fill{background:linear-gradient(90deg,var(--teal),#34d399);}
.tt-modal__advice{border-radius:11px;padding:13px 14px;display:flex;gap:9px;align-items:flex-start;border:1px solid;}
.tt-modal__advice--safe{background:var(--success-light);border-color:#6ee7b7;color:#065f46;}
.tt-modal__advice--caution{background:var(--warning-light);border-color:#fcd34d;color:#92400e;}
.tt-modal__advice--danger{background:var(--danger-light);border-color:#fca5a5;color:#7f1d1d;}
.tt-modal__advice i{font-size:14px;flex-shrink:0;margin-top:1px;}
.tt-modal__advice-title{font-weight:700;font-size:11px;margin-bottom:2px;}
.tt-modal__advice-text{font-size:11px;line-height:1.6;font-weight:500;}
/* Footer */
.tt-modal__footer{border-top:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;justify-content:space-between;background:var(--surf2);flex-shrink:0;flex-wrap:wrap;gap:8px;}
.tt-modal__footer-meta{font-size:10px;color:var(--muted);display:flex;align-items:center;gap:5px;font-family:'DM Mono',monospace;}
.tt-modal__footer-btns{display:flex;gap:8px;}
.tt-modal__footer-btn{padding:7px 14px;border-radius:7px;font-size:11px;font-weight:600;font-family:'Sora',sans-serif;cursor:pointer;border:none;transition:all .15s;}
.tt-modal__footer-btn--ghost{background:var(--surf);border:1px solid var(--border);color:var(--text);}
.tt-modal__footer-btn--ghost:hover{background:var(--border);}
.tt-modal__footer-btn--primary{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;}
.tt-modal__footer-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(13,27,54,.3);}

/* ── CHAT ── */
.tt-chat-bubble{position:fixed;bottom:26px;right:26px;z-index:9000;}
.tt-chat-toggle{display:flex;align-items:center;gap:10px;padding:11px 18px;background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;border:none;border-radius:26px;font-family:'Sora',sans-serif;font-size:13px;font-weight:700;cursor:pointer;box-shadow:0 8px 28px rgba(13,27,54,.35);transition:transform .2s,box-shadow .2s;}
.tt-chat-toggle:hover{transform:translateY(-3px);box-shadow:0 12px 36px rgba(13,27,54,.45);}
.tt-chat-toggle__dot{width:8px;height:8px;border-radius:50%;background:var(--success);box-shadow:0 0 0 2px rgba(16,185,129,.3);animation:pulse 1.5s infinite;}
.tt-chat-window{position:absolute;bottom:calc(100% + 12px);right:0;width:420px;height:570px;background:var(--surf);border-radius:var(--rlg);border:1px solid var(--border);box-shadow:var(--shadow-lg);display:none;flex-direction:column;overflow:hidden;animation:fadeUp .28s cubic-bezier(.34,1.56,.64,1);}
.tt-chat-window--open{display:flex !important;}
@media(max-width:500px){.tt-chat-window{width:calc(100vw - 32px);right:-14px;}}
.tt-chat-head{background:linear-gradient(135deg,var(--navy),var(--navy-light));padding:14px 16px;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.tt-chat-head__title{font-size:13px;font-weight:700;color:#fff;margin-bottom:3px;}
.tt-chat-head__status{font-size:10px;color:rgba(255,255,255,.65);display:flex;align-items:center;gap:4px;}
.tt-chat-head__status-dot{width:5px;height:5px;border-radius:50%;background:var(--success);animation:pulse 1.5s infinite;}
.tt-chat-head__actions{display:flex;gap:5px;}
.tt-chat-head__btn{width:28px;height:28px;background:rgba(255,255,255,.1);border:none;color:rgba(255,255,255,.8);border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:background .15s;}
.tt-chat-head__btn:hover{background:rgba(255,255,255,.2);color:#fff;}
.tt-chat-msgs{flex:1;overflow-y:auto;padding:14px;background:var(--surf2);display:flex;flex-direction:column;gap:10px;}
.tt-chat-msgs::-webkit-scrollbar{width:4px;}
.tt-chat-msgs::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
.tt-msg{display:flex;gap:7px;align-items:flex-end;}
.tt-msg--user{flex-direction:row-reverse;}
.tt-msg__avatar{width:26px;height:26px;border-radius:7px;display:flex;align-items:center;justify-content:center;font-size:11px;flex-shrink:0;}
.tt-msg--bot .tt-msg__avatar{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;}
.tt-msg--user .tt-msg__avatar{background:linear-gradient(135deg,var(--amber),var(--amber-dark));color:#fff;}
.tt-msg__bubble{max-width:82%;padding:9px 13px;border-radius:13px;font-size:12px;line-height:1.65;}
.tt-msg--bot .tt-msg__bubble{background:var(--surf);border:1px solid var(--border);border-bottom-left-radius:3px;color:var(--text);}
.tt-msg--user .tt-msg__bubble{background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;border-bottom-right-radius:3px;}
.tt-msg__time{font-size:9px;color:var(--muted);font-family:'DM Mono',monospace;margin-top:3px;}
.tt-msg--user .tt-msg__time{text-align:right;}
.tt-typing{display:flex;gap:4px;padding:9px 13px;background:var(--surf);border:1px solid var(--border);border-radius:13px;border-bottom-left-radius:3px;width:fit-content;}
.tt-typing span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:bounce .9s ease infinite;}
.tt-typing span:nth-child(2){animation-delay:.15s;}
.tt-typing span:nth-child(3){animation-delay:.3s;}
@keyframes bounce{0%,100%{transform:translateY(0)}50%{transform:translateY(-5px)}}
.tt-quick-btns{padding:8px 12px 0;display:flex;gap:5px;flex-wrap:wrap;background:var(--surf2);border-top:1px solid var(--border);flex-shrink:0;}
.tt-quick-btn{padding:4px 10px;background:var(--surf);border:1px solid var(--border);border-radius:12px;font-family:'Sora',sans-serif;font-size:10px;font-weight:600;color:var(--text);cursor:pointer;white-space:nowrap;transition:all .15s;margin-bottom:7px;}
.tt-quick-btn:hover{background:var(--navy);color:#fff;border-color:var(--navy);}
.tt-chat-input{padding:10px 12px;border-top:1px solid var(--border);background:var(--surf);display:flex;gap:7px;align-items:center;flex-shrink:0;}
.tt-chat-input input{flex:1;padding:8px 13px;border:1.5px solid var(--border);border-radius:20px;font-family:'Sora',sans-serif;font-size:12px;color:var(--text);background:var(--surf2);outline:none;transition:border-color .18s,box-shadow .18s;}
.tt-chat-input input:focus{border-color:var(--navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);background:#fff;}
.tt-chat-input input::placeholder{color:#94a3b8;}
.tt-chat-send{width:36px;height:36px;background:linear-gradient(135deg,var(--navy),var(--navy-light));color:#fff;border:none;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:12px;transition:transform .15s,box-shadow .15s;flex-shrink:0;}
.tt-chat-send:hover{transform:scale(1.08);box-shadow:0 4px 12px rgba(13,27,54,.3);}
.tt-chat-send:disabled{opacity:.5;cursor:not-allowed;transform:none;}

/* ── UTILITY ── */
.tt-mono{font-family:'DM Mono',monospace;}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--surf2);}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:3px;}
::-webkit-scrollbar-thumb:hover{background:var(--muted);}
@media(max-width:700px){.tt-hero{padding:16px;}.tt-hero__title{font-size:17px;}.tt-container{padding:14px;}.tt-chat-bubble{bottom:14px;right:14px;}}
</style>
</head>
<body>

<!-- ── HERO ── -->
<div class="tt-hero">
    <div class="tt-hero__ring tt-hero__ring--1"></div>
    <div class="tt-hero__ring tt-hero__ring--2"></div>
    <div class="tt-hero__ring tt-hero__ring--3"></div>
    <div class="tt-hero__inner">
        <div class="tt-hero__left">
            <a href="../dashboard/index.php" class="tt-back-btn"><i class="fas fa-arrow-left"></i> Back</a>
            <div class="tt-hero__icon"><i class="fas fa-wind"></i></div>
            <div>
                <h1 class="tt-hero__title">Typhoon Tracker Philippines</h1>
                <p class="tt-hero__sub">Real-time monitoring · AI safety assistance · ML predictions</p>
            </div>
        </div>
        <div class="tt-datetime">
            <div class="tt-datetime__label">Current Time</div>
            <div class="tt-datetime__val" id="currentDateTime">—</div>
        </div>
        <div class="tt-hero__right"></div>
    </div>
</div>

<div class="tt-container">
<div class="tt-grid">

<!-- ════ LEFT COLUMN ════ -->
<div>

    <!-- WEATHER -->
    <div class="tt-panel" style="animation-delay:.05s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--sky"><i class="fas fa-cloud-sun"></i></span>
                <h2>Real-Time Weather</h2>
            </div>
            <span class="tt-badge tt-badge--muted tt-mono" id="lastUpdate">Updating…</span>
        </div>
        <div class="tt-weather-grid">
            <div class="tt-wc tt-wc--wind">
                <div class="tt-wc__icon"><i class="fas fa-wind"></i></div>
                <div>
                    <div class="tt-wc__label">Wind Speed</div>
                    <div class="tt-wc__val" id="windSpeed">—<span class="tt-wc__unit">km/h</span></div>
                    <div class="tt-wc__spark"></div>
                    <div class="tt-wc__status" id="windStatus">Checking…</div>
                </div>
            </div>
            <div class="tt-wc tt-wc--temp">
                <div class="tt-wc__icon"><i class="fas fa-thermometer-half"></i></div>
                <div>
                    <div class="tt-wc__label">Temperature</div>
                    <div class="tt-wc__val" id="temperature">—<span class="tt-wc__unit">°C</span></div>
                    <div class="tt-wc__spark"></div>
                    <div class="tt-wc__status" id="tempStatus">Checking…</div>
                </div>
            </div>
            <div class="tt-wc tt-wc--pres">
                <div class="tt-wc__icon"><i class="fas fa-tachometer-alt"></i></div>
                <div>
                    <div class="tt-wc__label">Pressure</div>
                    <div class="tt-wc__val" id="pressure">—<span class="tt-wc__unit">hPa</span></div>
                    <div class="tt-wc__spark"></div>
                    <div class="tt-wc__status" id="pressureStatus">Checking…</div>
                </div>
            </div>
            <div class="tt-wc tt-wc--humi">
                <div class="tt-wc__icon"><i class="fas fa-tint"></i></div>
                <div>
                    <div class="tt-wc__label">Humidity</div>
                    <div class="tt-wc__val" id="humidity">—<span class="tt-wc__unit">%</span></div>
                    <div class="tt-wc__spark"></div>
                    <div class="tt-wc__status" id="humidityStatus">Checking…</div>
                </div>
            </div>
        </div>
        <div class="tt-location"><i class="fas fa-map-marker-alt"></i><span id="userLocation">Detecting location…</span></div>
    </div>

    <!-- ML PREDICTIONS -->
    <div class="tt-panel" style="animation-delay:.1s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--indigo"><i class="fas fa-brain"></i></span>
                <h2>ML Weather Predictions</h2>
            </div>
            <span class="tt-badge tt-badge--muted tt-mono" id="mlLastUpdate">Waiting for data…</span>
        </div>
        <div class="ml-grid" id="mlGrid">
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Rain Probability</span>
                    <span class="ml-card__icon" style="background:var(--info-light);color:var(--info)"><i class="fas fa-umbrella"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlRainProb">—</span><span class="ml-card__unit">%</span></div>
                <div class="ml-card__sub" id="mlRainLabel">Awaiting data</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlRainBar" style="background:linear-gradient(90deg,var(--info),#60a5fa);width:0"></div></div>
            </div>
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Flood Risk</span>
                    <span class="ml-card__icon" style="background:var(--danger-light);color:var(--danger)"><i class="fas fa-water"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlFloodRisk">—</span><span class="ml-card__unit">%</span></div>
                <div class="ml-card__sub" id="mlFloodLabel">Awaiting data</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlFloodBar" style="background:linear-gradient(90deg,var(--danger),#f87171);width:0"></div></div>
            </div>
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Storm Severity</span>
                    <span class="ml-card__icon" style="background:var(--warning-light);color:var(--amber-dark)"><i class="fas fa-bolt"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlSeverity">—</span><span class="ml-card__unit">/10</span></div>
                <div class="ml-card__sub" id="mlSeverityLabel">Awaiting data</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlSeverityBar" style="background:linear-gradient(90deg,var(--amber-dark),var(--amber));width:0"></div></div>
            </div>
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Wind Forecast</span>
                    <span class="ml-card__icon" style="background:var(--sky-light);color:var(--sky)"><i class="fas fa-wind"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlWindForecast">—</span><span class="ml-card__unit">km/h</span></div>
                <div class="ml-card__sub" id="mlWindLabel">6-hour prediction</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlWindBar" style="background:linear-gradient(90deg,var(--sky),#38bdf8);width:0"></div></div>
            </div>
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Pressure Trend</span>
                    <span class="ml-card__icon" style="background:var(--teal-light);color:var(--teal)"><i class="fas fa-chart-line"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlPressureTrend">—</span><span class="ml-card__unit">hPa/h</span></div>
                <div class="ml-card__sub" id="mlPressureLabel">Rate of change</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlPressureBar" style="background:linear-gradient(90deg,var(--teal),#2dd4bf);width:0"></div></div>
            </div>
            <div class="ml-card">
                <div class="ml-card__top">
                    <span class="ml-card__label">Typhoon Likelihood</span>
                    <span class="ml-card__icon" style="background:var(--rose-light);color:var(--rose)"><i class="fas fa-circle-notch"></i></span>
                </div>
                <div><span class="ml-card__val" id="mlTyphoonLikelihood">—</span><span class="ml-card__unit">%</span></div>
                <div class="ml-card__sub" id="mlTyphoonLabel">Next 24h window</div>
                <div class="ml-card__bar"><div class="ml-card__bar-fill" id="mlTyphoonBar" style="background:linear-gradient(90deg,var(--rose),#fb7185);width:0"></div></div>
            </div>
        </div>
        <!-- Model confidence -->
        <div style="padding:0 20px 6px;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);display:flex;align-items:center;gap:6px">
            Model Confidence <span style="flex:1;height:1px;background:var(--border);display:block"></span>
        </div>
        <div class="ml-confidence" id="mlConfidence">
            <div class="ml-conf-row">
                <span class="ml-conf-label">Linear Regression (Rain)</span>
                <div class="ml-conf-bar"><div class="ml-conf-fill" id="confRain" style="background:linear-gradient(90deg,var(--info),#60a5fa);width:0"></div></div>
                <span class="ml-conf-val" id="confRainVal">—</span>
            </div>
            <div class="ml-conf-row">
                <span class="ml-conf-label">Decision Tree (Flood)</span>
                <div class="ml-conf-bar"><div class="ml-conf-fill" id="confFlood" style="background:linear-gradient(90deg,var(--danger),#f87171);width:0"></div></div>
                <span class="ml-conf-val" id="confFloodVal">—</span>
            </div>
            <div class="ml-conf-row">
                <span class="ml-conf-label">Neural Net (Typhoon)</span>
                <div class="ml-conf-bar"><div class="ml-conf-fill" id="confTyphoon" style="background:linear-gradient(90deg,var(--rose),#fb7185);width:0"></div></div>
                <span class="ml-conf-val" id="confTyphoonVal">—</span>
            </div>
            <div class="ml-conf-row">
                <span class="ml-conf-label">ARIMA (Wind)</span>
                <div class="ml-conf-bar"><div class="ml-conf-fill" id="confWind" style="background:linear-gradient(90deg,var(--sky),#38bdf8);width:0"></div></div>
                <span class="ml-conf-val" id="confWindVal">—</span>
            </div>
        </div>
        <div id="mlAlertBox" class="ml-alert ml-alert--info" style="display:none">
            <i class="fas fa-brain"></i>
            <div>
                <div class="ml-alert__title" id="mlAlertTitle">ML Analysis</div>
                <div class="ml-alert__text" id="mlAlertText">Running models…</div>
            </div>
        </div>
    </div>

    <!-- MAP -->
    <div class="tt-panel" style="animation-delay:.15s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--teal"><i class="fas fa-map"></i></span>
                <h2>Typhoon Map</h2>
            </div>
        </div>
        <div id="map"></div>
    </div>

    <!-- FORECAST -->
    <div class="tt-panel" style="animation-delay:.2s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--amber"><i class="fas fa-calendar-alt"></i></span>
                <h2>7-Day Weather Forecast</h2>
            </div>
        </div>
        <div class="tt-forecast-grid" id="forecastDays"></div>
    </div>

</div><!-- /left -->

<!-- ════ RIGHT SIDEBAR ════ -->
<div>

    <!-- TYPHOON LIST -->
    <div class="tt-panel" style="animation-delay:.08s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--rose"><i class="fas fa-wind"></i></span>
                <h2>Active Typhoons</h2>
            </div>
            <button class="tt-refresh-btn" id="refreshBtn" onclick="fetchTyphoons()"><i class="fas fa-sync-alt"></i> Refresh</button>
        </div>
        <div class="tt-typhoon-list" id="typhoonList">
            <div class="tt-empty"><i class="fas fa-search"></i><p>Scanning for active typhoons…</p></div>
        </div>
    </div>

    <!-- SIGNAL REFERENCE -->
    <div class="tt-panel" style="animation-delay:.12s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--indigo"><i class="fas fa-flag"></i></span>
                <h2>PAGASA Signal Reference</h2>
            </div>
        </div>
        <div style="padding:12px 20px 16px;display:flex;flex-direction:column;gap:7px">
            <?php
            $signals=[
                ['#1','39–61 km/h','tt-badge--success','Low threat — stay informed'],
                ['#2','62–88 km/h','tt-badge--warning','Moderate — prepare supplies'],
                ['#3','89–117 km/h','tt-badge--danger','High — consider evacuation'],
                ['#4','118–184 km/h','tt-badge--danger','Severe — evacuate now'],
                ['#5','185+ km/h','tt-badge--danger tt-badge--pulse','Catastrophic — evacuate immediately'],
            ];
            foreach($signals as [$sig,$speed,$cls,$note]):?>
            <div style="display:flex;align-items:center;gap:9px;padding:8px 11px;background:var(--surf2);border-radius:8px;border:1px solid var(--border)">
                <span class="tt-badge <?=$cls?>" style="flex-shrink:0">Signal <?=$sig?></span>
                <span class="tt-mono" style="font-size:10px;flex-shrink:0"><?=$speed?></span>
                <span style="font-size:11px;color:var(--muted)"><?=$note?></span>
            </div>
            <?php endforeach;?>
        </div>
    </div>

    <!-- EMERGENCY CONTACTS -->
    <div class="tt-panel" style="animation-delay:.16s">
        <div class="tt-panel__header">
            <div class="tt-panel__title">
                <span class="tt-panel__icon tt-panel__icon--rose"><i class="fas fa-phone-alt"></i></span>
                <h2>Emergency Contacts</h2>
            </div>
        </div>
        <div style="padding:12px 20px 16px;display:flex;flex-direction:column;gap:6px">
            <?php
            $contacts=[
                ['NDRRMC','911','fa-shield-alt','var(--rose)'],
                ['PAGASA','(02) 8284-0800','fa-cloud-sun','var(--sky)'],
                ['Red Cross','143','fa-first-aid','var(--danger)'],
                ['Fire Department','160','fa-fire-extinguisher','var(--amber-dark)'],
            ];
            foreach($contacts as [$label,$num,$ico,$color]):?>
            <a href="tel:<?=preg_replace('/[^0-9+]/','',$num)?>" style="display:flex;align-items:center;gap:9px;padding:9px 11px;background:var(--surf2);border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text);transition:transform .15s" onmouseover="this.style.transform='translateX(3px)'" onmouseout="this.style.transform=''">
                <div style="width:30px;height:30px;border-radius:8px;background:<?=$color?>1a;color:<?=$color?>;display:flex;align-items:center;justify-content:center;font-size:12px;flex-shrink:0"><i class="fas <?=$ico?>"></i></div>
                <div style="flex:1">
                    <div style="font-size:10px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.5px"><?=$label?></div>
                    <div class="tt-mono" style="font-size:12px;font-weight:600"><?=$num?></div>
                </div>
                <i class="fas fa-chevron-right" style="font-size:10px;color:var(--border)"></i>
            </a>
            <?php endforeach;?>
        </div>
        <!-- ── TYPHOON HISTORY BUTTON ── -->
<button onclick="openTyphoonHistoryModal()"
    style="display:flex;align-items:center;gap:9px;padding:9px 11px;
           background:linear-gradient(135deg,#1e1b4b,var(--navy-light));
           border:1px solid rgba(99,102,241,.35);border-radius:8px;
           color:#fff;cursor:pointer;width:100%;
           font-family:'Sora',sans-serif;
           transition:transform .15s,box-shadow .15s;margin-top:4px"
    onmouseover="this.style.transform='translateX(3px)';this.style.boxShadow='0 4px 14px rgba(99,102,241,.3)'"
    onmouseout="this.style.transform='';this.style.boxShadow=''">
    <div style="width:30px;height:30px;border-radius:8px;
                background:rgba(99,102,241,.2);color:#a5b4fc;
                display:flex;align-items:center;justify-content:center;
                font-size:12px;flex-shrink:0">
        <i class="fas fa-book-open"></i>
    </div>
    <div style="flex:1;text-align:left">
        <div style="font-size:10px;font-weight:600;color:rgba(255,255,255,.6);
                    text-transform:uppercase;letter-spacing:.5px">Reference</div>
        <div style="font-size:12px;font-weight:600">PH Typhoon History</div>
    </div>
    <i class="fas fa-chevron-right" style="font-size:10px;color:rgba(255,255,255,.3)"></i>
</button>
    </div>

</div><!-- /right -->
</div><!-- /tt-grid -->
</div><!-- /tt-container -->


<!-- ════ CHAT BUBBLE ════ -->
<div class="tt-chat-bubble">
    <button class="tt-chat-toggle" onclick="toggleChat()">
        <div class="tt-chat-toggle__dot"></div>
        <i class="fas fa-robot"></i>
        <span>AI Safety Assistant</span>
    </button>
    <div class="tt-chat-window" id="chatBubbleWindow">
        <div class="tt-chat-head">
            <div>
                <div class="tt-chat-head__title"><i class="fas fa-robot" style="margin-right:6px;opacity:.8"></i>AI Safety Assistant</div>
                <div class="tt-chat-head__status"><span class="tt-chat-head__status-dot"></span><span id="chatStatus">Online &amp; Ready</span></div>
            </div>
            <div class="tt-chat-head__actions">
                <button class="tt-chat-head__btn" onclick="clearChatHistory()" title="Clear"><i class="fas fa-trash"></i></button>
                <button class="tt-chat-head__btn" onclick="toggleChat()" title="Close"><i class="fas fa-times"></i></button>
            </div>
        </div>
        <div class="tt-chat-msgs" id="chatContainer"></div>
        <div class="tt-quick-btns">
            <button class="tt-quick-btn" onclick="askQuestion('Am I safe from typhoons?')">Am I safe?</button>
            <button class="tt-quick-btn" onclick="askQuestion('What should be in my emergency kit?')">Emergency kit</button>
            <button class="tt-quick-btn" onclick="askQuestion('Should I evacuate now?')">Evacuate?</button>
            <button class="tt-quick-btn" onclick="askQuestion('Explain the current ML predictions')"><i class="fas fa-brain"></i> ML analysis</button>
        </div>
        <div class="tt-chat-input">
            <input type="text" id="messageInput" placeholder="Ask about typhoons, safety, weather…" autocomplete="off" onkeypress="if(event.key==='Enter')sendMessage()">
            <button class="tt-chat-send" id="sendBtn" onclick="sendMessage()"><i class="fas fa-paper-plane"></i></button>
        </div>
    </div>
</div>


<!-- ════ ENHANCED FORECAST MODAL ════ -->
<div id="forecastModal" class="tt-modal" onclick="if(event.target===this)closeForecastModal()">
    <div class="tt-modal__box">
        <div class="tt-modal__hero">
            <div class="tt-modal__hero-ring tt-modal__hero-ring--1"></div>
            <div class="tt-modal__hero-ring tt-modal__hero-ring--2"></div>
            <div class="tt-modal__hero-ring tt-modal__hero-ring--3"></div>
            <button class="tt-modal__close-btn" onclick="closeForecastModal()"><i class="fas fa-times"></i></button>
            <div class="tt-modal__hero-inner">
                <div class="tt-modal__hero-left">
                    <div class="tt-modal__day-icon" id="modalDayIcon">⛅</div>
                    <div>
                        <div class="tt-modal__day-name" id="modalDayName">Day</div>
                        <div class="tt-modal__day-date" id="modalDayDate"></div>
                        <div class="tt-modal__condition"><i class="fas fa-cloud-sun" id="modalCondIcon"></i><span id="modalConditionText">Partly Cloudy</span></div>
                    </div>
                </div>
                <div class="tt-modal__hero-temps">
                    <div class="high" id="modalTempHigh">—<span class="unit">°C</span></div>
                    <div class="low" id="modalTempLow">/ —°C</div>
                </div>
            </div>
        </div>
        <div class="tt-modal__body">
            <!-- LEFT: details -->
            <div class="tt-modal__details">
                <div class="tt-modal__section-label">Precipitation</div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:var(--sky-light);color:var(--sky)"><i class="fas fa-tint"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Rain Probability</div>
                        <div class="tt-modal__detail-val" id="detailRainProb">—</div>
                        <div class="tt-modal__detail-sub" id="detailRainProbSub">—</div>
                    </div>
                </div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:var(--info-light);color:var(--info)"><i class="fas fa-cloud-rain"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Expected Rainfall</div>
                        <div class="tt-modal__detail-val" id="detailRainfall">—</div>
                        <div class="tt-modal__detail-sub" id="detailRainfallSub">—</div>
                    </div>
                </div>
                <div class="tt-modal__section-label">Atmosphere</div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:var(--amber-light);color:var(--amber-dark)"><i class="fas fa-thermometer-half"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Temperature Range</div>
                        <div class="tt-modal__detail-val" id="detailTempRange">—</div>
                        <div class="tt-modal__detail-sub" id="detailTempSub">—</div>
                    </div>
                </div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:var(--teal-light);color:var(--teal)"><i class="fas fa-water"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Humidity</div>
                        <div class="tt-modal__detail-val" id="detailHumidity">—</div>
                        <div class="tt-modal__detail-sub" id="detailHumiditySub">—</div>
                    </div>
                </div>
                <div class="tt-modal__section-label">Wind</div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:var(--indigo-light);color:var(--indigo)"><i class="fas fa-wind"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Wind Speed</div>
                        <div class="tt-modal__detail-val" id="detailWindSpeed">—</div>
                        <div class="tt-modal__detail-sub" id="detailWindDesc">—</div>
                    </div>
                </div>
                <div class="tt-modal__detail-row">
                    <div class="tt-modal__detail-icon" style="background:#fdf4ff;color:#7c3aed"><i class="fas fa-tachometer-alt"></i></div>
                    <div class="tt-modal__detail-text">
                        <div class="tt-modal__detail-label">Pressure</div>
                        <div class="tt-modal__detail-val" id="detailPressure">—</div>
                        <div class="tt-modal__detail-sub" id="detailPressureSub">—</div>
                    </div>
                </div>
            </div>
            <!-- RIGHT: stats -->
            <div class="tt-modal__stats">
                <div class="tt-modal__section-label" style="margin-top:0">Daily Statistics</div>
                <div class="tt-stat-card tt-stat-card--rain">
                    <div class="tt-stat-card__top">
                        <span class="tt-stat-card__label">Rain Risk</span>
                        <span class="tt-stat-card__icon"><i class="fas fa-umbrella"></i></span>
                    </div>
                    <div><span class="tt-stat-card__val" id="statRainProb">—</span><span class="tt-stat-card__unit">%</span></div>
                    <div class="tt-stat-card__sub" id="statRainDesc">—</div>
                    <div class="tt-stat-bar"><div class="tt-stat-bar__fill" id="statRainBar"></div></div>
                </div>
                <div class="tt-stat-card tt-stat-card--heat">
                    <div class="tt-stat-card__top">
                        <span class="tt-stat-card__label">Temperature High</span>
                        <span class="tt-stat-card__icon"><i class="fas fa-sun"></i></span>
                    </div>
                    <div><span class="tt-stat-card__val" id="statTempHigh">—</span><span class="tt-stat-card__unit">°C</span></div>
                    <div class="tt-stat-card__sub" id="statTempDesc">—</div>
                    <div class="tt-stat-bar"><div class="tt-stat-bar__fill" id="statTempBar"></div></div>
                </div>
                <div class="tt-stat-card tt-stat-card--comfort">
                    <div class="tt-stat-card__top">
                        <span class="tt-stat-card__label">Comfort Score</span>
                        <span class="tt-stat-card__icon"><i class="fas fa-smile"></i></span>
                    </div>
                    <div><span class="tt-stat-card__val" id="statComfort">—</span><span class="tt-stat-card__unit">/10</span></div>
                    <div class="tt-stat-card__sub" id="statComfortDesc">—</div>
                    <div class="tt-stat-bar"><div class="tt-stat-bar__fill" id="statComfortBar"></div></div>
                </div>
                <div class="tt-modal__advice tt-modal__advice--caution" id="weatherAdvice">
                    <i class="fas fa-exclamation-circle"></i>
                    <div>
                        <div class="tt-modal__advice-title" id="adviceTitle">Weather Advisory</div>
                        <div class="tt-modal__advice-text" id="adviceText">Loading forecast details…</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="tt-modal__footer">
            <div class="tt-modal__footer-meta"><i class="fas fa-satellite-dish"></i><span>Open-Meteo · PAGASA · ML Engine v2</span></div>
            <div class="tt-modal__footer-btns">
                <button class="tt-modal__footer-btn tt-modal__footer-btn--ghost" onclick="closeForecastModal()"><i class="fas fa-times" style="margin-right:4px"></i>Close</button>
                <button class="tt-modal__footer-btn tt-modal__footer-btn--primary" onclick="askAIAboutDay()"><i class="fas fa-robot" style="margin-right:4px"></i>Ask AI About This Day</button>
            </div>
        </div>
    </div>
</div>

<!-- ════ CLEAR CHAT MODAL ════ -->
<div id="clearChatModal" class="tt-modal" onclick="if(event.target===this)closeClearChatModal()">
    <div class="tt-modal__box tt-modal__box--sm">
        <div class="tt-modal__hero" style="background:linear-gradient(135deg,#7f1d1d,var(--danger));padding:20px 24px">
            <button class="tt-modal__close-btn" onclick="closeClearChatModal()"><i class="fas fa-times"></i></button>
            <div class="tt-modal__hero-inner">
                <div class="tt-modal__hero-left">
                    <div class="tt-modal__day-icon" style="font-size:20px;width:46px;height:46px"><i class="fas fa-trash" style="color:#fff"></i></div>
                    <div>
                        <div class="tt-modal__day-name" style="font-size:16px">Clear Chat History?</div>
                        <div class="tt-modal__day-date">This cannot be undone</div>
                    </div>
                </div>
            </div>
        </div>
        <div style="padding:20px">
            <p style="font-size:13px;color:var(--muted);margin-bottom:18px;line-height:1.7">This will permanently delete all conversation history from the database. The AI will no longer recall past conversations. <strong style="color:var(--danger)">This cannot be undone.</strong></p>
            <div style="display:flex;gap:9px">
                <button onclick="closeClearChatModal()" class="tt-modal__footer-btn tt-modal__footer-btn--ghost" style="flex:1;padding:9px">Cancel</button>
                <button onclick="confirmClearChat()" style="flex:1;padding:9px;background:var(--danger);color:#fff;border:none;border-radius:7px;font-family:'Sora',sans-serif;font-size:12px;font-weight:600;cursor:pointer"><i class="fas fa-trash" style="margin-right:5px"></i>Clear History</button>
            </div>
        </div>
    </div>
</div>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
'use strict';

/* ══════════════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════════════ */
const APP = {
    weatherData: null,
    typhoonData: [],
    forecastDays: [],
    userLocation: 'Philippines',
    userLat: 7.0800,
    userLon: 125.6200,
    map: null,
    typhoonMarkers: [],
    userMarker: null,
    chatLocationMarker: null,
    sessionId: 'session_' + Math.random().toString(36).slice(2),
    chatHistory: [],
    mlHistory: [],
};

/* ══════════════════════════════════════════════════════════
   LOCATION MAP (Philippines)
══════════════════════════════════════════════════════════ */
const PH_LOCATIONS = {
    'quezon city':[14.6760,121.0437],'san juan':[14.6000,121.0333],'las pinas':[14.4453,120.9936],
    'las piñas':[14.4453,120.9936],'paranaque':[14.4793,121.0198],'parañaque':[14.4793,121.0198],
    'mandaluyong':[14.5794,121.0359],'muntinlupa':[14.4126,121.0414],'valenzuela':[14.7011,120.9830],
    'caloocan':[14.7500,120.9822],'malabon':[14.6625,120.9570],'navotas':[14.6667,120.9417],
    'marikina':[14.6507,121.1029],'pateros':[14.5456,121.0681],'taguig':[14.5176,121.0509],
    'makati':[14.5547,121.0244],'pasay':[14.5378,121.0014],'pasig':[14.5764,121.0851],
    'manila':[14.5995,120.9842],'quezon province':[14.0313,122.1109],'camarines norte':[14.1389,122.7632],
    'camarines sur':[13.6252,123.1839],'nueva vizcaya':[16.3301,121.1711],'nueva ecija':[15.5784,121.1112],
    'ilocos norte':[18.1647,120.7116],'ilocos sur':[17.5755,120.3869],'la union':[16.6159,120.3209],
    'pangasinan':[15.8949,120.2863],'catanduanes':[13.7089,124.2421],'cagayan':[17.6132,121.7270],
    'isabela':[16.9754,121.8107],'benguet':[16.5607,120.8018],'ifugao':[16.8303,121.1710],
    'batangas':[13.7565,121.0583],'bulacan':[14.7942,120.8800],'pampanga':[15.0794,120.6200],
    'zambales':[15.5082,120.0697],'bataan':[14.6418,120.4818],'tarlac':[15.4755,120.5960],
    'laguna':[14.1407,121.4690],'cavite':[14.4791,120.8970],'rizal':[14.6042,121.3084],
    'aurora':[15.9784,121.6323],'baguio':[16.4023,120.5960],'bicol':[13.4199,123.4135],
    'albay':[13.1787,123.5281],'legazpi':[13.1391,123.7438],'sorsogon':[12.9433,124.0000],
    'masbate':[12.3697,123.6197],'negros occidental':[10.2926,123.0249],'negros oriental':[9.6168,123.0108],
    'eastern samar':[11.6500,125.4167],'southern leyte':[10.3365,125.1720],'iloilo city':[10.6969,122.5644],
    'cebu city':[10.3157,123.8854],'tagbilaran':[9.6500,123.8500],'dumaguete':[9.3068,123.3054],
    'tacloban':[11.2543,125.0000],'bacolod':[10.6407,122.9740],'iloilo':[10.7202,122.5621],
    'samar':[11.7534,125.1106],'leyte':[10.8620,124.8811],'biliran':[11.5833,124.4667],
    'capiz':[11.5500,122.7500],'aklan':[11.8166,122.0942],'antique':[11.3683,122.0698],
    'guimaras':[10.5982,122.6277],'bohol':[9.8500,124.1435],'cebu':[10.3157,123.8854],
    'zamboanga del norte':[8.1521,123.2650],'zamboanga del sur':[7.8383,123.2966],
    'zamboanga city':[6.9214,122.0790],'davao del norte':[7.5619,125.6549],
    'davao del sur':[6.7656,125.3284],'davao oriental':[7.3172,126.5420],'davao city':[7.0700,125.6120],
    'cagayan de oro':[8.4542,124.6319],'misamis oriental':[8.5000,124.6000],
    'misamis occidental':[8.3373,123.7071],'compostela valley':[7.6717,126.0765],
    'north cotabato':[7.1347,124.8510],'south cotabato':[6.2969,124.8420],
    'sultan kudarat':[6.5069,124.4168],'lanao del norte':[8.0730,124.2873],
    'lanao del sur':[7.8232,124.4198],'agusan del norte':[8.9456,125.5317],
    'agusan del sur':[8.1635,126.0135],'surigao del norte':[9.7177,125.5950],
    'surigao del sur':[8.7512,126.1378],'cotabato city':[7.2047,124.2310],
    'general santos':[6.1164,125.1716],'zamboanga':[6.9214,122.0790],'maguindanao':[6.9423,124.4175],
    'sarangani':[5.9272,124.9940],'bukidnon':[8.0515,125.0985],'surigao':[9.7806,125.4964],
    'butuan':[8.9475,125.5406],'iligan':[8.2280,124.2452],'gensan':[6.1164,125.1716],
    'tagum':[7.4478,125.8076],'digos':[6.7497,125.3571],'davao':[7.0700,125.6120],
    'south china sea':[15.0000,114.0000],'philippine sea':[15.0000,130.0000],
    'sulu sea':[8.0000,120.0000],'celebes sea':[4.0000,123.0000],'pacific ocean':[15.0000,140.0000],
};

function extractLocationFromText(text) {
    const lower = text.toLowerCase();
    const keys = Object.keys(PH_LOCATIONS).sort((a,b) => b.length - a.length);
    let best = null, bestLen = 0;
    for (const key of keys) {
        const escaped = key.replace(/[.*+?^${}()|[\]\\]/g,'\\$&');
        const re = new RegExp('(?:^|[\\s,.()\\/])' + escaped + '(?=$|[\\s,.()\\/])','i');
        if (re.test(lower) && key.length > bestLen) {
            best = { name: key, coords: PH_LOCATIONS[key] };
            bestLen = key.length;
        }
    }
    return best;
}

function flyMapToLocation(name, coords) {
    if (!APP.map) return;
    const [lat, lon] = coords;
    const displayName = name.replace(/\b\w/g, c => c.toUpperCase());
    APP.map.flyTo([lat, lon], 9, { animate: true, duration: 1.5 });
    if (APP.chatLocationMarker) { APP.map.removeLayer(APP.chatLocationMarker); APP.chatLocationMarker = null; }
    const icon = L.divIcon({
        html: `<div style="background:linear-gradient(135deg,#6366f1,#4f46e5);color:#fff;border-radius:10px;padding:5px 10px;font-family:'Sora',sans-serif;font-size:11px;font-weight:700;box-shadow:0 4px 14px rgba(99,102,241,.5);white-space:nowrap;border:2px solid #fff;display:flex;align-items:center;gap:5px;"><i class="fas fa-comment-dots" style="font-size:10px"></i>${displayName}</div>`,
        className: '', iconAnchor: [0, 0]
    });
    APP.chatLocationMarker = L.marker([lat, lon], { icon }).addTo(APP.map)
        .bindPopup(`<strong>${displayName}</strong><br><span style="font-size:11px;color:#64748b">Mentioned in chat</span>`).openPopup();
    setTimeout(() => { if (APP.chatLocationMarker) { APP.map.removeLayer(APP.chatLocationMarker); APP.chatLocationMarker = null; } }, 10000);
    const mapEl = document.getElementById('map');
    if (mapEl) mapEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

/* ══════════════════════════════════════════════════════════
   DATETIME
══════════════════════════════════════════════════════════ */
function updateDateTime() {
    const el = document.getElementById('currentDateTime');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric'})
        + ' · ' + now.toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}
setInterval(updateDateTime, 1000); updateDateTime();

/* ══════════════════════════════════════════════════════════
   MAP INIT
══════════════════════════════════════════════════════════ */
function initMap() {
    APP.map = L.map('map', {
        zoomControl: true,
        scrollWheelZoom: true,
        attributionControl: true
    }).setView([15.0, 122.0], 5);

    // ── Dark ocean tiles (PAGASA-style navy background)
    L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
        attribution: '© <a href="https://www.openstreetmap.org/copyright" style="color:#aaa">OpenStreetMap</a> © <a href="https://carto.com/" style="color:#aaa">CARTO</a>',
        subdomains: 'abcd',
        maxZoom: 18
    }).addTo(APP.map);

    // ── PAR boundary (5°N–25°N, 115°E–135°E) — dashed cyan like PAGASA
    const parCoords = [[25,115],[25,135],[5,135],[5,115],[25,115]];
    L.polyline(parCoords, {
        color: '#00d4ff', weight: 1.5, dashArray: '8,6', opacity: 0.7
    }).addTo(APP.map);

    // ── PAR label
    L.marker([25.6, 115], {
        icon: L.divIcon({
            html: `<div style="color:#00d4ff;font-family:'DM Mono',monospace;font-size:9px;font-weight:600;
                       letter-spacing:1.4px;text-transform:uppercase;opacity:.65;white-space:nowrap;
                       text-shadow:0 1px 4px rgba(0,0,0,.9);pointer-events:none">
                       ▸ Philippine Area of Responsibility (PAR)</div>`,
            className: '', iconAnchor: [-4, 0]
        }),
        interactive: false,
        zIndexOffset: -1000
    }).addTo(APP.map);

    // ── Store PAR layer group for typhoon overlays
    APP.typhoonLayer = L.layerGroup().addTo(APP.map);
}

function updateMapMarkers() {
    // ── User location marker
    if (APP.userMarker) APP.map.removeLayer(APP.userMarker);
    const userIcon = L.divIcon({
        html: `<div style="width:12px;height:12px;border-radius:50%;background:#e11d48;
                   border:2.5px solid #fff;box-shadow:0 0 0 4px rgba(225,29,72,.25),0 2px 8px rgba(225,29,72,.5)"></div>`,
        className: '', iconAnchor: [6, 6]
    });
    APP.userMarker = L.marker([APP.userLat, APP.userLon], { icon: userIcon, zIndexOffset: 1000 })
        .addTo(APP.map)
        .bindPopup(`<strong style="font-family:'Sora',sans-serif">📍 Your Location</strong><br>
            <span style="font-size:11px;color:#64748b">${APP.userLocation}</span><br>
            <span style="font-size:10px;font-family:'DM Mono',monospace">${APP.userLat.toFixed(4)}°N, ${APP.userLon.toFixed(4)}°E</span>`);

    // ── Clear previous typhoon overlays
    if (APP.typhoonLayer) APP.typhoonLayer.clearLayers();
    APP.typhoonMarkers = [];

    if (!APP.typhoonData || !APP.typhoonData.length) return;

    APP.typhoonData.forEach(t => {
        const dist = parseFloat(t.distance);
        const wind = parseFloat(t.windSpeed);

       // Use REAL coordinates if available, otherwise fall back to bearing estimate
        let tLat, tLon;
        if (t.lat != null && t.lon != null && !isNaN(parseFloat(t.lat))) {
            tLat = parseFloat(t.lat);
            tLon = parseFloat(t.lon);
        } else {
            const bearing = (t._bearing !== undefined) ? t._bearing : (Math.random() * 360);
            t._bearing = bearing;
            const rad = bearing * Math.PI / 180;
            tLat = APP.userLat + (dist / 111) * Math.cos(rad);
            tLon = APP.userLon + (dist / (111 * Math.cos(APP.userLat * Math.PI / 180))) * Math.sin(rad);
        }

        // ── Color + size by intensity
        const isSuper = wind >= 185;
        const isTyphoon = wind >= 118;
        const isStorm = wind >= 62;
        const color = isSuper ? '#ff3860' : isTyphoon ? '#f97316' : isStorm ? '#f59e0b' : '#60a5fa';
        const glowColor = isSuper ? 'rgba(255,56,96,.5)' : isTyphoon ? 'rgba(249,115,22,.45)' : isStorm ? 'rgba(245,158,11,.4)' : 'rgba(96,165,250,.35)';
        const iconSize = isSuper ? 38 : isTyphoon ? 32 : isStorm ? 26 : 20;
        const label = isSuper ? 'SUPER TYPHOON' : isTyphoon ? 'TYPHOON' : isStorm ? 'TROPICAL STORM' : 'TROPICAL DEPRESSION';

        // ── Typhoon eye icon (spinning)
        const tIcon = L.divIcon({
            html: `<div style="
                width:${iconSize}px;height:${iconSize}px;border-radius:50%;
                background:radial-gradient(circle at 38% 38%, ${color}cc, ${color}44);
                border:2px solid ${color};
                box-shadow:0 0 0 4px ${glowColor},0 0 18px ${glowColor};
                display:flex;align-items:center;justify-content:center;
                animation:tt-spin 4s linear infinite;
                cursor:pointer;
            ">
                <div style="width:${Math.round(iconSize*0.35)}px;height:${Math.round(iconSize*0.35)}px;
                    border-radius:50%;background:#fff;opacity:.85"></div>
            </div>`,
            className: '',
            iconAnchor: [iconSize / 2, iconSize / 2]
        });

        const marker = L.marker([tLat, tLon], { icon: tIcon, zIndexOffset: 900 })
            .bindPopup(`
                <div style="font-family:'Sora',sans-serif;min-width:200px">
                    <div style="font-size:13px;font-weight:800;color:#0f172a;margin-bottom:6px">
                        🌀 ${t.name}
                    </div>
                    <div style="display:inline-block;padding:2px 8px;border-radius:20px;
                        background:${color}22;border:1px solid ${color}66;
                        color:${color};font-size:10px;font-weight:700;margin-bottom:8px">${label}</div>
                    <div style="display:flex;flex-direction:column;gap:4px;font-size:11px;color:#475569">
                        <div>💨 Wind: <strong>${wind} km/h</strong></div>
                        <div>📍 Distance: <strong>${dist} km from you</strong></div>
                        ${t.direction ? `<div>🧭 Moving: <strong>${t.direction}</strong></div>` : ''}
                        ${t.pressure ? `<div>🌡️ Pressure: <strong>${t.pressure} hPa</strong></div>` : ''}
                        <div style="margin-top:4px;padding-top:4px;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8">
                            Source: ${t.source || 'PAGASA/JTWC'}
                        </div>
                    </div>
                </div>
            `);

        APP.typhoonLayer.addLayer(marker);
        APP.typhoonMarkers.push(marker);

        // ── Forecast track line (dotted, fading) if track data exists
        if (t.track && t.track.length > 1) {
            const trackLine = L.polyline(t.track.map(p => [p.lat, p.lon]), {
                color, weight: 2.5, opacity: 0.85, dashArray: '1, 0'
            });
            APP.typhoonLayer.addLayer(trackLine);

            // Past track dots
            t.track.forEach((pt, idx) => {
                const dotSize = idx === t.track.length - 1 ? 0 : 7;
                if (dotSize === 0) return;
                const pastIcon = L.divIcon({
                    html: `<div style="width:${dotSize}px;height:${dotSize}px;border-radius:50%;
                               background:${color};opacity:${0.3 + (idx / t.track.length) * 0.5};
                               border:1px solid ${color}88"></div>`,
                    className: '', iconAnchor: [dotSize / 2, dotSize / 2]
                });
                APP.typhoonLayer.addLayer(L.marker([pt.lat, pt.lon], { icon: pastIcon, interactive: false }));
            });
        }

        // ── Forecast track (dashed, lighter)
        if (t.forecast && t.forecast.length > 0) {
            const forecastCoords = [[tLat, tLon], ...t.forecast.map(p => [p.lat, p.lon])];
            const forecastLine = L.polyline(forecastCoords, {
                color, weight: 2, opacity: 0.55, dashArray: '6, 5'
            });
            APP.typhoonLayer.addLayer(forecastLine);

            // Forecast cone (uncertainty envelope)
            if (t.forecast.length >= 2) {
                const conePoints = buildConePolygon([{lat: tLat, lon: tLon}, ...t.forecast]);
                if (conePoints.length > 2) {
                    const cone = L.polygon(conePoints, {
                        color, fillColor: color, fillOpacity: 0.08,
                        weight: 0, interactive: false
                    });
                    APP.typhoonLayer.addLayer(cone);
                }
            }

            // Forecast position dots with time labels
            t.forecast.forEach((pt, idx) => {
                const hours = (idx + 1) * 24;
                const fIcon = L.divIcon({
                    html: `<div style="display:flex;flex-direction:column;align-items:center;pointer-events:none">
                        <div style="width:10px;height:10px;border-radius:50%;background:transparent;
                            border:2px solid ${color};opacity:.75"></div>
                        <div style="font-size:8px;font-weight:700;color:${color};
                            font-family:'DM Mono',monospace;margin-top:1px;
                            text-shadow:0 1px 3px rgba(0,0,0,.9);white-space:nowrap">+${hours}h</div>
                    </div>`,
                    className: '', iconAnchor: [5, 5]
                });
                APP.typhoonLayer.addLayer(L.marker([pt.lat, pt.lon], { icon: fIcon, interactive: false }));
            });
        }

        // ── Wind radius circle (danger zone)
        const windRadiusKm = wind >= 185 ? 250 : wind >= 118 ? 180 : wind >= 88 ? 120 : wind >= 62 ? 80 : 50;
        const windCircle = L.circle([tLat, tLon], {
            radius: windRadiusKm * 1000,
            color, fillColor: color, fillOpacity: 0.04,
            weight: 1, dashArray: '3,4', opacity: 0.45
        });
        APP.typhoonLayer.addLayer(windCircle);
    });

    // ── If there are typhoons, fit the map to show all of them + user
    if (APP.typhoonData.length > 0) {
        try {
            const allMarkers = [
    [APP.userLat, APP.userLon],
    ...APP.typhoonData
        .filter(t => t.lat != null && t.lon != null && !isNaN(parseFloat(t.lat)))
        .map(t => [parseFloat(t.lat), parseFloat(t.lon)])
];
if (allMarkers.length > 1) {
    APP.map.fitBounds(L.latLngBounds(allMarkers), { padding: [60, 60], maxZoom: 7 });
}
        } catch(e) { /* fallback: do nothing */ }
    }
}

// ── Helper: build a simple cone-of-uncertainty polygon around a track array
function buildConePolygon(points) {
    if (points.length < 2) return [];
    const leftSide = [], rightSide = [];
    points.forEach((pt, i) => {
        const spreadKm = 40 + i * 35; // cone widens over time
        const spreadDeg = spreadKm / 111;
        // compute bearing to next point for perpendicular offset
        const next = points[Math.min(i + 1, points.length - 1)];
        const dx = next.lon - pt.lon, dy = next.lat - pt.lat;
        const angle = Math.atan2(dx, dy);
        const perpLat = Math.cos(angle) * spreadDeg;
        const perpLon = Math.sin(angle) * spreadDeg;
        leftSide.push([pt.lat + perpLat, pt.lon - perpLon]);
        rightSide.push([pt.lat - perpLat, pt.lon + perpLon]);
    });
    return [...leftSide, ...rightSide.reverse()];
}

/* ══════════════════════════════════════════════════════════
   WEATHER FETCH
══════════════════════════════════════════════════════════ */
async function fetchWeather(lat, lon) {
    ['windSpeed','temperature','pressure','humidity'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.innerHTML = '<span style="font-size:12px;color:var(--muted)">…</span>';
    });
    document.getElementById('lastUpdate').textContent = 'Fetching…';
    try {
        const params = new URLSearchParams({
            latitude: lat, longitude: lon,
            current: 'temperature_2m,relative_humidity_2m,wind_speed_10m,surface_pressure,weather_code',
            daily: 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,wind_speed_10m_max',
            forecast_days: 7, timezone: 'Asia/Manila'
        });
        const res = await fetch('https://api.open-meteo.com/v1/forecast?' + params, { signal: AbortSignal.timeout(12000) });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        if (!data.current) throw new Error('No current data');
        const c = data.current;
        APP.weatherData = {
            temperature: Math.round((c.temperature_2m ?? 28)*10)/10,
            humidity:    Math.round(c.relative_humidity_2m ?? 75),
            windSpeed:   Math.round(c.wind_speed_10m ?? 0),
            pressure:    Math.round(c.surface_pressure ?? 1013),
            weatherCode: c.weather_code ?? 0
        };
        APP.mlHistory.push({ ...APP.weatherData, ts: Date.now() });
        if (APP.mlHistory.length > 20) APP.mlHistory.shift();
        updateWeatherDisplay(APP.weatherData);
        runMLEngine(APP.weatherData);
        const d = data.daily || {};
        const days = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        APP.forecastDays = (d.time || []).map((dateStr, i) => {
            const dt = new Date(dateStr + 'T00:00:00');
            return {
                dayName:    i===0?'Today':i===1?'Tomorrow':days[dt.getDay()],
                date:       dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'}),
                maxTemp:    Math.round(d.temperature_2m_max?.[i] ?? 32),
                minTemp:    Math.round(d.temperature_2m_min?.[i] ?? 25),
                precipProb: d.precipitation_probability_max?.[i] ?? 0,
                precip:     Math.round((d.precipitation_sum?.[i] ?? 0)*10)/10,
                windSpeed:  Math.round(d.wind_speed_10m_max?.[i] ?? 0),
                pressure:   APP.weatherData.pressure,
                humidity:   APP.weatherData.humidity
            };
        });
        renderForecast(APP.forecastDays);
    } catch(e) {
        console.error('Weather fetch error:', e.message);
        document.getElementById('lastUpdate').textContent = 'Retrying…';
        ['windSpeed','temperature','pressure','humidity'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.innerHTML = '<span style="font-size:11px;color:var(--danger)">—</span>';
        });
        document.getElementById('forecastDays').innerHTML =
            '<div class="tt-empty" style="grid-column:1/-1"><i class="fas fa-exclamation-circle" style="color:var(--danger)"></i><p>Could not load weather. Check your connection.</p></div>';
        setTimeout(() => fetchWeather(lat, lon), 15000);
    }
}

/* ══════════════════════════════════════════════════════════
   TYPHOON FETCH — with minimum skeleton display time
══════════════════════════════════════════════════════════ */
function renderTyphoonSkeleton() {
    document.getElementById('typhoonList').innerHTML = `
        <div class="tt-skeleton-list">
            ${[0,1,2].map((_, i) => `
            <div class="tt-skeleton-item" style="animation-delay:${i * 0.12}s">
                <div class="tt-skeleton-stripe"></div>
                <div class="tt-skeleton-body">
                    <div class="tt-skeleton-row">
                        <div class="tt-skeleton-block tt-skeleton-block--name"></div>
                        <div class="tt-skeleton-block tt-skeleton-block--badge"></div>
                    </div>
                    <div class="tt-skeleton-row tt-skeleton-row--meta">
                        <div class="tt-skeleton-block tt-skeleton-block--meta"></div>
                        <div class="tt-skeleton-block tt-skeleton-block--meta"></div>
                    </div>
                </div>
            </div>`).join('')}
        </div>`;
}

async function fetchTyphoons() {
    /* ── disable button & spin icon while loading ── */
    const btn = document.getElementById('refreshBtn');
    if (btn) btn.classList.add('tt-refresh-btn--spinning');

    renderTyphoonSkeleton();

    /* skeleton is visible for AT LEAST 1 800 ms regardless of API speed */
    const minDelay = new Promise(resolve => setTimeout(resolve, 1800));

    let result = null, found = false;
try {
    const data = await fetchFromPAGASAProxy(); // now fetches PAGASA + JTWC combined
    result = data;
    found = true;
} catch(e) {
    // last resort: JTWC only
    try {
        const data = await fetchFromJTWC();
        if (data && data.length > 0) { result = data; found = true; }
    } catch(e2) {}
}

    /* wait until both the API round-trip AND the minimum delay are done */
    await minDelay;

    if (found) {
        APP.typhoonData = result;
        renderTyphoonList(APP.typhoonData);
    } else {
        APP.typhoonData = [];
        renderTyphoonListUnavailable();
    }

    updateMapMarkers();

    /* re-enable button */
    if (btn) btn.classList.remove('tt-refresh-btn--spinning');
}

async function fetchFromPAGASAProxy() {
    let proxyStorms = [];
    try {
        const res = await fetch('pagasa_proxy.php', { signal: AbortSignal.timeout(20000) });
        if (res.ok) {
            const data = await res.json();
            if (data.typhoons && data.typhoons.length > 0) proxyStorms = data.typhoons;
        }
    } catch(e) { /* proxy failed, continue */ }

    // Always also fetch JTWC/GDACS for outside-PAR storms
    let jtwcStorms = [];
    try { jtwcStorms = await fetchFromJTWC() || []; } catch(e) {}

    // Merge: add JTWC storms not already in proxy results
    const existing = new Set(proxyStorms.map(t => t.name));
    const newStorms = jtwcStorms.filter(t => t.lat != null && !existing.has(t.name));

    return [...proxyStorms, ...newStorms];
}

async function fetchFromJTWC() {
    // ── Try GDACS GeoJSON first (real lat/lon, reliable)
    try {
        const gRes = await fetch(
            'https://www.gdacs.org/gdacsapi/api/events/geteventlist/SEARCH?eventtype=TC&alertlevel=Orange;Red',
            { signal: AbortSignal.timeout(8000) }
        );
        if (gRes.ok) {
            const gJson = await gRes.json();
            const storms = [];
            (gJson.features || []).forEach(f => {
                const p = f.properties || {};
                const coords = f.geometry?.coordinates;
                if (!coords) return;
                const [lon, lat] = coords;
                if (lon < 95 || lon > 185 || lat < -5 || lat > 45) return;
                const windKts = parseFloat(p.maxwind || p.wind_max || 0);
                const windKmh = windKts > 0 ? Math.round(windKts * 1.852) : parseInt(p.windspeed || 0);
                storms.push({
                    name:      (p.name || p.eventname || 'UNNAMED').toUpperCase(),
                    windSpeed: windKmh,
                    lat:       parseFloat(lat),
                    lon:       parseFloat(lon),
                    distance:  Math.round(haversineKm(APP.userLat, APP.userLon, lat, lon)),
                    direction: null,
                    source:    'GDACS'
                });
            });
            if (storms.length > 0) return storms;
        }
    } catch(e) { /* fall through */ }

    // ── Fallback: JTWC XML with real coordinate parsing
    try {
        const res = await fetch(
            'https://www.nhc.noaa.gov/productfeeds/jtwc_atcf.xml',
            { signal: AbortSignal.timeout(8000) }
        );
        if (!res.ok) return null;
        const text = await res.text();
        const parser = new DOMParser();
        const xml = parser.parseFromString(text, 'text/xml');
        const items = xml.querySelectorAll('item');
        if (!items.length) return [];
        const storms = [];
        items.forEach(item => {
            const title = item.querySelector('title')?.textContent || '';
            const desc  = item.querySelector('description')?.textContent || '';
            // Western Pacific only
            if (!title.match(/\bW\b|\bWP\b/i) &&
                !desc.toLowerCase().includes('western pacific') &&
                !desc.toLowerCase().includes('philippine')) return;
            // Parse real coordinates from description
            const cM = desc.match(/(\d+\.?\d*)\s*[°\s]*([NS])[\s,\/]+(\d+\.?\d*)\s*[°\s]*([EW])/i);
            let tLat = null, tLon = null;
            if (cM) {
                tLat = parseFloat(cM[1]); if (cM[2].toUpperCase() === 'S') tLat = -tLat;
                tLon = parseFloat(cM[3]); if (cM[4].toUpperCase() === 'W') tLon = -tLon;
            }
            const wM = desc.match(/(\d+)\s*kt/i);
            const pM = desc.match(/(\d{3,4})\s*(?:mb|hpa)/i);
            storms.push({
                name:      title.replace(/^(Tropical\s+)?(Cyclone|Typhoon|Storm|Depression)\s*/i,'').trim() || 'UNNAMED',
                windSpeed: wM ? Math.round(parseInt(wM[1]) * 1.852) : 0,
                lat:       tLat,
                lon:       tLon,
                distance:  (tLat !== null && tLon !== null)
                               ? Math.round(haversineKm(APP.userLat, APP.userLon, tLat, tLon))
                               : 9999,
                direction: extractDirection(desc),
                pressure:  pM ? parseInt(pM[1]) : null,
                source:    'JTWC'
            });
        });
        return storms;
    } catch(e) { return null; }
}

async function fetchFromOpenWeatherTyphoons() { return null; }

function estimateDistanceFromDesc(desc, userLat, userLon) {
    const m = desc.match(/(\d+\.?\d*)[°\s]*([NS])[\s,]+(\d+\.?\d*)[°\s]*([EW])/i);
    if (m) {
        let lat = parseFloat(m[1]); if (m[2].toUpperCase()==='S') lat = -lat;
        let lon = parseFloat(m[3]); if (m[4].toUpperCase()==='W') lon = -lon;
        return Math.round(haversineKm(userLat, userLon, lat, lon));
    }
    return 999;
}
function extractDirection(desc) {
    const m = desc.match(/moving\s+(?:toward\s+the\s+)?([A-Z]+(?:EAST|WEST|NORTH|SOUTH|NE|NW|SE|SW)?)/i);
    return m ? m[1].toUpperCase().slice(0,2) : null;
}
function haversineKm(lat1,lon1,lat2,lon2) {
    const R=6371,dLat=(lat2-lat1)*Math.PI/180,dLon=(lon2-lon1)*Math.PI/180;
    const a=Math.sin(dLat/2)**2+Math.cos(lat1*Math.PI/180)*Math.cos(lat2*Math.PI/180)*Math.sin(dLon/2)**2;
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

/* ══════════════════════════════════════════════════════════
   TYPHOON LIST RENDER
══════════════════════════════════════════════════════════ */
function renderTyphoonList(typhoons) {
    const el = document.getElementById('typhoonList');
    if (!typhoons || !typhoons.length) {
        el.innerHTML = `
            <div class="tt-allclear" id="allClearState">
                <div class="tt-allclear__ring-wrap">
                    <div class="tt-allclear__ring"></div>
                    <div class="tt-allclear__ring"></div>
                    <div class="tt-allclear__ring"></div>
                    <div class="tt-allclear__icon-circle">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
                <div class="tt-allclear__title">All Clear</div>
                <div class="tt-allclear__desc">No active typhoons detected in the Philippine Area of Responsibility.</div>
                <span class="tt-badge tt-badge--success"><i class="fas fa-shield-alt" style="margin-right:4px"></i>PAR is safe</span>
            </div>`;
        /* trigger fade-in on next paint */
        requestAnimationFrame(() => requestAnimationFrame(() => {
            const node = document.getElementById('allClearState');
            if (node) node.classList.add('tt-allclear--visible');
        }));
        return;
    }
    el.innerHTML = typhoons.map(t => {
        const d = parseFloat(t.distance);
        const lvl = d<300?'danger':d<600?'warning':'info';
        const badge = d<300
            ? `<span class="tt-badge tt-badge--danger tt-badge--pulse"><i class="fas fa-exclamation-triangle"></i> Immediate Danger</span>`
            : d<600
                ? `<span class="tt-badge tt-badge--warning"><i class="fas fa-exclamation-circle"></i> High Alert</span>`
                : `<span class="tt-badge tt-badge--info"><i class="fas fa-eye"></i> Monitoring</span>`;
        const inPAR = t.lat != null && t.lon != null
            && t.lat >= 5 && t.lat <= 25
            && t.lon >= 115 && t.lon <= 135;
        const parTag = inPAR
            ? `<span class="tt-badge tt-badge--danger" style="margin-left:4px">IN PAR</span>`
            : `<span class="tt-badge tt-badge--muted" style="margin-left:4px">Outside PAR</span>`;
        return `<div class="tt-typhoon-item tt-typhoon-item--${lvl}">
            <div class="tt-typhoon-item__stripe"></div>
            <div class="tt-typhoon-item__body">
                <div class="tt-typhoon-item__top">
                    <span class="tt-typhoon-item__name"><i class="fas fa-wind" style="margin-right:5px;opacity:.6"></i>${t.name}</span>${badge}
                </div>
                <div class="tt-typhoon-item__meta">
                    <span><i class="fas fa-tachometer-alt" style="margin-right:2px"></i>${t.windSpeed} km/h</span>
                    <span><i class="fas fa-map-marker-alt" style="margin-right:2px"></i>${t.distance} km away</span>
                    ${t.direction?`<span><i class="fas fa-compass" style="margin-right:2px"></i>${t.direction}</span>`:''}
                    ${parTag}
                </div>
            </div>
        </div>`;
    }).join('');
}

function renderTyphoonListUnavailable() {
    const el = document.getElementById('typhoonList');
    el.innerHTML = `
        <div style="padding:14px 18px;opacity:0;transform:translateY(8px);transition:opacity .5s ease,transform .5s ease;" id="typhoonUnavailInner">
            <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:var(--info-light);border:1px solid #93c5fd;border-radius:10px;margin-bottom:10px">
                <i class="fas fa-satellite-dish" style="color:var(--info);margin-top:1px;flex-shrink:0"></i>
                <div>
                    <div style="font-size:11px;font-weight:700;color:#1e40af;margin-bottom:3px">Live Data Unavailable</div>
                    <div style="font-size:11px;color:#1e40af;line-height:1.55">Could not reach real-time typhoon APIs. No simulated data is shown.</div>
                </div>
            </div>
            <div style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:var(--surf2);border:1px solid var(--border);border-radius:10px">
                <i class="fas fa-external-link-alt" style="color:var(--muted);margin-top:1px;flex-shrink:0"></i>
                <div style="font-size:11px;color:var(--muted);line-height:1.6">
                    Check official sources:<br>
                    <a href="https://bagong.pagasa.dost.gov.ph" target="_blank" style="color:var(--navy-light);font-weight:600">PAGASA</a> ·
                    <a href="https://www.jma.go.jp/en/typh/" target="_blank" style="color:var(--navy-light);font-weight:600">JMA</a> ·
                    <a href="https://www.jtwc.navy.mil" target="_blank" style="color:var(--navy-light);font-weight:600">JTWC</a>
                </div>
            </div>
        </div>`;
    requestAnimationFrame(() => requestAnimationFrame(() => {
        const node = document.getElementById('typhoonUnavailInner');
        if (node) { node.style.opacity = '1'; node.style.transform = 'translateY(0)'; }
    }));
}

/* ══════════════════════════════════════════════════════════
   WEATHER DISPLAY
══════════════════════════════════════════════════════════ */
function updateWeatherDisplay(data) {
    const wind=parseFloat(data.windSpeed),temp=parseFloat(data.temperature),
          pres=parseFloat(data.pressure),hum=parseFloat(data.humidity);
    document.getElementById('windSpeed').innerHTML   = `${wind}<span class="tt-wc__unit">km/h</span>`;
    document.getElementById('temperature').innerHTML = `${temp}<span class="tt-wc__unit">°C</span>`;
    document.getElementById('pressure').innerHTML    = `${pres}<span class="tt-wc__unit">hPa</span>`;
    document.getElementById('humidity').innerHTML    = `${hum}<span class="tt-wc__unit">%</span>`;
    const setStatus = (id,text,bg,color) => {
        const el=document.getElementById(id); el.textContent=text;
        el.style.cssText=`background:${bg};color:${color};border-radius:6px;padding:2px 7px;`;
    };
    if(wind>118)setStatus('windStatus','Signal #4+','#fee2e2','#7f1d1d');
    else if(wind>88)setStatus('windStatus','Signal #3','#fef3c7','#92400e');
    else if(wind>62)setStatus('windStatus','Signal #2','#dbeafe','#1e40af');
    else if(wind>39)setStatus('windStatus','Signal #1','#d1fae5','#065f46');
    else setStatus('windStatus','Normal','transparent','#64748b');
    if(temp>=36)setStatus('tempStatus','Dangerously Hot','#fee2e2','#7f1d1d');
    else if(temp>=32)setStatus('tempStatus','Very Hot','#fef3c7','#92400e');
    else if(temp>=28)setStatus('tempStatus','Warm','#d1fae5','#065f46');
    else setStatus('tempStatus','Comfortable','transparent','#64748b');
    if(pres<1005)setStatus('pressureStatus','Critical Low','#fee2e2','#7f1d1d');
    else if(pres<1009)setStatus('pressureStatus','Low','#fef3c7','#92400e');
    else setStatus('pressureStatus','Normal','transparent','#64748b');
    if(hum>=95)setStatus('humidityStatus','Critical','#fee2e2','#7f1d1d');
    else if(hum>=90)setStatus('humidityStatus','Very High','#fef3c7','#92400e');
    else if(hum>=80)setStatus('humidityStatus','High','#dbeafe','#1e40af');
    else setStatus('humidityStatus','Comfortable','transparent','#64748b');
    document.getElementById('lastUpdate').textContent='Updated '+new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
}

/* ══════════════════════════════════════════════════════════
   FORECAST RENDER
══════════════════════════════════════════════════════════ */
function renderForecast(days) {
    const el = document.getElementById('forecastDays');
    if (!days || !days.length) {
        el.innerHTML = '<div class="tt-empty"><i class="fas fa-cloud"></i><p>Forecast unavailable</p></div>'; return;
    }
    el.innerHTML = days.map((d,i) => {
        const ico = d.precipProb>70?'🌧️':d.precipProb>40?'⛅':'☀️';
        return `<div class="tt-fc-day" onclick="openForecastModal(${i})" style="animation-delay:${i*0.05}s">
            <div class="tt-fc-day__name">${d.dayName}</div>
            <div class="tt-fc-day__icon">${ico}</div>
            <div class="tt-fc-day__temps">${d.minTemp}° / ${d.maxTemp}°C</div>
            <div class="tt-fc-day__rain"><i class="fas fa-tint" style="margin-right:2px"></i>${d.precipProb}%</div>
        </div>`;
    }).join('');
}

/* ══════════════════════════════════════════════════════════
   ML ENGINE
══════════════════════════════════════════════════════════ */
function runMLEngine(weather) {
    const w=parseFloat(weather.windSpeed),h=parseFloat(weather.humidity),
          p=parseFloat(weather.pressure),t=parseFloat(weather.temperature),
          history=APP.mlHistory;
    const pressureDeficit=Math.max(0,1013-p);
    const rainProb=Math.min(100,Math.max(0,Math.round(-20+(h*0.72)+(pressureDeficit*1.8)+(w*0.25)+(t>30?5:0))));
    const rainConf=Math.min(98,70+h*0.1+pressureDeficit*0.5);
    let floodRisk=0;
    if(h>=95&&p<1005&&w>40)floodRisk=90;
    else if(h>=90&&p<1008&&w>30)floodRisk=65;
    else if(h>=85&&p<1010)floodRisk=40;
    else if(h>=80||p<1012)floodRisk=20;
    else floodRisk=5;
    APP.typhoonData.forEach(ty=>{const d=parseFloat(ty.distance);if(d<200)floodRisk=Math.min(100,floodRisk+25);else if(d<400)floodRisk=Math.min(100,floodRisk+12);});
    const floodConf=80+(history.length>5?8:0);
    const sigmoid=x=>1/(1+Math.exp(-x));
    const typhoonScore=sigmoid(-6+(pressureDeficit*0.08)+(w*0.04)+(h>90?1.5:0)+(APP.typhoonData.length*1.8)+(APP.typhoonData.some(ty=>ty.distance<300)?2.5:0));
    const typhoonLikelihood=Math.round(typhoonScore*100);
    const typhoonConf=Math.min(95,60+history.length*1.5);
    let windForecast=w;
    if(history.length>=3){const recent=history.slice(-3).map(r=>r.windSpeed);const avg=recent.reduce((a,b)=>a+b,0)/recent.length;const trend=recent[recent.length-1]-recent[0];windForecast=Math.round(Math.max(0,avg+trend*0.3));}
    const windConf=Math.min(92,55+history.length*2);
    const severity=Math.min(10,Math.round((w/30)+(pressureDeficit/8)+(h/20)+(APP.typhoonData.length>0?1.5:0)+(APP.typhoonData.some(ty=>ty.distance<300)?2:0)));
    let pressureTrend=0;
    if(history.length>=2){const old=history[history.length-2].pressure;pressureTrend=Math.round((p-old)*10)/10;}
    setMLCard('mlRainProb',rainProb,'mlRainBar',rainProb,'mlRainLabel',rainProb>=70?'High — expect rain':rainProb>=40?'Moderate chance':'Low probability',Math.round(rainConf));
    setMLCard('mlFloodRisk',floodRisk,'mlFloodBar',floodRisk,'mlFloodLabel',floodRisk>=70?'HIGH — flood risk':floodRisk>=40?'Moderate risk':'Low risk',Math.round(floodConf));
    setMLCard('mlSeverity',severity,'mlSeverityBar',severity*10,'mlSeverityLabel',severity>=7?'Severe':severity>=4?'Moderate':'Low',78);
    setMLCard('mlWindForecast',windForecast,'mlWindBar',Math.min(100,windForecast),'mlWindLabel','6-hour prediction',Math.round(windConf));
    document.getElementById('mlPressureTrend').textContent=(pressureTrend>=0?'+':'')+pressureTrend;
    document.getElementById('mlPressureLabel').textContent=pressureTrend<-1?'Falling — storm risk':pressureTrend>1?'Rising — clearing':'Stable';
    animateBar('mlPressureBar',Math.min(100,Math.abs(pressureTrend)*20));
    setMLCard('mlTyphoonLikelihood',typhoonLikelihood,'mlTyphoonBar',typhoonLikelihood,'mlTyphoonLabel',typhoonLikelihood>=60?'High — monitor PAGASA':typhoonLikelihood>=30?'Moderate':'Low likelihood',Math.round(typhoonConf));
    setTimeout(()=>{setConfBar('confRain',Math.round(rainConf),'confRainVal');setConfBar('confFlood',Math.round(floodConf),'confFloodVal');setConfBar('confTyphoon',Math.round(typhoonConf),'confTyphoonVal');setConfBar('confWind',Math.round(windConf),'confWindVal');},200);
    const alertBox=document.getElementById('mlAlertBox'),alertTitle=document.getElementById('mlAlertTitle'),alertText=document.getElementById('mlAlertText');
    alertBox.style.display='flex';
    if(floodRisk>=70||typhoonLikelihood>=65){alertBox.className='ml-alert ml-alert--danger';alertTitle.textContent='🚨 High-Risk Conditions Detected';alertText.textContent=`ML models indicate ${floodRisk>=70?'HIGH flood risk ('+floodRisk+'%)':''}${floodRisk>=70&&typhoonLikelihood>=65?' and ':''}${typhoonLikelihood>=65?'elevated typhoon likelihood ('+typhoonLikelihood+'%)':''} in your area. Monitor PAGASA and prepare emergency kit.`;}
    else if(rainProb>=60||severity>=5){alertBox.className='ml-alert ml-alert--warning';alertTitle.textContent='⚠️ Elevated Weather Risk';alertText.textContent=`Models predict ${rainProb}% rain probability with storm severity ${severity}/10. Stay alert and check forecasts regularly.`;}
    else{alertBox.className='ml-alert ml-alert--success';alertTitle.textContent='✅ Favorable Conditions';alertText.textContent=`All ML models indicate low risk. Rain probability: ${rainProb}%, Flood risk: ${floodRisk}%, Typhoon likelihood: ${typhoonLikelihood}%. Conditions are safe.`;}
    document.getElementById('mlLastUpdate').textContent='ML ran at '+new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
}
function setMLCard(valId,val,barId,barPct,labelId,labelText){
    document.getElementById(valId).textContent=val;
    document.getElementById(labelId).textContent=labelText;
    animateBar(barId,barPct);
}
function animateBar(id,pct){const el=document.getElementById(id);if(el)setTimeout(()=>{el.style.width=Math.round(pct)+'%';},100);}
function setConfBar(barId,val,valId){const bar=document.getElementById(barId),lbl=document.getElementById(valId);if(bar)bar.style.width=val+'%';if(lbl)lbl.textContent=val+'%';}

/* ══════════════════════════════════════════════════════════
   GEOLOCATION + BOOT
══════════════════════════════════════════════════════════ */
function boot() {
    initMap();
    fetchWeather(APP.userLat, APP.userLon);
    fetchTyphoons();
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => {
                const newLat=pos.coords.latitude, newLon=pos.coords.longitude;
                if(Math.abs(newLat-APP.userLat)>0.1||Math.abs(newLon-APP.userLon)>0.1){
                    APP.userLat=newLat; APP.userLon=newLon;
                    fetchWeather(APP.userLat, APP.userLon);
                    APP.map.setView([APP.userLat, APP.userLon], 8);
                }
                reverseGeocode(APP.userLat, APP.userLon);
                updateMapMarkers();
            },
            () => {
                document.getElementById('userLocation').textContent='Philippines (default) · Enable location for local weather';
                updateMapMarkers();
            },
            { timeout: 8000, maximumAge: 60000 }
        );
    } else { updateMapMarkers(); }
    setInterval(() => fetchWeather(APP.userLat, APP.userLon), 600000);
    setInterval(fetchTyphoons, 300000);
}

async function reverseGeocode(lat, lon) {
    try {
        const r=await fetch(`https://nominatim.openstreetmap.org/reverse?lat=${lat}&lon=${lon}&format=json`);
        const d=await r.json();
        APP.userLocation=d.address?.city||d.address?.municipality||d.address?.county||d.display_name?.split(',')[0]||'Philippines';
        document.getElementById('userLocation').textContent=APP.userLocation+' · '+lat.toFixed(4)+'°N, '+lon.toFixed(4)+'°E';
        updateMapMarkers();
    } catch {
        document.getElementById('userLocation').textContent=`${lat.toFixed(4)}°N, ${lon.toFixed(4)}°E`;
    }
}

/* ══════════════════════════════════════════════════════════
   FORECAST MODAL
══════════════════════════════════════════════════════════ */
function openForecastModal(idx) {
    const d=APP.forecastDays[idx]; if(!d)return;
    const condIcon=d.precipProb>70?'fa-cloud-showers-heavy':d.precipProb>40?'fa-cloud-sun-rain':d.windSpeed>62?'fa-wind':'fa-cloud-sun';
    const dayIcon=d.precipProb>80?(d.windSpeed>62?'⛈️':'🌧️'):d.precipProb>50?'🌦️':d.precipProb>30?'⛅':'☀️';
    const condText=d.precipProb>70?'Rainy / Showers':d.precipProb>40?'Partly Cloudy':'Mostly Sunny';
    document.getElementById('modalDayIcon').textContent=dayIcon;
    document.getElementById('modalDayName').textContent=d.dayName;
    document.getElementById('modalDayDate').textContent=d.date||'';
    document.getElementById('modalConditionText').textContent=condText;
    document.getElementById('modalCondIcon').className='fas '+condIcon;
    document.getElementById('modalTempHigh').innerHTML=`${d.maxTemp}<span class="unit">°C</span>`;
    document.getElementById('modalTempLow').textContent=`/ ${d.minTemp}°C`;
    document.getElementById('detailRainProb').textContent=d.precipProb+'%';
    document.getElementById('detailRainProbSub').textContent=d.precipProb>=80?'High — expect rain showers':d.precipProb>=50?'Moderate chance':'Low probability';
    document.getElementById('detailRainfall').textContent=d.precip+' mm';
    document.getElementById('detailRainfallSub').textContent=d.precip>30?'Heavy — flooding possible':d.precip>10?'Moderate rainfall':'Light precipitation';
    document.getElementById('detailTempRange').textContent=`${d.minTemp}°C – ${d.maxTemp}°C`;
    document.getElementById('detailTempSub').textContent=d.maxTemp>=36?'Dangerously hot':d.maxTemp>=32?'Very warm day':'Comfortable range';
    document.getElementById('detailHumidity').textContent=d.humidity!=null?d.humidity+'%':(APP.weatherData?APP.weatherData.humidity+'%':'—');
    document.getElementById('detailHumiditySub').textContent='Estimated';
    document.getElementById('detailWindSpeed').textContent=d.windSpeed?d.windSpeed+' km/h':'—';
    document.getElementById('detailWindDesc').textContent=!d.windSpeed?'—':d.windSpeed>88?'Signal #3 — storm force':d.windSpeed>62?'Signal #2 — strong winds':d.windSpeed>39?'Signal #1 — moderate':'Light breeze';
    document.getElementById('detailPressure').textContent=d.pressure?d.pressure+' hPa':'—';
    document.getElementById('detailPressureSub').textContent=!d.pressure?'—':d.pressure<1005?'Critical low':d.pressure<1009?'Low pressure':'Normal range';
    const rainPct=d.precipProb;
    const tempPct=Math.min(100,Math.round(((d.maxTemp-20)/20)*100));
const _hum   = d.humidity ?? APP.weatherData?.humidity ?? 70;
const _wind  = d.windSpeed ?? 0;
const _temp  = d.maxTemp ?? 30;
const _rain  = d.precipProb ?? 0;

const _rainPenalty  = Math.min(4,  _rain  / 25);
const _windPenalty  = Math.min(3,  _wind  / 40);
const _humPenalty   = Math.max(0, (_hum  - 75) / 25);
const _heatPenalty  = Math.max(0, (_temp - 34) / 4);

const comfort = Math.max(1, Math.min(10,
    Math.round(10 - _rainPenalty - _windPenalty - _humPenalty - _heatPenalty)
));    document.getElementById('statRainProb').textContent=rainPct;
    document.getElementById('statRainDesc').textContent=rainPct>=80?'High — expect showers':rainPct>=50?'Moderate — bring umbrella':'Low — likely dry';
    document.getElementById('statTempHigh').textContent=d.maxTemp;
    document.getElementById('statTempDesc').textContent=d.maxTemp>=36?'Danger — avoid outdoors':d.maxTemp>=33?'Caution — limit activity':'Comfortable range';
    document.getElementById('statComfort').textContent=comfort;
document.getElementById('statComfortDesc').textContent=comfort>=8?'Excellent — great day outdoors!':comfort>=6?'Good — minor discomfort':comfort>=4?'Moderate — take it easy':comfort>=2?'Poor — limit outdoor time':'Very poor — stay indoors';    const adv=document.getElementById('weatherAdvice');
    adv.className='tt-modal__advice ';
    if(d.precipProb>=80||d.windSpeed>=62){adv.className+='tt-modal__advice--danger';document.getElementById('adviceTitle').textContent='⚠️ Weather Warning';document.getElementById('adviceText').textContent=d.windSpeed>=62?`Strong winds (${d.windSpeed} km/h) forecast. Secure loose objects and monitor PAGASA.`:`Heavy rain (${d.precip}mm) likely. Flood risk — keep NDRRMC (911) ready.`;}
    else if(d.precipProb>=40){adv.className+='tt-modal__advice--caution';document.getElementById('adviceTitle').textContent='☔ Weather Advisory';document.getElementById('adviceText').textContent=`Moderate rain (${d.precipProb}%). Carry an umbrella and avoid flood-prone areas.`;}
    else{adv.className+='tt-modal__advice--safe';document.getElementById('adviceTitle').textContent='✅ Conditions Favorable';document.getElementById('adviceText').textContent=`Low rain risk (${d.precipProb}%) with ${d.minTemp}–${d.maxTemp}°C. Good for outdoor activities.`;}
    document.getElementById('forecastModal').classList.add('tt-modal--open');
    setTimeout(()=>{document.getElementById('statRainBar').style.width=rainPct+'%';document.getElementById('statTempBar').style.width=tempPct+'%';document.getElementById('statComfortBar').style.width=(comfort*10)+'%';},80);
}

function closeForecastModal() {
    document.getElementById('forecastModal').classList.remove('tt-modal--open');
    ['statRainBar','statTempBar','statComfortBar'].forEach(id=>{const el=document.getElementById(id);if(el)el.style.width='0';});
}

function askAIAboutDay() {
    const dayName = document.getElementById('modalDayName')?.textContent || 'this day';
    const dayDate = document.getElementById('modalDayDate')?.textContent || '';
    const tempHigh = document.getElementById('statTempHigh')?.textContent || '—';
    const rainProb = document.getElementById('statRainProb')?.textContent || '—';
    const rainfall = document.getElementById('detailRainfall')?.textContent || '—';
    const windSpeed = document.getElementById('detailWindSpeed')?.textContent || '—';
    const humidity = document.getElementById('detailHumidity')?.textContent || '—';
    const pressure = document.getElementById('detailPressure')?.textContent || '—';
    const condition = document.getElementById('modalConditionText')?.textContent || '—';
    const comfort = document.getElementById('statComfort')?.textContent || '—';
    const advice = document.getElementById('adviceText')?.textContent || '—';

    const message = `What should I prepare for the weather on ${dayName} (${dayDate})? `
        + `Here is the actual forecast data: Condition: ${condition}, High: ${tempHigh}°C, `
        + `Rain probability: ${rainProb}%, Expected rainfall: ${rainfall}, Wind: ${windSpeed}, `
        + `Humidity: ${humidity}, Pressure: ${pressure}, Comfort score: ${comfort}/10. `
        + `Advisory: ${advice}. Give me specific and practical advice based on these exact conditions.`;

    closeForecastModal();
    document.getElementById('chatBubbleWindow').classList.add('tt-chat-window--open');
    setTimeout(() => askQuestion(message), 300);
}

/* ══════════════════════════════════════════════════════════
   CHAT
══════════════════════════════════════════════════════════ */
function toggleChat(){document.getElementById('chatBubbleWindow').classList.toggle('tt-chat-window--open');}
function clearChatHistory(){document.getElementById('clearChatModal').classList.add('tt-modal--open');}
function closeClearChatModal(){document.getElementById('clearChatModal').classList.remove('tt-modal--open');}
function confirmClearChat(){APP.chatHistory=[];document.getElementById('chatContainer').innerHTML='';closeClearChatModal();addChatMessage('bot','🗑️ Chat history cleared. How can I help you today?');}

function addChatMessage(role, text) {
    const c=document.getElementById('chatContainer');
    const d=document.createElement('div'); d.className=`tt-msg tt-msg--${role==='user'?'user':'bot'}`;
    const time=new Date().toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit'});
    const avatar=role==='user'?'<div class="tt-msg__avatar"><i class="fas fa-user"></i></div>':'<div class="tt-msg__avatar"><i class="fas fa-robot"></i></div>';
    const fmt=text.replace(/\n/g,'<br>').replace(/\*\*(.*?)\*\*/g,'<strong>$1</strong>');
    d.innerHTML=`${avatar}<div><div class="tt-msg__bubble">${fmt}</div><div class="tt-msg__time">${time}</div></div>`;
    c.appendChild(d); c.scrollTop=c.scrollHeight;
}
function showTyping(){const c=document.getElementById('chatContainer');const d=document.createElement('div');d.className='tt-msg tt-msg--bot';d.id='typingIndicator';d.innerHTML='<div class="tt-msg__avatar"><i class="fas fa-robot"></i></div><div class="tt-typing"><span></span><span></span><span></span></div>';c.appendChild(d);c.scrollTop=c.scrollHeight;}
function hideTyping(){const el=document.getElementById('typingIndicator');if(el)el.remove();}

async function sendMessage() {
    const input=document.getElementById('messageInput'),btn=document.getElementById('sendBtn');
    const msg=input.value.trim(); if(!msg)return;
    input.value=''; btn.disabled=true;
    addChatMessage('user',msg); APP.chatHistory.push({role:'user',content:msg});
    showTyping(); document.getElementById('chatStatus').textContent='Thinking…';
    try {
        const res=await fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({message:msg,weatherData:APP.weatherData,typhoonData:APP.typhoonData,userLocation:APP.userLocation,currentDateTime:new Date().toLocaleString('en-PH'),session_id:APP.sessionId})});
        const data=await res.json(); hideTyping();
        const reply=data.response||'Sorry, I could not get a response.';
        addChatMessage('bot',reply); APP.chatHistory.push({role:'assistant',content:reply});
        const found=extractLocationFromText(msg);
        if(found)flyMapToLocation(found.name,found.coords);
    } catch(e) {
        hideTyping(); addChatMessage('bot','⚠️ Connection error. Please check your internet and try again.');
    }
    btn.disabled=false; document.getElementById('chatStatus').textContent='Online & Ready';
}

function askQuestion(q){document.getElementById('messageInput').value=q;sendMessage();}

document.addEventListener('keydown',e=>{
    if(e.key==='Escape'){document.querySelectorAll('.tt-modal--open').forEach(m=>m.classList.remove('tt-modal--open'));document.getElementById('chatBubbleWindow').classList.remove('tt-chat-window--open');}
});

document.addEventListener('DOMContentLoaded', boot);

(function gpsReEnablePatch() {
    const LS_KEY = 'tt_gps_v4';

    // After DOM is ready, watch for the location bar and inject the button
    function injectEnableButton() {
        // If already allowed/active, do nothing
        if (localStorage.getItem(LS_KEY) === 'allow') return;

        const targets = [
            document.getElementById('userLocation'),
            document.querySelector('.tt-location span'),
            document.querySelector('#ttLbText'),
        ].filter(Boolean);

        if (!targets.length) {
            setTimeout(injectEnableButton, 400);
            return;
        }

        // Don't add duplicate buttons
        if (document.getElementById('gpsEnableBtn')) return;

        const btn = document.createElement('button');
        btn.id = 'gpsEnableBtn';
        btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Enable GPS';
        btn.style.cssText = `
            margin-left: 10px;
            padding: 3px 11px;
            background: linear-gradient(135deg, #0d1b36, #1c3461);
            color: #fff;
            border: none;
            border-radius: 20px;
            font-family: 'Sora', sans-serif;
            font-size: 10px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all .18s;
            vertical-align: middle;
        `;
        btn.onmouseenter = () => btn.style.transform = 'translateY(-1px)';
        btn.onmouseleave = () => btn.style.transform = '';

        btn.onclick = function () {
            // Reset stored choice so modal shows fresh
            localStorage.removeItem(LS_KEY);
            window._locationDenied = false;
            btn.remove();

            // If the patch's modal function exists, re-run it
            if (typeof window.decideAction === 'function') {
                window.decideAction();
                return;
            }

            // Fallback: show the modal directly
            const modal = document.getElementById('ttGpsModal');
            if (modal) {
                modal.classList.add('open');
                return;
            }

            // Last resort: call GPS directly
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(
                    pos => {
                        if (typeof window.applyGPS === 'function') {
                            window.applyGPS(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy);
                        } else if (window.APP) {
                            window.APP.userLat = pos.coords.latitude;
                            window.APP.userLon = pos.coords.longitude;
                            if (typeof updateMapMarkers === 'function') updateMapMarkers();
                        }
                    },
                    err => alert('GPS error: ' + err.message),
                    { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 }
                );
            }
        };

        // Insert after the first target element
        const anchor = targets[0];
        anchor.parentNode.insertBefore(btn, anchor.nextSibling);
    }

    // Run after everything else loads
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => setTimeout(injectEnableButton, 800));
    } else {
        setTimeout(injectEnableButton, 800);
    }
})();

</script>
<script src="typhoon_live_patch.js"></script>
<script src="typhoon_hourly_patch.js"></script>
<script src="typhoon_location_patch.js"></script>

</body>
</html>