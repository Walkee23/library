<?php
// CRITICAL: Start Output Buffering
ob_start();
session_start();

// Authentication check: Must be Staff
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Staff') {
    header("Location: " . BASE_URL . "/views/login.php");
    ob_end_flush();
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$staff_name = $_SESSION['name'] ?? 'Staff';
$penalties = [];
$status_message = '';
$error_type = '';
$staffID = $_SESSION['user_id'];

// Search and Filter variables
$search_term = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'Pending'); // Default filter to Pending

// --- FUNCTION TO HANDLE COLLECT/WAIVE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['penalty_id'])) {
    $penaltyID = filter_var($_POST['penalty_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';

    if ($penaltyID) {
        try {
            $pdo->beginTransaction();

            if ($action === 'Collect') {
                // 1. Update Penalty Status to Paid and set PaidDate
                $pdo->prepare("UPDATE Penalty SET Status = 'Paid', PaidDate = CURRENT_TIMESTAMP() WHERE PenaltyID = ? AND Status = 'Pending'")
                    ->execute([$penaltyID]);
                
                // 2. Insert a record into the Payment table
                $pdo->prepare("INSERT INTO Payment (UserID, PenaltyID, Amount, Status) 
                               VALUES (?, ?, (SELECT AmountDue FROM Penalty WHERE PenaltyID = ?), 'Completed')")
                    ->execute([$staffID, $penaltyID, $penaltyID]);

                $status_message = "Penalty #{$penaltyID} collected successfully and marked PAID.";
                $error_type = 'success';

            } elseif ($action === 'Waive') {
                // 1. Update Penalty Status to Waived
                $pdo->prepare("UPDATE Penalty SET Status = 'Waived', PaidDate = CURRENT_TIMESTAMP() WHERE PenaltyID = ? AND Status = 'Pending'")
                    ->execute([$penaltyID]);
                
                $status_message = "Penalty #{$penaltyID} waived and closed.";
                $error_type = 'error'; // Use error/warning color for closure of liability
            }
            
            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Penalty Action Error: " . $e->getMessage());
            $status_message = "Transaction failed. Database Error.";
            $error_type = 'error';
        }
        
        // Redirect to clear POST data and show message, preserving search/filter
        $params = '?msg=' . urlencode($status_message) . '&type=' . $error_type . '&status=' . urlencode($status_filter) . '&search=' . urlencode($search_term);
        header("Location: penalties.php" . $params);
        ob_end_flush();
        exit();
    }
}

// --- 2. FETCH PENALTIES (Dynamic Query) ---
try {
    $sql = "
        SELECT 
            P.PenaltyID, P.AmountDue, P.Status, P.IssuedDate,
            U.Name AS BorrowerName, U.Role AS BorrowerRole,
            BK.Title, BK.ISBN
        FROM Penalty P
        JOIN Users U ON P.UserID = U.UserID
        JOIN Borrow BO ON P.BorrowID = BO.BorrowID
        -- FIX: Join Book via the CopyID's BookID
        JOIN Book_Copy BCPY ON BO.CopyID = BCPY.CopyID 
        JOIN Book BK ON BCPY.BookID = BK.BookID
        WHERE 1=1
    ";

    $params = [];
    $is_search = !empty($search_term);
    
    // Apply Status Filter
    if ($status_filter !== 'All') {
        $sql .= " AND P.Status = :status";
        $params[':status'] = $status_filter;
    }
    
    // Apply Search Filter
    if ($is_search) {
        // Use prepared statements securely for the search term
        $sql .= " AND (U.Name LIKE :search OR BK.Title LIKE :search OR BK.ISBN LIKE :search)";
        $params[':search'] = '%' . $search_term . '%';
    }

    $sql .= " ORDER BY P.IssuedDate DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $penalties = $stmt->fetchAll();
    
    // Handle message after redirect
    if (isset($_GET['msg'])) {
        $status_message = htmlspecialchars($_GET['msg']);
        $error_type = htmlspecialchars($_GET['type'] ?? 'success');
    }

} catch (PDOException $e) {
    error_log("Penalties Fetch Error: " . $e->getMessage());
    $status_message = "Database Error: Could not load penalty list.";
    $error_type = 'error';
}
?>
<?php ob_end_flush(); ?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Penalties Management</title>

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

        /* --- Collapsible Sidebar (Fixed Anchor) --- */
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
            padding: 30px 42px;
            min-height: 80vh;
            margin-left: 70px;
            transition: margin-left 0.5s ease;
        }

        .main-content.pushed {
            margin-left: 280px;
        }

        /* Penalties Section */
        .penalties-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        
        .penalties-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
            margin-top: 30px;
            align-self: self-start;
        }

        /* --- Penalties Card Styles --- */
        .penalties-card {
            margin-top: 20px;
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 100%; 
            max-width: 1100px;
            overflow-x: auto;
        }
        
        .search-form {
            display: flex;
            gap: 20px;
            margin-bottom: 25px;
            margin-top: 15px;
            align-self: flex-start;
            width: 100%;
            max-width: 900px;
        }

        /* Wrapper for Search Input and Button */
        .search-input-wrapper {
            position: relative;
            flex-grow: 1;
            display: flex;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: 6px;
        }
        
        .form-input, .form-select {
            padding: 5px 15px;
            border: 2px solid #ccc;
            box-sizing: border-box;
            font-size: 16px;
            height: 43px;
            transition: border-color 0.2s;
            background-color: #fff;
            color: #333;
        }
        .form-input:focus, .form-select:focus {
            border-color: #00A693;
            outline: none;
            box-shadow: 0 0 0 0px #00A693;
        }

        .search-input {
            width: 100%;
            max-width: none; /* Let flex control width */
            border-radius: 6px 0 0 6px;
            border-right: none; /* Connects input visually to the button */
        }

        .form-select {
            width: 180px; /* Fixed width for filter dropdown */
            border-radius: 6px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05); /* Shadow on its own element */
            flex-shrink: 0;
        }
        
        .search-button {
            background-color: #00a89d;
            color: white;
            padding: 0 18px; /* Vertical padding is controlled by height */
            border: none;
            border-radius: 0 6px 6px 0; /* Right side rounded */
            cursor: pointer;
            font-weight: bold;
            font-size: 18px;
            transition: background-color 0.2s;
            height: 43px; /* Match input height */
        }
        
        .search-button:hover {
            background-color: #00897b;
        }

        /* Table Styling */
        .penalties-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            font-size: 15px;
            margin-bottom: 15px;
        }

        .penalties-table th, .penalties-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .penalties-table th {
            background-color: #F7FCFC;
            color: #6C6C6C;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .penalties-table tbody tr:hover {
            background-color: #FAFAFA;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 14px;
        }

        .status-pending {
            background-color: #fff3e0;
            color: #ff9800; /* Amber */
        }
        
        .status-paid {
            background-color: #e8f5e9;
            color: #4CAF50; /* Green */
        }
        
        .status-cleard {
            background-color: #fce4ec;
            color: #e91e63; /* Pink/Reddish */
        }
        
        .status-replacement {
            color: #d32f2f;
            font-weight: bold;
        }

        /* Action Buttons */
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: bold;
            transition: background-color 0.2s;
            font-size: 13px;
            margin-right: 5px;
        }

        .collect-btn {
            background-color: #00bcd4;
            color: white;
        }
        .collect-btn:hover {
            background-color: #0097a7;
        }

        .waive-btn {
            background-color: #9e9e9e;
            color: white;
        }
        .waive-btn:hover {
            background-color: #757575;
        }

        /* Status/Error Box */
        .status-box {
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px;
            width: 100%; 
            max-width: 1100px;
            font-weight: 600;
            align-self: flex-start; 
        }
        .status-success {
            background-color: #e8f5e9; 
            color: #388e3c;
        }
        .status-error {
            background-color: #ffcdd2; 
            color: #d32f2f;
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
                <li class="nav-item"><a href="staff.php">
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
                <li class="nav-item active"><a href="penalties.php">
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

            <div class="penalties-section">
                <h2>Handle Book Penalties</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="penalties-card">
                    <form method="GET" action="penalties.php" class="search-form">
                        
                        <div class="search-input-wrapper">
                            <input type="text" name="search" class="search-input form-input" 
                                placeholder="Search by Borrower, Book Title, or ISBN..." 
                                value="<?php echo htmlspecialchars($search_term); ?>">
                            
                            <button type="submit" class="search-button" title="Search">
                                <span class="material-icons">search</span>
                            </button>
                        </div>
                        
                        <select name="status" class="form-select" onchange="this.form.submit()">
                            <option value="All" <?php echo $status_filter === 'All' ? 'selected' : ''; ?>>Filter: All Statuses</option>
                            <option value="Pending" <?php echo $status_filter === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="Paid" <?php echo $status_filter === 'Paid' ? 'selected' : ''; ?>>Paid</option>
                            <option value="Waived" <?php echo $status_filter === 'Waived' ? 'selected' : ''; ?>>Waived</option>
                        </select>
                    </form>
                    
                    <table class="penalties-table">
                        <thead>
                            <tr>
                                <th>Liability ID</th>
                                <th>Borrower</th>
                                <th>Book Title (ISBN)</th>
                                <th>Amount Due</th>
                                <th>Issued Date</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($penalties)): ?>
                                <?php foreach ($penalties as $penalty): 
                                    $statusClass = strtolower($penalty['Status']);
                                    $isPending = $penalty['Status'] === 'Pending';
                                ?>
                                    <tr>
                                        <td>#<?php echo htmlspecialchars($penalty['PenaltyID']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($penalty['BorrowerName']); ?> 
                                            (<small><?php echo htmlspecialchars($penalty['BorrowerRole']); ?></small>)
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($penalty['Title']); ?>
                                            <small style="display: block; color: #999;">(ISBN: <?php echo htmlspecialchars($penalty['ISBN']); ?>)</small>
                                        </td>
                                        <td class="status-replacement">₱<?php echo number_format($penalty['AmountDue'], 2); ?></td>
                                        <td><?php echo (new DateTime($penalty['IssuedDate']))->format('M d, Y'); ?></td>
                                        <td><span class="status-badge status-<?php echo $statusClass; ?>"><?php echo htmlspecialchars($penalty['Status']); ?></span></td>
                                        <td>
                                            <?php if ($isPending): ?>
                                                <form method="POST" style="display: inline-block;">
                                                    <input type="hidden" name="penalty_id" value="<?php echo htmlspecialchars($penalty['PenaltyID']); ?>">
                                                    <button type="submit" name="action" value="Collect" class="action-btn collect-btn">Collect Fee</button>
                                                    <button type="submit" name="action" value="Waive" class="action-btn waive-btn">Waive</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: #999; padding: 20px;">No penalties found.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
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