<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../controllers/LibrarianController.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}

$controller = new LibrarianController($pdo, $_SESSION['user_id']);
$data = $controller->getDashboardStats();

$librarian_name = $_SESSION['name'] ?? 'Librarian';
$totalBooks = $data['totalBooks'] ?? 'N/A';
$copiesAvailable = $data['copiesAvailable'] ?? 'N/A';
$recentActivity = $data['recentActivity'] ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Librarian's Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; margin: 0; padding: 0; background-color: #F7FCFC; color: #333; }
        .container { display: flex; min-height: 100vh; }
        .sidebar { width: 70px; padding: 30px 0; background-color: #fff; border-right: 1px solid #eee; position: fixed; height: 100vh; top: 0; left: 0; z-index: 100; transition: width 0.5s ease; white-space: nowrap; overflow-x: hidden; }
        .sidebar.active { width: 250px; }
        .logo { font-size: 19px; font-weight: bold; color: #000; padding: 0 23px 40px; display: flex; align-items: center; cursor: pointer; }
        .logo-text { opacity: 0; transition: opacity 0.1s ease; margin-left: 10px; }
        .sidebar.active .logo-text { opacity: 1; }
        .nav-list { list-style: none; padding: 0; margin: 0; }
        .nav-item a { display: flex; align-items: center; font-size: 15px; padding: 15px 24px; text-decoration: none; color: #6C6C6C; transition: background-color 0.2s; }
        .nav-item a:hover { background-color: #f0f0f0; }
        .nav-item.active a { color: #000; font-weight: bold; }
        .nav-icon { margin-right: 20px; font-size: 21px; width: 20px; }
        .main-content { flex-grow: 1; padding: 30px 32px; margin-left: 70px; transition: margin-left 0.5s ease; }
        .main-content.pushed { margin-left: 250px; }
        .header { text-align: right; padding-bottom: 20px; font-size: 16px; color: #666; }
        .header span { font-weight: bold; color: #333; }
        .dashboard-section { width: 100%; display: flex; flex-direction: column; align-items: center; }
        .dashboard-section h2 { font-size: 25px; font-weight: bold; align-self: start; margin-bottom: 20px; }
        .action-cards { display: flex; gap: 30px; margin: 25px 0 35px; width: 100%; justify-content: center; }
        .card { flex: 1; max-width: 218px; background-color: #57e4d4ff; border-radius: 11px; display: flex; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card-link { display: flex; align-items: center; justify-content: center; width: 100%; padding: 25px; text-decoration: none; color: #333; font-weight: 550; font-size: 16px; border-radius: 11px; transition: 0.2s; }
        .card-link:hover { background-color: #63d5c8ff; box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .overview-section { width: 100%; max-width: 960px; }
        .overview-card { background-color: #fff; border-radius: 11px; padding: 30px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .stats-grid { display: flex; gap: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; margin-bottom: 20px; }
        .stat-box { flex: 1; text-align: center; border-left: 1px solid #f0f0f0; }
        .stat-box:first-child { border-left: none; }
        .stat-box h4 { font-size: 38px; color: #00A693; margin: 0 0 5px; }
        .activity-table { width: 100%; border-collapse: collapse; font-size: 14px; }
        .activity-table th, .activity-table td { padding: 12px 0; text-align: left; border-bottom: 1px solid #f9f9f9; }
        .activity-type-added { color: #00A693; font-weight: 600; }
        .activity-type-updated { color: #e5a000; font-weight: 600; }
        .activity-type-archived { color: #777; font-weight: 600; }
    </style>
</head>
<body>
    <div class="container">
        <div id="sidebar-menu" class="sidebar">
            <div class="logo" onclick="toggleSidebar()">
                <span class="material-icons">menu</span><span class="logo-text">📚 Smart Library</span>
            </div>
            <ul class="nav-list">
                <li class="nav-item active"><a href="librarian.php"><span class="material-icons nav-icon">dashboard</span><span class="logo-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="book_inventory.php"><span class="material-icons nav-icon">inventory_2</span><span class="logo-text">Inventory</span></a></li>
                <li class="nav-item"><a href="manage_books.php"><span class="material-icons nav-icon">edit_note</span><span class="logo-text">Manage Books</span></a></li>
            </ul>
            <div class="logout nav-item"><a href="login.php"><span class="nav-icon material-icons">logout</span><span class="text">Logout</span></a></div>
        </div>

        <div id="main-content-area" class="main-content">
            <div class="header">Welcome, <span><?php echo htmlspecialchars($librarian_name); ?></span></div>
            <div class="dashboard-section">
                <h2>Librarian's Dashboard</h2>
                <div class="action-cards">
                    <div class="card"><a href="book_inventory.php" class="card-link">Book Inventory</a></div>
                    <div class="card"><a href="add_book.php" class="card-link">Add Book</a></div>
                    <div class="card"><a href="update_book.php" class="card-link">Update Book</a></div>
                    <div class="card"><a href="archive_book.php" class="card-link">Archive Book</a></div>
                </div>
                <div class="overview-section">
                    <div class="overview-card">
                        <h3>Library Overview</h3>
                        <div class="stats-grid">
                            <div class="stat-box"><h4><?php echo $totalBooks; ?></h4><p>Total Books</p></div>
                            <div class="stat-box"><h4><?php echo $copiesAvailable; ?></h4><p>Copies Available</p></div>
                        </div>
                        <h3>Recent Activity</h3>
                        <table class="activity-table">
                            <thead><tr><th style="width:150px;">Time</th><th>Action</th><th>Book</th></tr></thead>
                            <tbody>
                                <?php if (!empty($recentActivity)): ?>
                                    <?php foreach ($recentActivity as $act): 
                                        $cls = match($act['ActionType']) { 'Added'=>'activity-type-added','Updated'=>'activity-type-updated',default=>'activity-type-archived' }; ?>
                                        <tr>
                                            <td><?php echo date('M d, H:i', strtotime($act['Timestamp'])); ?></td>
                                            <td><span class="<?php echo $cls; ?>"><?php echo $act['ActionType']; ?></span></td>
                                            <td><?php echo htmlspecialchars($act['Title']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" style="text-align:center;color:#999;">No recent activity.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar-menu').classList.toggle('active');
            document.getElementById('main-content-area').classList.toggle('pushed');
        }
    </script>
</body>
</html>