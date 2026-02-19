<?php
// Start output buffering FIRST
ob_start();

// Include required files
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/security.php';

requireLogin();

// Clean all output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Start fresh output buffer
ob_start();

// Set headers
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Validate request ID
$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($request_id <= 0) {
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Invalid request ID: ' . ($request_id === 0 ? '0 (zero)' : $request_id)
    ]);
    exit();
}

// Get current user
$user_id   = getCurrentUserId();
$user_role = getCurrentUserRole();

// Fetch request with ownership check
$sql = "SELECT r.*, 
        res.first_name, res.last_name, res.email, res.contact_number, res.address,
        rt.request_type_name, rt.fee,
        u.username as processed_by_name
        FROM tbl_requests r
        INNER JOIN tbl_residents res ON r.resident_id = res.resident_id
        LEFT JOIN tbl_request_types rt ON r.request_type_id = rt.request_type_id
        LEFT JOIN tbl_users u ON r.processed_by = u.user_id
        WHERE r.request_id = ?";

if ($user_role === 'Resident') {
    $sql .= " AND res.resident_id = (SELECT resident_id FROM tbl_users WHERE user_id = ?)";
}

$stmt = $conn->prepare($sql);

if (!$stmt) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database prepare error: ' . $conn->error]);
    exit();
}

if ($user_role === 'Resident') {
    $stmt->bind_param("ii", $request_id, $user_id);
} else {
    $stmt->bind_param("i", $request_id);
}

if (!$stmt->execute()) {
    ob_end_clean();
    echo json_encode(['success' => false, 'message' => 'Database execute error: ' . $stmt->error]);
    exit();
}

$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $stmt->close();
    ob_end_clean();
    echo json_encode([
        'success' => false, 
        'message' => 'Request not found or access denied. (ID: ' . $request_id . ')'
    ]);
    exit();
}

$request = $result->fetch_assoc();
$stmt->close();

// Fetch attachments
$attachments = [];
$attachments_sql = "SELECT a.attachment_id, a.request_id, a.requirement_id,
                           a.file_name, a.file_path, a.file_type, a.file_size, a.uploaded_at,
                           req.requirement_name 
                    FROM tbl_request_attachments a
                    LEFT JOIN tbl_requirements req ON a.requirement_id = req.requirement_id
                    WHERE a.request_id = ?
                    ORDER BY a.uploaded_at ASC";

$att_stmt = $conn->prepare($attachments_sql);
if ($att_stmt) {
    $att_stmt->bind_param("i", $request_id);
    $att_stmt->execute();
    $att_result = $att_stmt->get_result();
    while ($row = $att_result->fetch_assoc()) {
        $attachments[] = $row;
    }
    $att_stmt->close();
}

// Status helpers
$status_class = [
    'Pending' => 'warning', 
    'Approved' => 'info', 
    'Released' => 'success', 
    'Rejected' => 'danger'
];
$status_icon = [
    'Pending' => 'clock', 
    'Approved' => 'check-circle', 
    'Released' => 'check-double', 
    'Rejected' => 'times-circle'
];
$class = $status_class[$request['status']] ?? 'secondary';
$icon = $status_icon[$request['status']] ?? 'info-circle';

// Generate HTML
ob_start();
?>
<style>
.attachment-gallery {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.attachment-card {
    border: 2px solid #e0e0e0;
    border-radius: 12px;
    overflow: hidden;
    transition: all 0.3s ease;
    background: white;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.attachment-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    border-color: #0d6efd;
}
.attachment-preview {
    width: 100%;
    height: 200px;
    background: #f8f9fa;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    position: relative;
    cursor: pointer;
}
.attachment-preview img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    transition: transform 0.3s ease;
    pointer-events: none;
}
.attachment-preview:hover img { transform: scale(1.1); }
.attachment-preview .file-icon { font-size: 4rem; color: #6c757d; }
.attachment-preview .overlay {
    position: absolute;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.6);
    display: flex; align-items: center; justify-content: center;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
}
.attachment-preview:hover .overlay { opacity: 1; }
.attachment-preview .overlay i { color: white; font-size: 2.5rem; }
.attachment-info { padding: 15px; }
.attachment-tag {
    display: inline-block;
    padding: 4px 10px;
    background: #0d6efd;
    color: white;
    border-radius: 20px;
    font-size: 0.75rem;
    margin-bottom: 8px;
}
.attachment-filename {
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 8px;
    color: #2c3e50;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.attachment-meta {
    display: flex;
    justify-content: space-between;
    font-size: 0.75rem;
    color: #6c757d;
    margin-bottom: 10px;
}
.attachment-actions { display: flex; gap: 8px; }
.attachment-actions .btn { flex: 1; font-size: 0.8rem; padding: 6px 10px; }
.file-not-found {
    background: #fff3cd;
    border: 2px dashed #ffc107;
    padding: 30px;
    text-align: center;
    border-radius: 12px;
}
.image-lightbox-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.95);
    z-index: 10000;
    display: none;
    align-items: center;
    justify-content: center;
}
.image-lightbox-overlay.active { display: flex !important; }
.lightbox-controls {
    position: fixed;
    top: 20px; right: 20px;
    display: flex; gap: 10px;
    z-index: 10001;
}
.lightbox-btn {
    color: white;
    font-size: 1.5rem;
    cursor: pointer;
    background: rgba(0,0,0,0.7);
    width: 45px; height: 45px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    transition: all 0.3s ease;
    border: 2px solid transparent;
}
.lightbox-btn:hover { background: rgba(0,123,255,0.8); border-color: white; transform: scale(1.1); }
.lightbox-close-btn { background: rgba(220,53,69,0.7); }
.lightbox-close-btn:hover { background: rgba(220,53,69,0.9); }
.lightbox-content-wrapper {
    position: relative;
    max-width: 95vw; max-height: 95vh;
    overflow: auto;
    display: flex; align-items: center; justify-content: center;
}
.lightbox-image {
    display: block;
    max-width: 100%; max-height: 90vh;
    object-fit: contain;
    border-radius: 8px;
    cursor: zoom-in;
    transition: transform 0.3s ease;
    transform-origin: center center;
}
.lightbox-image.zoomed { cursor: zoom-out; max-width: none; max-height: none; }
.lightbox-info {
    position: fixed;
    bottom: 20px;
    left: 50%; transform: translateX(-50%);
    color: white;
    padding: 12px 24px;
    background: rgba(0,0,0,0.8);
    border-radius: 25px;
    max-width: 80%;
    z-index: 10001;
}
.zoom-indicator {
    position: fixed;
    bottom: 80px;
    left: 50%; transform: translateX(-50%);
    color: white;
    padding: 8px 16px;
    background: rgba(0,0,0,0.7);
    border-radius: 20px;
    font-size: 0.9rem;
    display: none;
    z-index: 10001;
}
.zoom-indicator.active { display: block; }
.timeline { position: relative; padding: 20px 0; }
.timeline-item { position: relative; padding-left: 40px; padding-bottom: 20px; }
.timeline-item:last-child { padding-bottom: 0; }
.timeline-item::before {
    content: '';
    position: absolute;
    left: 10px; top: 0; bottom: -20px;
    width: 2px; background: #dee2e6;
}
.timeline-item:last-child::before { display: none; }
.timeline-marker {
    position: absolute;
    left: 0; top: 0;
    width: 20px; height: 20px;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 0 0 2px #dee2e6;
}
.timeline-marker.pending  { background: #ffc107; }
.timeline-marker.approved { background: #0dcaf0; }
.timeline-marker.released { background: #198754; }
.timeline-marker.rejected { background: #dc3545; }
</style>

<div class="row">
    <!-- Left Column -->
    <div class="col-lg-8">

        <!-- Request Header -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <h4 class="mb-1">
                            <i class="fas fa-file-alt text-primary me-2"></i>
                            <?php echo htmlspecialchars($request['request_type_name'] ?? 'N/A'); ?>
                        </h4>
                        <p class="text-muted mb-0">
                            Request ID: <strong class="text-primary">#<?php echo str_pad($request['request_id'], 5, '0', STR_PAD_LEFT); ?></strong>
                        </p>
                    </div>
                    <span class="badge bg-<?php echo $class; ?> p-2">
                        <i class="fas fa-<?php echo $icon; ?> me-1"></i>
                        <?php echo htmlspecialchars($request['status']); ?>
                    </span>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <small class="text-muted d-block">Request Date</small>
                        <strong><?php echo date('F d, Y g:i A', strtotime($request['request_date'])); ?></strong>
                    </div>
                    <?php if ($request['processed_date']): ?>
                    <div class="col-md-6 mb-2">
                        <small class="text-muted d-block">Processed Date</small>
                        <strong><?php echo date('F d, Y g:i A', strtotime($request['processed_date'])); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Resident Information -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-user me-2"></i>Resident Information</h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-12 mb-3">
                        <label class="text-muted small d-block">Full Name</label>
                        <strong><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></strong>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small d-block">Email</label>
                        <strong><?php echo htmlspecialchars($request['email'] ?? 'N/A'); ?></strong>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="text-muted small d-block">Contact</label>
                        <strong><?php echo htmlspecialchars($request['contact_number'] ?? 'N/A'); ?></strong>
                    </div>
                    <div class="col-md-12">
                        <label class="text-muted small d-block">Address</label>
                        <strong><?php echo htmlspecialchars($request['address'] ?? 'N/A'); ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Request Details -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Request Details</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted small d-block">Purpose</label>
                    <div class="bg-light p-3 rounded">
                        <?php echo nl2br(htmlspecialchars($request['purpose'])); ?>
                    </div>
                </div>
                <?php if ($request['remarks']): ?>
                <div class="mb-0">
                    <label class="text-muted small d-block">Remarks / Notes</label>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-comment me-2"></i>
                        <?php echo nl2br(htmlspecialchars($request['remarks'])); ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Attachments -->
        <?php if (!empty($attachments)): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-paperclip me-2"></i>Attachments</h6>
                <span class="badge bg-primary"><?php echo count($attachments); ?> Files</span>
            </div>
            <div class="card-body">
                <div class="attachment-gallery">
                    <?php foreach ($attachments as $index => $attachment): ?>
                        <?php
                        $web_path    = '../../' . $attachment['file_path'];
                        $server_path = realpath(__DIR__ . '/../../' . $attachment['file_path']);
                        $file_exists = $server_path && file_exists($server_path);
                        $extension   = strtolower(pathinfo($attachment['file_name'], PATHINFO_EXTENSION));
                        $is_image    = in_array($extension, ['jpg','jpeg','png','gif','bmp','webp','svg','ico','tiff','tif']);
                        $is_pdf      = ($extension === 'pdf');
                        $unique_id   = 'img_' . $request_id . '_' . $index;
                        ?>
                        <div class="attachment-card">
                            <?php if ($file_exists): ?>
                                <div class="attachment-preview" id="<?php echo $unique_id; ?>">
                                    <?php if ($is_image): ?>
                                        <img src="<?php echo htmlspecialchars($web_path); ?>"
                                             alt="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                        <div class="overlay"><i class="fas fa-search-plus"></i></div>
                                    <?php elseif ($is_pdf): ?>
                                        <i class="fas fa-file-pdf file-icon text-danger"></i>
                                        <div class="overlay"><i class="fas fa-eye"></i></div>
                                    <?php else: ?>
                                        <i class="fas fa-file file-icon text-primary"></i>
                                        <div class="overlay"><i class="fas fa-download"></i></div>
                                    <?php endif; ?>
                                </div>
                                <div class="attachment-info">
                                    <?php if (!empty($attachment['requirement_name'])): ?>
                                    <div class="attachment-tag">
                                        <i class="fas fa-tag me-1"></i><?php echo htmlspecialchars($attachment['requirement_name']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <div class="attachment-filename" title="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                        <?php echo htmlspecialchars($attachment['file_name']); ?>
                                    </div>
                                    <div class="attachment-meta">
                                        <span><i class="fas fa-hdd me-1"></i><?php echo number_format($attachment['file_size'] / 1024, 2); ?> KB</span>
                                        <span><i class="fas fa-clock me-1"></i><?php echo date('M d, Y', strtotime($attachment['uploaded_at'])); ?></span>
                                    </div>
                                    <?php if (!$is_image): ?>
                                    <div class="attachment-actions">
                                        <a href="<?php echo htmlspecialchars($web_path); ?>" class="btn btn-sm btn-primary" target="_blank">
                                            <i class="fas fa-external-link-alt me-1"></i>Open
                                        </a>
                                        <a href="<?php echo htmlspecialchars($web_path); ?>" class="btn btn-sm btn-success"
                                           download="<?php echo htmlspecialchars($attachment['file_name']); ?>">
                                            <i class="fas fa-download me-1"></i>Download
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php if ($is_image): ?>
                                <script>
                                (function() {
                                    var el = document.getElementById('<?php echo $unique_id; ?>');
                                    if (el) {
                                        el.addEventListener('click', function(e) {
                                            e.preventDefault(); e.stopPropagation();
                                            window.openImageLightboxGlobal(
                                                '<?php echo addslashes($web_path); ?>',
                                                '<?php echo addslashes($attachment['file_name']); ?>'
                                            );
                                        });
                                    }
                                })();
                                </script>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="file-not-found p-3">
                                    <i class="fas fa-exclamation-triangle fa-2x text-danger d-block mb-2"></i>
                                    <h6 class="text-danger">File Not Found</h6>
                                    <small class="text-break"><?php echo htmlspecialchars($attachment['file_name']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <p class="text-muted mb-0">No attachments uploaded.</p>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column -->
    <div class="col-lg-4">

        <!-- Payment -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-money-bill-wave me-2"></i>Payment</h6>
            </div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="text-muted small d-block">Fee</label>
                    <?php if ($request['fee'] > 0): ?>
                        <h4 class="mb-0 text-success">₱<?php echo number_format($request['fee'], 2); ?></h4>
                    <?php else: ?>
                        <span class="badge bg-light text-dark">Free</span>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="text-muted small d-block">Payment Status</label>
                    <?php if ($request['payment_status']): ?>
                        <span class="badge bg-success p-2"><i class="fas fa-check-circle me-1"></i>Paid</span>
                    <?php elseif ($request['fee'] > 0): ?>
                        <span class="badge bg-warning text-dark p-2"><i class="fas fa-exclamation-circle me-1"></i>Unpaid</span>
                    <?php else: ?>
                        <span class="badge bg-secondary p-2">N/A</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Processing -->
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-cog me-2"></i>Processing</h6>
            </div>
            <div class="card-body">
                <?php if ($request['processed_by_name']): ?>
                <div class="mb-2">
                    <label class="text-muted small d-block">Processed By</label>
                    <strong><?php echo htmlspecialchars($request['processed_by_name']); ?></strong>
                </div>
                <?php endif; ?>
                <?php if ($request['processed_date']): ?>
                    <?php $diff = (new DateTime($request['request_date']))->diff(new DateTime($request['processed_date'])); ?>
                    <label class="text-muted small d-block">Processing Time</label>
                    <strong><?php echo $diff->days > 0 ? $diff->days . ' day' . ($diff->days > 1 ? 's' : '') : 'Same day'; ?></strong>
                <?php else: ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <small>Still being processed</small>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Timeline -->
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-light">
                <h6 class="mb-0"><i class="fas fa-history me-2"></i>Timeline</h6>
            </div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-marker <?php echo strtolower($request['status']); ?>"></div>
                        <div>
                            <strong><?php echo htmlspecialchars($request['status']); ?></strong><br>
                            <small class="text-muted">
                                <?php echo $request['processed_date']
                                    ? date('M d, Y g:i A', strtotime($request['processed_date']))
                                    : 'Current Status'; ?>
                            </small>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <div class="timeline-marker pending"></div>
                        <div>
                            <strong>Submitted</strong><br>
                            <small class="text-muted"><?php echo date('M d, Y g:i A', strtotime($request['request_date'])); ?></small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Lightbox -->
<div class="image-lightbox-overlay" id="imageLightbox">
    <div class="lightbox-controls">
        <div class="lightbox-btn" id="lbZoomIn"><i class="fas fa-search-plus"></i></div>
        <div class="lightbox-btn" id="lbZoomOut"><i class="fas fa-search-minus"></i></div>
        <div class="lightbox-btn" id="lbReset"><i class="fas fa-compress"></i></div>
        <div class="lightbox-btn lightbox-close-btn" id="lbClose"><i class="fas fa-times"></i></div>
    </div>
    <div class="lightbox-content-wrapper">
        <img class="lightbox-image" id="lightboxImg" src="" alt="">
    </div>
    <div class="zoom-indicator" id="zoomIndicator">
        <i class="fas fa-search-plus me-2"></i><span id="zoomLevel">100%</span>
    </div>
    <div class="lightbox-info"><strong id="lightboxFileName"></strong></div>
</div>

<script>
(function() {
    var zoom = 1;

    window.openImageLightboxGlobal = function(src, name) {
        var lb  = document.getElementById('imageLightbox');
        var img = document.getElementById('lightboxImg');
        if (!lb || !img) return;
        zoom = 1;
        img.classList.remove('zoomed');
        img.style.transform = 'scale(1)';
        img.src = src;
        document.getElementById('lightboxFileName').textContent = name;
        document.getElementById('zoomLevel').textContent = '100%';
        lb.classList.add('active');
        document.body.style.overflow = 'hidden';
    };

    function close() {
        var lb = document.getElementById('imageLightbox');
        if (!lb) return;
        lb.classList.remove('active');
        document.body.style.overflow = '';
        zoom = 1;
        var img = document.getElementById('lightboxImg');
        if (img) { img.classList.remove('zoomed'); img.style.transform = 'scale(1)'; }
    }

    function setZoom(z) {
        zoom = Math.min(Math.max(z, 1), 5);
        var img = document.getElementById('lightboxImg');
        if (!img) return;
        img.style.transform = 'scale(' + zoom + ')';
        zoom > 1 ? img.classList.add('zoomed') : img.classList.remove('zoomed');
        document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
    }

    setTimeout(function() {
        var lb  = document.getElementById('imageLightbox');
        var img = document.getElementById('lightboxImg');
        if (document.getElementById('lbClose')) {
            document.getElementById('lbClose').onclick = function(e) { e.stopPropagation(); close(); };
        }
        if (document.getElementById('lbZoomIn')) {
            document.getElementById('lbZoomIn').onclick = function(e) { e.stopPropagation(); setZoom(zoom + 0.5); };
        }
        if (document.getElementById('lbZoomOut')) {
            document.getElementById('lbZoomOut').onclick = function(e) { e.stopPropagation(); setZoom(zoom - 0.5); };
        }
        if (document.getElementById('lbReset')) {
            document.getElementById('lbReset').onclick = function(e) { e.stopPropagation(); setZoom(1); };
        }
        if (lb)  lb.onclick  = function(e) { if (e.target === this) close(); };
        if (img) img.onclick = function(e) { e.stopPropagation(); setZoom(zoom > 1 ? 1 : 2); };
        document.addEventListener('keydown', function(e) {
            if (!lb || !lb.classList.contains('active')) return;
            if (e.key === 'Escape') close();
            if (e.key === '+' || e.key === '=') setZoom(zoom + 0.5);
            if (e.key === '-') setZoom(zoom - 0.5);
            if (e.key === '0') setZoom(1);
        });
    }, 100);
})();
</script>

<?php
$html = ob_get_clean();

// Clean all remaining buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Send response
echo json_encode(['success' => true, 'html' => $html]);
exit();