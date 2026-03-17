<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Manage Announcements';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$where_conditions = [];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(title LIKE ? OR content LIKE ?)";
    $search_term = "%{$search}%";
    $params[] = $search_term;
    $params[] = $search_term;
    $types .= 'ss';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

$query = "
    SELECT *
    FROM tbl_announcements
    $where_clause
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$announcements = $stmt->get_result();

$stats_query = "
    SELECT 
        COUNT(*) as total_announcements,
        COUNT(CASE WHEN DATE(created_at) = CURDATE() THEN 1 END) as today_announcements,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as week_announcements,
        COUNT(CASE WHEN DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as month_announcements
    FROM tbl_announcements
";
$stats_result = $conn->query($stats_query);
$stats = $stats_result->fetch_assoc();

include '../../../includes/header.php';
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{
    --db-navy:#0d1b36;--db-navy-mid:#152849;--db-navy-light:#1c3461;
    --db-amber:#f59e0b;--db-amber-light:#fef3c7;--db-amber-dark:#b45309;
    --db-teal:#0d9488;--db-teal-light:#ccfbf1;
    --db-rose:#e11d48;--db-rose-light:#ffe4e6;
    --db-sky:#0ea5e9;--db-sky-light:#e0f2fe;
    --db-indigo:#6366f1;--db-indigo-light:#e0e7ff;
    --db-success:#10b981;--db-success-light:#d1fae5;
    --db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;
    --db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;
    --db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;
    --db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);
    --db-shadow-lg:0 8px 40px rgba(13,27,54,.14),0 2px 8px rgba(13,27,54,.06);
}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}

/* Hero */
.rm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#224090 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.rm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.rm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.rm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(245,158,11,.12);}
.rm-hero__ring--3{width:100px;height:100px;bottom:-40px;left:40%;border-color:rgba(13,148,136,.14);}
.rm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.rm-hero__left{display:flex;align-items:center;gap:16px;}
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(245,158,11,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* Stats */
.db-stats-row{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.db-stat-card{flex:1 1 140px;background:var(--db-surf);border-radius:var(--db-radius);padding:16px 14px 12px;display:flex;flex-direction:column;gap:9px;box-shadow:var(--db-shadow);border:1px solid var(--db-border);transition:transform .2s,box-shadow .2s;}
.db-stat-card:hover{transform:translateY(-3px);box-shadow:var(--db-shadow-lg);}
.db-stat-card__icon{width:38px;height:38px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:15px;}
.db-stat-card__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-stat-card__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-stat-card__icon--sky{background:var(--db-sky-light);color:var(--db-sky);}
.db-stat-card__icon--indigo{background:var(--db-indigo-light);color:var(--db-indigo);}
.db-stat-card__num{font-size:26px;font-weight:800;line-height:1;letter-spacing:-1px;}
.db-stat-card__label{font-size:10px;color:var(--db-muted);font-weight:500;text-transform:uppercase;letter-spacing:.5px;}
.db-stat-card__bar{height:3px;border-radius:2px;opacity:.4;}
.db-stat-card__bar--amber{background:linear-gradient(90deg,var(--db-amber),transparent);}
.db-stat-card__bar--teal{background:linear-gradient(90deg,var(--db-teal),transparent);}
.db-stat-card__bar--sky{background:linear-gradient(90deg,var(--db-sky),transparent);}
.db-stat-card__bar--indigo{background:linear-gradient(90deg,var(--db-indigo),transparent);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__body{padding:20px 22px;}

/* Buttons */
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--sm{padding:6px 12px;font-size:12px;}
.db-btn--primary{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));color:#fff;}
.db-btn--primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(13,27,54,.25);color:#fff;}
.db-btn--success{background:linear-gradient(135deg,#065f46,var(--db-success));color:#fff;}
.db-btn--success:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(16,185,129,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-btn--danger{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));color:#fff;}
.db-btn--danger:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--warning{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));color:#fff;}
.db-btn--warning:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(245,158,11,.35);color:#fff;}

/* Form */
.db-form-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;}
.db-input,.db-select{padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-input:focus,.db-select:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-filter-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;display:block;}

/* Badges */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;white-space:nowrap;}
.db-badge--amber{background:var(--db-amber-light);color:#92400e;}
.db-badge--teal{background:var(--db-teal-light);color:#0f766e;}

/* Announcements List */
.ann-list{display:flex;flex-direction:column;gap:0;}
.ann-item{padding:20px 22px;border-bottom:1px solid var(--db-border);transition:background .12s;}
.ann-item:last-child{border-bottom:none;}
.ann-item:hover{background:var(--db-surf2);}
.ann-header{display:flex;align-items:center;gap:12px;margin-bottom:12px;}
.ann-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));display:flex;align-items:center;justify-content:center;font-size:15px;color:rgba(255,255,255,.8);flex-shrink:0;}
.ann-meta{flex:1;}
.ann-author{font-weight:600;font-size:13px;color:var(--db-text);}
.ann-date{font-size:11px;color:var(--db-muted);font-family:'DM Mono',monospace;}
.ann-title{font-size:15px;font-weight:700;color:var(--db-text);margin-bottom:6px;line-height:1.4;}
.ann-content{color:var(--db-muted);line-height:1.7;font-size:13px;margin-bottom:14px;}
.ann-footer{display:flex;align-items:center;justify-content:flex-end;gap:8px;}
.ann-id{font-family:'DM Mono',monospace;font-size:10px;color:var(--db-indigo);font-weight:500;margin-right:auto;}

/* Empty state */
.db-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:56px 24px;text-align:center;gap:12px;}
.db-empty i{font-size:44px;color:var(--db-border);}
.db-empty p{font-size:14px;color:var(--db-muted);}

/* Alert */
.db-alert{display:flex;align-items:center;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid;}
.db-alert--success{background:var(--db-success-light);color:#065f46;border-color:var(--db-success);}
.db-alert--error{background:#fee2e2;color:#7f1d1d;border-color:var(--db-rose);}
.db-alert__close{margin-left:auto;background:none;border:none;cursor:pointer;font-size:18px;opacity:.6;}

/* Modal */
.db-modal{display:none;position:fixed;inset:0;background:rgba(13,27,54,.55);backdrop-filter:blur(5px);z-index:9999;align-items:center;justify-content:center;padding:20px;}
.db-modal--open{display:flex;}
.db-modal__box{background:var(--db-surf);border-radius:var(--db-radius-lg);width:100%;max-width:560px;max-height:92vh;overflow-y:auto;box-shadow:var(--db-shadow-lg);animation:dbModalIn .28s cubic-bezier(.34,1.56,.64,1);}
@keyframes dbModalIn{from{opacity:0;transform:scale(.9) translateY(16px)}to{opacity:1;transform:scale(1) translateY(0)}}
.db-modal__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-radius:var(--db-radius-lg) var(--db-radius-lg) 0 0;}
.db-modal__header--teal{background:linear-gradient(135deg,#065f46,var(--db-teal));}
.db-modal__header--amber{background:linear-gradient(135deg,var(--db-amber-dark),var(--db-amber));}
.db-modal__header--rose{background:linear-gradient(135deg,#7f1d1d,var(--db-rose));}
.db-modal__header--navy{background:linear-gradient(135deg,var(--db-navy),var(--db-navy-light));}
.db-modal__header h3{color:#fff;font-size:15px;font-weight:700;display:flex;align-items:center;gap:8px;}
.db-modal__close{background:rgba(255,255,255,.15);border:none;color:rgba(255,255,255,.85);width:30px;height:30px;border-radius:7px;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;transition:background .15s;}
.db-modal__close:hover{background:rgba(255,255,255,.28);color:#fff;}
.db-modal__body{padding:22px;}
.db-modal__footer{display:flex;gap:10px;margin-top:18px;}
.db-modal__footer .db-btn{flex:1;justify-content:center;}

.db-field{margin-bottom:16px;}
.db-field label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;}
.db-field input,.db-field textarea{width:100%;padding:9px 13px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;}
.db-field textarea{min-height:140px;resize:vertical;}
.db-field input:focus,.db-field textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-detail-row{margin-bottom:14px;}
.db-detail-label{font-size:10px;font-weight:600;text-transform:uppercase;color:var(--db-muted);letter-spacing:.5px;margin-bottom:4px;}
.db-detail-value{font-size:13.5px;color:var(--db-text);line-height:1.6;}
.db-detail-value.large{font-size:15px;font-weight:700;}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel,body.dark-mode .db-modal__box{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-stat-card{background:#1e293b !important;border-color:#334155 !important;color:#e2e8f0 !important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-field input,body.dark-mode .db-field textarea{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .ann-item:hover{background:#1e293b !important;}
body.dark-mode .ann-title{color:#f1f5f9 !important;}
body.dark-mode .ann-content{color:#94a3b8 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-detail-value{color:#e2e8f0 !important;}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-bullhorn"></i></div>
            <div>
                <div class="rm-hero__title">Manage Announcements</div>
                <div class="rm-hero__sub">Create, edit, and manage community announcements</div>
            </div>
        </div>
        <a href="create-announcement.php" class="db-btn db-btn--success">
            <i class="fas fa-plus"></i> New Announcement
        </a>
    </div>
</div>

<div style="padding:0 24px 24px;">

<!-- Stats -->
<div class="db-stats-row">
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--amber"><i class="fas fa-bullhorn"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['total_announcements']); ?></div><div class="db-stat-card__label">Total</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--amber"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--teal"><i class="fas fa-calendar-day"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['today_announcements']); ?></div><div class="db-stat-card__label">Posted Today</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--teal"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--sky"><i class="fas fa-calendar-week"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['week_announcements']); ?></div><div class="db-stat-card__label">This Week</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--sky"></div>
    </div>
    <div class="db-stat-card">
        <div class="db-stat-card__icon db-stat-card__icon--indigo"><i class="fas fa-calendar-alt"></i></div>
        <div><div class="db-stat-card__num"><?php echo number_format($stats['month_announcements']); ?></div><div class="db-stat-card__label">This Month</div></div>
        <div class="db-stat-card__bar db-stat-card__bar--indigo"></div>
    </div>
</div>

<!-- Search -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-search"></i></div>
            <h2>Search Announcements</h2>
        </div>
        <?php if ($search): ?>
            <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </div>
    <div class="db-panel__body">
        <form method="GET">
            <div class="db-form-row">
                <div style="flex:1;min-width:220px;">
                    <label class="db-filter-label">Search</label>
                    <input type="text" name="search" class="db-input" style="width:100%;" placeholder="Search by title or content…" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div style="padding-top:18px;">
                    <button type="submit" class="db-btn db-btn--primary"><i class="fas fa-search"></i> Search</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Announcements List -->
<div class="db-panel">
    <div class="db-panel__header">
        <div class="db-panel__title">
            <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list"></i></div>
            <h2>All Announcements</h2>
            <span class="db-badge db-badge--amber"><?php echo $announcements->num_rows; ?></span>
        </div>
        <span style="font-size:12px;color:var(--db-muted);"><i class="fas fa-info-circle"></i> Click actions to manage</span>
    </div>

    <div class="ann-list">
        <?php if ($announcements->num_rows > 0): ?>
            <?php while ($announcement = $announcements->fetch_assoc()):
                $announcement_id = isset($announcement['id']) ? $announcement['id'] : 0;
                $title = htmlspecialchars($announcement['title']);
                $content = htmlspecialchars($announcement['content']);
                $created_at = date('M d, Y \a\t h:i A', strtotime($announcement['created_at']));
            ?>
            <div class="ann-item">
                <div class="ann-header">
                    <div class="ann-avatar"><i class="fas fa-user"></i></div>
                    <div class="ann-meta">
                        <div class="ann-author">Admin</div>
                        <div class="ann-date"><?php echo $created_at; ?></div>
                    </div>
                </div>
                <div class="ann-title"><?php echo $title; ?></div>
                <div class="ann-content">
                    <?php
                    if (strlen($content) > 220) {
                        echo nl2br(substr($content, 0, 220)) . '…';
                    } else {
                        echo nl2br($content);
                    }
                    ?>
                </div>
                <div class="ann-footer">
                    <?php if ($announcement_id > 0): ?>
                        <span class="ann-id">#<?php echo str_pad($announcement_id, 5, '0', STR_PAD_LEFT); ?></span>
                        <button onclick="viewAnnouncement(<?php echo $announcement_id; ?>)" class="db-btn db-btn--ghost db-btn--sm">
                            <i class="fas fa-eye"></i> View
                        </button>
                        <button onclick="editAnnouncement(<?php echo $announcement_id; ?>)" class="db-btn db-btn--primary db-btn--sm">
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <button onclick="confirmDelete(<?php echo $announcement_id; ?>)" class="db-btn db-btn--danger db-btn--sm">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="db-empty">
                <i class="fas fa-bullhorn"></i>
                <p>No announcements found.</p>
                <?php if ($search): ?>
                    <a href="?" class="db-btn db-btn--ghost db-btn--sm"><i class="fas fa-times"></i> Clear Filter</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

</div><!-- /padding -->

<!-- View Modal -->
<div id="viewModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--navy">
            <h3><i class="fas fa-eye"></i> View Announcement</h3>
            <button class="db-modal__close" onclick="closeModal('viewModal')">×</button>
        </div>
        <div class="db-modal__body">
            <div class="db-detail-row">
                <div class="db-detail-label">Title</div>
                <div class="db-detail-value large" id="view-title"></div>
            </div>
            <div class="db-detail-row">
                <div class="db-detail-label">Content</div>
                <div class="db-detail-value" id="view-content" style="white-space:pre-wrap;"></div>
            </div>
            <div class="db-detail-row">
                <div class="db-detail-label">Posted On</div>
                <div class="db-detail-value" id="view-date" style="font-family:'DM Mono',monospace;font-size:12px;"></div>
            </div>
            <div class="db-modal__footer">
                <button onclick="closeModal('viewModal')" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="db-modal">
    <div class="db-modal__box">
        <div class="db-modal__header db-modal__header--teal">
            <h3><i class="fas fa-edit"></i> Edit Announcement</h3>
            <button class="db-modal__close" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="db-modal__body">
            <form id="editForm">
                <input type="hidden" id="edit-id" name="id">
                <div class="db-field">
                    <label>Title</label>
                    <input type="text" id="edit-title" name="title" required>
                </div>
                <div class="db-field">
                    <label>Content</label>
                    <textarea id="edit-content" name="content" required></textarea>
                </div>
            </form>
            <div class="db-modal__footer">
                <button onclick="closeModal('editModal')" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Cancel</button>
                <button onclick="saveAnnouncement()" class="db-btn db-btn--primary"><i class="fas fa-save"></i> Save Changes</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Modal -->
<div id="deleteModal" class="db-modal">
    <div class="db-modal__box" style="max-width:420px;">
        <div class="db-modal__header db-modal__header--rose">
            <h3><i class="fas fa-trash"></i> Delete Announcement</h3>
            <button class="db-modal__close" onclick="closeModal('deleteModal')">×</button>
        </div>
        <div class="db-modal__body">
            <p style="color:var(--db-muted);line-height:1.7;margin-bottom:4px;">Are you sure you want to delete this announcement? <strong style="color:var(--db-rose)">This action cannot be undone.</strong></p>
            <input type="hidden" id="delete-id">
            <div class="db-modal__footer">
                <button onclick="closeModal('deleteModal')" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Cancel</button>
                <button onclick="deleteAnnouncement()" class="db-btn db-btn--danger"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
function closeModal(id){ document.getElementById(id).classList.remove('db-modal--open'); document.body.style.overflow=''; }
function openModal(id){ document.getElementById(id).classList.add('db-modal--open'); document.body.style.overflow='hidden'; }
window.addEventListener('click', e => { if(e.target.classList.contains('db-modal')) closeModal(e.target.id); });
document.addEventListener('keydown', e => { if(e.key==='Escape') document.querySelectorAll('.db-modal--open').forEach(m=>closeModal(m.id)); });

function viewAnnouncement(id) {
    fetch(`actions/get-announcement.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('view-title').textContent = data.announcement.title || '';
                document.getElementById('view-content').textContent = data.announcement.content || '';
                document.getElementById('view-date').textContent = new Date(data.announcement.created_at).toLocaleString();
                openModal('viewModal');
            } else { alert('Error: ' + (data.message || 'Unknown error')); }
        })
        .catch(e => alert('Failed to load: ' + e.message));
}

function editAnnouncement(id) {
    fetch(`actions/get-announcement.php?id=${id}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('edit-id').value = id;
                document.getElementById('edit-title').value = data.announcement.title;
                document.getElementById('edit-content').value = data.announcement.content;
                openModal('editModal');
            } else { alert('Error: ' + data.message); }
        });
}

function saveAnnouncement() {
    const id = document.getElementById('edit-id').value;
    const title = document.getElementById('edit-title').value;
    const content = document.getElementById('edit-content').value;
    if (!title || !content) { alert('Please fill in all fields'); return; }
    fetch('actions/update-announcement.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id, title, content })
    }).then(r => r.json()).then(data => {
        if (data.success) { closeModal('editModal'); location.reload(); }
        else alert('Error: ' + data.message);
    });
}

function confirmDelete(id) {
    document.getElementById('delete-id').value = id;
    openModal('deleteModal');
}

function deleteAnnouncement() {
    const id = document.getElementById('delete-id').value;
    fetch('actions/delete-announcement.php', {
        method: 'POST', headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ id })
    }).then(r => r.json()).then(data => {
        if (data.success) { closeModal('deleteModal'); location.reload(); }
        else alert('Error: ' + data.message);
    });
}
</script>

<?php include '../../../includes/footer.php'; ?>