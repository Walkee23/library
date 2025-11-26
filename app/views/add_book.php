<?php
// CRITICAL FIX 1: Start Output Buffering to prevent messages from appearing before HTML
ob_start();
session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    ob_end_flush(); // Flush buffer before redirect
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$status_message = '';
$error_type = ''; // success or error

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $author = trim($_POST['author'] ?? '');
    $isbn = trim($_POST['isbn'] ?? '');
    $price = filter_var($_POST['price'] ?? 0.00, FILTER_VALIDATE_FLOAT);
    $category = trim($_POST['category'] ?? '');
    $quantity = filter_var($_POST['quantity'] ?? 1, FILTER_VALIDATE_INT);
    $coverImagePath = NULL; // Reset variable

    // Basic validation
    if (empty($title) || empty($isbn) || $price === false || empty($category) || $quantity === false || $quantity < 1) {
        $status_message = "Please check your input values for Title, ISBN, Price, Category, and Quantity.";
        $error_type = 'error';
    }
    // --- File Upload Handling ---
    if (isset($_FILES['cover_image']) && $_FILES['cover_image']['error'] === UPLOAD_ERR_OK) {
        $fileTmpPath = $_FILES['cover_image']['tmp_name'];
        $fileName = $_FILES['cover_image']['name'];
        $fileSize = $_FILES['cover_image']['size'];
        $fileType = $_FILES['cover_image']['type'];

        // Sanitize file name to prevent directory traversal attacks
        $fileNameCmps = explode(".", $fileName);
        $fileExtension = strtolower(end($fileNameCmps));

        // Create a unique, safe filename (e.g., ISBN-timestamp.ext)
        $newFileName = $isbn . '-' . time() . '.' . $fileExtension;

        // Define the destination path
        $uploadFileDir = __DIR__ . '/../../public/covers/'; // Adjust path to public/covers
        $dest_path = $uploadFileDir . $newFileName;

        if (move_uploaded_file($fileTmpPath, $dest_path)) {
            // Save the relative path to the database
            $coverImagePath = 'public/covers/' . $newFileName;
        } else {
            $status_message = "Error uploading file. Check folder permissions.";
            $error_type = 'error';
            // Stop processing if the upload failed
        }
    } else {
        // --- Database Insertion Update ---
        if ($error_type !== 'error') { // Only proceed if no upload error occurred
            try {
                // 1. INSERT THE NEW BOOK
                $sql = "INSERT INTO Book (Title, Author, ISBN, Price, CoverImagePath, Category, Status) 
                    VALUES (:title, :author, :isbn, :price, :cover_path, :category, 'Available')";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':title' => $title,
                    ':author' => $author,
                    ':isbn' => $isbn,
                    ':price' => $price,
                    ':category' => $category,
                    ':cover_path' => $coverImagePath,
                ]);

                $newBookId = $pdo->lastInsertId();

                // 2. Populate the Book_Copy table for each copy
                if ($quantity > 0) {
                    $copySql = "INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')";
                    $copyStmt = $pdo->prepare($copySql);
                    for ($i = 0; $i < $quantity; $i++) {
                        $copyStmt->execute([$newBookId]);
                    }
                }

                // 3. LOG THE ACTION
                $logSql = "INSERT INTO Management_Log (UserID, BookID, ActionType, Description) 
                   VALUES (:user_id, :book_id, 'Added', :desc)";

                $logStmt = $pdo->prepare($logSql);
                $logStmt->execute([
                    ':user_id' => $_SESSION['user_id'],
                    ':book_id' => $newBookId,
                    ':desc' => "Added book '{$title}' (ISBN {$isbn}). Total copies created: {$quantity}.",
                ]);

                $status_message = "Book '{$title}' added successfully! Total copies created: {$quantity}.";
                $error_type = 'success';
                $_POST = array(); // Clear form

            } catch (PDOException $e) {
                // Check for duplicate ISBN error
                if ($e->getCode() === '23000') {
                    $status_message = "Error: The ISBN '{$isbn}' already exists in the library catalog.";
                } else {
                    error_log("Add Book Error: " . $e->getMessage());
                    $status_message = "Database Error: Could not add the book. (Check for a missing  column).";
                }
                $error_type = 'error';
            }
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
    <title>Add a New Book</title>

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

        /* Add Book Section */
        .addbook-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .addbook-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 40px;
            margin-top: 20px;
            align-self: self-start;
        }


        /* --- New Book Form Card Styles --- */

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

        /* --- New styles for icons and visual appeal --- */
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
            /* Wrapper for input field */
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
            /* Space for the icon */
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
                <li class="nav-item active"><a href="add_book.php">
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

            <div class="addbook-section">
                <h2>Book Management</h2>

                <?php if (!empty($status_message)): ?>
                    <div class="status-box <?php echo ($error_type === 'success' ? 'status-success' : 'status-error'); ?>">
                        <?php echo htmlspecialchars($status_message); ?>
                    </div>
                <?php endif; ?>

                <div class="form-card">
                    <div class="form-header-title">
                        <span class="material-icons form-icon">book</span>
                        Add New Book
                    </div>

                    <form action="add_book.php" method="POST" enctype="multipart/form-data">

                        <div class="form-group">
                            <label for="isbn" class="form-label">ISBN</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">vpn_key</span>
                                <input type="text" id="isbn" name="isbn" class="form-input"
                                    placeholder="e.g., 978-0123456789" required
                                    value="<?php echo htmlspecialchars($_POST['isbn'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="title" class="form-label">Title</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">title</span>
                                <input type="text" id="title" name="title" class="form-input" required
                                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="author" class="form-label">Author</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">person</span>
                                <input type="text" id="author" name="author" class="form-input" required
                                    value="<?php echo htmlspecialchars($_POST['author'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="price" class="form-label">Price</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">payments</span>
                                <input type="number" id="price" name="price" class="form-input" min="0.01" step="0.01"
                                    required value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="category" class="form-label">Category</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">category</span>
                                <input type="text" id="category" name="category" class="form-input" required
                                    value="<?php echo htmlspecialchars($_POST['category'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="quantity" class="form-label">Quantity</label>
                            <div class="form-input-icon-wrapper">
                                <span class="material-icons form-input-icon">inventory</span>
                                <input type="number" id="quantity" name="quantity" class="form-input" min="1" required
                                    value="<?php echo htmlspecialchars($_POST['quantity'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="button-group">
                            <button type="submit" class="action-button">Add Book</button>
                            <button type="button" class="action-button cancel-button"
                                onclick="window.location.href='add_book.php'">Cancel</button>
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