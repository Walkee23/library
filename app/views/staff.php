<?php
session_start();

// Redirect if user is not logged in or is not Staff (CORRECTED)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

// --- Initialize Dashboard Variables ---
$staff_name = $_SESSION['name'] ?? 'Staff';
$pendingRequests = 'N/A';
$outstandingPenalties = 'N/A';
$recentActivity = [];

try {
    // 1. Get total pending borrowing requests (Assuming Status='Reserved' in Borrow table)
    $stmt1 = $pdo->query("SELECT COUNT(BorrowID) AS pending FROM Borrow WHERE Status = 'Reserved'");
    $stats1 = $stmt1->fetch();
    $pendingRequests = $stats1['pending'] ?? 0;

    // 2. Get total outstanding (pending) penalties (Replacement Fees)
    $stmt2 = $pdo->query("SELECT COUNT(PenaltyID) AS outstanding FROM Penalty WHERE Status = 'Pending'");
    $stats2 = $stmt2->fetch();
    $outstandingPenalties = $stats2['outstanding'] ?? 0;

    // 3. Get recent operational activity (Borrow/Return)
    $sql_activity = "
        SELECT 
            BR.ActionTimestamp, 
            BR.ActionType, 
            BK.Title, 
            U.Name AS UserName
        FROM Borrowing_Record BR
        JOIN Borrow BO ON BR.BorrowID = BO.BorrowID
        JOIN Users U ON BO.UserID = U.UserID
        -- FIX: Join Book via Book_Copy table to get metadata
        JOIN Book_Copy BCPY ON BO.CopyID = BCPY.CopyID
        JOIN Book BK ON BCPY.BookID = BK.BookID
        WHERE BR.ActionType IN ('Borrowed', 'Returned')
        ORDER BY BR.ActionTimestamp DESC
        LIMIT 5
    ";
    $stmt3 = $pdo->query($sql_activity);
    $recentActivity = $stmt3->fetchAll();

} catch (PDOException $e) {
    error_log("Staff Dashboard Error: " . $e->getMessage());
    $error_message = "Could not load staff dashboard statistics.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff's Dashboard</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">

    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <style>
        /* Global Styles */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #F7FCFC;
            color: #333;
        }

        /* Layout Container */
        .container {
            display: flex;
            min-height: 100vh;
        }

        /* --- Collapsible sidebar (Fixed Anchor) --- */
        .sidebar {
            width: 70px;
            padding: 30px 0;
            background-color: #fff;
            border-right: 1px solid #eee;
            box-shadow: 3px 0 9px rgba(0, 0, 0, 0.05);
            position: fixed;
            height: 100vh;
            top: 0;
            left: 0;
            z-index: 100;
            flex-shrink: 0;
            overflow-x: hidden;
            overflow-y: auto;
            transition: width 0.5s ease;
            white-space: nowrap;
        }

        .sidebar.active {
            width: 280px;
        }

        .logo {
            font-size: 19px;
            font-weight: bold;
            color: #000;
            padding: 0 23px 40px;
            display: flex;
            align-items: center;
            cursor: pointer;
            white-space: nowrap;
        }

        .logo-text {
            opacity: 0;
            transition: opacity 0.1s ease;
            margin-left: 10px;
        }

        .sidebar.active .logo-text {
            opacity: 1;
        }

        .nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-item a {
            display: flex;
            align-items: center;
            font-size: 15px;
            padding: 15px 24px 15px;
            text-decoration: none;
            color: #6C6C6C;
            transition: background-color 0.2s;
            white-space: nowrap;
        }

        .text {
            opacity: 0;
            transition: opacity 0.1s ease;
            margin-left: 5px;
        }

        .sidebar.active .text {
            opacity: 1;
        }

        .nav-item a:hover {
            background-color: #f0f0f0;
        }

        .nav-item.active a {
            color: #000;
            font-weight: bold;
        }

        .nav-icon {
            font-family: 'Material Icons';
            margin-right: 20px;
            font-size: 21px;
            width: 20px;
        }

        .logout {
            margin-top: 260px;
            cursor: pointer;
        }

        .logout a {
            display: flex;
            align-items: center;
            font-size: 15px;
            padding: 15px 24px 15px;
            color: #e94343ff;
            text-decoration: none;
            transition: background-color 0.2s;
            white-space: nowrap;
        }

        .logout a:hover {
            background-color: #f0f0f0;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 30px 32px;
            min-height: 100vh;
            margin-left: 70px;
            transition: margin-left 0.5s ease;
        }

        .main-content.pushed {
            margin-left: 280px;
        }

        /* Header/Welcome Message */
        .header {
            text-align: right;
            padding-bottom: 20px;
            font-size: 16px;
            color: #666;
        }

        .header span {
            font-weight: bold;
            color: #333;
        }

        /* Dashboard Section - Centering/Layout */
        .dashboard-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .dashboard-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-left: 10px;
            margin-bottom: 20px;
            margin-top: -7px;
            align-self: self-start;
        }

        /* Action Cards */
        .action-cards {
            display: flex;
            gap: 30px;
            margin-top: 25px;
            margin-bottom: 35px;
            width: 100%;
            justify-content: center;
            border-radius: 11px;
        }

        .card {
            flex: 1;
            max-width: 218px;
            background-color: #57e4d4ff;
            border-radius: 11px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            min-height: 30px;
            display: flex;
        }

        .card-link {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #333;
            background-color: #57e4d4ff;
            border-radius: 11px;
            font-weight: 550;
            font-size: 16px;
            padding: 25px;
            flex-grow: 2;
            transition: background-color 0.2s, box-shadow 0.2s;
        }

        .card-link:hover {
            background-color: #63d5c8ff;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 11px;
        }

        /* Overview Section */
        .overview-section {
            width: 100%;
            max-width: 960px;
            display: flex;
            justify-content: center;
        }

        .overview-card {
            width: 100%;
            max-width: 960px;
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            margin-bottom: 20px;
        }

        /* --- Overview Metrics and Activity Styles --- */
        .stats-grid {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .stat-box {
            flex: 1;
            padding: 15px 0;
            text-align: center;
            border-left: 1px solid #f0f0f0;
        }

        .stat-box:first-child {
            border-left: none;
        }

        .stat-box h4 {
            font-size: 38px;
            font-weight: 800;
            color: #00A693;
            margin: 0 0 5px 0;
        }

        .stat-box p {
            font-size: 16px;
            color: #6C6C6C;
            margin: 0;
        }

        /* --- Recent Activity Table --- */
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }

        .activity-table th,
        .activity-table td {
            padding: 12px 0;
            text-align: left;
            border-bottom: 1px solid #f9f9f9;
        }

        .activity-table th {
            color: #333;
            font-weight: 600;
        }

        .activity-type-borrowed {
            color: #e5a000; /* Orange/Loan */
            font-weight: 600;
        }

        .activity-type-returned {
            color: #00A693; /* Teal/Success */
            font-weight: 600;
        }

        /* Responsive adjustments for the stats grid */
        @media (max-width: 650px) {
            .stats-grid {
                flex-direction: column;
            }

            .stat-box {
                border-left: none;
                border-bottom: 1px solid #f0f0f0;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div id="sidebar-menu" class="sidebar">
            <div class="logo" onclick="toggleSidebar()">
                <span class="hamburger-icon material-icons">menu</span>
                <span class="logo-text">📚 Smart Library</span>
            </div>

            <ul class="nav-list">
                <li class="nav-item active"><a href="staff.php">
                        <span class="nav-icon material-icons">dashboard</span>
                        <span class="text">Dashboard</span>
                    </a></li>
                <li class="nav-item"><a href="borrowing_requests.php">
                        <span class="nav-icon material-icons">rule</span>
                        <span class="text">Borrowing Requests</span>
                    </a></li>
                <li class="nav-item"><a href="returning&clearance.php">
                        <span class="nav-icon material-icons">assignment_turned_in</span>
                        <span class="text">Returns & Clearance</span>
                    </a></li>
                <li class="nav-item"><a href="penalties.php">
                        <span class="nav-icon material-icons">monetization_on</span>
                        <span class="text">Penalties Management</span>
                    </a></li>
                <li class="nav-item"><a href="borrower_status.php">
                        <span class="nav-icon material-icons">person_search</span>
                        <span class="text">Borrower Status</span>
                    </a></li>
            </ul>
            <ul class="logout nav-list">
                <li class="nav-item"><a href="login.php">
                        <span class="nav-icon material-icons">logout</span>
                        <span class="text">Logout</span>
                    </a></li>
            </ul>
        </div>

        <div id="main-content-area" class="main-content">
            <div class="header">
                Welcome, <span><?php echo htmlspecialchars($staff_name); ?></span>
            </div>

            <div class="dashboard-section">
                <h2>Staff's Dashboard</h2>

                <div class="action-cards">
                    <div class="card">
                        <a href="borrowing_requests.php" class="card-link">Manage Requests</a>
                    </div>
                    <div class="card">
                        <a href="returning&clearance.php" class="card-link">Process Returns</a>
                    </div>
                    <div class="card">
                        <a href="penalties.php" class="card-link">Handle Penalties</a>
                    </div>
                    <div class="card">
                        <a href="borrower_status.php" class="card-link">Borrower Lookup</a>
                    </div>
                </div>

                <div class="overview-section">
                    <div class="overview-card">
                        <h3>Operational Overview</h3>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <h4><?php echo $pendingRequests; ?></h4>
                                <p>Pending Borrow Requests</p>
                            </div>
                            <div class="stat-box">
                                <h4><?php echo $outstandingPenalties; ?></h4>
                                <p>Pending Penalties</p>
                            </div>
                        </div>

                        <h3>Recent Loan Activity</h3>

                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th style="width: 120px;">Time</th>
                                    <th>Action</th>
                                    <th>Book / Borrower</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentActivity)): ?>
                                    <?php foreach ($recentActivity as $activity):
                                        $actionClass = strtolower($activity['ActionType']) === 'borrowed' ? 'activity-type-borrowed' : 'activity-type-returned';
                                        $actionText = htmlspecialchars($activity['ActionType']);
                                        $timeFormatted = (new DateTime($activity['ActionTimestamp']))->format('m-d-Y h:i:s');
                                        ?>
                                        <tr>
                                            <td><?php echo $timeFormatted; ?></td>
                                            <td><span
                                                    class="<?php echo $actionClass; ?>"><?php echo $actionText; ?></span>
                                            </td>
                                            <td>
                                                "<?php echo htmlspecialchars($activity['Title']); ?>"
                                                by <?php echo htmlspecialchars($activity['UserName']); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999;">No recent loan activity
                                            recorded.</td>
                                    </tr>
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
            const sidebar = document.getElementById('sidebar-menu');
            const mainContent = document.getElementById('main-content-area');

            sidebar.classList.toggle('active');
            mainContent.classList.toggle('pushed');

            // Store state in local storage
            if (sidebar.classList.contains('active')) {
                localStorage.setItem('sidebarState', 'expanded');
            } else {
                localStorage.setItem('sidebarState', 'collapsed');
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedState = localStorage.getItem('sidebarState');
            const sidebar = document.getElementById('sidebar-menu');
            const mainContent = document.getElementById('main-content-area');

            // Apply saved state only if it exists
            if (savedState === 'expanded') {
                sidebar.classList.add('active');
                mainContent.classList.add('pushed');
            }
        });
    </script>
</body>

</html>