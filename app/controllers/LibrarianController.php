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

    public function getDashboardStats() {
        try {
            $totalBooks = $this->pdo->query("SELECT COUNT(BookID) FROM Book WHERE Status != 'Archived'")->fetchColumn();
            
            $copies = $this->pdo->query("
                SELECT COUNT(BC.CopyID) FROM Book_Copy BC 
                JOIN Book B ON BC.BookID = B.BookID 
                WHERE BC.Status='Available' AND B.Status != 'Archived'
            ")->fetchColumn();

            $activity = $this->pdo->query("
                SELECT ML.Timestamp, ML.ActionType, B.Title 
                FROM Management_Log ML 
                LEFT JOIN Book B ON ML.BookID = B.BookID 
                ORDER BY ML.Timestamp DESC LIMIT 5
            ")->fetchAll();

            return [
                'totalBooks' => $totalBooks,
                'copiesAvailable' => $copies,
                'recentActivity' => $activity
            ];
        } catch (Exception $e) {
            return [];
        }
    }
}
?>