<?php
require_once '../../config/config.php';
require_once '../../includes/auth_functions.php';

requireLogin();

$page_title = 'Announcements';

// Get filter parameters
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
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

// Get announcements - Simple query with only existing columns
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

include '../../includes/header.php';
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }
    
    body {
        font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
        background: #f5f5f5;
        color: #1a1a1a;
    }
    
    .page-header {
        background: white;
        padding: 2rem;
        margin-bottom: 2rem;
        border-bottom: 1px solid #e0e0e0;
    }
    
    .page-header h1 {
        font-size: 1.75rem;
        font-weight: 600;
        margin-bottom: 0.5rem;
        color: #1a1a1a;
    }
    
    .page-header p {
        color: #666;
        font-size: 0.9375rem;
    }
    
    .container-fluid {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 1rem 2rem;
    }
    
    .filters-section {
        background: white;
        padding: 1.5rem;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
        margin-bottom: 2rem;
    }
    
    .filters-grid {
        display: grid;
        grid-template-columns: 2fr 1fr 1fr auto;
        gap: 1rem;
    }
    
    @media (max-width: 768px) {
        .filters-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .form-group label {
        display: block;
        font-size: 0.875rem;
        font-weight: 500;
        margin-bottom: 0.5rem;
        color: #333;
    }
    
    .form-control {
        width: 100%;
        padding: 0.625rem 0.875rem;
        font-size: 0.875rem;
        border: 1px solid #d0d0d0;
        border-radius: 6px;
        background: white;
        transition: all 0.15s;
    }
    
    .form-control:focus {
        outline: none;
        border-color: #666;
        box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.05);
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
    }
    
    .btn-primary {
        background: #333;
        color: white;
    }
    
    .btn-primary:hover {
        background: #1a1a1a;
    }
    
    .announcements-grid {
        display: grid;
        gap: 1.5rem;
    }
    
    .announcement-card {
        background: white;
        border-radius: 8px;
        overflow: hidden;
        border: 1px solid #e0e0e0;
        transition: all 0.2s;
    }
    
    .announcement-content-wrapper {
        padding: 1.5rem;
    }
    
    .announcement-header {
        display: flex;
        align-items: center;
        gap: 1rem;
        margin-bottom: 1rem;
        padding-bottom: 1rem;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .author-avatar {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background: #e0e0e0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 600;
        color: #666;
        font-size: 1.125rem;
    }
    
    .author-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        border-radius: 50%;
    }
    
    .announcement-meta {
        flex: 1;
    }
    
    .author-name {
        font-weight: 600;
        color: #1a1a1a;
        font-size: 0.9375rem;
        margin-bottom: 0.25rem;
    }
    
    .post-date {
        font-size: 0.8125rem;
        color: #666;
        display: flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .announcement-title {
        font-size: 1.25rem;
        font-weight: 600;
        margin-bottom: 0.75rem;
        color: #1a1a1a;
        line-height: 1.4;
    }
    
    .announcement-text {
        color: #4a4a4a;
        line-height: 1.6;
        margin-bottom: 1rem;
    }
    
    .announcement-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 0.75rem;
        padding-top: 1rem;
        border-top: 1px solid #f0f0f0;
    }
    
    .announcement-tags {
        display: flex;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 0.375rem;
        padding: 0.375rem 0.75rem;
        font-size: 0.75rem;
        font-weight: 500;
        border-radius: 4px;
        border: 1px solid;
    }
    
    .badge-urgent {
        background: #ffebee;
        color: #c62828;
        border-color: #ef9a9a;
    }
    
    .badge-high {
        background: #fff3e0;
        color: #e65100;
        border-color: #ffcc80;
    }
    
    .badge-normal {
        background: #e3f2fd;
        color: #1565c0;
        border-color: #90caf9;
    }
    
    .badge-low {
        background: #f5f5f5;
        color: #616161;
        border-color: #bdbdbd;
    }
    
    .badge-category {
        background: white;
        color: #555;
        border-color: #d0d0d0;
    }
    
    .read-more {
        color: #333;
        text-decoration: none;
        font-weight: 500;
        font-size: 0.875rem;
        display: inline-flex;
        align-items: center;
        gap: 0.25rem;
    }
    
    .read-more:hover {
        color: #000;
    }
    
    .empty-state {
        text-align: center;
        padding: 4rem 2rem;
        background: white;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
    }
    
    .empty-state i {
        font-size: 4rem;
        color: #d0d0d0;
        margin-bottom: 1rem;
    }
    
    .empty-state h3 {
        font-size: 1.25rem;
        color: #333;
        margin-bottom: 0.5rem;
    }
    
    .empty-state p {
        color: #666;
    }
    
    .urgent-banner {
        background: #fff5f5;
        border: 1px solid #ffcdd2;
        border-radius: 8px;
        padding: 1rem 1.5rem;
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
    }
    
    .urgent-banner i {
        font-size: 1.5rem;
        color: #d32f2f;
    }
    
    .urgent-banner-content {
        flex: 1;
    }
    
    .urgent-banner h4 {
        color: #c62828;
        font-size: 1rem;
        margin-bottom: 0.25rem;
    }
    
    .urgent-banner p {
        color: #b71c1c;
        font-size: 0.875rem;
        margin: 0;
    }
</style>

<div class="page-header">
    <h1><i class="fas fa-bullhorn"></i> Announcements</h1>
    <p>Stay updated with the latest news and information from the community</p>
</div>

<div class="container-fluid">
    <?php
    // Check for urgent announcements
    $urgent_query = "
        SELECT COUNT(*) as urgent_count
        FROM tbl_announcements
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
    ";
    $urgent_result = $conn->query($urgent_query);
    $urgent_data = $urgent_result->fetch_assoc();
    
    if ($urgent_data['urgent_count'] > 0):
    ?>
    <div class="urgent-banner">
        <i class="fas fa-exclamation-triangle"></i>
        <div class="urgent-banner-content">
            <h4>Recent Announcements</h4>
            <p>There <?php echo $urgent_data['urgent_count'] == 1 ? 'is' : 'are'; ?> <?php echo $urgent_data['urgent_count']; ?> announcement<?php echo $urgent_data['urgent_count'] == 1 ? '' : 's'; ?> from the past week.</p>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Filters -->
    <div class="filters-section">
        <form method="GET" action="">
            <div class="filters-grid" style="grid-template-columns: 1fr auto;">
                <div class="form-group">
                    <label><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" class="form-control" placeholder="Search announcements..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <!-- Announcements Grid -->
    <div class="announcements-grid">
        <?php if ($announcements->num_rows > 0): ?>
            <?php while ($announcement = $announcements->fetch_assoc()): ?>
                <div class="announcement-card">
                    <div class="announcement-content-wrapper">
                        <div class="announcement-header">
                            <div class="author-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="announcement-meta">
                                <div class="author-name">Admin</div>
                                <div class="post-date">
                                    <i class="far fa-clock"></i>
                                    <?php echo date('M d, Y \a\t h:i A', strtotime($announcement['created_at'])); ?>
                                </div>
                            </div>
                        </div>
                        
                        <h3 class="announcement-title"><?php echo htmlspecialchars($announcement['title']); ?></h3>
                        
                        <div class="announcement-text">
                            <?php 
                            $content = htmlspecialchars($announcement['content']);
                            if (strlen($content) > 300) {
                                echo nl2br(substr($content, 0, 300)) . '...';
                            } else {
                                echo nl2br($content);
                            }
                            ?>
                        </div>
                        
                        <div class="announcement-footer">
                            <div class="announcement-tags">
                                <span class="badge badge-category">
                                    <i class="fas fa-bullhorn"></i>
                                    Announcement
                                </span>
                            </div>
                            <?php if (strlen($announcement['content']) > 300): ?>
                                <a href="view-announcement.php?id=<?php echo $announcement['announcement_id']; ?>" class="read-more">
                                    Read more <i class="fas fa-arrow-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Announcements Found</h3>
                <p>There are no announcements matching your criteria at this time.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>