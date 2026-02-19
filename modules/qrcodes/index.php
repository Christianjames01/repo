<?php
/**
 * QR Code Management Module
 * Location: /modules/qrcodes/index.php
 * Barangay Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/qr_helper.php';

// Require admin or staff access
requireAnyRole(['Admin', 'Super Admin', 'Staff']);

$page_title = "QR Code Management";

// Handle AJAX requests - MUST be before any HTML output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // Clear any output buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: application/json');
    
    $action = $_POST['action'];
    
    try {
        switch ($action) {
            case 'generate_single':
                $resident_id = intval($_POST['resident_id'] ?? 0);
                
                if ($resident_id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid resident ID']);
                    exit;
                }
                
                // Get resident data
                $resident = fetchOne($conn,
                    "SELECT resident_id,
                            CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name,
                            address,
                            contact_number as contact
                     FROM tbl_residents
                     WHERE resident_id = ?",
                    [$resident_id], 'i'
                );
                
                if (!$resident) {
                    echo json_encode(['success' => false, 'message' => 'Resident not found']);
                    exit;
                }
                
                // Generate QR code
                $qr_dir = UPLOAD_DIR . 'qrcodes/';
                
                // Create directory if it doesn't exist
                if (!file_exists($qr_dir)) {
                    if (!mkdir($qr_dir, 0777, true)) {
                        echo json_encode(['success' => false, 'message' => 'Failed to create QR code directory']);
                        exit;
                    }
                }
                
                // Check if directory is writable
                if (!is_writable($qr_dir)) {
                    echo json_encode(['success' => false, 'message' => 'QR code directory is not writable']);
                    exit;
                }
                
                $qr_filename = generateResidentQRCode($resident_id, $resident, $qr_dir);
                
                if ($qr_filename) {
                    // Verify the file was actually created
                    if (!file_exists($qr_dir . $qr_filename)) {
                        echo json_encode(['success' => false, 'message' => 'QR code file was not created']);
                        exit;
                    }
                    
                    // Update resident record
                    $update_result = executeQuery($conn,
                        "UPDATE tbl_residents SET qr_code = ? WHERE resident_id = ?",
                        [$qr_filename, $resident_id], 'si'
                    );
                    
                    // Log activity
                    logActivity($conn, getCurrentUserId(), 
                        "Generated QR code for resident: {$resident['full_name']}",
                        'tbl_residents', $resident_id);
                    
                    echo json_encode([
                        'success' => true,
                        'message' => 'QR code generated successfully',
                        'qr_code' => $qr_filename,
                        'qr_url' => getQRCodeURL($qr_filename)
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to generate QR code. Please check server logs.']);
                }
                exit;
                
            case 'batch_generate':
                $residents = fetchAll($conn,
                    "SELECT resident_id,
                            CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name,
                            address,
                            contact_number as contact
                     FROM tbl_residents
                     WHERE (qr_code IS NULL OR qr_code = '')"
                );
                
                if (empty($residents)) {
                    echo json_encode([
                        'success' => true,
                        'generated' => 0,
                        'errors' => [],
                        'message' => 'No residents found without QR codes'
                    ]);
                    exit;
                }
                
                $generated = 0;
                $errors = [];
                $qr_dir = UPLOAD_DIR . 'qrcodes/';
                
                // Create directory if it doesn't exist
                if (!file_exists($qr_dir)) {
                    mkdir($qr_dir, 0777, true);
                }
                
                foreach ($residents as $resident) {
                    $qr_filename = generateResidentQRCode($resident['resident_id'], $resident, $qr_dir);
                    
                    if ($qr_filename) {
                        executeQuery($conn,
                            "UPDATE tbl_residents SET qr_code = ? WHERE resident_id = ?",
                            [$qr_filename, $resident['resident_id']], 'si'
                        );
                        $generated++;
                    } else {
                        $errors[] = "Failed to generate QR for: {$resident['full_name']}";
                    }
                }
                
                // Log activity
                logActivity($conn, getCurrentUserId(), 
                    "Batch generated QR codes for {$generated} residents",
                    'tbl_residents', null);
                
                echo json_encode([
                    'success' => true,
                    'generated' => $generated,
                    'errors' => $errors,
                    'message' => "Successfully generated {$generated} QR code" . ($generated !== 1 ? 's' : '')
                ]);
                exit;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
                exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        exit;
    }
}

include __DIR__ . '/../../includes/header.php';

// Get statistics with error handling
try {
    $total_residents = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_residents");
    $with_qr = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_residents WHERE qr_code IS NOT NULL AND qr_code != ''");
    $without_qr = fetchOne($conn, "SELECT COUNT(*) as count FROM tbl_residents WHERE (qr_code IS NULL OR qr_code = '')");
} catch (Exception $e) {
    $total_residents = ['count' => 0];
    $with_qr = ['count' => 0];
    $without_qr = ['count' => 0];
}
?>

<div class="container-fluid py-4">
    <?php echo displayMessage(); ?>
    
    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-md-4">
            <div class="card bg-primary text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-users fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="card-title mb-0">Total Residents</h5>
                            <h2 class="mb-0"><?php echo number_format($total_residents['count']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card bg-success text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-qrcode fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="card-title mb-0">With QR Codes</h5>
                            <h2 class="mb-0"><?php echo number_format($with_qr['count']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-md-4">
            <div class="card bg-warning text-white shadow-sm">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle fa-3x"></i>
                        </div>
                        <div class="flex-grow-1 ms-3">
                            <h5 class="card-title mb-0">Without QR Codes</h5>
                            <h2 class="mb-0"><?php echo number_format($without_qr['count']); ?></h2>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Action Buttons -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="fas fa-tools me-2"></i>Bulk Actions</h5>
                    <button type="button" class="btn btn-primary" id="batchGenerateBtn">
                        <i class="fas fa-magic me-2"></i>Generate QR Codes for All Residents
                    </button>
                    <a href="print_id_cards.php" class="btn btn-info" target="_blank">
                        <i class="fas fa-print me-2"></i>Print All ID Cards
                    </a>
                    <a href="../certificates/" class="btn btn-success">
                        <i class="fas fa-certificate me-2"></i>Manage Certificates
                    </a>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Residents Table -->
    <div class="row">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-list me-2"></i>Resident QR Codes</h4>
                </div>
                <div class="card-body">
                    <!-- Search and Filter -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="searchInput" placeholder="Search residents...">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <select class="form-select" id="filterStatus">
                                <option value="">All Residents</option>
                                <option value="with_qr">With QR Code</option>
                                <option value="without_qr">Without QR Code</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <button type="button" class="btn btn-secondary w-100" onclick="location.reload()">
                                <i class="fas fa-sync-alt me-2"></i>Refresh
                            </button>
                        </div>
                    </div>
                    
                    <!-- Table -->
                    <div class="table-responsive">
                        <table class="table table-striped table-hover" id="residentsTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Address</th>
                                    <th>Contact</th>
                                    <th>QR Code</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                try {
                                    $residents = fetchAll($conn,
                                        "SELECT resident_id,
                                                CONCAT(first_name, ' ', IFNULL(CONCAT(middle_name, ' '), ''), last_name) as full_name,
                                                address,
                                                contact_number,
                                                qr_code,
                                                profile_photo
                                         FROM tbl_residents
                                         ORDER BY last_name, first_name"
                                    );
                                    
                                    if (empty($residents)) {
                                        echo '<tr><td colspan="6" class="text-center">No residents found</td></tr>';
                                    } else {
                                        foreach ($residents as $resident):
                                ?>
                                <tr data-resident-id="<?php echo $resident['resident_id']; ?>">
                                    <td><?php echo $resident['resident_id']; ?></td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <?php if (!empty($resident['profile_photo'])): ?>
                                                <img src="<?php echo UPLOAD_URL; ?>profiles/<?php echo htmlspecialchars($resident['profile_photo']); ?>"
                                                     alt="Photo" class="rounded-circle me-2" style="width: 40px; height: 40px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="rounded-circle bg-secondary text-white me-2 d-flex align-items-center justify-content-center"
                                                     style="width: 40px; height: 40px;">
                                                    <?php echo strtoupper(substr($resident['full_name'], 0, 1)); ?>
                                                </div>
                                            <?php endif; ?>
                                            <span><?php echo htmlspecialchars($resident['full_name']); ?></span>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($resident['address']); ?></td>
                                    <td><?php echo htmlspecialchars($resident['contact_number'] ?? 'N/A'); ?></td>
                                    <td class="qr-cell">
                                        <?php if (!empty($resident['qr_code'])): ?>
                                            <img src="<?php echo getQRCodeURL($resident['qr_code']); ?>"
                                                 alt="QR Code" style="width: 60px; height: 60px;" class="border">
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Not Generated</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="actions-cell">
                                        <div class="btn-group btn-group-sm" role="group">
                                            <?php if (!empty($resident['qr_code'])): ?>
                                                <button type="button" class="btn btn-info view-qr-btn"
                                                        data-qr="<?php echo htmlspecialchars($resident['qr_code']); ?>"
                                                        data-name="<?php echo htmlspecialchars($resident['full_name']); ?>"
                                                        title="View QR Code">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <a href="print_id_card.php?resident_id=<?php echo $resident['resident_id']; ?>"
                                                   class="btn btn-primary" target="_blank" title="Print ID Card">
                                                    <i class="fas fa-print"></i>
                                                </a>
                                                <a href="<?php echo getQRCodeURL($resident['qr_code']); ?>"
                                                   class="btn btn-success" download title="Download QR Code">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-<?php echo empty($resident['qr_code']) ? 'primary' : 'warning'; ?> generate-qr-btn"
                                                    data-resident-id="<?php echo $resident['resident_id']; ?>"
                                                    title="<?php echo empty($resident['qr_code']) ? 'Generate' : 'Regenerate'; ?> QR Code">
                                                <i class="fas fa-<?php echo empty($resident['qr_code']) ? 'plus' : 'sync-alt'; ?>"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php 
                                        endforeach;
                                    }
                                } catch (Exception $e) {
                                    echo '<tr><td colspan="6" class="text-center text-danger">Error loading residents: ' . htmlspecialchars($e->getMessage()) . '</td></tr>';
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Message Modal -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="messageModalHeader">
                <h5 class="modal-title" id="messageModalTitle">
                    <i class="fas fa-info-circle me-2"></i>Message
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="messageModalBody">
                <!-- Message content will be inserted here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" id="messageModalClose">Close</button>
                <button type="button" class="btn btn-primary" id="messageModalConfirm" style="display: none;">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- QR Code Preview Modal -->
<div class="modal fade" id="qrPreviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-qrcode me-2"></i>QR Code Preview</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <h6 id="residentNamePreview" class="mb-3"></h6>
                <img id="qrPreviewImage" src="" alt="QR Code" class="img-fluid border" style="max-width: 400px;">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a id="downloadQrLink" href="" class="btn btn-success" download>
                    <i class="fas fa-download me-2"></i>Download
                </a>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const qrPreviewModal = new bootstrap.Modal(document.getElementById('qrPreviewModal'));
    const messageModal = new bootstrap.Modal(document.getElementById('messageModal'));
    
    // Helper function to show message modal
    function showMessage(title, message, type = 'info', isConfirm = false) {
        const modalHeader = document.getElementById('messageModalHeader');
        const modalTitle = document.getElementById('messageModalTitle');
        const modalBody = document.getElementById('messageModalBody');
        const confirmBtn = document.getElementById('messageModalConfirm');
        const closeBtn = document.getElementById('messageModalClose');
        
        // Set colors based on type
        const colors = {
            success: { bg: 'bg-success', icon: 'fa-check-circle' },
            error: { bg: 'bg-danger', icon: 'fa-exclamation-circle' },
            warning: { bg: 'bg-warning', icon: 'fa-exclamation-triangle', text: 'text-dark' },
            info: { bg: 'bg-info', icon: 'fa-info-circle' }
        };
        
        const color = colors[type] || colors.info;
        
        // Update modal
        modalHeader.className = `modal-header ${color.bg} ${color.text || 'text-white'}`;
        modalTitle.innerHTML = `<i class="fas ${color.icon} me-2"></i>${title}`;
        modalBody.textContent = message;
        
        // Update close button color for white backgrounds
        const closeButtonInHeader = modalHeader.querySelector('.btn-close');
        if (color.text === 'text-dark') {
            closeButtonInHeader.classList.remove('btn-close-white');
        } else {
            closeButtonInHeader.classList.add('btn-close-white');
        }
        
        // Show/hide confirm button
        if (isConfirm) {
            confirmBtn.style.display = 'inline-block';
            closeBtn.textContent = 'Cancel';
            
            return new Promise((resolve) => {
                // Remove any existing event listeners by cloning
                const newConfirmBtn = confirmBtn.cloneNode(true);
                confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
                
                const newCloseBtn = closeBtn.cloneNode(true);
                closeBtn.parentNode.replaceChild(newCloseBtn, closeBtn);
                
                newConfirmBtn.onclick = () => {
                    messageModal.hide();
                    resolve(true);
                };
                
                newCloseBtn.onclick = () => {
                    messageModal.hide();
                    resolve(false);
                };
                
                // Handle modal close event
                document.getElementById('messageModal').addEventListener('hidden.bs.modal', function() {
                    resolve(false);
                }, { once: true });
                
                messageModal.show();
            });
        } else {
            confirmBtn.style.display = 'none';
            closeBtn.textContent = 'Close';
            messageModal.show();
            return Promise.resolve(true);
        }
    }
    
    // Function to update table row with QR code
    function updateTableRow(residentId, qrCode, qrUrl) {
        const row = document.querySelector(`tr[data-resident-id="${residentId}"]`);
        if (!row) {
            console.error('Row not found for resident ID:', residentId);
            return;
        }
        
        // Update QR Code column
        const qrCell = row.querySelector('.qr-cell');
        if (qrCell) {
            qrCell.innerHTML = `<img src="${qrUrl}?t=${Date.now()}" alt="QR Code" style="width: 60px; height: 60px;" class="border">`;
        }
        
        // Update Actions column
        const actionsCell = row.querySelector('.actions-cell');
        const residentName = row.cells[1].querySelector('span').textContent;
        
        if (actionsCell) {
            actionsCell.innerHTML = `
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-info view-qr-btn"
                            data-qr="${qrCode}"
                            data-name="${residentName}"
                            title="View QR Code">
                        <i class="fas fa-eye"></i>
                    </button>
                    <a href="print_id_card.php?resident_id=${residentId}"
                       class="btn btn-primary" target="_blank" title="Print ID Card">
                        <i class="fas fa-print"></i>
                    </a>
                    <a href="${qrUrl}"
                       class="btn btn-success" download title="Download QR Code">
                        <i class="fas fa-download"></i>
                    </a>
                    <button type="button" class="btn btn-warning generate-qr-btn"
                            data-resident-id="${residentId}"
                            title="Regenerate QR Code">
                        <i class="fas fa-sync-alt"></i>
                    </button>
                </div>
            `;
            
            // Re-attach event listeners to the new buttons
            attachButtonListeners(actionsCell);
        }
    }
    
    // Function to attach event listeners to buttons
    function attachButtonListeners(container) {
        // View QR button
        const viewBtn = container.querySelector('.view-qr-btn');
        if (viewBtn) {
            viewBtn.addEventListener('click', function() {
                const qrCode = this.dataset.qr;
                const name = this.dataset.name;
                const qrUrl = '<?php echo UPLOAD_URL; ?>qrcodes/' + qrCode + '?t=' + Date.now();
                
                document.getElementById('residentNamePreview').textContent = name;
                document.getElementById('qrPreviewImage').src = qrUrl;
                document.getElementById('downloadQrLink').href = '<?php echo UPLOAD_URL; ?>qrcodes/' + qrCode;
                
                qrPreviewModal.show();
            });
        }
        
        // Generate QR button
        const generateBtn = container.querySelector('.generate-qr-btn');
        if (generateBtn) {
            generateBtn.addEventListener('click', handleGenerateQR);
        }
    }
    
    // Handle QR generation
    async function handleGenerateQR() {
        const residentId = this.dataset.residentId;
        const button = this;
        const originalHtml = button.innerHTML;
        
        const confirmed = await showMessage(
            'Confirm Generation',
            'Generate QR code for this resident?',
            'warning',
            true
        );
        
        if (!confirmed) return;
        
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=generate_single&resident_id=${residentId}`
            });
            
            const data = await response.json();
            console.log('Response:', data);
            
            if (data.success) {
                // Update the table row with new QR code
                updateTableRow(residentId, data.qr_code, data.qr_url);
                
                // Show success message
                await showMessage('Success', data.message, 'success');
            } else {
                await showMessage('Error', data.message, 'error');
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        } catch (error) {
            console.error('Fetch error:', error);
            await showMessage('Error', 'An error occurred while generating QR code. Please try again.', 'error');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }
    
    // Generate single QR code (initial page load)
    document.querySelectorAll('.generate-qr-btn').forEach(btn => {
        btn.addEventListener('click', handleGenerateQR);
    });
    
    // Batch generate QR codes
    document.getElementById('batchGenerateBtn').addEventListener('click', async function() {
        const confirmed = await showMessage(
            'Confirm Batch Generation',
            'Generate QR codes for all residents without QR codes? This may take a while.',
            'warning',
            true
        );
        
        if (!confirmed) return;
        
        const button = this;
        const originalHtml = button.innerHTML;
        
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Generating...';
        
        try {
            const response = await fetch('', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=batch_generate'
            });
            
            const data = await response.json();
            
            if (data.success) {
                let message = data.message;
                if (data.errors && data.errors.length > 0) {
                    message += '\n\nErrors:\n' + data.errors.join('\n');
                }
                await showMessage('Success', message, 'success');
                location.reload();
            } else {
                await showMessage('Error', data.message, 'error');
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        } catch (error) {
            console.error('Fetch error:', error);
            await showMessage('Error', 'An error occurred while batch generating QR codes. Please try again.', 'error');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    });
    
    // View QR code (initial page load)
    document.querySelectorAll('.view-qr-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const qrCode = this.dataset.qr;
            const name = this.dataset.name;
            const qrUrl = '<?php echo UPLOAD_URL; ?>qrcodes/' + qrCode + '?t=' + Date.now();
            
            document.getElementById('residentNamePreview').textContent = name;
            document.getElementById('qrPreviewImage').src = qrUrl;
            document.getElementById('downloadQrLink').href = '<?php echo UPLOAD_URL; ?>qrcodes/' + qrCode;
            
            qrPreviewModal.show();
        });
    });
    
    // Search functionality
    document.getElementById('searchInput').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#residentsTable tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(searchTerm) ? '' : 'none';
        });
    });
    
    // Filter by QR status
    document.getElementById('filterStatus').addEventListener('change', function() {
        const filter = this.value;
        const rows = document.querySelectorAll('#residentsTable tbody tr');
        
        rows.forEach(row => {
            const hasQr = row.querySelector('img[alt="QR Code"]') !== null;
            
            if (filter === '') {
                row.style.display = '';
            } else if (filter === 'with_qr' && hasQr) {
                row.style.display = '';
            } else if (filter === 'without_qr' && !hasQr) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>