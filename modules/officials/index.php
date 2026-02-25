<?php
require_once '../../config/config.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../config/database.php';
requireLogin();

$page_title = 'Barangay Officials';

// Fetch all officials with their positions
$officials = [];
$sql = "SELECT 
            official_id,
            first_name,
            middle_name,
            last_name,
            position,
            term_start,
            term_end,
            photo,
            is_active
        FROM tbl_barangay_officials
        WHERE is_active = 1
        ORDER BY 
            CASE position
                WHEN 'Barangay Captain' THEN 1
                WHEN 'Barangay Kagawad' THEN 2
                WHEN 'Barangay Secretary' THEN 3
                WHEN 'Barangay Treasurer' THEN 4
                WHEN 'SK Chairperson' THEN 5
                WHEN 'SK Kagawad' THEN 6
                ELSE 7
            END,
            last_name ASC";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $officials[] = $row;
    }
}

// Organize officials by position
$captain = null;
$kagawads = [];
$secretary = null;
$treasurer = null;
$sk_chair = null;
$sk_kagawads = [];
$others = [];

foreach ($officials as $official) {
    switch ($official['position']) {
        case 'Barangay Captain':
            $captain = $official;
            break;
        case 'Barangay Kagawad':
            $kagawads[] = $official;
            break;
        case 'Barangay Secretary':
            $secretary = $official;
            break;
        case 'Barangay Treasurer':
            $treasurer = $official;
            break;
        case 'SK Chairperson':
            $sk_chair = $official;
            break;
        case 'SK Kagawad':
            $sk_kagawads[] = $official;
            break;
        default:
            $others[] = $official;
            break;
    }
}

$extra_css = '<link rel="stylesheet" href="../../assets/css/officials.css">
<style>
    .page-header-with-logos {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 50px;
        margin-bottom: 40px;
    }
    
    .page-header-with-logos .logo {
        width: 150px;
        height: 150px;
        object-fit: contain;
    }
    
    .page-header-content {
        text-align: center;
    }
    
    .page-header-content h1 {
        margin: 0 0 10px 0;
    }
    
    .page-header-content p {
        margin: 0;
    }
    
    .two-column-layout {
        display: flex;
        gap: 40px;
        position: relative;
        align-items: flex-start;
    }
    
    .officials-section {
        flex: 1;
        position: relative;
    }
    
    .officials-section:first-child .section-title::before {
        background-image: url("../../uploads/officials/brgy.png");
    }
    
    .officials-section:last-child .section-title::before {
        background-image: url("../../uploads/officials/sk.png");
    }
    
    /* Vertical tree structure */
    .org-chart {
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 30px;
    }
    
    .org-level {
        display: flex;
        flex-wrap: wrap;
        justify-content: center;
        gap: 20px;
        width: 100%;
    }
    
    .org-level.council,
    .org-level.admin,
    .org-level.sk-members {
        max-width: 100%;
    }
    
    /* Connector lines between levels */
    .connector {
        width: 2px;
        height: 30px;
        background: linear-gradient(to bottom, #3b82f6, transparent);
        margin: 0 auto;
    }
    
    /* Official box styling */
    .official-box {
        background: white;
        border-radius: 12px;
        padding: 20px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        text-align: center;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        min-width: 200px;
        max-width: 250px;
    }
    
    .official-box:hover {
        transform: translateY(-5px);
        box-shadow: 0 8px 12px rgba(0,0,0,0.15);
    }
    
    .official-box.captain {
        border-top: 4px solid #3b82f6;
    }
    
    .official-box.sk-captain {
        border-top: 4px solid #10b981;
    }
    
    .official-box .photo {
        width: 100px;
        height: 100px;
        margin: 0 auto 15px;
        border-radius: 50%;
        overflow: hidden;
        border: 3px solid #e5e7eb;
    }
    
    .official-box .photo img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    
    .official-box .initials {
        width: 100%;
        height: 100%;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #3b82f6, #8b5cf6);
        color: white;
        font-size: 32px;
        font-weight: bold;
    }
    
    .official-box h3 {
        margin: 0 0 8px 0;
        font-size: 16px;
        color: #1f2937;
    }
    
    .official-box .position {
        margin: 0 0 8px 0;
        font-size: 14px;
        color: #3b82f6;
        font-weight: 600;
    }
    
    .official-box .term {
        margin: 0;
        font-size: 12px;
        color: #6b7280;
    }
    
    /* Responsive design */
    @media (max-width: 1024px) {
        .two-column-layout {
            flex-direction: column;
        }
        
        .vertical-divider {
            display: none;
        }
    }
</style>';
include '../../includes/header.php';
?>

<div class="officials-page">
    <div class="page-header">
        <div class="page-header-with-logos">
            <div class="page-header-content">
                <h1>Barangay Centro Officials</h1>
                <p>Meet the dedicated leaders serving our community</p>
            </div>
        </div>
    </div>

    <div class="two-column-layout">
        
        <!-- LEFT SIDE: BARANGAY OFFICIALS -->
        <div class="officials-section">
            <h2 class="section-title">Barangay Officials</h2>
            
            <div class="org-chart">
                <!-- Barangay Captain -->
                <?php if ($captain): ?>
                <div class="org-level">
                    <div class="official-box captain">
                        <div class="photo">
                            <?php if ($captain['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $captain['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($captain['photo']); ?>" alt="<?php echo htmlspecialchars($captain['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($captain['first_name'], 0, 1) . substr($captain['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($captain['first_name'] . ' ' . ($captain['middle_name'] ? substr($captain['middle_name'], 0, 1) . '. ' : '') . $captain['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($captain['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($captain['term_start'])); ?> - <?php echo date('Y', strtotime($captain['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($captain['first_name'] . ' ' . ($captain['middle_name'] ? substr($captain['middle_name'], 0, 1) . '. ' : '') . $captain['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($captain['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($captain['term_start'])); ?> – <?php echo date('Y', strtotime($captain['term_end'])); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="connector"></div>
                <?php endif; ?>

                <!-- Barangay Kagawads -->
                <?php if (!empty($kagawads)): ?>
                <div class="org-level council">
                    <?php foreach ($kagawads as $kagawad): ?>
                    <div class="official-box">
                        <div class="photo">
                            <?php if ($kagawad['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $kagawad['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($kagawad['photo']); ?>" alt="<?php echo htmlspecialchars($kagawad['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($kagawad['first_name'], 0, 1) . substr($kagawad['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($kagawad['first_name'] . ' ' . ($kagawad['middle_name'] ? substr($kagawad['middle_name'], 0, 1) . '. ' : '') . $kagawad['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($kagawad['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($kagawad['term_start'])); ?> - <?php echo date('Y', strtotime($kagawad['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($kagawad['first_name'] . ' ' . ($kagawad['middle_name'] ? substr($kagawad['middle_name'], 0, 1) . '. ' : '') . $kagawad['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($kagawad['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($kagawad['term_start'])); ?> – <?php echo date('Y', strtotime($kagawad['term_end'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <div class="connector"></div>
                <?php endif; ?>

                <!-- Administrative Staff -->
                <?php if ($secretary || $treasurer): ?>
                <div class="org-level admin">
                    <?php if ($secretary): ?>
                    <div class="official-box">
                        <div class="photo">
                            <?php if ($secretary['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $secretary['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($secretary['photo']); ?>" alt="<?php echo htmlspecialchars($secretary['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($secretary['first_name'], 0, 1) . substr($secretary['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($secretary['first_name'] . ' ' . ($secretary['middle_name'] ? substr($secretary['middle_name'], 0, 1) . '. ' : '') . $secretary['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($secretary['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($secretary['term_start'])); ?> - <?php echo date('Y', strtotime($secretary['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($secretary['first_name'] . ' ' . ($secretary['middle_name'] ? substr($secretary['middle_name'], 0, 1) . '. ' : '') . $secretary['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($secretary['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($secretary['term_start'])); ?> – <?php echo date('Y', strtotime($secretary['term_end'])); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($treasurer): ?>
                    <div class="official-box">
                        <div class="photo">
                            <?php if ($treasurer['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $treasurer['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($treasurer['photo']); ?>" alt="<?php echo htmlspecialchars($treasurer['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($treasurer['first_name'], 0, 1) . substr($treasurer['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($treasurer['first_name'] . ' ' . ($treasurer['middle_name'] ? substr($treasurer['middle_name'], 0, 1) . '. ' : '') . $treasurer['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($treasurer['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($treasurer['term_start'])); ?> - <?php echo date('Y', strtotime($treasurer['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($treasurer['first_name'] . ' ' . ($treasurer['middle_name'] ? substr($treasurer['middle_name'], 0, 1) . '. ' : '') . $treasurer['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($treasurer['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($treasurer['term_start'])); ?> – <?php echo date('Y', strtotime($treasurer['term_end'])); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- DIVIDER LINE -->
        <div class="vertical-divider"></div>

        <!-- RIGHT SIDE: SK OFFICIALS -->
        <div class="officials-section">
            <h2 class="section-title">Sangguniang Kabataan</h2>
            
            <div class="org-chart">
                <!-- SK Chairperson -->
                <?php if ($sk_chair): ?>
                <div class="org-level">
                    <div class="official-box sk-captain">
                        <div class="photo">
                            <?php if ($sk_chair['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $sk_chair['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($sk_chair['photo']); ?>" alt="<?php echo htmlspecialchars($sk_chair['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($sk_chair['first_name'], 0, 1) . substr($sk_chair['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($sk_chair['first_name'] . ' ' . ($sk_chair['middle_name'] ? substr($sk_chair['middle_name'], 0, 1) . '. ' : '') . $sk_chair['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($sk_chair['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($sk_chair['term_start'])); ?> - <?php echo date('Y', strtotime($sk_chair['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($sk_chair['first_name'] . ' ' . ($sk_chair['middle_name'] ? substr($sk_chair['middle_name'], 0, 1) . '. ' : '') . $sk_chair['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($sk_chair['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($sk_chair['term_start'])); ?> – <?php echo date('Y', strtotime($sk_chair['term_end'])); ?></span>
                        </div>
                    </div>
                </div>
                
                <div class="connector"></div>
                <?php endif; ?>

                <!-- SK Kagawads -->
                <?php if (!empty($sk_kagawads)): ?>
                <div class="org-level sk-members">
                    <?php foreach ($sk_kagawads as $sk_kagawad): ?>
                    <div class="official-box">
                        <div class="photo">
                            <?php if ($sk_kagawad['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $sk_kagawad['photo'])): ?>
                                <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($sk_kagawad['photo']); ?>" alt="<?php echo htmlspecialchars($sk_kagawad['first_name']); ?>">
                            <?php else: ?>
                                <div class="initials">
                                    <?php echo strtoupper(substr($sk_kagawad['first_name'], 0, 1) . substr($sk_kagawad['last_name'], 0, 1)); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <h3><?php echo htmlspecialchars($sk_kagawad['first_name'] . ' ' . ($sk_kagawad['middle_name'] ? substr($sk_kagawad['middle_name'], 0, 1) . '. ' : '') . $sk_kagawad['last_name']); ?></h3>
                        <p class="position"><?php echo htmlspecialchars($sk_kagawad['position']); ?></p>
                        <p class="term"><?php echo date('Y', strtotime($sk_kagawad['term_start'])); ?> - <?php echo date('Y', strtotime($sk_kagawad['term_end'])); ?></p>
                        <div class="official-box__overlay">
                            <span class="ov-name"><?php echo htmlspecialchars($sk_kagawad['first_name'] . ' ' . ($sk_kagawad['middle_name'] ? substr($sk_kagawad['middle_name'], 0, 1) . '. ' : '') . $sk_kagawad['last_name']); ?></span>
                            <span class="ov-position"><?php echo htmlspecialchars($sk_kagawad['position']); ?></span>
                            <span class="ov-term">Term: <?php echo date('Y', strtotime($sk_kagawad['term_start'])); ?> – <?php echo date('Y', strtotime($sk_kagawad['term_end'])); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- Other Officials (if any) -->
    <?php if (!empty($others)): ?>
    <div class="other-officials-section">
        <h2 class="section-title">Other Officials</h2>
        <div class="org-level others">
            <?php foreach ($others as $official): ?>
            <div class="official-box">
                <div class="photo">
                    <?php if ($official['photo'] && file_exists($_SERVER['DOCUMENT_ROOT'] . '/barangaylink1/uploads/officials/' . $official['photo'])): ?>
                        <img src="/barangaylink1/uploads/officials/<?php echo htmlspecialchars($official['photo']); ?>" alt="<?php echo htmlspecialchars($official['first_name']); ?>">
                    <?php else: ?>
                        <div class="initials">
                            <?php echo strtoupper(substr($official['first_name'], 0, 1) . substr($official['last_name'], 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <h3><?php echo htmlspecialchars($official['first_name'] . ' ' . ($official['middle_name'] ? substr($official['middle_name'], 0, 1) . '. ' : '') . $official['last_name']); ?></h3>
                <p class="position"><?php echo htmlspecialchars($official['position']); ?></p>
                <p class="term"><?php echo date('Y', strtotime($official['term_start'])); ?> - <?php echo date('Y', strtotime($official['term_end'])); ?></p>
                <div class="official-box__overlay">
                    <span class="ov-name"><?php echo htmlspecialchars($official['first_name'] . ' ' . ($official['middle_name'] ? substr($official['middle_name'], 0, 1) . '. ' : '') . $official['last_name']); ?></span>
                    <span class="ov-position"><?php echo htmlspecialchars($official['position']); ?></span>
                    <span class="ov-term">Term: <?php echo date('Y', strtotime($official['term_start'])); ?> – <?php echo date('Y', strtotime($official['term_end'])); ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include '../../includes/footer.php'; ?>