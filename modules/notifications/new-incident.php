<?php
/**
 * New Incident - Barangay
 * modules/incidents/new-incident.php
 * Integrates with existing header.php / footer.php / sidebar
 */
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../config/database.php';
require_once '../../includes/functions.php';

requireLogin();

$page_title  = 'New Incident';
$user_id     = $_SESSION['user_id'];
$user_role   = getCurrentUserRole();

/* ── POST: Handle form submission ─────────────────────────────── */
$success_message = '';
$error_message   = '';

/* ── DEBUG: Show real column names — visit ?debug_cols to see ── */
if (isset($_GET['debug_cols'])) {
    $r = $conn->query("SHOW COLUMNS FROM tbl_incidents");
    $cols = [];
    while ($row = $r->fetch_assoc()) $cols[] = $row['Field'];
    echo '<pre style="background:#1e293b;color:#34d399;padding:20px;margin:20px;border-radius:8px;font-size:13px">';
    echo "tbl_incidents columns:\n\n";
    foreach ($cols as $c) echo "  - $c\n";
    echo '</pre>';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_incident'])) {

    $request_type   = 'Incident';
    $status         = 'Open';
    $mode           = trim($_POST['mode'] ?? '');
    $impact         = trim($_POST['impact'] ?? '');
    $urgency        = trim($_POST['urgency'] ?? '');
    $priority       = trim($_POST['priority'] ?? '');

    $requester_name = trim($_POST['requester_name'] ?? '');
    $address        = trim($_POST['address'] ?? '');
    $purok          = trim($_POST['purok'] ?? '');
    $contact        = trim($_POST['contact'] ?? '');
    $age            = intval($_POST['age'] ?? 0);
    $gender         = trim($_POST['gender'] ?? '');
    $civil_status   = trim($_POST['civil_status'] ?? '');

    $office         = trim($_POST['office'] ?? '');
    $assigned_officer = trim($_POST['assigned_officer'] ?? '');
    $category       = trim($_POST['category'] ?? '');
    $sub_category   = trim($_POST['sub_category'] ?? '');
    $item           = trim($_POST['item'] ?? '');
    $root_cause     = trim($_POST['root_cause'] ?? '');

    $subject        = trim($_POST['subject'] ?? '');
    $description    = trim($_POST['description'] ?? '');
    $privacy_agreed = isset($_POST['privacy_notice']) ? 1 : 0;

    if (empty($mode) || empty($impact) || empty($urgency) || empty($requester_name)
        || empty($contact) || empty($category) || empty($sub_category) || empty($item)
        || empty($root_cause) || empty($subject)) {
        $error_message = 'Please fill in all required fields.';
    } elseif (!$privacy_agreed) {
        $error_message = 'You must agree to the Privacy Notice before submitting.';
    } else {
        /*
         * ── Auto-detect columns in tbl_incidents then build INSERT ──
         * This avoids "Unknown column" errors regardless of schema.
         */

        /* 1. Get all actual column names from the table */
        $col_result = $conn->query("SHOW COLUMNS FROM tbl_incidents");
        $db_columns = [];
        if ($col_result) {
            while ($col_row = $col_result->fetch_assoc()) {
                $db_columns[] = $col_row['Field'];
            }
        }

        /* 2. All possible data we want to insert — key = column name */
        $all_data = [
            'user_id'          => ['v' => $user_id,          't' => 'i'],
            'reported_by'      => ['v' => $user_id,          't' => 'i'],
            'resident_id'      => ['v' => $user_id,          't' => 'i'],
            'request_type'     => ['v' => $request_type,     't' => 's'],
            'incident_type'    => ['v' => $request_type,     't' => 's'],
            'type'             => ['v' => $request_type,     't' => 's'],
            'status'           => ['v' => $status,           't' => 's'],
            'incident_status'  => ['v' => $status,           't' => 's'],
            'mode'             => ['v' => $mode,             't' => 's'],
            'impact'           => ['v' => $impact,           't' => 's'],
            'urgency'          => ['v' => $urgency,          't' => 's'],
            'priority'         => ['v' => $priority,         't' => 's'],
            'requester_name'   => ['v' => $requester_name,   't' => 's'],
            'complainant_name' => ['v' => $requester_name,   't' => 's'],
            'full_name'        => ['v' => $requester_name,   't' => 's'],
            'address'          => ['v' => $address,          't' => 's'],
            'location'         => ['v' => $address,          't' => 's'],
            'incident_location'=> ['v' => $address,          't' => 's'],
            'purok'            => ['v' => $purok,            't' => 's'],
            'zone'             => ['v' => $purok,            't' => 's'],
            'contact_number'   => ['v' => $contact,          't' => 's'],
            'contact'          => ['v' => $contact,          't' => 's'],
            'phone'            => ['v' => $contact,          't' => 's'],
            'age'              => ['v' => $age,              't' => 'i'],
            'gender'           => ['v' => $gender,           't' => 's'],
            'civil_status'     => ['v' => $civil_status,     't' => 's'],
            'office'           => ['v' => $office,           't' => 's'],
            'group_name'       => ['v' => $office,           't' => 's'],
            'assigned_officer' => ['v' => $assigned_officer, 't' => 's'],
            'assigned_to'      => ['v' => $assigned_officer, 't' => 's'],
            'technician'       => ['v' => $assigned_officer, 't' => 's'],
            'category'         => ['v' => $category,         't' => 's'],
            'incident_category'=> ['v' => $category,         't' => 's'],
            'sub_category'     => ['v' => $sub_category,     't' => 's'],
            'subcategory'      => ['v' => $sub_category,     't' => 's'],
            'item'             => ['v' => $item,             't' => 's'],
            'incident_item'    => ['v' => $item,             't' => 's'],
            'root_cause'       => ['v' => $root_cause,       't' => 's'],
            'cause'            => ['v' => $root_cause,       't' => 's'],
            'subject'          => ['v' => $subject,          't' => 's'],
            'title'            => ['v' => $subject,          't' => 's'],
            'incident_title'   => ['v' => $subject,          't' => 's'],
            'description'      => ['v' => $description,      't' => 's'],
            'details'          => ['v' => $description,      't' => 's'],
            'incident_details' => ['v' => $description,      't' => 's'],
            'narrative'        => ['v' => $description,      't' => 's'],
        ];

        /* 3. Only keep keys that actually exist as columns */
        $insert_cols   = [];
        $insert_vals   = [];
        $insert_types  = '';
        $insert_params = [];

        /* Track which "logical field" already has a column matched
           so we don't double-insert user_id vs reported_by etc. */
        $matched_logical = [];
        $logical_map = [
            'user_id'          => 'reporter',
            'reported_by'      => 'reporter',
            'resident_id'      => 'reporter',
            'request_type'     => 'rtype',
            'incident_type'    => 'rtype',
            'type'             => 'rtype',
            'status'           => 'status',
            'incident_status'  => 'status',
            'mode'             => 'mode',
            'impact'           => 'impact',
            'urgency'          => 'urgency',
            'priority'         => 'priority',
            'requester_name'   => 'reqname',
            'complainant_name' => 'reqname',
            'full_name'        => 'reqname',
            'address'          => 'addr',
            'location'         => 'addr',
            'incident_location'=> 'addr',
            'purok'            => 'purok',
            'zone'             => 'purok',
            'contact_number'   => 'contact',
            'contact'          => 'contact',
            'phone'            => 'contact',
            'age'              => 'age',
            'gender'           => 'gender',
            'civil_status'     => 'civil',
            'office'           => 'office',
            'group_name'       => 'office',
            'assigned_officer' => 'officer',
            'assigned_to'      => 'officer',
            'technician'       => 'officer',
            'category'         => 'cat',
            'incident_category'=> 'cat',
            'sub_category'     => 'subcat',
            'subcategory'      => 'subcat',
            'item'             => 'item',
            'incident_item'    => 'item',
            'root_cause'       => 'cause',
            'cause'            => 'cause',
            'subject'          => 'subj',
            'title'            => 'subj',
            'incident_title'   => 'subj',
            'description'      => 'desc',
            'details'          => 'desc',
            'incident_details' => 'desc',
            'narrative'        => 'desc',
        ];

        foreach ($db_columns as $col) {
            if (isset($all_data[$col])) {
                $logical = $logical_map[$col] ?? $col;
                if (isset($matched_logical[$logical])) continue; // skip duplicate
                $matched_logical[$logical] = true;
                $insert_cols[]   = "`$col`";
                $insert_params[] = $all_data[$col]['v'];
                $insert_types   .= $all_data[$col]['t'];
            }
        }

        /* 4. Add timestamp column only if it actually exists in the table */
        $timestamp_options = ['created_at','date_created','date_filed','reported_at','incident_date','date_added','created','timestamp'];
        foreach ($timestamp_options as $ts_col) {
            if (in_array($ts_col, $db_columns) && !in_array("`$ts_col`", $insert_cols)) {
                $insert_cols[]   = "`$ts_col`";
                $insert_params[] = date('Y-m-d H:i:s');
                $insert_types   .= 's';
                break;
            }
        }

        if (empty($insert_cols)) {
            $error_message = 'Could not match any form fields to tbl_incidents columns. Please check your database schema.';
        } else {
            /* Build SQL using ONLY matched columns — nothing hardcoded */
            $placeholders = array_fill(0, count($insert_cols), '?');
            $sql = "INSERT INTO tbl_incidents (" . implode(', ', $insert_cols) . ") VALUES (" . implode(', ', $placeholders) . ")";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param($insert_types, ...$insert_params);
                if ($stmt->execute()) {
                    $new_id = $stmt->insert_id;
                    $_SESSION['success_message'] = 'Incident #' . $new_id . ' submitted successfully.';
                    header('Location: index.php');
                    exit();
                } else {
                    $error_message = 'Database error: ' . $stmt->error . ' | Columns tried: ' . implode(', ', $insert_cols);
                }
                $stmt->close();
            } else {
                $error_message = 'Prepare error: ' . $conn->error . ' | SQL: ' . $sql;
            }
        }
    }
}

/* ── CSS ──────────────────────────────────────────────────────── */
$extra_css = '
<style>
@import url("https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;500;600;700;800&display=swap");

/* Override main-content padding */
.main-content {
    padding: 0 !important;
    background: #f3f4f6 !important;
    font-family: "Nunito Sans", sans-serif !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    height: calc(100vh - 60px) !important;
}

:root {
    --ni-white:    #ffffff;
    --ni-bg:       #f3f4f6;
    --ni-border:   #e5e7eb;
    --ni-border2:  #d1d5db;
    --ni-text:     #374151;
    --ni-light:    #6b7280;
    --ni-muted:    #9ca3af;
    --ni-blue:     #1976d2;
    --ni-blue-lt:  #e3f2fd;
    --ni-blue-dk:  #1565c0;
    --ni-red:      #ef4444;
    --ni-green:    #22c55e;
    --ni-shadow:   0 1px 3px rgba(0,0,0,.08);
}

/* ── Page header bar ── */
.ni-page-header {
    height: 52px;
    background: var(--ni-white);
    border-bottom: 1px solid var(--ni-border);
    display: flex;
    align-items: center;
    padding: 0 24px;
    gap: 14px;
    flex-shrink: 0;
    box-shadow: var(--ni-shadow);
}
.ni-back-btn {
    width: 30px; height: 30px; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--ni-border); background: var(--ni-white);
    color: var(--ni-light); cursor: pointer; font-size: 13px;
    text-decoration: none; transition: all .13s; flex-shrink: 0;
}
.ni-back-btn:hover { background: var(--ni-blue-lt); color: var(--ni-blue); border-color: #bfdbfe; text-decoration: none; }
.ni-page-title { font-size: 16px; font-weight: 800; color: var(--ni-text); }
.ni-spacer { flex: 1; }

/* Template selector */
.ni-tmpl-wrap {
    display: flex; align-items: center; gap: 10px;
}
.ni-tmpl-label { font-size: 12.5px; color: var(--ni-light); font-weight: 600; }
.ni-tmpl-box {
    display: flex; align-items: center;
    border: 1px solid var(--ni-border); border-radius: 5px;
    overflow: hidden; background: var(--ni-white); min-width: 200px;
}
.ni-tmpl-icon {
    padding: 0 10px; height: 34px; display: flex; align-items: center;
    background: #fff8e1; border-right: 1px solid var(--ni-border);
}
.ni-tmpl-icon i { color: #f59e0b; font-size: 13px; }
.ni-tmpl-sel {
    border: none; outline: none; padding: 0 10px; height: 34px;
    font-size: 12.5px; font-family: "Nunito Sans", sans-serif;
    color: var(--ni-text); background: transparent; flex: 1; cursor: pointer;
}

/* ── Scroll area ── */
.ni-scroll {
    flex: 1; overflow-y: auto; overflow-x: hidden;
    padding: 20px 24px 30px;
    background: var(--ni-bg);
}
.ni-scroll::-webkit-scrollbar { width: 5px; }
.ni-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 3px; }

/* ── Alerts ── */
.ni-alert {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 16px; border-radius: 6px;
    font-size: 13px; font-weight: 500; margin-bottom: 14px;
}
.ni-alert-ok  { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
.ni-alert-err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
.ni-alert-close { margin-left: auto; background: none; border: none; cursor: pointer; font-size: 18px; opacity: .6; line-height: 1; color: inherit; }
.ni-alert-close:hover { opacity: 1; }

/* ── Form card ── */
.ni-card {
    background: var(--ni-white);
    border: 1px solid var(--ni-border);
    border-radius: 8px;
    overflow: hidden;
    box-shadow: var(--ni-shadow);
}

/* ── Section header ── */
.ni-sec-head {
    display: flex; align-items: center; gap: 8px;
    padding: 13px 20px 12px;
    cursor: pointer; user-select: none;
    border-bottom: 1px solid var(--ni-border);
    transition: background .12s;
}
.ni-sec-head:hover { background: #fafafa; }
.ni-sec-title { font-size: 13.5px; font-weight: 800; color: var(--ni-text); }
.ni-sec-chevron { font-size: 10px; color: var(--ni-muted); margin-left: auto; transition: transform .22s; }
.ni-sec-chevron.rotated { transform: rotate(-90deg); }

/* ── Section body (collapsible) ── */
.ni-sec-body {
    overflow: hidden;
    transition: max-height .28s ease, opacity .22s ease;
    opacity: 1;
}
.ni-sec-body.collapsed { max-height: 0 !important; opacity: 0; }

/* ── Two-column grid ── */
.ni-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0;
    padding: 16px 20px 18px;
}
.ni-col-left  { padding-right: 22px; border-right: 1px solid #f3f4f6; }
.ni-col-right { padding-left: 22px; }

/* ── Form rows ── */
.ni-row {
    display: grid;
    grid-template-columns: 150px 1fr;
    align-items: start;
    gap: 10px;
    padding: 7px 0;
}
.ni-row + .ni-row { border-top: 1px solid #f9fafb; }
.ni-label {
    font-size: 12.5px; font-weight: 600; color: var(--ni-light);
    padding-top: 8px; line-height: 1.3;
}
.ni-req { color: var(--ni-red); margin-left: 2px; font-size: 11px; }

/* ── Input / Select ── */
.ni-input {
    width: 100%; padding: 7px 10px;
    border: 1px solid var(--ni-border); border-radius: 4px;
    font-size: 12.5px; font-family: "Nunito Sans", sans-serif;
    color: var(--ni-text); background: var(--ni-white);
    outline: none; transition: border-color .13s;
}
.ni-input:focus { border-color: var(--ni-blue); box-shadow: 0 0 0 2px var(--ni-blue-lt); }
.ni-input.readonly { background: #f9fafb; color: var(--ni-light); cursor: default; }
.ni-input::placeholder { color: var(--ni-muted); }

.ni-select {
    width: 100%; padding: 7px 28px 7px 10px;
    border: 1px solid var(--ni-border); border-radius: 4px;
    font-size: 12.5px; font-family: "Nunito Sans", sans-serif;
    color: var(--ni-text); background: var(--ni-white);
    outline: none; cursor: pointer; appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'10\' height=\'6\'%3E%3Cpath d=\'M0 0l5 6 5-6z\' fill=\'%239ca3af\'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 10px center;
    transition: border-color .13s;
}
.ni-select:focus { border-color: var(--ni-blue); box-shadow: 0 0 0 2px var(--ni-blue-lt); }

/* Select with X button */
.ni-select-wrap { position: relative; display: flex; align-items: center; }
.ni-select-wrap .ni-select { padding-right: 52px; }
.ni-select-clear {
    position: absolute; right: 26px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--ni-muted); cursor: pointer;
    font-size: 11px; padding: 2px 4px;
}
.ni-select-clear:hover { color: var(--ni-red); }

/* Input with icon */
.ni-input-icon-wrap { position: relative; }
.ni-input-icon-wrap .ni-input { padding-right: 34px; }
.ni-input-icon-wrap .ni-icon {
    position: absolute; right: 9px; top: 50%; transform: translateY(-50%);
    color: var(--ni-muted); font-size: 13px; cursor: pointer;
}
.ni-input-icon-wrap .ni-icon:hover { color: var(--ni-blue); }

/* Input with + btn */
.ni-input-plus { display: flex; }
.ni-input-plus .ni-input { border-radius: 4px 0 0 4px; flex: 1; }
.ni-plus-btn {
    width: 34px; height: 34px;
    border: 1px solid var(--ni-border); border-left: none;
    border-radius: 0 4px 4px 0; background: #f9fafb; color: var(--ni-blue);
    cursor: pointer; font-size: 13px; display: flex; align-items: center;
    justify-content: center; transition: all .13s; flex-shrink: 0;
}
.ni-plus-btn:hover { background: var(--ni-blue-lt); }

/* Full-width rows */
.ni-fullrow {
    padding: 10px 20px 14px;
    border-top: 1px solid #f3f4f6;
}
.ni-fullrow .ni-row { grid-template-columns: 150px 1fr; }

/* ── Rich text ── */
.ni-rich-toolbar {
    display: flex; align-items: center; flex-wrap: wrap; gap: 1px;
    padding: 5px 8px;
    border: 1px solid var(--ni-border); border-radius: 4px 4px 0 0;
    background: #f9fafb; border-bottom: none;
}
.ni-rt-btn {
    width: 26px; height: 26px; border-radius: 3px;
    display: flex; align-items: center; justify-content: center;
    border: none; background: transparent; color: var(--ni-light);
    cursor: pointer; font-size: 12px; font-family: inherit; font-weight: 600;
    transition: all .1s;
}
.ni-rt-btn:hover { background: #e5e7eb; color: var(--ni-text); }
.ni-rt-sep { width: 1px; height: 18px; background: var(--ni-border); margin: 0 3px; flex-shrink: 0; }
.ni-rt-select {
    height: 26px; border: 1px solid var(--ni-border); border-radius: 3px;
    font-size: 11.5px; font-family: inherit; color: var(--ni-text);
    background: var(--ni-white); padding: 0 5px; outline: none; cursor: pointer;
}
.ni-rich-body {
    border: 1px solid var(--ni-border); border-top: none;
    border-radius: 0 0 4px 4px;
    min-height: 140px; padding: 10px; font-size: 12.5px;
    color: var(--ni-text); outline: none; line-height: 1.6;
    background: var(--ni-white);
}
.ni-rich-body:focus { border-color: var(--ni-blue); }

/* ── Attachments ── */
.ni-attach-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 11px 20px; border-top: 1px solid var(--ni-border);
}
.ni-attach-title { font-size: 12.5px; font-weight: 700; color: var(--ni-text); }
.ni-attach-btns { display: flex; gap: 5px; }
.ni-attach-btn {
    width: 26px; height: 26px; border-radius: 4px;
    display: flex; align-items: center; justify-content: center;
    border: 1px solid var(--ni-border); background: var(--ni-white);
    color: var(--ni-muted); cursor: pointer; font-size: 11px; transition: all .13s;
}
.ni-attach-btn:hover { background: var(--ni-blue-lt); color: var(--ni-blue); border-color: #bfdbfe; }
.ni-drop-zone {
    margin: 0 20px 16px;
    border: 1.5px dashed var(--ni-border2); border-radius: 6px;
    padding: 20px; text-align: center; cursor: pointer;
    background: #fafafa; transition: all .13s;
}
.ni-drop-zone:hover,
.ni-drop-zone.over { border-color: var(--ni-blue); background: var(--ni-blue-lt); }
.ni-drop-zone p { font-size: 12.5px; color: var(--ni-muted); margin: 0; }
.ni-file-list { padding: 0 20px 8px; }
.ni-file-item {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 0; border-bottom: 1px solid #f3f4f6;
    font-size: 12px; color: var(--ni-text);
}
.ni-file-remove {
    background: none; border: none; color: var(--ni-muted);
    cursor: pointer; font-size: 12px; padding: 1px 4px; margin-left: auto;
}
.ni-file-remove:hover { color: var(--ni-red); }

/* ── Privacy ── */
.ni-privacy {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 12px 20px; border-top: 1px solid var(--ni-border);
}
.ni-privacy-cb { margin-top: 2px; width: 15px; height: 15px; cursor: pointer; accent-color: var(--ni-blue); flex-shrink: 0; }
.ni-privacy-text { font-size: 11.5px; color: var(--ni-light); line-height: 1.6; }

/* ── Resolution section ── */
.ni-resolution-head {
    display: flex; align-items: center; gap: 8px; padding: 13px 20px;
    cursor: pointer; user-select: none; border-top: 1px solid var(--ni-border);
    transition: background .12s;
}
.ni-resolution-head:hover { background: #fafafa; }
.ni-resolution-title { font-size: 13.5px; font-weight: 800; color: var(--ni-text); }

/* ── Footer buttons ── */
.ni-footer {
    display: flex; align-items: center; justify-content: center;
    gap: 10px; padding: 20px;
    border-top: 1px solid var(--ni-border);
    background: var(--ni-white);
}
.ni-btn-submit {
    padding: 8px 24px; border-radius: 5px;
    font-size: 13px; font-weight: 700; font-family: inherit;
    background: var(--ni-blue); color: #fff; border: none; cursor: pointer;
    transition: background .13s;
}
.ni-btn-submit:hover { background: var(--ni-blue-dk); }
.ni-btn-reset, .ni-btn-cancel {
    padding: 8px 18px; border-radius: 5px;
    font-size: 13px; font-weight: 600; font-family: inherit;
    background: var(--ni-white); color: var(--ni-light);
    border: 1px solid var(--ni-border); cursor: pointer; transition: all .13s;
    text-decoration: none; display: inline-flex; align-items: center;
}
.ni-btn-reset:hover  { border-color: var(--ni-blue); color: var(--ni-blue); background: var(--ni-blue-lt); }
.ni-btn-cancel:hover { border-color: var(--ni-red); color: var(--ni-red); background: #fef2f2; }

/* ── Dark mode overrides ── */
body.dark-mode .ni-card,
body.dark-mode .ni-page-header { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .ni-page-title,
body.dark-mode .ni-sec-title,
body.dark-mode .ni-resolution-title { color: #f1f5f9 !important; }
body.dark-mode .ni-label { color: #94a3b8 !important; }
body.dark-mode .ni-input,
body.dark-mode .ni-select,
body.dark-mode .ni-rich-body {
    background: #334155 !important; color: #e2e8f0 !important;
    border-color: #475569 !important;
}
body.dark-mode .ni-rich-toolbar { background: #243044 !important; border-color: #475569 !important; }
body.dark-mode .ni-scroll { background: #0f172a !important; }
body.dark-mode .ni-sec-head:hover,
body.dark-mode .ni-resolution-head:hover { background: #243044 !important; }
body.dark-mode .ni-footer { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .ni-drop-zone { background: #243044 !important; border-color: #475569 !important; }
body.dark-mode .ni-tmpl-box { background: #1e293b !important; border-color: #334155 !important; }
body.dark-mode .ni-tmpl-sel { background: transparent !important; color: #e2e8f0 !important; }

@media (max-width: 900px) {
    .ni-grid { grid-template-columns: 1fr; }
    .ni-col-left { padding-right: 0; border-right: none; padding-bottom: 8px; border-bottom: 1px solid #f3f4f6; }
    .ni-col-right { padding-left: 0; }
    .ni-row { grid-template-columns: 130px 1fr; }
}
@media (max-width: 600px) {
    .ni-row { grid-template-columns: 1fr; }
    .ni-label { padding-top: 0; }
    .ni-page-header { padding: 0 12px; gap: 8px; }
    .ni-scroll { padding: 12px; }
    .ni-tmpl-wrap { display: none; }
}
</style>';

include '../../includes/header.php';
?>

<!-- ══ PAGE HEADER ══ -->
<div class="ni-page-header">
    <a href="javascript:history.back()" class="ni-back-btn" title="Go back">
        <i class="fas fa-arrow-left"></i>
    </a>
    <div class="ni-page-title">New Incident</div>
    <div class="ni-spacer"></div>
    <div class="ni-tmpl-wrap">
        <span class="ni-tmpl-label">Select Template</span>
        <div class="ni-tmpl-box">
            <div class="ni-tmpl-icon"><i class="fas fa-fire-alt"></i></div>
            <select class="ni-tmpl-sel" id="templateSelect" onchange="applyTemplate(this.value)">
                <option value="incident">Barangay Incident</option>
                <option value="complaint">Complaint Report</option>
                <option value="blotter">Blotter Entry</option>
                <option value="community">Community Request</option>
                <option value="medical">Medical Assistance</option>
            </select>
        </div>
    </div>
</div>

<!-- ══ SCROLL AREA ══ -->
<div class="ni-scroll">

    <?php if ($error_message): ?>
    <div class="ni-alert ni-alert-err" id="niAlertErr">
        <i class="fas fa-exclamation-circle"></i>
        <?= $error_message ?>
        <button class="ni-alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <?php if ($success_message): ?>
    <div class="ni-alert ni-alert-ok" id="niAlertOk">
        <i class="fas fa-check-circle"></i>
        <?= htmlspecialchars($success_message) ?>
        <button class="ni-alert-close" onclick="this.parentElement.remove()">×</button>
    </div>
    <?php endif; ?>

    <form id="incidentForm" method="POST" enctype="multipart/form-data">
    <input type="hidden" name="submit_incident" value="1">

    <div class="ni-card">

        <!-- ════════════════════════════
             TICKET DETAILS
        ════════════════════════════ -->
        <div class="ni-sec-head" onclick="toggleSec('secTicket', this)">
            <div class="ni-sec-title">Ticket Details</div>
            <i class="fas fa-chevron-down ni-sec-chevron" id="chvTicket"></i>
        </div>
        <div class="ni-sec-body" id="secTicket">
            <div class="ni-grid">
                <!-- Left -->
                <div class="ni-col-left">
                    <div class="ni-row">
                        <div class="ni-label">Request Type</div>
                        <input class="ni-input readonly" value="Incident" readonly>
                        <input type="hidden" name="request_type" value="Incident">
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Status</div>
                        <input class="ni-input readonly" value="Open" readonly>
                        <input type="hidden" name="status" value="Open">
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Mode <span class="ni-req">*</span></div>
                        <select class="ni-select" name="mode" required>
                            <option value="" disabled selected>-- Select Mode --</option>
                            <option value="Walk-In" <?= ($_POST['mode']??'')==='Walk-In'?'selected':'' ?>>Walk-In</option>
                            <option value="Phone Call" <?= ($_POST['mode']??'')==='Phone Call'?'selected':'' ?>>Phone Call</option>
                            <option value="Online Portal" <?= ($_POST['mode']??'')==='Online Portal'?'selected':'' ?>>Online Portal</option>
                            <option value="SMS" <?= ($_POST['mode']??'')==='SMS'?'selected':'' ?>>SMS</option>
                            <option value="Email" <?= ($_POST['mode']??'')==='Email'?'selected':'' ?>>Email</option>
                        </select>
                    </div>
                </div>
                <!-- Right -->
                <div class="ni-col-right">
                    <div class="ni-row">
                        <div class="ni-label">Impact <span class="ni-req">*</span></div>
                        <select class="ni-select" name="impact" required>
                            <option value="" disabled selected>-- Select Impact --</option>
                            <option value="Affects Individual">Affects Individual</option>
                            <option value="Affects Household">Affects Household</option>
                            <option value="Affects Street / Sitio">Affects Street / Sitio</option>
                            <option value="Affects Entire Barangay">Affects Entire Barangay</option>
                            <option value="Affects Multiple Barangays">Affects Multiple Barangays</option>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Urgency <span class="ni-req">*</span></div>
                        <select class="ni-select" name="urgency" id="urgencySelect" onchange="calcPriority()" required>
                            <option value="" disabled selected>-- Select Urgency --</option>
                            <option value="Low">Low</option>
                            <option value="Medium">Medium</option>
                            <option value="High">High</option>
                            <option value="Critical">Critical</option>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Priority</div>
                        <input class="ni-input readonly" id="priorityInput" name="priority" value="-- Select Priority --" readonly style="color:var(--ni-muted)">
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════
             REQUESTER DETAILS
        ════════════════════════════ -->
        <div class="ni-sec-head" onclick="toggleSec('secRequester', this)">
            <div class="ni-sec-title">Requester Details</div>
            <i class="fas fa-chevron-down ni-sec-chevron" id="chvRequester"></i>
        </div>
        <div class="ni-sec-body" id="secRequester">
            <div class="ni-grid">
                <!-- Left -->
                <div class="ni-col-left">
                    <div class="ni-row">
                        <div class="ni-label">Requester Name <span class="ni-req">*</span></div>
                        <div class="ni-input-icon-wrap">
                            <input class="ni-input" name="requester_name" id="requesterName"
                                   placeholder="-- Select Requester Name --"
                                   value="<?= htmlspecialchars($_POST['requester_name']??'') ?>" required>
                            <span class="ni-icon"><i class="fas fa-user-plus"></i></span>
                        </div>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Address <span class="ni-req">*</span></div>
                        <input class="ni-input" name="address"
                               placeholder="House No., Street, Sitio"
                               value="<?= htmlspecialchars($_POST['address']??'') ?>" required>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Purok / Zone</div>
                        <select class="ni-select" name="purok">
                            <option value="" disabled selected>-- Select Purok --</option>
                            <?php for($p=1;$p<=8;$p++): ?>
                            <option value="Purok <?=$p?>" <?=($_POST['purok']??'')==="Purok $p"?'selected':''?>>Purok <?=$p?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Contact Number <span class="ni-req">*</span></div>
                        <input class="ni-input" name="contact"
                               placeholder="09XX-XXX-XXXX"
                               value="<?= htmlspecialchars($_POST['contact']??'') ?>" required>
                    </div>
                </div>
                <!-- Right -->
                <div class="ni-col-right">
                    <div class="ni-row">
                        <div class="ni-label">Assets / Property</div>
                        <div class="ni-input-plus">
                            <input class="ni-input" name="assets" placeholder="-- Select Assets --">
                            <button type="button" class="ni-plus-btn"><i class="fas fa-plus"></i></button>
                        </div>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Age</div>
                        <input class="ni-input" name="age" type="number"
                               placeholder="Enter age" min="1" max="120"
                               value="<?= htmlspecialchars($_POST['age']??'') ?>">
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Gender</div>
                        <select class="ni-select" name="gender">
                            <option value="" disabled selected>-- Select Gender --</option>
                            <option value="Male"   <?=($_POST['gender']??'')==='Male'?'selected':''?>>Male</option>
                            <option value="Female" <?=($_POST['gender']??'')==='Female'?'selected':''?>>Female</option>
                            <option value="Prefer not to say">Prefer not to say</option>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Civil Status</div>
                        <select class="ni-select" name="civil_status">
                            <option value="" disabled selected>-- Select --</option>
                            <option value="Single"    <?=($_POST['civil_status']??'')==='Single'?'selected':''?>>Single</option>
                            <option value="Married"   <?=($_POST['civil_status']??'')==='Married'?'selected':''?>>Married</option>
                            <option value="Widowed"   <?=($_POST['civil_status']??'')==='Widowed'?'selected':''?>>Widowed</option>
                            <option value="Separated" <?=($_POST['civil_status']??'')==='Separated'?'selected':''?>>Separated</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════
             BARANGAY OFFICIAL / TECHNICIAN DETAILS
        ════════════════════════════ -->
        <div class="ni-sec-head" onclick="toggleSec('secOfficial', this)">
            <div class="ni-sec-title">Technician Details</div>
            <i class="fas fa-chevron-down ni-sec-chevron" id="chvOfficial"></i>
        </div>
        <div class="ni-sec-body" id="secOfficial">
            <div class="ni-grid">
                <!-- Left -->
                <div class="ni-col-left">
                    <div class="ni-row">
                        <div class="ni-label">Group</div>
                        <div class="ni-select-wrap">
                            <select class="ni-select" name="office" id="officeSelect">
                                <option value="" disabled>-- Select Office --</option>
                                <option value="Barangay" selected>Barangay</option>
                                <option value="BCPC">BCPC</option>
                                <option value="VAWC Desk">VAWC Desk</option>
                                <option value="Lupon Tagapamayapa">Lupon Tagapamayapa</option>
                                <option value="Health Center">Health Center</option>
                                <option value="SK Council">SK Council</option>
                            </select>
                            <button type="button" class="ni-select-clear" onclick="document.getElementById('officeSelect').selectedIndex=0"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Technician</div>
                        <select class="ni-select" name="assigned_officer">
                            <option value="" disabled selected>-- Select Technician --</option>
                            <option value="Barangay Captain">Barangay Captain</option>
                            <option value="Barangay Kagawad 1">Barangay Kagawad 1</option>
                            <option value="Barangay Kagawad 2">Barangay Kagawad 2</option>
                            <option value="Barangay Kagawad 3">Barangay Kagawad 3</option>
                            <option value="Barangay Secretary">Barangay Secretary</option>
                            <option value="Barangay Treasurer">Barangay Treasurer</option>
                            <option value="SK Chairman">SK Chairman</option>
                        </select>
                    </div>
                </div>
                <!-- Right -->
                <div class="ni-col-right">
                    <div class="ni-row">
                        <div class="ni-label">Category <span class="ni-req">*</span></div>
                        <select class="ni-select" name="category" id="categorySelect" onchange="updateSubcategory()" required>
                            <option value="" disabled selected>-- Select Category --</option>
                            <option value="Peace &amp; Order">Peace &amp; Order</option>
                            <option value="Health &amp; Sanitation">Health &amp; Sanitation</option>
                            <option value="Infrastructure">Infrastructure</option>
                            <option value="Social Services">Social Services</option>
                            <option value="Environmental">Environmental</option>
                            <option value="Legal / Documentation">Legal / Documentation</option>
                            <option value="Youth &amp; Sports">Youth &amp; Sports</option>
                            <option value="Disaster / Calamity">Disaster / Calamity</option>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Sub Category <span class="ni-req">*</span></div>
                        <select class="ni-select" name="sub_category" id="subCategorySelect" onchange="updateItem()" required>
                            <option value="" disabled selected>-- Select Sub Category --</option>
                        </select>
                    </div>
                    <div class="ni-row">
                        <div class="ni-label">Item <span class="ni-req">*</span></div>
                        <select class="ni-select" name="item" id="itemSelect" required>
                            <option value="" disabled selected>-- Select Item --</option>
                        </select>
                    </div>
                </div>
            </div>
            <!-- Root Cause — full width -->
            <div class="ni-fullrow">
                <div class="ni-row">
                    <div class="ni-label">Root Cause <span class="ni-req">*</span></div>
                    <input class="ni-input" name="root_cause"
                           placeholder="Describe the root cause of the incident..."
                           value="<?= htmlspecialchars($_POST['root_cause']??'') ?>" required>
                </div>
            </div>
        </div>

        <!-- ════════════════════════════
             REQUEST / INCIDENT DETAILS
        ════════════════════════════ -->
        <div class="ni-sec-head" style="cursor:default">
            <div class="ni-sec-title">Request</div>
        </div>

        <div class="ni-fullrow" style="border-top: none">
            <div class="ni-row">
                <div class="ni-label">Subject <span class="ni-req">*</span></div>
                <input class="ni-input" name="subject" id="subjectInput"
                       placeholder="Brief title of the incident"
                       value="<?= htmlspecialchars($_POST['subject']??'') ?>" required>
            </div>
        </div>

        <div style="padding: 4px 20px 0">
            <div class="ni-row" style="align-items:start">
                <div class="ni-label" style="padding-top:10px">Description</div>
                <div>
                    <div class="ni-rich-toolbar">
                        <button type="button" class="ni-rt-btn" onclick="fmt('bold')" title="Bold"><b>B</b></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('italic')" title="Italic"><i>I</i></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('underline')" title="Underline"><u>U</u></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('strikeThrough')" title="Strike"><s>S</s></button>
                        <div class="ni-rt-sep"></div>
                        <select class="ni-rt-select" onchange="fmt('fontName',this.value)">
                            <option>PT Sans</option><option>Nunito Sans</option><option>Arial</option><option>Courier New</option>
                        </select>
                        <select class="ni-rt-select" style="width:50px" onchange="fmt('fontSize',this.value)">
                            <option>10</option><option>12</option><option selected>14</option><option>16</option><option>18</option><option>24</option>
                        </select>
                        <div class="ni-rt-sep"></div>
                        <button type="button" class="ni-rt-btn" onclick="fmt('justifyLeft')" title="Left"><i class="fas fa-align-left" style="font-size:10px"></i></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('justifyCenter')" title="Center"><i class="fas fa-align-center" style="font-size:10px"></i></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('justifyRight')" title="Right"><i class="fas fa-align-right" style="font-size:10px"></i></button>
                        <div class="ni-rt-sep"></div>
                        <button type="button" class="ni-rt-btn" onclick="fmt('insertUnorderedList')" title="Bullets"><i class="fas fa-list-ul" style="font-size:10px"></i></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('insertOrderedList')" title="Numbered"><i class="fas fa-list-ol" style="font-size:10px"></i></button>
                        <div class="ni-rt-sep"></div>
                        <button type="button" class="ni-rt-btn" onclick="fmt('indent')" title="Indent"><i class="fas fa-indent" style="font-size:10px"></i></button>
                        <button type="button" class="ni-rt-btn" onclick="fmt('outdent')" title="Outdent"><i class="fas fa-outdent" style="font-size:10px"></i></button>
                        <div class="ni-rt-sep"></div>
                        <button type="button" class="ni-rt-btn" title="Code"><i class="fas fa-code" style="font-size:10px"></i></button>
                        <button type="button" class="ni-rt-btn" title="Expand"><i class="fas fa-expand-alt" style="font-size:10px"></i></button>
                    </div>
                    <div class="ni-rich-body" id="descEditor" contenteditable="true"></div>
                    <input type="hidden" name="description" id="descHidden">
                </div>
            </div>
        </div>

        <!-- ── Attachments ── -->
        <div class="ni-attach-bar" style="margin-top:14px">
            <div class="ni-attach-title">Attachments</div>
            <div class="ni-attach-btns">
                <!-- label trick: clicking label triggers file input reliably cross-browser -->
                <label for="fileInput" class="ni-attach-btn" title="Add file" style="cursor:pointer;margin:0">
                    <i class="fas fa-plus"></i>
                </label>
                <button type="button" class="ni-attach-btn" title="Options"><i class="fas fa-chevron-down"></i></button>
            </div>
        </div>
        <!-- opacity:0 + position:absolute is more reliable than display:none for programmatic clicks -->
        <input type="file" id="fileInput" name="attachments[]" multiple
               style="position:absolute;width:1px;height:1px;opacity:0;overflow:hidden;z-index:-1"
               onchange="handleFiles(this)">
        <label for="fileInput" class="ni-drop-zone" id="dropZone" style="display:block;cursor:pointer"
             ondragover="event.preventDefault();this.classList.add('over')"
             ondragleave="this.classList.remove('over')"
             ondrop="handleDrop(event)"
             onclick="event.preventDefault();document.getElementById('fileInput').click()">
            <p>
                <i class="fas fa-cloud-upload-alt" style="font-size:20px;display:block;margin-bottom:6px;color:var(--ni-muted)"></i>
                Drag and drop files here or <span style="color:var(--ni-blue);font-weight:700;text-decoration:underline">click to browse</span>
            </p>
        </label>
        <div class="ni-file-list" id="fileList" style="display:none"></div>

        <!-- ── Privacy Notice ── -->
        <div class="ni-privacy">
            <input type="checkbox" class="ni-privacy-cb" name="privacy_notice" id="privacyCb" required>
            <label for="privacyCb" class="ni-privacy-text">
                By checking this box, you consent to the use of your personal info and incident details to address your
                request. Data is kept only as needed per policy. For privacy concerns, contact the Barangay Secretary.
            </label>
        </div>

        <!-- ── Resolution (collapsed by default) ── -->
        <div class="ni-resolution-head" onclick="toggleSec('secResolution', this)">
            <i class="fas fa-chevron-right" style="font-size:10px;color:var(--ni-muted)"></i>
            <div class="ni-resolution-title">Resolution</div>
            <i class="fas fa-chevron-down ni-sec-chevron rotated" id="chvResolution" style="margin-left:auto"></i>
        </div>
        <div class="ni-sec-body collapsed" id="secResolution" style="max-height:0">
            <div class="ni-fullrow" style="border-top:none">
                <div class="ni-row" style="align-items:start">
                    <div class="ni-label">Resolution Notes</div>
                    <textarea class="ni-input" name="resolution_notes" rows="4"
                              placeholder="Enter resolution details..."
                              style="resize:vertical;min-height:80px"></textarea>
                </div>
                <div class="ni-row" style="margin-top:6px">
                    <div class="ni-label">Resolved By</div>
                    <input class="ni-input" name="resolved_by" placeholder="Officer name">
                </div>
                <div class="ni-row">
                    <div class="ni-label">Resolution Date</div>
                    <input class="ni-input" name="resolution_date" type="date">
                </div>
            </div>
        </div>

        <!-- ── Form footer ── -->
        <div class="ni-footer">
            <button type="submit" class="ni-btn-submit">
                <i class="fas fa-paper-plane" style="margin-right:5px;font-size:11px"></i> Add Request
            </button>
            <button type="button" class="ni-btn-reset" onclick="resetForm()">Reset</button>
            <a href="javascript:history.back()" class="ni-btn-cancel">Cancel</a>
        </div>

    </div><!-- /ni-card -->
    </form>

</div><!-- /ni-scroll -->

<script>
/* ══ Category → Subcategory → Item ══ */
const catMap = {
    'Peace & Order': {
        'Disturbance':         ['Noise Complaint','Public Nuisance','Illegal Gathering'],
        'Physical Altercation':['Fistfight','Assault','Domestic Violence'],
        'Property Dispute':    ['Land Boundary','Trespassing','Vandalism'],
        'Illegal Activities':  ['Drug Related','Theft / Robbery','Illegal Gambling'],
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
        '4Ps / Welfare':  ['Beneficiary Issue','New Application','Update Records'],
        'Senior Citizens':['Pension Issue','ID Application','Assistance Request'],
        'PWD Services':   ['ID Application','Benefit Claim','Accessibility Concern'],
        'Livelihood':     ['Skills Training','Capital Assistance','Business Permit Help'],
    },
    'Environmental': {
        'Illegal Logging':['Tree Cutting','Forest Encroachment'],
        'Pollution':      ['Air Pollution','Water Pollution','Noise Pollution'],
        'Animal Control': ['Stray Animal','Rabies Concern','Livestock Complaint'],
    },
    'Legal / Documentation': {
        'Certificates':['Barangay Clearance','Certificate of Residency','Certificate of Indigency'],
        'Blotter':     ['New Blotter Entry','Blotter Follow-up'],
        'Mediation':   ['Conciliation Request','Lupon Referral'],
    },
    'Youth & Sports': {
        'SK Programs':     ['Youth Assembly','SK Project Request'],
        'Sports Facilities':['Court Reservation','Equipment Request'],
        'Youth Activities':['Training / Seminar','Cultural Event'],
    },
    'Disaster / Calamity': {
        'Flood':     ['Evacuation Request','Relief Goods','Damage Assessment'],
        'Fire':      ['Post-Fire Assistance','Temporary Shelter'],
        'Typhoon':   ['Roof Damage','Relief Assistance','Tree Fallen on Property'],
        'Earthquake':['Structural Damage Report','Injury Report'],
    },
};

function updateSubcategory() {
    const cat   = document.getElementById('categorySelect').value;
    const subSel = document.getElementById('subCategorySelect');
    const itemSel = document.getElementById('itemSelect');
    subSel.innerHTML  = '<option value="" disabled selected>-- Select Sub Category --</option>';
    itemSel.innerHTML = '<option value="" disabled selected>-- Select Item --</option>';
    if (cat && catMap[cat]) {
        Object.keys(catMap[cat]).forEach(sub => {
            subSel.innerHTML += `<option value="${sub}">${sub}</option>`;
        });
    }
}

function updateItem() {
    const cat = document.getElementById('categorySelect').value;
    const sub = document.getElementById('subCategorySelect').value;
    const itemSel = document.getElementById('itemSelect');
    itemSel.innerHTML = '<option value="" disabled selected>-- Select Item --</option>';
    if (cat && sub && catMap[cat] && catMap[cat][sub]) {
        catMap[cat][sub].forEach(item => {
            itemSel.innerHTML += `<option value="${item}">${item}</option>`;
        });
    }
}

/* ══ Priority auto-calc ══ */
function calcPriority() {
    const urgency = document.getElementById('urgencySelect').value;
    const map = { 'Low': 'Low', 'Medium': 'Medium', 'High': 'High', 'Critical': 'Critical' };
    const pInput = document.getElementById('priorityInput');
    if (map[urgency]) {
        pInput.value = map[urgency];
        pInput.style.color = '';
    } else {
        pInput.value = '-- Select Priority --';
        pInput.style.color = 'var(--ni-muted)';
    }
}

/* ══ Template presets ══ */
function applyTemplate(val) {
    const catSel = document.getElementById('categorySelect');
    const templates = {
        complaint: { cat: 'Peace & Order' },
        blotter:   { cat: 'Legal / Documentation' },
        community: { cat: 'Social Services' },
        medical:   { cat: 'Health & Sanitation' },
    };
    if (templates[val]) {
        catSel.value = templates[val].cat;
        updateSubcategory();
    }
}

/* ══ Collapsible sections ══ */
function toggleSec(id, head) {
    const body = document.getElementById(id);
    const chevron = head ? head.querySelector('.ni-sec-chevron') : null;
    const isCollapsed = body.classList.toggle('collapsed');
    if (!isCollapsed) {
        body.style.maxHeight = body.scrollHeight + 200 + 'px';
    } else {
        body.style.maxHeight = '0';
    }
    if (chevron) {
        chevron.classList.toggle('rotated', isCollapsed);
    }
}

/* Init section heights */
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.ni-sec-body:not(.collapsed)').forEach(el => {
        el.style.maxHeight = el.scrollHeight + 200 + 'px';
    });
});

/* ══ Rich text ══ */
function fmt(cmd, val) {
    document.execCommand(cmd, false, val || null);
    document.getElementById('descEditor').focus();
}

/* ══ File handling ══ */
function handleFiles(input) {
    if (input.files && input.files.length > 0) {
        addToList(Array.from(input.files));
    }
}
function handleDrop(e) {
    e.preventDefault();
    e.stopPropagation();
    document.getElementById('dropZone').classList.remove('over');
    if (e.dataTransfer && e.dataTransfer.files.length > 0) {
        addToList(Array.from(e.dataTransfer.files));
    }
}
function addToList(files) {
    const fl = document.getElementById('fileList');
    fl.style.display = 'block';
    const iconMap = {
        jpg:'fa-image', jpeg:'fa-image', png:'fa-image',
        gif:'fa-image', webp:'fa-image', pdf:'fa-file-pdf',
        doc:'fa-file-word', docx:'fa-file-word',
        xls:'fa-file-excel', xlsx:'fa-file-excel',
        zip:'fa-file-archive', rar:'fa-file-archive',
    };
    files.forEach(f => {
        const div = document.createElement('div');
        div.className = 'ni-file-item';
        const ext = f.name.split('.').pop().toLowerCase();
        const icon = iconMap[ext] || 'fa-file';
        const size = f.size > 1048576
            ? (f.size/1048576).toFixed(1) + ' MB'
            : (f.size/1024).toFixed(1) + ' KB';
        div.innerHTML = `
            <i class="fas ${icon}" style="color:var(--ni-blue);font-size:13px;width:16px;flex-shrink:0"></i>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</span>
            <span style="color:var(--ni-muted);font-size:11px;flex-shrink:0;margin-left:8px">${size}</span>
            <button type="button" class="ni-file-remove" onclick="this.closest('.ni-file-item').remove()">
                <i class="fas fa-times"></i>
            </button>`;
        fl.appendChild(div);
    });

    /* Reset input so same file can be re-added if removed */
    const inp = document.getElementById('fileInput');
    inp.value = '';
}

/* Open file dialog programmatically — works in all browsers */
function openFilePicker() {
    document.getElementById('fileInput').click();
}

/* ══ Form submit — sync description ══ */
document.getElementById('incidentForm').addEventListener('submit', function() {
    document.getElementById('descHidden').value = document.getElementById('descEditor').innerHTML;
});

/* ══ Reset ══ */
function resetForm() {
    document.getElementById('incidentForm').reset();
    document.getElementById('descEditor').innerHTML = '';
    document.getElementById('subCategorySelect').innerHTML = '<option value="" disabled selected>-- Select Sub Category --</option>';
    document.getElementById('itemSelect').innerHTML = '<option value="" disabled selected>-- Select Item --</option>';
    document.getElementById('priorityInput').value = '-- Select Priority --';
    document.getElementById('priorityInput').style.color = 'var(--ni-muted)';
    document.getElementById('fileList').innerHTML = '';
    document.getElementById('fileList').style.display = 'none';
}

/* ══ Auto-dismiss server alerts ══ */
setTimeout(function() {
    ['niAlertErr','niAlertOk'].forEach(id => {
        const el = document.getElementById(id);
        if (el) { el.style.transition='opacity .4s'; el.style.opacity='0'; setTimeout(()=>el.remove(),400); }
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>