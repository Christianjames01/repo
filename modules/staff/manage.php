<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';

requireLogin();
$user_role = getCurrentUserRole();
if ($user_role !== 'Admin' && $user_role !== 'Staff' && $user_role !== 'Super Admin') {
    header('Location: ../../modules/dashboard/index.php'); exit();
}

$page_title = 'Manage Staff';
$success_message = '';
$error_message = '';

$upload_dir = '../../uploads/profiles/';
if (!file_exists($upload_dir)) mkdir($upload_dir, 0755, true);

$check_status   = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'status'");   $has_status   = $check_status->num_rows > 0;
$check_active   = $conn->query("SHOW COLUMNS FROM tbl_users LIKE 'is_active'"); $has_is_active = $check_active->num_rows > 0;
$check_phone    = $conn->query("SHOW COLUMNS FROM tbl_residents LIKE 'phone'"); $has_phone     = $check_phone->num_rows > 0;
if (!$has_phone) { $conn->query("ALTER TABLE tbl_residents ADD COLUMN phone VARCHAR(20) AFTER email"); $has_phone = true; }
$check_photo = $conn->query("SHOW COLUMNS FROM tbl_residents LIKE 'profile_photo'");
if ($check_photo->num_rows == 0) $conn->query("ALTER TABLE tbl_residents ADD COLUMN profile_photo VARCHAR(255) AFTER phone");
$status_column = $has_status ? 'u.status' : ($has_is_active ? 'u.is_active' : "'active' as status");

$role_map = ['Admin'=>1,'Staff'=>6,'Secretary'=>3,'Treasurer'=>4,'Tanod'=>5,'Barangay Tanod'=>5,'Driver'=>20,'Barangay Captain'=>2];

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['action'])) {
    function handlePhotoUpload($upload_dir, &$error_message) {
        if (!isset($_FILES['profile_photo'])||$_FILES['profile_photo']['error']!==UPLOAD_ERR_OK) return null;
        $allowed=['image/jpeg','image/jpg','image/png','image/gif'];
        if (!in_array($_FILES['profile_photo']['type'],$allowed)||$_FILES['profile_photo']['size']>5242880){$error_message="Invalid photo format or size too large (max 5MB).";return false;}
        $ext=pathinfo($_FILES['profile_photo']['name'],PATHINFO_EXTENSION);
        $fn=uniqid().'_'.time().'.'.$ext;
        if (!move_uploaded_file($_FILES['profile_photo']['tmp_name'],$upload_dir.$fn)){$error_message="Failed to upload profile photo.";return false;}
        return $fn;
    }
    if ($_POST['action']==='add_staff') {
        if ($user_role!=='Super Admin'){$_SESSION['temp_error']='Only Super Admins can add new staff members.';header('Location: '.$_SERVER['PHP_SELF']);exit();}
        try {
            $username=$_POST['username'];$email=$_POST['email'];$password=$_POST['password'];
            $first_name=trim($_POST['first_name']);$middle_name=trim($_POST['middle_name']??'');$last_name=trim($_POST['last_name']);
            $ext=trim($_POST['ext']??'');$role=trim($_POST['role']);$phone=trim($_POST['phone']??'');
            $date_of_birth=$_POST['date_of_birth']??'';$gender=$_POST['gender']??'';$civil_status=$_POST['civil_status']??'';
            $occupation=trim($_POST['occupation']??'');$birthplace=trim($_POST['birthplace']??'');
            $permanent_address=trim($_POST['permanent_address']??'');$street=trim($_POST['street']??'');
            $barangay=trim($_POST['barangay']??'');$town=trim($_POST['town']??'');$province=trim($_POST['province']??'');
            $address=implode(', ',array_filter([$permanent_address,$street,$barangay,$town,$province]));
            $role_id=isset($role_map[$role])?$role_map[$role]:intval($_POST['role_id']??0);
            if (empty($username)||empty($email)||empty($password)||empty($first_name)||empty($last_name)||empty($role)) $error_message="All required fields must be filled in.";
            if (!$error_message&&!empty($date_of_birth)){$dob=new DateTime($date_of_birth);$now=new DateTime();if($now->diff($dob)->y<18)$error_message="Staff member must be at least 18 years old.";}
            if (!$error_message&&!empty($phone)){$cp=preg_replace('/[^0-9]/','', $phone);if(strlen($cp)!=11||substr($cp,0,2)!='09')$error_message="Contact number must be in format 09XXXXXXXXX";else $phone=$cp;}
            if (!$error_message){$photo_result=handlePhotoUpload($upload_dir,$error_message);$profile_photo=($photo_result===false)?null:$photo_result;}
            if (!$error_message){$stmt=$conn->prepare("SELECT user_id FROM tbl_users WHERE username=?");$stmt->bind_param("s",$username);$stmt->execute();$dup=$stmt->get_result()->num_rows>0;$stmt->close();if($dup)$error_message="Username already exists!";}
            if (!$error_message){$stmt=$conn->prepare("SELECT user_id FROM tbl_users WHERE email=?");$stmt->bind_param("s",$email);$stmt->execute();$dup=$stmt->get_result()->num_rows>0;$stmt->close();if($dup)$error_message="Email already exists!";}
            if (!$error_message){
                $conn->query("LOCK TABLES tbl_residents WRITE");
                $id_res=$conn->query("SELECT COALESCE(MAX(resident_id),0)+1 AS next_id FROM tbl_residents");
                if(!$id_res){$conn->query("UNLOCK TABLES");$error_message="Could not determine next resident_id.";}
            }
            if (!$error_message){
                $nid=(int)$id_res->fetch_assoc()['next_id'];
                $stmt=$conn->prepare("INSERT INTO tbl_residents (resident_id,first_name,middle_name,last_name,ext_name,date_of_birth,birthplace,gender,civil_status,address,permanent_address,street,barangay,town,province,contact_number,phone,email,occupation,profile_photo,status,is_verified,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())");
             $status_val = 'active';
$is_verified_val = 1;
$stmt->bind_param("issssssssssssssssssssi",
    $nid, $first_name, $middle_name, $last_name, $ext,
    $date_of_birth, $birthplace, $gender, $civil_status,
    $address, $permanent_address, $street, $barangay, $town,
    $province, $phone, $phone, $email, $occupation,
    $profile_photo, $status_val, $is_verified_val
);
                if ($stmt->execute()&&$stmt->affected_rows>0){
                    $resident_id=$nid;$stmt->close();$conn->query("UNLOCK TABLES");
                    if ($has_status&&$has_is_active){$us='active';$ia=1;$s=$conn->prepare("INSERT INTO tbl_users (username,email,password,role,role_id,status,is_active,resident_id) VALUES (?,?,?,?,?,?,?,?)");$s->bind_param("ssssisii",$username,$email,$password,$role,$role_id,$us,$ia,$resident_id);}
                    elseif($has_status){$us='active';$s=$conn->prepare("INSERT INTO tbl_users (username,email,password,role,role_id,status,resident_id) VALUES (?,?,?,?,?,?,?)");$s->bind_param("ssssisi",$username,$email,$password,$role,$role_id,$us,$resident_id);}
                    elseif($has_is_active){$ia=1;$s=$conn->prepare("INSERT INTO tbl_users (username,email,password,role,role_id,is_active,resident_id) VALUES (?,?,?,?,?,?,?)");$s->bind_param("ssssiii",$username,$email,$password,$role,$role_id,$ia,$resident_id);}
                    else{$s=$conn->prepare("INSERT INTO tbl_users (username,email,password,role,role_id,resident_id) VALUES (?,?,?,?,?,?)");$s->bind_param("ssssii",$username,$email,$password,$role,$role_id,$resident_id);}
                    if ($s->execute()) $success_message="Staff member added successfully!";
                    else $error_message="Error creating user account: ".$s->error;
                    $s->close();
                } else {$conn->query("UNLOCK TABLES");$error_message="Error creating resident record: ".$stmt->error;$stmt->close();}
            }
        } catch(Exception $e){$conn->query("UNLOCK TABLES");$error_message="Error: ".$e->getMessage();}
    }
    elseif ($_POST['action']==='edit_staff') {
        try {
            $user_id=intval($_POST['user_id']);$username=trim($_POST['username']);$email=trim($_POST['email']);
            $first_name=trim($_POST['first_name']);$last_name=trim($_POST['last_name']);
            $role=trim($_POST['role']??'');$phone=trim($_POST['phone']??'');$password=$_POST['password']??'';
            if (empty($username)||empty($email)||empty($first_name)||empty($last_name)) $error_message="All required fields must be filled in.";
            $profile_photo=null;$update_photo=false;
            if (!$error_message){$pr=handlePhotoUpload($upload_dir,$error_message);if($pr===false){}elseif($pr!==null){$profile_photo=$pr;$update_photo=true;$st=$conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.user_id=?");$st->bind_param("i",$user_id);$st->execute();$old=$st->get_result()->fetch_assoc();$st->close();if($old&&$old['profile_photo']&&file_exists($upload_dir.$old['profile_photo']))unlink($upload_dir.$old['profile_photo']);}}
            if (!$error_message){$st=$conn->prepare("SELECT user_id FROM tbl_users WHERE username=? AND user_id!=?");$st->bind_param("si",$username,$user_id);$st->execute();if($st->get_result()->num_rows>0)$error_message="Username already exists!";$st->close();}
            if (!$error_message){$st=$conn->prepare("SELECT user_id FROM tbl_users WHERE email=? AND user_id!=?");$st->bind_param("si",$email,$user_id);$st->execute();if($st->get_result()->num_rows>0)$error_message="Email already in use!";$st->close();}
            if (!$error_message){
                $st=$conn->prepare("SELECT resident_id,role,role_id FROM tbl_users WHERE user_id=?");$st->bind_param("i",$user_id);$st->execute();$cur=$st->get_result()->fetch_assoc();$st->close();
                $resident_id=$cur['resident_id']??null;
                if(empty($role)){$role=$cur['role'];$role_id=intval($cur['role_id']);}else{$role_id=isset($role_map[$role])?$role_map[$role]:intval($_POST['role_id']??$cur['role_id']);}
                if ($resident_id){if($update_photo){$st=$conn->prepare("UPDATE tbl_residents SET first_name=?,last_name=?,email=?,phone=?,profile_photo=? WHERE resident_id=?");$st->bind_param("sssssi",$first_name,$last_name,$email,$phone,$profile_photo,$resident_id);}else{$st=$conn->prepare("UPDATE tbl_residents SET first_name=?,last_name=?,email=?,phone=? WHERE resident_id=?");$st->bind_param("ssssi",$first_name,$last_name,$email,$phone,$resident_id);}if(!$st->execute())$error_message="Error updating resident.";$st->close();}
                if (!$error_message){if(!empty($password)){$st=$conn->prepare("UPDATE tbl_users SET username=?,email=?,password=?,role=?,role_id=? WHERE user_id=?");$st->bind_param("ssssii",$username,$email,$password,$role,$role_id,$user_id);}else{$st=$conn->prepare("UPDATE tbl_users SET username=?,email=?,role=?,role_id=? WHERE user_id=?");$st->bind_param("sssii",$username,$email,$role,$role_id,$user_id);}if($st->execute())$success_message="Staff member updated successfully!";else $error_message="Error updating user: ".$st->error;$st->close();}
            }
        } catch(Exception $e){$error_message="Error: ".$e->getMessage();}
    }
    elseif ($_POST['action']==='toggle_status') {
        if (!$has_status&&!$has_is_active){$error_message="Status toggle not supported.";}
        else{try{$user_id=intval($_POST['user_id']);$ns=$_POST['new_status'];if($has_status){$st=$conn->prepare("UPDATE tbl_users SET status=? WHERE user_id=?");$st->bind_param("si",$ns,$user_id);}else{$ia=($ns==='active')?1:0;$st=$conn->prepare("UPDATE tbl_users SET is_active=? WHERE user_id=?");$st->bind_param("ii",$ia,$user_id);}if($st->execute())$success_message=($ns==='active')?"Staff member activated!":"Staff member deactivated!";else $error_message="Error updating status.";$st->close();}catch(Exception $e){$error_message="Error: ".$e->getMessage();}}
    }
    elseif ($_POST['action']==='delete_staff') {
        try{$user_id=intval($_POST['user_id']);$st=$conn->prepare("SELECT r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.user_id=?");$st->bind_param("i",$user_id);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();if($row&&$row['profile_photo']&&file_exists($upload_dir.$row['profile_photo']))unlink($upload_dir.$row['profile_photo']);$st=$conn->prepare("DELETE FROM tbl_users WHERE user_id=?");$st->bind_param("i",$user_id);if($st->execute())$success_message="Staff member deleted!";else $error_message="Error deleting staff.";$st->close();}catch(Exception $e){$error_message="Error: ".$e->getMessage();}
    }
    $_SESSION['temp_success']=$success_message;$_SESSION['temp_error']=$error_message;
    header('Location: '.$_SERVER['PHP_SELF']);exit();
}
if (isset($_SESSION['temp_success'])){$success_message=$_SESSION['temp_success'];unset($_SESSION['temp_success']);}
if (isset($_SESSION['temp_error'])){$error_message=$_SESSION['temp_error'];unset($_SESSION['temp_error']);}

$staff_members=[];
$result=$conn->query("SELECT u.user_id,u.username,u.email,$status_column AS status,u.created_at,u.role,u.role_id,r.first_name,r.last_name,r.phone,r.profile_photo FROM tbl_users u LEFT JOIN tbl_residents r ON u.resident_id=r.resident_id WHERE u.role IN ('Admin','Staff','Tanod','Barangay Tanod','Driver','Barangay Captain','Secretary','Treasurer') ORDER BY u.role,r.last_name,r.first_name");
while ($row=$result->fetch_assoc()) $staff_members[]=$row;

// Stats
$total=$total_active=$total_inactive=0;
$role_counts=[];
foreach ($staff_members as $s){$total++;$active=($s['status']==='active'||$s['status']==1);if($active)$total_active++;else $total_inactive++;$role_counts[$s['role']]=($role_counts[$s['role']]??0)+1;}

include '../../includes/header.php';
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
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#4338ca,var(--db-indigo));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(99,102,241,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}
.db-stats-row{display:flex;flex-wrap:wrap;gap:14px;margin-bottom:24px;}
.db-stat-card{flex:1 1 160px;background:var(--db-surf);border-radius:var(--db-radius);padding:18px 16px 14px;display:flex;flex-direction:column;gap:10px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);}
.db-stat-card__icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:16px;}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__icon--success{background:var(--db-success-light);color:var(--db-success);}
.db-stat-card__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__num{font-size:28px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:11px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}
.db-stat-card__bar--success{background:linear-gradient(90deg,var(--db-success),transparent);}
.db-stat-card__bar--rose{background:linear-gradient(90deg,var(--db-rose),transparent);}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{background:linear-gradient(135deg,var(--db-navy-light),#2748a0);transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--danger{background:var(--db-danger);color:#fff;}
.db-btn--danger:hover{background:#dc2626;color:#fff;}
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--indigo{background:var(--db-indigo-light);color:#4338ca;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-input{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-search-wrap{position:relative;max-width:360px;}
.db-search-wrap i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--db-muted);}
.db-search-wrap input{padding-left:36px;width:100%;}
.db-table-wrap{overflow-x:auto;}
.db-table{width:100%;border-collapse:collapse;font-size:12.5px;}
.db-table thead tr{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-table thead th{color:rgba(255,255,255,.8);font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;padding:11px 16px;white-space:nowrap;border:none;}
.db-table tbody tr{border-bottom:1px solid var(--db-border);transition:background .12s;}
.db-table tbody tr:last-child{border-bottom:none;}
.db-table tbody tr:hover{background:#f5f8ff;}
.db-table tbody td{padding:11px 16px;vertical-align:middle;}
.db-staff-avatar{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;overflow:hidden;background:linear-gradient(135deg,var(--db-indigo),#4338ca);color:#fff;flex-shrink:0;}
.db-staff-avatar img{width:100%;height:100%;object-fit:cover;}
.db-staff-info{display:flex;align-items:center;gap:12px;}
.db-staff-info h4{font-size:13px;font-weight:600;margin:0 0 2px;}
.db-staff-info p{font-size:11px;color:var(--db-muted);margin:0;}
.db-icon-btn{padding:6px 8px;border:none;background:transparent;color:var(--db-muted);cursor:pointer;border-radius:6px;transition:all .15s;font-size:13px;}
.db-icon-btn:hover{background:var(--db-surf2);color:var(--db-text);}
.db-icon-btn.danger:hover{background:var(--db-danger-light);color:var(--db-danger);}
.db-icon-btn.warn:hover{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-icon-btn.success:hover{background:var(--db-success-light);color:var(--db-success);}
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* DB Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:700px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
.db-modal__box--sm{max-width:480px;}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;position:sticky;top:0;z-index:10;}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header--rose{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--success{background:linear-gradient(135deg,#065f46,var(--db-success));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

/* Form fields inside modal */
.db-section-title{color:var(--db-navy);font-size:.85rem;font-weight:700;margin:16px 0 10px;padding-bottom:6px;border-bottom:2px solid var(--db-border);display:flex;align-items:center;gap:7px;}
.db-form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.db-form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;}
.db-form-group{margin-bottom:12px;}
.db-form-group label{display:block;font-size:11px;font-weight:700;color:var(--db-muted);margin-bottom:5px;text-transform:uppercase;letter-spacing:.4px;}
.db-form-group select{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);appearance:none;outline:none;transition:border-color .18s;}
.db-form-group select:focus,.db-form-group .db-input:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-form-group .db-input{width:100%;}
.db-form-group textarea.db-input{resize:vertical;min-height:70px;}
.db-photo-upload{border:2px dashed var(--db-border);border-radius:var(--db-radius-sm);padding:20px;text-align:center;cursor:pointer;transition:all .2s;}
.db-photo-upload:hover{border-color:var(--db-navy-light);background:var(--db-surf2);}
.db-photo-preview{width:80px;height:80px;border-radius:50%;margin:0 auto 10px;overflow:hidden;display:none;box-shadow:var(--db-shadow);}
.db-photo-preview img{width:100%;height:100%;object-fit:cover;}
.db-required{color:var(--db-rose);}
.db-helper{font-size:11px;color:var(--db-muted);margin-top:4px;}

/* Delete/Status confirm */
.db-confirm-icon{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:28px;}
.db-confirm-icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-confirm-icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-confirm-icon--success{background:var(--db-success-light);color:var(--db-success);}

@media(max-width:768px){.rm-hero{padding:20px;border-radius:0;}.db-form-row,.db-form-row-3{grid-template-columns:1fr;}.db-table thead th,.db-table tbody td{padding:9px 10px;font-size:11.5px;}}
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
body.dark-mode .db-panel__icon--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-stat-card {
    background: #1e293b !important;
    border-color: #334155 !important;
}
body.dark-mode .db-stat-card__label {
    color: #64748b !important;
}
body.dark-mode .db-stat-card__icon--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-stat-card__icon--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-stat-card__icon--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-stat-card__icon--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-input {
    background: #334155 !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-input:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15) !important;
}
body.dark-mode .db-input option {
    background: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-search-wrap i {
    color: #64748b !important;
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
body.dark-mode .db-table tbody tr:hover {
    background: #243044 !important;
}
body.dark-mode .db-table tbody td {
    color: #e2e8f0 !important;
}
body.dark-mode .db-staff-info h4 {
    color: #f1f5f9 !important;
}
body.dark-mode .db-staff-info p {
    color: #94a3b8 !important;
}
body.dark-mode .db-badge--indigo {
    background: #1e1b4b !important;
    color: #a5b4fc !important;
}
body.dark-mode .db-badge--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-badge--sky {
    background: #0c2a40 !important;
    color: #38bdf8 !important;
}
body.dark-mode .db-badge--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-badge--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode .db-badge--teal {
    background: #0d2e2a !important;
    color: #2dd4bf !important;
}
body.dark-mode .db-badge--muted {
    background: #1e293b !important;
    color: #94a3b8 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-icon-btn {
    color: #94a3b8 !important;
}
body.dark-mode .db-icon-btn:hover {
    background: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-icon-btn.danger:hover {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-icon-btn.warn:hover {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-icon-btn.success:hover {
    background: #052e16 !important;
    color: #4ade80 !important;
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
/* Modals */
body.dark-mode .db-modal__box {
    background: #1e293b !important;
}
body.dark-mode .db-modal__body {
    background: #1e293b !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-section-title {
    color: #93c5fd !important;
    border-bottom-color: #334155 !important;
}
body.dark-mode .db-form-group label {
    color: #94a3b8 !important;
}
body.dark-mode .db-form-group select {
    background: #334155 !important;
    color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .db-form-group select:focus {
    border-color: #60a5fa !important;
    box-shadow: 0 0 0 3px rgba(96,165,250,.15) !important;
}
body.dark-mode .db-form-group select option {
    background: #334155 !important;
    color: #e2e8f0 !important;
}
body.dark-mode .db-photo-upload {
    border-color: #475569 !important;
    color: #94a3b8 !important;
}
body.dark-mode .db-photo-upload:hover {
    border-color: #60a5fa !important;
    background: #243044 !important;
}
body.dark-mode .db-helper {
    color: #64748b !important;
}
body.dark-mode .db-required {
    color: #fb7185 !important;
}
/* Delete / status confirm modals */
body.dark-mode .db-confirm-icon--rose {
    background: #2d1c1c !important;
    color: #fb7185 !important;
}
body.dark-mode .db-confirm-icon--amber {
    background: #27211a !important;
    color: #fbbf24 !important;
}
body.dark-mode .db-confirm-icon--success {
    background: #052e16 !important;
    color: #4ade80 !important;
}
body.dark-mode #delete_staff_name,
body.dark-mode #status_staff_name {
    color: #f1f5f9 !important;
}
body.dark-mode #status_question {
    color: #f1f5f9 !important;
}
body.dark-mode #status_action_text,
body.dark-mode #status_desc {
    color: #94a3b8 !important;
}
</style>

<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-users-cog"></i></div>
            <div>
                <div class="rm-hero__title">Manage Staff</div>
                <div class="rm-hero__sub">View and manage all staff members</div>
            </div>
        </div>
        <?php if ($user_role==='Super Admin'): ?>
        <button class="db-btn db-btn--primary" onclick="openModal('addStaffModal')"><i class="fas fa-plus"></i> Add Staff Member</button>
        <?php endif; ?>
    </div>
</div>

<div style="padding:0 24px 24px;">

<?php if ($success_message): ?><div class="db-alert db-alert--success"><i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>
<?php if ($error_message): ?><div class="db-alert db-alert--error"><i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?> <button class="db-alert__close" onclick="this.parentElement.remove()">×</button></div><?php endif; ?>

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-users"></i></div>
        <div><div class="db-stat-card__num"><?php echo $total; ?></div><div class="db-stat-card__label">Total Staff</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--success"><i class="fas fa-check-circle"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-success)"><?php echo $total_active; ?></div><div class="db-stat-card__label">Active</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--success"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--rose"><i class="fas fa-ban"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-rose)"><?php echo $total_inactive; ?></div><div class="db-stat-card__label">Inactive</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--rose"></div>
    </div>
    <?php foreach (array_slice($role_counts,0,3,true) as $role=>$count): ?>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-id-badge"></i></div>
        <div><div class="db-stat-card__num" style="color:var(--db-amber-dark)"><?php echo $count; ?></div><div class="db-stat-card__label"><?php echo htmlspecialchars($role); ?></div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Table Panel -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--indigo"><i class="fas fa-users-cog"></i></div>
            <h2>All Staff Members</h2>
            <span class="db-badge db-badge--indigo"><?php echo $total; ?></span>
        </div>
        <div class="db-search-wrap">
            <i class="fas fa-search"></i>
            <input type="text" class="db-input" id="searchInput" placeholder="Search staff…" onkeyup="searchTable()">
        </div>
    </div>

    <?php if (empty($staff_members)): ?>
    <div class="db-empty">
        <i class="fas fa-user-tie"></i>
        <p>No staff members yet</p>
        <?php if ($user_role==='Super Admin'): ?>
        <button class="db-btn db-btn--primary db-btn--sm" onclick="openModal('addStaffModal')"><i class="fas fa-plus"></i> Add Staff Member</button>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="db-table-wrap">
        <table class="db-table" id="staffTable">
            <thead><tr><th>Staff Member</th><th>Role</th><th>Contact</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($staff_members as $staff):
                $active=($staff['status']==='active'||$staff['status']==1);
                $f=!empty($staff['first_name'])?substr($staff['first_name'],0,1):substr($staff['username'],0,1);
                $l=!empty($staff['last_name'])?substr($staff['last_name'],0,1):'';
                $rn=$staff['role'];
                $rc=['Admin'=>'indigo','Staff'=>'sky','Tanod'=>'amber','Barangay Tanod'=>'amber','Driver'=>'teal','Barangay Captain'=>'rose','Secretary'=>'amber','Treasurer'=>'indigo'][$rn]??'muted';
            ?>
            <tr>
                <td>
                    <div class="db-staff-info">
                        <div class="db-staff-avatar">
                            <?php if (!empty($staff['profile_photo'])&&file_exists($upload_dir.$staff['profile_photo'])): ?>
                            <img src="<?php echo $upload_dir.htmlspecialchars($staff['profile_photo']); ?>" alt="Photo">
                            <?php else: echo strtoupper($f.$l); endif; ?>
                        </div>
                        <div>
                            <h4><?php echo htmlspecialchars(!empty($staff['first_name'])?$staff['first_name'].' '.$staff['last_name']:$staff['username']); ?></h4>
                            <p>@<?php echo htmlspecialchars($staff['username']); ?></p>
                        </div>
                    </div>
                </td>
                <td><span class="db-badge db-badge--<?php echo $rc; ?>"><?php echo htmlspecialchars($rn); ?></span></td>
                <td>
                    <div style="font-size:12px"><i class="fas fa-envelope" style="color:var(--db-muted);margin-right:4px"></i><?php echo htmlspecialchars($staff['email']); ?></div>
                    <?php if (!empty($staff['phone'])): ?><div style="font-size:11px;color:var(--db-muted);margin-top:2px"><i class="fas fa-phone" style="margin-right:4px"></i><?php echo htmlspecialchars($staff['phone']); ?></div><?php endif; ?>
                </td>
                <td><span class="db-badge <?php echo $active?'db-badge--success':'db-badge--rose'; ?>"><i class="fas fa-<?php echo $active?'check-circle':'times-circle'; ?>"></i> <?php echo $active?'Active':'Inactive'; ?></span></td>
                <td><span style="font-size:12px;color:var(--db-muted)"><i class="fas fa-calendar-alt" style="margin-right:4px"></i><?php echo date('M j, Y',strtotime($staff['created_at'])); ?></span></td>
                <td>
                    <div style="display:flex;gap:4px;">
                        <button class="db-icon-btn" onclick='editStaff(<?php echo json_encode($staff,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="Edit"><i class="fas fa-edit"></i></button>
                        <?php if ($has_status||$has_is_active): ?>
                        <button class="db-icon-btn <?php echo $active?'warn':'success'; ?>" onclick='openStatusModal(<?php echo json_encode($staff,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="<?php echo $active?'Deactivate':'Activate'; ?>"><i class="fas fa-<?php echo $active?'ban':'check'; ?>"></i></button>
                        <?php endif; ?>
                        <button class="db-icon-btn danger" onclick='openDeleteModal(<?php echo json_encode($staff,JSON_HEX_APOS|JSON_HEX_QUOT); ?>)' title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ADD STAFF MODAL -->
<div id="addStaffModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-user-plus"></i> Add New Staff Member</h3>
            <button class="db-modal__close" onclick="closeModal('addStaffModal')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data" onsubmit="return validateAddForm()">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="add_staff">

                <div class="db-section-title"><i class="fas fa-camera"></i> Profile Photo</div>
                <div class="db-form-group">
                    <div class="db-photo-upload" onclick="document.getElementById('add_photo').click()">
                        <div class="db-photo-preview" id="add_preview"></div>
                        <i class="fas fa-camera" style="font-size:22px;color:var(--db-muted);margin-bottom:6px;display:block"></i>
                        <p style="margin:0;font-size:12.5px;color:var(--db-muted)">Click to upload photo <span style="color:#94a3b8">(optional)</span></p>
                    </div>
                    <input type="file" name="profile_photo" id="add_photo" accept="image/*" style="display:none" onchange="previewPhoto(this,'add_preview')">
                </div>

                <div class="db-section-title"><i class="fas fa-user"></i> Personal Information</div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>First Name <span class="db-required">*</span></label><input type="text" name="first_name" class="db-input" required placeholder="Juan"></div>
                    <div class="db-form-group"><label>Last Name <span class="db-required">*</span></label><input type="text" name="last_name" class="db-input" required placeholder="dela Cruz"></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Middle Name</label><input type="text" name="middle_name" class="db-input" placeholder="(optional)"></div>
                    <div class="db-form-group"><label>Extension</label><input type="text" name="ext" class="db-input" placeholder="Jr., Sr."></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Date of Birth</label><input type="date" name="date_of_birth" class="db-input" max="<?php echo date('Y-m-d',strtotime('-18 years')); ?>"><p class="db-helper">Must be 18+</p></div>
                    <div class="db-form-group"><label>Gender</label><select name="gender" class="db-input"><option value="">Select</option><option>Male</option><option>Female</option></select></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Civil Status</label><select name="civil_status" class="db-input"><option value="">Select</option><option>Single</option><option>Married</option><option>Widowed</option><option>Separated</option></select></div>
                    <div class="db-form-group"><label>Occupation</label><input type="text" name="occupation" class="db-input" placeholder="(optional)"></div>
                </div>
                <div class="db-form-group"><label>Birthplace</label><input type="text" name="birthplace" class="db-input" placeholder="City, Province"></div>

                <div class="db-section-title"><i class="fas fa-map-marker-alt"></i> Address</div>
                <div class="db-form-group"><label>Permanent Address</label><input type="text" name="permanent_address" class="db-input" placeholder="House No., Street"></div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Street</label><input type="text" name="street" class="db-input"></div>
                    <div class="db-form-group"><label>Barangay</label><input type="text" name="barangay" class="db-input"></div>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Town/City</label><input type="text" name="town" class="db-input"></div>
                    <div class="db-form-group"><label>Province</label><input type="text" name="province" class="db-input"></div>
                </div>

                <div class="db-section-title"><i class="fas fa-phone"></i> Contact</div>
                <div class="db-form-group"><label>Mobile Number</label><input type="tel" name="phone" id="add_phone" class="db-input" placeholder="09XXXXXXXXX" maxlength="11"><p class="db-helper">Format: 09XXXXXXXXX</p></div>
                <div class="db-form-group"><label>Email <span class="db-required">*</span></label><input type="email" name="email" class="db-input" required placeholder="juan@example.com"></div>

                <div class="db-section-title"><i class="fas fa-id-badge"></i> Role & Account</div>
                <div class="db-form-group">
                    <label>Role <span class="db-required">*</span></label>
                    <select name="role" id="add_role" class="db-input" required>
                        <option value="">— Select Role —</option>
                        <option>Admin</option><option>Barangay Captain</option><option>Secretary</option>
                        <option>Treasurer</option><option>Barangay Tanod</option><option>Tanod</option>
                        <option>Staff</option><option>Driver</option>
                    </select>
                    <input type="hidden" name="role_id" id="add_role_id" value="0">
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Username <span class="db-required">*</span></label><input type="text" name="username" class="db-input" required minlength="5" maxlength="20" placeholder="juandelacruz"><p class="db-helper">5–20 chars</p></div>
                    <div class="db-form-group">
                        <label>Password <span class="db-required">*</span></label>
                        <div style="position:relative">
                            <input type="password" name="password" id="add_password" class="db-input" required minlength="8" style="padding-right:40px;width:100%">
                            <button type="button" onclick="togglePw('add_password','add_pw_eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--db-muted)"><i class="fas fa-eye" id="add_pw_eye"></i></button>
                        </div>
                        <p class="db-helper">Min 8 characters</p>
                    </div>
                </div>

                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('addStaffModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-user-plus"></i> Add Staff Member</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- EDIT STAFF MODAL -->
<div id="editStaffModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-user-edit"></i> Edit Staff Member</h3>
            <button class="db-modal__close" onclick="closeModal('editStaffModal')">×</button>
        </div>
        <form method="POST" enctype="multipart/form-data">
            <div class="db-modal__body">
                <input type="hidden" name="action" value="edit_staff">
                <input type="hidden" name="user_id" id="edit_user_id">
                <input type="hidden" name="role_id" id="edit_role_id" value="0">
                <div class="db-form-group">
                    <label style="font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.4px;">Profile Photo <span style="font-weight:400;color:#94a3b8;">(optional, leave blank to keep)</span></label>
                    <div class="db-photo-upload" onclick="document.getElementById('edit_photo').click()">
                        <div class="db-photo-preview" id="edit_preview"></div>
                        <i class="fas fa-camera" style="font-size:20px;color:var(--db-muted);margin-bottom:6px;display:block"></i>
                        <p style="margin:0;font-size:12.5px;color:var(--db-muted)">Click to change photo</p>
                    </div>
                    <input type="file" name="profile_photo" id="edit_photo" accept="image/*" style="display:none" onchange="previewPhoto(this,'edit_preview')">
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>First Name <span class="db-required">*</span></label><input type="text" name="first_name" id="edit_first_name" class="db-input" required></div>
                    <div class="db-form-group"><label>Last Name <span class="db-required">*</span></label><input type="text" name="last_name" id="edit_last_name" class="db-input" required></div>
                </div>
                <div class="db-form-group"><label>Email <span class="db-required">*</span></label><input type="email" name="email" id="edit_email" class="db-input" required></div>
                <div class="db-form-group"><label>Phone</label><input type="text" name="phone" id="edit_phone" class="db-input"></div>
                <div class="db-form-group">
                    <label>Role <span class="db-required">*</span></label>
                    <select name="role" id="edit_role" class="db-input" required onchange="syncRoleId('edit')">
                        <option value="">— Select Role —</option>
                        <option>Admin</option><option>Barangay Captain</option><option>Secretary</option>
                        <option>Treasurer</option><option>Barangay Tanod</option><option>Tanod</option>
                        <option>Staff</option><option>Driver</option>
                    </select>
                </div>
                <div class="db-form-row">
                    <div class="db-form-group"><label>Username <span class="db-required">*</span></label><input type="text" name="username" id="edit_username" class="db-input" required></div>
                    <div class="db-form-group">
                        <label>New Password <span style="font-weight:400;color:#94a3b8;">(optional)</span></label>
                        <div style="position:relative">
                            <input type="password" name="password" id="edit_password" class="db-input" style="padding-right:40px;width:100%" placeholder="Leave blank to keep">
                            <button type="button" onclick="togglePw('edit_password','edit_pw_eye')" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--db-muted)"><i class="fas fa-eye" id="edit_pw_eye"></i></button>
                        </div>
                    </div>
                </div>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('editStaffModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Update Staff</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- DELETE MODAL -->
<div id="deleteStaffModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-exclamation-triangle"></i> Delete Staff Member</h3>
            <button class="db-modal__close" onclick="closeModal('deleteStaffModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body" style="text-align:center;padding:28px 22px;">
                <input type="hidden" name="action" value="delete_staff">
                <input type="hidden" name="user_id" id="delete_user_id">
                <div class="db-confirm-icon db-confirm-icon--rose"><i class="fas fa-user-times"></i></div>
                <h3 style="font-size:16px;font-weight:700;margin-bottom:6px">Are you sure?</h3>
                <p style="color:var(--db-muted);margin:0">You are about to delete:</p>
                <p style="font-weight:700;margin:6px 0 10px" id="delete_staff_name"></p>
                <p style="color:var(--db-muted);font-size:12px">This action cannot be undone.</p>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('deleteStaffModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn db-btn--danger"><i class="fas fa-trash"></i> Delete</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- STATUS TOGGLE MODAL -->
<div id="statusToggleModal" class="db-modal">
    <div class="db-modal__box db-modal__box--sm">
        <div class="db-modal__header" id="status_modal_header">
            <h3 id="status_modal_title_h3"><i class="fas fa-toggle-on"></i> Change Status</h3>
            <button class="db-modal__close" onclick="closeModal('statusToggleModal')">×</button>
        </div>
        <form method="POST">
            <div class="db-modal__body" style="text-align:center;padding:28px 22px;">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="user_id" id="status_user_id">
                <input type="hidden" name="new_status" id="status_new_status">
                <div id="status_icon_div" class="db-confirm-icon"></div>
                <h3 style="font-size:16px;font-weight:700;margin-bottom:6px" id="status_question"></h3>
                <p style="color:var(--db-muted);margin:0" id="status_action_text"></p>
                <p style="font-weight:700;margin:6px 0 10px" id="status_staff_name"></p>
                <p style="color:var(--db-muted);font-size:12px" id="status_desc"></p>
                <div class="db-modal__footer">
                    <button type="button" class="db-btn db-btn--ghost" onclick="closeModal('statusToggleModal')"><i class="fas fa-times"></i> Cancel</button>
                    <button type="submit" class="db-btn" id="status_confirm_btn"><span id="status_btn_text">Confirm</span></button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
const ROLE_MAP={'Admin':1,'Barangay Captain':2,'Secretary':3,'Treasurer':4,'Tanod':5,'Barangay Tanod':5,'Staff':6,'Driver':20};
function openModal(id){document.getElementById(id).classList.add('db-modal--open');document.body.style.overflow='hidden';}
function closeModal(id){document.getElementById(id).classList.remove('db-modal--open');document.body.style.overflow='';if(id==='addStaffModal')resetPreview('add_preview','add_photo');if(id==='editStaffModal')resetPreview('edit_preview','edit_photo');}
function resetPreview(pid,iid){const p=document.getElementById(pid);if(p){p.style.display='none';p.innerHTML='';}const i=document.getElementById(iid);if(i)i.value='';}
function previewPhoto(input,previewId){const p=document.getElementById(previewId);if(!input.files||!input.files[0])return;const r=new FileReader();r.onload=e=>{p.innerHTML='<img src="'+e.target.result+'" alt="Preview">';p.style.display='block';};r.readAsDataURL(input.files[0]);}
function syncRoleId(prefix){const s=document.getElementById(prefix+'_role');const v=s?s.value:'';document.getElementById(prefix+'_role_id').value=ROLE_MAP[v]||0;}
document.getElementById('add_role').addEventListener('change',()=>syncRoleId('add'));
document.getElementById('add_phone').addEventListener('input',function(){this.value=this.value.replace(/[^0-9]/g,'');});
function togglePw(inputId,iconId){const i=document.getElementById(inputId),ic=document.getElementById(iconId);if(i.type==='password'){i.type='text';ic.className='fas fa-eye-slash';}else{i.type='password';ic.className='fas fa-eye';}}
function validateAddForm(){const role=document.getElementById('add_role').value;if(!role){alert('Please select a role.');return false;}syncRoleId('add');const phone=document.getElementById('add_phone').value;if(phone&&(phone.length!==11||!phone.startsWith('09'))){alert('Contact number must be 09XXXXXXXXX');return false;}return true;}
function editStaff(s){document.getElementById('edit_user_id').value=s.user_id;document.getElementById('edit_first_name').value=s.first_name||'';document.getElementById('edit_last_name').value=s.last_name||'';document.getElementById('edit_email').value=s.email||'';document.getElementById('edit_phone').value=s.phone||'';document.getElementById('edit_username').value=s.username||'';document.getElementById('edit_password').value='';document.getElementById('edit_role').value=s.role||'';document.getElementById('edit_role_id').value=ROLE_MAP[s.role]||s.role_id||0;const p=document.getElementById('edit_preview');if(s.profile_photo){p.innerHTML='<img src="../../uploads/profiles/'+s.profile_photo+'" alt="Photo">';p.style.display='block';}else{p.innerHTML='';p.style.display='none';}openModal('editStaffModal');}
function openDeleteModal(s){document.getElementById('delete_user_id').value=s.user_id;const name=(s.first_name&&s.last_name)?s.first_name+' '+s.last_name:s.username;document.getElementById('delete_staff_name').textContent=name+' ('+s.role+')';openModal('deleteStaffModal');}
function openStatusModal(s){const isActive=(s.status==='active'||s.status==1),ns=isActive?'inactive':'active';const name=(s.first_name&&s.last_name)?s.first_name+' '+s.last_name:s.username;document.getElementById('status_user_id').value=s.user_id;document.getElementById('status_new_status').value=ns;document.getElementById('status_staff_name').textContent=name+' ('+s.role+')';const header=document.getElementById('status_modal_header'),icon=document.getElementById('status_icon_div'),btn=document.getElementById('status_confirm_btn');if(isActive){header.className='db-modal__header db-modal__header--amber';document.getElementById('status_modal_title_h3').innerHTML='<i class="fas fa-ban"></i> Deactivate Staff';icon.className='db-confirm-icon db-confirm-icon--amber';icon.innerHTML='<i class="fas fa-user-slash"></i>';document.getElementById('status_question').textContent='Deactivate this staff member?';document.getElementById('status_action_text').textContent='You are about to deactivate:';document.getElementById('status_desc').textContent='They will lose system access. You can re-activate at any time.';btn.style.background='var(--db-amber-dark)';btn.style.color='#fff';document.getElementById('status_btn_text').textContent='Deactivate';}else{header.className='db-modal__header db-modal__header--success';document.getElementById('status_modal_title_h3').innerHTML='<i class="fas fa-check-circle"></i> Activate Staff';icon.className='db-confirm-icon db-confirm-icon--success';icon.innerHTML='<i class="fas fa-user-check"></i>';document.getElementById('status_question').textContent='Activate this staff member?';document.getElementById('status_action_text').textContent='You are about to activate:';document.getElementById('status_desc').textContent='They will regain access to the system.';btn.style.background='var(--db-success)';btn.style.color='#fff';document.getElementById('status_btn_text').textContent='Activate';}openModal('statusToggleModal');}
function searchTable(){const f=document.getElementById('searchInput').value.toLowerCase();document.querySelectorAll('#staffTable tbody tr').forEach(r=>{r.style.display=r.textContent.toLowerCase().includes(f)?'':' none';});}
window.addEventListener('click',e=>{if(e.target.classList.contains('db-modal'))closeModal(e.target.id);});
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id));});
setTimeout(()=>document.querySelectorAll('.db-alert').forEach(a=>{a.style.opacity='0';setTimeout(()=>a.remove(),400);}),5000);
</script>
<?php include '../../includes/footer.php'; ?>