<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
requireLogin();
$page_title = 'Add Expense';
$user_role = getCurrentUserRole();
if (!in_array($user_role, ['Super Admin', 'Treasurer', 'Admin'])) { header('Location: ../../modules/dashboard/index.php'); exit(); }
$current_user_id = getCurrentUserId();
$current_year = date('Y');
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category_id    = isset($_POST['category_id'])    ? intval($_POST['category_id'])      : 0;
    $allocation_id  = isset($_POST['allocation_id'])  && intval($_POST['allocation_id'])>0 ? intval($_POST['allocation_id']) : null;
    $amount         = isset($_POST['amount'])         ? floatval($_POST['amount'])          : 0;
    $payee          = isset($_POST['payee'])          ? trim($_POST['payee'])               : '';
    $description    = isset($_POST['description'])    ? trim($_POST['description'])         : '';
    $expense_date   = isset($_POST['expense_date'])   ? $_POST['expense_date']              : '';
    $invoice_number = isset($_POST['invoice_number']) ? trim($_POST['invoice_number'])      : '';
    $payment_method = isset($_POST['payment_method']) ? $_POST['payment_method']            : 'Cash';
    $check_number   = isset($_POST['check_number'])   ? trim($_POST['check_number'])        : '';
    if ($category_id<=0)       $errors[]="Please select an expense category";
    if ($amount<=0)            $errors[]="Amount must be greater than zero";
    if (empty($payee))         $errors[]="Payee is required";
    if (empty($description))   $errors[]="Description is required";
    if (empty($expense_date))  $errors[]="Expense date is required";
    if ($allocation_id && $amount>0 && $user_role==='Super Admin') {
        $bc=fetchOne($conn,"SELECT remaining_amount FROM tbl_budget_allocations WHERE allocation_id=? AND status='Approved'",[$allocation_id],'i');
        if ($bc && $amount>$bc['remaining_amount']) $errors[]="Amount exceeds budget remaining (₱".number_format($bc['remaining_amount'],2).")";
    }
    if (empty($errors)) {
        $ref_prefix="EXP-".date('Ym')."-";
        $lr=$conn->prepare("SELECT reference_number FROM tbl_expenses WHERE reference_number LIKE ? ORDER BY expense_id DESC LIMIT 1");
        $sp=$ref_prefix.'%'; $lr->bind_param("s",$sp); $lr->execute(); $lrr=$lr->get_result();
        $new_num=$lrr->num_rows>0?intval(substr($lrr->fetch_assoc()['reference_number'],-4))+1:1; $lr->close();
        $ref=$ref_prefix.str_pad($new_num,4,'0',STR_PAD_LEFT);
        if ($allocation_id && $user_role==='Super Admin') {
            $stmt=$conn->prepare("INSERT INTO tbl_expenses (reference_number,category_id,allocation_id,amount,payee,description,expense_date,invoice_number,payment_method,check_number,requested_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'Pending')");
            $stmt->bind_param("siidssssssi",$ref,$category_id,$allocation_id,$amount,$payee,$description,$expense_date,$invoice_number,$payment_method,$check_number,$current_user_id);
        } else {
            $stmt=$conn->prepare("INSERT INTO tbl_expenses (reference_number,category_id,amount,payee,description,expense_date,invoice_number,payment_method,check_number,requested_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,'Pending')");
            $stmt->bind_param("sidssssssi",$ref,$category_id,$amount,$payee,$description,$expense_date,$invoice_number,$payment_method,$check_number,$current_user_id);
        }
        if ($stmt->execute()) { $_SESSION['success_message']="Expense added! Reference: {$ref}"; header('Location: expenses.php'); exit(); }
        else $errors[]="Error: ".$stmt->error;
        $stmt->close();
    }
}
$categories  = fetchAll($conn,"SELECT * FROM tbl_expense_categories WHERE is_active=1 ORDER BY category_name");
$allocations = [];
if ($user_role==='Super Admin') {
    $allocations=fetchAll($conn,"SELECT ba.*,ec.category_name FROM tbl_budget_allocations ba JOIN tbl_expense_categories ec ON ba.category_id=ec.category_id WHERE ba.fiscal_year=? AND ba.status='Approved' AND ba.remaining_amount>0 ORDER BY ec.category_name",[$current_year],'i');
}
include '../../includes/header.php';
?>
<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=DM+Mono:wght@300;400;500&display=swap');
:root{--db-navy:#0d1b36;--db-navy-light:#1c3461;--db-rose:#e11d48;--db-rose-light:#ffe4e6;--db-danger:#ef4444;--db-danger-light:#fee2e2;--db-bg:#eef2f7;--db-surf:#ffffff;--db-surf2:#f8fafc;--db-border:#e2e8f0;--db-text:#0f172a;--db-muted:#64748b;--db-radius:14px;--db-radius-sm:8px;--db-radius-lg:20px;--db-shadow:0 1px 3px rgba(13,27,54,.06),0 4px 16px rgba(13,27,54,.07);}
*,*::before,*::after{box-sizing:border-box;}
body{font-family:'Sora',sans-serif;background:var(--db-bg);color:var(--db-text);font-size:13.5px;}
.fm-hero{background:linear-gradient(135deg,var(--db-navy) 0%,var(--db-navy-light) 65%,#7f1d1d 100%);padding:28px 36px;margin-bottom:24px;border-radius:0 0 var(--db-radius-lg) var(--db-radius-lg);position:relative;overflow:hidden;}
.fm-hero__ring{position:absolute;border-radius:50%;border:1px solid rgba(255,255,255,.06);pointer-events:none;}
.fm-hero__ring--1{width:300px;height:300px;top:-130px;right:-60px;}
.fm-hero__ring--2{width:180px;height:180px;top:-50px;right:70px;border-color:rgba(225,29,72,.12);}
.fm-hero__inner{position:relative;z-index:1;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;}
.fm-hero__left{display:flex;align-items:center;gap:16px;}
.fm-hero__icon{width:52px;height:52px;border-radius:14px;background:linear-gradient(135deg,#9f1239,var(--db-rose));display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff;box-shadow:0 4px 16px rgba(225,29,72,.4);flex-shrink:0;}
.fm-hero__title{font-size:22px;font-weight:800;color:#fff;letter-spacing:-.4px;margin-bottom:3px;}
.fm-hero__sub{font-size:13px;color:rgba(255,255,255,.55);}
.db-alert--error{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-radius:var(--db-radius);margin-bottom:16px;font-weight:500;font-size:13.5px;border-left:4px solid var(--db-danger);background:var(--db-danger-light);color:#7f1d1d;}
.db-panel{background:var(--db-surf);border-radius:var(--db-radius-lg);border:1px solid var(--db-border);box-shadow:var(--db-shadow);margin-bottom:18px;overflow:hidden;animation:dbFadeUp .35s ease both;}
@keyframes dbFadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.db-panel__body{padding:28px 30px;}
.fm-form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:18px;margin-bottom:24px;}
.fm-form-grid .full{grid-column:1/-1;}
.db-form-group{display:flex;flex-direction:column;gap:5px;}
.db-form-label{font-size:11px;font-weight:600;color:var(--db-muted);text-transform:uppercase;letter-spacing:.5px;}
.db-form-label--req::after{content:' *';color:var(--db-rose);}
.db-input,.db-select,.db-textarea{padding:10px 14px;border:1.5px solid var(--db-border);border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;color:var(--db-text);background:var(--db-surf);outline:none;transition:border-color .18s,box-shadow .18s;width:100%;}
.db-input:focus,.db-select:focus,.db-textarea:focus{border-color:var(--db-navy-light);box-shadow:0 0 0 3px rgba(28,52,97,.1);}
.db-textarea{resize:vertical;min-height:90px;}
.db-form-hint{font-size:11px;color:var(--db-muted);}
.fm-form-actions{display:flex;gap:10px;padding-top:20px;border-top:1px solid var(--db-border);}
.db-btn{display:inline-flex;align-items:center;gap:6px;padding:10px 20px;border-radius:var(--db-radius-sm);font-family:'Sora',sans-serif;font-size:13px;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:all .18s;white-space:nowrap;}
.db-btn--rose{background:linear-gradient(135deg,#9f1239,var(--db-rose));color:#fff;}
.db-btn--rose:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(225,29,72,.3);color:#fff;}
.db-btn--ghost{background:var(--db-surf2);color:var(--db-text);border-color:var(--db-border);}
.db-btn--ghost:hover{background:var(--db-border);}
.db-budget-hint{font-size:11.5px;color:var(--db-muted);margin-top:4px;}
body.dark-mode{background:#0f172a!important;color:#e2e8f0!important;}
body.dark-mode .db-panel{background:#1e293b!important;border-color:#334155!important;}
body.dark-mode .db-input,body.dark-mode .db-select,body.dark-mode .db-textarea{background:#334155!important;color:#e2e8f0!important;border-color:#475569!important;}
body.dark-mode .db-form-label,body.dark-mode .db-form-hint,body.dark-mode .db-budget-hint{color:#94a3b8!important;}
body.dark-mode .fm-form-actions{border-top-color:#334155!important;}
body.dark-mode .db-btn--ghost{background:#1e293b!important;color:#e2e8f0!important;border-color:#475569!important;}
@media(max-width:768px){.fm-hero{padding:20px;border-radius:0;}.fm-form-grid{grid-template-columns:1fr;}.fm-form-grid .full{grid-column:1/1;}.fm-form-actions{flex-direction:column;}}
</style>
<div class="fm-hero">
    <div class="fm-hero__ring fm-hero__ring--1"></div><div class="fm-hero__ring fm-hero__ring--2"></div>
    <div class="fm-hero__inner">
        <div class="fm-hero__left">
            <div class="fm-hero__icon"><i class="fas fa-minus-circle"></i></div>
            <div><div class="fm-hero__title">Add Expense</div><div class="fm-hero__sub">Record new barangay expense</div></div>
        </div>
        <a href="expenses.php" class="db-btn db-btn--ghost" style="color:#fff;border-color:rgba(255,255,255,.25);background:rgba(255,255,255,.1);"><i class="fas fa-arrow-left"></i> Back to List</a>
    </div>
</div>
<div style="padding:0 24px 24px;">
<?php if (!empty($errors)): ?>
<div class="db-alert--error"><i class="fas fa-exclamation-circle" style="flex-shrink:0;margin-top:1px;"></i><ul style="margin:0;padding-left:18px;"><?php foreach($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<div class="db-panel">
    <div class="db-panel__body">
        <form method="POST">
            <div class="fm-form-grid">
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Expense Category</label>
                    <select name="category_id" id="category_id" class="db-select" required onchange="filterBudgets()">
                        <option value="">Select Category</option>
                        <?php foreach($categories as $c): ?><option value="<?php echo $c['category_id']; ?>" <?php echo (isset($_POST['category_id'])&&$_POST['category_id']==$c['category_id'])?'selected':''; ?>><?php echo htmlspecialchars($c['category_name']); ?></option><?php endforeach; ?>
                    </select>
                    <span class="db-form-hint">Select the type of expense</span>
                </div>
                <?php if ($user_role==='Super Admin' && !empty($allocations)): ?>
                <div class="db-form-group">
                    <label class="db-form-label">Budget Allocation (Optional)</label>
                    <select name="allocation_id" id="allocation_id" class="db-select" onchange="updateBudgetInfo()">
                        <option value="0">No budget allocation</option>
                        <?php foreach($allocations as $a): ?><option value="<?php echo $a['allocation_id']; ?>" data-category="<?php echo $a['category_id']; ?>" data-remaining="<?php echo $a['remaining_amount']; ?>" <?php echo (isset($_POST['allocation_id'])&&$_POST['allocation_id']==$a['allocation_id'])?'selected':''; ?>><?php echo htmlspecialchars($a['category_name']); ?> — ₱<?php echo number_format($a['remaining_amount'],2); ?> remaining</option><?php endforeach; ?>
                    </select>
                    <span class="db-budget-hint" id="budgetHint">Charge to approved budget (optional)</span>
                </div>
                <?php endif; ?>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Amount (₱)</label>
                    <input type="number" name="amount" id="amount" class="db-input" step="0.01" min="0.01" placeholder="0.00" value="<?php echo isset($_POST['amount'])?$_POST['amount']:''; ?>" required>
                    <span class="db-form-hint">Enter the expense amount</span>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Expense Date</label>
                    <input type="date" name="expense_date" class="db-input" value="<?php echo isset($_POST['expense_date'])?$_POST['expense_date']:date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                </div>
                <div class="db-form-group full">
                    <label class="db-form-label db-form-label--req">Payee</label>
                    <input type="text" name="payee" class="db-input" placeholder="e.g., XYZ Hardware, Juan Dela Cruz" value="<?php echo isset($_POST['payee'])?htmlspecialchars($_POST['payee']):''; ?>" required>
                    <span class="db-form-hint">Name of person/company to be paid</span>
                </div>
                <div class="db-form-group">
                    <label class="db-form-label db-form-label--req">Payment Method</label>
                    <select name="payment_method" id="payment_method" class="db-select" required onchange="toggleCheck()">
                        <?php foreach(['Cash','Check','Bank Transfer','GCash','PayMaya','Other'] as $m): ?><option value="<?php echo $m; ?>" <?php echo (isset($_POST['payment_method'])&&$_POST['payment_method']===$m)?'selected':''; ?>><?php echo $m; ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="db-form-group" id="checkGroup" style="display:none;">
                    <label class="db-form-label">Check Number</label>
                    <input type="text" name="check_number" class="db-input" placeholder="Check number" value="<?php echo isset($_POST['check_number'])?htmlspecialchars($_POST['check_number']):''; ?>">
                </div>
                <div class="db-form-group">
                    <label class="db-form-label">Invoice / Bill Number</label>
                    <input type="text" name="invoice_number" class="db-input" placeholder="Optional invoice number" value="<?php echo isset($_POST['invoice_number'])?htmlspecialchars($_POST['invoice_number']):''; ?>">
                </div>
                <div class="db-form-group full">
                    <label class="db-form-label db-form-label--req">Description / Purpose</label>
                    <textarea name="description" class="db-textarea" placeholder="Describe the expense purpose…" required><?php echo isset($_POST['description'])?htmlspecialchars($_POST['description']):''; ?></textarea>
                </div>
            </div>
            <div class="fm-form-actions">
                <button type="submit" class="db-btn db-btn--rose"><i class="fas fa-save"></i> Save Expense</button>
                <a href="expenses.php" class="db-btn db-btn--ghost"><i class="fas fa-times"></i> Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
<script>
function toggleCheck(){const v=document.getElementById('payment_method').value;document.getElementById('checkGroup').style.display=v==='Check'?'flex':'none';}
function filterBudgets(){
    <?php if($user_role==='Super Admin'&&!empty($allocations)): ?>
    const cat=document.getElementById('category_id').value;
    document.querySelectorAll('#allocation_id option').forEach(o=>{if(!o.value||o.value==='0')return;o.style.display=(!cat||o.dataset.category===cat)?'':'none';});
    const sel=document.getElementById('allocation_id');
    if(sel.options[sel.selectedIndex]&&sel.options[sel.selectedIndex].style.display==='none'){sel.value='0';updateBudgetInfo();}
    <?php endif; ?>
}
function updateBudgetInfo(){
    <?php if($user_role==='Super Admin'&&!empty($allocations)): ?>
    const sel=document.getElementById('allocation_id');const o=sel.options[sel.selectedIndex];const h=document.getElementById('budgetHint');
    if(o.value!=='0'){const r=o.dataset.remaining;h.innerHTML='Budget remaining: <strong style="color:#10b981">₱'+parseFloat(r).toLocaleString('en-PH',{minimumFractionDigits:2})+'</strong>';h.style.color='#059669';}
    else{h.innerHTML='Charge to approved budget (optional)';h.style.color='';}
    <?php endif; ?>
}
document.addEventListener('DOMContentLoaded',()=>{toggleCheck();filterBudgets();updateBudgetInfo();});
</script>
<?php include '../../includes/footer.php'; ?>