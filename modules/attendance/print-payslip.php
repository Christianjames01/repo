<?php
/**
 * Print Payslip
 * modules/attendance/print-payslip.php
 * Generates a printable payslip
 */

require_once '../../config/config.php';
require_once '../../includes/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /barangaylink/modules/auth/login.php');
    exit();
}

$current_user_id = getCurrentUserId();

// Get payslip ID
if (!isset($_GET['id']) || empty($_GET['id'])) {
    die('Invalid payslip ID');
}

$payslip_id = intval($_GET['id']);

// Get payslip details - ensure it belongs to current user
$payslip = fetchOne($conn,
    "SELECT p.*, 
            CONCAT(r.first_name, ' ', r.last_name) as staff_name,
            r.profile_photo,
            r.contact_number,
            r.address as staff_address,
            u.role as staff_role,
            CONCAT(cr.first_name, ' ', cr.last_name) as generated_by_name
    FROM tbl_payslips p
    LEFT JOIN tbl_users u ON p.user_id = u.user_id
    LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
    LEFT JOIN tbl_users cu ON p.generated_by = cu.user_id
    LEFT JOIN tbl_residents cr ON cu.resident_id = cr.resident_id
    WHERE p.payslip_id = ? AND p.user_id = ?",
    [$payslip_id, $current_user_id], 'ii'
);

if (!$payslip) {
    die('Payslip not found or access denied');
}

// Get attendance records for this pay period
$attendance_records = fetchAll($conn,
    "SELECT attendance_date, status, time_in, time_out, notes
    FROM tbl_attendance
    WHERE user_id = ? 
    AND attendance_date BETWEEN ? AND ?
    AND time_in IS NOT NULL 
    AND time_out IS NOT NULL
    ORDER BY attendance_date",
    [$current_user_id, $payslip['pay_period_start'], $payslip['pay_period_end']], 'iss'
);

// Calculate attendance details
$attendance_details = [];
foreach ($attendance_records as $record) {
    $time_in = strtotime($record['time_in']);
    $time_out = strtotime($record['time_out']);
    $worked_seconds = $time_out - $time_in;
    $worked_hours = $worked_seconds / 3600;
    
    // Subtract 1 hour lunch if worked > 6 hours
    if ($worked_hours > 6) {
        $worked_hours -= 1;
    }
    
    $attendance_details[] = [
        'date' => $record['attendance_date'],
        'status' => $record['status'],
        'time_in' => $record['time_in'],
        'time_out' => $record['time_out'],
        'worked_hours' => round($worked_hours, 2),
        'notes' => $record['notes']
    ];
}

// FIXED: Changed 'id' to 'info_id' or use LIMIT 1
$barangay_info = fetchOne($conn, "SELECT * FROM tbl_barangay_info LIMIT 1");

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo htmlspecialchars($payslip['staff_name']); ?> - <?php echo date('F Y', strtotime($payslip['pay_period_start'])); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
            body {
                print-color-adjust: exact;
                -webkit-print-color-adjust: exact;
            }
            .page-break {
                page-break-after: always;
            }
        }
        
        body {
            font-family: 'Arial', sans-serif;
            font-size: 12px;
        }
        
        .payslip-container {
            max-width: 900px;
            margin: 20px auto;
            padding: 30px;
            background: white;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        
        .header-section {
            text-align: center;
            border-bottom: 3px solid #333;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        
        .logo {
            width: 80px;
            height: 80px;
            margin-bottom: 10px;
        }
        
        .barangay-name {
            font-size: 24px;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .payslip-title {
            font-size: 20px;
            font-weight: bold;
            margin: 10px 0;
            color: #2c3e50;
        }
        
        .info-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            width: 30%;
        }
        
        .earnings-table,
        .deductions-table {
            margin: 20px 0;
        }
        
        .earnings-table th {
            background-color: #d4edda;
            color: #155724;
        }
        
        .deductions-table th {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .net-pay-section {
            background-color: #d1ecf1;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .signature-section {
            margin-top: 60px;
        }
        
        .signature-box {
            text-align: center;
            padding: 10px;
        }
        
        .signature-line {
            border-top: 2px solid #333;
            margin-top: 50px;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <!-- Print Button -->
    <div class="no-print text-center py-3">
        <button onclick="window.print()" class="btn btn-primary btn-lg">
            <i class="fas fa-print me-2"></i> Print Payslip
        </button>
        <a href="view-my-payslip.php?id=<?php echo $payslip_id; ?>" class="btn btn-secondary btn-lg">
            <i class="fas fa-arrow-left me-2"></i> Back to Details
        </a>
    </div>

    <div class="payslip-container">
        <!-- Header -->
        <div class="header-section">
            <?php if ($barangay_info && $barangay_info['barangay_logo'] && file_exists('../../uploads/barangay/' . $barangay_info['barangay_logo'])): ?>
                <img src="../../uploads/barangay/<?php echo $barangay_info['barangay_logo']; ?>" class="logo" alt="Barangay Logo">
            <?php else: ?>
                <div class="logo mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                     style="width: 80px; height: 80px; font-size: 2rem;">
                    <i class="fas fa-landmark"></i>
                </div>
            <?php endif; ?>
            
            <div class="barangay-name">
                <?php echo $barangay_info ? htmlspecialchars($barangay_info['barangay_name']) : 'BARANGAY CENTRO'; ?>
            </div>
            <?php if ($barangay_info): ?>
                <div><?php echo htmlspecialchars($barangay_info['address'] ?? ''); ?></div>
                <div><?php echo htmlspecialchars($barangay_info['contact_number'] ?? ''); ?> | <?php echo htmlspecialchars($barangay_info['email'] ?? ''); ?></div>
            <?php endif; ?>
            
            <div class="payslip-title mt-3">EMPLOYEE PAYSLIP</div>
            <div>Pay Period: <?php echo date('F d', strtotime($payslip['pay_period_start'])); ?> - <?php echo date('F d, Y', strtotime($payslip['pay_period_end'])); ?></div>
        </div>

        <!-- Employee Information -->
        <table class="table table-bordered info-table">
            <tr>
                <th>Employee Name:</th>
                <td colspan="3"><strong><?php echo htmlspecialchars($payslip['staff_name']); ?></strong></td>
            </tr>
            <tr>
                <th>Position:</th>
                <td><?php echo htmlspecialchars($payslip['staff_role']); ?></td>
                <th>Payslip ID:</th>
                <td>#<?php echo str_pad($payslip['payslip_id'], 6, '0', STR_PAD_LEFT); ?></td>
            </tr>
            <tr>
                <th>Pay Period:</th>
                <td><?php echo date('M d', strtotime($payslip['pay_period_start'])); ?> - <?php echo date('M d, Y', strtotime($payslip['pay_period_end'])); ?></td>
                <th>Generated Date:</th>
                <td><?php echo date('M d, Y', strtotime($payslip['generated_at'])); ?></td>
            </tr>
        </table>

        <!-- Attendance Summary -->
        <div class="row mb-3">
            <div class="col-md-3">
                <div class="border p-2 text-center">
                    <strong>Days Present</strong>
                    <div class="fs-4 text-success"><?php echo $payslip['days_present']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-2 text-center">
                    <strong>Days Late</strong>
                    <div class="fs-4 text-warning"><?php echo $payslip['days_late']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-2 text-center">
                    <strong>Days Absent</strong>
                    <div class="fs-4 text-danger"><?php echo $payslip['days_absent']; ?></div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="border p-2 text-center">
                    <strong>Overtime Hours</strong>
                    <div class="fs-4 text-primary"><?php echo number_format($payslip['overtime_hours'], 1); ?></div>
                </div>
            </div>
        </div>

        <!-- Earnings -->
        <table class="table table-bordered earnings-table">
            <thead>
                <tr>
                    <th colspan="2">EARNINGS</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Basic Salary</td>
                    <td class="text-end">₱<?php echo number_format($payslip['basic_salary'], 2); ?></td>
                </tr>
                <tr>
                    <td>Allowances</td>
                    <td class="text-end">₱<?php echo number_format($payslip['allowances'], 2); ?></td>
                </tr>
                <?php if ($payslip['overtime_hours'] > 0): ?>
                <tr>
                    <td>
                        Overtime Pay 
                        <small>(<?php echo number_format($payslip['overtime_hours'], 2); ?> hrs @ ₱<?php echo number_format($payslip['hourly_rate'] * 1.25, 2); ?>/hr)</small>
                    </td>
                    <td class="text-end">₱<?php echo number_format($payslip['overtime_pay'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-success">
                    <th>GROSS PAY</th>
                    <th class="text-end">₱<?php echo number_format($payslip['gross_pay'], 2); ?></th>
                </tr>
            </tbody>
        </table>

        <!-- Deductions -->
        <table class="table table-bordered deductions-table">
            <thead>
                <tr>
                    <th colspan="2">DEDUCTIONS</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($payslip['late_minutes'] > 0): ?>
                <tr>
                    <td>
                        Late Deductions 
                        <small>(<?php echo $payslip['late_minutes']; ?> minutes / <?php echo $payslip['days_late']; ?> days)</small>
                    </td>
                    <td class="text-end">₱<?php echo number_format($payslip['late_deductions'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payslip['absence_deductions'] > 0): ?>
                <tr>
                    <td>
                        Absence Deductions 
                        <small>(<?php echo $payslip['absences']; ?> days)</small>
                    </td>
                    <td class="text-end">₱<?php echo number_format($payslip['absence_deductions'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($payslip['other_deductions'] > 0): ?>
                <tr>
                    <td>Other Deductions <small>(SSS, PhilHealth, Pag-IBIG, Tax, etc.)</small></td>
                    <td class="text-end">₱<?php echo number_format($payslip['other_deductions'], 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="table-danger">
                    <th>TOTAL DEDUCTIONS</th>
                    <th class="text-end">₱<?php echo number_format($payslip['total_deductions'], 2); ?></th>
                </tr>
            </tbody>
        </table>

        <!-- Net Pay -->
        <div class="net-pay-section">
            <div class="row align-items-center">
                <div class="col-md-6">
                    <h4 class="mb-0">NET PAY</h4>
                    <small class="text-muted">Take-home pay after all deductions</small>
                </div>
                <div class="col-md-6 text-end">
                    <h2 class="mb-0 text-primary">₱<?php echo number_format($payslip['net_pay'], 2); ?></h2>
                </div>
            </div>
        </div>

        <!-- Daily Attendance (if available) -->
        <?php if (!empty($attendance_details)): ?>
        <h6 class="mt-4 mb-2">Daily Attendance Record:</h6>
        <table class="table table-bordered table-sm">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Time In</th>
                    <th>Time Out</th>
                    <th class="text-end">Hours</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($attendance_details as $detail): ?>
                <tr>
                    <td><?php echo date('M d, D', strtotime($detail['date'])); ?></td>
                    <td><?php echo $detail['status']; ?></td>
                    <td><?php echo date('h:i A', strtotime($detail['time_in'])); ?></td>
                    <td><?php echo date('h:i A', strtotime($detail['time_out'])); ?></td>
                    <td class="text-end"><?php echo number_format($detail['worked_hours'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="signature-section">
            <div class="row">
                <div class="col-6">
                    <div class="signature-box">
                        <div class="signature-line">
                            <strong><?php echo htmlspecialchars($payslip['staff_name']); ?></strong><br>
                            Employee Signature
                        </div>
                    </div>
                </div>
                <div class="col-6">
                    <div class="signature-box">
                        <div class="signature-line">
                            <strong><?php echo htmlspecialchars($payslip['generated_by_name'] ?? 'Administrator'); ?></strong><br>
                            Authorized Signatory
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <script>
        // Auto-print when page loads (optional)
        // window.onload = function() { window.print(); }
    </script>
</body>
</html>