<?php
session_start();

// --- Authentication and Setup ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}

require_once __DIR__ . '/../models/database.php';
require_once __DIR__ . '/../../config.php';

$librarian_name = $_SESSION['name'] ?? 'Librarian';
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

$categories = [];

try {
    // 1. Fetch unique categories for the filter dropdown
    $categories = $pdo->query("SELECT DISTINCT Category FROM Book WHERE Category IS NOT NULL AND Category != '' ORDER BY Category ASC")->fetchAll(PDO::FETCH_COLUMN);

    // 2. Define the dynamic calculation fields
    $dynamic_fields = "
        B.BookID, B.Title, B.Author, B.ISBN, B.Price, B.CoverImagePath, B.Status, B.Category,
        -- Calculate CopiesTotal dynamically from Book_Copy table:
        (SELECT COUNT(BC1.CopyID) FROM Book_Copy BC1 WHERE BC1.BookID = B.BookID) AS CopiesTotal,
        -- Calculate CopiesAvailable dynamically from Book_Copy table:
        (SELECT COUNT(BC2.CopyID) FROM Book_Copy BC2 WHERE BC2.BookID = B.BookID AND BC2.Status = 'Available') AS CopiesAvailable
    ";

    // 3. Build the Base SQL Query for COUNT and LISTING
    $base_sql = "FROM Book B WHERE B.Status != 'Archived'"; // Use alias B for the Book table

    // Apply Status Filter
    if ($status_filter !== 'All') {
        $safe_status = $pdo->quote($status_filter);
        $base_sql .= " AND Status = {$safe_status}";
    }

    // Apply Category Filter
    if ($category_filter !== 'All') {
        $safe_category = $pdo->quote($category_filter);
        $base_sql .= " AND B.Category = {$safe_category}";
    }

    $count_sql = "SELECT COUNT(B.BookID) " . $base_sql;
    $list_sql = "SELECT {$dynamic_fields} " . $base_sql;
    
    $is_search = !empty($search_term);

    if ($is_search) {
        // --- Apply Search to both COUNT and LIST queries ---
        $search_clause = " AND (B.Title LIKE :search OR B.Author LIKE :search OR B.ISBN LIKE :search)";
        $safe_search = $pdo->quote('%' . $search_term . '%');
        
        $count_sql .= str_replace(':search', $safe_search, $search_clause);
        $list_sql .= str_replace(':search', $safe_search, $search_clause);
        
        $query_message = "Showing results for: '" . htmlspecialchars($search_term) . "'";
    }

    // 4. Fetch Total Count
    $total_books = $pdo->query($count_sql)->fetchColumn();
    $total_pages = ceil($total_books / $books_per_page);

    // 5. Finalize List Query with Pagination and Order
    $list_sql .= " ORDER BY B.Title ASC LIMIT {$books_per_page} OFFSET {$offset}";

    // 6. Execute the final list query
    $stmt = $pdo->query($list_sql);
    $books = $stmt->fetchAll();

    // We must re-run the final list query, injecting the search term again if needed
    if ($is_search) {
        $list_sql = str_replace(':search', $safe_search, $list_sql);
    }

    // Ensure current page is within valid range
    if ($current_page > $total_pages && $total_pages > 0) {
        $params = "?page={$total_pages}";
        if ($is_search)
            $params .= "&search=" . urlencode($search_term);
        if ($status_filter !== 'All')
            $params .= "&status=" . urlencode($status_filter);

        header("Location: book_inventory.php" . $params);
        exit();
    }

} catch (PDOException $e) {
    error_log("Inventory Query Error: " . $e->getMessage());
    $query_message = "Database Error! Could not load inventory.";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Book Inventory</title>

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
            padding: 30px 0;
            background-color: #fff;
            border-right: 1px solid #eee;
            box-shadow: 3px 0 9px rgba(0, 0, 0, 0.05);
            position: fixed;
            height: 100vh;
            top: 0;
            left: 0;
            z-index: 100;
            /* The sidebar itself no longer needs a width transition for the smooth effect */
            transition: width 0.5s ease;
            overflow-x: hidden;
            overflow-y: auto;
            white-space: nowrap;
        }

        .sidebar.active {
            width: 250px;
            /* Expanded Width (Toggled by JS) */
        }

        .main-content-wrapper {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding-left: 32px;
            padding-right: 32px;

            /* CRITICAL: The margin-left transitions to push the content away from the sidebar */
            margin-left: 70px;
            /* Initial margin equals collapsed sidebar width */
            transition: margin-left 0.5s ease;

            width: 100%;
            /* Important for centering */
        }

        .main-content-wrapper.pushed {
            margin-left: 250px;
            /* Margin equals expanded sidebar width */
        }

        .main-content {
            width: 100%;
            /* Allows the inner content to span the full width of the wrapper */
            max-width: 1200px;
            /* Optional: Sets a max width for readability */
            padding-top: 30px;
            /* Remove left/right padding here since the wrapper handles it */
        }

        .inventory-section {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .logo {
            font-size: 19px;
            font-weight: bold;
            color: #000;
            padding: 0 23px 40px;
            display: flex;
            align-items: center;
            cursor: pointer;
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
        }

        .inventory-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-left: 10px;
            margin-bottom: 20px;
            margin-top: 20px;
            align-self: flex-start;
        }

        .inventory-section p.subtitle {
            font-size: 15px;
            color: #666;
            margin-left: 10px;
            margin-bottom: 60px;
            margin-top: -5px;
            align-self: flex-start;
        }

        .search-filters {
            display: flex;
            gap: 15px;
            margin-bottom: 60px;
            width: 100%;
            max-width: 1050px;
            flex-wrap: wrap;
        }

        .search-input-wrapper {
            position: relative;
            flex-grow: 1;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border-radius: 8px;
            background-color: #fff;
            display: flex;
            min-width: 300px;
        }

        /* Styling for ALL Select/Input fields */
        .search-input,
        .filter-select {
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 16px;
            color: #333;
            transition: border-color 0.2s;
            box-sizing: border-box;
            height: 48px;
            /* Ensures all inputs/selects are the same height */
        }

        /* Separate styling for the filter selects */
        .filter-select {
            max-width: 200px;
            flex-grow: 0;
            /* Prevents stretching */
            padding-right: 35px;
            /* Space for the dropdown arrow */

            /* Apply box shadow styling to filter selects too */
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            background-color: #fff;
        }

        /* Focus styling for all filter elements */
        .search-input:focus,
        .filter-select:focus {
            outline: none;
        }

        /* New Style for the Search Icon Button (Replaces the text button) */
        .search-btn-icon {
            /* Positioned visually next to the input field */
            padding: 0 18px;
            border: none;
            background-color: #57e4d4ff;
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
            background-color: #4bd0c0ff;
        }

        .search-input {
            width: 100%;
            padding: 12px 35px 12px 18px;
            border: 2px solid #ddd;
            border-right: none;
            border-radius: 8px 0 0 8px;
            padding-right: 35px;
            font-size: 16px;
            background-color: #fff;
            color: #333;
            transition: border-color 0.3s;
        }

        .search-input:focus {
            border-color: #57e4d4ff;
            outline: none;
        }

        .clear-btn {
            position: absolute;
            top: 50%;
            right: 9%;
            transform: translateY(-50%);
            height: 100%;
            width: 35px;
            background: none;
            border: none;
            color: #999;
            font-size: 28px;
            font-weight: 600px;
            cursor: pointer;
            display:
                <?php echo empty($search_term) ? 'none' : 'block'; ?>
            ;
            padding: 0;
            line-height: 1;
            outline: none;
            transition: color 0.2s;
            z-index: 10;
        }

        .clear-btn:hover {
            color: #777;
        }

        /* Book Cards Container */
        .book-list {
            display: flex;
            gap: 30px;
            flex-wrap: wrap;
            width: 100%;
            justify-content: center;
        }

        /* FIX: Increased width/height to fit text better */
        .book-card {
            width: 492px;
            height: 350px;
            background-color: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 10px rgba(0, 0, 0, 0.05);
            display: flex;
            padding: 15px;
            box-sizing: border-box;
        }

        .book-cover-area {
            background-color: #F0F8F8;
            width: 180px;
            height: 320px;
            border-radius: 8px;
            margin-right: 15px;
            flex-shrink: 0;
        }

        .book-details {
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            flex-grow: 1;
            padding: 5px 0;
            padding-left: 5px;
        }

        /* Book Title and Author */
        .book-title {
            font-size: 17px;
            font-weight: 600;
            line-height: 1.3;
            margin: 5px 0 3px 0;
            word-wrap: break-word;
        }

        .book-author {
            font-size: 14px;
            color: #666;
            margin: 0;
        }

        /* Book Status and Stock Info */
        .book-status {
            font-size: 14px;
            color: #444;
            font-weight: 500;
            margin-bottom: 10px;
            margin-top: 10px;
        }

        .book-status span {
            font-weight: 700;
        }

        .book-status .available-stock {
            color: #00A693;
            font-size: 16px;
        }

        .book-total-stock {
            color: #999;
            font-size: 13px;
            /* Mute total count */
            font-weight: 500;
            margin-top: 2px;
        }

        .book-status .low-stock {
            color: #E5A000;
        }

        /* Action Buttons/Status Tags */
        .action-button {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 10px 15px;
            border-radius: 8px;
            font-weight: 700;
            cursor: default;
            text-decoration: none;
            width: 100px;
            min-height: 40px;
            box-sizing: border-box;
            align-self: flex-end;
            font-size: 15px;
            border: none;
        }

        /* --- Specific Status Tag Colors --- */

        .available-tag {
            background-color: #00A693;
            color: #fff;
        }

        .reserved-tag {
            background-color: #E0E0E0;
            color: #444;
        }

        .borrowed-tag {
            background-color: #F8D7DA;
            color: #721C24;
        }

        .archived-tag {
            background-color: #E6E6E6;
            color: #777;
            font-weight: 500;
        }

        /* --- PAGINATION STYLES --- */
        .pagination-controls {
            margin-top: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: flex-end;
            width: 100%;
        }

        .pagination {
            display: flex;
            padding-left: 0;
            list-style: none;
            border-radius: 0.50rem;
        }

        .page-item:first-child .page-link {
            border-top-left-radius: 0.50rem;
            border-bottom-left-radius: 0.50rem;
        }

        .page-item:last-child .page-link {
            border-top-right-radius: 0.50rem;
            border-bottom-right-radius: 0.50rem;
        }

        .page-link {
            display: block;
            padding: 0.5rem 0.75rem;
            color: #4aa0fdff;
            background-color: #fff;
            border: 1px solid #dee2e6;
            text-decoration: none;
            transition: color 0.15s ease-in-out, background-color 0.15s ease-in-out, border-color 0.15s ease-in-out;
        }

        .page-item:not(:first-child) .page-link {
            margin-left: -1px;
            /* Overlap borders slightly */
        }

        .page-link:hover {
            z-index: 2;
            color: #007bffff;
            background-color: #e9ecef;
            border-color: #dee2e6;
        }

        /* Active State */
        .page-item.active .page-link {
            z-index: 3;
            color: #fff;
            background-color: #34cfbcff;
            border-color: #34cfbcff;
        }

        /* Disabled State */
        .page-item.disabled .page-link {
            color: #6c757d;
            pointer-events: none;
            background-color: #eeebebff;
            border-color: #d3d2d2ff;
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
                <li class="nav-item active"><a href="book_inventory.php">
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

        <div id="main-content-wrapper" class="main-content-wrapper">
            <div class="main-content">

                <div class="inventory-section">
                    <h2>Manage Book Inventory</h2>
                    <p class="subtitle">Search, filter, and manage books.
                    </p>

                    <form method="GET" action="book_inventory.php" class="search-filters">
                        <div class="search-input-wrapper">
                            <input type="text" name="search" id="search-input-field" class="search-input"
                                placeholder="Search by Title, Author, ISBN"
                                value="<?php echo htmlspecialchars($search_term); ?>">

                            <button type="button" class="clear-btn" onclick="window.location.href='book_inventory.php';"
                                title="Clear Search" <?php echo empty($search_term) ? 'style="display: none;"' : ''; ?>>
                                &times;
                            </button>

                            <button type="submit" name="submit_search" class="search-btn-icon" title="Search">
                                <span class="material-icons">search</span>
                            </button>
                        </div>

                        <select name="status" onchange="this.form.submit()" class="filter-select">
                            <option value="All" <?php echo $status_filter === 'All' ? 'selected' : ''; ?>>All Statuses
                            </option>
                            <option value="Available" <?php echo $status_filter === 'Available' ? 'selected' : ''; ?>>
                                Available</option>
                            <option value="Reserved" <?php echo $status_filter === 'Reserved' ? 'selected' : ''; ?>>
                                Reserved</option>
                            <option value="Borrowed" <?php echo $status_filter === 'Borrowed' ? 'selected' : ''; ?>>
                                Borrowed</option>
                        </select>

                        <select name="category" onchange="this.form.submit()" class="filter-select">
                            <option value="All" <?php echo $category_filter === 'All' ? 'selected' : ''; ?>>All Categories</option>
                            <?php 
                            // This loop now uses the dynamically fetched list from the database
                            foreach ($categories as $catName): ?> 
                                <option value="<?php echo htmlspecialchars($catName); ?>" <?php echo $category_filter === $catName ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($catName); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>

                    <?php
                    if (!empty($query_message)): ?>
                        <p
                            style="font-size: 15px; font-weight: 600; color: #00A693; margin-top: -20px; margin-bottom: 30px;">
                            <?php echo htmlspecialchars($query_message); ?>
                        </p>
                    <?php endif; ?>

                    <div class="book-list">
                        <?php if (empty($books)): ?>
                            <p style="width: 100%;">No books found matching your criteria.</p>
                        <?php endif; ?>

                        <?php foreach ($books as $book):
                            // ... (Your book card loop and logic is here) ...
                            $copiesAvailable = (int) $book['CopiesAvailable'];
                            $stockClass = ($copiesAvailable > 0) ? 'available-stock' : 'low-stock';
                            $statusTagClass = strtolower($book['Status']) . '-tag';

                            $coverImagePath = $book['CoverImagePath'] ?? null;
                            $coverStyle = '';
                            $fallbackText = '';

                            if (!empty($coverImagePath)) {
                                if (strpos($coverImagePath, 'http') === 0) {
                                    $imageURL = htmlspecialchars($coverImagePath);
                                } else {
                                    $imageURL = BASE_URL . '/' . htmlspecialchars($coverImagePath);
                                }
                                $coverStyle = "
                    background-image: url('$imageURL'); 
                    background-size: cover; 
                    background-position: center; 
                    background-repeat: no-repeat;
                    background-color: transparent;
                ";
                            } else {
                                $coverStyle = "
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    color: #999;
                    text-align: center;
                ";
                                $fallbackText = 'No Cover';
                            }
                            ?>

                            <div class="book-card">
                                <div class="book-cover-area" style="<?php echo $coverStyle; ?>">
                                    <?php echo $fallbackText; ?>
                                </div>

                                <div class="book-details">
                                    <div>
                                        <div class="book-title"><?php echo htmlspecialchars($book['Title']); ?></div>
                                        <div class="book-author">By: <?php echo htmlspecialchars($book['Author']); ?></div>
                                    </div>

                                    <div class="stock-info-block">
                                        <div class="book-status">
                                            Stock: <span class="<?php echo $stockClass; ?>"><?php echo $copiesAvailable; ?>
                                                copies</span> available
                                        </div>
                                        <div class="book-total-stock">
                                            Total Copies: <?php echo $book['CopiesTotal']; ?>
                                        </div>
                                        <small style="display: block; color: #aaa; margin-top: 5px;">(ISBN:
                                            <?php echo $book['ISBN']; ?>)</small>
                                    </div>
                                    <div class="action-button <?php echo $statusTagClass; ?>">
                                        <?php echo htmlspecialchars($book['Status']); ?>
                                    </div>
                                </div>
                            </div>

                        <?php endforeach; ?>
                    </div>

                    <?php if ($total_pages > 1): ?>
                        <div class="pagination-controls">
                            <ul class="pagination">
                                <?php
                                $searchParam = $search_term ? "&search=" . urlencode($search_term) : '';

                                // Previous Page Link
                                $prevPage = max(1, $current_page - 1);
                                $prevDisabled = ($current_page == 1) ? 'disabled' : '';
                                ?>
                                <li class="page-item <?php echo $prevDisabled; ?>">
                                    <a href="?page=<?php echo $prevPage . $searchParam; ?>" class="page-link">
                                        Previous
                                    </a>
                                </li>

                                <?php
                                // Loop for page numbers
                                for ($i = 1; $i <= $total_pages; $i++):
                                    $activeClass = ($i == $current_page) ? 'active' : '';
                                    ?>
                                    <li class="page-item <?php echo $activeClass; ?>">
                                        <a href="?page=<?php echo $i . $searchParam; ?>" class="page-link">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>

                                <?php
                                // Next Page Link
                                $nextPage = min($total_pages, $current_page + 1);
                                $nextDisabled = ($current_page == $total_pages) ? 'disabled' : '';
                                ?>
                                <li class="page-item <?php echo $nextDisabled; ?>">
                                    <a href="?page=<?php echo $nextPage . $searchParam; ?>" class="page-link">
                                        Next
                                    </a>
                                </li>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>

                <script>
                    const sidebar = document.getElementById('sidebar-menu');
                    const contentWrapper = document.getElementById('main-content-wrapper'); // Get the new ID

                    // Use a simple function to find the sidebar and toggle the 'active' class
                    function toggleSidebar() {
                        // Toggles sidebar width
                        sidebar.classList.toggle('active');

                        // CRITICAL: Toggles content margin to push content
                        contentWrapper.classList.toggle('pushed');
                    }

                    const searchInput = document.getElementById('search-input-field');
                    const clearBtn = document.querySelector('.clear-btn');

                    // Show/hide the 'X' button dynamically as the user types
                    if (searchInput && clearBtn) {
                        searchInput.addEventListener('input', function () {
                            if (this.value.length > 0) {
                                clearBtn.style.display = 'block';
                            } else {
                                clearBtn.style.display = 'none';
                            }
                        });
                    }
                </script>

            </div>
        </div>
    </div>
    </div>
    </div>
</body>

</html>