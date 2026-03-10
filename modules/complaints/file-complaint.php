<?php
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/security.php';
require_once '../../includes/functions.php';
require_once '../../config/session.php';

requireRole('Resident');

$current_user_id = getCurrentUserId();
$page_title = 'File a Complaint';

$resident_id  = null;
$resident_info = [];

$stmt = $conn->prepare("SELECT u.resident_id, r.first_name, r.last_name, r.email, r.contact_number, r.address
                        FROM tbl_users u
                        LEFT JOIN tbl_residents r ON u.resident_id = r.resident_id
                        WHERE u.user_id = ?");
$stmt->bind_param("i", $current_user_id);
$stmt->execute();
$result = $stmt->get_result();
if ($result->num_rows > 0) { $resident_info = $result->fetch_assoc(); $resident_id = $resident_info['resident_id']; }
$stmt->close();

if (!$resident_id) {
    $_SESSION['error_message'] = 'Resident information not found';
    header('Location: view-complaints.php'); exit;
}

include '../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-danger:#ef4444;--db-danger-light:#fee2e2;
    --db-info:#3b82f6;--db-info-light:#dbeafe;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* ── Hero ── */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#1a4a2e 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#e11d48,#be123c);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* ── Alerts ── */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--error{background:var(--db-danger-light);color:#7f1d1d;border-color:var(--db-danger);}
.db-alert--info{background:var(--db-info-light);color:#1e40af;border-color:var(--db-info);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* ── Panel ── */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--rose{background:var(--db-rose-light);color:var(--db-rose);}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--purple{background:#f3e8ff;color:#7c3aed;}
.db-panel__body{padding:22px;}

/* ── Badges ── */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--rose{background:var(--db-rose-light);color:#9f1239;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--sky{background:var(--db-sky-light);color:#0369a1;}
.db-badge--success{background:var(--db-success-light);color:#065f46;}
.db-badge--muted{background:var(--db-surf2);color:var(--db-muted);border:1px solid var(--db-border);}
.db-badge--danger{background:var(--db-danger-light);color:#7f1d1d;}

/* ── Buttons ── */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--danger{background:linear-gradient(135deg,var(--db-rose),#be123c);color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--glass{background:rgba(255,255,255,.1);color:#fff;border-color:rgba(255,255,255,.2);}
.db-btn--glass:hover{background:rgba(255,255,255,.2);color:#fff;}

/* ── Form controls ── */
.db-form-group{margin-bottom:18px;}
.db-form-label{font-family:'DM Mono',monospace;font-size:10px;font-weight:500;text-transform:uppercase;letter-spacing:.8px;color:var(--db-muted);margin-bottom:6px;display:block;}
.db-form-control,.db-form-select,.db-form-textarea{display:block;width:100%;border:2px solid var(--db-border);border-radius:var(--db-radius-sm);font-size:13px;padding:10px 13px;font-family:'Sora',sans-serif;transition:border-color .18s,box-shadow .18s;background:var(--db-surf);color:var(--db-text);}
.db-form-control:focus,.db-form-select:focus,.db-form-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);outline:none;}
.db-form-textarea{resize:vertical;min-height:130px;line-height:1.7;}
.db-form-row--2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}

/* ── Upload zone ── */
.db-upload-zone{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:28px 20px;border:2px dashed var(--db-border);border-radius:var(--db-radius);background:var(--db-surf2);cursor:pointer;transition:border-color .2s,background .2s;}
.db-upload-zone:hover,.db-upload-zone.has-file{border-color:var(--db-navy-light);background:var(--db-indigo-light);}
.db-upload-zone__icon{font-size:28px;color:var(--db-muted);transition:color .2s;}
.db-upload-zone:hover .db-upload-zone__icon,.db-upload-zone.has-file .db-upload-zone__icon{color:var(--db-navy-light);}
.db-upload-zone__text{font-size:13px;font-weight:600;color:var(--db-text);}
.db-upload-zone__hint{font-size:11px;color:var(--db-muted);}

/* ── Image preview grid ── */
.db-img-preview-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:10px;margin-top:12px;}
.db-img-preview-card{border-radius:var(--db-radius-sm);overflow:hidden;border:1px solid var(--db-border);background:var(--db-surf);box-shadow:var(--db-shadow);position:relative;animation:dbFadeUp .2s ease both;}
.db-img-preview-card__thumb{height:110px;overflow:hidden;background:var(--db-surf2);display:flex;align-items:center;justify-content:center;}
.db-img-preview-card__thumb img{width:100%;height:100%;object-fit:cover;}
.db-img-preview-card__thumb .file-icon{font-size:2rem;color:var(--db-sky);}
.db-img-preview-card__foot{padding:7px 10px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--db-border);}
.db-img-preview-card__name{font-size:10px;color:var(--db-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:80px;}
.db-img-preview-card__remove{background:none;border:none;cursor:pointer;color:var(--db-rose);padding:2px 4px;border-radius:4px;font-size:13px;transition:background .15s;}
.db-img-preview-card__remove:hover{background:var(--db-rose-light);}

/* ── Side card ── */
.db-side-card{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:16px;overflow:hidden;animation:dbFadeUp .35s ease both;}
.db-side-card__header{display:flex;align-items:center;gap:8px;padding:14px 18px;border-bottom:1px solid var(--db-border);font-size:13px;font-weight:700;}
.db-side-card__body{padding:14px 18px;}

/* ── Lightbox ── */
#db-lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:9999;align-items:center;justify-content:center;}
#db-lightbox.active{display:flex;}
#db-lightbox-img{max-width:90vw;max-height:90vh;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,.5);}
#db-lightbox-close{position:absolute;top:20px;right:28px;color:#fff;font-size:36px;cursor:pointer;line-height:1;opacity:.7;transition:opacity .2s;}
#db-lightbox-close:hover{opacity:1;}

@media(max-width:768px){
    .rm-hero{padding:20px;border-radius:0;}
    .db-form-row--2{grid-template-columns:1fr;}
    .fc-grid{grid-template-columns:1fr!important;}
}
</style>

<!-- ── Hero ── -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-file-alt"></i></div>
            <div>
                <div class="rm-hero__title">File a Complaint</div>
                <div class="rm-hero__sub">Submit your concern or complaint to the barangay office</div>
            </div>
        </div>
        <a href="view-complaints.php" class="db-btn db-btn--glass db-btn--sm">
            <i class="fas fa-arrow-left"></i> Back to Complaints
        </a>
    </div>
</div>

<div style="padding:0 24px 32px;">

<?php if (isset($_SESSION['error_message'])): ?>
<div class="db-alert db-alert--error">
    <i class="fas fa-exclamation-circle"></i>
    <?php echo htmlspecialchars($_SESSION['error_message']); unset($_SESSION['error_message']); ?>
    <button class="db-alert__close" onclick="this.parentElement.remove()">×</button>
</div>
<?php endif; ?>

<form action="process-complaint.php" method="POST" enctype="multipart/form-data" id="complaintForm">
    <input type="hidden" name="action" value="file_complaint">
    <input type="hidden" name="resident_id" value="<?php echo $resident_id; ?>">

    <div class="fc-grid" style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

        <!-- ══ LEFT COLUMN ══ -->
        <div>

            <!-- Complaint Information -->
            <div class="db-panel" style="animation-delay:.05s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--rose"><i class="fas fa-info-circle"></i></div>
                        <h2>Complaint Information</h2>
                    </div>
                </div>
                <div class="db-panel__body">

                    <div class="db-form-group">
                        <label class="db-form-label" for="subject">Subject <span style="color:var(--db-rose)">*</span></label>
                        <input type="text" class="db-form-control" id="subject" name="subject"
                               placeholder="Brief description of your complaint" required maxlength="255">
                        <div style="font-size:11px;color:var(--db-muted);margin-top:5px;">
                            <i class="fas fa-info-circle"></i> Keep it short and descriptive
                        </div>
                    </div>

                    <div class="db-form-row--2">
                        <div class="db-form-group">
                            <label class="db-form-label" for="category">Category <span style="color:var(--db-rose)">*</span></label>
                            <select class="db-form-select" id="category" name="category" required>
                                <option value="">Select category</option>
                                <option value="Noise">Noise Disturbance</option>
                                <option value="Garbage">Garbage / Waste Management</option>
                                <option value="Property">Property Dispute</option>
                                <option value="Infrastructure">Infrastructure / Road Issues</option>
                                <option value="Public Safety">Public Safety Concern</option>
                                <option value="Services">Barangay Services</option>
                                <option value="Utilities">Utilities (Water / Electric)</option>
                                <option value="Animals">Stray Animals</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="db-form-group">
                            <label class="db-form-label" for="priority">Priority Level <span style="color:var(--db-rose)">*</span></label>
                            <select class="db-form-select" id="priority" name="priority" required>
                                <option value="Low">Low — Not urgent</option>
                                <option value="Medium" selected>Medium — Normal concern</option>
                                <option value="High">High — Needs attention soon</option>
                                <option value="Urgent">Urgent — Immediate attention</option>
                            </select>
                            <div id="priorityPill" style="margin-top:8px;display:none;">
                                <span id="priorityBadge" class="db-badge"></span>
                            </div>
                        </div>
                    </div>

                    <div class="db-form-group">
                        <label class="db-form-label" for="description">Detailed Description <span style="color:var(--db-rose)">*</span></label>
                        <textarea class="db-form-textarea" id="description" name="description"
                                  placeholder="Please provide as much detail as possible… What happened? When? Where? Who was involved?" required></textarea>
                    </div>

                    <div class="db-form-group">
                        <label class="db-form-label" for="location">Location / Address</label>
                        <input type="text" class="db-form-control" id="location" name="location"
                               placeholder="Specific location where the issue occurred"
                               value="<?php echo htmlspecialchars($resident_info['address']??''); ?>">
                    </div>

                </div>
            </div>

            <!-- Attachments Panel -->
            <div class="db-panel" style="animation-delay:.1s">
                <div class="db-panel__header">
                    <div class="db-panel__title">
                        <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-paperclip"></i></div>
                        <h2>Attachments</h2>
                        <span class="db-badge db-badge--muted">Optional</span>
                    </div>
                    <span id="attachCountBadge" style="display:none;" class="db-badge db-badge--sky"></span>
                </div>
                <div class="db-panel__body">

                    <label for="attachments" class="db-upload-zone" id="uploadZone">
                        <div class="db-upload-zone__icon"><i class="fas fa-cloud-upload-alt"></i></div>
                        <div class="db-upload-zone__text" id="uploadText">Click to upload or drag files here</div>
                        <div class="db-upload-zone__hint">Images, PDF, DOC — Max 5 MB each · Up to 5 files</div>
                    </label>
                    <input type="file" id="attachments" name="attachments[]"
                           accept="image/*,.pdf,.doc,.docx" multiple style="display:none;">

                    <!-- Live preview grid -->
                    <div id="previewGrid" class="db-img-preview-grid" style="display:none;"></div>

                </div>
            </div>

            <!-- Terms + Submit -->
            <div class="db-panel" style="animation-delay:.15s">
                <div class="db-panel__body">

                    <div style="display:flex;align-items:flex-start;gap:10px;padding:14px;background:var(--db-surf2);border:1px solid var(--db-border);border-radius:var(--db-radius-sm);margin-bottom:18px;">
                        <input type="checkbox" id="terms" name="terms" required
                               style="width:16px;height:16px;margin-top:2px;accent-color:var(--db-navy);flex-shrink:0;cursor:pointer;">
                        <label for="terms" style="font-size:12.5px;color:var(--db-text);cursor:pointer;line-height:1.5;">
                            I certify that the information provided is true and accurate to the best of my knowledge.
                        </label>
                    </div>

                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <button type="submit" class="db-btn db-btn--danger" id="submitBtn">
                            <i class="fas fa-paper-plane"></i> Submit Complaint
                        </button>
                        <a href="view-complaints.php" class="db-btn db-btn--ghost">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>

                </div>
            </div>

        </div><!-- /left -->

        <!-- ══ RIGHT COLUMN ══ -->
        <div>

            <!-- Your Information -->
            <div class="db-side-card" style="animation-delay:.07s">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--navy" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-user"></i></div>
                    <span>Your Information</span>
                    <span class="db-badge db-badge--muted" style="margin-left:auto;"><i class="fas fa-lock"></i> Auto-filled</span>
                </div>
                <div class="db-side-card__body" style="display:flex;flex-direction:column;gap:12px;">
                    <?php
                    $fields = [
                        ['Name',    $resident_info['first_name'].' '.$resident_info['last_name']],
                        ['Contact', $resident_info['contact_number']??''],
                        ['Email',   $resident_info['email']??''],
                        ['Address', $resident_info['address']??''],
                    ];
                    foreach ($fields as [$lbl,$val]):
                    ?>
                    <div>
                        <div style="font-size:10px;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;font-weight:600;margin-bottom:3px;font-family:'DM Mono',monospace;"><?php echo $lbl; ?></div>
                        <div style="font-size:13px;font-weight:<?php echo $lbl==='Address'?'400':'600'; ?>;color:<?php echo $lbl==='Address'?'var(--db-muted)':'var(--db-text)'; ?>;"><?php echo htmlspecialchars($val); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Important Notes -->
            <div class="db-side-card" style="animation-delay:.1s">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--amber" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-lightbulb"></i></div>
                    <span>Important Notes</span>
                </div>
                <div class="db-side-card__body" style="font-size:12px;color:var(--db-muted);line-height:1.7;">
                    <p style="margin:0 0 5px;">• Provide accurate and honest information</p>
                    <p style="margin:0 0 5px;">• You will receive a complaint number for tracking</p>
                    <p style="margin:0 0 5px;">• Response time depends on priority level</p>
                    <p style="margin:0 0 5px;">• You can track your complaint status online</p>
                    <p style="margin:0;">• For emergencies, call the barangay hotline</p>
                </div>
            </div>

            <!-- Priority Guide -->
            <div class="db-side-card" style="animation-delay:.13s">
                <div class="db-side-card__header">
                    <div class="db-panel__icon db-panel__icon--purple" style="width:28px;height:28px;font-size:12px;"><i class="fas fa-layer-group"></i></div>
                    <span>Priority Guide</span>
                </div>
                <div class="db-side-card__body" style="display:flex;flex-direction:column;gap:9px;">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="db-badge db-badge--success"><i class="fas fa-circle"></i> Low</span>
                        <span style="font-size:11.5px;color:var(--db-muted);">Not urgent, can wait</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="db-badge db-badge--amber"><i class="fas fa-circle"></i> Medium</span>
                        <span style="font-size:11.5px;color:var(--db-muted);">Normal concern</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="db-badge db-badge--rose"><i class="fas fa-exclamation-circle"></i> High</span>
                        <span style="font-size:11.5px;color:var(--db-muted);">Needs attention soon</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:8px;">
                        <span class="db-badge db-badge--danger"><i class="fas fa-fire"></i> Urgent</span>
                        <span style="font-size:11.5px;color:var(--db-muted);">Immediate attention</span>
                    </div>
                </div>
            </div>

        </div><!-- /right -->

    </div><!-- /grid -->
</form>

</div><!-- /padding wrapper -->

<!-- Lightbox for previewing images before upload -->
<div id="db-lightbox" onclick="dbLightboxClose()">
    <span id="db-lightbox-close" onclick="dbLightboxClose()">&times;</span>
    <img id="db-lightbox-img" src="" alt="" onclick="event.stopPropagation()">
</div>

<script>
/* ── Priority badge ── */
const priorityMap = {
    Low:    { cls:'db-badge--success', icon:'fa-circle',             label:'Low Priority' },
    Medium: { cls:'db-badge--amber',   icon:'fa-exclamation-circle', label:'Medium Priority' },
    High:   { cls:'db-badge--rose',    icon:'fa-exclamation-triangle',label:'High Priority' },
    Urgent: { cls:'db-badge--danger',  icon:'fa-fire',               label:'Urgent' },
};
document.getElementById('priority').addEventListener('change', function () {
    const pill  = document.getElementById('priorityPill');
    const badge = document.getElementById('priorityBadge');
    const cfg   = priorityMap[this.value];
    if (!cfg) { pill.style.display='none'; return; }
    badge.className = 'db-badge ' + cfg.cls;
    badge.innerHTML = `<i class="fas ${cfg.icon}"></i> ${cfg.label}`;
    pill.style.display = 'block';
});
document.getElementById('priority').dispatchEvent(new Event('change'));

/* ── File handling + live preview ── */
let selectedFiles = [];
let _handling = false; // debounce guard — prevents double-fire from label+input

const zone = document.getElementById('uploadZone');
const inp  = document.getElementById('attachments');

// Drag-and-drop
['dragenter','dragover'].forEach(e => zone.addEventListener(e, ev => { ev.preventDefault(); zone.classList.add('has-file'); }));
['dragleave','drop'].forEach(e  => zone.addEventListener(e, ev => { ev.preventDefault(); if(e==='dragleave') zone.classList.remove('has-file'); }));
zone.addEventListener('drop', ev => {
    ev.preventDefault();
    zone.classList.remove('has-file');
    if (!ev.dataTransfer.files.length) return;
    try {
        const dt = new DataTransfer();
        [...ev.dataTransfer.files].forEach(f => dt.items.add(f));
        inp.files = dt.files;
    } catch(e) {}
    processFiles([...inp.files]);
});

// Input change — the ONLY place we read inp.files
inp.addEventListener('change', function() {
    if (_handling) return;
    _handling = true;
    setTimeout(() => { _handling = false; }, 300);
    processFiles([...this.files]);
});

function processFiles(files) {
    if (files.length === 0) return;

    // Deduplicate against already-selected files by name+size
    const existing = new Set(selectedFiles.map(f => f.name + f.size));
    const incoming = files.filter(f => !existing.has(f.name + f.size));

    const merged = [...selectedFiles, ...incoming];

    if (merged.length > 5) {
        alert('Maximum 5 files allowed. Only the first 5 will be kept.');
        merged.splice(5);
    }

    for (let f of merged) {
        if (f.size > 5 * 1024 * 1024) {
            alert(`"${f.name}" exceeds the 5 MB limit and was skipped.`);
            merged.splice(merged.indexOf(f), 1);
        }
    }

    selectedFiles = merged;
    syncInputFiles();
    renderPreviews();
}

function removeFile(idx) {
    selectedFiles.splice(idx, 1);
    syncInputFiles();
    renderPreviews();
}

function syncInputFiles() {
    const text = document.getElementById('uploadText');
    const cnt  = document.getElementById('attachCountBadge');

    if (selectedFiles.length === 0) {
        inp.value = '';
        zone.classList.remove('has-file');
        text.innerHTML = 'Click to upload or drag files here';
        cnt.style.display = 'none';
        return;
    }

    try {
        const dt = new DataTransfer();
        selectedFiles.forEach(f => dt.items.add(f));
        inp.files = dt.files;
    } catch(e) {}

    zone.classList.add('has-file');
    const n = selectedFiles.length;
    text.innerHTML = `<i class="fas fa-check-circle" style="color:var(--db-success);"></i> ${n} file${n>1?'s':''} selected`;
    cnt.innerHTML  = `<i class="fas fa-paperclip"></i> ${n}`;
    cnt.style.display = 'inline-flex';
}

function renderPreviews() {
    const grid = document.getElementById('previewGrid');
    if (!selectedFiles.length) { grid.style.display='none'; grid.innerHTML=''; return; }

    grid.style.display = 'grid';
    grid.innerHTML = '';

    selectedFiles.forEach((file, i) => {
        const isImg   = file.type.startsWith('image/');
        const ext     = file.name.split('.').pop().toLowerCase();
        const iconMap = { pdf:'fa-file-pdf', doc:'fa-file-word', docx:'fa-file-word' };
        const fi      = iconMap[ext] ?? 'fa-file';
        const safeName = file.name.replace(/'/g, "\\'");

        const card = document.createElement('div');
        card.className = 'db-img-preview-card';
        card.dataset.idx = i;

        // Build foot HTML (same for both image and file)
        const foot = `<div class="db-img-preview-card__foot">
            <span class="db-img-preview-card__name" title="${safeName}">${file.name}</span>
            <button type="button" class="db-img-preview-card__remove" data-idx="${i}" title="Remove">
                <i class="fas fa-times"></i>
            </button>
        </div>`;

        if (isImg) {
            // Placeholder while loading
            card.innerHTML = `<div class="db-img-preview-card__thumb db-img-loading" style="display:flex;align-items:center;justify-content:center;">
                <i class="fas fa-spinner fa-spin" style="color:var(--db-muted);font-size:1.5rem;"></i>
            </div>${foot}`;

            const reader = new FileReader();
            const capturedIdx = i; // capture index at read time
            reader.onload = ev => {
                const src = ev.target.result;
                const thumb = card.querySelector('.db-img-preview-card__thumb');
                if (thumb) {
                    thumb.classList.remove('db-img-loading');
                    thumb.style.cursor = 'pointer';
                    thumb.innerHTML = `<img src="${src}" alt="${safeName}" style="width:100%;height:100%;object-fit:cover;">`;
                    thumb.onclick = () => dbLightbox(src);
                }
            };
            reader.readAsDataURL(file);
        } else {
            card.innerHTML = `<div class="db-img-preview-card__thumb">
                <i class="fas ${fi} file-icon"></i>
            </div>${foot}`;
        }

        grid.appendChild(card);
    });

    // Single delegated listener for remove buttons — avoids stale onclick closures
    grid.querySelectorAll('.db-img-preview-card__remove').forEach(btn => {
        btn.addEventListener('click', function() {
            removeFile(parseInt(this.dataset.idx));
        });
    });
}

/* ── Lightbox ── */
function dbLightbox(src) {
    document.getElementById('db-lightbox-img').src = src;
    document.getElementById('db-lightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function dbLightboxClose() {
    document.getElementById('db-lightbox').classList.remove('active');
    document.getElementById('db-lightbox-img').src = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key==='Escape') dbLightboxClose(); });

/* ── Submit loading state ── */
document.getElementById('complaintForm').addEventListener('submit', function (e) {
    if (!this.checkValidity()) { e.preventDefault(); this.reportValidity(); return; }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting…';
});

/* ── Auto-dismiss alerts ── */
setTimeout(() => {
    document.querySelectorAll('.db-alert').forEach(a => {
        a.style.transition='opacity .4s'; a.style.opacity='0';
        setTimeout(()=>a.remove(),400);
    });
}, 5000);
</script>

<?php include '../../includes/footer.php'; ?>