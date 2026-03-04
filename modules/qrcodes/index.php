<?php
/**
 * QR Code Management Module
 * Location: /modules/qrcodes/index.php
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/qr_helper.php';

requireAnyRole(['Admin', 'Super Admin', 'Staff']);

$page_title = "QR Code Management";

// Handle AJAX requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'];
    try {
        switch ($action) {
            case 'generate_single':
                $resident_id = intval($_POST['resident_id'] ?? 0);
                if ($resident_id <= 0) { echo json_encode(['success'=>false,'message'=>'Invalid resident ID']); exit; }
                $resident = fetchOne($conn,
                    "SELECT resident_id, CONCAT(first_name,' ',IFNULL(CONCAT(middle_name,' '),''),last_name) as full_name, address, contact_number as contact FROM tbl_residents WHERE resident_id=?",
                    [$resident_id],'i');
                if (!$resident) { echo json_encode(['success'=>false,'message'=>'Resident not found']); exit; }
                $qr_dir = UPLOAD_DIR.'qrcodes/';
                if (!file_exists($qr_dir)) { if (!mkdir($qr_dir,0777,true)) { echo json_encode(['success'=>false,'message'=>'Failed to create QR directory']); exit; } }
                if (!is_writable($qr_dir)) { echo json_encode(['success'=>false,'message'=>'QR directory not writable']); exit; }
                $qr_filename = generateResidentQRCode($resident_id,$resident,$qr_dir);
                if ($qr_filename) {
                    if (!file_exists($qr_dir.$qr_filename)) { echo json_encode(['success'=>false,'message'=>'QR file was not created']); exit; }
                    executeQuery($conn,"UPDATE tbl_residents SET qr_code=? WHERE resident_id=?",[$qr_filename,$resident_id],'si');
                    logActivity($conn,getCurrentUserId(),"Generated QR code for resident: {$resident['full_name']}",'tbl_residents',$resident_id);
                    echo json_encode(['success'=>true,'message'=>'QR code generated successfully','qr_code'=>$qr_filename,'qr_url'=>getQRCodeURL($qr_filename)]);
                } else { echo json_encode(['success'=>false,'message'=>'Failed to generate QR code.']); }
                exit;
            case 'batch_generate':
                $residents = fetchAll($conn,"SELECT resident_id, CONCAT(first_name,' ',IFNULL(CONCAT(middle_name,' '),''),last_name) as full_name, address, contact_number as contact FROM tbl_residents WHERE (qr_code IS NULL OR qr_code='')");
                if (empty($residents)) { echo json_encode(['success'=>true,'generated'=>0,'errors'=>[],'message'=>'All residents already have QR codes']); exit; }
                $generated=0; $errors=[]; $qr_dir=UPLOAD_DIR.'qrcodes/';
                if (!file_exists($qr_dir)) mkdir($qr_dir,0777,true);
                foreach ($residents as $r) {
                    $fn=generateResidentQRCode($r['resident_id'],$r,$qr_dir);
                    if ($fn) { executeQuery($conn,"UPDATE tbl_residents SET qr_code=? WHERE resident_id=?",[$fn,$r['resident_id']],'si'); $generated++; }
                    else $errors[]="Failed for: {$r['full_name']}";
                }
                logActivity($conn,getCurrentUserId(),"Batch generated QR codes for {$generated} residents",'tbl_residents',null);
                echo json_encode(['success'=>true,'generated'=>$generated,'errors'=>$errors,'message'=>"Generated {$generated} QR code".($generated!==1?'s':'')]);
                exit;
            default:
                echo json_encode(['success'=>false,'message'=>'Invalid action']); exit;
        }
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]); exit; }
}

include __DIR__ . '/../../includes/header.php';

try {
    $total_residents = fetchOne($conn,"SELECT COUNT(*) as count FROM tbl_residents");
    $with_qr         = fetchOne($conn,"SELECT COUNT(*) as count FROM tbl_residents WHERE qr_code IS NOT NULL AND qr_code!=''");
    $without_qr      = fetchOne($conn,"SELECT COUNT(*) as count FROM tbl_residents WHERE (qr_code IS NULL OR qr_code='')");
    $pct = $total_residents['count'] > 0 ? round($with_qr['count'] / $total_residents['count'] * 100) : 0;
} catch (Exception $e) {
    $total_residents = $with_qr = $without_qr = ['count'=>0]; $pct = 0;
}
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-teal),#0f766e);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--teal{background:linear-gradient(135deg,#0f766e,var(--db-teal));color:#fff;}
.db-btn--teal:hover{background:linear-gradient(135deg,var(--db-teal),#2dd4bf);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,148,136,.3);color:#fff;}
.db-btn--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--amber:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-input{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;width:100%;}
.db-input:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-search-wrap{position:relative;max-width:320px;}
.db-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--db-muted);}
.db-search-wrap input{padding-left:36px;}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-avatar{width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;background:linear-gradient(135deg,var(--db-teal),#0f766e);color:#fff;flex-shrink:0;overflow:hidden;}
.db-avatar img{width:100%;height:100%;object-fit:cover;}
.db-resident-info{display:flex;align-items:center;gap:10px;}
.db-resident-info h4{font-size:13px;font-weight:600;margin:0 0 2px;}
.db-resident-info p{font-size:11px;color:var(--db-muted);margin:0;}
.db-qr-thumb{width:56px;height:56px;border-radius:6px;border:2px solid var(--db-border);overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--db-surf2);}
.db-qr-thumb img{width:100%;height:100%;object-fit:cover;}
.db-qr-thumb--missing{color:var(--db-border);font-size:20px;}
.db-icon-btn{padding:6px 8px;border:none;background:transparent;color:var(--db-muted);cursor:pointer;border-radius:6px;transition:all .15s;font-size:13px;}
.db-icon-btn:hover{background:var(--db-surf2);color:var(--db-text);}
.db-icon-btn.teal:hover{background:var(--db-teal-light);color:var(--db-teal);}
.db-icon-btn.amber:hover{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-icon-btn.success:hover{background:var(--db-success-light);color:var(--db-success);}
.db-progress-bar{height:8px;background:var(--db-border);border-radius:4px;overflow:hidden;}
.db-progress-bar__fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--db-teal),#2dd4bf);transition:width .6s ease;}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:480px;overflow:hidden;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;}
.db-modal__header--teal{background:linear-gradient(135deg,#0f766e,var(--db-teal));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;text-align:center;}

/* Toast */
.db-toast{position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);font-weight:600;font-size:13px;max-width:360px;animation:dbToastIn .28s ease;}
@keyframes dbToastIn{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)}}
.db-toast--success{background:var(--db-success);color:#fff;}
.db-toast--error{background:var(--db-danger);color:#fff;}
.db-toast--info{background:var(--db-navy);color:#fff;}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-stats-row{gap:10px;}.db-modal{padding:10px;}}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-qrcode"></i></div>
            <div>
                <div class="rm-hero__title">QR Code Management</div>
                <div class="rm-hero__sub">Generate and manage resident QR codes &amp; ID cards</div>
            </div>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <a href="print_id_cards.php" target="_blank" class="db-btn db-btn--ghost" style="background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);"><i class="fas fa-print"></i> Print All IDs</a>
            <a href="../certificates/" class="db-btn db-btn--ghost" style="background:rgba(255,255,255,.12);color:#fff;border-color:rgba(255,255,255,.2);"><i class="fas fa-certificate"></i> Certificates</a>
            <button id="batchGenerateBtn" class="db-btn db-btn--teal"><i class="fas fa-magic"></i> Generate All QR Codes</button>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($total_residents['count']); ?></div><div class="db-stat-card__label">Total Residents</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-qrcode"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo number_format($with_qr['count']); ?></div><div class="db-stat-card__label">With QR Code</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo number_format($without_qr['count']); ?></div><div class="db-stat-card__label">Without QR Code</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-chart-pie"></i></div>
        <div>
            <div class="db-stat-card__num" style="color:var(--db-teal)"><?php echo $pct; ?>%</div>
            <div class="db-stat-card__label">Coverage</div>
            <div class="db-progress-bar" style="margin-top:4px;"><div class="db-progress-bar__fill" style="width:<?php echo $pct; ?>%"></div></div>
        </div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
</div>

<!-- Table Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div>
            <h2>Resident QR Codes</h2>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
            <select id="filterStatus" class="db-input" style="max-width:180px;padding:8px 12px;">
                <option value="">All Residents</option>
                <option value="with_qr">With QR Code</option>
                <option value="without_qr">Without QR Code</option>
            </select>
            <div class="db-search-wrap">
                <i class="fas fa-search"></i>
                <input type="text" class="db-input" id="searchInput" placeholder="Search residents…">
            </div>
        </div>
    </div>

    <div class="db-table-wrap">
        <table class="db-table" id="residentsTable">
            <thead><tr><th>Resident</th><th>Address</th><th>Contact</th><th>QR Code</th><th>Actions</th></tr></thead>
            <tbody>
            <?php
            try {
                $residents = fetchAll($conn,"SELECT resident_id, CONCAT(first_name,' ',IFNULL(CONCAT(middle_name,' '),''),last_name) as full_name, address, contact_number, qr_code, profile_photo FROM tbl_residents ORDER BY last_name,first_name");
                if (empty($residents)) { echo '<tr><td colspan="5"><div class="db-empty"><i class="fas fa-users"></i><p>No residents found</p></div></td></tr>'; }
                else foreach ($residents as $r):
                    $initials = strtoupper(substr($r['full_name'],0,1));
            ?>
            <tr data-resident-id="<?php echo $r['resident_id']; ?>" data-has-qr="<?php echo !empty($r['qr_code'])?'1':'0'; ?>">
                <td>
                    <div class="db-resident-info">
                        <div class="db-avatar">
                            <?php if (!empty($r['profile_photo'])): ?><img src="<?php echo UPLOAD_URL; ?>profiles/<?php echo htmlspecialchars($r['profile_photo']); ?>" alt=""><?php else: echo $initials; endif; ?>
                        </div>
                        <div>
                            <h4><?php echo htmlspecialchars($r['full_name']); ?></h4>
                            <p>ID #<?php echo $r['resident_id']; ?></p>
                        </div>
                    </div>
                </td>
                <td style="max-width:200px;"><span style="font-size:12px;color:var(--db-muted)"><?php echo htmlspecialchars($r['address']??'—'); ?></span></td>
                <td><span style="font-family:'DM Mono',monospace;font-size:12px"><?php echo htmlspecialchars($r['contact_number']??'—'); ?></span></td>
                <td class="qr-cell">
                    <?php if (!empty($r['qr_code'])): ?>
                    <div class="db-qr-thumb"><img src="<?php echo getQRCodeURL($r['qr_code']); ?>" alt="QR"></div>
                    <?php else: ?>
                    <span class="db-badge db-badge--amber"><i class="fas fa-exclamation-triangle"></i> Not Generated</span>
                    <?php endif; ?>
                </td>
                <td class="actions-cell">
                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                        <?php if (!empty($r['qr_code'])): ?>
                        <button class="db-icon-btn teal view-qr-btn" data-qr="<?php echo htmlspecialchars($r['qr_code']); ?>" data-name="<?php echo htmlspecialchars($r['full_name']); ?>" title="View QR Code"><i class="fas fa-eye"></i></button>
                        <a href="print_id_card.php?resident_id=<?php echo $r['resident_id']; ?>" class="db-icon-btn" target="_blank" title="Print ID Card"><i class="fas fa-print"></i></a>
                        <a href="<?php echo getQRCodeURL($r['qr_code']); ?>" class="db-icon-btn success" download title="Download QR Code"><i class="fas fa-download"></i></a>
                        <?php endif; ?>
                        <button class="db-icon-btn <?php echo empty($r['qr_code'])?'teal':'amber'; ?> generate-qr-btn" data-resident-id="<?php echo $r['resident_id']; ?>" title="<?php echo empty($r['qr_code'])?'Generate':'Regenerate'; ?> QR Code"><i class="fas fa-<?php echo empty($r['qr_code'])?'qrcode':'sync-alt'; ?>"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach;
            } catch (Exception $e) { echo '<tr><td colspan="5" style="color:var(--db-danger);padding:20px">Error: '.htmlspecialchars($e->getMessage()).'</td></tr>'; } ?>
            </tbody>
        </table>
    </div>
</div>
</div>

<!-- QR Preview Modal -->
<div id="qrPreviewModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-qrcode"></i> QR Code Preview</h3>
            <button class="db-modal__close" onclick="closeQRModal()">×</button>
        </div>
        <div class="db-modal__body" style="padding:28px;">
            <p id="qrPreviewName" style="font-weight:700;font-size:15px;margin:0 0 16px;"></p>
            <div style="display:inline-block;padding:12px;border:2px solid var(--db-border);border-radius:var(--db-radius);background:#fff;box-shadow:var(--db-shadow);">
                <img id="qrPreviewImage" src="" alt="QR Code" style="width:220px;height:220px;display:block;">
            </div>
            <div style="display:flex;gap:10px;justify-content:center;margin-top:20px;">
                <button class="db-btn db-btn--ghost" onclick="closeQRModal()"><i class="fas fa-times"></i> Close</button>
                <a id="downloadQrLink" href="" class="db-btn db-btn--success" download><i class="fas fa-download"></i> Download</a>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div id="toastContainer" style="position:fixed;bottom:24px;right:24px;z-index:99999;display:flex;flex-direction:column;gap:8px;"></div>

<script>
function showToast(msg, type='success') {
    const t=document.createElement('div');
    t.className='db-toast db-toast--'+type;
    const icons={success:'fa-check-circle',error:'fa-times-circle',info:'fa-info-circle'};
    t.innerHTML='<i class="fas '+(icons[type]||icons.info)+'"></i><span>'+msg+'</span>';
    document.getElementById('toastContainer').appendChild(t);
    setTimeout(()=>{t.style.opacity='0';t.style.transition='opacity .3s';setTimeout(()=>t.remove(),300);},3500);
}
function closeQRModal() { document.getElementById('qrPreviewModal').classList.remove('db-modal--open'); }
document.getElementById('qrPreviewModal').addEventListener('click',e=>{if(e.target===e.currentTarget)closeQRModal();});

function openQRPreview(qrCode, name) {
    const url='<?php echo UPLOAD_URL; ?>qrcodes/'+qrCode+'?t='+Date.now();
    document.getElementById('qrPreviewName').textContent=name;
    document.getElementById('qrPreviewImage').src=url;
    document.getElementById('downloadQrLink').href='<?php echo UPLOAD_URL; ?>qrcodes/'+qrCode;
    document.getElementById('qrPreviewModal').classList.add('db-modal--open');
}

function updateRow(residentId, qrCode, qrUrl) {
    const row=document.querySelector('tr[data-resident-id="'+residentId+'"]');
    if (!row) return;
    const name=row.querySelector('h4').textContent;
    row.dataset.hasQr='1';
    row.querySelector('.qr-cell').innerHTML='<div class="db-qr-thumb"><img src="'+qrUrl+'?t='+Date.now()+'" alt="QR"></div>';
    row.querySelector('.actions-cell').innerHTML=`
        <div style="display:flex;gap:4px;flex-wrap:wrap;">
            <button class="db-icon-btn teal view-qr-btn" data-qr="${qrCode}" data-name="${name}" title="View QR Code"><i class="fas fa-eye"></i></button>
            <a href="print_id_card.php?resident_id=${residentId}" class="db-icon-btn" target="_blank" title="Print ID Card"><i class="fas fa-print"></i></a>
            <a href="${qrUrl}" class="db-icon-btn success" download title="Download"><i class="fas fa-download"></i></a>
            <button class="db-icon-btn amber generate-qr-btn" data-resident-id="${residentId}" title="Regenerate QR Code"><i class="fas fa-sync-alt"></i></button>
        </div>`;
    attachRowListeners(row);
}

function attachRowListeners(container) {
    container.querySelectorAll('.view-qr-btn').forEach(b=>b.addEventListener('click',function(){openQRPreview(this.dataset.qr,this.dataset.name);}));
    container.querySelectorAll('.generate-qr-btn').forEach(b=>b.addEventListener('click',handleGenerate));
}

async function handleGenerate() {
    const rid=this.dataset.residentId, btn=this;
    if (!confirm('Generate QR code for this resident?')) return;
    const orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    try {
        const fd=new FormData(); fd.append('action','generate_single'); fd.append('resident_id',rid);
        const res=await fetch('',{method:'POST',body:fd});
        const data=await res.json();
        if (data.success) { updateRow(rid,data.qr_code,data.qr_url); showToast(data.message,'success'); }
        else { showToast(data.message,'error'); btn.disabled=false; btn.innerHTML=orig; }
    } catch(e) { showToast('Error generating QR code.','error'); btn.disabled=false; btn.innerHTML=orig; }
}

document.querySelectorAll('.generate-qr-btn').forEach(b=>b.addEventListener('click',handleGenerate));
document.querySelectorAll('.view-qr-btn').forEach(b=>b.addEventListener('click',function(){openQRPreview(this.dataset.qr,this.dataset.name);}));

document.getElementById('batchGenerateBtn').addEventListener('click',async function(){
    if (!confirm('Generate QR codes for all residents without one? This may take a while.')) return;
    const btn=this, orig=btn.innerHTML;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Generating…';
    try {
        const fd=new FormData(); fd.append('action','batch_generate');
        const res=await fetch('',{method:'POST',body:fd});
        const data=await res.json();
        if (data.success) { showToast(data.message+(data.errors.length?' ('+data.errors.length+' errors)':''),'success'); setTimeout(()=>location.reload(),1800); }
        else { showToast(data.message,'error'); }
    } catch(e) { showToast('Batch generation error.','error'); }
    finally { btn.disabled=false; btn.innerHTML=orig; }
});

document.getElementById('searchInput').addEventListener('keyup',function(){
    const f=this.value.toLowerCase();
    document.querySelectorAll('#residentsTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(f)?'':'none';});
});
document.getElementById('filterStatus').addEventListener('change',function(){
    const f=this.value;
    document.querySelectorAll('#residentsTable tbody tr').forEach(r=>{
        if (!f) { r.style.display=''; return; }
        const has=r.dataset.hasQr==='1';
        r.style.display=(f==='with_qr'&&has||f==='without_qr'&&!has)?'':'none';
    });
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>