<?php
// CRITICAL: Start Output Buffering
ob_start();
session_start();

// --- Authentication and Setup ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Student') {
    header("Location: " . BASE_URL . "/views/login.php");
    ob_end_flush();
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$student_name = $_SESSION['name'] ?? 'Student';
$userID = $_SESSION['user_id'];
$loanLimit = 3; // Fixed limit for Students

// --- Inventory and Search Setup ---
$books = [];
$search_term = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? 'All'); 
$category_filter = trim($_GET['category'] ?? 'All'); 

// --- Pagination Setup ---
$books_per_page = 4;
$current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT) ?: 1;
$offset = ($current_page - 1) * $books_per_page;
$total_books = 0;
$total_pages = 0;
$query_message = '';
$categories = []; // For dynamic filter dropdown

// --- Fetch User Loan Status (for Borrow Limit Check) ---
$stmt_status = $pdo->prepare("SELECT COUNT(BorrowID) FROM Borrow WHERE UserID = ? AND Status = 'Borrowed'");
$stmt_status->execute([$userID]);
$borrowedCount = $stmt_status->fetchColumn();
$maxedOut = $borrowedCount >= $loanLimit;

try {
    // 1. Fetch unique categories for the filter dropdown
    $categories = $pdo->query("SELECT DISTINCT Category FROM Book WHERE Category IS NOT NULL AND Category != '' ORDER BY Category ASC")->fetchAll(PDO::FETCH_COLUMN);

    // 2. Define the dynamic calculation fields
    $dynamic_fields = "
        B.BookID, B.Title, B.Author, B.ISBN, B.Price, B.CoverImagePath, B.Status, B.Category,
        (SELECT COUNT(BC1.CopyID) FROM Book_Copy BC1 WHERE BC1.BookID = B.BookID) AS CopiesTotal,
        (SELECT COUNT(BC2.CopyID) FROM Book_Copy BC2 WHERE BC2.BookID = B.BookID AND BC2.Status = 'Available') AS CopiesAvailable,
        (SELECT COUNT(R.ReservationID) FROM Reservation R WHERE R.BookID = B.BookID AND R.UserID = {$userID} AND R.Status = 'Active') AS HasActiveReservation
    ";
    
    // 3. Build the Base SQL Query for COUNT and LISTING
    $base_sql = "FROM Book B WHERE B.Status != 'Archived'"; // Exclude archived books

    // Apply Filters (Status and Category)
    if ($status_filter !== 'All') {
        $safe_status = $pdo->quote($status_filter);
        $base_sql .= " AND B.Status = {$safe_status}";
    }
    
    if ($category_filter !== 'All') {
        $safe_category = $pdo->quote($category_filter);
        $base_sql .= " AND B.Category = {$safe_category}";
    }

    $count_sql = "SELECT COUNT(B.BookID) " . $base_sql;
    $list_sql = "SELECT {$dynamic_fields} " . $base_sql;
    
    $is_search = !empty($search_term);

    if ($is_search) {
        // Apply Search (using functional, non-prepared method)
        $search_clause = " AND (B.Title LIKE :search OR B.Author LIKE :search OR B.ISBN LIKE :search)";
        $safe_search = $pdo->quote('%' . $search_term . '%');
        
        $count_sql .= str_replace(':search', $safe_search, $search_clause);
        $list_sql .= str_replace(':search', $safe_search, $search_clause);
        
        $query_message = "Showing results for: '" . htmlspecialchars($search_term) . "'";
    }

    // 4. Fetch Total Count & Calculate Pages
    $total_books = $pdo->query($count_sql)->fetchColumn();
    $total_pages = ceil($total_books / $books_per_page);

    // 5. Finalize List Query with Pagination and Order
    $list_sql .= " ORDER BY B.Title ASC LIMIT {$books_per_page} OFFSET {$offset}";
    
    $stmt = $pdo->query($list_sql);
    $book_inventory = $stmt->fetchAll();
    
    // Pagination safety check remains here...

} catch (PDOException $e) {
    error_log("Student Inventory Query Error: " . $e->getMessage());
    $query_message = "Database Error: Could not load the book catalog.";
}


// --- POST Logic (Student Actions) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book_id'])) {
    $bookID = filter_var($_POST['book_id'], FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $bookTitle = $_POST['book_title'] ?? 'Book';

    try {
        $pdo->beginTransaction();

        if ($action === 'borrow') {
             // Logic to find copy, update status, create borrow record, and log action
             // NOTE: This logic should ideally be handled by the Staff, but for testing student UI:
             
             if ($maxedOut) {
                 $status_message = "Denied: You have reached your borrowing limit of {$loanLimit} books.";
                 $error_type = 'error';
                 $pdo->rollBack();
             } else {
                 $stmt_copy = $pdo->prepare("SELECT CopyID FROM Book_Copy WHERE BookID = ? AND Status = 'Available' LIMIT 1");
                 $stmt_copy->execute([$bookID]);
                 $copyID = $stmt_copy->fetchColumn();

                 if ($copyID) {
                     $dueDate = date('Y-m-d H:i:s', strtotime('+30 days')); 
                     
                     // Use 'Reserved' status for Staff approval
                     $pdo->prepare("INSERT INTO Borrow (UserID, CopyID, DueDate, Status) VALUES (?, ?, ?, 'Reserved')")
                          ->execute([$userID, $copyID, $dueDate]);
                     
                     $status_message = "Success! Borrow request for '{$bookTitle}' submitted for staff approval.";
                     $error_type = 'success';
                     $pdo->commit();
                 } else {
                     $status_message = "Error: Book is out of stock. Try reserving it instead.";
                     $error_type = 'error';
                     $pdo->rollBack();
                 }
             }

        } elseif ($action === 'reserve') {
            // Logic to create reservation
            $expiryDate = date('Y-m-d H:i:s', strtotime('+7 days'));

            $stmt_reserved = $pdo->prepare("SELECT ReservationID FROM Reservation WHERE UserID = ? AND BookID = ? AND Status = 'Active'");
            $stmt_reserved->execute([$userID, $bookID]);
            
            if ($stmt_reserved->fetch()) {
                $status_message = "Error: You already have an active reservation for '{$bookTitle}'.";
                $error_type = 'error';
                $pdo->rollBack();
            } else {
                $pdo->prepare("INSERT INTO Reservation (UserID, BookID, ExpiryDate, Status) VALUES (?, ?, ?, 'Active')")
                    ->execute([$userID, $bookID, $expiryDate]);
                
                $status_message = "Success! '{$bookTitle}' reserved until " . date('M d, Y', strtotime($expiryDate)) . ".";
                $error_type = 'success';
                $pdo->commit();
            }
        }
        
        // Redirect to clear POST data and show message
        header("Location: student_borrow.php?msg=" . urlencode($status_message) . "&type={$error_type}");
        ob_end_flush();
        exit();

    } catch (PDOException $e) {
        // Rollback transaction on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Borrow/Reserve Error: " . $e->getMessage());
        $status_message = "System Error: Your request could not be processed.";
        $error_type = 'error';
        
        // Redirect with error message
        header("Location: student_borrow.php?msg=" . urlencode($status_message) . "&type={$error_type}");
        ob_end_flush();
        exit();
    }
} // <--- This closing brace was missing for the if($_SERVER...POST) block


    // Handle Message Display on GET Request (after redirect)


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
    <title>Browse and Borrow Books</title>

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
        
        .text {
            opacity: 0;
            transition: opacity 0.1s ease;
            margin-left: 5px;
        }

        .sidebar.active .text {
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

        .header {
            text-align: right;
            padding-bottom: 20px;
            font-size: 16px;
            color: #666;
        }

        /* Inventory Section */
        .inventory-section {
            width: 100%;
        }

        .inventory-section h2 {
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 7px;
            margin-top: 0;
        }

        .inventory-section p.subtitle {
            font-size: 15px;
            color: #666;
            margin-bottom: 30px;
        }

        /* --- Search Bar & Filters --- */
        .search-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 40px;
            width: 100%;
            max-width: 900px;
            flex-wrap: wrap;
        }
        .search-input-wrapper {
            position: relative;
            flex-grow: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            border-radius: 8px;
            background-color: #fff;
            display: flex;
        }
        .search-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px 0 0 8px;
            font-size: 16px;
            color: #333;
            border-right: none;
            transition: border-color 0.2s;
        }
        .search-btn-icon {
            padding: 0 18px;
            border: none;
            background-color: #00A693;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            border-radius: 0 8px 8px 0;
            transition: background-color 0.2s;
            display: flex;
            align-items: center;
        }
        .search-btn-icon:hover {
            background-color: #00897B;
        }
        
        /* --- Book Card Display --- */
        .book-list {
            display: flex;
            gap: 23px;
            flex-wrap: wrap;
            width: 100%;
            max-width: 900px;
        }

        .book-card {
            width: 320px;
            height: 220px;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            padding: 15px;
            box-sizing: border-box;
            transition: transform 0.2s;
        }
        .book-card:hover {
             transform: translateY(-3px);
             box-shadow: 0 6px 15px rgba(0, 0, 0, 0.1);
        }

        .book-cover-area {
            width: 110px;
            height: 190px;
            border-radius: 8px;
            margin-right: 15px;
            flex-shrink: 0;
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            background-color: #F0F8F8;
            display: flex;
            justify-content: center;
            align-items: center;
            color: #999;
            font-size: 12px;
        }

        .book-details {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-grow: 1;
            padding: 5px 0;
        }

        .book-title {
            font-size: 17px;
            font-weight: 600;
            line-height: 1.3;
            margin: 5px 0 3px 0;
        }
        .book-author {
            font-size: 14px;
            color: #666;
            margin: 0;
        }
        
        .stock-available { font-weight: 700; color: #00A693; }
        .stock-low { font-weight: 700; color: #ff9800; }
        .stock-reserved-count { font-size: 13px; color: #666; }

        /* Action Buttons/Tags */
        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            text-decoration: none;
            border: none;
            width: 100%; 
            min-height: 40px;
            box-sizing: border-box;
            font-size: 15px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .borrow-button { background-color: #00A693; color: #fff; }
        .reserve-button { background-color: #ff9800; color: #fff; }
        .action-button:disabled { background-color: #ddd; color: #666; cursor: not-allowed; }
        
        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000; 
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0, 0, 0, 0.6);
            justify-content: center;
            align-items: center;
        }

        .modal-content {
            background-color: #fff;
            margin: auto;
            padding: 30px;
            border-radius: 10px;
            width: 90%;
            max-width: 400px;
            box-shadow: 0 5px 25px rgba(0, 0, 0, 0.4);
            text-align: center;
        }
        .modal-buttons button {
            padding: 10px 20px;
            margin: 0 10px;
            border: none;
            border-radius: 5px;
            font-weight: 600;
            cursor: pointer;
        }
        .confirm-btn { background-color: #00A693; color: #fff; }
        .cancel-btn { background-color: #ddd; color: #333; }
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
                <li class="nav-item"><a href="student.php">
                        <span class="nav-icon material-icons">dashboard</span>
                        <span class="text">Dashboard</span>
                    </a></li>
                <li class="nav-item active"><a href="student_borrow.php">
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

            <div class="inventory-section">
                <h2>Browse and Request Books</h2>
                <p class="subtitle">Search the catalog and request loans or make reservations. (<?php echo $borrowedCount; ?>/<?php echo $loanLimit; ?> books borrowed)</p>
                
                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo $error_type === 'success' ? 'status-success' : 'status-error'; ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <form method="GET" action="student_borrow.php" class="search-filters" style="max-width: 100%;">
                    <div class="search-input-wrapper">
                        <input type="text" name="search" class="search-input" placeholder="Search by Title or Author..." 
                               value="<?php echo htmlspecialchars($search_term); ?>">
                        <button type="submit" class="search-btn-icon">
                            <span class="material-icons">search</span>
                        </button>
                    </div>
                </form>

                <div class="book-list">
                    <?php if (empty($book_inventory)): ?>
                        <p style="width: 100%; color: #999; text-align: center;">No books found in the catalog matching your search.</p>
                    <?php else: ?>
                        <?php 
                        foreach ($book_inventory as $book): 
                            $isAvailable = $book['CopiesAvailable'] > 0;
                            $stockText = $isAvailable ? "{$book['CopiesAvailable']} copies" : "Out of Stock";
                            $stockClass = $isAvailable ? 'stock-available' : 'stock-low';
                            $buttonAction = $isAvailable ? 'Borrow' : 'Reserve';
                            $buttonClass = $isAvailable ? 'borrow-button' : 'reserve-button';
                            $isDisabled = $maxedOut; 
                            
                            // Check if student already has an active reservation for this book (cannot reserve twice)
                            $hasActiveReservation = $book['HasActiveReservation'] > 0;

                            if($hasActiveReservation) {
                                $buttonAction = 'Reserved';
                                $buttonClass = 'reserved-tag';
                                $isDisabled = true;
                            }
                        ?>
                        <div class="book-card">
                             <div class="book-cover-area" style="<?php if($book['CoverImagePath']) echo "background-image: url('".BASE_URL."/".htmlspecialchars($book['CoverImagePath'])."');"; ?>">
                                <?php if(empty($book['CoverImagePath'])) echo "No Cover"; ?>
                            </div>
                            
                            <div class="book-details">
                                <div>
                                    <div class="book-title"><?php echo htmlspecialchars($book['Title']); ?></div>
                                    <div class="book-author">By: <?php echo htmlspecialchars($book['Author']); ?></div>
                                    <div class="stock-reserved-count">Reservations: <?php echo $book['ActiveReservations']; ?></div>
                                </div>
                                
                                <div class="book-status-info">
                                    Stock: <span class="<?php echo $stockClass; ?>"><?php echo $stockText; ?></span>
                                </div>

                                <form method="POST" action="student_borrow.php" 
                                      onsubmit="return openConfirmModal(this, '<?php echo $book['BookID']; ?>', '<?php echo htmlspecialchars(addslashes($book['Title'])); ?>', '<?php echo $buttonAction; ?>', '<?php echo $maxedOut; ?>')">
                                    <input type="hidden" name="book_id" value="<?php echo $book['BookID']; ?>">
                                    <input type="hidden" name="action" value="<?php echo strtolower($buttonAction); ?>">
                                    <input type="hidden" name="book_title" value="<?php echo htmlspecialchars($book['Title']); ?>">
                                    
                                    <button class="action-button <?php echo $buttonClass; ?>" type="submit" <?php echo $isDisabled ? 'disabled' : ''; ?>>
                                        <?php echo $buttonAction; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <div id="confirmActionModal" class="modal">
        <div class="modal-content">
            <h3 id="modalTitle">Confirm Action</h3>
            <p id="modalMessage"></p>
            <div class="modal-buttons">
                <button id="modalConfirmBtn" class="confirm-btn">Confirm</button>
                <button type="button" class="cancel-btn" onclick="closeModal('confirmActionModal')">Cancel</button>
            </div>
        </div>
    </div>

    <form id="modalSubmissionForm" method="POST" action="student_borrow.php">
        <input type="hidden" name="book_id" id="modalBookId">
        <input type="hidden" name="action" id="modalAction">
        <input type="hidden" name="book_title" id="modalBookTitle">
    </form>


    <script>
        // --- Modal Control Functions ---
        function openModal(modalId) { document.getElementById(modalId).style.display = 'flex'; }
        function closeModal(modalId) { document.getElementById(modalId).style.display = 'none'; }
        
        // --- Borrowing/Reservation Workflow ---
        function openConfirmModal(formElement, bookId, bookTitle, action, maxedOut) {
            const modal = document.getElementById('confirmActionModal');
            const modalTitle = document.getElementById('modalTitle');
            const modalMessage = document.getElementById('modalMessage');
            const confirmBtn = document.getElementById('modalConfirmBtn');
            const submissionForm = document.getElementById('modalSubmissionForm');

            action = action.trim(); // Clean up action text

            // 1. CHECK BORROW LIMIT (If trying to borrow AND maxed out)
            if (action === 'Borrow' && maxedOut === '1') {
                 modalTitle.textContent = "Borrow Limit Reached";
                 modalMessage.innerHTML = "You have reached your borrowing limit. Please return a book before submitting another loan request.";
                 confirmBtn.style.display = 'none'; // Hide confirm button for denial
                 openModal('confirmActionModal');
                 return false; // Stop initial form submission
            }

            // 2. SETUP MODAL FOR CONFIRMATION
            
            modalTitle.textContent = `${action} Book`;
            if (action === 'Borrow') {
                 modalMessage.innerHTML = `You are requesting to borrow <b>${bookTitle}</b>. This requires staff approval.`;
            } else if (action === 'Reserve') {
                 modalMessage.innerHTML = `You are confirming a reservation for <b>${bookTitle}</b>.`;
            } else {
                return false;
            }
            
            // 3. TRANSFER DATA TO HIDDEN SUBMISSION FORM
            document.getElementById('modalBookId').value = bookId;
            document.getElementById('modalAction').value = action.toLowerCase();
            document.getElementById('modalBookTitle').value = bookTitle;
            confirmBtn.style.display = 'inline-block';
            
            // 4. ATTACH CONFIRM BUTTON LISTENER
            // Remove previous listener to prevent multiple submissions
            confirmBtn.onclick = function() {
                // Manually submit the hidden form
                submissionForm.submit(); 
            };
            
            openModal('confirmActionModal');
            
            // Prevent the original form from submitting its data
            return false; 
        }

        // Attach event listener for generic modal closing
        window.onclick = function (event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // --- Sidebar Toggle Logic ---
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