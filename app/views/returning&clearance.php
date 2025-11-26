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
$staffID = $_SESSION['user_id'];
$loan_details = null; 
$identifier = trim($_GET['book_identifier'] ?? ''); 

// --- FUNCTIONS ---

/**
 * Fetches the currently active loan record based on the book's ISBN.
 * This query is complex as it must join across Book_Copy to get the Book metadata.
 */
function getActiveLoanDetails($pdo, $identifier) {
    $sql = "
        SELECT 
            BO.BorrowID, BO.UserID, BO.CopyID, BO.BorrowDate, BO.DueDate, BO.Status AS LoanStatus,
            BK.Title, BK.ISBN, BK.Price, 
            U.Name AS BorrowerName, U.Role AS BorrowerRole
        FROM Book BK
        JOIN Book_Copy BCPY ON BK.BookID = BCPY.BookID
        JOIN Borrow BO ON BCPY.CopyID = BO.CopyID
        JOIN Users U ON BO.UserID = U.UserID
        WHERE BK.ISBN = ? AND BO.Status = 'Borrowed'
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$identifier]);
    return $stmt->fetch();
}

// Function to calculate penalty fee based on your strict rules (Unreturned = Full Price)
function calculatePenalty($loan) {
    if (!$loan) return ['amount' => 0.00, 'is_overdue' => false, 'status' => 'Cleared'];
    
    $dueDate = new DateTime($loan['DueDate']);
    $today = new DateTime();
    
    // Check for late return based on strict rules
    if ($today > $dueDate) {
        return [
            'amount' => (float)$loan['Price'], // Full replacement cost
            'is_overdue' => true,
            'status' => 'Pending Payment'
        ];
    }
    
    // Early or On-Time return
    return ['amount' => 0.00, 'is_overdue' => false, 'status' => 'Cleared'];
}


// ===========================================
// 1. HANDLE POST (FINALIZE RETURN & CLEARANCE)
// ===========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'finalize') {
    // CRITICAL: Now collecting CopyID instead of BookID
    $borrowID = filter_var($_POST['borrow_id'] ?? null, FILTER_VALIDATE_INT);
    $copyID = filter_var($_POST['copy_id'] ?? null, FILTER_VALIDATE_INT); 
    $userID = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
    
    $finalPenalty = filter_var($_POST['penalty_amount'] ?? 0.00, FILTER_VALIDATE_FLOAT);
    $paymentStatus = trim($_POST['payment_status'] ?? '');
    $condition = trim($_POST['condition'] ?? 'good');

    if ($borrowID && $copyID && $userID) {
        try {
            $pdo->beginTransaction();
            $returnDate = date('Y-m-d H:i:s');
            
            // 1. Update the Borrow Record: Set ReturnDate and Status
            $pdo->prepare("UPDATE Borrow SET Status = 'Returned', ReturnDate = ?, ProcessedBy = ? WHERE BorrowID = ? AND CopyID = ? AND Status = 'Borrowed'")
                ->execute([$returnDate, $staffID, $borrowID, $copyID]);

            // 2. Update the Book_Copy Status (Mark the specific physical item as available/damaged)
            // NOTE: Condition handling is simplified. 'major_damage' copies should be marked 'Damaged'
            $newCopyStatus = ($condition === 'major_damage') ? 'Damaged' : 'Available';
            
            $pdo->prepare("UPDATE Book_Copy SET Status = ? WHERE CopyID = ?")
                ->execute([$newCopyStatus, $copyID]);
                
            // 3. Handle Penalties (If the calculated fee is greater than zero)
            if ($finalPenalty > 0.00) {
                $penaltyStatus = ($paymentStatus === 'Paid') ? 'Paid' : 'Pending';
                
                // If a fee is due, we create a Penalty record
                $pdo->prepare("INSERT INTO Penalty (BorrowID, UserID, AmountDue, Status) VALUES (?, ?, ?, ?)")
                    ->execute([$borrowID, $userID, $finalPenalty, $penaltyStatus]);
            }
            
            // 4. Log the Return Action
            $logSql = "INSERT INTO Borrowing_Record (BorrowID, ActionType, ChangedBy) VALUES (?, 'Returned', ?)";
            $pdo->prepare($logSql)->execute([$borrowID, $staffID]);

            $pdo->commit();
            $status_message = "Return finalized! Copy marked as {$newCopyStatus}. Penalty status: {$paymentStatus}.";
            $error_type = 'success';

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Clearance Transaction Error: " . $e->getMessage());
            $status_message = "Transaction Failed: Could not finalize return.";
            $error_type = 'error';
        }
        
        // Redirect to clear POST data and show message
        header("Location: returning&clearance.php?msg=" . urlencode($status_message) . "&type={$error_type}");
        ob_end_flush();
        exit();
    }
}


// ===========================================
// 2. HANDLE GET (SEARCH LOOKUP)
// ===========================================
if (!empty($identifier)) {
    // We now use the identifier (ISBN) to find a currently borrowed copy
    $loan_details = getActiveLoanDetails($pdo, $identifier);

    if ($loan_details) {
        // Calculate dynamic penalty details
        $penalty_info = calculatePenalty($loan_details);
        $loan_details['PenaltyAmount'] = $penalty_info['amount'];
        $loan_details['IsOverdue'] = $penalty_info['is_overdue'];
        $loan_details['PenaltyStatusText'] = $penalty_info['status'];

        $status_message = "Active loan found for ISBN: " . htmlspecialchars($identifier);
        $error_type = 'success';
    } else {
        $status_message = "No active loan found for identifier: " . htmlspecialchars($identifier) . ". (Check for typos or if the book has already been returned).";
        $error_type = 'error';
    }
}

// 3. Handle Message Display on GET Request (after redirect)
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
    <title>Returning & Clearance</title>

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

        /* Return and Clearance Section */
        .return-clearance-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .return-clearance-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
            margin-top: 30px;
            align-self: self-start;
        }

        /* --- Two-column layout for forms/info --- */
        .info-cards {
            display: flex;
            gap: 30px;
            width: 100%;
            max-width: 1000px;
            flex-wrap: wrap; /* Responsive wrap */
        }

        .card {
            flex: 1;
            min-width: 350px;
            background-color: #fff;
            border-radius: 11px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
        }

        .card-header {
            font-size: 19px;
            font-weight: 600;
            color: #000;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        /* Form Styling */
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            font-size: 16px;
            color: #333;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-input, .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px;
            outline: none;
        }
        
        /* Icon Wrapper for Input */
        .form-input-icon-wrapper {
            position: relative;
        }
        
        .form-input-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 20px;
        }
        
        .form-input, .form-select {
            padding-left: 40px; /* Space for the icon */
        }
        .form-input:focus, .form-select:focus {
            border-color: #00A693; /* Brand color focus */
        }

        /* Action Buttons */
        .action-button {
            width: 100%;
            background-color: #00a89d;
            color: white;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: bold;
            transition: background-color 0.3s;
        }

        .action-button:hover {
            background-color: #00897b;
        }
        
        .clearance-button {
            background-color: #4CAF50; /* Green for success/clearance */
        }
        
        .clearance-button:hover {
            background-color: #388E3C;
        }
        
        /* Status Colors */
        .status-box {
            padding: 15px; 
            margin-bottom: 20px; 
            border-radius: 8px; 
            width: 100%; 
            max-width: 1000px;
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
        
        .overdue-fee {
            color: #d32f2f;
            font-weight: bold;
        }
        
        .cleared-status {
            color: #4CAF50;
            font-weight: bold;
        }

        /* Detail List */
        .detail-list {
            list-style: none;
            padding: 0;
            margin-top: 15px;
        }
        
        .detail-list li {
            padding: 10px 0;
            border-bottom: 1px dashed #eee;
            font-size: 16px;
            display: flex;
            justify-content: space-between;
        }
        
        .detail-list strong {
            display: inline-block;
            width: 120px;
            color: #6C6C6C;
            font-weight: bold;
        }

        .detail-list span {
            font-weight: 600;
            color: #333;
        }
        
        @media (max-width: 800px) {
            .info-cards {
                flex-direction: column;
                gap: 20px;
            }
            .card {
                min-width: 100%;
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
                <li class="nav-item"><a href="borrowing_requests.php">
                        <span class="nav-icon material-icons">rule</span>
                        <span class="text">Borrowing Requests</span>
                    </a></li>
                <li class="nav-item active"><a href="returning&clearance.php">
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

            <div class="return-clearance-section">
                <h2>Book Returns and Clearance</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="info-cards">
                    
                    <div class="card">
                        <div class="card-header">
                            <span class="material-icons" style="color: #00A693; margin-right: 5px;">search</span>
                            1. Scan Book ID / Search Record
                        </div>
                        
                        <form method="GET" action="returning&clearance.php"> 
                            <div class="form-group">
                                <label for="book_identifier" class="form-label">Book ID / ISBN</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">qr_code_scanner</span>
                                    <input type="text" id="book_identifier" name="book_identifier" class="form-input" placeholder="e.g., 9781234567890" required value="<?php echo htmlspecialchars($identifier); ?>">
                                </div>
                            </div>
                            <button type="submit" class="action-button">Search Active Loan</button>
                        </form>
                        
                        <hr style="margin: 30px 0 20px; border: 0; border-top: 1px solid #eee;">
                        
                        <div class="card-header" style="border-bottom: none; margin-bottom: 10px;">
                            Loan & Borrower Details
                        </div>
                        
                        <?php if ($loan_details): ?>
                            <ul class="detail-list">
                                <li><strong>Borrower:</strong> <?php echo htmlspecialchars($loan_details['BorrowerName']); ?></li>
                                <li><strong>Role:</strong> <?php echo htmlspecialchars($loan_details['BorrowerRole']); ?></li>
                                <li><strong>Book Title:</strong> <?php echo htmlspecialchars($loan_details['Title']); ?></li>
                                <li><strong>Book Price:</strong> ₱<?php echo number_format($loan_details['Price'], 2); ?></li>
                                <li><strong>Due Date:</strong> <?php echo (new DateTime($loan_details['DueDate']))->format('M d, Y'); ?></li>
                                <li><strong>Overdue:</strong> 
                                    <span class="<?php echo $loan_details['IsOverdue'] ? 'overdue-fee' : 'cleared-status'; ?>">
                                        <?php echo $loan_details['IsOverdue'] ? 'YES (Late)' : 'No'; ?>
                                    </span>
                                </li>
                            </ul>
                        <?php else: ?>
                            <p style="color: #999; text-align: center;">Scan a book or enter an ISBN to view loan details.</p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="card">
                        <div class="card-header">
                             <span class="material-icons" style="color: #4CAF50; margin-right: 5px;">check_circle</span>
                            2. Finalize Clearance
                        </div>
                        
                        <?php if ($loan_details): ?>
                            <form method="POST" action="returning&clearance.php">
                                <input type="hidden" name="action" value="finalize">
                                <input type="hidden" name="borrow_id" value="<?php echo htmlspecialchars($loan_details['BorrowID']); ?>">
                                <input type="hidden" name="copy_id" value="<?php echo htmlspecialchars($loan_details['CopyID']); ?>"> 
                                <input type="hidden" name="user_id" value="<?php echo htmlspecialchars($loan_details['UserID']); ?>">
                                
                                <div class="form-group">
                                    <label for="condition" class="form-label">Book Condition Upon Return</label>
                                    <div class="form-input-icon-wrapper">
                                        <span class="material-icons form-input-icon">book_online</span>
                                        <select id="condition" name="condition" class="form-select" required>
                                            <option value="good" selected>Good / No Damage</option>
                                            <option value="minor_damage">Minor Damage</option>
                                            <option value="major_damage">Major Damage (Requires Fee)</option>
                                        </select>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="penalty" class="form-label">Calculated Replacement Fee</label>
                                    <div class="form-input-icon-wrapper">
                                        <span class="material-icons form-input-icon">attach_money</span>
                                        <input type="text" id="penalty" name="penalty_display" class="form-input <?php echo $loan_details['PenaltyAmount'] > 0 ? 'overdue-fee' : ''; ?>" 
                                               value="₱<?php echo number_format($loan_details['PenaltyAmount'], 2); ?>" readonly>
                                        <input type="hidden" name="penalty_amount" value="<?php echo $loan_details['PenaltyAmount']; ?>">
                                    </div>
                                </div>
                                
                                <div class="form-group">
                                    <label for="payment_status" class="form-label">Penalty Payment Status</label>
                                    <div class="form-input-icon-wrapper">
                                        <span class="material-icons form-input-icon">payment</span>
                                        <select id="payment_status" name="payment_status" class="form-select" required>
                                            <option value="Cleared" <?php echo $loan_details['PenaltyAmount'] == 0 ? 'selected' : 'disabled'; ?>>Cleared (No Fee)</option>
                                            <option value="Pending Payment" <?php echo $loan_details['PenaltyAmount'] > 0 ? 'selected' : ''; ?>>Pending Payment (Create Penalty)</option>
                                            <option value="Paid">Paid Now</option>
                                        </select>
                                    </div>
                                    <small style="display: block; margin-top: 5px; color: #666;">
                                        Note: If a fee is due, selecting 'Paid Now' finalizes the loan and penalty.
                                    </small>
                                </div>

                                <div class="form-group" style="margin-top: 40px;">
                                    <button type="submit" class="action-button clearance-button">
                                        Finalize Return & Issue Clearance
                                    </button>
                                </div>
                            </form>
                        <?php else: ?>
                            <p style="color: #999; text-align: center; padding-top: 100px;">
                                Search for a book identifier in the left panel to proceed.
                            </p>
                        <?php endif; ?>
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

            if (savedState === 'expanded') {
                sidebar.classList.add('active');
                mainContent.classList.add('pushed');
            }
        });
    </script>
</body>
</html>