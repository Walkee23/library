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
$status_message = '';
$error_type = '';
$search_term = trim($_GET['search'] ?? '');
$borrower = null;
$active_BorrowedBooks = [];
$pending_fees = 0.00;
$clearance_status = 'Cleared';
$overdue_count = 0;

// --- CORE FUNCTIONALITY ---

if (!empty($search_term)) {
    try {
        $search_param = '%' . $search_term . '%';

        // 1. Fetch Borrower Details by ID or Name
        $stmt = $pdo->prepare("SELECT UserID, Name, Role, Email FROM Users WHERE UserID = ? OR Name LIKE ? LIMIT 1");

        // Attempt search by exact ID first
        $stmt->execute([is_numeric($search_term) ? $search_term : 0, $search_param]);
        $borrower = $stmt->fetch();

        // If numeric search failed, try name search again without the ID fallback
        if (!$borrower && !is_numeric($search_term)) {
            $stmt = $pdo->prepare("SELECT UserID, Name, Role, Email FROM Users WHERE Name LIKE ? LIMIT 1");
            $stmt->execute([$search_param]);
            $borrower = $stmt->fetch();
        }

        if ($borrower) {
            $userID = $borrower['UserID'];

            // --- Implement Role-Based Limits ---
            $role = $borrower['Role'];
            if ($role === 'Teacher') {
                $borrower['borrowedbookLimit'] = 'Unlimited'; // Teachers have no numerical limit
            } elseif ($role === 'Student') {
                $borrower['borrowedbookLimit'] = 3; // Students have a limit of 3
            } else {
                $borrower['borrowedbookLimit'] = 'N/A'; // Staff/Librarian have no borrowing privileges or limit
            }

            // 2. Fetch Active BorrowedBooks for the Borrower
            $sql_BorrowedBooks = "
                SELECT 
                    BO.DueDate, BO.BorrowDate, BK.Title, BK.ISBN, BK.Price
                FROM Borrow BO
                JOIN Book_Copy BCPY ON BO.CopyID = BCPY.CopyID
                JOIN Book BK ON BCPY.BookID = BK.BookID
                WHERE BO.UserID = ? AND BO.Status = 'Borrowed'
                ORDER BY BO.DueDate ASC
            ";
            $stmt_BorrowedBooks = $pdo->prepare($sql_BorrowedBooks);
            $stmt_BorrowedBooks->execute([$userID]);
            $active_BorrowedBooks = $stmt_BorrowedBooks->fetchAll();

            // 3. Check Overdue Status and Calculate Total Pending Fees
            $overdue_count = 0;
            $pending_fees = 0.00;
            $today = new DateTime();

            // Check BorrowedBooks for overdue status and calculate liability
            foreach ($active_BorrowedBooks as &$borrowedbook) {
                $dueDate = new DateTime($borrowedbook['DueDate']);
                $borrowedbook['is_overdue'] = $today > $dueDate;

                if ($borrowedbook['is_overdue']) {
                    $overdue_count++;
                    // Strict Penalty Rule: Full book price is the liability until returned/paid
                    $pending_fees += (float) $borrowedbook['Price'];
                }
            }

            // 4. Check for Existing Pending Penalties (Penalties table)
            $stmt_penalties = $pdo->prepare("SELECT SUM(AmountDue) FROM Penalty WHERE UserID = ? AND Status = 'Pending'");
            $stmt_penalties->execute([$userID]);
            $pending_fees += (float) $stmt_penalties->fetchColumn() ?? 0.00;

            // 5. Determine Clearance Status (The Logic Fix)
            if ($pending_fees > 0.00 || $overdue_count > 0) {
                $clearance_status = 'On Hold';
            } else {
                $clearance_status = 'Cleared'; // Correctly defaults to Cleared if no liability exists
            }

            // 6. Assign final data to the borrower array for display
            $borrower['ActiveBorrowedBooks'] = count($active_BorrowedBooks);
            $borrower['PendingFees'] = $pending_fees;
            $borrower['OverdueCount'] = $overdue_count;
            $borrower['ClearanceStatus'] = $clearance_status;

            $error_type = 'success';

        } else {
            $status_message = "Error: Borrower not found.";
            $error_type = 'error';
        }
    } catch (PDOException $e) {
        error_log("Borrower Status Fetch Error: " . $e->getMessage());
        $status_message = "Database Error: Could not retrieve borrower information.";
        $error_type = 'error';
    }
}


// Handle Message Display on GET Request (after redirect)
if (isset($_GET['msg'])) {
    $status_message = htmlspecialchars($_GET['msg']);
    $error_type = htmlspecialchars($_GET['type'] ?? 'success');
}
?>
<?php ob_end_flush(); ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Borrower Status</title>

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

        /* Borrower Status Section */
        .status-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .status-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 25px;
            margin-top: 30px;
            align-self: self-start;
        }

        /* --- Status Card Styles (Enhanced) --- */
        .status-card {
            margin-top: 20px;
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 90%;
            max-width: 1050px;
            overflow-x: auto;
        }

        .card-header {
            font-size: 19px;
            font-weight: 600;
            color: #333;
            margin-bottom: 30px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
        }

        /* Search Form (Modernized) */
        .search-form {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
        }

        .form-input {
            flex-grow: 1;
            padding: 12px 15px;
            border: 2px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            border-color: #00A693;
            outline: none;
        }

        .search-button {
            background-color: #00a89d;
            color: white;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            font-size: 17px;
            transition: background-color 0.2s;
        }

        .search-button:hover {
            background-color: #00897b;
        }

        /* Borrower Information Area */
        .borrower-info {
            padding: 20px 0;
            border-top: 1px solid #eee;
            /* Thinner border */
            display:
                <?php echo $borrower ? 'block' : 'none'; ?>
            ;
            /* Show only if data loaded */
        }

        .info-title {
            font-size: 19px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #333;
            display: flex;
            align-items: center;
        }

        .info-list {
            list-style: none;
            padding: 0;
            display: flex;
            flex-wrap: wrap;
            gap: 15px 50px;
            /* Increased horizontal gap */
        }

        .info-list li {
            width: 45%;
            /* Keeps layout stable */
            min-width: 250px;
            font-size: 16px;
            line-height: 1.5;
        }

        .info-list strong {
            display: block;
            font-weight: 500;
            color: #6C6C6C;
        }

        .status-clear {
            color: #4CAF50;
            font-weight: 700;
        }

        .status-hold {
            color: #d32f2f;
            font-weight: 700;
        }

        /* Overdue Text */
        .overdue-text {
            color: #d32f2f;
            font-weight: 600;
        }

        /* Currently Borrowed Table (Improved readability) */
        .borrowed-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            font-size: 15px;
        }

        .borrowed-table th,
        .borrowed-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
        }

        .borrowed-table th {
            background-color: #F7FCFC;
            color: #6C6C6C;
            font-weight: 600;
            text-transform: uppercase;
        }

        .overdue-row td {
            background-color: #ffcdd2;
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
                <li class="nav-item"><a href="penalties.php">
                        <span class="nav-icon material-icons">monetization_on</span>
                        <span class="text">Penalties Management</span>
                    </a></li>
                <li class="nav-item active"><a href="borrower_status.php">
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

            <div class="status-section">
                <h2>Borrower Status Lookup</h2>

                <div class="status-card">
                    <div class="card-header">
                        <span class="material-icons" style="color: #00A693; margin-right: 10px;">search</span>
                        Search Borrower Record
                    </div>

                    <form class="search-form" method="GET" action="borrower_status.php">
                        <input type="text" name="search" class="form-input" placeholder="Enter Borrower ID or Name..."
                            required value="<?php echo htmlspecialchars($search_term); ?>">
                        <button type="submit" class="search-button">Lookup Status</button>
                    </form>

                    <?php if (!empty($status_message)): ?>
                        <p
                            style="font-size: 15px; font-weight: 600; color: <?php echo $error_type === 'success' ? '#00A693' : '#d32f2f'; ?>; margin-bottom: 10px;">
                            <?php echo htmlspecialchars($status_message); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($borrower): ?>
                        <div class="borrower-info">
                            <div class="info-title">
                                <span class="material-icons" style="margin-right: 8px;">account_circle</span>
                                Borrower: <?php echo htmlspecialchars($borrower['Name']); ?>
                            </div>

                            <ul class="info-list">
                                <li>
                                    <strong>Role:</strong> <span><?php echo htmlspecialchars($borrower['Role']); ?></span>
                                </li>
                                <li>
                                    <strong>Borrow Limit:</strong>
                                    <span><?php echo htmlspecialchars($borrower['borrowedbookLimit']); ?>
                                        <?php echo $borrower['Role'] !== 'Teacher' ? 'Books' : ''; ?></span>
                                </li>
                                <li>
                                    <strong>Active Borrowed Books:</strong>
                                    <span><?php echo htmlspecialchars($borrower['ActiveBorrowedBooks']); ?></span>
                                </li>
                                <li>
                                    <strong>Clearance Status:</strong>
                                    <span
                                        class="<?php echo $borrower['ClearanceStatus'] === 'On Hold' ? 'status-hold' : 'status-clear'; ?>">
                                        <?php echo htmlspecialchars($borrower['ClearanceStatus']); ?>
                                    </span>
                                </li>
                                <li>
                                    <strong>Overdue Books:</strong>
                                    <span>
                                        <?php echo $borrower['OverdueCount'] > 0 ? htmlspecialchars($borrower['OverdueCount']) : '0'; ?>
                                    </span>
                                </li>
                                <li>
                                    <strong>Pending Fees:</strong>
                                    <span
                                        class="<?php echo $borrower['PendingFees'] > 0 ? 'overdue-text' : 'status-clear'; ?>">
                                        ₱<?php echo number_format($borrower['PendingFees'], 2); ?>
                                    </span>
                                </li>
                            </ul>
                        </div>

                        <div class="info-title" style="margin-top: 30px;">Currently Borrowed Books</div>
                        <table class="borrowed-table">
                            <thead>
                                <tr>
                                    <th>Book Title</th>
                                    <th>ISBN</th>
                                    <th>Date Borrowed</th>
                                    <th>Due Date</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($active_BorrowedBooks)): ?>
                                    <?php foreach ($active_BorrowedBooks as $borrowedbook):
                                        $is_overdue_class = $borrowedbook['is_overdue'] ? 'overdue-row' : '';
                                        $status_text = $borrowedbook['is_overdue'] ? 'Overdue' : 'On Time';
                                        ?>
                                        <tr class="<?php echo $is_overdue_class; ?>">
                                            <td><?php echo htmlspecialchars($borrowedbook['Title']); ?></td>
                                            <td><?php echo htmlspecialchars($borrowedbook['ISBN']); ?></td>
                                            <td><?php echo (new DateTime($borrowedbook['BorrowDate']))->format('M d, Y'); ?></td>
                                            <td><?php echo (new DateTime($borrowedbook['DueDate']))->format('M d, Y'); ?></td>
                                            <td><span
                                                    class="<?php echo $borrowedbook['is_overdue'] ? 'overdue-text' : ''; ?>"><?php echo $status_text; ?></span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="5" style="text-align: center; color: #999;">No active borrowed books.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p style="text-align: center; color: #6C6C6C; padding: 50px 0;">
                            Use the search bar above to look up a borrower's current status and borrowed books.
                        </p>
                    <?php endif; ?>

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