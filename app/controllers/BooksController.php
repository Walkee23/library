<?php
// app/controllers/BooksController.php
require_once __DIR__ . '/../models/database.php';

class BooksController {
    private $pdo;
    private $userID;

    public function __construct($pdo, $userID) {
        $this->pdo = $pdo;
        $this->userID = $userID;
    }

    // --- 1. INVENTORY (Read) ---
    public function getInventory($params) {
        $search = trim($params['search'] ?? '');
        $status = trim($params['status'] ?? 'All');
        $category = trim($params['category'] ?? 'All');
        $page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $limit = 5; 
        $offset = ($page - 1) * $limit;

        $categories = $this->pdo->query("SELECT DISTINCT Category FROM Book WHERE Category != '' ORDER BY Category")->fetchAll(PDO::FETCH_COLUMN);

        $where = ["Status != 'Archived'"];
        $args = [];

        if ($status !== 'All') { $where[] = "Status = ?"; $args[] = $status; }
        if ($category !== 'All') { $where[] = "Category = ?"; $args[] = $category; }
        if ($search) {
            $where[] = "(Title LIKE ? OR Author LIKE ? OR ISBN LIKE ?)";
            $args[] = "%$search%"; $args[] = "%$search%"; $args[] = "%$search%";
        }

        $whereSQL = implode(" AND ", $where);

        // Pagination
        $stmtCount = $this->pdo->prepare("SELECT COUNT(*) FROM Book WHERE $whereSQL");
        $stmtCount->execute($args);
        $total = $stmtCount->fetchColumn();
        $totalPages = ceil($total / $limit);

        // Data
        $sql = "
            SELECT B.*, 
            (SELECT COUNT(CopyID) FROM Book_Copy BC WHERE BC.BookID = B.BookID) as CopiesTotal,
            (SELECT COUNT(CopyID) FROM Book_Copy BC WHERE BC.BookID = B.BookID AND BC.Status = 'Available') as CopiesAvailable
            FROM Book B 
            WHERE $whereSQL 
            ORDER BY Title ASC 
            LIMIT $limit OFFSET $offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($args);
        $books = $stmt->fetchAll();

        return [
            'books' => $books, 'categories' => $categories, 
            'total_pages' => $totalPages, 'current_page' => $page,
            'filters' => ['search' => $search, 'status' => $status, 'category' => $category]
        ];
    }

    // --- 2. ADD BOOK (Create) ---
    public function addBook($post, $files) {
        $title = trim($post['title']);
        $isbn = trim($post['isbn']);
        $qty = (int)$post['quantity'];
        
        if(empty($title) || empty($isbn) || $qty < 1) return ['msg' => 'Invalid input.', 'type' => 'error'];

        $coverPath = null;
        if (isset($files['cover_image']) && $files['cover_image']['error'] === 0) {
            $ext = pathinfo($files['cover_image']['name'], PATHINFO_EXTENSION);
            $newName = $isbn . '-' . time() . '.' . $ext;
            if(move_uploaded_file($files['cover_image']['tmp_name'], __DIR__ . '/../../public/covers/' . $newName)) {
                $coverPath = 'public/covers/' . $newName;
            }
        }

        try {
            $this->pdo->beginTransaction();
            $sql = "INSERT INTO Book (Title, Author, ISBN, Price, Category, CoverImagePath, Status) VALUES (?,?,?,?,?,?, 'Available')";
            $this->pdo->prepare($sql)->execute([$title, $post['author'], $isbn, $post['price'], $post['category'], $coverPath]);
            $bookID = $this->pdo->lastInsertId();

            $copySql = $this->pdo->prepare("INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')");
            for($i=0; $i<$qty; $i++) $copySql->execute([$bookID]);

            $this->logAction($bookID, 'Added', "Added book '$title' with $qty copies.");
            $this->pdo->commit();
            return ['msg' => 'Book added successfully.', 'type' => 'success'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['msg' => 'Error: ' . $e->getMessage(), 'type' => 'error'];
        }
    }

    // --- 3. UPDATE BOOK (Update) ---
    public function updateBook($post) {
        $id = $post['book_id'];
        $newQty = (int)$post['quantity'];
        
        try {
            $this->pdo->beginTransaction();
            $sql = "UPDATE Book SET Title=?, Author=?, Price=?, Category=? WHERE BookID=?";
            $this->pdo->prepare($sql)->execute([$post['title'], $post['author'], $post['price'], $post['category'], $id]);

            $currentTotal = $this->pdo->query("SELECT COUNT(*) FROM Book_Copy WHERE BookID=$id")->fetchColumn();
            $diff = $newQty - $currentTotal;

            if($diff > 0) {
                $addStmt = $this->pdo->prepare("INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')");
                for($i=0; $i<$diff; $i++) $addStmt->execute([$id]);
            } elseif ($diff < 0) {
                $delStmt = $this->pdo->prepare("DELETE FROM Book_Copy WHERE BookID=? AND Status='Available' LIMIT 1");
                for($i=0; $i<abs($diff); $i++) $delStmt->execute([$id]);
            }

            $this->logAction($id, 'Updated', "Updated details and synced stock to $newQty.");
            $this->pdo->commit();
            return ['msg' => 'Book updated.', 'type' => 'success'];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['msg' => 'Update failed: ' . $e->getMessage(), 'type' => 'error'];
        }
    }

    // --- 4. ARCHIVE BOOK (Delete/Archive) ---
    public function archiveBook($post) {
        $isbn = $post['isbn'];
        $reason = $post['reason'];

        try {
            $book = $this->getBookByIsbn($isbn);
            if (!$book) return ['msg' => 'Book not found.', 'type' => 'error'];
            if($book['CopiesAvailable'] < $book['CopiesTotal']) return ['msg' => 'Cannot archive: Copies are currently borrowed.', 'type' => 'error'];

            $this->pdo->prepare("UPDATE Book SET Status='Archived', CopiesTotal=0, CopiesAvailable=0 WHERE BookID=?")->execute([$book['BookID']]);
            $this->logAction($book['BookID'], 'Archived', "Archived book. Reason: $reason");
            
            return ['msg' => 'Book archived.', 'type' => 'success'];
        } catch (Exception $e) {
            return ['msg' => 'Archive failed.', 'type' => 'error'];
        }
    }

    // Helpers
    public function getBookByIsbn($isbn) {
        $stmt = $this->pdo->prepare("
            SELECT B.*, 
            (SELECT COUNT(*) FROM Book_Copy WHERE BookID=B.BookID) as CopiesTotal,
            (SELECT COUNT(*) FROM Book_Copy WHERE BookID=B.BookID AND Status='Available') as CopiesAvailable
            FROM Book B WHERE ISBN = ? AND Status != 'Archived'
        ");
        $stmt->execute([$isbn]);
        return $stmt->fetch();
    }

    private function logAction($bookID, $type, $desc) {
        $stmt = $this->pdo->prepare("INSERT INTO Management_Log (UserID, BookID, ActionType, Description) VALUES (?, ?, ?, ?)");
        $stmt->execute([$this->userID, $bookID, $type, $desc]);
    }
}
?>