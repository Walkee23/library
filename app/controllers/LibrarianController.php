<?php
// app/controllers/LibrarianController.php

require_once __DIR__ . '/../models/database.php';

class LibrarianController {
    private $pdo;
    private $userID;

    public function __construct($pdo, $userID) {
        $this->pdo = $pdo;
        $this->userID = $userID;
    }

    // --- DASHBOARD (librarian.php) ---
    public function getDashboardStats() {
        try {
            // 1. Total Books
            $stmt1 = $this->pdo->query("SELECT COUNT(BookID) AS total FROM Book WHERE Status != 'Archived'");
            $totalBooks = $stmt1->fetch()['total'];

            // 2. Copies Available
            $stmt2 = $this->pdo->query("
                SELECT COUNT(BC.CopyID) AS available
                FROM Book_Copy BC
                JOIN Book B ON BC.BookID = B.BookID
                WHERE BC.Status = 'Available' AND B.Status != 'Archived'
            ");
            $copiesAvailable = $stmt2->fetch()['available'] ?? 0;

            // 3. Recent Activity
            $sql_activity = "
                SELECT ML.Timestamp, ML.ActionType, B.Title, U.Name AS UserName
                FROM Management_Log ML
                LEFT JOIN Book B ON ML.BookID = B.BookID
                JOIN Users U ON ML.UserID = U.UserID
                ORDER BY ML.Timestamp DESC
                LIMIT 5
            ";
            $recentActivity = $this->pdo->query($sql_activity)->fetchAll();

            return [
                'totalBooks' => $totalBooks,
                'copiesAvailable' => $copiesAvailable,
                'recentActivity' => $recentActivity
            ];
        } catch (PDOException $e) {
            error_log("Dashboard Error: " . $e->getMessage());
            return [];
        }
    }

    // --- INVENTORY (book_inventory.php) ---
    public function getInventory($params) {
        $search_term = trim($params['search'] ?? '');
        $status_filter = trim($params['status'] ?? 'All');
        $category_filter = trim($params['category'] ?? 'All');
        $current_page = filter_var($params['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1;
        $books_per_page = 4;
        $offset = ($current_page - 1) * $books_per_page;

        // Get Categories for Dropdown
        $categories = $this->pdo->query("SELECT DISTINCT Category FROM Book WHERE Category IS NOT NULL AND Category != '' ORDER BY Category ASC")->fetchAll(PDO::FETCH_COLUMN);

        // Build Query
        $base_sql = "FROM Book B WHERE B.Status != 'Archived'"; 
        if ($status_filter !== 'All') {
            $base_sql .= " AND B.Status = " . $this->pdo->quote($status_filter);
        }
        if ($category_filter !== 'All') {
            $base_sql .= " AND B.Category = " . $this->pdo->quote($category_filter);
        }
        if (!empty($search_term)) {
            $safe_search = $this->pdo->quote('%' . $search_term . '%');
            $base_sql .= " AND (B.Title LIKE $safe_search OR B.Author LIKE $safe_search OR B.ISBN LIKE $safe_search)";
        }

        // Fetch Total & Pages
        $total_books = $this->pdo->query("SELECT COUNT(B.BookID) " . $base_sql)->fetchColumn();
        $total_pages = ceil($total_books / $books_per_page);

        // Fetch Books
        $dynamic_fields = "
            B.BookID, B.Title, B.Author, B.ISBN, B.Price, B.CoverImagePath, B.Status, B.Category,
            (SELECT COUNT(BC1.CopyID) FROM Book_Copy BC1 WHERE BC1.BookID = B.BookID) AS CopiesTotal,
            (SELECT COUNT(BC2.CopyID) FROM Book_Copy BC2 WHERE BC2.BookID = B.BookID AND BC2.Status = 'Available') AS CopiesAvailable
        ";
        $list_sql = "SELECT {$dynamic_fields} " . $base_sql . " ORDER BY B.Title ASC LIMIT {$books_per_page} OFFSET {$offset}";
        $books = $this->pdo->query($list_sql)->fetchAll();

        return [
            'books' => $books,
            'categories' => $categories,
            'total_pages' => $total_pages,
            'current_page' => $current_page,
            'search_term' => $search_term,
            'status_filter' => $status_filter,
            'category_filter' => $category_filter
        ];
    }

    // --- ADD BOOK (add_book.php) ---
    public function addBook($postData, $files) {
        $title = trim($postData['title'] ?? '');
        $author = trim($postData['author'] ?? '');
        $isbn = trim($postData['isbn'] ?? '');
        $price = filter_var($postData['price'] ?? 0.00, FILTER_VALIDATE_FLOAT);
        $category = trim($postData['category'] ?? '');
        $quantity = filter_var($postData['quantity'] ?? 1, FILTER_VALIDATE_INT);
        $coverImagePath = NULL;

        if (empty($title) || empty($isbn) || $price === false || empty($category) || $quantity < 1) {
            return ['msg' => "Please check all input values.", 'type' => 'error'];
        }

        // File Upload Logic
        if (isset($files['cover_image']) && $files['cover_image']['error'] === UPLOAD_ERR_OK) {
            $fileTmpPath = $files['cover_image']['tmp_name'];
            $fileName = $files['cover_image']['name'];
            $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $newFileName = $isbn . '-' . time() . '.' . $fileExtension;
            $uploadFileDir = __DIR__ . '/../../public/covers/';
            
            if (move_uploaded_file($fileTmpPath, $uploadFileDir . $newFileName)) {
                $coverImagePath = 'public/covers/' . $newFileName;
            } else {
                return ['msg' => "Error uploading file.", 'type' => 'error'];
            }
        }

        try {
            $this->pdo->beginTransaction();

            // Insert Book
            $sql = "INSERT INTO Book (Title, Author, ISBN, Price, CoverImagePath, Category, Status) 
                    VALUES (?, ?, ?, ?, ?, ?, 'Available')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$title, $author, $isbn, $price, $coverImagePath, $category]);
            $newBookId = $this->pdo->lastInsertId();

            // Insert Copies
            if ($quantity > 0) {
                $copySql = "INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')";
                $copyStmt = $this->pdo->prepare($copySql);
                for ($i = 0; $i < $quantity; $i++) {
                    $copyStmt->execute([$newBookId]);
                }
            }

            // Log
            $logSql = "INSERT INTO Management_Log (UserID, BookID, ActionType, Description) VALUES (?, ?, 'Added', ?)";
            $this->pdo->prepare($logSql)->execute([$this->userID, $newBookId, "Added book '{$title}' (ISBN {$isbn}). Total copies: {$quantity}."]);

            $this->pdo->commit();
            return ['msg' => "Book '{$title}' added successfully!", 'type' => 'success'];

        } catch (PDOException $e) {
            $this->pdo->rollBack();
            if ($e->getCode() === '23000') return ['msg' => "Error: ISBN '{$isbn}' already exists.", 'type' => 'error'];
            return ['msg' => "Database Error: " . $e->getMessage(), 'type' => 'error'];
        }
    }

    // --- UPDATE BOOK HELPER (Fetch Data) ---
    public function getBookByIsbn($isbn) {
        $sql = "
            SELECT 
                B.BookID, B.Title, B.Author, B.ISBN, B.Price, B.Category, 
                (SELECT COUNT(BC1.CopyID) FROM Book_Copy BC1 WHERE BC1.BookID = B.BookID) AS CopiesTotal,
                (SELECT COUNT(BC2.CopyID) FROM Book_Copy BC2 WHERE BC2.BookID = B.BookID AND BC2.Status = 'Available') AS CopiesAvailable
            FROM Book B 
            WHERE B.ISBN = ? AND B.Status != 'Archived'
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$isbn]);
        return $stmt->fetch();
    }

    // --- UPDATE BOOK (update_book.php) ---
    public function updateBook($postData) {
        $bookID = filter_var($postData['book_id'] ?? null, FILTER_VALIDATE_INT);
        $title = trim($postData['title'] ?? '');
        $author = trim($postData['author'] ?? '');
        $isbn = trim($postData['isbn'] ?? ''); // Used for lookup/logging
        $category = trim($postData['category'] ?? '');
        $price = filter_var($postData['price'] ?? 0.00, FILTER_VALIDATE_FLOAT);
        $new_quantity = filter_var($postData['quantity'] ?? 0, FILTER_VALIDATE_INT);

        if (!$bookID || empty($title) || $price === false || $new_quantity < 0) {
            return ['msg' => "Please check inputs.", 'type' => 'error'];
        }

        try {
            $this->pdo->beginTransaction();

            // Check current stock to calculate difference
            $current_book = $this->getBookByIsbn($isbn); // Reuse helper
            if (!$current_book) throw new Exception("Book not found for stock calculation.");

            $old_total = $current_book['CopiesTotal'];
            $old_available = $current_book['CopiesAvailable'];
            $currently_borrowed = $old_total - $old_available;
            $diff = $new_quantity - $old_total;

            // Validate logic: Cannot reduce total below borrowed amount
            if ($new_quantity < $currently_borrowed) {
                $this->pdo->rollBack();
                return ['msg' => "Error: Cannot reduce stock below currently borrowed amount ({$currently_borrowed}).", 'type' => 'error'];
            }

            // Update Book Details
            $sql = "UPDATE Book SET Title = ?, Author = ?, Price = ?, Category = ? WHERE BookID = ?";
            $this->pdo->prepare($sql)->execute([$title, $author, $price, $category, $bookID]);

            // Adjust Copies
            if ($diff > 0) {
                $copyStmt = $this->pdo->prepare("INSERT INTO Book_Copy (BookID, Status) VALUES (?, 'Available')");
                for ($i = 0; $i < $diff; $i++) $copyStmt->execute([$bookID]);
            } elseif ($diff < 0) {
                $removeCount = abs($diff);
                // Remove 'Available' copies only
                $stmtDel = $this->pdo->prepare("DELETE FROM Book_Copy WHERE BookID = ? AND Status = 'Available' LIMIT ?");
                // PDO LIMIT binding trick for int
                $stmtDel->bindValue(1, $bookID, PDO::PARAM_INT);
                $stmtDel->bindValue(2, $removeCount, PDO::PARAM_INT);
                $stmtDel->execute();
            }

            // Log
            $logMsg = "Updated details. Stock adjusted to {$new_quantity} (Prev: {$old_total}).";
            $this->pdo->prepare("INSERT INTO Management_Log (UserID, BookID, ActionType, Description) VALUES (?, ?, 'Updated', ?)")
                      ->execute([$this->userID, $bookID, $logMsg]);

            $this->pdo->commit();
            return ['msg' => "Book updated successfully!", 'type' => 'success'];

        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['msg' => "Update Error: " . $e->getMessage(), 'type' => 'error'];
        }
    }

    // --- ARCHIVE BOOK (archive_book.php) ---
    public function archiveBook($postData) {
        $isbn = trim($postData['isbn'] ?? '');
        $reason = trim($postData['reason'] ?? 'Not specified');

        if (empty($isbn)) return ['msg' => "Please enter ISBN.", 'type' => 'error'];

        try {
            // Check Book Status
            $book = $this->getBookByIsbn($isbn);
            if (!$book) return ['msg' => "Book not found or already archived.", 'type' => 'error'];

            // Check if copies are borrowed
            if ($book['CopiesAvailable'] < $book['CopiesTotal']) {
                return ['msg' => "Error: Cannot archive. Some copies are currently borrowed.", 'type' => 'error'];
            }

            // Archive
            $this->pdo->prepare("UPDATE Book SET Status = 'Archived', CopiesTotal = 0, CopiesAvailable = 0 WHERE BookID = ?")
                      ->execute([$book['BookID']]);

            // Log
            $desc = "Archived book '{$book['Title']}'. Reason: {$reason}.";
            $this->pdo->prepare("INSERT INTO Management_Log (UserID, BookID, ActionType, Description) VALUES (?, ?, 'Archived', ?)")
                      ->execute([$this->userID, $book['BookID'], $desc]);

            return ['msg' => "Book archived successfully.", 'type' => 'success'];

        } catch (PDOException $e) {
            return ['msg' => "Database Error.", 'type' => 'error'];
        }
    }
}
?>