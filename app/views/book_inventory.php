<?php
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../controllers/BooksController.php'; // Changed Controller

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') exit(header("Location: ".BASE_URL."/views/login.php"));

// Use BooksController now
$controller = new BooksController($pdo, $_SESSION['user_id']);
$data = $controller->getInventory($_GET);

// Extract data
$books = $data['books'];
$categories = $data['categories'];
$total_pages = $data['total_pages'];
$current_page = $data['current_page'];
$search_term = $data['filters']['search'];
$status_filter = $data['filters']['status'];
$category_filter = $data['filters']['category'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Book Inventory</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; margin: 0; background-color: #F7FCFC; color: #333; }
        .container { display: flex; min-height: 100vh; }
        .sidebar { width: 70px; padding: 30px 0; background-color: #fff; border-right: 1px solid #eee; position: fixed; height: 100vh; top: 0; left: 0; z-index: 100; transition: width 0.5s ease; white-space: nowrap; overflow-x: hidden; }
        .sidebar.active { width: 250px; }
        .logo { font-size: 19px; font-weight: bold; color: #000; padding: 0 23px 40px; display: flex; align-items: center; cursor: pointer; }
        .logo-text { opacity: 0; transition: opacity 0.1s ease; margin-left: 10px; }
        .sidebar.active .logo-text { opacity: 1; }
        .nav-list { list-style: none; padding: 0; margin: 0; }
        .nav-item a { display: flex; align-items: center; font-size: 15px; padding: 15px 24px; text-decoration: none; color: #6C6C6C; transition: 0.2s; }
        .nav-item.active a { color: #000; font-weight: bold; }
        .main-content { flex-grow: 1; padding: 30px 32px; margin-left: 70px; transition: margin-left 0.5s ease; }
        .main-content.pushed { margin-left: 250px; }
        .search-filters { display: flex; gap: 15px; margin-bottom: 30px; }
        .search-input-wrapper { display: flex; flex-grow: 1; border: 2px solid #ddd; border-radius: 8px; background: #fff; }
        .search-input { border: none; padding: 12px; flex-grow: 1; border-radius: 8px; outline: none; }
        .search-btn { background: #57e4d4ff; border: none; padding: 0 20px; color: white; border-radius: 0 6px 6px 0; cursor: pointer; }
        .filter-select { padding: 12px; border: 2px solid #ddd; border-radius: 8px; outline: none; }
        .book-list { display: flex; flex-wrap: wrap; gap: 20px; justify-content: center; }
        .book-card { width: 480px; background: #fff; padding: 15px; border-radius: 12px; display: flex; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
        .book-cover { width: 120px; height: 180px; background: #eee; border-radius: 8px; margin-right: 15px; background-size: cover; background-position: center; display: flex; align-items: center; justify-content: center; color: #999; }
        .book-info { flex: 1; display: flex; flex-direction: column; justify-content: space-between; }
        .stock-avail { color: #00A693; font-weight: bold; }
        .status-tag { padding: 5px 10px; border-radius: 5px; font-weight: bold; font-size: 13px; width: fit-content; }
        .status-Available { background: #e0f2f1; color: #00695c; }
        .pagination { display: flex; gap: 5px; justify-content: flex-end; list-style: none; margin-top: 20px; }
        .page-link { padding: 8px 12px; border: 1px solid #ddd; background: #fff; text-decoration: none; color: #333; }
        .page-link.active { background: #57e4d4ff; color: #fff; border-color: #57e4d4ff; }
    </style>
</head>
<body>
    <div class="container">
        <div id="sidebar-menu" class="sidebar">
            <div class="logo" onclick="toggleSidebar()"><span class="material-icons">menu</span><span class="logo-text">📚 Smart Library</span></div>
            <ul class="nav-list">
                <li class="nav-item"><a href="librarian.php"><span class="material-icons" style="margin-right:20px;">dashboard</span><span class="logo-text">Dashboard</span></a></li>
                <li class="nav-item active"><a href="book_inventory.php"><span class="material-icons" style="margin-right:20px;">inventory_2</span><span class="logo-text">Inventory</span></a></li>
                <li class="nav-item"><a href="manage_books.php"><span class="material-icons" style="margin-right:20px;">edit_note</span><span class="logo-text">Manage Books</span></a></li>
            </ul>
            <div class="logout nav-item"><a href="login.php"><span class="material-icons" style="margin-right:20px;">logout</span><span class="logo-text">Logout</span></a></div>
        </div>

        <div id="main-content-area" class="main-content">
            <h2>Book Inventory</h2>
            <form method="GET" class="search-filters">
                <div class="search-input-wrapper">
                    <input type="text" name="search" class="search-input" placeholder="Search Title, Author, ISBN" value="<?php echo htmlspecialchars($search_term); ?>">
                    <button type="submit" class="search-btn"><span class="material-icons">search</span></button>
                </div>
                <select name="status" class="filter-select" onchange="this.form.submit()">
                    <option value="All" <?php echo $status_filter=='All'?'selected':''; ?>>All Status</option>
                    <option value="Available" <?php echo $status_filter=='Available'?'selected':''; ?>>Available</option>
                    <option value="Borrowed" <?php echo $status_filter=='Borrowed'?'selected':''; ?>>Borrowed</option>
                </select>
                <select name="category" class="filter-select" onchange="this.form.submit()">
                    <option value="All">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter==$cat?'selected':''; ?>><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </form>

            <div class="book-list">
                <?php if (empty($books)): ?><p>No books found.</p><?php endif; ?>
                <?php foreach ($books as $book): 
                    $imgUrl = $book['CoverImagePath'] ? (strpos($book['CoverImagePath'],'http')===0 ? $book['CoverImagePath'] : BASE_URL.'/'.$book['CoverImagePath']) : '';
                    $bgStyle = $imgUrl ? "background-image: url('$imgUrl');" : "";
                ?>
                <div class="book-card">
                    <div class="book-cover" style="<?php echo $bgStyle; ?>"><?php echo !$imgUrl ? 'No Cover' : ''; ?></div>
                    <div class="book-info">
                        <div>
                            <div style="font-weight:bold; font-size:17px;"><?php echo htmlspecialchars($book['Title']); ?></div>
                            <div style="color:#666; font-size:14px;">By: <?php echo htmlspecialchars($book['Author']); ?></div>
                            <div style="color:#999; font-size:12px;">ISBN: <?php echo htmlspecialchars($book['ISBN']); ?></div>
                        </div>
                        <div>
                            <div>Stock: <span class="stock-avail"><?php echo $book['CopiesAvailable']; ?></span> / <?php echo $book['CopiesTotal']; ?></div>
                            <div class="status-tag status-<?php echo $book['Status']; ?>"><?php echo $book['Status']; ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php if($total_pages > 1): ?>
            <ul class="pagination">
                <?php for($i=1; $i<=$total_pages; $i++): 
                    $url = "?page=$i&search=$search_term&status=$status_filter&category=$category_filter"; 
                    $act = ($i==$current_page) ? 'active' : ''; ?>
                    <li><a href="<?php echo $url; ?>" class="page-link <?php echo $act; ?>"><?php echo $i; ?></a></li>
                <?php endfor; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar-menu').classList.toggle('active');
            document.getElementById('main-content-area').classList.toggle('pushed');
        }
    </script>
</body>
</html>