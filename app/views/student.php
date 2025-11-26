<?php
// CRITICAL: Start Output Buffering
ob_start();
session_start();

// Authentication check: Must be Student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: " . BASE_URL . "/views/login.php");
    ob_end_flush();
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$student_name = $_SESSION['name'] ?? 'Student';
$userID = $_SESSION['user_id'];
$borrowedCount = 0;
$reservationCount = 0;
$borrowedbookLimit = 3; // Fixed limit for Students
$clearanceStatus = 'Cleared';
$hasOverdue = false;

try {
    // 1. Get Count of Currently Borrowed Books
    $stmt1 = $pdo->prepare("SELECT COUNT(BorrowID) FROM Borrow WHERE UserID = ? AND Status = 'Borrowed'");
    $stmt1->execute([$userID]);
    $borrowedCount = $stmt1->fetchColumn();

    // 2. Get Count of Active Reservations
    $stmt2 = $pdo->prepare("SELECT COUNT(ReservationID) FROM Reservation WHERE UserID = ? AND Status = 'Active'");
    $stmt2->execute([$userID]);
    $reservationCount = $stmt2->fetchColumn();

    // 3. Check for Overdue Books and Pending Penalties (Affecting Clearance)
    $stmt3 = $pdo->prepare("
        SELECT 
            COUNT(BO.BorrowID) AS OverdueCount,
            (SELECT SUM(AmountDue) FROM Penalty WHERE UserID = ? AND Status = 'Pending') AS PendingFees
        FROM Borrow BO
        WHERE BO.UserID = ? AND BO.Status = 'Borrowed' AND BO.DueDate < NOW()
    ");
    $stmt3->execute([$userID, $userID]);
    $liabilities = $stmt3->fetch();

    if ($liabilities['OverdueCount'] > 0 || $liabilities['PendingFees'] > 0.00) {
        $clearanceStatus = 'On Hold';
        $hasOverdue = $liabilities['OverdueCount'] > 0;
    }

} catch (PDOException $e) {
    error_log("Student Dashboard Error: " . $e->getMessage());
    // Fallback to default values
}
?>
<?php ob_end_flush(); ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student's Dashboard</title>

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
            min-height: 80vh;
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

        .borrow-limit {
            font-size: 0.9em;
            color: #666;
            margin-left: 10px;
            margin-bottom: 40px;
            align-self: self-start;
        }

        /* --- Metric Boxes --- */
        .metric-cards {
            display: flex;
            gap: 20px;
            margin-bottom: 35px;
            width: 100%;
            max-width: 900px;
            flex-wrap: wrap;
        }

        .metric-box {
            flex: 1;
            min-width: 200px;
            background-color: #fff;
            border-radius: 11px;
            padding: 25px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border: 1px solid transparent;
            transition: border 0.3s;
        }

        .metric-box h4 {
            font-size: 40px;
            font-weight: 800;
            margin: 0;
        }

        .metric-box .metric-icon {
            font-size: 32px;
            margin-bottom: 10px;
            align-self: flex-end;
            /* Pushes icon to the top right */
        }

        .metric-box p {
            font-size: 15px;
            color: #6C6C6C;
            margin-top: 5px;
        }

        /* Status Colors */
        .stat-good {
            color: #00A693;
            /* Teal */
        }

        .stat-warn {
            color: #ff9800;
            /* Amber */
        }

        .stat-bad {
            color: #d32f2f;
            /* Red */
        }

        /* --- Action Links (Bottom Cards) --- */
        .action-link-box {
            flex: 1;
            min-width: 200px;
            background-color: #fff;
            border-radius: 11px;
            padding: 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
            transition: box-shadow 0.2s;
        }

        .action-link-box a {
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            color: #00A693;
            font-weight: 600;
            font-size: 17px;
            padding: 20px;
            border-radius: 11px;
            transition: background-color 0.2s;
        }

        .action-link-box a:hover {
            background-color: #f0f8f8;
            /* Light teal background on hover */
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        /* --- WARNING/REMINDER --- */
        .reminder {
            font-size: 16px;
            font-weight: 500;
            color: #d32f2f;
            padding: 15px;
            border: 2px solid #ffcdd2;
            background-color: #ffcdd2;
            border-radius: 8px;
            width: 100%;
            max-width: 900px;
            margin-top: 20px;
        }

        /* Responsive adjustments */
        @media (max-width: 650px) {
            .metric-cards {
                flex-direction: column;
            }

            .metric-box,
            .action-link-box {
                min-width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div id="sidebar-menu" class="sidebar">
            <div class="logo" onclick="toggleSidebar()">
                <span class="nav-icon material-icons">menu</span>
                <span class="logo-text">📚 Smart Library</span>
            </div>

            <ul class="nav-list">
                <li class="nav-item active"><a href="student.php">
                        <span class="nav-icon material-icons">dashboard</span>
                        <span class="text">Dashboard</span>
                    </a></li>
                <li class="nav-item"><a href="student_borrow.php">
                        <span class="nav-icon material-icons">local_library</span>
                        <span class="text">Books</span>
                    </a></li>
                <li class="nav-item"><a href="student_reservation.php">
                        <span class="nav-icon material-icons">bookmark_add</span>
                        <span class="text">Reservations</span>
                    </a></li>
                <li class="nav-item"><a href="studentborrowed_books.php">
                        <span class="nav-icon material-icons">menu_book</span>
                        <span class="text">Borrowed Books</span>
                    </a>
                </li>
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
                Welcome, <span><?php echo htmlspecialchars($student_name); ?></span>
            </div>

            <div class="dashboard-section">
                <h2>Student's Dashboard</h2>
                <div class="borrow-limit">Borrow Limit: <?php echo $borrowedbookLimit; ?> books per semester</div>

                <div class="metric-cards">

                    <div class="metric-box">
                        <span
                            class="material-icons metric-icon stat-<?php echo $borrowedCount >= $borrowedbookLimit ? 'bad' : 'good'; ?>">
                            menu_book
                        </span>
                        <h4 class="<?php echo $borrowedCount >= $borrowedbookLimit ? 'stat-bad' : 'stat-good'; ?>">
                            <?php echo $borrowedCount; ?>
                        </h4>
                        <p>Active Books</p>
                    </div>

                    <div class="metric-box">
                        <span
                            class="material-icons metric-icon stat-<?php echo $reservationCount > 0 ? 'warn' : 'good'; ?>">
                            bookmark
                        </span>
                        <h4 class="<?php echo $reservationCount > 0 ? 'stat-warn' : 'stat-good'; ?>">
                            <?php echo $reservationCount; ?>
                        </h4>
                        <p>Active Reservations</p>
                    </div>

                    <div class="metric-box">
                        <span
                            class="material-icons metric-icon stat-<?php echo $clearanceStatus === 'On Hold' ? 'bad' : 'good'; ?>">
                            <?php echo $clearanceStatus === 'On Hold' ? 'warning' : 'check_circle'; ?>
                        </span>
                        <h4 class="<?php echo $clearanceStatus === 'On Hold' ? 'stat-bad' : 'stat-good'; ?>">
                            <?php echo $clearanceStatus; ?>
                        </h4>
                        <p>Clearance Status</p>
                    </div>
                </div>

                <div class="metric-cards" style="margin-top: 0; margin-bottom: 30px;">
                    <div class="action-link-box">
                        <a href="student_borrow.php">
                            <span class="material-icons" style="margin-right: 10px;">search</span>
                            Borrow Books
                        </a>
                    </div>
                    <div class="action-link-box">
                        <a href="studentborrowed_books.php">
                            <span class="material-icons" style="margin-right: 10px;">assignment_return</span>
                            Return Books
                        </a>
                    </div>
                </div>

                <?php if ($hasOverdue || $clearanceStatus === 'On Hold'): ?>
                    <div class="reminder">
                        ⚠️ **Action Required:** Your clearance is on hold due to overdue items or pending fees. Please settle your liability.
                    </div>
                <?php endif; ?>

                <div class="reminder" style="color: #6C6C6C; background-color: #f0f0f0; border: 2px solid #ddd;">
                    Reminder: You must return all borrowed books at semester end. Unreturned books will be charged at full price.
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