<?php
// Set execution time higher for large libraries (300 seconds = 5 minutes)
set_time_limit(300); 

// Include the database connection setup. This file uses your config.php constants (DB_HOST, etc.) 
require_once __DIR__ . '/app/models/database.php'; 

// Define the desired cover size: S (Small), M (Medium), L (Large)
$cover_size = 'L'; 
$books_updated = 0;

echo "<h1>Starting Book Cover Fetcher...</h1>";

try {
    // 1. Get all books that have an ISBN but are missing a cover URL
    // CORRECTED: Table name is 'book', Image column is 'CoverImagePath', Status check is 'Status != 'Archived''
    $stmt = $pdo->prepare("SELECT BookID, ISBN FROM book 
                          WHERE ISBN IS NOT NULL 
                          AND CoverImagePath IS NULL 
                          AND Status != 'Archived'"); 
    $stmt->execute();
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<p>Found " . count($books) . " books needing cover updates.</p>";

    // 2. Loop through each book and generate/store the Open Library URL
    foreach ($books as $book) {
        // NOTE: Column names are case-sensitive here, using the capitalized names from schema
        $book_id = $book['BookID']; 
        $isbn = $book['ISBN'];

        // Open Library URL Pattern
        // Example: https://covers.openlibrary.org/b/isbn/9780321765723-L.jpg
        $open_library_url = "https://covers.openlibrary.org/b/isbn/" . urlencode($isbn) . "-" . $cover_size . ".jpg";

        // 3. Update the database
        // CORRECTED: Updating the 'CoverImagePath' column in the 'book' table
        $update_stmt = $pdo->prepare("UPDATE book SET CoverImagePath = ? WHERE BookID = ?");
        $update_stmt->execute([$open_library_url, $book_id]);

        $books_updated++;
        echo "<p>✅ Updated Book ID {$book_id} ({$isbn}) with URL: {$open_library_url}</p>";
    }

    echo "<h2>Process Complete! Total books updated: {$books_updated}</h2>";

} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

?>