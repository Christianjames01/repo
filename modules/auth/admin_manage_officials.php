<?php
session_start();

// CORRECTED PATH: Go up 2 directories from modules/auth/
require_once('../../config/database.php');

// Check if user is logged in and is admin or super admin
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'Super Admin', 'Super Administrator'])) {
    header('Location: login.php');
    exit();
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $action = $_POST['action'];
        
        // Add new official
        if ($action === 'add') {
            $first_name = $conn->real_escape_string($_POST['first_name']);
            $middle_name = $conn->real_escape_string($_POST['middle_name']);
            $last_name = $conn->real_escape_string($_POST['last_name']);
            $position = $conn->real_escape_string($_POST['position']);
            $committee = $conn->real_escape_string($_POST['committee']);
            $term_start = $conn->real_escape_string($_POST['term_start']);
            $term_end = $conn->real_escape_string($_POST['term_end']);
            $display_order = intval($_POST['display_order']);
            $official_type = $conn->real_escape_string($_POST['official_type']);
            
            // Handle photo upload
            $photo = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../assets/images/officials/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = uniqid('official_') . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo = 'assets/images/officials/' . $new_filename;
                    }
                }
            }
            
            $sql = "INSERT INTO tbl_barangay_officials (first_name, middle_name, last_name, position, committee, term_start, term_end, display_order, official_type, photo) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("sssssssiss", $first_name, $middle_name, $last_name, $position, $committee, $term_start, $term_end, $display_order, $official_type, $photo);
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Official added successfully!";
            } else {
                $_SESSION['error_message'] = "Error adding official: " . $conn->error;
            }
            $stmt->close();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Update official  
        elseif ($action === 'update') {
            $id = intval($_POST['id']);
            $first_name = $conn->real_escape_string($_POST['first_name']);
            $middle_name = $conn->real_escape_string($_POST['middle_name']);
            $last_name = $conn->real_escape_string($_POST['last_name']);
            $position = $conn->real_escape_string($_POST['position']);
            $committee = $conn->real_escape_string($_POST['committee']);
            $term_start = $conn->real_escape_string($_POST['term_start']);
            $term_end = $conn->real_escape_string($_POST['term_end']);
            $display_order = intval($_POST['display_order']);
            $official_type = $conn->real_escape_string($_POST['official_type']);
            
            // Handle photo upload
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $upload_dir = '../../assets/images/officials/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                
                if (in_array($file_extension, $allowed_extensions)) {
                    $new_filename = uniqid('official_') . '.' . $file_extension;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $upload_path)) {
                        $photo = 'assets/images/officials/' . $new_filename;
                        
                        $sql = "UPDATE tbl_barangay_officials 
                                SET first_name=?, middle_name=?, last_name=?, position=?, committee=?, term_start=?, term_end=?, display_order=?, official_type=?, photo=? 
                                WHERE official_id=?";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssssssissi", $first_name, $middle_name, $last_name, $position, $committee, $term_start, $term_end, $display_order, $official_type, $photo, $id);
                    }
                }
            } else {
                $sql = "UPDATE tbl_barangay_officials 
                        SET first_name=?, middle_name=?, last_name=?, position=?, committee=?, term_start=?, term_end=?, display_order=?, official_type=? 
                        WHERE official_id=?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssisi", $first_name, $middle_name, $last_name, $position, $committee, $term_start, $term_end, $display_order, $official_type, $id);
            }
            
            if ($stmt->execute()) {
                $_SESSION['success_message'] = "Official updated successfully!";
            } else {
                $_SESSION['error_message'] = "Error updating official: " . $conn->error;
            }
            $stmt->close();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
        
        // Delete official
        elseif ($action === 'delete') {
            $id = intval($_POST['id']);
            
            // Get photo path before deleting
            $sql = "SELECT photo FROM tbl_barangay_officials WHERE official_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $result = $stmt->get_result();
            $official = $result->fetch_assoc();
            
            // Delete the official
            $sql = "DELETE FROM tbl_barangay_officials WHERE official_id=?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $id);
            
            if ($stmt->execute()) {
                // Delete photo file if exists
                if ($official && $official['photo'] && file_exists('../../' . $official['photo'])) {
                    unlink('../../' . $official['photo']);
                }
                $_SESSION['success_message'] = "Official deleted successfully!";
            } else {
                $_SESSION['error_message'] = "Error deleting official: " . $conn->error;
            }
            $stmt->close();
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit();
        }
    }
}

// Fetch all officials
$barangay_officials = [];
$sk_officials = [];

$sql = "SELECT *, CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) as full_name 
        FROM tbl_barangay_officials 
        WHERE official_type='barangay' AND is_active=1 
        ORDER BY display_order";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $barangay_officials[] = $row;
    }
}

$sql = "SELECT *, CONCAT(first_name, ' ', IFNULL(middle_name, ''), ' ', last_name) as full_name 
        FROM tbl_barangay_officials 
        WHERE official_type='sk' AND is_active=1 
        ORDER BY display_order";
$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $sk_officials[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Barangay Officials</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: #f8fafc; color: #1f2937; }
        .container { max-width: 1400px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 30px; border-radius: 12px; margin-bottom: 30px; box-shadow: 0 4px 12px rgba(30,58,138,0.3); }
        .header h1 { font-size: 32px; margin-bottom: 8px; }
        .alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; animation: slideIn 0.3s ease; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        .alert-success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .section { background: white; border-radius: 12px; padding: 30px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); }
        .section h2 { color: #1e3a8a; margin-bottom: 20px; font-size: 24px; display: flex; align-items: center; gap: 10px; }
        .btn { padding: 12px 24px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all 0.3s; text-decoration: none; display: inline-block; }
        .btn-primary { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; box-shadow: 0 4px 12px rgba(30,58,138,0.3); }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(30,58,138,0.4); }
        .btn-secondary { background: #6b7280; color: white; }
        .btn-secondary:hover { background: #4b5563; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-danger:hover { background: #dc2626; }
        .btn-sm { padding: 8px 16px; font-size: 14px; }
        .officials-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 20px; margin-top: 20px; }
        .official-card { background: linear-gradient(135deg, #f8fafc, #ffffff); border: 2px solid #e2e8f0; border-radius: 12px; padding: 20px; transition: all 0.3s; position: relative; overflow: hidden; }
        .official-card::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(30,58,138,0.05), transparent); transition: left 0.5s ease; }
        .official-card:hover::before { left: 100%; }
        .official-card:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,0.1); border-color: #3b82f6; }
        .official-card img { width: 100%; height: 250px; object-fit: cover; border-radius: 8px; margin-bottom: 15px; }
        .official-card h3 { color: #0f172a; margin-bottom: 8px; font-size: 20px; }
        .official-card p { color: #64748b; margin-bottom: 6px; font-size: 14px; }
        .official-card p strong { color: #374151; }
        .card-actions { display: flex; gap: 10px; margin-top: 15px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; font-weight: 600; color: #374151; }
        .form-group input, .form-group select { width: 100%; padding: 12px; border: 2px solid #e5e7eb; border-radius: 8px; font-size: 15px; font-family: inherit; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #3b82f6; }
        .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; animation: fadeIn 0.3s ease; }
        .modal.show { display: flex; }
        .modal-content { background: white; border-radius: 16px; max-width: 700px; width: 90%; max-height: 90vh; overflow-y: auto; padding: 0; box-shadow: 0 20px 60px rgba(0,0,0,0.3); animation: slideUp 0.4s ease; }
        @keyframes slideUp { from { opacity: 0; transform: translateY(50px); } to { opacity: 1; transform: translateY(0); } }
        .modal-header { background: linear-gradient(135deg, #1e3a8a, #3b82f6); color: white; padding: 25px 30px; border-radius: 16px 16px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h2 { color: white; margin: 0; font-size: 24px; }
        .modal-close { background: rgba(255,255,255,0.2); border: none; color: white; font-size: 24px; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; transition: all 0.3s; display: flex; align-items: center; justify-content: center; }
        .modal-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }
        .modal-body { padding: 30px; }
        .modal-footer { padding: 20px 30px; background: #f8fafc; border-radius: 0 0 16px 16px; display: flex; gap: 10px; justify-content: flex-end; }
        .back-btn { margin-bottom: 20px; }
        .empty-state { text-align: center; padding: 60px 20px; color: #64748b; }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 20px; }
        .empty-state p { font-size: 18px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="../../admin/dashboard.php" class="btn btn-secondary back-btn"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        <div class="header">
            <h1><i class="fas fa-users"></i> Manage Barangay Officials</h1>
            <p>Add, edit, or remove barangay and SK officials</p>
        </div>
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $_SESSION['success_message']; unset($_SESSION['success_message']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error_message']; unset($_SESSION['error_message']); ?></div>
        <?php endif; ?>
        <div class="section">
            <button onclick="openAddModal()" class="btn btn-primary"><i class="fas fa-plus"></i> Add New Official</button>
        </div>
        <div class="section">
            <h2><i class="fas fa-landmark"></i> Barangay Officials</h2>
            <?php if (empty($barangay_officials)): ?>
                <div class="empty-state"><i class="fas fa-users-slash"></i><p>No barangay officials found. Click "Add New Official" to add one.</p></div>
            <?php else: ?>
                <div class="officials-grid">
                    <?php foreach ($barangay_officials as $official): ?>
                        <div class="official-card">
                            <?php if ($official['photo']): ?>
                                <img src="../../<?php echo htmlspecialchars($official['photo']); ?>" alt="<?php echo htmlspecialchars($official['full_name']); ?>">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/320x250/3b82f6/ffffff?text=No+Photo" alt="Default">
                            <?php endif; ?>
                            <h3><?php echo htmlspecialchars($official['full_name']); ?></h3>
                            <p><strong><?php echo htmlspecialchars($official['position']); ?></strong></p>
                            <p><?php echo htmlspecialchars($official['committee']); ?></p>
                            <p><i class="fas fa-calendar"></i> <?php echo date('Y', strtotime($official['term_start'])); ?> - <?php echo date('Y', strtotime($official['term_end'])); ?></p>
                            <div class="card-actions">
                                <button onclick='editOfficial(<?php echo json_encode($official); ?>)' class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</button>
                                <button onclick="deleteOfficial(<?php echo $official['official_id']; ?>, '<?php echo htmlspecialchars($official['full_name']); ?>')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="section">
            <h2><i class="fas fa-users"></i> SK Officials</h2>
            <?php if (empty($sk_officials)): ?>
                <div class="empty-state"><i class="fas fa-users-slash"></i><p>No SK officials found. Click "Add New Official" to add one.</p></div>
            <?php else: ?>
                <div class="officials-grid">
                    <?php foreach ($sk_officials as $official): ?>
                        <div class="official-card">
                            <?php if ($official['photo']): ?>
                                <img src="../../<?php echo htmlspecialchars($official['photo']); ?>" alt="<?php echo htmlspecialchars($official['full_name']); ?>">
                            <?php else: ?>
                                <img src="https://via.placeholder.com/320x250/3b82f6/ffffff?text=No+Photo" alt="Default">
                            <?php endif; ?>
                            <h3><?php echo htmlspecialchars($official['full_name']); ?></h3>
                            <p><strong><?php echo htmlspecialchars($official['position']); ?></strong></p>
                            <p><?php echo htmlspecialchars($official['committee']); ?></p>
                            <p><i class="fas fa-calendar"></i> <?php echo date('Y', strtotime($official['term_start'])); ?> - <?php echo date('Y', strtotime($official['term_end'])); ?></p>
                            <div class="card-actions">
                                <button onclick='editOfficial(<?php echo json_encode($official); ?>)' class="btn btn-primary btn-sm"><i class="fas fa-edit"></i> Edit</button>
                                <button onclick="deleteOfficial(<?php echo $official['official_id']; ?>, '<?php echo htmlspecialchars($official['full_name']); ?>')" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div id="officialModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2 id="modalTitle">Add New Official</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="officialForm" method="POST" enctype="multipart/form-data">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="id" id="officialId">
                    <div class="form-row">
                        <div class="form-group"><label>First Name *</label><input type="text" name="first_name" id="first_name" required></div>
                        <div class="form-group"><label>Middle Name</label><input type="text" name="middle_name" id="middle_name"></div>
                        <div class="form-group"><label>Last Name *</label><input type="text" name="last_name" id="last_name" required></div>
                    </div>
                    <div class="form-group">
                        <label>Position *</label>
                        <select name="position" id="position" required>
                            <option value="">Select Position</option>
                            <optgroup label="Barangay Officials">
                                <option value="Punong Barangay">Punong Barangay</option>
                                <option value="Barangay Kagawad">Barangay Kagawad</option>
                                <option value="Barangay Secretary">Barangay Secretary</option>
                                <option value="Barangay Treasurer">Barangay Treasurer</option>
                            </optgroup>
                            <optgroup label="SK Officials">
                                <option value="SK Chairperson">SK Chairperson</option>
                                <option value="SK Kagawad">SK Kagawad</option>
                                <option value="SK Secretary">SK Secretary</option>
                                <option value="SK Treasurer">SK Treasurer</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="form-group"><label>Committee *</label><input type="text" name="committee" id="committee" placeholder="e.g., Chairperson, Health" required></div>
                    <div class="form-row">
                        <div class="form-group"><label>Term Start *</label><input type="date" name="term_start" id="term_start" required></div>
                        <div class="form-group"><label>Term End *</label><input type="date" name="term_end" id="term_end" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>Display Order *</label><input type="number" name="display_order" id="display_order" min="1" value="1" required></div>
                        <div class="form-group"><label>Type *</label><select name="official_type" id="official_type" required><option value="barangay">Barangay</option><option value="sk">SK</option></select></div>
                    </div>
                    <div class="form-group"><label>Photo</label><input type="file" name="photo" accept="image/*"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
    <form id="deleteForm" method="POST" style="display:none;"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="deleteId"></form>
    <script>
        function openAddModal() {
            document.getElementById('modalTitle').textContent = 'Add New Official';
            document.getElementById('formAction').value = 'add';
            document.getElementById('officialForm').reset();
            document.getElementById('officialId').value = '';
            document.getElementById('officialModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function editOfficial(official) {
            document.getElementById('modalTitle').textContent = 'Edit Official';
            document.getElementById('formAction').value = 'update';
            document.getElementById('officialId').value = official.official_id;
            document.getElementById('first_name').value = official.first_name;
            document.getElementById('middle_name').value = official.middle_name || '';
            document.getElementById('last_name').value = official.last_name;
            document.getElementById('position').value = official.position;
            document.getElementById('committee').value = official.committee;
            document.getElementById('term_start').value = official.term_start;
            document.getElementById('term_end').value = official.term_end;
            document.getElementById('display_order').value = official.display_order;
            document.getElementById('official_type').value = official.official_type;
            document.getElementById('officialModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeModal() {
            document.getElementById('officialModal').classList.remove('show');
            document.body.style.overflow = 'auto';
        }
        function deleteOfficial(id, name) {
            if (confirm(`Delete ${name}?`)) {
                document.getElementById('deleteId').value = id;
                document.getElementById('deleteForm').submit();
            }
        }
        window.onclick = (e) => { if (e.target.id === 'officialModal') closeModal(); }
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeModal(); });
    </script>
</body>
</html>