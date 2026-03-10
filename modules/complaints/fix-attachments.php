<?php
/**
 * fix-attachments.php
 * Fixes tbl_complaint_attachments records that have wrong/mismatched complaint_id.
 * DELETE this file after use.
 */
require_once '../../config/database.php';

echo "<style>
body{font-family:monospace;padding:24px;background:#f8fafc;color:#0f172a;}
h2{color:#0d1b36;border-bottom:2px solid #e2e8f0;padding-bottom:8px;}
h3{color:#1c3461;margin-top:20px;}
table{border-collapse:collapse;width:100%;margin-bottom:16px;}
td,th{border:1px solid #e2e8f0;padding:8px 12px;font-size:12px;}
th{background:#0d1b36;color:#fff;}
tr:nth-child(even){background:#f1f5f9;}
.ok{color:#10b981;font-weight:bold;}
.bad{color:#e11d48;font-weight:bold;}
.warn{color:#f59e0b;font-weight:bold;}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:16px;}
.btn{display:inline-block;padding:10px 20px;background:#0d1b36;color:#fff;border-radius:7px;text-decoration:none;font-weight:bold;margin-right:8px;margin-top:8px;}
.btn-green{background:#10b981;}
.log{background:#0f172a;color:#a3e635;padding:14px;border-radius:8px;font-size:12px;line-height:2;}
</style>";

echo "<h2>🔧 Attachment Records Repair</h2>";

$upload_fs = $_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/complaints/';

// ── Check if table exists ──
$tc = $conn->query("SHOW TABLES LIKE 'tbl_complaint_attachments'");
if (!$tc || $tc->num_rows === 0) {
    echo "<p class='bad'>tbl_complaint_attachments does not exist.</p>";
    exit();
}

// ── Show all complaints ──
echo "<div class='box'><h3>All Complaints</h3>";
$comps = $conn->query("SELECT complaint_id, complaint_number FROM tbl_complaints ORDER BY complaint_id");
echo "<table><tr><th>complaint_id</th><th>complaint_number</th></tr>";
while ($c = $comps->fetch_assoc()) {
    echo "<tr><td>{$c['complaint_id']}</td><td>{$c['complaint_number']}</td></tr>";
}
echo "</table></div>";

// ── Show all attachment records ──
echo "<div class='box'><h3>All tbl_complaint_attachments Records</h3>";
$atts = $conn->query("SELECT a.*, c.complaint_number 
                      FROM tbl_complaint_attachments a 
                      LEFT JOIN tbl_complaints c ON a.complaint_id = c.complaint_id 
                      ORDER BY a.complaint_id");
echo "<table><tr><th>att_id</th><th>complaint_id</th><th>complaint_number</th><th>file_path</th><th>file exists?</th><th>issue?</th></tr>";
$orphaned = [];
while ($a = $atts->fetch_assoc()) {
    $bn     = basename($a['file_path']);
    $exists = file_exists($upload_fs . $bn) ? "<span class='ok'>YES</span>" : "<span class='bad'>NO</span>";
    $issue  = '';
    if (empty($a['complaint_number'])) {
        $issue = "<span class='bad'>complaint_id={$a['complaint_id']} not in tbl_complaints!</span>";
        $orphaned[] = $a;
    }
    // Extract ID from filename e.g. complaint_0_... or complaint_4_...
    preg_match('/^complaint_(\d+)_/', $bn, $m);
    $file_id = isset($m[1]) ? (int)$m[1] : null;
    if ($file_id !== null && $file_id != $a['complaint_id'] && empty($issue)) {
        $issue = "<span class='warn'>filename says id=$file_id but record says {$a['complaint_id']}</span>";
    }
    echo "<tr>
        <td>{$a['id']}</td>
        <td>{$a['complaint_id']}</td>
        <td>" . htmlspecialchars($a['complaint_number'] ?? '—') . "</td>
        <td style='font-size:11px'>" . htmlspecialchars($bn) . "</td>
        <td>$exists</td>
        <td>$issue</td>
    </tr>";
}
echo "</table></div>";

// ── Scan uploads folder for unlinked files ──
echo "<div class='box'><h3>Files on Disk vs Database</h3>";
$disk_files = [];
if (is_dir($upload_fs)) {
    foreach (scandir($upload_fs) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (strpos($f, 'complaint_') === 0) $disk_files[] = $f;
    }
}

$linked_files = [];
$lf = $conn->query("SELECT file_path FROM tbl_complaint_attachments");
while ($r = $lf->fetch_assoc()) $linked_files[] = basename($r['file_path']);

echo "<table><tr><th>File on disk</th><th>In DB?</th><th>complaint_id from filename</th><th>Complaint exists?</th></tr>";
$unlinked = [];
foreach ($disk_files as $df) {
    $in_db = in_array($df, $linked_files);
    preg_match('/^complaint_(\d+)_/', $df, $m);
    $fid = isset($m[1]) ? (int)$m[1] : null;
    $comp_exists = false;
    $comp_num = '—';
    if ($fid !== null) {
        $cr = $conn->query("SELECT complaint_id, complaint_number FROM tbl_complaints WHERE complaint_id=$fid");
        if ($cr && $cr->num_rows > 0) {
            $comp_exists = true;
            $comp_num = $cr->fetch_assoc()['complaint_number'];
        }
    }
    if (!$in_db) $unlinked[] = ['file' => $df, 'fid' => $fid];
    echo "<tr>
        <td style='font-size:11px'>$df</td>
        <td class='" . ($in_db ? 'ok' : 'bad') . "'>" . ($in_db ? 'YES' : 'NO — not linked') . "</td>
        <td>$fid</td>
        <td class='" . ($comp_exists ? 'ok' : 'bad') . "'>" . ($comp_exists ? "YES ($comp_num)" : 'NO') . "</td>
    </tr>";
}
echo "</table></div>";

// ── Auto-fix: re-link unlinked files ──
if (isset($_GET['fix'])) {
    echo "<div class='box'><h3>Running Fix</h3><div class='log'>";

    // Fix 1: Delete orphaned attachment records (complaint_id not in tbl_complaints)
    $del = $conn->query("DELETE FROM tbl_complaint_attachments WHERE complaint_id NOT IN (SELECT complaint_id FROM tbl_complaints)");
    echo "Deleted orphaned attachment records: {$conn->affected_rows}<br>";

    // Fix 2: Re-link unlinked disk files to correct complaint
    $fixed = 0;
    foreach ($unlinked as $u) {
        $f   = $u['file'];
        $fid = $u['fid'];
        if (!$fid) { echo "<span class='warn'>Skipped $f — can't determine complaint_id</span><br>"; continue; }

        // Check complaint exists
        $cr = $conn->query("SELECT complaint_id FROM tbl_complaints WHERE complaint_id=$fid");
        if (!$cr || $cr->num_rows === 0) {
            // Try to find complaint by matching complaint_number in filename? No — just skip
            echo "<span class='warn'>Skipped $f — complaint_id=$fid not found in tbl_complaints</span><br>";
            continue;
        }

        $file_path = 'uploads/complaints/' . $f;
        $ins = $conn->prepare("INSERT INTO tbl_complaint_attachments (complaint_id, file_name, file_path, uploaded_at) VALUES (?, ?, ?, NOW())");
        $ins->bind_param("iss", $fid, $f, $file_path);
        if ($ins->execute()) {
            echo "<span class='ok'>Linked $f to complaint_id=$fid</span><br>";
            $fixed++;
        } else {
            echo "<span class='bad'>Failed to link $f: {$ins->error}</span><br>";
        }
        $ins->close();
    }
    echo "<br><span class='ok'>Done. Fixed $fixed file(s).</span>";
    echo "</div></div>";
}

// ── Show fix button ──
$has_issues = !empty($orphaned) || !empty($unlinked);
echo "<div class='box'>";
if ($has_issues) {
    echo "<p class='bad'>Issues found. Click to auto-fix:</p>";
    echo "<a class='btn btn-green' href='?fix=1'>Fix Attachment Records</a>";
} else {
    echo "<p class='ok'>All attachment records look correct!</p>";
}
echo "</div>";

echo "<hr><a class='btn' href='view-complaints.php'>Go to View Complaints</a>";
echo "<p style='color:#94a3b8;font-size:11px;margin-top:12px;'>Delete this file after use.</p>";
?>