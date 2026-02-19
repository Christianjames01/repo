<?php
// Include config which handles session, database, and functions
require_once __DIR__ . '/../../config/config.php';

// Check if user is logged in and is Super Admin
if (!isLoggedIn() || $_SESSION['role_name'] !== 'Super Admin') {
    header('Location: ' . BASE_URL . '/modules/auth/login.php');
    exit();
}

// Get beneficiary ID
if (!isset($_GET['id'])) {
    header('Location: beneficiaries.php');
    exit();
}

$beneficiary_id = $_GET['id'];

// Fetch beneficiary data
// Fetch beneficiary data WITH extended details
$query = "SELECT 
    b.*,
    e.last_name,
    e.first_name,
    e.middle_name,
    e.ext_name,
    e.permanent_address,
    e.street,
    e.barangay,
    e.town,
    e.province,
    e.birthplace,
    e.mobile_phone,
    e.birthday,
    e.civil_status,
    e.gender,
    e.father_full_name,
    e.father_address,
    e.father_education,
    e.father_income,
    e.mother_full_name,
    e.mother_address,
    e.mother_education,
    e.mother_income,
    e.secondary_school,
    e.degree_program,
    e.year_level,
    e.reference_1,
    e.reference_2,
    e.reference_3,
    e.id_picture,
    e.ctrl_no
    FROM tbl_4ps_beneficiaries b
    LEFT JOIN tbl_4ps_extended_details e ON b.beneficiary_id = e.beneficiary_id
    WHERE b.beneficiary_id = ?";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $beneficiary_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header('Location: beneficiaries.php');
    exit();
}

$data = $result->fetch_assoc();
$stmt->close();


$page_title = '4Ps Application Form - ' . $data['ctrl_no'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: white; }
            .form-container { box-shadow: none !important; }
        }
        
        body {
            background: #f5f5f5;
            font-family: Arial, sans-serif;
        }
        
        .form-container {
            max-width: 900px;
            margin: 2rem auto;
            background: white;
            padding: 2rem;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        
        .form-header {
            text-align: center;
            border-bottom: 3px solid #000;
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .form-logo {
            width: 80px;
            height: 80px;
        }
        
        .form-title {
            font-size: 14px;
            font-weight: bold;
            margin: 0.5rem 0;
        }
        
        .form-subtitle {
            font-size: 12px;
            margin: 0.25rem 0;
        }
        
        .section-header {
            background: #000;
            color: white;
            padding: 0.5rem;
            font-weight: bold;
            font-size: 14px;
            margin-top: 1rem;
            margin-bottom: 0.5rem;
        }
        
        .form-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1rem;
        }
        
        .form-table td {
            border: 1px solid #000;
            padding: 0.5rem;
            font-size: 12px;
        }
        
        .form-table .label {
            font-weight: bold;
            background: #f0f0f0;
            width: 30%;
        }
        
        .id-picture-box {
            border: 2px solid #000;
            width: 150px;
            height: 150px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 1rem auto;
        }
        
        .id-picture-box img {
            max-width: 100%;
            max-height: 100%;
        }
        
        .signature-section {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid #000;
        }
        
        .signature-box {
            border-bottom: 1px solid #000;
            min-height: 60px;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <div class="form-container">
        <!-- Print Button -->
        <div class="text-end mb-3 no-print">
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print me-2"></i>Print Application
            </button>
            <a href="beneficiaries-debug.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back
            </a>
        </div>
        
        <!-- Form Header -->
        <div class="form-header">
            <div class="d-flex align-items-center justify-content-between">
                <div style="width: 80px;"></div>
                <div class="flex-grow-1">
                    <div class="form-title">4Ps PARTY-LIST (Pagtibayin at Palaguin ang Pangkabuhayan Pilipino Party-List)</div>
                    <div class="form-title">EDUCATIONAL ASSISTANCE PROGRAM</div>
                    <div class="form-subtitle">A PRIVATE SECTOR INITIATIVE</div>
                </div>
                <div class="text-end">
                    <strong>Ctrl. No:</strong> <?php echo htmlspecialchars($data['ctrl_no']); ?>
                </div>
            </div>
        </div>
        
        <h5 class="text-center mb-4" style="border: 2px solid #000; padding: 0.5rem;">APPLICATION FORM</h5>
        
        <!-- Personal Information -->
        <div class="section-header">PERSONAL INFORMATION</div>
        <table class="form-table">
            <tr>
                <td class="label">LAST NAME</td>
                <td><?php echo htmlspecialchars($data['last_name']); ?></td>
                <td class="label">FIRST NAME</td>
                <td><?php echo htmlspecialchars($data['first_name']); ?></td>
                <td class="label">MIDDLE NAME</td>
                <td><?php echo htmlspecialchars($data['middle_name']); ?></td>
                <td class="label">Ext</td>
                <td><?php echo htmlspecialchars($data['ext_name']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">PERMANENT ADDRESS:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['permanent_address']); ?></td>
            </tr>
            <tr>
                <td class="label">STREET</td>
                <td><?php echo htmlspecialchars($data['street']); ?></td>
                <td class="label">BRGY</td>
                <td><?php echo htmlspecialchars($data['barangay']); ?></td>
                <td class="label">TOWN</td>
                <td><?php echo htmlspecialchars($data['town']); ?></td>
                <td class="label">PROVINCE</td>
                <td><?php echo htmlspecialchars($data['province']); ?></td>
            </tr>
            <tr>
                <td class="label">BIRTHPLACE:</td>
                <td colspan="3"><?php echo htmlspecialchars($data['birthplace']); ?></td>
                <td class="label">MOBILE/PHONE NO.:</td>
                <td colspan="3"><?php echo htmlspecialchars($data['mobile_phone']); ?></td>
            </tr>
            <tr>
                <td class="label">BIRTHDAY:</td>
                <td><?php echo date('F d, Y', strtotime($data['birthday'])); ?></td>
                <td class="label">CIVIL STATUS:</td>
                <td><?php echo htmlspecialchars($data['civil_status']); ?></td>
                <td class="label">GENDER:</td>
                <td colspan="3"><?php echo htmlspecialchars($data['gender']); ?></td>
            </tr>
        </table>
        
        <!-- Family Background -->
        <div class="section-header">FAMILY BACKGROUND</div>
        <table class="form-table">
            <tr>
                <td class="label" colspan="2">FATHER'S FULL NAME:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['father_full_name']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">ADDRESS:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['father_address']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">EDUCATIONAL ATTAINMENT:</td>
                <td colspan="3"><?php echo htmlspecialchars($data['father_education']); ?></td>
                <td class="label">MONTHLY INCOME:</td>
                <td colspan="2">₱<?php echo number_format($data['father_income'], 2); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">MOTHER'S FULL MAIDEN NAME:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['mother_full_name']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">ADDRESS:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['mother_address']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">EDUCATIONAL ATTAINMENT:</td>
                <td colspan="3"><?php echo htmlspecialchars($data['mother_education']); ?></td>
                <td class="label">MONTHLY INCOME:</td>
                <td colspan="2">₱<?php echo number_format($data['mother_income'], 2); ?></td>
            </tr>
        </table>
        
        <!-- Academic Information -->
        <div class="section-header">ACADEMIC INFORMATION</div>
        <table class="form-table">
            <tr>
                <td class="label" colspan="2">SECONDARY SCHOOL ADDRESS:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['secondary_school']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">DEGREE PROGRAM/COURSE TAKEN:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['degree_program']); ?></td>
            </tr>
            <tr>
                <td class="label" colspan="2">YEAR LEVEL:</td>
                <td colspan="6"><?php echo htmlspecialchars($data['year_level']); ?></td>
            </tr>
        </table>
        
        <!-- Personal References -->
        <div class="section-header">PERSONAL REFERENCES:</div>
        <table class="form-table">
            <tr>
                <td class="label" style="width: 5%;">1.</td>
                <td><?php echo htmlspecialchars($data['reference_1']); ?></td>
            </tr>
            <tr>
                <td class="label">2.</td>
                <td><?php echo htmlspecialchars($data['reference_2']); ?></td>
            </tr>
            <tr>
                <td class="label">3.</td>
                <td><?php echo htmlspecialchars($data['reference_3']); ?></td>
            </tr>
        </table>
        
        <!-- 4Ps Program Details -->
        <div class="section-header">4Ps PROGRAM DETAILS</div>
        <table class="form-table">
            <tr>
                <td class="label">HOUSEHOLD ID:</td>
                <td><?php echo htmlspecialchars($data['household_id']); ?></td>
                <td class="label">GRANTEE NAME:</td>
                <td><?php echo htmlspecialchars($data['grantee_name']); ?></td>
            </tr>
            <tr>
                <td class="label">DATE REGISTERED:</td>
                <td><?php echo date('F d, Y', strtotime($data['date_registered'])); ?></td>
                <td class="label">STATUS:</td>
                <td><?php echo htmlspecialchars($data['status']); ?></td>
            </tr>
            <tr>
                <td class="label">SET NUMBER:</td>
                <td><?php echo htmlspecialchars($data['set_number']); ?></td>
                <td class="label">COMPLIANCE:</td>
                <td><?php echo htmlspecialchars($data['compliance_status']); ?></td>
            </tr>
            <tr>
                <td class="label">MONTHLY GRANT:</td>
                <td colspan="3">₱<?php echo number_format($data['monthly_grant'], 2); ?></td>
            </tr>
            <?php if ($data['remarks']): ?>
            <tr>
                <td class="label">REMARKS:</td>
                <td colspan="3"><?php echo nl2br(htmlspecialchars($data['remarks'])); ?></td>
            </tr>
            <?php endif; ?>
        </table>
        
        <!-- ID Picture -->
        <div class="row">
            <div class="col-md-8">
                <div class="signature-section">
                    <p style="font-size: 12px;">
                        <strong>I hereby certify that the foregoing statements are true and correct.</strong><br>
                        Any misrepresentation or withholding of information will automatically disqualify me from the educational assistance program.
                    </p>
                    
                    <div class="signature-box"></div>
                    <div class="text-center" style="font-size: 12px;">
                        <strong>Applicant's Signature over Printed Name</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-center mt-3">
                    <strong style="font-size: 12px;">RECENT 2x2 ID PICTURE</strong>
                </div>
                <div class="id-picture-box">
                    <?php if ($data['id_picture'] && file_exists(__DIR__ . '/../../uploads/4ps/' . $data['id_picture'])): ?>
                        <img src="<?php echo BASE_URL; ?>/uploads/4ps/<?php echo htmlspecialchars($data['id_picture']); ?>" alt="ID Picture">
                    <?php else: ?>
                        <div class="text-center text-muted">
                            <i class="fas fa-user fa-3x"></i><br>
                            <small>No Photo</small>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Approval Section -->
        <div class="row mt-4" style="border-top: 2px solid #000; padding-top: 1rem;">
            <div class="col-md-6">
                <div style="font-size: 12px;">
                    <strong>EVALUATED/RECOMMENDED BY:</strong>
                    <div class="signature-box"></div>
                    <div class="text-center">
                        <strong>4PS PARTY-LIST COORDINATOR</strong>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div style="font-size: 12px;">
                    <strong>APPROVED BY:</strong>
                    <div class="signature-box"></div>
                    <div class="text-center">
                        <strong>AUTHORIZED SIGNATORY</strong>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Requirements -->
        <div class="mt-4" style="font-size: 11px; border-top: 2px solid #000; padding-top: 1rem;">
            <strong>Requirements:</strong>
            <ol style="margin: 0.5rem 0;">
                <li>4Ps Party-List Educational Assistance Application Form</li>
                <li>Certificate of Enrollment (Photocopy)</li>
                <li>Transcript of Records (from previous school year)</li>
                <li>Student ID (or any government ID)</li>
                <li>Barangay Clearance</li>
            </ol>
            <strong>Qualifications:</strong>
            <ul style="margin: 0.5rem 0;">
                <li>Must be enrolled in the current semester</li>
                <li>No failing marks from the previous semester</li>
                <li>At least no lower grades than 2.5</li>
            </ul>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>