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
$pending_requests = [];
$status_message = '';
$error_type = '';
$staffID = $_SESSION['user_id']; // Staff member processing the request

// --- FUNCTION TO EXECUTE APPROVAL/REJECTION ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $borrowID = filter_var($_POST['borrow_id'] ?? null, FILTER_VALIDATE_INT);
    $bookID = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);
    $action = $_POST['action'];

    if ($borrowID && $bookID) {
        try {
            $pdo->beginTransaction();

            if ($action === 'Approve') {
                // 1. Get the requested BookID and CopyID from the existing Borrow record
                $stmt_get_loan_data = $pdo->prepare("SELECT CopyID FROM Borrow WHERE BorrowID = ?");
                $stmt_get_loan_data->execute([$borrowID]);
                $requested_copyID = $stmt_get_loan_data->fetchColumn();

                // 2. Check for an AVAILABLE COPY in the new Book_Copy table
                $stmt_copy = $pdo->prepare("SELECT Status FROM Book_Copy WHERE CopyID = ?");
                $stmt_copy->execute([$requested_copyID]);
                $copy_status = $stmt_copy->fetchColumn();

                if ($copy_status === 'Available') {

                    // 3. Update the Book_Copy Status
                    $pdo->prepare("UPDATE Book_Copy SET Status = 'Borrowed' WHERE CopyID = ?")
                        ->execute([$requested_copyID]);

                    // 4. Update the Borrow Record Status
                    $pdo->prepare("UPDATE Borrow SET Status = 'Borrowed', ProcessedBy = ? WHERE BorrowID = ? AND Status = 'Reserved'")
                        ->execute([$staffID, $borrowID]);

                    // 5. Log the action
                    $logSql = "INSERT INTO Borrowing_Record (BorrowID, ActionType, ChangedBy) VALUES (?, 'Borrowed', ?)";
                    $pdo->prepare($logSql)->execute([$borrowID, $staffID]);

                    $status_message = "Request #{$borrowID} APPROVED. Book successfully loaned and inventory updated.";
                    $error_type = 'success';

                } else {
                    // Failure: The specific copy requested is no longer available (race condition, or logic error)
                    $status_message = "Error: The specific copy is no longer available for loan.";
                    $error_type = 'error';
                }

            } elseif ($action === 'Reject') {
                // 1. Update Borrow status to 'Rejected' (or 'Cancelled') and set processor
                $pdo->prepare("UPDATE Borrow SET Status = 'Cancelled', ProcessedBy = ? WHERE BorrowID = ? AND Status = 'Reserved'")
                    ->execute([$staffID, $borrowID]);

                // 2. Log the action (Optional: create a new ActionType ENUM 'Rejected' for better tracking)
                $logSql = "INSERT INTO Borrowing_Record (BorrowID, ActionType, ChangedBy) VALUES (?, 'Rejected', ?)";
                $pdo->prepare($logSql)->execute([$borrowID, $staffID]);

                // 3. Update book status if CopiesAvailable was decreased by a prior reservation logic (not needed here, as we assume reservation doesn't touch CopiesAvailable until approval).

                $status_message = "Request #{$borrowID} REJECTED and closed.";
                $error_type = 'error';
            }

            $pdo->commit();

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Borrow Action Error: " . $e->getMessage());
            $status_message = "Transaction failed. Database Error: " . $e->getMessage();
            $error_type = 'error';
        }

        // Redirect to clear POST data and show message
        header("Location: borrowing_requests.php?msg=" . urlencode($status_message) . "&type={$error_type}");
        ob_end_flush();
        exit();
    }
}

// --- 3. Fetch Pending Requests (Refreshed Data) ---
try {
    $sql = "
        SELECT 
            B.BorrowID, B.CopyID,
            BK.Title, BK.ISBN, 
            U.UserID, U.Name AS BorrowerName, U.Role AS BorrowerRole,
            B.BorrowDate,
            -- Get the actual BookID via the Book_Copy table for subsequent actions
            BCPY.BookID, 
            -- Calculate total available copies of this title for display
            (SELECT COUNT(BC.CopyID) FROM Book_Copy BC 
             WHERE BC.BookID = BCPY.BookID AND BC.Status = 'Available') AS CopiesAvailable
        FROM Borrow B
        JOIN Book_Copy BCPY ON B.CopyID = BCPY.CopyID -- Join to copy table
        JOIN Book BK ON BCPY.BookID = BK.BookID        -- Join from copy table to book metadata
        JOIN Users U ON B.UserID = U.UserID
        WHERE B.Status = 'Reserved' 
        ORDER BY B.BorrowDate ASC
    ";

    $stmt = $pdo->query($sql);
    $pending_requests = $stmt->fetchAll();

} catch (PDOException $e) {
    error_log("Borrow Requests Fetch Error: " . $e->getMessage());
    $status_message = "Database Error: Could not load pending requests.";
    $error_type = 'error';
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
    <title>Borrowing Requests</title>

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

        /* Borrow Requests Section */
        .borrowreq-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .borrowreq-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
            margin-top: 30px;
            align-self: self-start;
        }

        /* --- Requests Table Card Styles --- */
        .requests-card {
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            width: 90%;
            max-width: 1100px;
            overflow-x: auto;
        }

        .requests-card h3 {
            font-size: 18px;
            font-weight: 600;
            margin-top: 0;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

        /* Status/Error Box */
        .status-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            width: 100%;
            max-width: 1200px;
            font-weight: 600;
        }

        .status-success {
            background-color: #e8f5e9;
            color: #388e3c;
        }

        .status-error {
            background-color: #ffcdd2;
            color: #d32f2f;
        }

        /* --- Table Styling (Functional & Responsive) --- */
        .requests-table {
            width: 100%;
            min-width: 800px;
            border-collapse: collapse;
            font-size: 14px;
        }

        .requests-table th,
        .requests-table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .requests-table th {
            background-color: #F7FCFC;
            color: #6C6C6C;
            font-weight: 600;
            text-transform: uppercase;
        }

        .requests-table tbody tr:hover {
            background-color: #FAFAFA;
        }

        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 6px 11px;
            border-radius: 15px;
            font-weight: 600;
            font-size: 13px;
        }

        .status-pending {
            background-color: #fff3e0;
            color: #ff9800;
        }

        /* Action Buttons */
        .action-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
            font-size: 14px;
        }

        .approve-btn {
            background-color: #00A693;
            color: white;
            margin-right: 5px;
        }

        .approve-btn:hover {
            background-color: #00897B;
        }

        .reject-btn {
            background-color: #F44336;
            color: white;
        }

        .reject-btn:hover {
            background-color: #D32F2F;
        }

        /* Mobile/Responsive Styles */
        @media screen and (max-width: 768px) {
            .requests-card {
                padding: 15px;
            }

            /* Hide table header on small screens */
            .requests-table thead {
                display: none;
            }

            /* Make table body act like stacked cards */
            .requests-table,
            .requests-table tbody,
            .requests-table tr,
            .requests-table td {
                display: block;
                width: 100%;
            }

            /* Style rows as blocks */
            .requests-table tr {
                margin-bottom: 15px;
                border: 1px solid #eee;
                border-radius: 8px;
            }

            /* Style cells (td) as full-width elements */
            .requests-table td {
                text-align: right;
                padding-left: 50%;
                /* Give space for the pseudo-label */
                position: relative;
                border-bottom: 1px dashed #eee;
            }

            /* Create labels for mobile view using the column headers */
            .requests-table td:before {
                content: attr(data-label);
                position: absolute;
                left: 10px;
                font-weight: 600;
                color: #6C6C6C;
            }

            /* Adjust action button grouping */
            .requests-table td:last-child {
                text-align: center;
                padding-left: 15px;
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
                <li class="nav-item"><a href="staff.php">
                        <span class="nav-icon material-icons">dashboard</span>
                        <span class="text">Dashboard</span>
                    </a></li>
                <li class="nav-item active"><a href="borrowing_requests.php">
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

            <div class="borrowreq-section">
                <h2>Manage Borrowing Requests</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>"
                        style="align-self: flex-start;">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="requests-card">
                    <h3>Pending Requests (<?php echo count($pending_requests); ?>)</h3>

                    <?php if (empty($pending_requests)): ?>
                        <p style="text-align: center; color: #6C6C6C; padding: 30px;">
                            🎉 No pending requests.
                        </p>
                    <?php else: ?>
                        <table class="requests-table">
                            <thead>
                                <tr>
                                    <th>Req. ID</th>
                                    <th>Borrower</th>
                                    <th>Book Title (ISBN)</th>
                                    <th>Date Requested</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_requests as $request): ?>
                                    <tr>
                                        <td data-label="Request ID">#<?php echo htmlspecialchars($request['BorrowID']); ?></td>
                                        <td data-label="Borrower">
                                            <?php echo htmlspecialchars($request['BorrowerName']); ?>
                                            (<small><?php echo htmlspecialchars($request['BorrowerRole']); ?></small>)
                                        </td>
                                        <td data-label="Book Details">
                                            <?php echo htmlspecialchars($request['Title']); ?>
                                            <small style="display: block; color: #999;">(ISBN:
                                                <?php echo htmlspecialchars($request['ISBN']); ?>)</small>
                                            <small style="display: block; color: #00A693;">Available:
                                                <?php echo $request['CopiesAvailable']; ?></small>
                                        </td>
                                        <td data-label="Date Requested">
                                            <?php echo (new DateTime($request['BorrowDate']))->format('M d, Y'); ?>
                                        </td>
                                        <td data-label="Status"><span class="status-badge status-pending">Pending</span></td>
                                        <td data-label="Actions">
                                            <form method="POST" style="display: inline-block;">
                                                <input type="hidden" name="borrow_id"
                                                    value="<?php echo htmlspecialchars($request['BorrowID']); ?>">
                                                <input type="hidden" name="book_id"
                                                    value="<?php echo htmlspecialchars($request['BookID']); ?>">
                                                <button type="submit" name="action" value="Approve"
                                                    class="action-btn approve-btn">Approve</button>
                                                <button type="submit" name="action" value="Reject"
                                                    class="action-btn reject-btn">Reject</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
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