<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';

requireLogin();
$user_id = getCurrentUserId();
$user_role = getCurrentUserRole();
if (!in_array($user_role,['Admin','Super Admin','Staff','Treasurer'])) { header('Location: ../dashboard/index.php'); exit(); }

$page_title = 'Document Requests Management';

// Handle status update
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_status'])) {
    $request_id=intval($_POST['request_id']); $status=sanitizeInput($_POST['status']); $remarks=isset($_POST['remarks'])?sanitizeInput($_POST['remarks']):'';
    $req_stmt=$conn->prepare("SELECT r.*,rt.request_type_name,res.first_name,res.last_name,u.user_id FROM tbl_requests r JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id JOIN tbl_residents res ON r.resident_id=res.resident_id JOIN tbl_users u ON res.resident_id=u.resident_id WHERE r.request_id=?");
    $req_stmt->bind_param("i",$request_id); $req_stmt->execute(); $rd=$req_stmt->get_result()->fetch_assoc(); $req_stmt->close();
    if ($rd) { $stmt=$conn->prepare("UPDATE tbl_requests SET status=?,processed_by=?,processed_date=NOW() WHERE request_id=?"); $stmt->bind_param("sii",$status,$user_id,$request_id); if($stmt->execute()){$nm="Request Status Updated";$msg="Your request for {$rd['request_type_name']} is now {$status}";if(!empty($remarks))$msg.=". Remarks: {$remarks}";$nt="request_".strtolower($status);$ns=$conn->prepare("INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at) VALUES(?,?,?,?,?,?,0,NOW())");$ns->bind_param("ississ",$rd['user_id'],$nt,'request',$request_id,$nm,$msg);$ns->execute();$ns->close();$_SESSION['success_message']='Request status updated successfully!';}else{$_SESSION['error_message']='Failed to update request status.';}$stmt->close();}else{$_SESSION['error_message']='Request not found.';}
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=manage'); exit();
}

// Handle payment update
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_payment'])) {
    $request_id=intval($_POST['request_id']); $payment_status=intval($_POST['payment_status']);
    $req_stmt=$conn->prepare("SELECT r.*,rt.request_type_name,rt.fee,res.first_name,res.last_name,u.user_id FROM tbl_requests r JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id JOIN tbl_residents res ON r.resident_id=res.resident_id JOIN tbl_users u ON res.resident_id=u.resident_id WHERE r.request_id=?");
    $req_stmt->bind_param("i",$request_id); $req_stmt->execute(); $rd=$req_stmt->get_result()->fetch_assoc(); $req_stmt->close();
    if ($rd) {
        $cur_stmt=$conn->prepare("SELECT payment_status FROM tbl_requests WHERE request_id=?"); $cur_stmt->bind_param("i",$request_id); $cur_stmt->execute(); $cd=$cur_stmt->get_result()->fetch_assoc(); $cur_stmt->close(); $was_paid=($cd&&$cd['payment_status']==1);
        $stmt=$conn->prepare("UPDATE tbl_requests SET payment_status=? WHERE request_id=?"); $stmt->bind_param("ii",$payment_status,$request_id);
        if ($stmt->execute()) {
            if ($payment_status==1&&!$was_paid&&$rd['fee']>0) {
                $tc=$conn->query("SHOW TABLES LIKE 'tbl_revenues'");
                if ($tc&&$tc->num_rows>0) {
                    $cid=null; $cr=$conn->query("SELECT category_id FROM tbl_revenue_categories WHERE category_name='Document Fees' LIMIT 1");
                    if ($cr&&$cr->num_rows>0) $cid=$cr->fetch_assoc()['category_id']; else { $conn->query("INSERT INTO tbl_revenue_categories (category_name,description,is_active) VALUES('Document Fees','Revenue from document requests',1)"); $cid=$conn->insert_id; }
                    if ($cid) { $ref='REV-'.date('Ymd').'-'.str_pad($request_id,6,'0',STR_PAD_LEFT); $rn=trim($rd['first_name'].' '.$rd['last_name']); $src=$rn.' – '.$rd['request_type_name']; $desc="Payment for {$rd['request_type_name']} (Request #{$request_id})"; $fee=(float)$rd['fee']; $rs=$conn->prepare("INSERT INTO tbl_revenues (reference_number,category_id,source,amount,description,transaction_date,payment_method,received_by,verified_by,verification_date,status,created_at) VALUES(?,?,?,?,?,NOW(),'Cash',?,?,NOW(),'Verified',NOW())"); if($rs){$rs->bind_param("sisssdii",$ref,$cid,$src,$fee,$desc,$user_id,$user_id);if($rs->execute()){$bs=$conn->prepare("UPDATE tbl_fund_balance SET current_balance=current_balance+?,updated_by=?,last_updated=NOW() ORDER BY balance_id DESC LIMIT 1");if($bs){$bs->bind_param("di",$fee,$user_id);$bs->execute();$bs->close();}$nm="Payment Confirmed";$msg="Your payment of ₱".number_format($fee,2)." for {$rd['request_type_name']} has been confirmed. Reference: {$ref}";$ns=$conn->prepare("INSERT INTO tbl_notifications (user_id,type,reference_type,reference_id,title,message,is_read,created_at) VALUES(?,?,?,?,?,?,0,NOW())");$ns->bind_param("ississ",$rd['user_id'],'payment_confirmed','request',$request_id,$nm,$msg);$ns->execute();$ns->close();$_SESSION['success_message']="Payment confirmed! Revenue verified automatically. Reference: {$ref}";}else{$_SESSION['success_message']='Payment updated but failed to create revenue: '.$rs->error;}$rs->close();}}
                } else { $_SESSION['success_message']='Payment status updated successfully!'; }
            } else { $_SESSION['success_message']='Payment status updated successfully!'; }
        } else { $_SESSION['error_message']='Failed to update payment status.'; }
        $stmt->close();
    } else { $_SESSION['error_message']='Request not found.'; }
    header('Location: '.$_SERVER['PHP_SELF'].'?tab=manage'); exit();
}

$active_tab   = isset($_GET['tab'])         ? $_GET['tab']             : 'manage';
$search       = isset($_GET['search'])      ? sanitizeInput($_GET['search']) : '';
$date_from    = isset($_GET['date_from'])   ? $_GET['date_from']       : date('Y-m-01');
$date_to      = isset($_GET['date_to'])     ? $_GET['date_to']         : date('Y-m-d');
$rt_filter    = isset($_GET['request_type'])? intval($_GET['request_type']): 0;
$status_filter= isset($_GET['status'])      ? $_GET['status']          : '';

$stats=$conn->query("SELECT COUNT(*) as total, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status='Released' THEN 1 ELSE 0 END) as released, SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN payment_status=1 THEN 1 ELSE 0 END) as paid FROM tbl_requests")->fetch_assoc();

$request_types=[];
$rtr=$conn->query("SELECT * FROM tbl_request_types ORDER BY request_type_name");
if ($rtr) while($r=$rtr->fetch_assoc()) $request_types[]=$r;

$msql="SELECT r.*,res.first_name,res.last_name,res.email,rt.request_type_name,rt.fee,u.username as processed_by_name FROM tbl_requests r INNER JOIN tbl_residents res ON r.resident_id=res.resident_id LEFT JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id LEFT JOIN tbl_users u ON r.processed_by=u.user_id WHERE 1=1";
$mp=[]; $mt='';
if ($status_filter&&$status_filter!=='Paid') { $msql.=" AND r.status=?"; $mp[]=$status_filter; $mt.='s'; }
if ($status_filter==='Paid') $msql.=" AND r.payment_status=1";
if ($search) { $msql.=" AND (res.first_name LIKE ? OR res.last_name LIKE ? OR rt.request_type_name LIKE ?)"; $sp="%{$search}%"; $mp[]=$sp;$mp[]=$sp;$mp[]=$sp; $mt.='sss'; }
$msql.=" ORDER BY CASE r.status WHEN 'Pending' THEN 1 WHEN 'Approved' THEN 2 WHEN 'Released' THEN 3 WHEN 'Rejected' THEN 4 END, r.request_date DESC";
if (!empty($mp)) { $ms=$conn->prepare($msql); $ms->bind_param($mt,...$mp); $ms->execute(); $manage_requests=$ms->get_result(); }
else $manage_requests=$conn->query($msql);

$stmt=$conn->prepare("SELECT COUNT(*) as total_requests, SUM(CASE WHEN status='Pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status='Approved' THEN 1 ELSE 0 END) as approved, SUM(CASE WHEN status='Released' THEN 1 ELSE 0 END) as released, SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rejected, SUM(CASE WHEN payment_status=1 THEN 1 ELSE 0 END) as paid_requests, SUM(CASE WHEN payment_status=1 THEN rt.fee ELSE 0 END) as total_revenue FROM tbl_requests r LEFT JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id WHERE DATE(request_date) BETWEEN ? AND ?");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $report_stats=$stmt->get_result()->fetch_assoc(); $stmt->close();

$stmt=$conn->prepare("SELECT rt.request_type_name,COUNT(*) as count FROM tbl_requests r LEFT JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id WHERE DATE(r.request_date) BETWEEN ? AND ? GROUP BY r.request_type_id,rt.request_type_name ORDER BY count DESC");
$stmt->bind_param("ss",$date_from,$date_to); $stmt->execute(); $type_distribution=$stmt->get_result(); $stmt->close();

$dsql="SELECT r.*,res.first_name,res.last_name,rt.request_type_name,rt.fee FROM tbl_requests r INNER JOIN tbl_residents res ON r.resident_id=res.resident_id LEFT JOIN tbl_request_types rt ON r.request_type_id=rt.request_type_id WHERE DATE(r.request_date) BETWEEN ? AND ?";
$dp=[$date_from,$date_to]; $dt='ss';
if ($active_tab==='reports'&&$status_filter) { $dsql.=" AND r.status=?"; $dp[]=$status_filter; $dt.='s'; }
if ($active_tab==='reports'&&$rt_filter)     { $dsql.=" AND r.request_type_id=?"; $dp[]=$rt_filter; $dt.='i'; }
$dsql.=" ORDER BY r.request_date DESC";
$stmt=$conn->prepare($dsql); $stmt->bind_param($dt,...$dp); $stmt->execute(); $report_requests=$stmt->get_result(); $stmt->close();

include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;--db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;--db-teal:#0d9488;--db-teal-light:#ccfbf1;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-sky:#0ea5e9;--db-sky-light:#e0f2fe;--db-indigo:#6366f1;--db-indigo-light:#e0e7ff;--db-success:#10b981;--db-success-light:#d1fae5;--db-warning:#f59e0b;--db-warning-light:#fef3c7;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-info:#3b82f6;--db-info-light:#dbeafe;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);--db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);}
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
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert--info{background:var(--db-info-light);color:#1e40af;border-color:var(--db-info);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 130px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s,border-color .2s;text-decoration:none;color:inherit;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);color:inherit;}
.db-stat-card.active{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.15),var(--db-shadow-lg);transform:translateY(-3px);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}
.db-tab-nav{display:flex;gap:0;border-bottom:2px solid var(--db-border);margin-bottom:20px;}
.db-tab-btn{padding:11px 20px;font-family:'Sora',sans-serif;font-size:13px;font-weight:600;color:var(--db-muted);background:none;border:none;border-bottom:3px solid transparent;cursor:pointer;text-decoration:none;transition:all .18s;display:inline-flex;align-items:center;gap:7px;margin-bottom:-2px;}
.db-tab-btn:hover{color:var(--db-navy);border-bottom-color:rgba(28,52,97,.2);}
.db-tab-btn.active{color:var(--db-navy);border-bottom-color:var(--db-navy);}
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr.req-row:hover{background:#f5f8ff;box-shadow:inset 3px 0 0 var(--db-teal);cursor:pointer;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-id{font-family:'DM Mono',monospace;font-size:11px;color:var(--db-indigo);font-weight:500;}
.db-text-sm{font-size:11.5px;color:var(--db-muted);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}
/* DB Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:480px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}
.db-notice{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:var(--db-radius-sm);font-size:12.5px;margin-top:14px;}
.db-notice--success{background:var(--db-success-light);color:#065f46;}
.db-notice--amber{background:var(--db-amber-light);color:#92400e;}
/* Reports section */
.db-mini-stats{display:flex;flex-wrap:wrap;gap:10px;margin-bottom:18px;}
.db-mini-stat{flex:1 1 120px;background:var(--db-surf);border-radius:var(--db-radius);padding:14px 16px;text-align:center;border:1px solid var(--db-border);box-shadow:var(--db-shadow);}
.db-mini-stat .num{font-size:24px;font-weight:800;letter-spacing:-1px;}
.db-mini-stat .lbl{font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-top:2px;}
.db-charts-row{display:flex;gap:18px;flex-wrap:wrap;margin-bottom:18px;}
.db-chart-card{flex:1 1 280px;background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);overflow:hidden;}
.db-chart-card__header{padding:16px 20px;border-bottom:1px solid var(--db-border);display:flex;align-items:center;gap:10px;}
.db-chart-card__header h3{font-size:14px;font-weight:700;}
.db-chart-card__body{padding:16px 20px;}
/* Hover preview */
.db-preview{position:fixed;z-index:9999;width:340px;background:var(--db-surf);border-radius:var(--db-radius);box-shadow:var(--db-shadow-lg);border:1px solid var(--db-border);overflow:hidden;pointer-events:none;animation:dbPrevIn .18s ease;}
@keyframes dbPrevIn{from{opacity:0;transform:translateY(6px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.db-preview__header{display:flex;align-items:center;gap:12px;padding:14px 16px 10px;border-bottom:1px solid #f0f0f0;}
.db-preview__icon{width:42px;height:42px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.db-preview__header-text{flex:1;min-width:0;}
.db-preview__type{font-family:'DM Mono',monospace;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--db-muted);margin-bottom:2px;}
.db-preview__title{font-size:.88rem;font-weight:700;color:var(--db-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.db-preview__body{padding:12px 16px 14px;}
.db-preview__row{display:flex;gap:8px;font-size:.8rem;margin-bottom:6px;}
.db-preview__label{color:var(--db-muted);font-weight:600;min-width:68px;flex-shrink:0;}
.db-preview__val{color:var(--db-text);}
.db-preview__footer{font-size:.72rem;color:#adb5bd;display:flex;align-items:center;gap:8px;margin-top:8px;padding-top:8px;border-top:1px solid #f0f0f0;}
@media print{.no-print{display:none !important}}
@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-preview{display:none !important;}}
/* ══════════════════════════════════════
   DARK MODE OVERRIDES
══════════════════════════════════════ */
body.dark-mode { background: #0f172a !important; color: #e2e8f0 !important; }

body.dark-mode .db-panel {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-panel__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-panel__title h2 {
    color: #f1f5f9 !important;
}
body.dark-mode .db-panel__icon--teal {
    background: #0d2e2a !important;
    color: #2dd4bf !important;
}
body.dark-mode .db-stat-card {
    background: #1e293b !important;
    border-color: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-stat-card:hover,
body.dark-mode .db-stat-card.active {
    box-shadow: 0 0 0 3px rgba(148,163,184,.15), var(--db-shadow-lg) !important;
}
body.dark-mode .db-stat-card__icon--teal {
    background: #0d2e2a !important;
    color: #2dd4bf !important;
}
body.dark-mode .db-stat-card__icon--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-stat-card__icon--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-stat-card__icon--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-stat-card__icon--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-stat-card__icon--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-stat-card__label {
    color: #64748b !important;
}
body.dark-mode .db-tab-nav {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-tab-btn {
    color: #64748b !important;
}
body.dark-mode .db-tab-btn:hover,
body.dark-mode .db-tab-btn.active {
    color: #f1f5f9 !important;
    border-bottom-color: #60a5fa !important;
}
body.dark-mode .db-input,
body.dark-mode .db-select {
    background: #334155 !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-input:focus,
body.dark-mode .db-select:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15) !important;
}
body.dark-mode .db-input option,
body.dark-mode .db-select option {
    background: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-filter-label {
    color: #94a3b8 !important;
}
body.dark-mode .db-table thead tr {
    background: linear-gradient(135deg, #0f172a, #1e293b) !important;
}
body.dark-mode .db-table thead th {
    color: rgba(148,163,184,.9) !important;
}
body.dark-mode .db-table tbody tr {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-table tbody tr.req-row:hover {
    background: #1e293b !important;
    box-shadow: inset 3px 0 0 #2dd4bf !important;
}
body.dark-mode .db-table tbody td {
    color: #e2e8f0 !important;
}
body.dark-mode .db-text-sm {
    color: #94a3b8 !important;
}
body.dark-mode .db-id {
    color: #a5b4fc !important;
}
body.dark-mode .db-badge--teal {
    background: #0d2e2a !important;
    color: #2dd4bf !important;
}
body.dark-mode .db-badge--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-badge--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-badge--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-badge--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-badge--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-badge--muted {
    background: #1e293b !important;
    color: #94a3b8 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-alert--success {
    background: #052e16 !important;
    color: #86efac !important;
    border-color: #4ade80 !important;
}
body.dark-mode .db-alert--error {
    background: #2d1c1c !important;
    color: #fca5a5 !important;
    border-color: #ef4444 !important;
}
body.dark-mode .db-alert--info {
    background: #0c2a40 !important;
    color: #93c5fd !important;
    border-color: #3b82f6 !important;
}
body.dark-mode .db-btn--ghost {
    background: #1e293b !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-btn--ghost:hover {
    background: #334155 !important;
}
body.dark-mode .db-empty i {
    color: #334155 !important;
}
body.dark-mode .db-empty p {
    color: #64748b !important;
}
/* Mini stats (reports tab) */
body.dark-mode .db-mini-stat {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-mini-stat .lbl {
    color: #64748b !important;
}
/* Chart cards (reports tab) */
body.dark-mode .db-chart-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-chart-card__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-chart-card__header h3 {
    color: #f1f5f9 !important;
}
/* Hover preview */
body.dark-mode .db-preview {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-preview__header {
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-preview__title {
    color: #f1f5f9 !important;
}
body.dark-mode .db-preview__type,
body.dark-mode .db-preview__msg,
body.dark-mode .db-preview__label {
    color: #94a3b8 !important;
}
body.dark-mode .db-preview__val {
    color: #e2e8f0 !important;
}
body.dark-mode .db-preview__footer {
    border-top-color: #334155 !important;
    color: #64748b !important;
}
/* Modals */
body.dark-mode .db-modal__box {
    background: #1e293b !important;
}
body.dark-mode .db-modal__body {
    background: #1e293b !important;
}
body.dark-mode .db-notice--success {
    background: #052e16 !important;
    color: #86efac !important;
}
body.dark-mode .db-notice--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-tasks"></i></div>
            <div>
                <div class="rm-hero__title">Document Requests Management</div>
                <div class="rm-hero__sub">Manage and analyze document requests</div>
            </div>
        </div>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php echo displayMessage(); ?>
<?php if (isset($_SESSION['success_message'])): ?><div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($_SESSION['success_message']); unset($_SESSION['success_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
<?php if (isset($_SESSION['error_message'])): ?><div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <a href="?tab=manage" class="db-stat-card <?php echo ($active_tab==='manage'&&!$status_filter)?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-tasks"></i></div>
        <div><div class="db-stat-card__num"><?php echo $stats['total']; ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </a>
    <a href="?tab=manage&status=Pending" class="db-stat-card <?php echo $status_filter==='Pending'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-clock"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $stats['pending']; ?></div><div class="db-stat-card__label">Pending</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </a>
    <a href="?tab=manage&status=Approved" class="db-stat-card <?php echo $status_filter==='Approved'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-sky)"><?php echo $stats['approved']; ?></div><div class="db-stat-card__label">Approved</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </a>
    <a href="?tab=manage&status=Released" class="db-stat-card <?php echo $status_filter==='Released'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-double"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $stats['released']; ?></div><div class="db-stat-card__label">Released</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </a>
    <a href="?tab=manage&status=Rejected" class="db-stat-card <?php echo $status_filter==='Rejected'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-times-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $stats['rejected']; ?></div><div class="db-stat-card__label">Rejected</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </a>
    <a href="?tab=manage&status=Paid" class="db-stat-card <?php echo $status_filter==='Paid'?'active':''; ?>">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-money-bill"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-indigo)"><?php echo $stats['paid']; ?></div><div class="db-stat-card__label">Paid</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </a>
</div>

<!-- Tab Nav -->
<div class="db-tab-nav no-print">
    <a href="?tab=manage<?php echo $status_filter?'&status='.urlencode($status_filter):''; ?>" class="db-tab-btn <?php echo $active_tab==='manage'?'active':''; ?>">
        <i class="fas fa-tasks"></i> Manage Requests
    </a>
    <a href="?tab=reports" class="db-tab-btn <?php echo $active_tab==='reports'?'active':''; ?>">
        <i class="fas fa-chart-bar"></i> Reports & Analytics
    </a>
</div>

<?php if ($active_tab === 'manage'): ?>
<!-- Search Filter -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div>
            <h2>Search Requests</h2>
        </div>
        <?php if ($status_filter): ?>
        <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:12px;color:var(--db-muted)">Filtering:</span>
            <?php $bc=['Pending'=>'amber','Approved'=>'sky','Released'=>'success','Rejected'=>'rose','Paid'=>'indigo']; $c=$bc[$status_filter]??'muted'; ?>
            <span class="db-badge db-badge--<?php echo $c; ?>"><?php echo htmlspecialchars($status_filter); ?></span>
            <a href="?tab=manage" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <input type="hidden" name="tab" value="manage">
            <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
            <div class="db-form-row">
                <div style="flex:1;min-width:200px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search by name or document type…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Search</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Requests Table -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div>
            <h2><?php echo $status_filter?htmlspecialchars($status_filter).' Requests':'All Requests'; ?></h2>
            <span class="db-badge db-badge--teal"><?php echo $manage_requests?$manage_requests->num_rows:0; ?></span>
        </div>
        <span class="db-text-sm"><i class="fas fa-info-circle"></i> Hover to preview · Click to open</span>
    </div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>ID</th><th>Date</th><th>Resident</th><th>Document Type</th><th>Purpose</th><th>Fee</th><th>Payment</th><th>Status</th></tr></thead>
            <tbody>
            <?php if ($manage_requests && $manage_requests->num_rows > 0):
                while ($req=$manage_requests->fetch_assoc()):
                    $icon='fa-file-alt'; $ic='muted';
                    if ($req['status']==='Pending')  {$icon='fa-clock';$ic='amber';}
                    if ($req['status']==='Approved') {$icon='fa-check-circle';$ic='sky';}
                    if ($req['status']==='Released') {$icon='fa-check-double';$ic='success';}
                    if ($req['status']==='Rejected') {$icon='fa-times-circle';$ic='rose';}
                    $fn=htmlspecialchars(($req['first_name']??'').' '.($req['last_name']??''));
                    $dt=htmlspecialchars($req['request_type_name']??'N/A');
                    $fee_txt=$req['fee']>0?'₱'.number_format($req['fee'],2):'Free';
                    $pay_txt=$req['payment_status']?'Paid':($req['fee']>0?'Unpaid':'N/A');
            ?>
            <tr class="req-row"
                data-url="view-request.php?id=<?php echo $req['request_id']; ?>"
                data-pt="<?php echo $dt; ?>" data-pm="<?php echo htmlspecialchars(mb_strimwidth($req['purpose']??'',0,110,'…')); ?>"
                data-pc="<?php echo $ic; ?>" data-picon="<?php echo $icon; ?>"
                data-ptype="<?php echo htmlspecialchars($req['status']); ?>"
                data-ptime="<?php echo htmlspecialchars(date('M j, Y g:i A',strtotime($req['request_date']))); ?>"
                data-pname="<?php echo $fn; ?>"
                data-pemail="<?php echo htmlspecialchars($req['email']??'N/A'); ?>"
                data-pfee="<?php echo htmlspecialchars($fee_txt); ?>"
                data-ppay="<?php echo htmlspecialchars($pay_txt); ?>">
                <td><span class="db-id">#<?php echo str_pad($req['request_id'],5,'0',STR_PAD_LEFT); ?></span></td>
                <td><span class="db-text-sm"><?php echo date('M d, Y',strtotime($req['request_date'])); ?></span></td>
                <td>
                    <strong><?php echo $fn; ?></strong>
                    <br><span class="db-text-sm"><?php echo htmlspecialchars($req['email']??'N/A'); ?></span>
                </td>
                <td><span class="db-badge db-badge--sky"><?php echo $dt; ?></span></td>
                <td><span class="db-text-sm"><?php $p=$req['purpose']??''; echo htmlspecialchars(strlen($p)>40?substr($p,0,40).'…':$p); ?></span></td>
                <td><?php if ($req['fee']>0): ?><strong style="color:var(--db-success)">₱<?php echo number_format($req['fee'],2); ?></strong><?php else: ?><span class="db-badge db-badge--muted">Free</span><?php endif; ?></td>
                <td><?php if ($req['payment_status']): ?><span class="db-badge db-badge--success"><i class="fas fa-check"></i> Paid</span><?php elseif($req['fee']>0): ?><span class="db-badge db-badge--amber">Unpaid</span><?php else: ?><span class="db-badge db-badge--muted">N/A</span><?php endif; ?></td>
                <td><?php
                    $sc=['Pending'=>'amber','Approved'=>'sky','Released'=>'success','Rejected'=>'rose'];
                    $si=['Pending'=>'clock','Approved'=>'check-circle','Released'=>'check-double','Rejected'=>'times-circle'];
                    $cls=$sc[$req['status']]??'muted'; $ico=$si[$req['status']]??'circle';
                    echo "<span class='db-badge db-badge--$cls'><i class='fas fa-$ico'></i> {$req['status']}</span>";
                ?></td>
            </tr>
            <?php endwhile; else: ?>
            <tr><td colspan="8"><div class="db-empty"><i class="fas fa-inbox"></i><p>No requests found</p><?php if($status_filter): ?><a href="?tab=manage" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a><?php endif; ?></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: /* Reports tab */ ?>

<!-- Report Filters -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-filter"></i></div>
            <h2>Report Filters</h2>
        </div>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <input type="hidden" name="tab" value="reports">
            <div class="db-form-row">
                <div><label class="db-filter-label">Date From</label><input type="date" name="date_from" class="db-input" value="<?php echo htmlspecialchars($date_from); ?>"></div>
                <div><label class="db-filter-label">Date To</label><input type="date" name="date_to" class="db-input" value="<?php echo htmlspecialchars($date_to); ?>"></div>
                <div>
                    <label class="db-filter-label">Status</label>
                    <select name="status" class="db-select">
                        <option value="">All Status</option>
                        <?php foreach(['Pending','Approved','Released','Rejected'] as $s): ?><option value="<?php echo $s; ?>" <?php echo $status_filter===$s?'selected':''; ?>><?php echo $s; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="db-filter-label">Request Type</label>
                    <select name="request_type" class="db-select">
                        <option value="0">All Types</option>
                        <?php foreach ($request_types as $t): ?><option value="<?php echo $t['request_type_id']; ?>" <?php echo $rt_filter==$t['request_type_id']?'selected':''; ?>><?php echo htmlspecialchars($t['request_type_name']); ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div style="padding-top:18px;"><button type="submit" class="db-btn db-btn--primary"><i class="fas fa-filter"></i> Filter</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Mini Stats -->
<div class="db-mini-stats">
    <div class="db-mini-stat"><div class="num"><?php echo $report_stats['total_requests']; ?></div><div class="lbl">Total</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-amber-dark)"><?php echo $report_stats['pending']; ?></div><div class="lbl">Pending</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-sky)"><?php echo $report_stats['approved']; ?></div><div class="lbl">Approved</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-success)"><?php echo $report_stats['released']; ?></div><div class="lbl">Released</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-indigo)"><?php echo $report_stats['paid_requests']; ?></div><div class="lbl">Paid</div></div>
    <div class="db-mini-stat"><div class="num" style="color:var(--db-success);font-size:18px;">₱<?php echo number_format($report_stats['total_revenue']??0,2); ?></div><div class="lbl">Revenue</div></div>
</div>

<!-- Charts -->
<div class="db-charts-row">
    <div class="db-chart-card">
        <div class="db-chart-card__header"><i class="fas fa-chart-pie" style="color:var(--db-teal)"></i><h3>Request Type Distribution</h3></div>
        <div class="db-chart-card__body">
            <canvas id="typeChart" style="max-height:240px;"></canvas>
            <table class="db-table" style="margin-top:14px;"><tbody>
            <?php if ($type_distribution && $type_distribution->num_rows > 0): $type_distribution->data_seek(0); while($r=$type_distribution->fetch_assoc()): ?>
                <tr><td><?php echo htmlspecialchars($r['request_type_name']??'Unknown'); ?></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $r['count']; ?></td></tr>
            <?php endwhile; else: ?><tr><td colspan="2" style="text-align:center;color:var(--db-muted)">No data</td></tr><?php endif; ?>
            </tbody></table>
        </div>
    </div>
    <div class="db-chart-card">
        <div class="db-chart-card__header"><i class="fas fa-chart-pie" style="color:var(--db-indigo)"></i><h3>Status Overview</h3></div>
        <div class="db-chart-card__body">
            <canvas id="statusChart" style="max-height:240px;"></canvas>
            <table class="db-table" style="margin-top:14px;"><tbody>
                <?php foreach(['Pending'=>['amber',$report_stats['pending']],'Approved'=>['sky',$report_stats['approved']],'Released'=>['success',$report_stats['released']],'Rejected'=>['rose',$report_stats['rejected']]] as $s=>[$c,$n]): ?>
                <tr><td><span class="db-badge db-badge--<?php echo $c; ?>"><?php echo $s; ?></span></td><td style="text-align:right;font-weight:700;font-family:'DM Mono',monospace"><?php echo $n; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table>
        </div>
    </div>
</div>

<!-- Summary + Export -->
<div class="db-panel no-print">
    <div class="db-panel__header">
        <div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-info-circle"></i></div><h2>Period Summary</h2></div>
    </div>
    <div class="db-panel__body" style="display:flex;gap:24px;flex-wrap:wrap;">
        <div style="flex:1;min-width:200px;">
            <p style="font-size:13px;margin-bottom:8px;"><strong>Date Range:</strong> <?php echo date('M d, Y',strtotime($date_from)).' – '.date('M d, Y',strtotime($date_to)); ?></p>
            <?php $d1=new DateTime($date_from);$d2=new DateTime($date_to);$days=$d1->diff($d2)->days+1; ?>
            <p style="font-size:13px;margin-bottom:8px;"><strong>Total Days:</strong> <?php echo $days; ?></p>
            <p style="font-size:13px;margin-bottom:8px;"><strong>Avg/Day:</strong> <?php echo $days>0?number_format($report_stats['total_requests']/$days,2):0; ?> requests</p>
            <p style="font-size:13px;"><strong>Revenue:</strong> <span style="color:var(--db-success);font-weight:700;">₱<?php echo number_format($report_stats['total_revenue']??0,2); ?></span></p>
        </div>
        <div style="display:flex;flex-direction:column;gap:8px;min-width:160px;">
            <button class="db-btn db-btn--primary" onclick="window.print()"><i class="fas fa-print"></i> Print Report</button>
            <button class="db-btn db-btn--success" onclick="exportToExcel()"><i class="fas fa-file-excel"></i> Export to Excel</button>
        </div>
    </div>
</div>

<!-- Detailed List -->
<div class="db-panel">
    <div class="db-panel__header"><div class="db-panel__title"><div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-list"></i></div><h2>Detailed Request List</h2></div></div>
    <div class="db-table-wrap">
        <table class="db-table">
            <thead><tr><th>Request ID</th><th>Date</th><th>Resident</th><th>Request Type</th><th>Purpose</th><th>Status</th><th>Payment</th></tr></thead>
            <tbody>
            <?php if ($report_requests->num_rows > 0): while($r=$report_requests->fetch_assoc()): $sc=['Pending'=>'amber','Approved'=>'sky','Released'=>'success','Rejected'=>'rose']; $cls=$sc[$r['status']]??'muted'; ?>
                <tr>
                    <td><span class="db-id">#<?php echo str_pad($r['request_id'],5,'0',STR_PAD_LEFT); ?></span></td>
                    <td><span class="db-text-sm"><?php echo date('M d, Y',strtotime($r['request_date'])); ?></span></td>
                    <td><?php echo htmlspecialchars($r['first_name'].' '.$r['last_name']); ?></td>
                    <td><?php echo htmlspecialchars($r['request_type_name']??'N/A'); ?></td>
                    <td><span class="db-text-sm"><?php echo htmlspecialchars(mb_strimwidth($r['purpose']??'',0,50,'…')); ?></span></td>
                    <td><span class="db-badge db-badge--<?php echo $cls; ?>"><?php echo $r['status']; ?></span></td>
                    <td><?php echo $r['payment_status']?"<span class='db-badge db-badge--success'><i class='fas fa-check'></i> Paid</span>":"<span class='db-badge db-badge--amber'>Unpaid</span>"; ?></td>
                </tr>
            <?php endwhile; else: ?><tr><td colspan="7"><div class="db-empty"><i class="fas fa-inbox"></i><p>No requests found</p></div></td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php endif; ?>
</div><!-- /padding -->

<!-- Hover Preview Card -->
<div id="dbPreview" class="db-preview" style="display:none;">
    <div class="db-preview__header">
        <div class="db-preview__icon" id="dbPrevIcon"><i class="fas fa-file-alt" id="dbPrevIconI"></i></div>
        <div class="db-preview__header-text">
            <div class="db-preview__type" id="dbPrevType"></div>
            <div class="db-preview__title" id="dbPrevTitle"></div>
        </div>
    </div>
    <div class="db-preview__body">
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-user"></i> Resident</span><span class="db-preview__val" id="dbPrevName"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-envelope"></i> Email</span><span class="db-preview__val" id="dbPrevEmail"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-align-left"></i> Purpose</span><span class="db-preview__val" id="dbPrevMsg"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-peso-sign"></i> Fee</span><span class="db-preview__val" id="dbPrevFee"></span></div>
        <div class="db-preview__row"><span class="db-preview__label"><i class="fas fa-credit-card"></i> Payment</span><span class="db-preview__val" id="dbPrevPay"></span></div>
        <div class="db-preview__footer"><i class="far fa-calendar-alt"></i><span id="dbPrevTime"></span></div>
    </div>
</div>

<!-- Status Modal -->
<div id="statusModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Update Request Status</h3>
            <button class="db-modal__close" onclick="closeModal('statusModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST" id="statusForm">
                <input type="hidden" name="request_id" id="status_request_id">
                <div style="margin-bottom:14px;">
                    <label class="db-filter-label">Status</label>
                    <select name="status" id="status_select" class="db-select" style="width:100%;">
                        <option value="Pending">Pending</option><option value="Approved">Approved</option>
                        <option value="Released">Released</option><option value="Rejected">Rejected</option>
                    </select>
                </div>
                <div style="margin-bottom:4px;">
                    <label class="db-filter-label">Remarks</label>
                    <textarea name="remarks" class="db-input" style="width:100%;height:80px;resize:vertical;" placeholder="Add remarks…"></textarea>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('statusModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" name="update_status" class="db-btn db-btn--primary"><i class="fas fa-check"></i> Update Status</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--amber">
            <h3><i class="fas fa-money-bill"></i> Update Payment Status</h3>
            <button class="db-modal__close" onclick="closeModal('paymentModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form method="POST" id="paymentForm">
                <input type="hidden" name="request_id" id="payment_request_id">
                <div style="margin-bottom:4px;">
                    <label class="db-filter-label">Payment Status</label>
                    <select name="payment_status" id="payment_status_select" class="db-select" style="width:100%;">
                        <option value="0">Unpaid</option><option value="1">Paid</option>
                    </select>
                </div>
                <div class="db-notice db-notice--success">
                    <i class="fas fa-check-circle" style="flex-shrink:0;margin-top:1px;"></i>
                    <span>When marked as <strong>Paid</strong>, revenue is automatically recorded as <strong>Verified</strong> in Revenue Management.</span>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('paymentModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" name="update_payment" class="db-btn db-btn--primary"><i class="fas fa-check"></i> Update Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});
function openStatusModal(id,status){document.getElementById('status_request_id').value=id;document.getElementById('status_select').value=status;openModal('statusModal');}
function openPaymentModal(id,paid){document.getElementById('payment_request_id').value=id;document.getElementById('payment_status_select').value=paid;openModal('paymentModal');}
function exportToExcel(){alert('Excel export can be implemented with PHPSpreadsheet.');}

(function(){
    const card=document.getElementById('dbPreview');
    if(!card)return;
    const iconBox=card.querySelector('#dbPrevIcon'),iconEl=card.querySelector('#dbPrevIconI');
    const cmap={amber:{bg:'rgba(245,158,11,.1)',text:'#b45309'},sky:{bg:'rgba(14,165,233,.1)',text:'#0ea5e9'},success:{bg:'rgba(16,185,129,.1)',text:'#10b981'},rose:{bg:'rgba(225,29,72,.1)',text:'#e11d48'},muted:{bg:'rgba(148,163,184,.1)',text:'#64748b'}};
    let timer;
    function pos(e){const cw=340,ch=card.offsetHeight||240,m=14;let x=e.clientX+m,y=e.clientY+m;if(x+cw>window.innerWidth-m)x=e.clientX-cw-m;if(y+ch>window.innerHeight-m)y=e.clientY-ch-m;card.style.left=x+'px';card.style.top=y+'px';}
    document.querySelectorAll('.req-row').forEach(row=>{
        row.addEventListener('mouseenter',function(e){clearTimeout(timer);const c=cmap[this.dataset.pc]||cmap.muted;document.getElementById('dbPrevTitle').textContent=this.dataset.pt;document.getElementById('dbPrevType').textContent=this.dataset.ptype;document.getElementById('dbPrevMsg').textContent=this.dataset.pm;document.getElementById('dbPrevTime').textContent=this.dataset.ptime;document.getElementById('dbPrevName').textContent=this.dataset.pname;document.getElementById('dbPrevEmail').textContent=this.dataset.pemail;document.getElementById('dbPrevFee').textContent=this.dataset.pfee;document.getElementById('dbPrevPay').textContent=this.dataset.ppay;iconEl.className='fas '+this.dataset.picon;iconBox.style.background=c.bg;iconEl.style.color=c.text;pos(e);card.style.display='block';});
        row.addEventListener('mousemove',pos);
        row.addEventListener('mouseleave',()=>{timer=setTimeout(()=>{if(!card.matches(':hover'))card.style.display='none';},150);});
        row.addEventListener('click',function(){if(this.dataset.url)location.href=this.dataset.url;});
    });
})();

setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);

<?php if ($active_tab==='reports'): ?>
document.addEventListener('DOMContentLoaded',function(){
    const tc=document.getElementById('typeChart');
    if(tc){new Chart(tc.getContext('2d'),{type:'doughnut',data:{labels:[<?php if($type_distribution&&$type_distribution->num_rows>0){$type_distribution->data_seek(0);while($r=$type_distribution->fetch_assoc())echo"'".addslashes($r['request_type_name']??'Unknown')."',";} ?>],datasets:[{data:[<?php if($type_distribution&&$type_distribution->num_rows>0){$type_distribution->data_seek(0);while($r=$type_distribution->fetch_assoc())echo$r['count'].',';} ?>],backgroundColor:['#0d9488','#f59e0b','#0ea5e9','#6366f1','#e11d48','#10b981','#3b82f6','#a855f7']}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{position:'bottom'}}}});}
    const sc=document.getElementById('statusChart');
    if(sc){new Chart(sc.getContext('2d'),{type:'pie',data:{labels:['Pending','Approved','Released','Rejected'],datasets:[{data:[<?php echo $report_stats['pending'].','.$report_stats['approved'].','.$report_stats['released'].','.$report_stats['rejected']; ?>],backgroundColor:['#f59e0b','#0ea5e9','#10b981','#e11d48']}]},options:{responsive:true,maintainAspectRatio:true,plugins:{legend:{position:'bottom'}}}});}
});
<?php endif; ?>
</script>

<?php
if (isset($ms)) $ms->close();
include '../../includes/footer.php';
?>