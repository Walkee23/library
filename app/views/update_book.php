<?php
// CRITICAL FIX 1: Start Output Buffering
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
$current_book = null;
$lookup_isbn = '';

// --- FUNCTION TO LOAD BOOK DATA BY ISBN ---
// --- FUNCTION TO LOAD BOOK DATA BY ISBN (Updated Select Query) ---
function loadBookData($pdo, $isbn) {
    try {
        $sql = "
            SELECT 
                B.BookID, B.Title, B.Author, B.ISBN, B.Price, B.Category, 
                -- Calculate CopiesTotal dynamically:
                (SELECT COUNT(BC1.CopyID) FROM Book_Copy BC1 WHERE BC1.BookID = B.BookID) AS CopiesTotal,
                -- Calculate CopiesAvailable dynamically:
                (SELECT COUNT(BC2.CopyID) FROM Book_Copy BC2 WHERE BC2.BookID = B.BookID AND BC2.Status = 'Available') AS CopiesAvailable
            FROM Book B 
            WHERE B.ISBN = ? AND B.Status != 'Archived'
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$isbn]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Load Book Data Error: " . $e->getMessage());
        return false;
    }
}

// 1. HANDLE POST (UPDATE SUBMISSION)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $price = filter_var($_POST['price'] ?? 0.00, FILTER_VALIDATE_FLOAT);
    $new_quantity = filter_var($_POST['quantity'] ?? 0, FILTER_VALIDATE_INT);
    $bookID = filter_var($_POST['book_id'] ?? null, FILTER_VALIDATE_INT);

    // Basic validation
    if (empty($title) || empty($isbn) || $price === false || $new_quantity === false || $new_quantity < 0) {
        $status_message = "Please check all input values.";
        $error_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            
            // 1. Get current stock counts (dynamic calculation via loadBookData)
            $current_data = loadBookData($pdo, $isbn);
            $old_total = $current_data['CopiesTotal'] ?? 0;
            $old_available = $current_data['CopiesAvailable'] ?? 0;
            $currently_borrowed = $old_total - $old_available;
            $quantity_difference = $new_quantity - $old_total;

            if ($new_quantity < $currently_borrowed) {
                // CRITICAL CHECK: Cannot reduce total stock below currently borrowed copies
                $status_message = "Error: Cannot reduce total copies. {$currently_borrowed} copies are currently on loan.";
                $error_type = 'error';
                $pdo->rollBack();
            } else {
                
                // 2. UPDATE static book details (Title, Author, Price, Category)
                $sql = "UPDATE Book SET Title = :title, Author = :author, Price = :price, Category = :category
                        WHERE BookID = :book_id";
                $pdo->prepare($sql)->execute([
                    ':title' => $title,
                    ':author' => $author,
                    ':price' => $price,
                    ':category' => $category,
                    ':book_id' => $bookID,
                ]);

                // 3. SYNCHRONIZE BOOK_COPY TABLE (Managing Stock)
                $log_description = [];
                $changes_made = false;

                if ($quantity_difference > 0) {
                    // ADD NEW COPIES: Create $quantity_difference new 'Available' records
                    $log_description[] = "Added {$quantity_difference} new copies.";
                    $copySql = "INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')";
                    $copyStmt = $pdo->prepare($copySql);
                    for ($i = 0; $i < $quantity_difference; $i++) {
                        $copyStmt->execute([$bookID]);
                    }
                    $changes_made = true;

                } elseif ($quantity_difference < 0) {
                    // REMOVE COPIES: Delete |$quantity_difference| 'Available' records
                    $copies_to_remove = abs($quantity_difference);
                    $log_description[] = "Removed {$copies_to_remove} available copies.";
                    
                    // Fetch the CopyIDs that are currently Available
                    $stmt_copies = $pdo->prepare("SELECT CopyID FROM Book_Copy WHERE BookID = ? AND Status = 'Available' LIMIT ?");
                    $stmt_copies->bindParam(1, $bookID, PDO::PARAM_INT);
                    $stmt_copies->bindParam(2, $copies_to_remove, PDO::PARAM_INT);
                    $stmt_copies->execute();
                    $copyIdsToDelete = $stmt_copies->fetchAll(PDO::FETCH_COLUMN);

                    // Execute deletion for each fetched CopyID
                    if (!empty($copyIdsToDelete)) {
                         $placeholders = implode(',', array_fill(0, count($copyIdsToDelete), '?'));
                         $pdo->prepare("DELETE FROM Book_Copy WHERE CopyID IN ($placeholders)")
                            ->execute($copyIdsToDelete);
                    }
                    $changes_made = true;
                }
                
                // 4. LOG THE ACTION
                // NOTE: Use the existing detailed log logic from the previous step here if desired.
                $logMessage = "Stock synchronized. Total copies adjusted to {$new_quantity}. " . implode("; ", $log_description);
                
                $logSql = "INSERT INTO Management_Log (UserID, BookID, ActionType, Description) VALUES (:user_id, :book_id, 'Updated', :desc)";
                $logStmt = $pdo->prepare($logSql);
                $logStmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':book_id' => $bookID,
                    ':desc' => $logMessage,
                ]);

                $pdo->commit();
                $status_message = "Book '{$title}' updated successfully! Total copies: {$new_quantity}.";
                $error_type = 'success';
            }

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log("Update Book Transaction Error: " . $e->getMessage());
            $status_message = "Database Error: Could not update the book details.";
            $error_type = 'error';
        }
    }
}

// 2. HANDLE GET (INITIAL LOAD OR LOOKUP)
// Check for lookup query parameter (e.g., from Inventory page link)
if (isset($_GET['isbn'])) {
    $lookup_isbn = trim($_GET['isbn']);
    $current_book = loadBookData($pdo, $lookup_isbn);

    if (!$current_book) {
        $status_message = "Error: Book with ISBN '{$lookup_isbn}' was not found or is archived.";
        $error_type = 'error';
        $lookup_isbn = ''; // Clear search field
    }
} else {
    // If it's a GET request but no ISBN is set, initialize $current_book to null.
    $current_book = null;
}
?>
<?php ob_end_flush(); ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Book</title>

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

        /* Update Book Section */
        .updatebook-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .updatebook-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
            margin-top: 20px;
            align-self: self-start;
        }


        /* --- Book Form Card Styles --- */
        .form-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.05);
            max-width: 650px;
            width: 100%;
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

        .form-input {
            width: 100%;
            padding: 12px;
            border: 2px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 17px;
            outline: none;
            transition: border-color 0.3s;
        }

        .form-input:focus {
            border-color: #00bcd4;
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

        .action-button:hover {
            background-color: #00897b;
        }

        .cancel-button {
            background-color: #e0e0e0;
            color: #333;
        }

        .cancel-button:hover {
            background-color: #ccc;
        }

        /* --- New styles for icons and visual appeal (From add_book.php) --- */
        .form-icon {
            color: #00a89d;
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

        /* Adjust input padding to accommodate the icon */
        .form-input {
            padding-left: 40px;
        }

        /* New style for status message */
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
                <li class="nav-item active"><a href="update_book.php">
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

            <div class="updatebook-section">
                <h2>Book Management</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">

                    <div class="form-header-title">
                        <span class="material-icons form-icon">edit_square</span>
                        Update Book Details
                    </div>

                    <?php if (!$current_book && empty($_POST['isbn'])): ?>
                        <form action="update_book.php" method="GET">
                            <p style="color: #666; margin-bottom: 15px;">Enter the ISBN of the book you want to update:</p>
                            <div class="form-group">
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">search</span>
                                    <input type="text" name="isbn" class="form-input"
                                        placeholder="Enter ISBN (e.g., 978-0123456789)" required>
                                </div>
                            </div>
                            <button type="submit" class="action-button" style="width: 100%;">Search Book</button>
                        </form>

                    <?php else: ?>
                        <p style="color: #666; margin-bottom: 15px;">Editing:
                            **<?php echo htmlspecialchars($current_book['Title'] ?? 'Book Not Found'); ?>** (ISBN:
                            <?php echo htmlspecialchars($current_book['ISBN'] ?? ''); ?>)
                        </p>

                        <form action="update_book.php" method="POST">
                            <input type="hidden" name="book_id"
                                value="<?php echo htmlspecialchars($current_book['BookID'] ?? ''); ?>">
                            <input type="hidden" name="isbn"
                                value="<?php echo htmlspecialchars($current_book['ISBN'] ?? $_POST['isbn'] ?? ''); ?>">

                            <div class="form-group">
                                <label for="isbn_display" class="form-label">ISBN (Read-Only)</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">vpn_key_off</span>
                                    <input type="text" id="isbn_display" class="form-input" readonly
                                        style="background-color: #f0f0f0;"
                                        value="<?php echo htmlspecialchars($current_book['ISBN'] ?? $_POST['isbn'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="title" class="form-label">Title</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">title</span>
                                    <input type="text" id="title" name="title" class="form-input" required
                                        value="<?php echo htmlspecialchars($current_book['Title'] ?? $_POST['title'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="author" class="form-label">Author</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">person</span>
                                    <input type="text" id="author" name="author" class="form-input" required
                                        value="<?php echo htmlspecialchars($current_book['Author'] ?? $_POST['author'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="price" class="form-label">Price</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">payments</span>
                                    <input type="number" id="price" name="price" class="form-input" min="0.01" step="0.01"
                                        required
                                        value="<?php echo htmlspecialchars($current_book['Price'] ?? $_POST['price'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="category" class="form-label">Category</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">category</span>
                                    <input type="text" id="category" name="category" class="form-input"
                                        required
                                        value="<?php echo htmlspecialchars($current_book['Category'] ?? $_POST['category'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="quantity" class="form-label">Total Copies</label>
                                <div class="form-input-icon-wrapper">
                                    <span class="material-icons form-input-icon">inventory</span>
                                    <input type="number" id="quantity" name="quantity" class="form-input" min="0" required
                                        value="<?php echo htmlspecialchars($current_book['CopiesTotal'] ?? $_POST['quantity'] ?? ''); ?>">
                                </div>
                                <small style="color: #666; font-size: 13px;">Currently Available:
                                    **<?php echo $current_book['CopiesAvailable'] ?? 'N/A'; ?>**</small>
                            </div>

                            <div class="button-group">
                                <button type="submit" class="action-button">Update Book</button>
                                <button type="button" class="action-button cancel-button"
                                    onclick="window.location.href='update_book.php'">Cancel</button>
                            </div>
                        </form>
                    <?php endif; ?>
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