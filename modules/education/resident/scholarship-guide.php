<?php
require_once '../../../config/config.php';

requireLogin();
$page_title = 'Scholarship Guide';

// Get active scholarships for reference
$scholarships_sql = "SELECT * FROM tbl_education_scholarships WHERE status = 'active' ORDER BY scholarship_name";
$active_scholarships = fetchAll($conn, $scholarships_sql);

include '../../../includes/header.php';
?>

<style>
.guide-section {
    padding: 2rem;
    background: #f8f9fa;
    border-radius: 12px;
    margin-bottom: 1.5rem;
}
.guide-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 3rem 2rem;
    border-radius: 12px;
    margin-bottom: 2rem;
}
.step-card {
    transition: all 0.3s;
    border-radius: 10px;
    border: 2px solid #e9ecef;
}
.step-card:hover {
    border-color: #007bff;
    box-shadow: 0 4px 12px rgba(0,123,255,0.15);
}
.step-number {
    width: 50px;
    height: 50px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
}
.faq-item {
    border-bottom: 1px solid #e9ecef;
    padding: 1rem 0;
}
.faq-item:last-child {
    border-bottom: none;
}
.timeline {
    position: relative;
    padding-left: 30px;
}
.timeline::before {
    content: '';
    position: absolute;
    left: 9px;
    top: 0;
    bottom: 0;
    width: 2px;
    background: #e9ecef;
}
.timeline-item {
    position: relative;
    padding-bottom: 1.5rem;
}
.timeline-dot {
    position: absolute;
    left: -26px;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 3px solid #007bff;
    background: white;
}
.checklist-item {
    padding: 0.75rem;
    border-radius: 8px;
    margin-bottom: 0.5rem;
    background: white;
    border: 1px solid #e9ecef;
}
.checklist-item i {
    color: #28a745;
}
</style>

<div class="container-fluid py-4">
    <!-- Header -->
    <div class="guide-header text-center">
        <h1 class="mb-3">
            <i class="fas fa-book-open me-2"></i>Scholarship Application Guide
        </h1>
        <p class="lead mb-0">Everything you need to know about applying for barangay scholarships</p>
    </div>

    <div class="row">
        <!-- Main Content -->
        <div class="col-lg-8 mb-4">
            <!-- Overview -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h3 class="mb-4">
                        <i class="fas fa-info-circle me-2 text-primary"></i>Overview
                    </h3>
                    <p>The Barangay Education Assistance Program aims to support deserving students in pursuing their education by providing financial assistance for tuition fees, school supplies, and other educational needs.</p>
                    
                    <div class="row mt-4">
                        <div class="col-md-4 text-center mb-3">
                            <div class="display-6 text-primary">
                                <i class="fas fa-users"></i>
                            </div>
                            <h5 class="mt-2">Open to All</h5>
                            <p class="small text-muted">Available to all barangay residents</p>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="display-6 text-success">
                                <i class="fas fa-hand-holding-usd"></i>
                            </div>
                            <h5 class="mt-2">Financial Support</h5>
                            <p class="small text-muted">Varying amounts based on need</p>
                        </div>
                        <div class="col-md-4 text-center mb-3">
                            <div class="display-6 text-info">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h5 class="mt-2">Fast Processing</h5>
                            <p class="small text-muted">5-10 days processing time</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step-by-Step Process -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h3 class="mb-4">
                        <i class="fas fa-list-ol me-2 text-success"></i>Application Process
                    </h3>

                    <div class="row g-4">
                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">1</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Check Eligibility</h5>
                                        <p class="text-muted mb-0">Ensure you meet all the eligibility requirements before applying.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">2</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Prepare Documents</h5>
                                        <p class="text-muted mb-0">Gather all required documents listed in the requirements section.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">3</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Submit Online Application</h5>
                                        <p class="text-muted mb-2">Fill out the online application form completely and accurately.</p>
                                        <a href="apply-scholarship.php" class="btn btn-sm btn-primary">
                                            <i class="fas fa-external-link-alt me-1"></i>Go to Application Form
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">4</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Upload Documents</h5>
                                        <p class="text-muted mb-0">Upload scanned copies of required documents through the portal.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">5</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Wait for Evaluation</h5>
                                        <p class="text-muted mb-0">Your application will be reviewed within 5-10 working days.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-12">
                            <div class="step-card p-3">
                                <div class="d-flex align-items-start">
                                    <div class="step-number me-3">6</div>
                                    <div class="flex-grow-1">
                                        <h5 class="mb-2">Receive Notification</h5>
                                        <p class="text-muted mb-0">You'll be notified of the decision via SMS, email, or through the portal.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Required Documents -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h3 class="mb-4">
                        <i class="fas fa-file-alt me-2 text-warning"></i>Required Documents
                    </h3>

                    <div class="guide-section">
                        <h5 class="text-primary mb-3">Documentary Requirements Checklist:</h5>
                        
                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Certificate of Enrollment</strong> - Current semester/school year
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Latest Report Card</strong> - Previous semester/school year grades
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Good Moral Certificate</strong> - From previous school
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Birth Certificate</strong> - NSO/PSA certified copy
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Barangay Clearance</strong> - Issued within the last 3 months
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Certificate of Indigency</strong> - For financial assistance applicants
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>2x2 ID Pictures</strong> - Recent, white background (2 copies)
                        </div>

                        <div class="checklist-item">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>Parent's/Guardian's ID</strong> - Valid government-issued ID
                        </div>
                    </div>

                    <div class="alert alert-info mt-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> All documents should be clear, legible scans or photos. Originals may be required for verification.
                    </div>
                </div>
            </div>

            <!-- Eligibility Criteria -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h3 class="mb-4">
                        <i class="fas fa-user-check me-2 text-info"></i>Eligibility Criteria
                    </h3>

                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <h6 class="mb-2">Residency</h6>
                            <p class="text-muted">Must be a bonafide resident of the barangay for at least 6 months</p>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <h6 class="mb-2">Academic Standing</h6>
                            <p class="text-muted">Must maintain at least 80% (or equivalent) general average</p>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <h6 class="mb-2">Enrollment Status</h6>
                            <p class="text-muted">Must be currently enrolled or have proof of acceptance in an accredited school</p>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <h6 class="mb-2">Good Moral Character</h6>
                            <p class="text-muted">No record of serious disciplinary actions</p>
                        </div>

                        <div class="timeline-item">
                            <div class="timeline-dot"></div>
                            <h6 class="mb-2">Financial Need</h6>
                            <p class="text-muted">Must demonstrate genuine financial need (for assistance programs)</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- FAQs -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-4">
                    <h3 class="mb-4">
                        <i class="fas fa-question-circle me-2 text-danger"></i>Frequently Asked Questions
                    </h3>

                    <div class="faq-item">
                        <h6 class="text-primary">When can I apply for a scholarship?</h6>
                        <p class="text-muted mb-0">You can apply anytime during the school year. However, for specific scholarship programs, check the application period in the "Available Scholarships" section.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">How long does the processing take?</h6>
                        <p class="text-muted mb-0">Applications are typically processed within 5-10 working days. You'll receive a notification once your application has been reviewed.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">Can I apply for multiple scholarships?</h6>
                        <p class="text-muted mb-0">Yes, you can apply for multiple programs as long as you meet the eligibility requirements for each.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">What if my application is rejected?</h6>
                        <p class="text-muted mb-0">You will receive notification with the reason for rejection. You may reapply in the next cycle or address the issues mentioned in the rejection.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">How will I receive the scholarship?</h6>
                        <p class="text-muted mb-0">Approved scholarships may be disbursed directly to your school or given as a cash grant, depending on the scholarship type and barangay policy.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">Do I need to renew my scholarship every year?</h6>
                        <p class="text-muted mb-0">Yes, most scholarships require annual renewal. You'll need to submit updated documents and maintain the required academic standing.</p>
                    </div>

                    <div class="faq-item">
                        <h6 class="text-primary">Who can I contact for more information?</h6>
                        <p class="text-muted mb-0">You can contact the Barangay Education Office at <?php echo BARANGAY_CONTACT; ?> or email <?php echo BARANGAY_EMAIL; ?>.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div class="col-lg-4">
            <!-- Quick Actions -->
            <div class="card border-0 shadow-sm mb-4 sticky-top" style="top: 20px;">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0">
                        <i class="fas fa-rocket me-2"></i>Quick Actions
                    </h5>
                </div>
                <div class="card-body">
                    <div class="d-grid gap-2">
                        <a href="apply-scholarship.php" class="btn btn-primary btn-lg">
                            <i class="fas fa-edit me-2"></i>Apply Now
                        </a>
                        <a href="my-documents.php" class="btn btn-outline-secondary">
                            <i class="fas fa-folder me-2"></i>My Documents
                        </a>
                        <a href="student-portal.php" class="btn btn-outline-info">
                            <i class="fas fa-tachometer-alt me-2"></i>My Dashboard
                        </a>
                    </div>
                </div>
            </div>

            <!-- Active Scholarships -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="mb-0">
                        <i class="fas fa-award me-2 text-warning"></i>Available Scholarships
                    </h6>
                </div>
                <div class="card-body">
                    <?php if (empty($active_scholarships)): ?>
                        <p class="text-muted small text-center">No active scholarships at the moment</p>
                    <?php else: ?>
                        <?php foreach ($active_scholarships as $scholarship): ?>
                            <div class="mb-3 pb-3 border-bottom">
                                <h6 class="mb-1"><?php echo htmlspecialchars($scholarship['scholarship_name']); ?></h6>
                                <div class="mb-2">
                                    <span class="badge bg-success">
                                        ₱<?php echo number_format($scholarship['amount'], 2); ?>
                                    </span>
                                    <?php if ($scholarship['slots']): ?>
                                        <span class="badge bg-info">
                                            <?php echo $scholarship['slots']; ?> slots
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($scholarship['application_end']): ?>
                                    <p class="small text-muted mb-0">
                                        <i class="fas fa-calendar me-1"></i>
                                        Deadline: <?php echo date('M d, Y', strtotime($scholarship['application_end'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Contact Info -->
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-0 py-3">
                    <h6 class="mb-0">
                        <i class="fas fa-phone-alt me-2 text-success"></i>Need Help?
                    </h6>
                </div>
                <div class="card-body">
                    <h6 class="text-primary mb-3">Contact Information:</h6>
                    <p class="small mb-2">
                        <i class="fas fa-map-marker-alt text-danger me-2"></i>
                        <?php echo BARANGAY_ADDRESS; ?>
                    </p>
                    <p class="small mb-2">
                        <i class="fas fa-phone text-primary me-2"></i>
                        <?php echo BARANGAY_CONTACT; ?>
                    </p>
                    <p class="small mb-3">
                        <i class="fas fa-envelope text-info me-2"></i>
                        <?php echo BARANGAY_EMAIL; ?>
                    </p>
                    
                    <h6 class="text-primary mb-2">Office Hours:</h6>
                    <p class="small mb-1">Monday - Friday: 8:00 AM - 5:00 PM</p>
                    <p class="small mb-0">Saturday: 8:00 AM - 12:00 PM</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$conn->close();
include '../../../includes/footer.php';
?>