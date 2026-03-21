<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Create New Poll';

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('MIN_POLL_OPTIONS', 2);
define('MAX_POLL_OPTIONS', 20);
define('MAX_QUESTION_LENGTH', 500);
define('MAX_DESCRIPTION_LENGTH', 1000);
define('MAX_OPTION_LENGTH', 200);

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$user_id = $_SESSION['user_id'];
$resident_query = $conn->prepare("SELECT resident_id FROM tbl_users WHERE user_id = ?");

if (!$resident_query) {
    error_log("Database error: Failed to prepare resident query - " . $conn->error);
    $_SESSION['error_message'] = "System error. Please try again later.";
    header("Location: polls-manage.php");
    exit();
}

$resident_query->bind_param("i", $user_id);
$resident_query->execute();
$resident_result = $resident_query->get_result();

if ($resident_result->num_rows > 0) {
    $row = $resident_result->fetch_assoc();
    $creator_resident_id = $row['resident_id'];
    if (is_null($creator_resident_id)) {
        $_SESSION['error_message'] = "Your account is not linked to a resident profile.";
        header("Location: polls-manage.php");
        exit();
    }
} else {
    $_SESSION['error_message'] = "User not found. Please contact administrator.";
    header("Location: polls-manage.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $debug_info = [];

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error_message'] = "Invalid request. Please try again.";
        header("Location: polls-create.php");
        exit();
    }

    if (empty($errors)) {
        $question     = trim($_POST['question']);
        $description  = trim($_POST['description']);
        $status       = $_POST['status'];
        $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        $show_results = $_POST['show_results'];
        $end_date     = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $options      = array_filter($_POST['options'], fn($opt) => !empty(trim($opt)));

        if (empty($question))                            $errors[] = "Poll question is required.";
        elseif (strlen($question) > MAX_QUESTION_LENGTH) $errors[] = "Question must be " . MAX_QUESTION_LENGTH . " characters or less.";

        if (!empty($description) && strlen($description) > MAX_DESCRIPTION_LENGTH)
            $errors[] = "Description must be " . MAX_DESCRIPTION_LENGTH . " characters or less.";

        if (!in_array($status, ['draft','active','closed']))      $errors[] = "Invalid status selected.";
        if (!in_array($show_results, ['after_vote','always','never'])) $errors[] = "Invalid 'show results' option.";

        if (count($options) < MIN_POLL_OPTIONS) $errors[] = "At least " . MIN_POLL_OPTIONS . " options are required.";
        if (count($options) > MAX_POLL_OPTIONS) $errors[] = "Maximum " . MAX_POLL_OPTIONS . " options allowed.";

        foreach ($options as $option)
            if (strlen($option) > MAX_OPTION_LENGTH) { $errors[] = "Each option must be " . MAX_OPTION_LENGTH . " characters or less."; break; }

        $trimmed_options = array_map('trim', $options);
        if (count($trimmed_options) !== count(array_unique($trimmed_options)))
            $errors[] = "Duplicate options are not allowed.";

        if ($end_date) {
            try {
                $end_datetime = new DateTime($end_date);
                if ($end_datetime <= new DateTime()) $errors[] = "End date and time must be in the future.";
            } catch (Exception $e) { $errors[] = "Invalid date format."; }
        }
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            $poll_id_query = $conn->query("SELECT COALESCE(MAX(poll_id), 0) + 1 as next_id FROM tbl_polls");
            if (!$poll_id_query) throw new Exception("Failed to get next poll_id: " . $conn->error);
            $poll_id = max(1, intval($poll_id_query->fetch_assoc()['next_id']));

            $stmt = $conn->prepare("INSERT INTO tbl_polls (poll_id, question, description, created_by, status, allow_multiple, show_results, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) throw new Exception("Failed to prepare poll insert: " . $conn->error);

            $stmt->bind_param("issisiss", $poll_id, $question, $description, $creator_resident_id, $status, $allow_multiple, $show_results, $end_date);
            if (!$stmt->execute()) throw new Exception("Failed to execute poll insert: " . $stmt->error);
            $stmt->close();

            $conn->query("ALTER TABLE tbl_polls AUTO_INCREMENT = " . ($poll_id + 1));

            foreach ($options as $index => $option_text) {
                $option_text = trim($option_text);
                $order = $index + 1;
                $option_stmt = $conn->prepare("INSERT INTO tbl_poll_options (poll_id, option_text, option_order) VALUES (?, ?, ?)");
                if (!$option_stmt) throw new Exception("Failed to prepare option insert: " . $conn->error);
                $option_stmt->bind_param("isi", $poll_id, $option_text, $order);
                if (!$option_stmt->execute()) throw new Exception("Failed to insert option $order: " . $option_stmt->error);
                $option_stmt->close();
            }

            $conn->commit();
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['success_message'] = "Poll created successfully!";
            header("Location: polls-manage.php");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $errors[] = "Error: " . $e->getMessage();
        }
    }
}

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
.rm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#065f46,var(--db-teal));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(13,148,136,.4);flex-shrink:0;}
.rm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.rm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}

/* Panel */
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__header{display:flex;align-items:center;justify-content:space-between;padding:18px 22px;border-bottom:1px solid var(--db-border);gap:10px;flex-wrap:wrap;}
.db-panel__title{display:flex;align-items:center;gap:10px;}
.db-panel__title h2{font-size:15px;font-weight:700;margin:0;}
.db-panel__icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:14px;}
.db-panel__icon--teal{background:var(--db-teal-light);color:var(--db-teal);}
.db-panel__icon--amber{background:var(--db-amber-light);color:var(--db-amber-dark);}
.db-panel__icon--navy{background:var(--db-indigo-light);color:var(--db-navy-light);}
.db-panel__body{padding:24px 22px;}

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

/* Form fields */
.db-field{margin-bottom:18px;}
.db-field label{display:block;font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;}
.db-field .required{color:var(--db-rose);margin-left:2px;}
.db-field input[type="text"],
.db-field input[type="datetime-local"],
.db-field textarea,
.db-field select{
    width:100%;padding:10px 14px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);
    font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);
    outline:none;transition:border-color .18s,box-shadow .18s;appearance:none;
}
.db-field textarea{min-height:100px;resize:vertical;}
.db-field input:focus,.db-field textarea:focus,.db-field select:focus{
    border-color:var(--db-teal);box-shadow:0 0 0 3px rgba(13,148,136,.12);
}
.db-field .db-help{font-size:11px;color:var(--db-muted);margin-top:5px;}

.db-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}

/* Options section */
.options-list{display:flex;flex-direction:column;gap:10px;margin-bottom:14px;}
.option-row{display:flex;align-items:center;gap:10px;}
.option-row input[type="text"]{
    flex:1;padding:10px 14px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);
    font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);
    outline:none;transition:border-color .18s,box-shadow .18s;
}
.option-row input:focus{border-color:var(--db-teal);box-shadow:0 0 0 3px rgba(13,148,136,.12);}
.option-drag{cursor:grab;color:var(--db-muted);font-size:14px;padding:4px;}
.option-num{font-family:'DM Mono',monospace;font-size:11px;font-weight:600;color:var(--db-muted);min-width:22px;text-align:center;}
.option-remove{background:var(--db-rose-light);color:var(--db-rose);border:none;border-radius:7px;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:14px;transition:all .15s;flex-shrink:0;}
.option-remove:hover{background:var(--db-rose);color:#fff;}

/* Toggle checkbox */
.db-toggle-row{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;background:var(--db-surf2);border-radius:var(--db-radius-sm);border:1.5px solid var(--db-border);}
.db-toggle-row label{font-size:13px;font-weight:500;color:var(--db-text);cursor:pointer;display:flex;align-items:center;gap:8px;}
.db-toggle-row .db-toggle-desc{font-size:11px;color:var(--db-muted);font-weight:400;}
.db-switch{position:relative;display:inline-block;width:40px;height:22px;flex-shrink:0;}
.db-switch input{opacity:0;width:0;height:0;}
.db-switch-slider{position:absolute;inset:0;background:#cbd5e1;border-radius:22px;transition:.25s;cursor:pointer;}
.db-switch-slider:before{content:'';position:absolute;height:16px;width:16px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.25s;box-shadow:0 1px 4px rgba(0,0,0,.2);}
.db-switch input:checked+.db-switch-slider{background:var(--db-teal);}
.db-switch input:checked+.db-switch-slider:before{transform:translateX(18px);}

/* Alert */
.db-alert{padding:14px 18px;border-radius:var(--db-radius-sm);margin-bottom:20px;display:flex;gap:12px;align-items:flex-start;}
.db-alert--danger{background:var(--db-rose-light);color:#7f1d1d;border-left:4px solid var(--db-rose);}
.db-alert--danger ul{margin:6px 0 0 16px;padding:0;}
.db-alert--danger li{margin-bottom:3px;font-size:13px;}
.db-alert i{flex-shrink:0;margin-top:1px;}

/* Form footer */
.db-form-footer{display:flex;align-items:center;justify-content:flex-end;gap:10px;padding-top:20px;border-top:1px solid var(--db-border);margin-top:4px;}

/* Section divider */
.db-section-label{font-size:11px;font-weight:700;color:var(--db-muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.db-section-label::after{content:'';flex:1;height:1px;background:var(--db-border);}

/* Badge for option count */
.db-badge{display:inline-flex;align-items:center;gap:4px;padding:2px 9px;border-radius:20px;font-family:'DM Mono',monospace;font-size:10px;font-weight:500;letter-spacing:.3px;}
.db-badge--teal{background:var(--db-teal-light);color:var(--db-teal);}

/* Dark mode */
body.dark-mode{background:#0f172a !important;color:#e2e8f0 !important;}
body.dark-mode .db-panel{background:#1e293b !important;border-color:#334155 !important;}
body.dark-mode .db-panel__header{border-bottom-color:#334155 !important;}
body.dark-mode .db-field input,body.dark-mode .db-field textarea,body.dark-mode .db-field select,body.dark-mode .option-row input{background:#334155 !important;color:#e2e8f0 !important;border-color:#475569 !important;}
body.dark-mode .db-toggle-row{background:#0f172a !important;border-color:#334155 !important;}
body.dark-mode .db-btn--ghost{background:#1e293b !important;color:#e2e8f0 !important;border-color:#475569 !important;}

@media(max-width:640px){
    .db-grid-2{grid-template-columns:1fr;}
    .rm-hero{padding:20px 18px;}
    .rm-hero__title{font-size:17px;}
    .db-panel__body{padding:16px;}
    .db-form-footer{flex-direction:column-reverse;}
    .db-form-footer .db-btn{width:100%;justify-content:center;}
}
</style>

<!-- Hero -->
<div class="rm-hero">
    <div class="rm-hero__ring rm-hero__ring--1"></div>
    <div class="rm-hero__ring rm-hero__ring--2"></div>
    <div class="rm-hero__ring rm-hero__ring--3"></div>
    <div class="rm-hero__inner">
        <div class="rm-hero__left">
            <div class="rm-hero__icon"><i class="fas fa-plus"></i></div>
            <div>
                <div class="rm-hero__title">Create New Poll</div>
                <div class="rm-hero__sub">Set up a new community poll or survey</div>
            </div>
        </div>
        <a href="polls-manage.php" class="db-btn db-btn--ghost">
            <i class="fas fa-arrow-left"></i> Back to Polls
        </a>
    </div>
</div>

<div style="padding:0 24px 32px;">

    <?php if (!empty($errors)): ?>
        <div class="db-alert db-alert--danger">
            <i class="fas fa-exclamation-circle"></i>
            <div>
                <strong>Please fix the following errors:</strong>
                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="pollForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

        <!-- Poll Details -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--teal"><i class="fas fa-align-left"></i></div>
                    <h2>Poll Details</h2>
                </div>
            </div>
            <div class="db-panel__body">

                <div class="db-field">
                    <label>Poll Question <span class="required">*</span></label>
                    <input type="text"
                           name="question"
                           placeholder="e.g., What day works best for the community meeting?"
                           value="<?php echo isset($_POST['question']) ? htmlspecialchars($_POST['question']) : ''; ?>"
                           maxlength="<?php echo MAX_QUESTION_LENGTH; ?>"
                           required>
                    <div class="db-help">Keep it clear and concise (max <?php echo MAX_QUESTION_LENGTH; ?> characters)</div>
                </div>

                <div class="db-field">
                    <label>Description <span style="font-weight:400;text-transform:none;font-size:11px;color:var(--db-muted);">(optional)</span></label>
                    <textarea name="description"
                              placeholder="Add context or additional details about this poll…"
                              maxlength="<?php echo MAX_DESCRIPTION_LENGTH; ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                    <div class="db-help">Max <?php echo MAX_DESCRIPTION_LENGTH; ?> characters</div>
                </div>

            </div>
        </div>

        <!-- Poll Options -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--amber"><i class="fas fa-list-ul"></i></div>
                    <h2>Poll Options</h2>
                    <span class="db-badge db-badge--teal" id="optionCountBadge">2 / <?php echo MAX_POLL_OPTIONS; ?></span>
                </div>
            </div>
            <div class="db-panel__body">

                <div class="options-list" id="optionsContainer">
                    <?php
                    $saved_options = isset($_POST['options']) ? $_POST['options'] : ['', ''];
                    foreach ($saved_options as $index => $option):
                    ?>
                    <div class="option-row">
                        <span class="option-num"><?php echo $index + 1; ?></span>
                        <input type="text"
                               name="options[]"
                               placeholder="Option <?php echo $index + 1; ?>"
                               value="<?php echo htmlspecialchars($option); ?>"
                               maxlength="<?php echo MAX_OPTION_LENGTH; ?>"
                               <?php echo $index < 2 ? 'required' : ''; ?>>
                        <?php if ($index >= 2): ?>
                        <button type="button" class="option-remove" onclick="removeOption(this)" title="Remove option">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php else: ?>
                        <div style="width:30px;"></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="db-btn db-btn--ghost db-btn--sm" onclick="addOption()" id="addOptionBtn">
                    <i class="fas fa-plus"></i> Add Option
                </button>
                <div class="db-help" style="margin-top:8px;">Min <?php echo MIN_POLL_OPTIONS; ?> · Max <?php echo MAX_POLL_OPTIONS; ?> options · Each up to <?php echo MAX_OPTION_LENGTH; ?> characters</div>

            </div>
        </div>

        <!-- Settings -->
        <div class="db-panel">
            <div class="db-panel__header">
                <div class="db-panel__title">
                    <div class="db-panel__icon db-panel__icon--navy"><i class="fas fa-sliders-h"></i></div>
                    <h2>Poll Settings</h2>
                </div>
            </div>
            <div class="db-panel__body">

                <div class="db-grid-2">
                    <div class="db-field">
                        <label>Status <span class="required">*</span></label>
                        <select name="status" required>
                            <option value="draft"   <?php echo (!isset($_POST['status']) || $_POST['status']==='draft')   ? 'selected' : ''; ?>>Draft</option>
                            <option value="active"  <?php echo (isset($_POST['status']) && $_POST['status']==='active')   ? 'selected' : ''; ?>>Active</option>
                            <option value="closed"  <?php echo (isset($_POST['status']) && $_POST['status']==='closed')   ? 'selected' : ''; ?>>Closed</option>
                        </select>
                        <div class="db-help">Draft polls are not visible to residents</div>
                    </div>

                    <div class="db-field">
                        <label>End Date &amp; Time <span style="font-weight:400;text-transform:none;font-size:11px;color:var(--db-muted);">(optional)</span></label>
                        <input type="datetime-local"
                               name="end_date"
                               value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>">
                        <div class="db-help">Poll auto-closes at this time</div>
                    </div>
                </div>

                <div class="db-field">
                    <label>Show Results</label>
                    <select name="show_results" required>
                        <option value="after_vote" <?php echo (!isset($_POST['show_results']) || $_POST['show_results']==='after_vote') ? 'selected' : ''; ?>>After Voting</option>
                        <option value="always"     <?php echo (isset($_POST['show_results']) && $_POST['show_results']==='always')      ? 'selected' : ''; ?>>Always Show</option>
                        <option value="never"      <?php echo (isset($_POST['show_results']) && $_POST['show_results']==='never')       ? 'selected' : ''; ?>>Never Show</option>
                    </select>
                    <div class="db-help">Control when residents can see poll results</div>
                </div>

                <div class="db-toggle-row">
                    <label for="allow_multiple">
                        <i class="fas fa-check-double" style="color:var(--db-teal);"></i>
                        Allow Multiple Selections
                        <span class="db-toggle-desc">— residents can pick more than one option</span>
                    </label>
                    <label class="db-switch">
                        <input type="checkbox" name="allow_multiple" id="allow_multiple" value="1"
                               <?php echo (isset($_POST['allow_multiple']) && $_POST['allow_multiple']) ? 'checked' : ''; ?>>
                        <span class="db-switch-slider"></span>
                    </label>
                </div>

            </div>
        </div>

        <!-- Footer actions -->
        <div class="db-form-footer">
            <a href="polls-manage.php" class="db-btn db-btn--ghost">
                <i class="fas fa-times"></i> Cancel
            </a>
            <button type="submit" class="db-btn db-btn--success">
                <i class="fas fa-check"></i> Create Poll
            </button>
        </div>

    </form>
</div>

<script>
const MAX_OPTIONS = <?php echo MAX_POLL_OPTIONS; ?>;
const MIN_OPTIONS = <?php echo MIN_POLL_OPTIONS; ?>;

function getOptionRows() {
    return document.querySelectorAll('#optionsContainer .option-row');
}

function updateNumbers() {
    const rows = getOptionRows();
    rows.forEach((row, i) => {
        row.querySelector('.option-num').textContent = i + 1;
        const inp = row.querySelector('input[type="text"]');
        inp.placeholder = 'Option ' + (i + 1);
        if (i < 2) inp.setAttribute('required', '');
        else inp.removeAttribute('required');
    });
    document.getElementById('optionCountBadge').textContent = rows.length + ' / ' + MAX_OPTIONS;
    document.getElementById('addOptionBtn').disabled = rows.length >= MAX_OPTIONS;
}

function addOption() {
    if (getOptionRows().length >= MAX_OPTIONS) return;

    const row = document.createElement('div');
    row.className = 'option-row';
    row.innerHTML = `
        <span class="option-num"></span>
        <input type="text" name="options[]" maxlength="<?php echo MAX_OPTION_LENGTH; ?>">
        <button type="button" class="option-remove" onclick="removeOption(this)" title="Remove option">
            <i class="fas fa-times"></i>
        </button>`;
    document.getElementById('optionsContainer').appendChild(row);
    updateNumbers();
    row.querySelector('input').focus();
}

function removeOption(btn) {
    if (getOptionRows().length <= MIN_OPTIONS) {
        alert('A poll must have at least ' + MIN_OPTIONS + ' options.');
        return;
    }
    btn.closest('.option-row').remove();
    updateNumbers();
}

updateNumbers();

document.getElementById('pollForm').addEventListener('submit', function(e) {
    const filled = Array.from(document.querySelectorAll('input[name="options[]"]'))
                        .filter(i => i.value.trim() !== '');
    if (filled.length < MIN_OPTIONS) {
        e.preventDefault();
        alert('Please provide at least ' + MIN_OPTIONS + ' options.');
        return;
    }
    const texts = filled.map(i => i.value.trim().toLowerCase());
    if (texts.length !== new Set(texts).size) {
        e.preventDefault();
        alert('Duplicate options are not allowed.');
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>