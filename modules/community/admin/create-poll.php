<?php
require_once '../../../config/config.php';
require_once '../../../includes/auth_functions.php';

requireLogin();
requireRole(['Admin', 'Staff', 'Super Admin']);

$page_title = 'Create New Poll';

// ENABLE DETAILED ERROR REPORTING FOR DEBUGGING
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Constants for validation
define('MIN_POLL_OPTIONS', 2);
define('MAX_POLL_OPTIONS', 20);
define('MAX_QUESTION_LENGTH', 500);
define('MAX_DESCRIPTION_LENGTH', 1000);
define('MAX_OPTION_LENGTH', 200);

// Generate CSRF token if not exists
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Get resident_id from user_id
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
    $creator_resident_id = $resident_result->fetch_assoc()['resident_id'];
} else {
    error_log("Critical: User ID $user_id has no associated resident_id");
    $_SESSION['error_message'] = "User not found. Please contact administrator.";
    header("Location: polls-manage.php");
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $errors = [];
    $debug_info = [];
    
    // CSRF token validation
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        error_log("CSRF token validation failed for user_id: $user_id");
        $_SESSION['error_message'] = "Invalid request. Please try again.";
        header("Location: polls-create.php");
        exit();
    }
    
    // Rate limiting check - prevent spam (DISABLED FOR TESTING)
    /*
    $rate_check = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM tbl_polls 
        WHERE created_by = ? 
        AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
    ");
    
    if ($rate_check) {
        $rate_check->bind_param("i", $creator_resident_id);
        $rate_check->execute();
        $rate_result = $rate_check->get_result();
        $rate_data = $rate_result->fetch_assoc();
        
        if ($rate_data['count'] > 0) {
            $errors[] = "Please wait a moment before creating another poll.";
        }
    }
    */
    
    if (empty($errors)) {
        // Sanitize and validate inputs
        $question = trim($_POST['question']);
        $description = trim($_POST['description']);
        $status = $_POST['status'];
        $allow_multiple = isset($_POST['allow_multiple']) ? 1 : 0;
        $show_results = $_POST['show_results'];
        $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
        $options = array_filter($_POST['options'], function($opt) {
            return !empty(trim($opt));
        });
        
        // Validation
        if (empty($question)) {
            $errors[] = "Poll question is required.";
        } elseif (strlen($question) > MAX_QUESTION_LENGTH) {
            $errors[] = "Question must be " . MAX_QUESTION_LENGTH . " characters or less.";
        }
        
        if (!empty($description) && strlen($description) > MAX_DESCRIPTION_LENGTH) {
            $errors[] = "Description must be " . MAX_DESCRIPTION_LENGTH . " characters or less.";
        }
        
        // Validate status
        $valid_statuses = ['draft', 'active', 'closed'];
        if (!in_array($status, $valid_statuses)) {
            $errors[] = "Invalid status selected.";
        }
        
        // Validate show_results
        $valid_show_results = ['after_vote', 'always', 'never'];
        if (!in_array($show_results, $valid_show_results)) {
            $errors[] = "Invalid 'show results' option selected.";
        }
        
        // Validate options
        if (count($options) < MIN_POLL_OPTIONS) {
            $errors[] = "At least " . MIN_POLL_OPTIONS . " options are required.";
        }
        
        if (count($options) > MAX_POLL_OPTIONS) {
            $errors[] = "Maximum " . MAX_POLL_OPTIONS . " options allowed.";
        }
        
        // Validate option lengths
        foreach ($options as $option) {
            if (strlen($option) > MAX_OPTION_LENGTH) {
                $errors[] = "Each option must be " . MAX_OPTION_LENGTH . " characters or less.";
                break;
            }
        }
        
        // Check for duplicate options
        $trimmed_options = array_map('trim', $options);
        if (count($trimmed_options) !== count(array_unique($trimmed_options))) {
            $errors[] = "Duplicate options are not allowed.";
        }
        
        // Validate end date is in the future
        if ($end_date) {
            try {
                $end_datetime = new DateTime($end_date);
                $now = new DateTime();
                if ($end_datetime <= $now) {
                    $errors[] = "End date and time must be in the future.";
                }
            } catch (Exception $e) {
                $errors[] = "Invalid date format.";
            }
        }
    }
    
    if (empty($errors)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            $debug_info[] = "Starting poll creation...";
            
            // Get next poll_id manually since AUTO_INCREMENT is broken
            $poll_id_query = $conn->query("SELECT COALESCE(MAX(poll_id), 0) + 1 as next_id FROM tbl_polls");
            if (!$poll_id_query) {
                throw new Exception("Failed to get next poll_id: " . $conn->error);
            }
            
            $poll_id_data = $poll_id_query->fetch_assoc();
            $poll_id = max(1, intval($poll_id_data['next_id'])); // Ensure it's at least 1
            $debug_info[] = "Calculated next poll_id: $poll_id";
            
            // Insert poll WITH explicit poll_id
            $stmt = $conn->prepare("INSERT INTO tbl_polls (poll_id, question, description, created_by, status, allow_multiple, show_results, end_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            
            if (!$stmt) {
                throw new Exception("Failed to prepare poll insert: " . $conn->error);
            }
            
            $debug_info[] = "Poll statement prepared";
            
            $stmt->bind_param("issisiss", $poll_id, $question, $description, $creator_resident_id, $status, $allow_multiple, $show_results, $end_date);
            
            if (!$stmt->execute()) {
                throw new Exception("Failed to execute poll insert: " . $stmt->error);
            }
            
            $debug_info[] = "Poll created with ID: $poll_id";
            $stmt->close();
            
            // Fix AUTO_INCREMENT for tbl_polls
            $new_auto_increment = $poll_id + 1;
            if ($conn->query("ALTER TABLE tbl_polls AUTO_INCREMENT = $new_auto_increment")) {
                $debug_info[] = "Fixed tbl_polls AUTO_INCREMENT to: $new_auto_increment";
            } else {
                $debug_info[] = "Warning: Could not fix AUTO_INCREMENT: " . $conn->error;
            }
            
            // Insert options
            foreach ($options as $index => $option_text) {
                $option_text = trim($option_text);
                $order = $index + 1;
                
                $debug_info[] = "Inserting option $order";
                
                $option_stmt = $conn->prepare("INSERT INTO tbl_poll_options (poll_id, option_text, option_order) VALUES (?, ?, ?)");
                
                if (!$option_stmt) {
                    throw new Exception("Failed to prepare option insert: " . $conn->error);
                }
                
                $option_stmt->bind_param("isi", $poll_id, $option_text, $order);
                
                if (!$option_stmt->execute()) {
                    throw new Exception("Failed to insert option $order: " . $option_stmt->error);
                }
                
                $debug_info[] = "Option $order inserted with ID: " . $option_stmt->insert_id;
                $option_stmt->close();
            }
            
            $debug_info[] = "All options inserted";
            
            $conn->commit();
            $debug_info[] = "Transaction committed successfully!";
            
            // Regenerate CSRF token after successful submission
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            $_SESSION['success_message'] = "Poll created successfully!";
            header("Location: polls-manage.php");
            exit();
            
        } catch (Exception $e) {
            $conn->rollback();
            $debug_info[] = "ROLLBACK: " . $e->getMessage();
            
            error_log("Poll creation error for user_id $user_id: " . $e->getMessage());
            error_log("Debug info: " . implode(" | ", $debug_info));
            
            // Show detailed error in development
            $errors[] = "Error: " . $e->getMessage();
            $errors[] = "Debug: " . implode(" → ", $debug_info);
        }
    }
}

include '../../../includes/header.php';
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f8f9fa;
        color: #1a1a1a;
    }
    
    .page-header {
        background: white;
        padding: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e5e7eb;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .breadcrumb {
        font-size: 0.875rem;
        color: #6b7280;
    }
    
    .breadcrumb a {
        color: #3b82f6;
        text-decoration: none;
    }
    
    .breadcrumb a:hover {
        text-decoration: underline;
    }
    
    .form-container {
        max-width: 800px;
        margin: 0 auto;
        background: white;
        border-radius: 8px;
        border: 1px solid #e5e7eb;
        padding: 2rem;
    }
    
    .alert {
        padding: 1rem;
        border-radius: 6px;
        margin-bottom: 1.5rem;
    }
    
    .alert-danger {
        background: #fee2e2;
        color: #991b1b;
        border: 1px solid #fecaca;
    }
    
    .alert-success {
        background: #d1fae5;
        color: #065f46;
        border: 1px solid #a7f3d0;
    }
    
    .form-group {
        margin-bottom: 1.5rem;
    }
    
    .form-group label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #374151;
    }
    
    .required {
        color: #ef4444;
    }
    
    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        background: white;
        transition: all 0.15s;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #3b82f6;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    }
    
    .form-control:disabled {
        background-color: #f3f4f6;
        cursor: not-allowed;
    }
    
    textarea.form-control {
        resize: vertical;
        min-height: 100px;
        font-family: inherit;
    }
    
    .form-check {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        margin-bottom: 0.75rem;
    }
    
    .form-check input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
    }
    
    .form-check label {
        margin: 0;
        cursor: pointer;
        font-weight: 400;
    }
    
    .options-section {
        background: #f9fafb;
        padding: 1.5rem;
        border-radius: 6px;
        margin-bottom: 1.5rem;
    }
    
    .options-section h3 {
        font-size: 1rem;
        font-weight: 600;
        margin-bottom: 1rem;
        color: #1a1a1a;
    }
    
    .option-item {
        display: flex;
        gap: 0.75rem;
        margin-bottom: 0.75rem;
        align-items: center;
    }
    
    .option-item input {
        flex: 1;
    }
    
    .btn {
        padding: 0.625rem 1.25rem;
        font-size: 0.875rem;
        font-weight: 500;
        border-radius: 6px;
        border: none;
        cursor: pointer;
        transition: all 0.15s;
        text-decoration: none;
        display: inline-block;
        text-align: center;
    }
    
    .btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }
    
    .btn-secondary {
        background: #6b7280;
        color: white;
    }
    
    .btn-secondary:hover:not(:disabled) {
        background: #4b5563;
    }
    
    .btn-success {
        background: #10b981;
        color: white;
    }
    
    .btn-success:hover:not(:disabled) {
        background: #059669;
    }
    
    .btn-danger {
        background: #ef4444;
        color: white;
        padding: 0.5rem 0.75rem;
    }
    
    .btn-danger:hover:not(:disabled) {
        background: #dc2626;
    }
    
    .btn-sm {
        padding: 0.5rem 1rem;
        font-size: 0.813rem;
    }
    
    .form-actions {
        display: flex;
        gap: 1rem;
        justify-content: flex-end;
        margin-top: 2rem;
        padding-top: 1.5rem;
        border-top: 1px solid #e5e7eb;
    }
    
    .help-text {
        font-size: 0.813rem;
        color: #6b7280;
        margin-top: 0.25rem;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .page-header {
            padding: 1.5rem;
        }
        
        .page-header h1 {
            font-size: 1.5rem;
        }
        
        .form-container {
            padding: 1.5rem;
            margin: 0 1rem;
        }
        
        .form-row {
            grid-template-columns: 1fr;
        }
        
        .form-actions {
            flex-direction: column-reverse;
        }
        
        .form-actions .btn {
            width: 100%;
        }
        
        .option-item {
            flex-wrap: wrap;
        }
        
        .option-item input {
            min-width: 100%;
        }
    }
</style>

<div class="page-header">
    <h1>Create New Poll</h1>
    <div class="breadcrumb">
        <a href="<?php echo $base_url; ?>/modules/dashboard/index.php">Dashboard</a> / 
        <a href="polls-manage.php">Polls Management</a> / 
        <span>Create Poll</span>
    </div>
</div>

<div class="container-fluid">
    <div class="form-container">
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger" role="alert">
                <strong>Error Details:</strong>
                <ul style="margin: 0.5rem 0 0 1.25rem;">
                    <?php foreach ($errors as $error): ?>
                        <li style="word-break: break-all;"><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
        
        <form method="POST" action="" id="pollForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            
            <div class="form-group">
                <label for="question">Poll Question <span class="required">*</span></label>
                <input 
                    type="text" 
                    id="question"
                    name="question" 
                    class="form-control" 
                    placeholder="Enter your poll question" 
                    value="<?php echo isset($_POST['question']) ? htmlspecialchars($_POST['question']) : ''; ?>" 
                    maxlength="<?php echo MAX_QUESTION_LENGTH; ?>"
                    required
                    aria-required="true">
                <div class="help-text">Keep it clear and concise (max <?php echo MAX_QUESTION_LENGTH; ?> characters)</div>
            </div>
            
            <div class="form-group">
                <label for="description">Description (Optional)</label>
                <textarea 
                    id="description"
                    name="description" 
                    class="form-control" 
                    placeholder="Add additional context or details about this poll"
                    maxlength="<?php echo MAX_DESCRIPTION_LENGTH; ?>"
                    aria-describedby="desc-help"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                <div class="help-text" id="desc-help">Max <?php echo MAX_DESCRIPTION_LENGTH; ?> characters</div>
            </div>
            
            <div class="options-section">
                <h3>Poll Options <span class="required">*</span></h3>
                <div id="options-container">
                    <?php
                    $saved_options = isset($_POST['options']) ? $_POST['options'] : ['', ''];
                    foreach ($saved_options as $index => $option):
                    ?>
                    <div class="option-item">
                        <input 
                            type="text" 
                            name="options[]" 
                            class="form-control" 
                            placeholder="Option <?php echo $index + 1; ?>" 
                            value="<?php echo htmlspecialchars($option); ?>"
                            maxlength="<?php echo MAX_OPTION_LENGTH; ?>"
                            <?php echo $index < 2 ? 'required' : ''; ?>>
                        <?php if ($index >= 2): ?>
                        <button type="button" class="btn btn-danger btn-sm" onclick="removeOption(this)" aria-label="Remove option">
                            <i class="fas fa-times"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" onclick="addOption()" id="addOptionBtn">
                    <i class="fas fa-plus"></i> Add Option
                </button>
                <div class="help-text">Min <?php echo MIN_POLL_OPTIONS; ?> options, max <?php echo MAX_POLL_OPTIONS; ?> options (max <?php echo MAX_OPTION_LENGTH; ?> characters each)</div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="status">Status <span class="required">*</span></label>
                    <select name="status" id="status" class="form-control" required aria-required="true">
                        <option value="draft" <?php echo (!isset($_POST['status']) || $_POST['status'] === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                        <option value="closed" <?php echo (isset($_POST['status']) && $_POST['status'] === 'closed') ? 'selected' : ''; ?>>Closed</option>
                    </select>
                    <div class="help-text">Draft polls won't be visible to residents</div>
                </div>
                
                <div class="form-group">
                    <label for="end_date">End Date & Time (Optional)</label>
                    <input 
                        type="datetime-local" 
                        id="end_date"
                        name="end_date" 
                        class="form-control" 
                        value="<?php echo isset($_POST['end_date']) ? htmlspecialchars($_POST['end_date']) : ''; ?>">
                    <div class="help-text">Poll will auto-close at this time</div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="show_results">Show Results</label>
                <select name="show_results" id="show_results" class="form-control" required aria-required="true">
                    <option value="after_vote" <?php echo (!isset($_POST['show_results']) || $_POST['show_results'] === 'after_vote') ? 'selected' : ''; ?>>After Voting</option>
                    <option value="always" <?php echo (isset($_POST['show_results']) && $_POST['show_results'] === 'always') ? 'selected' : ''; ?>>Always Show</option>
                    <option value="never" <?php echo (isset($_POST['show_results']) && $_POST['show_results'] === 'never') ? 'selected' : ''; ?>>Never Show</option>
                </select>
                <div class="help-text">Control when residents can see poll results</div>
            </div>
            
            <div class="form-group">
                <div class="form-check">
                    <input 
                        type="checkbox" 
                        name="allow_multiple" 
                        id="allow_multiple" 
                        value="1" 
                        <?php echo (isset($_POST['allow_multiple']) && $_POST['allow_multiple']) ? 'checked' : ''; ?>>
                    <label for="allow_multiple">Allow Multiple Selections</label>
                </div>
                <div class="help-text" style="margin-left: 1.75rem;">Enable this if residents can select more than one option</div>
            </div>
            
            <div class="form-actions">
                <a href="polls-manage.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-check"></i> Create Poll
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const MAX_OPTIONS = <?php echo MAX_POLL_OPTIONS; ?>;
const MIN_OPTIONS = <?php echo MIN_POLL_OPTIONS; ?>;
let optionCount = document.querySelectorAll('#options-container .option-item').length;

function addOption() {
    const container = document.getElementById('options-container');
    
    if (optionCount >= MAX_OPTIONS) {
        alert('Maximum ' + MAX_OPTIONS + ' options allowed.');
        return;
    }
    
    optionCount++;
    const optionItem = document.createElement('div');
    optionItem.className = 'option-item';
    
    const input = document.createElement('input');
    input.type = 'text';
    input.name = 'options[]';
    input.className = 'form-control';
    input.placeholder = 'Option ' + optionCount;
    input.maxLength = <?php echo MAX_OPTION_LENGTH; ?>;
    
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'btn btn-danger btn-sm';
    button.setAttribute('aria-label', 'Remove option');
    button.onclick = function() { removeOption(this); };
    button.innerHTML = '<i class="fas fa-times"></i>';
    
    optionItem.appendChild(input);
    optionItem.appendChild(button);
    container.appendChild(optionItem);
    
    updateAddButtonState();
}

function removeOption(button) {
    const container = document.getElementById('options-container');
    
    if (container.children.length > MIN_OPTIONS) {
        button.parentElement.remove();
        optionCount--;
        updateAddButtonState();
    } else {
        alert('A poll must have at least ' + MIN_OPTIONS + ' options.');
    }
}

function updateAddButtonState() {
    const addBtn = document.getElementById('addOptionBtn');
    if (optionCount >= MAX_OPTIONS) {
        addBtn.disabled = true;
        addBtn.style.opacity = '0.5';
        addBtn.style.cursor = 'not-allowed';
    } else {
        addBtn.disabled = false;
        addBtn.style.opacity = '1';
        addBtn.style.cursor = 'pointer';
    }
}

updateAddButtonState();

document.getElementById('pollForm').addEventListener('submit', function(e) {
    const options = document.querySelectorAll('input[name="options[]"]');
    const filledOptions = Array.from(options).filter(opt => opt.value.trim() !== '');
    
    if (filledOptions.length < MIN_OPTIONS) {
        e.preventDefault();
        alert('Please provide at least ' + MIN_OPTIONS + ' options.');
        return false;
    }
    
    const optionTexts = filledOptions.map(opt => opt.value.trim().toLowerCase());
    const uniqueOptions = new Set(optionTexts);
    
    if (optionTexts.length !== uniqueOptions.size) {
        e.preventDefault();
        alert('Duplicate options are not allowed. Please ensure all options are unique.');
        return false;
    }
});
</script>

<?php include '../../../includes/footer.php'; ?>