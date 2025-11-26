<?php
// CRITICAL: Start Output Buffering
ob_start();
session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    ob_end_flush();
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$status_message = '';
$error_type = ''; // 'success' or 'error'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isbn = trim($_POST['isbn'] ?? '');
    $reason = trim($_POST['reason'] ?? 'Not specified');
    $librarian_id = $_SESSION['user_id'];

    if (empty($isbn) || empty($reason)) {
        $status_message = "Please enter the ISBN and select a reason for archiving.";
        $error_type = 'error';
    } else {
        try {
            // 1. Check if the book exists, get its details, and verify copies are not out
            $stmt_check = $pdo->prepare("SELECT BookID, Title, CopiesAvailable, Status FROM Book WHERE ISBN = ?");
            $stmt_check->execute([$isbn]);
            $book = $stmt_check->fetch();

            if (!$book) {
                $status_message = "Error: Book with ISBN '{$isbn}' not found in the active catalog.";
                $error_type = 'error';
            } elseif ($book['Status'] === 'Archived') {
                $status_message = "Book '{$book['Title']}' is already archived.";
                $error_type = 'error';
            } elseif ($book['CopiesAvailable'] < $book['CopiesTotal']) {
                $status_message = "Error: Cannot archive this book. There are copies currently borrowed or reserved.";
                $error_type = 'error';
            } else {
                // 2. Archive the book: Set Status to 'Archived' and reset copies to 0
                $sql = "UPDATE Book SET Status = 'Archived', CopiesTotal = 0, CopiesAvailable = 0 
                        WHERE BookID = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$book['BookID']]);

                // 3. LOG THE ACTION
                $logSql = "INSERT INTO Management_Log (UserID, BookID, ActionType, Description) 
                           VALUES (:user_id, :book_id, 'Archived', :desc)";

                $logStmt = $pdo->prepare($logSql);
                $logStmt->execute([
                    ':user_id' => $librarian_id,
                    ':book_id' => $book['BookID'],
                    ':desc' => "Archived book '{$book['Title']}'. Reason: {$reason}.",
                ]);

                $status_message = "Book '{$book['Title']}' archived successfully! It has been removed from the active inventory.";
                $error_type = 'success';
            }

        } catch (PDOException $e) {
            error_log("Archive Book Error: " . $e->getMessage());
            $status_message = "Database Error: Could not process the archiving request.";
            $error_type = 'error';
        }
    }
}
?>
<?php ob_end_flush(); ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Archive a Book</title>

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
            padding: 30px 42px;
            min-height: 80vh;
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


        /* Archive Book Section */
        .archivebook-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .archivebook-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 10px;
            margin-top: 20px;
            align-self: self-start;
        }


        /* --- Book Form Card Styles --- */

        .form-card {
            margin-top: 30px;
            background-color: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            max-width: 650px;
            width: 100%;
        }

        .warning-text {
            color: #d32f2f;
            /* Red color for warning */
            font-weight: 600;
            margin-bottom: 25px;
            padding: 15px;
            border: 2px solid #ef9a9a;
            background-color: #ffcdd2;
            border-radius: 4px;
        }

        /* Form Group and Input Styles */
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

        .form-input,
        .form-select {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 17px;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-input:focus,
        .form-select:focus {
            border-color: #00bcd4;
        }

        .form-select {
            color: #666;
        }

        .form-select option:not([disabled]):not(:first-child) {
            color: #333;
        }

        /* Button Styling */
        .button-group {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }

        .action-button {
            flex: 1;
            background-color: #00a89d;
            color: white;
            padding: 14px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 18px;
            font-weight: 600;
            transition: background-color 0.3s;
        }

        /* Specific style for Archive/Delete button (making it red) */
        .archive-button {
            background-color: #d32f2f;
            /* Red */
        }

        .archive-button:hover {
            background-color: #b71c1c;
            /* Darker red on hover */
        }

        .cancel-button {
            background-color: #e0e0e0;
            color: #333;
        }

        .cancel-button:hover {
            background-color: #ccc;
        }

        /* --- New styles for icons and visual appeal (From Add/Update Book) --- */
        .form-icon {
            color: #d32f2f;
            /* Using red for archive icon */
            font-size: 22px;
            margin-right: 10px;
            vertical-align: middle;
        }

        .form-header-title {
            display: flex;
            align-items: center;
            font-size: 20px;
            font-weight: 600;
            color: #333;
            margin-bottom: 25px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }

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

        .form-input {
            padding-left: 40px;
            /* Space for the icon */
        }

        .form-select {
            padding-left: 40px;
            /* Space for the icon */
        }

        /* New style for status message (MUST BE ADDED TO YOUR CSS) */
        .status-box {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            width: 100%;
            max-width: 650px;
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
                <li class="nav-item"><a href="librarian.php">
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
                <li class="nav-item active"><a href="archive_book.php">
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

            <div class="archivebook-section">
                <h2>Book Management</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <div class="form-header-title">
                        <span class="material-icons form-icon" style="color: #d32f2f;">delete</span>
                        Archive Book Permanently
                    </div>

                    <div class="warning-text">
                        Warning: Archiving a book removes it from the search catalog. This action cannot be easily
                        undone.
                    </div>

                    <form action="archive_book.php" method="POST">

                        <div class="form-group">
                            <label for="isbn" class="form-label">Book ISBN</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">vpn_key</span>
                                <input type="text" id="isbn" name="isbn" class="form-input"
                                    placeholder="Enter ISBN to archive" required
                                    value="<?php echo htmlspecialchars($_POST['isbn'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="reason" class="form-label">Reason for Archiving</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">info</span>
                                <select id="reason" name="reason" class="form-select" required>
                                    <option value="" disabled selected>Select a reason</option>
                                    <option value="lost">Lost</option>
                                    <option value="damaged">Damaged/Unrepairable</option>
                                    <option value="retired">Retired/Outdated Edition</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>

                        <div class="button-group">
                            <button type="submit" class="action-button archive-button">Archive Book Permanently</button>

                            <button type="button" class="action-button cancel-button"
                                onclick="window.location.href='archive_book.php'">Cancel</button>
                        </div>
                    </form>
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