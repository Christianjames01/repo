<?php
/**
 * fix-complaints-db.php  v2
 * Handles missing PRIMARY KEY + missing AUTO_INCREMENT + complaint_id=0 rows.
 * DELETE this file after use.
 */
require_once '../../config/database.php';

echo "<style>
body{font-family:monospace;padding:24px;background:#f8fafc;color:#0f172a;}
h2{color:#0d1b36;border-bottom:2px solid #e2e8f0;padding-bottom:8px;}
h3{color:#1c3461;margin-top:24px;}
table{border-collapse:collapse;width:100%;margin-bottom:16px;}
td,th{border:1px solid #e2e8f0;padding:9px 12px;font-size:13px;}
th{background:#0d1b36;color:#fff;}
tr:nth-child(even){background:#f1f5f9;}
.ok{color:#10b981;font-weight:bold;}
.bad{color:#e11d48;font-weight:bold;}
.warn{color:#f59e0b;font-weight:bold;}
.box{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06);}
.btn{display:inline-block;padding:10px 20px;background:#0d1b36;color:#fff;border-radius:7px;text-decoration:none;font-weight:bold;margin-right:8px;margin-top:8px;}
.btn-green{background:#10b981;}
.log{background:#0f172a;color:#a3e635;padding:14px;border-radius:8px;font-size:12px;line-height:2;}
</style>";

echo "<h2>Complaint DB Repair Tool v2</h2>";

// ── Read current state ──
$col_info  = $conn->query("SHOW COLUMNS FROM tbl_complaints LIKE 'complaint_id'")->fetch_assoc();
$has_ai    = strpos($col_info['Extra'] ?? '', 'auto_increment') !== false;
$pk_res    = $conn->query("SHOW KEYS FROM tbl_complaints WHERE Key_name='PRIMARY'");
$has_pk    = ($pk_res && $pk_res->num_rows > 0);
$max_id    = (int)($conn->query("SELECT MAX(complaint_id) FROM tbl_complaints")->fetch_row()[0] ?? 0);
$zero_cnt  = (int)($conn->query("SELECT COUNT(*) FROM tbl_complaints WHERE complaint_id=0")->fetch_row()[0] ?? 0);

echo "<div class='box'><h3>Current State</h3><table>
<tr><th>Check</th><th>Result</th></tr>
<tr><td>Column Extra</td><td><strong>" . htmlspecialchars($col_info['Extra'] ?: '(empty — no auto_increment, no key)') . "</strong></td></tr>
<tr><td>Has AUTO_INCREMENT</td><td class='" . ($has_ai?'ok':'bad') . "'>" . ($has_ai?'YES':'NO') . "</td></tr>
<tr><td>Has PRIMARY KEY</td><td class='" . ($has_pk?'ok':'bad') . "'>" . ($has_pk?'YES':'NO') . "</td></tr>
<tr><td>Max complaint_id</td><td>$max_id</td></tr>
<tr><td>Rows with id=0</td><td class='" . ($zero_cnt?'bad':'ok') . "'>$zero_cnt</td></tr>
</table></div>";

if (isset($_GET['fix'])) {
    echo "<div class='box'><h3>Running Repairs</h3><div class='log'>";

    $ti = 9000; // temp ID starting point

    // A: Give zero-id rows temp IDs so we can add a PK without duplicate key error
    if ($zero_cnt > 0) {
        $zeros = $conn->query("SELECT complaint_number FROM tbl_complaints WHERE complaint_id=0 ORDER BY complaint_number");
        while ($zrow = $zeros->fetch_assoc()) {
            $cn = $conn->real_escape_string($zrow['complaint_number']);
            $conn->query("UPDATE tbl_complaints SET complaint_id=$ti WHERE complaint_number='$cn' AND complaint_id=0 LIMIT 1");
            echo "Temp id=$ti assigned to [$cn] (rows affected: {$conn->affected_rows})<br>";
            $ti++;
        }
        echo "<span class='ok'>Temp IDs assigned ($zero_cnt rows)</span><br>";
    }

    // B: Add PRIMARY KEY if missing
    if (!$has_pk) {
        $ok = $conn->query("ALTER TABLE tbl_complaints ADD PRIMARY KEY (complaint_id)");
        echo ($ok ? "<span class='ok'>PRIMARY KEY added</span>" : "<span class='bad'>PK failed: " . htmlspecialchars($conn->error) . "</span>") . "<br>";
    } else {
        echo "<span class='ok'>PRIMARY KEY already exists</span><br>";
    }

    // C: Add AUTO_INCREMENT — set to safe value above everything
    $safe_next = max($ti, $max_id + 1, 10);
    $ok2 = $conn->query("ALTER TABLE tbl_complaints MODIFY complaint_id INT NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=$safe_next");
    echo ($ok2 ? "<span class='ok'>AUTO_INCREMENT added (next id=$safe_next)</span>" : "<span class='bad'>AUTO_INCREMENT failed: " . htmlspecialchars($conn->error) . "</span>") . "<br>";

    // D: Renumber the temp-id rows to real sequential IDs
    if ($zero_cnt > 0) {
        $real_next = (int)($conn->query("SELECT MAX(complaint_id) FROM tbl_complaints WHERE complaint_id < 9000")->fetch_row()[0] ?? 0) + 1;
        $tmps = $conn->query("SELECT complaint_id, complaint_number FROM tbl_complaints WHERE complaint_id >= 9000 ORDER BY complaint_id");
        while ($trow = $tmps->fetch_assoc()) {
            $old = (int)$trow['complaint_id'];
            $cn  = $conn->real_escape_string($trow['complaint_number']);
            $conn->query("UPDATE tbl_complaints SET complaint_id=$real_next WHERE complaint_id=$old LIMIT 1");
            echo "Renumbered [$cn]: id $old &rarr; $real_next (rows: {$conn->affected_rows})<br>";
            $real_next++;
        }
        // Reset AUTO_INCREMENT to actual max + 1
        $conn->query("ALTER TABLE tbl_complaints AUTO_INCREMENT=$real_next");
        echo "<span class='ok'>AUTO_INCREMENT reset to $real_next</span><br>";
    }

    echo "</div></div>";

    // ── Verify ──
    echo "<div class='box'><h3>After Repair — All Complaints</h3>";
    $after = $conn->query("SELECT complaint_id, complaint_number, resident_id, status FROM tbl_complaints ORDER BY complaint_id");
    echo "<table><tr><th>complaint_id</th><th>complaint_number</th><th>resident_id</th><th>status</th></tr>";
    $all_ok = true;
    while ($row = $after->fetch_assoc()) {
        $bad = ($row['complaint_id'] == 0);
        if ($bad) $all_ok = false;
        echo "<tr" . ($bad?" class='bad'":"") . ">
            <td>{$row['complaint_id']}</td><td>{$row['complaint_number']}</td>
            <td>{$row['resident_id']}</td><td>{$row['status']}</td></tr>";
    }
    echo "</table>";

    $col_after = $conn->query("SHOW COLUMNS FROM tbl_complaints LIKE 'complaint_id'")->fetch_assoc();
    $ai_ok = strpos($col_after['Extra'] ?? '', 'auto_increment') !== false;
    echo "<p class='" . ($ai_ok?'ok':'bad') . "'>" . ($ai_ok ? '✓ AUTO_INCREMENT is active.' : '✗ Still missing — run the ALTER manually in phpMyAdmin.') . "</p>";
    if ($all_ok) echo "<p class='ok'>✓ No more complaint_id=0 rows.</p>";
    echo "</div>";

} else {
    echo "<div class='box'>";
    if (!$has_ai || !$has_pk || $zero_cnt > 0) {
        echo "<p class='bad'>Issues detected. Click to repair all at once:</p>";
        echo "<a class='btn btn-green' href='?fix=1'>Run All Repairs</a>";
    } else {
        echo "<p class='ok'>Everything looks good — no repairs needed.</p>";
    }
    echo "</div>";
}

echo "<hr>";
echo "<a class='btn' href='view-complaints.php'>Go to View Complaints</a>";
echo "<p style='color:#94a3b8;font-size:11px;margin-top:12px;'>Delete this file after repairs are complete.</p>";
?>