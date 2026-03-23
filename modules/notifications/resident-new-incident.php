<?php
/**
 * Resident New Incident Report
 * modules/incidents/resident-new-incident.php
 * Simplified, resident-friendly form. Same DB logic as new-incident.php.
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title = 'Report an Incident';
$user_id    = $_SESSION['user_id'];
$user_role  = getCurrentUserRole();

/* ── Pre-fill resident profile ──────────────────────────────────────── */
/* ── Detect which age/birthdate column exists, then query safely ──── */
$_age_expr = 'NULL';
$_res_cols = $conn->query("SHOW COLUMNS FROM tbl_residents");
$_rcols    = [];
if ($_res_cols) { while ($rc = $_res_cols->fetch_assoc()) $_rcols[] = $rc['Field']; }

if (in_array('age', $_rcols)) {
    $_age_expr = 'r.age';
} elseif (in_array('birth_date', $_rcols)) {
    $_age_expr = "TIMESTAMPDIFF(YEAR, r.birth_date, CURDATE())";
} elseif (in_array('birthdate', $_rcols)) {
    $_age_expr = "TIMESTAMPDIFF(YEAR, r.birthdate, CURDATE())";
} elseif (in_array('date_of_birth', $_rcols)) {
    $_age_expr = "TIMESTAMPDIFF(YEAR, r.date_of_birth, CURDATE())";
} elseif (in_array('dob', $_rcols)) {
    $_age_expr = "TIMESTAMPDIFF(YEAR, r.dob, CURDATE())";
}

$_addr_col    = in_array('address', $_rcols)          ? 'r.address'        : 'NULL';
$_purok_col   = in_array('purok', $_rcols)             ? 'r.purok'          :
               (in_array('zone', $_rcols)              ? 'r.zone'           : 'NULL');
$_contact_col = in_array('contact_number', $_rcols)    ? 'r.contact_number' :
               (in_array('contact', $_rcols)           ? 'r.contact'        :
               (in_array('phone', $_rcols)             ? 'r.phone'          : 'NULL'));
$_gender_col  = in_array('gender', $_rcols)            ? 'r.gender'         :
               (in_array('sex', $_rcols)               ? 'r.sex'            : 'NULL');
$_civil_col   = in_array('civil_status', $_rcols)      ? 'r.civil_status'   :
               (in_array('marital_status', $_rcols)    ? 'r.marital_status' : 'NULL');

$resident = fetchOne($conn,
    "SELECT r.first_name, r.last_name, r.middle_name,
            {$_addr_col}    AS address,
            {$_purok_col}   AS purok,
            {$_contact_col} AS contact_number,
            {$_age_expr}    AS age,
            {$_gender_col}  AS gender,
            {$_civil_col}   AS civil_status,
            u.email
     FROM tbl_users u
     LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
     WHERE u.user_id = ?",
    [$user_id], 'i');

$prefill_name    = '';
$prefill_address = '';
$prefill_purok   = '';
$prefill_contact = '';
$prefill_age     = '';
$prefill_gender  = '';
$prefill_civil   = '';

if ($resident) {
    $fn = trim(($resident['first_name'] ?? '') . ' ' . ($resident['middle_name'] ? $resident['middle_name'] . ' ' : '') . ($resident['last_name'] ?? ''));
    $prefill_name    = $fn ?: '';
    $prefill_address = $resident['address']        ?? '';
    $prefill_purok   = $resident['purok']           ?? '';
    $prefill_contact = $resident['contact_number']  ?? '';
    $prefill_age     = $resident['age']             ?? '';
    $prefill_gender  = $resident['gender']          ?? '';
    $prefill_civil   = $resident['civil_status']    ?? '';
}

/* ── POST handler (identical logic to new-incident.php) ─────────────── */
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_incident'])) {

    $request_type  = 'Incident';
    $status        = 'Open';
    $mode          = 'Online Portal'; // residents always use Online Portal
    $impact        = trim($_POST['impact']        ?? '');
    $urgency       = trim($_POST['urgency']       ?? '');
    $priority      = trim($_POST['priority']      ?? '');

    $requester_name = trim($_POST['requester_name'] ?? '');
    $address        = trim($_POST['address']        ?? '');
    $purok          = trim($_POST['purok']          ?? '');
    $contact        = trim($_POST['contact']        ?? '');
    $age            = intval($_POST['age']          ?? 0);
    $gender         = trim($_POST['gender']         ?? '');
    $civil_status   = trim($_POST['civil_status']   ?? '');

    $office           = 'Barangay';
    $assigned_officer = '';
    $category         = trim($_POST['category']     ?? '');
    $sub_category     = trim($_POST['sub_category'] ?? '');
    $item             = trim($_POST['item']          ?? '');
    $root_cause       = trim($_POST['root_cause']    ?? '');
    $subject          = trim($_POST['subject']       ?? '');
    $description      = trim($_POST['description']   ?? '');
    $privacy_agreed   = isset($_POST['privacy_notice']) ? 1 : 0;

    if (empty($impact) || empty($urgency) || empty($requester_name) || empty($contact)
        || empty($category) || empty($sub_category) || empty($item)
        || empty($root_cause) || empty($subject)) {
        $error_message = 'Please fill in all required fields marked with *.';
    } elseif (!$privacy_agreed) {
        $error_message = 'You must agree to the Privacy Notice before submitting.';
    } else {
        /* Auto-detect columns — identical to new-incident.php */
        $col_result = $conn->query("SHOW COLUMNS FROM tbl_incidents");
        $db_columns = [];
        if ($col_result) {
            while ($col_row = $col_result->fetch_assoc()) $db_columns[] = $col_row['Field'];
        }

        $all_data = [
            'user_id'           => ['v' => $user_id,          't' => 'i'],
            'reported_by'       => ['v' => $user_id,          't' => 'i'],
            'resident_id'       => ['v' => $user_id,          't' => 'i'],
            'request_type'      => ['v' => $request_type,     't' => 's'],
            'incident_type'     => ['v' => $request_type,     't' => 's'],
            'type'              => ['v' => $request_type,     't' => 's'],
            'status'            => ['v' => $status,           't' => 's'],
            'incident_status'   => ['v' => $status,           't' => 's'],
            'mode'              => ['v' => $mode,             't' => 's'],
            'impact'            => ['v' => $impact,           't' => 's'],
            'urgency'           => ['v' => $urgency,          't' => 's'],
            'priority'          => ['v' => $priority,         't' => 's'],
            'requester_name'    => ['v' => $requester_name,   't' => 's'],
            'complainant_name'  => ['v' => $requester_name,   't' => 's'],
            'full_name'         => ['v' => $requester_name,   't' => 's'],
            'address'           => ['v' => $address,          't' => 's'],
            'location'          => ['v' => $address,          't' => 's'],
            'incident_location' => ['v' => $address,          't' => 's'],
            'purok'             => ['v' => $purok,            't' => 's'],
            'zone'              => ['v' => $purok,            't' => 's'],
            'contact_number'    => ['v' => $contact,          't' => 's'],
            'contact'           => ['v' => $contact,          't' => 's'],
            'phone'             => ['v' => $contact,          't' => 's'],
            'age'               => ['v' => $age,              't' => 'i'],
            'gender'            => ['v' => $gender,           't' => 's'],
            'civil_status'      => ['v' => $civil_status,     't' => 's'],
            'office'            => ['v' => $office,           't' => 's'],
            'group_name'        => ['v' => $office,           't' => 's'],
            'assigned_officer'  => ['v' => $assigned_officer, 't' => 's'],
            'assigned_to'       => ['v' => $assigned_officer, 't' => 's'],
            'technician'        => ['v' => $assigned_officer, 't' => 's'],
            'category'          => ['v' => $category,         't' => 's'],
            'incident_category' => ['v' => $category,         't' => 's'],
            'sub_category'      => ['v' => $sub_category,     't' => 's'],
            'subcategory'       => ['v' => $sub_category,     't' => 's'],
            'item'              => ['v' => $item,             't' => 's'],
            'incident_item'     => ['v' => $item,             't' => 's'],
            'root_cause'        => ['v' => $root_cause,       't' => 's'],
            'cause'             => ['v' => $root_cause,       't' => 's'],
            'subject'           => ['v' => $subject,          't' => 's'],
            'title'             => ['v' => $subject,          't' => 's'],
            'incident_title'    => ['v' => $subject,          't' => 's'],
            'description'       => ['v' => $description,      't' => 's'],
            'details'           => ['v' => $description,      't' => 's'],
            'incident_details'  => ['v' => $description,      't' => 's'],
            'narrative'         => ['v' => $description,      't' => 's'],
        ];

        $logical_map = [
            'user_id'=>'reporter','reported_by'=>'reporter','resident_id'=>'reporter',
            'request_type'=>'rtype','incident_type'=>'rtype','type'=>'rtype',
            'status'=>'status','incident_status'=>'status',
            'mode'=>'mode','impact'=>'impact','urgency'=>'urgency','priority'=>'priority',
            'requester_name'=>'reqname','complainant_name'=>'reqname','full_name'=>'reqname',
            'address'=>'addr','location'=>'addr','incident_location'=>'addr',
            'purok'=>'purok','zone'=>'purok',
            'contact_number'=>'contact','contact'=>'contact','phone'=>'contact',
            'age'=>'age','gender'=>'gender','civil_status'=>'civil',
            'office'=>'office','group_name'=>'office',
            'assigned_officer'=>'officer','assigned_to'=>'officer','technician'=>'officer',
            'category'=>'cat','incident_category'=>'cat',
            'sub_category'=>'subcat','subcategory'=>'subcat',
            'item'=>'item','incident_item'=>'item',
            'root_cause'=>'cause','cause'=>'cause',
            'subject'=>'subj','title'=>'subj','incident_title'=>'subj',
            'description'=>'desc','details'=>'desc','incident_details'=>'desc','narrative'=>'desc',
        ];

        $insert_cols = []; $insert_types = ''; $insert_params = []; $matched = [];
        foreach ($db_columns as $col) {
            if (!isset($all_data[$col])) continue;
            $logical = $logical_map[$col] ?? $col;
            if (isset($matched[$logical])) continue;
            $matched[$logical] = true;
            $insert_cols[]   = "`$col`";
            $insert_params[] = $all_data[$col]['v'];
            $insert_types   .= $all_data[$col]['t'];
        }

        foreach (['created_at','date_created','date_filed','reported_at','incident_date','date_added','created','timestamp'] as $ts_col) {
            if (in_array($ts_col, $db_columns) && !in_array("`$ts_col`", $insert_cols)) {
                $insert_cols[]   = "`$ts_col`";
                $insert_params[] = date('Y-m-d H:i:s');
                $insert_types   .= 's';
                break;
            }
        }

        if (empty($insert_cols)) {
            $error_message = 'Could not match any fields to database columns.';
        } else {
            $placeholders = array_fill(0, count($insert_cols), '?');
            $sql  = "INSERT INTO tbl_incidents (" . implode(', ', $insert_cols) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($insert_types, ...$insert_params);
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    // Notify admins
                    /* ── Notify admins ── */
                    if (function_exists('notifyIncidentReported')) {
                        notifyIncidentReported($conn, $new_id, $subject);
                    }

                    /* ── Create a notification for the resident themselves ── */
                    if (function_exists('createNotification')) {
                        createNotification(
                            $conn,
                            $user_id,
                            'Incident Report Submitted',
                            "Your incident report \"$subject\" (ID #$new_id) has been submitted successfully. The barangay will review it shortly.",
                            'incident_reported',
                            $new_id,
                            'incident'
                        );
                    } else {
                        /* Fallback: direct insert if createNotification() is unavailable */
                        $notif_stmt = $conn->prepare(
                            "INSERT INTO tbl_notifications
                             (user_id, title, message, type, reference_id, reference_type, is_read, created_at)
                             VALUES (?, ?, ?, 'incident_reported', ?, 'incident', 0, NOW())"
                        );
                        if ($notif_stmt) {
                            $notif_title   = 'Incident Report Submitted';
                            $notif_message = "Your incident report \"$subject\" (ID #$new_id) has been submitted successfully. The barangay will review it shortly.";
                            $notif_stmt->bind_param('issi', $user_id, $notif_title, $notif_message, $new_id);
                            $notif_stmt->execute();
                            $notif_stmt->close();
                        }
                    }

                    $_SESSION['success_message'] = "Your incident report #$new_id has been submitted. The barangay will review it shortly.";
                    header('Location: resident-notifications.php');
                    exit();
                } else {
                    $error_message = 'Submission failed. Please try again.';
                }
                $stmt->close();
            } else {
                $error_message = 'Database error. Please try again.';
            }
        }
    }
}

/* ── CSS ─────────────────────────────────────────────────────────────── */
$extra_css = '
<style>
@import url("https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap");

.main-content {
    padding: 0 !important;
    background: #f0f4f8 !important;
    font-family: "Outfit", sans-serif !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
}

:root {
    --r-bg:      #f0f4f8;
    --r-surface: #ffffff;
    --r-border:  #e2e8f0;
    --r-text:    #1a202c;
    --r-text2:   #4a5568;
    --r-text3:   #a0aec0;
    --r-blue:    #3b82f6;
    --r-blue-lt: #eff6ff;
    --r-blue-dk: #2563eb;
    --r-red:     #ef4444;
    --r-green:   #10b981;
    --r-orange:  #f59e0b;
    --r-shadow:  0 1px 4px rgba(0,0,0,.07);
    --r-radius:  12px;
    --r-radius-sm: 8px;
}

/* ── Scroll wrapper ── */
.r-page {
    flex: 1; overflow-y: auto; overflow-x: hidden;
    padding: 24px;
    display: flex; flex-direction: column; gap: 0;
    background: var(--r-bg);
}
.r-page::-webkit-scrollbar { width: 6px; }
.r-page::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 3px; }

/* ── Page header ── */
.r-page-header {
    display: flex; align-items: center; gap: 14px;
    margin-bottom: 22px;
}
.r-back-btn {
    width: 34px; height: 34px; border-radius: var(--r-radius-sm);
    display: flex; align-items: center; justify-content: center;
    border: 1.5px solid var(--r-border); background: var(--r-surface);
    color: var(--r-text2); cursor: pointer; font-size: 14px;
    text-decoration: none; transition: all .13s; flex-shrink: 0;
}
.r-back-btn:hover { background: var(--r-blue-lt); color: var(--r-blue); border-color: #bfdbfe; text-decoration: none; }
.r-page-title   { font-size: 20px; font-weight: 800; color: var(--r-text); }
.r-page-subtitle { font-size: 13px; color: var(--r-text3); margin-top: 2px; }

/* ── Alert ── */
.r-alert {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 16px; border-radius: var(--r-radius-sm);
    font-size: 13px; font-weight: 500; margin-bottom: 18px; line-height: 1.5;
}
.r-alert-err { background: #fef2f2; color: #991b1b; border: 1.5px solid #fecaca; }
.r-alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .5; line-height: 1; color: inherit; flex-shrink: 0; }

/* ── Progress steps ── */
.r-steps {
    display: flex; align-items: center; gap: 0;
    background: var(--r-surface); border: 1.5px solid var(--r-border);
    border-radius: var(--r-radius); padding: 16px 20px;
    margin-bottom: 20px; box-shadow: var(--r-shadow);
}
.r-step {
    display: flex; align-items: center; gap: 10px; flex: 1;
}
.r-step-num {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 800;
    border: 2px solid var(--r-border); background: var(--r-bg); color: var(--r-text3);
    transition: all .2s;
}
.r-step.active .r-step-num  { background: var(--r-blue); border-color: var(--r-blue); color: #fff; }
.r-step.done .r-step-num    { background: var(--r-green); border-color: var(--r-green); color: #fff; }
.r-step-label { font-size: 12px; font-weight: 600; color: var(--r-text3); line-height: 1.2; }
.r-step.active .r-step-label { color: var(--r-blue); }
.r-step.done .r-step-label   { color: var(--r-green); }
.r-step-line { flex: 1; height: 2px; background: var(--r-border); margin: 0 8px; border-radius: 1px; }
.r-step.done + .r-step-line, .r-step.done ~ .r-step .r-step-line { background: var(--r-green); }

/* ── Card ── */
.r-card {
    background: var(--r-surface); border: 1.5px solid var(--r-border);
    border-radius: var(--r-radius); overflow: hidden;
    box-shadow: var(--r-shadow); margin-bottom: 16px;
}
.r-card-header {
    display: flex; align-items: center; gap: 12px;
    padding: 16px 20px; border-bottom: 1px solid var(--r-border);
    background: #fafbfc;
}
.r-card-icon {
    width: 36px; height: 36px; border-radius: 10px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center; font-size: 15px;
}
.r-card-title   { font-size: 14px; font-weight: 800; color: var(--r-text); }
.r-card-desc    { font-size: 12px; color: var(--r-text3); margin-top: 1px; }
.r-card-body    { padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }

/* ── Field groups ── */
.r-field-row    { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.r-field-row.three { grid-template-columns: 1fr 1fr 1fr; }
.r-field-row.single { grid-template-columns: 1fr; }
@media(max-width: 680px) {
    .r-field-row, .r-field-row.three { grid-template-columns: 1fr; }
}

/* ── Field ── */
.r-field { display: flex; flex-direction: column; gap: 5px; }
.r-label {
    font-size: 12.5px; font-weight: 700; color: var(--r-text2);
    display: flex; align-items: center; gap: 4px;
}
.r-req { color: var(--r-red); font-size: 12px; }
.r-hint { font-size: 11px; color: var(--r-text3); margin-top: 3px; }

/* ── Inputs ── */
.r-input {
    width: 100%; padding: 9px 12px;
    border: 1.5px solid var(--r-border); border-radius: var(--r-radius-sm);
    font-size: 13px; font-family: "Outfit", sans-serif;
    color: var(--r-text); background: var(--r-surface);
    outline: none; transition: border-color .13s, box-shadow .13s;
    box-sizing: border-box;
}
.r-input:focus  { border-color: var(--r-blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.r-input.filled { background: #f8fafc; }
.r-input::placeholder { color: var(--r-text3); }

.r-select {
    width: 100%; padding: 9px 34px 9px 12px;
    border: 1.5px solid var(--r-border); border-radius: var(--r-radius-sm);
    font-size: 13px; font-family: "Outfit", sans-serif;
    color: var(--r-text); background: var(--r-surface);
    outline: none; cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'10\' height=\'6\'%3E%3Cpath d=\'M0 0l5 6 5-6z\' fill=\'%23a0aec0\'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 12px center;
    transition: border-color .13s, box-shadow .13s;
    box-sizing: border-box;
}
.r-select:focus { border-color: var(--r-blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }

.r-textarea {
    width: 100%; padding: 9px 12px; min-height: 100px; resize: vertical;
    border: 1.5px solid var(--r-border); border-radius: var(--r-radius-sm);
    font-size: 13px; font-family: "Outfit", sans-serif;
    color: var(--r-text); background: var(--r-surface);
    outline: none; transition: border-color .13s, box-shadow .13s;
    box-sizing: border-box; line-height: 1.6;
}
.r-textarea:focus { border-color: var(--r-blue); box-shadow: 0 0 0 3px rgba(59,130,246,.12); }
.r-textarea::placeholder { color: var(--r-text3); }

/* pre-filled badge */
.r-prefilled-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 2px 8px; border-radius: 20px;
    background: #f0fdf4; color: #15803d;
    font-size: 10.5px; font-weight: 700; border: 1px solid #bbf7d0;
}

/* ── Impact selector (visual tiles) ── */
.r-impact-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
@media(max-width: 500px) { .r-impact-grid { grid-template-columns: 1fr 1fr; } }
.r-impact-tile {
    border: 1.5px solid var(--r-border); border-radius: var(--r-radius-sm);
    padding: 10px 12px; cursor: pointer; transition: all .13s;
    display: flex; flex-direction: column; align-items: center; gap: 5px;
    text-align: center; user-select: none;
}
.r-impact-tile:hover { border-color: var(--r-blue); background: var(--r-blue-lt); }
.r-impact-tile.selected { border-color: var(--r-blue); background: var(--r-blue-lt); box-shadow: 0 0 0 3px rgba(59,130,246,.15); }
.r-impact-tile i   { font-size: 18px; }
.r-impact-tile span { font-size: 11px; font-weight: 700; color: var(--r-text2); line-height: 1.3; }

/* ── Urgency selector ── */
.r-urgency-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px; }
@media(max-width: 500px) { .r-urgency-row { grid-template-columns: 1fr 1fr; } }
.r-urgency-chip {
    border: 1.5px solid var(--r-border); border-radius: 20px;
    padding: 7px 12px; cursor: pointer; transition: all .13s;
    display: flex; align-items: center; justify-content: center; gap: 5px;
    font-size: 12.5px; font-weight: 700; user-select: none; text-align: center;
}
.r-urgency-chip:hover { border-color: currentColor; }
.r-urgency-chip[data-val="Low"]      { color: #16a34a; }
.r-urgency-chip[data-val="Medium"]   { color: #d97706; }
.r-urgency-chip[data-val="High"]     { color: #dc2626; }
.r-urgency-chip[data-val="Critical"] { color: #7c3aed; }
.r-urgency-chip.selected[data-val="Low"]      { background: #f0fdf4; border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
.r-urgency-chip.selected[data-val="Medium"]   { background: #fffbeb; border-color: #d97706; box-shadow: 0 0 0 3px rgba(217,119,6,.12); }
.r-urgency-chip.selected[data-val="High"]     { background: #fef2f2; border-color: #dc2626; box-shadow: 0 0 0 3px rgba(220,38,38,.12); }
.r-urgency-chip.selected[data-val="Critical"] { background: #f5f3ff; border-color: #7c3aed; box-shadow: 0 0 0 3px rgba(124,58,237,.12); }

/* ── File drop zone ── */
.r-dropzone {
    border: 2px dashed var(--r-border); border-radius: var(--r-radius-sm);
    padding: 22px; text-align: center; cursor: pointer;
    transition: all .13s; background: #fafbfc;
}
.r-dropzone:hover, .r-dropzone.over { border-color: var(--r-blue); background: var(--r-blue-lt); }
.r-dropzone-icon { font-size: 26px; color: var(--r-text3); margin-bottom: 8px; }
.r-dropzone p    { font-size: 13px; color: var(--r-text3); margin: 0; }
.r-dropzone span { color: var(--r-blue); font-weight: 700; text-decoration: underline; cursor: pointer; }
.r-file-item {
    display: flex; align-items: center; gap: 8px;
    padding: 7px 0; border-bottom: 1px solid #f7fafc;
    font-size: 12.5px; color: var(--r-text);
}
.r-file-remove {
    background: none; border: none; color: var(--r-text3);
    cursor: pointer; font-size: 13px; padding: 2px 5px; margin-left: auto;
}
.r-file-remove:hover { color: var(--r-red); }

/* ── Privacy box ── */
.r-privacy-box {
    display: flex; align-items: flex-start; gap: 12px;
    padding: 14px 16px; border-radius: var(--r-radius-sm);
    background: #f8fafc; border: 1.5px solid var(--r-border);
}
.r-privacy-cb { margin-top: 2px; width: 16px; height: 16px; cursor: pointer; accent-color: var(--r-blue); flex-shrink: 0; }
.r-privacy-text { font-size: 12px; color: var(--r-text2); line-height: 1.7; }

/* ── Submit bar ── */
.r-submit-bar {
    background: var(--r-surface); border: 1.5px solid var(--r-border);
    border-radius: var(--r-radius); padding: 18px 20px;
    display: flex; align-items: center; gap: 12px;
    box-shadow: var(--r-shadow);
}
.r-btn-submit {
    padding: 11px 28px; border-radius: var(--r-radius-sm);
    font-size: 14px; font-weight: 800; font-family: "Outfit", sans-serif;
    background: var(--r-blue); color: #fff; border: none; cursor: pointer;
    transition: background .13s; display: flex; align-items: center; gap: 8px;
}
.r-btn-submit:hover { background: var(--r-blue-dk); }
.r-btn-reset {
    padding: 11px 18px; border-radius: var(--r-radius-sm);
    font-size: 13.5px; font-weight: 600; font-family: "Outfit", sans-serif;
    background: var(--r-surface); color: var(--r-text2);
    border: 1.5px solid var(--r-border); cursor: pointer; transition: all .13s;
}
.r-btn-reset:hover { border-color: var(--r-blue); color: var(--r-blue); background: var(--r-blue-lt); }
.r-btn-cancel {
    padding: 11px 18px; border-radius: var(--r-radius-sm);
    font-size: 13.5px; font-weight: 600; font-family: "Outfit", sans-serif;
    background: var(--r-surface); color: var(--r-text2);
    border: 1.5px solid var(--r-border); cursor: pointer; transition: all .13s;
    text-decoration: none; display: inline-flex; align-items: center;
}
.r-btn-cancel:hover { border-color: var(--r-red); color: var(--r-red); background: #fef2f2; text-decoration: none; }
.r-submit-note { font-size: 12px; color: var(--r-text3); line-height: 1.5; margin-left: auto; text-align: right; }

/* dark mode */
body.dark-mode .r-page { background: #0f172a !important; }
body.dark-mode .r-card, body.dark-mode .r-steps, body.dark-mode .r-submit-bar { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .r-card-header { background: #243044 !important; }
body.dark-mode .r-card-title, body.dark-mode .r-page-title { color: #f1f5f9 !important; }
body.dark-mode .r-label { color: #94a3b8 !important; }
body.dark-mode .r-input, body.dark-mode .r-select, body.dark-mode .r-textarea { background: #334155 !important; color: #e2e8f0 !important; border-color: #475569 !important; }
body.dark-mode .r-impact-tile, body.dark-mode .r-urgency-chip { background: #243044 !important; border-color: #475569 !important; }
body.dark-mode .r-dropzone { background: #243044 !important; border-color: #475569 !important; }
body.dark-mode .r-privacy-box { background: #243044 !important; border-color: #475569 !important; }
</style>';

include '../../includes/header.php';
?>

<div class="r-page">

    <!-- ══ PAGE HEADER ══ -->
    <div class="r-page-header">
        <a href="javascript:history.back()" class="r-back-btn" title="Go back">
            <i class="fas fa-arrow-left"></i>
        </a>
        <div>
            <div class="r-page-title">Report an Incident</div>
            <div class="r-page-subtitle">Submit a new incident report to your barangay</div>
        </div>
    </div>

    <!-- ══ ALERT ══ -->
    <?php if ($error_message): ?>
    <div class="r-alert r-alert-err" id="rAlert">
        <i class="fas fa-exclamation-circle" style="margin-top:1px;flex-shrink:0"></i>
        <span><?= htmlspecialchars($error_message) ?></span>
        <button class="r-alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <!-- ══ PROGRESS STEPS ══ -->
    <div class="r-steps">
        <div class="r-step active" id="step1">
            <div class="r-step-num">1</div>
            <div class="r-step-label">Incident<br>Details</div>
        </div>
        <div class="r-step-line"></div>
        <div class="r-step" id="step2">
            <div class="r-step-num">2</div>
            <div class="r-step-label">Your<br>Information</div>
        </div>
        <div class="r-step-line"></div>
        <div class="r-step" id="step3">
            <div class="r-step-num">3</div>
            <div class="r-step-label">Description<br>&amp; Files</div>
        </div>
        <div class="r-step-line"></div>
        <div class="r-step" id="step4">
            <div class="r-step-num">4</div>
            <div class="r-step-label">Review<br>&amp; Submit</div>
        </div>
    </div>

    <form id="rForm" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="submit_incident" value="1">
    <input type="hidden" name="mode" value="Online Portal">
    <input type="hidden" name="priority" id="hiddenPriority">
    <input type="hidden" name="impact" id="hiddenImpact">
    <input type="hidden" name="urgency" id="hiddenUrgency">

    <!-- ══ CARD 1: Incident Details ══ -->
    <div class="r-card">
        <div class="r-card-header">
            <div class="r-card-icon" style="background:#eff6ff;color:#3b82f6">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div>
                <div class="r-card-title">Incident Details</div>
                <div class="r-card-desc">What kind of incident are you reporting?</div>
            </div>
        </div>
        <div class="r-card-body">

            <!-- Subject -->
            <div class="r-field r-field-row single">
                <div class="r-field">
                    <label class="r-label">Incident Title <span class="r-req">*</span></label>
                    <input class="r-input" name="subject" id="subjectInput"
                           placeholder="e.g. Noise disturbance near Purok 3, Broken streetlight on Main St."
                           value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>" required>
                    <div class="r-hint">Write a short, clear title for your incident report.</div>
                </div>
            </div>

            <!-- Category / Subcategory / Item -->
            <div class="r-field-row three">
                <div class="r-field">
                    <label class="r-label">Category <span class="r-req">*</span></label>
                    <select class="r-select" name="category" id="categorySelect" onchange="updateSubcategory()" required>
                        <option value="" disabled selected>-- Select --</option>
                        <option value="Peace &amp; Order">🔐 Peace &amp; Order</option>
                        <option value="Health &amp; Sanitation">🏥 Health &amp; Sanitation</option>
                        <option value="Infrastructure">🏗️ Infrastructure</option>
                        <option value="Social Services">🤝 Social Services</option>
                        <option value="Environmental">🌿 Environmental</option>
                        <option value="Legal / Documentation">📋 Legal / Documentation</option>
                        <option value="Youth &amp; Sports">⚽ Youth &amp; Sports</option>
                        <option value="Disaster / Calamity">🌊 Disaster / Calamity</option>
                    </select>
                </div>
                <div class="r-field">
                    <label class="r-label">Sub-Category <span class="r-req">*</span></label>
                    <select class="r-select" name="sub_category" id="subCategorySelect" onchange="updateItem()" required>
                        <option value="" disabled selected>-- Select Category First --</option>
                    </select>
                </div>
                <div class="r-field">
                    <label class="r-label">Specific Issue <span class="r-req">*</span></label>
                    <select class="r-select" name="item" id="itemSelect" required>
                        <option value="" disabled selected>-- Select Sub-Category First --</option>
                    </select>
                </div>
            </div>

            <!-- Root Cause -->
            <div class="r-field">
                <label class="r-label">Root Cause / What Happened <span class="r-req">*</span></label>
                <input class="r-input" name="root_cause"
                       placeholder="Briefly describe what caused this incident..."
                       value="<?= htmlspecialchars($_POST['root_cause'] ?? '') ?>" required>
            </div>

            <!-- Impact tiles -->
            <div class="r-field">
                <label class="r-label">Who is Affected? <span class="r-req">*</span></label>
                <div class="r-impact-grid" id="impactGrid">
                    <?php
                    $impacts = [
                        ['Affects Individual',           'fa-user',      '#3b82f6'],
                        ['Affects Household',            'fa-home',      '#8b5cf6'],
                        ['Affects Street / Sitio',       'fa-road',      '#f59e0b'],
                        ['Affects Entire Barangay',      'fa-map',       '#ef4444'],
                        ['Affects Multiple Barangays',   'fa-globe-asia','#1e293b'],
                    ];
                    foreach ($impacts as [$val, $icon, $color]):
                    ?>
                    <div class="r-impact-tile" data-val="<?= htmlspecialchars($val) ?>"
                         onclick="selectImpact(this)"
                         style="--ic:<?= $color ?>">
                        <i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i>
                        <span><?= $val ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Urgency chips -->
            <div class="r-field">
                <label class="r-label">Urgency Level <span class="r-req">*</span></label>
                <div class="r-urgency-row" id="urgencyRow">
                    <div class="r-urgency-chip" data-val="Low"      onclick="selectUrgency(this)"><i class="fas fa-circle" style="font-size:8px"></i> Low</div>
                    <div class="r-urgency-chip" data-val="Medium"   onclick="selectUrgency(this)"><i class="fas fa-circle" style="font-size:8px"></i> Medium</div>
                    <div class="r-urgency-chip" data-val="High"     onclick="selectUrgency(this)"><i class="fas fa-exclamation-circle" style="font-size:10px"></i> High</div>
                    <div class="r-urgency-chip" data-val="Critical" onclick="selectUrgency(this)"><i class="fas fa-bolt" style="font-size:10px"></i> Critical</div>
                </div>
                <div class="r-hint" id="urgencyHint">Select how urgent this incident is.</div>
            </div>

        </div>
    </div>

    <!-- ══ CARD 2: Your Information ══ -->
    <div class="r-card">
        <div class="r-card-header">
            <div class="r-card-icon" style="background:#f0fdf4;color:#10b981">
                <i class="fas fa-user-circle"></i>
            </div>
            <div>
                <div class="r-card-title">Your Information</div>
                <div class="r-card-desc">
                    Your profile details have been pre-filled. You may update them if needed.
                    <?php if ($prefill_name): ?>
                    <span class="r-prefilled-badge" style="margin-left:6px"><i class="fas fa-check"></i> Pre-filled from your profile</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="r-card-body">

            <div class="r-field-row">
                <div class="r-field">
                    <label class="r-label">Full Name <span class="r-req">*</span></label>
                    <input class="r-input <?= $prefill_name ? 'filled' : '' ?>" name="requester_name"
                           placeholder="Your full name"
                           value="<?= htmlspecialchars($_POST['requester_name'] ?? $prefill_name) ?>" required>
                </div>
                <div class="r-field">
                    <label class="r-label">Contact Number <span class="r-req">*</span></label>
                    <input class="r-input <?= $prefill_contact ? 'filled' : '' ?>" name="contact"
                           placeholder="09XX-XXX-XXXX"
                           value="<?= htmlspecialchars($_POST['contact'] ?? $prefill_contact) ?>" required>
                </div>
            </div>

            <div class="r-field-row">
                <div class="r-field">
                    <label class="r-label">Address</label>
                    <input class="r-input <?= $prefill_address ? 'filled' : '' ?>" name="address"
                           placeholder="House No., Street, Sitio"
                           value="<?= htmlspecialchars($_POST['address'] ?? $prefill_address) ?>">
                </div>
                <div class="r-field">
                    <label class="r-label">Purok / Zone</label>
                    <select class="r-select" name="purok">
                        <option value="">-- Select Purok --</option>
                        <?php for ($p = 1; $p <= 8; $p++):
                            $v = "Purok $p";
                            $sel = (($_POST['purok'] ?? $prefill_purok) === $v) ? 'selected' : '';
                        ?>
                        <option value="<?= $v ?>" <?= $sel ?>><?= $v ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>

            <div class="r-field-row three">
                <div class="r-field">
                    <label class="r-label">Age</label>
                    <input class="r-input <?= $prefill_age ? 'filled' : '' ?>" name="age" type="number"
                           placeholder="Age" min="1" max="120"
                           value="<?= htmlspecialchars($_POST['age'] ?? $prefill_age) ?>">
                </div>
                <div class="r-field">
                    <label class="r-label">Gender</label>
                    <select class="r-select" name="gender">
                        <option value="">-- Select --</option>
                        <?php foreach (['Male','Female','Prefer not to say'] as $g):
                            $sel = (($_POST['gender'] ?? $prefill_gender) === $g) ? 'selected' : '';
                        ?>
                        <option value="<?= $g ?>" <?= $sel ?>><?= $g ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="r-field">
                    <label class="r-label">Civil Status</label>
                    <select class="r-select" name="civil_status">
                        <option value="">-- Select --</option>
                        <?php foreach (['Single','Married','Widowed','Separated'] as $cs):
                            $sel = (($_POST['civil_status'] ?? $prefill_civil) === $cs) ? 'selected' : '';
                        ?>
                        <option value="<?= $cs ?>" <?= $sel ?>><?= $cs ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

        </div>
    </div>

    <!-- ══ CARD 3: Description & Attachments ══ -->
    <div class="r-card">
        <div class="r-card-header">
            <div class="r-card-icon" style="background:#fef9c3;color:#ca8a04">
                <i class="fas fa-file-alt"></i>
            </div>
            <div>
                <div class="r-card-title">Description &amp; Attachments</div>
                <div class="r-card-desc">Describe the incident in detail and attach any supporting photos or files.</div>
            </div>
        </div>
        <div class="r-card-body">

            <div class="r-field">
                <label class="r-label">Detailed Description</label>
                <textarea class="r-textarea" name="description"
                          placeholder="Provide as much detail as possible — when it happened, where exactly, who is involved, what you have witnessed, etc."
                          ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                <div class="r-hint">The more detail you provide, the faster the barangay can respond.</div>
            </div>

            <div class="r-field">
                <label class="r-label">Attachments <span style="font-weight:400;color:var(--r-text3)">(optional — photos, videos, documents)</span></label>
                <input type="file" id="rFileInput" name="attachments[]" multiple
                       style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;z-index:-1"
                       onchange="rHandleFiles(this)">
                <label for="rFileInput" class="r-dropzone" id="rDropZone"
                       ondragover="event.preventDefault();this.classList.add('over')"
                       ondragleave="this.classList.remove('over')"
                       ondrop="rHandleDrop(event)"
                       onclick="event.preventDefault();document.getElementById('rFileInput').click()">
                    <div class="r-dropzone-icon"><i class="fas fa-cloud-upload-alt"></i></div>
                    <p>Drag &amp; drop files here or <span>click to browse</span></p>
                    <p style="font-size:11px;margin-top:6px">Accepted: images, PDF, Word, video (max 10 MB each)</p>
                </label>
                <div id="rFileList"></div>
            </div>

        </div>
    </div>

    <!-- ══ CARD 4: Privacy & Submit ══ -->
    <div class="r-card">
        <div class="r-card-header">
            <div class="r-card-icon" style="background:#fdf4ff;color:#9333ea">
                <i class="fas fa-shield-alt"></i>
            </div>
            <div>
                <div class="r-card-title">Privacy Notice &amp; Submission</div>
                <div class="r-card-desc">Please read and agree before submitting.</div>
            </div>
        </div>
        <div class="r-card-body">
            <div class="r-privacy-box">
                <input type="checkbox" class="r-privacy-cb" name="privacy_notice" id="privacyCb" required>
                <label for="privacyCb" class="r-privacy-text">
                    By submitting this incident report, I consent to the collection and use of my personal information
                    and the details of this incident by the Barangay for the purpose of resolving my report. I understand
                    that my data will be kept confidential and used only in accordance with the Data Privacy Act of 2012.
                    For privacy concerns, please contact the Barangay Secretary.
                </label>
            </div>
        </div>
    </div>

    <!-- ══ SUBMIT BAR ══ -->
    <div class="r-submit-bar">
        <button type="submit" class="r-btn-submit">
            <i class="fas fa-paper-plane"></i> Submit Report
        </button>
        <button type="button" class="r-btn-reset" onclick="rReset()">
            <i class="fas fa-undo" style="font-size:11px;margin-right:4px"></i> Reset
        </button>
        <a href="javascript:history.back()" class="r-btn-cancel">Cancel</a>
        <div class="r-submit-note">
            <i class="fas fa-lock" style="font-size:11px"></i> Your report is secure &amp; confidential<br>
            The barangay will respond within 24–48 hours.
        </div>
    </div>

    </form>
</div><!-- /r-page -->

<script>
/* ══ Category → Sub → Item ══ */
const catMap = {
    'Peace & Order': {
        'Disturbance':          ['Noise Complaint','Public Nuisance','Illegal Gathering'],
        'Physical Altercation': ['Fistfight','Assault','Domestic Violence'],
        'Property Dispute':     ['Land Boundary','Trespassing','Vandalism'],
        'Illegal Activities':   ['Drug Related','Theft / Robbery','Illegal Gambling'],
    },
    'Health & Sanitation': {
        'Disease Outbreak': ['Dengue','COVID-19','Cholera / Diarrhea'],
        'Waste Management': ['Illegal Dumping','Clogged Drainage','Open Burning'],
        'Water Supply':     ['Water Shortage','Contaminated Water','Broken Waterline'],
        'Food Safety':      ['Street Food Violation','Food Poisoning Report'],
    },
    'Infrastructure': {
        'Roads & Pathways': ['Pothole / Damaged Road','Broken Footbridge','Missing Road Signs'],
        'Street Lighting':  ['Broken Streetlight','No Lighting in Area'],
        'Flood Control':    ['Clogged Canal','Flooded Street','Damaged Drainage'],
        'Facilities':       ['Damaged Basketball Court','Broken Barangay Hall Facilities'],
    },
    'Social Services': {
        '4Ps / Welfare':   ['Beneficiary Issue','New Application','Update Records'],
        'Senior Citizens': ['Pension Issue','ID Application','Assistance Request'],
        'PWD Services':    ['ID Application','Benefit Claim','Accessibility Concern'],
        'Livelihood':      ['Skills Training','Capital Assistance','Business Permit Help'],
    },
    'Environmental': {
        'Illegal Logging': ['Tree Cutting','Forest Encroachment'],
        'Pollution':       ['Air Pollution','Water Pollution','Noise Pollution'],
        'Animal Control':  ['Stray Animal','Rabies Concern','Livestock Complaint'],
    },
    'Legal / Documentation': {
        'Certificates': ['Barangay Clearance','Certificate of Residency','Certificate of Indigency'],
        'Blotter':      ['New Blotter Entry','Blotter Follow-up'],
        'Mediation':    ['Conciliation Request','Lupon Referral'],
    },
    'Youth & Sports': {
        'SK Programs':       ['Youth Assembly','SK Project Request'],
        'Sports Facilities': ['Court Reservation','Equipment Request'],
        'Youth Activities':  ['Training / Seminar','Cultural Event'],
    },
    'Disaster / Calamity': {
        'Flood':     ['Evacuation Request','Relief Goods','Damage Assessment'],
        'Fire':      ['Post-Fire Assistance','Temporary Shelter'],
        'Typhoon':   ['Roof Damage','Relief Assistance','Tree Fallen on Property'],
        'Earthquake':['Structural Damage Report','Injury Report'],
    },
};

function updateSubcategory() {
    const cat = document.getElementById('categorySelect').value;
    const sub = document.getElementById('subCategorySelect');
    const itm = document.getElementById('itemSelect');
    sub.innerHTML = '<option value="" disabled selected>-- Select --</option>';
    itm.innerHTML = '<option value="" disabled selected>-- Select Sub-Category First --</option>';
    if (cat && catMap[cat]) {
        Object.keys(catMap[cat]).forEach(s => {
            sub.innerHTML += `<option value="${s}">${s}</option>`;
        });
    }
    updateStepProgress();
}
function updateItem() {
    const cat = document.getElementById('categorySelect').value;
    const sub = document.getElementById('subCategorySelect').value;
    const itm = document.getElementById('itemSelect');
    itm.innerHTML = '<option value="" disabled selected>-- Select --</option>';
    if (cat && sub && catMap[cat]?.[sub]) {
        catMap[cat][sub].forEach(i => {
            itm.innerHTML += `<option value="${i}">${i}</option>`;
        });
    }
}

/* ══ Impact tiles ══ */
function selectImpact(el) {
    document.querySelectorAll('.r-impact-tile').forEach(t => t.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('hiddenImpact').value = el.dataset.val;
    updateStepProgress();
}

/* ══ Urgency chips ══ */
const urgencyHints = {
    Low:      'Low — can wait a few days.',
    Medium:   'Medium — should be addressed this week.',
    High:     'High — needs attention today.',
    Critical: 'Critical — immediate response needed!',
};
function selectUrgency(el) {
    document.querySelectorAll('.r-urgency-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const val = el.dataset.val;
    document.getElementById('hiddenUrgency').value = val;
    document.getElementById('hiddenPriority').value = val;
    document.getElementById('urgencyHint').textContent = urgencyHints[val] || '';
    updateStepProgress();
}

/* ══ Step progress indicator ══ */
function updateStepProgress() {
    const s = document.getElementById('subjectInput').value.trim();
    const c = document.getElementById('categorySelect').value;
    const u = document.getElementById('hiddenUrgency').value;
    const i = document.getElementById('hiddenImpact').value;

    setStep(1, s && c && u && i);

    const nm = document.querySelector('[name="requester_name"]').value.trim();
    const ct = document.querySelector('[name="contact"]').value.trim();
    setStep(2, nm && ct);
}
function setStep(n, done) {
    const el = document.getElementById('step' + n);
    if (!el) return;
    if (done) { el.classList.add('done'); el.classList.remove('active'); el.querySelector('.r-step-num').innerHTML = '<i class="fas fa-check" style="font-size:11px"></i>'; }
    else      { el.classList.remove('done'); }
}

/* Listen for changes */
['subjectInput','categorySelect'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.addEventListener('change', updateStepProgress);
    if (el) el.addEventListener('input',  updateStepProgress);
});
document.querySelector('[name="requester_name"]')?.addEventListener('input', updateStepProgress);
document.querySelector('[name="contact"]')?.addEventListener('input', updateStepProgress);

/* ══ File handling ══ */
function rHandleFiles(input) {
    if (input.files && input.files.length > 0) rAddToList(Array.from(input.files));
}
function rHandleDrop(e) {
    e.preventDefault(); e.stopPropagation();
    document.getElementById('rDropZone').classList.remove('over');
    if (e.dataTransfer?.files.length > 0) rAddToList(Array.from(e.dataTransfer.files));
}
function rAddToList(files) {
    const fl = document.getElementById('rFileList');
    const icons = { jpg:'fa-image',jpeg:'fa-image',png:'fa-image',gif:'fa-image',webp:'fa-image',
                    pdf:'fa-file-pdf',doc:'fa-file-word',docx:'fa-file-word',
                    xls:'fa-file-excel',xlsx:'fa-file-excel',zip:'fa-file-archive',rar:'fa-file-archive' };
    files.forEach(f => {
        const div = document.createElement('div');
        div.className = 'r-file-item';
        const ext  = f.name.split('.').pop().toLowerCase();
        const icon = icons[ext] || 'fa-file';
        const size = f.size > 1048576 ? (f.size/1048576).toFixed(1)+' MB' : (f.size/1024).toFixed(1)+' KB';
        div.innerHTML = `
            <i class="fas ${icon}" style="color:var(--r-blue);font-size:14px;width:16px;flex-shrink:0"></i>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:13px">${f.name}</span>
            <span style="color:var(--r-text3);font-size:11.5px;flex-shrink:0;margin-left:8px">${size}</span>
            <button type="button" class="r-file-remove" onclick="this.closest('.r-file-item').remove()">
                <i class="fas fa-times"></i>
            </button>`;
        fl.appendChild(div);
    });
    document.getElementById('rFileInput').value = '';
}

/* ══ Reset ══ */
function rReset() {
    document.getElementById('rForm').reset();
    document.querySelectorAll('.r-impact-tile').forEach(t => t.classList.remove('selected'));
    document.querySelectorAll('.r-urgency-chip').forEach(c => c.classList.remove('selected'));
    document.getElementById('hiddenImpact').value  = '';
    document.getElementById('hiddenUrgency').value = '';
    document.getElementById('hiddenPriority').value = '';
    document.getElementById('urgencyHint').textContent = 'Select how urgent this incident is.';
    document.getElementById('subCategorySelect').innerHTML = '<option value="" disabled selected>-- Select Category First --</option>';
    document.getElementById('itemSelect').innerHTML        = '<option value="" disabled selected>-- Select Sub-Category First --</option>';
    document.getElementById('rFileList').innerHTML = '';
    ['step1','step2','step3','step4'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.classList.remove('done'); el.querySelector('.r-step-num').textContent = id.replace('step',''); }
    });
}

/* ══ Validate hidden fields before submit ══ */
document.getElementById('rForm').addEventListener('submit', function(e) {
    if (!document.getElementById('hiddenImpact').value) {
        e.preventDefault();
        alert('Please select who is affected by this incident.');
        document.getElementById('impactGrid').scrollIntoView({behavior:'smooth', block:'center'});
        return;
    }
    if (!document.getElementById('hiddenUrgency').value) {
        e.preventDefault();
        alert('Please select the urgency level.');
        document.getElementById('urgencyRow').scrollIntoView({behavior:'smooth', block:'center'});
        return;
    }
});

/* Restore post-back selections */
(function() {
    const iv = '<?= htmlspecialchars($_POST['impact'] ?? '') ?>';
    const uv = '<?= htmlspecialchars($_POST['urgency'] ?? '') ?>';
    const cv = '<?= htmlspecialchars($_POST['category'] ?? '') ?>';
    const sv = '<?= htmlspecialchars($_POST['sub_category'] ?? '') ?>';
    const itv = '<?= htmlspecialchars($_POST['item'] ?? '') ?>';

    if (iv) {
        const tile = document.querySelector(`.r-impact-tile[data-val="${iv}"]`);
        if (tile) { tile.classList.add('selected'); document.getElementById('hiddenImpact').value = iv; }
    }
    if (uv) {
        const chip = document.querySelector(`.r-urgency-chip[data-val="${uv}"]`);
        if (chip) { chip.classList.add('selected'); document.getElementById('hiddenUrgency').value = uv; document.getElementById('hiddenPriority').value = uv; document.getElementById('urgencyHint').textContent = urgencyHints[uv] || ''; }
    }
    if (cv) {
        document.getElementById('categorySelect').value = cv;
        updateSubcategory();
        if (sv) {
            setTimeout(() => {
                document.getElementById('subCategorySelect').value = sv;
                updateItem();
                if (itv) setTimeout(() => { document.getElementById('itemSelect').value = itv; }, 50);
            }, 50);
        }
    }
    updateStepProgress();
})();
</script>

<?php include '../../includes/footer.php'; ?>