<?php
ob_start();
session_start();
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../controllers/BooksController.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Librarian') {
    header("Location: " . BASE_URL . "/views/login.php");
    exit();
}

$controller = new BooksController($pdo, $_SESSION['user_id']);
$activeTab = $_GET['tab'] ?? 'add'; // Default to Add tab
$msg = ''; $type = '';

// --- HANDLE ACTIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $res = [];
    
    if ($action === 'add') {
        $res = $controller->addBook($_POST, $_FILES);
    } elseif ($action === 'update') {
        $res = $controller->updateBook($_POST);
    } elseif ($action === 'archive') {
        $res = $controller->archiveBook($_POST);
    }

    if (!empty($res)) {
        $msg = $res['msg'];
        $type = $res['type'];
    }
}

// --- DATA FOR UPDATE TAB ---
$bookToEdit = null;
if ($activeTab === 'update' && isset($_GET['isbn'])) {
    $bookToEdit = $controller->getBookByIsbn($_GET['isbn']);
    if (!$bookToEdit) { $msg = "Book not found."; $type = "error"; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Books</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        /* CSS Styles */
        body { font-family: 'Poppins', sans-serif; background: #F7FCFC; margin:0; color: #333; }
        .container { display: flex; min-height: 100vh; }
        
        /* Sidebar */
        .sidebar { width: 70px; padding: 30px 0; background: #fff; border-right: 1px solid #eee; position: fixed; height: 100vh; z-index: 100; transition: width 0.5s ease; overflow: hidden; white-space: nowrap; }
        .sidebar.active { width: 250px; }
        .logo { font-weight: bold; font-size: 19px; padding: 0 23px 40px; display: flex; align-items: center; cursor: pointer; }
        .logo-text { margin-left: 10px; opacity: 0; transition: opacity 0.1s; }
        .sidebar.active .logo-text { opacity: 1; }
        .nav-list { list-style: none; padding: 0; }
        .nav-item a { display: flex; align-items: center; padding: 15px 24px; color: #666; text-decoration: none; transition: 0.2s; }
        .nav-item a:hover { background: #f0f0f0; }
        .nav-item.active a { color: #000; font-weight: bold; background: #f9f9f9; }
        .nav-icon { margin-right: 20px; font-size: 21px; }

        /* Main Content */
        .main { flex-grow: 1; margin-left: 70px; padding: 30px 40px; transition: margin 0.5s ease; }
        .main.pushed { margin-left: 250px; }

        /* Tabs */
        .tabs { display: flex; border-bottom: 2px solid #eee; margin-bottom: 30px; }
        .tab-link { padding: 12px 25px; text-decoration: none; color: #666; font-weight: 600; border-bottom: 3px solid transparent; transition: 0.3s; }
        .tab-link:hover { color: #00A693; }
        .tab-link.active { color: #00A693; border-bottom-color: #00A693; }

        /* Forms */
        .form-card { background: #fff; padding: 30px; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); max-width: 800px; margin: 0 auto; }
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; margin-bottom: 5px; font-weight: 500; }
        .form-input { width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; }
        .btn { padding: 12px 20px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; color: white; transition: 0.2s; width: 100%; }
        .btn-primary { background: #00A693; }
        .btn-danger { background: #d32f2f; }
        .msg-box { padding: 15px; margin-bottom: 20px; border-radius: 5px; text-align: center; font-weight: 600; max-width: 800px; margin: 0 auto 20px; }
        .msg-success { background: #e8f5e9; color: #2e7d32; }
        .msg-error { background: #ffebee; color: #c62828; }
    </style>
</head>
<body>
    <div class="container">
        <div id="sidebar-menu" class="sidebar">
            <div class="logo" onclick="toggleSidebar()">
                <span class="material-icons">menu</span><span class="logo-text">📚 Smart Library</span>
            </div>
            <ul class="nav-list">
                <li class="nav-item"><a href="librarian.php"><span class="material-icons nav-icon">dashboard</span><span class="logo-text">Dashboard</span></a></li>
                <li class="nav-item"><a href="book_inventory.php"><span class="material-icons nav-icon">inventory_2</span><span class="logo-text">Inventory</span></a></li>
                <li class="nav-item active"><a href="manage_books.php"><span class="material-icons nav-icon">edit_note</span><span class="logo-text">Manage Books</span></a></li>
            </ul>
            <div class="logout nav-item" style="margin-top:auto;">
                <a href="login.php"><span class="material-icons nav-icon">logout</span><span class="logo-text">Logout</span></a>
            </div>
        </div>

        <div id="main-area" class="main">
            <h2>Manage Books</h2>
            
            <?php if($msg): ?>
                <div class="msg-box msg-<?php echo $type; ?>"><?php echo $msg; ?></div>
            <?php endif; ?>

            <div class="tabs">
                <a href="?tab=add" class="tab-link <?php echo $activeTab=='add'?'active':''; ?>">Add Book</a>
                <a href="?tab=update" class="tab-link <?php echo $activeTab=='update'?'active':''; ?>">Update Book</a>
                <a href="?tab=archive" class="tab-link <?php echo $activeTab=='archive'?'active':''; ?>">Archive</a>
            </div>

            <?php if($activeTab === 'add'): ?>
                <div class="form-card">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add">
                        <div class="form-group">
                            <label class="form-label">ISBN</label>
                            <input type="text" name="isbn" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <div style="display:flex; gap:20px;">
                                <div style="flex:1;">
                                    <label class="form-label">Author</label>
                                    <input type="text" name="author" class="form-input" required>
                                </div>
                                <div style="flex:1;">
                                    <label class="form-label">Category</label>
                                    <input type="text" name="category" class="form-input" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <div style="display:flex; gap:20px;">
                                <div style="flex:1;">
                                    <label class="form-label">Price</label>
                                    <input type="number" step="0.01" name="price" class="form-input" required>
                                </div>
                                <div style="flex:1;">
                                    <label class="form-label">Quantity</label>
                                    <input type="number" name="quantity" class="form-input" value="1" min="1" required>
                                </div>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Cover Image</label>
                            <input type="file" name="cover_image" class="form-input">
                        </div>
                        <button class="btn btn-primary">Add Book</button>
                    </form>
                </div>
            <?php endif; ?>

            <?php if($activeTab === 'update'): ?>
                <div class="form-card">
                    <form method="GET" style="margin-bottom:30px; display:flex; gap:10px;">
                        <input type="hidden" name="tab" value="update">
                        <input type="text" name="isbn" class="form-input" placeholder="Enter ISBN to Edit" value="<?php echo $_GET['isbn']??''; ?>" required>
                        <button class="btn btn-primary" style="width:auto;">Search</button>
                    </form>

                    <?php if($bookToEdit): ?>
                        <form method="POST">
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="book_id" value="<?php echo $bookToEdit['BookID']; ?>">
                            <input type="hidden" name="isbn" value="<?php echo $bookToEdit['ISBN']; ?>"> <div class="form-group">
                                <label class="form-label">Title</label>
                                <input type="text" name="title" class="form-input" value="<?php echo htmlspecialchars($bookToEdit['Title']); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Author</label>
                                <input type="text" name="author" class="form-input" value="<?php echo htmlspecialchars($bookToEdit['Author']); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Price</label>
                                <input type="number" step="0.01" name="price" class="form-input" value="<?php echo $bookToEdit['Price']; ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category</label>
                                <input type="text" name="category" class="form-input" value="<?php echo htmlspecialchars($bookToEdit['Category']); ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Total Stock (Currently: <?php echo $bookToEdit['CopiesTotal']; ?>)</label>
                                <input type="number" name="quantity" class="form-input" value="<?php echo $bookToEdit['CopiesTotal']; ?>" min="0">
                                <small style="color:grey">Adjusting this will add/remove available copies.</small>
                            </div>
                            <button class="btn btn-primary">Save Changes</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if($activeTab === 'archive'): ?>
                <div class="form-card">
                    <div style="background:#ffcdd2; padding:15px; color:#c62828; border-radius:5px; margin-bottom:20px;">
                        Warning: Archiving a book removes it from the active catalog.
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="archive">
                        <div class="form-group">
                            <label class="form-label">Book ISBN</label>
                            <input type="text" name="isbn" class="form-input" required placeholder="ISBN to archive">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Reason</label>
                            <select name="reason" class="form-input">
                                <option>Lost</option>
                                <option>Damaged</option>
                                <option>Outdated</option>
                            </select>
                        </div>
                        <button class="btn btn-danger">Archive Permanently</button>
                    </form>
                </div>
            <?php endif; ?>

        </div>
    </div>
    
    <script>
        function toggleSidebar() {
            document.getElementById('sidebar-menu').classList.toggle('active');
            document.getElementById('main-area').classList.toggle('pushed');
        }
    </script>
</body>
</html>