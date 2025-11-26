<?php
session_start();

// Redirect if user is not logged in or is not a Librarian
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

// --- Initialize Dashboard Variables ---
$librarian_name = $_SESSION['name'] ?? 'Librarian';
$totalBooks = 'N/A';
$copiesAvailable = 'N/A';
$recentActivity = [];

try {
    // 1. Get total distinct books
    $stmt1 = $pdo->query("SELECT COUNT(BookID) AS total FROM Book WHERE Status != 'Archived'");
    $stats1 = $stmt1->fetch();
    $totalBooks = $stats1['total'];

    // 2. Get total copies available
    $stmt2 = $pdo->query("
        SELECT 
            COUNT(BC.CopyID) AS available
        FROM Book_Copy BC
        JOIN Book B ON BC.BookID = B.BookID
        WHERE BC.Status = 'Available' AND B.Status != 'Archived'
    ");
    $stats2 = $stmt2->fetch();
    $copiesAvailable = $stats2['available'] ?? 0;

    // 3. Get recent book management activity from the Management_Log table
    $sql_activity = "
        SELECT 
            ML.Timestamp, 
            ML.ActionType, 
            B.Title, 
            U.Name AS UserName
        FROM Management_Log ML
        LEFT JOIN Book B ON ML.BookID = B.BookID
        JOIN Users U ON ML.UserID = U.UserID
        ORDER BY ML.Timestamp DESC
        LIMIT 5
    ";
    $stmt3 = $pdo->query($sql_activity);
    $recentActivity = $stmt3->fetchAll();

} catch (PDOException $e) {
    // Handle error gracefully
    error_log("Dashboard Activity Error: " . $e->getMessage());
    $error_message = "Could not load book statistics.";
}
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
        /* Global Styles */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #F7FCFC;
            /* Requested background color */
            color: #333;
        }

        /* Layout Container */
        .container {
            display: flex;
            min-height: 100vh;
        }

        /* --- Collapsible sidebar --- */
        .sidebar {
            width: 70px;
            /* Initial Collapsed Width */
            padding: 30px 0;
            background-color: #fff;
            border-right: 1px solid #eee;
            box-shadow: 3px 0 9px rgba(0, 0, 0, 0.05);

            /* CRITICAL FIX: Anchor the sidebar to the viewport */
            position: fixed;
            height: 100vh;
            /* Full height */
            top: 0;
            left: 0;
            z-index: 100;
            /* Stays above content */

            flex-shrink: 0;
            overflow-x: hidden;
            overflow-y: auto;
            transition: width 0.5s ease;
            /* Smooth toggle animation */
            white-space: nowrap;
        }

        .sidebar.active {
            width: 250px;
            /* Expanded Width (Toggled by JS) */
        }

        .logo {
            font-size: 19px;
            font-weight: bold;
            color: #000;
            padding: 0 23px 40px;
            display: flex;
            align-items: center;
            cursor: pointer;
            /* Indicate it's clickable */
            white-space: nowrap;
            /* Prevents logo text wrap/break */
        }

        .logo-text {
            /* Hide text part of logo in collapsed view */
            opacity: 0;
            transition: opacity 0.1s ease;
            margin-left: 10px;
        }

        .sidebar.active .logo-text {
            opacity: 1;
            /* Show text when sidebar is active */
        }

        .nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-item a {
            display: flex;
            /* Use Flex for icon/text alignment */
            align-items: center;
            font-size: 15px;
            padding: 15px 24px 15px;
            text-decoration: none;
            color: #6C6C6C;
            transition: background-color 0.2s;
            white-space: nowrap;
        }

        .text {
            /* Hide text part of logo in collapsed view */
            opacity: 0;
            transition: opacity 0.1s ease;
            margin-left: 5px;
        }

        .sidebar.active .text {
            opacity: 1;
            /* Show text when sidebar is active */
        }

        .nav-item a:hover {
            background-color: #f0f0f0;
            /* Added space for the button on the right */
        }

        .nav-item.active a {
            color: #000;
            font-weight: bold;
        }

        .nav-icon {
            font-family: 'Material Icons';
            margin-right: 20px;
            /* Space between icon and text when expanded */
            font-size: 21px;
            width: 20px;
            /* Fixed width to keep icons aligned */
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
            /* Ensures full scroll height */

            /* CRITICAL FIX: Base margin to match collapsed sidebar width */
            margin-left: 70px;
            transition: margin-left 0.5s ease;
            /* Smoothly push/pull content */
        }

        /* NEW RULE: Pushes the main content when the sidebar is active */
        .main-content.pushed {
            margin-left: 250px;
            /* Margin matches expanded sidebar width */
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

        /* Dashboard Section */
        .dashboard-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            /* CRITICAL FIX: Center the content block horizontally in the available space */
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
            /* CRITICAL FIX: Center the card grouping */
            justify-content: center;
            border-radius: 11px;
        }

        .card {
            flex: 1;
            max-width: 218px;
            background-color: #57e4d4ff;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            min-height: 30px;
            /* To match the image's card height */
            display: flex;
            border-radius: 11px;
        }

        /* Card Link Style (The clickable area) */
        .card-link {
            display: flex;
            align-items: center;
            /* Vertically center content */
            justify-content: center;
            /* Horizontally center content */
            text-decoration: none;
            color: #333;
            background-color: #57e4d4ff;
            border-radius: 11px;
            font-weight: 550;
            font-size: 16px;
            /* Slightly larger text */
            padding: 25px;
            flex-grow: 2;
            /* Makes the link fill the card */
            transition: background-color 0.2s, box-shadow 0.2s;
        }

        /* Hover Effect for Clickable Card */
        .card-link:hover {
            background-color: #63d5c8ff;
            /* Light teal hover color */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            border-radius: 11px;
        }

        .overview-section {
            width: 100%;
            max-width: 960px;
            /* Centers the large overview card itself */
            display: flex;
            justify-content: center;
        }

        .overview-section h3 {
            font-size: 18px;
            font-weight: 600;
            margin-top: 1px;
            margin-bottom: 10px;
        }

        /* Overview Card Style */
        .overview-card {
            /* Remove fixed width/max-width here if necessary, let the wrapper control it */
            width: 100%;
            max-width: 960px;
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            max-width: 960px;
            margin-bottom: 20px;
        }

        /* --- Overview Stats Layout (Responsive) --- */
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

        .activity-table td {
            color: #444;
        }

        .activity-type-borrowed {
            color: #e5a000;
            /* Orange/Pending */
            font-weight: 600;
        }

        .activity-type-returned {
            color: #00A693;
            /* Teal/Success */
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

        /* --- New Activity Log Status Colors --- */
        .activity-type-added {
            color: #00A693;
            /* Teal/Success */
            font-weight: 600;
        }

        .activity-type-updated {
            color: #e5a000;
            /* Amber/Warning */
            font-weight: 600;
        }

        .activity-type-archived {
            color: #777;
            /* Gray/Muted */
            font-weight: 600;
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
                <li class="nav-item active"><a href="librarian.php">
                        <span class="nav-icon material-icons">dashboard</span>
                        <span class="text">Dashboard</span>
                    </a></li>
                <li class="nav-item"><a href="book_inventory.php">
                        <span class="nav-icon material-icons">inventory_2</span>
                        <span class="text">Book Inventory</span>
                    </a></li>
                <li class="nav-item"><a href="add_book.php">
                        <span class="nav-icon material-icons">add_box</span>
                        <span class="text">Add New Book</span>
                    </a></li>
                <li class="nav-item"><a href="update_book.php">
                        <span class="nav-icon material-icons">edit</span>
                        <span class="text">Update Book</span>
                    </a></li>
                <li class="nav-item"><a href="archive_book.php">
                        <span class="nav-icon material-icons">archive</span>
                        <span class="text">Archive Book</span>
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
                Welcome, <span><?php echo htmlspecialchars($_SESSION['name'] ?? '[Librarian]'); ?></span>
            </div>

            <div class="dashboard-section">
                <h2>Librarian's Dashboard</h2>

                <div class="action-cards">
                    <div class="card">
                        <a href="book_inventory.php" class="card-link">Book Inventory</a>
                    </div>
                    <div class="card">
                        <a href="add_book.php" class="card-link">Add Book</a>
                    </div>
                    <div class="card">
                        <a href="update_book.php" class="card-link">Update Book</a>
                    </div>
                    <div class="card">
                        <a href="archive_book.php" class="card-link">Archive Book</a>
                    </div>
                </div>

                <div class="overview-section">
                    <div class="overview-card">
                        <h3>Library Overview</h3>

                        <div class="stats-grid">
                            <div class="stat-box">
                                <h4><?php echo $totalBooks; ?></h4>
                                <p>Total Books</p>
                            </div>
                            <div class="stat-box">
                                <h4><?php echo $copiesAvailable; ?></h4>
                                <p>Copies Available</p>
                            </div>
                        </div>

                        <h3>Recent Book Management Activity</h3>

                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th style="width: 120px;">Time</th>
                                    <th>Action</th>
                                    <th>Book Title</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($recentActivity)): ?>
                                    <?php foreach ($recentActivity as $activity):
                                        // Determine class for visual feedback based on the action type
                                        $actionClass = '';
                                        if ($activity['ActionType'] === 'Added') {
                                            $actionClass = 'activity-type-added';
                                        } elseif ($activity['ActionType'] === 'Updated') {
                                            $actionClass = 'activity-type-updated';
                                        } elseif ($activity['ActionType'] === 'Archived') {
                                            $actionClass = 'activity-type-archived';
                                        }

                                        $timeFormatted = (new DateTime($activity['Timestamp']))->format('m-d-Y h:i:s'); // Full timestamp for precision
                                        ?>
                                        <tr>
                                            <td><?php echo $timeFormatted; ?></td>
                                            <td><span
                                                    class="<?php echo $actionClass; ?>"><?php echo htmlspecialchars($activity['ActionType']); ?></span>
                                            </td>
                                            <td>
                                                "<?php echo htmlspecialchars($activity['Title']); ?>"
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align: center; color: #999;">No recent management
                                            activity recorded.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <script>
                function toggleSidebar() {
                    const sidebar = document.getElementById('sidebar-menu');
                    const mainContent = document.getElementById('main-content-area'); // New ID

                    // 1. Toggle sidebar width
                    sidebar.classList.toggle('active');

                    // 2. Toggle content margin (for the smooth pushing effect)
                    mainContent.classList.toggle('pushed');

                    // Optional: Store state in local storage to remember setting across page reloads
                    if (sidebar.classList.contains('active')) {
                        localStorage.setItem('sidebarState', 'expanded');
                    } else {
                        localStorage.setItem('sidebarState', 'collapsed');
                    }
                }

            </script>
        </div>
    </div>

</body>

</html>