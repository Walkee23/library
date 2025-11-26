<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Reservations</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">

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

        /* Sidebar Navigation */
        .sidebar {
            width: 250px;
            padding: 30px 0;
            background-color: #fff;
            border-right: 1px solid #eee;
            box-shadow: 2px 0 5px rgba(0, 0, 0, 0.05);
        }

        .logo {
            font-size: 16px;
            font-weight: bold;
            color: #000;
            padding: 0 30px 40px;
        }

        .nav-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .nav-item a {
            font-size: 15px;
            display: block;
            padding: 15px 30px;
            text-decoration: none;
            color: #6C6C6C;
            transition: background-color 0.2s;
        }

        .nav-item a:hover {
            background-color: #f0f0f0;
        }

        .nav-item.active a {
            color: #000;
            font-weight: bold;
        }

        .logout {
            margin-top: 50px;
            cursor: pointer;
        }

        .logout a {
            display: block;
            padding: 15px 30px;
            color: #6C6C6C;
            text-decoration: none;
            transition: background-color 0.2s;
        }

        .logout a:hover {
            background-color: #f0f0f0;
        }

        /* Main Content Area */
        .main-content {
            flex-grow: 1;
            padding: 30px 32px;
        }

        /* Reservation Section (Retained) */
        .reservation-section h2 {
            font-size: 25px;
            font-weight: 700;
            margin-bottom: 7px;
            margin-top: 0;
        }

        .reservation-section p.subtitle {
            font-size: 15px;
            color: #666;
            margin-bottom: 30px;
        }

        .reserve-search-button {
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            background-color: #00A693;
            color: #fff;
            cursor: pointer;
            min-width: 120px;
            font-weight: 600;
        }

        .reserve-search-button:hover {
            background-color: #008779;
        }

        /* Reservation List Styling (Retained) */
        .reservation-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 20px;
        }

        .reservation-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: #fff;
            padding: 15px 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
        }

        .book-info strong {
            font-weight: 600;
        }

        .book-info span {
            font-size: 14px;
            color: #666;
            display: block;
            margin-top: 3px;
        }

        .reservation-info {
            text-align: right;
            font-size: 15px;
        }

        .reservation-info .expires {
            color: #E5A000;
            font-weight: 600;
        }

        /* Action Button (Retained) */
        .cancel-btn {
            background-color: #F8D7DA;
            color: #721C24;
            border: 1px solid #F5C6CB;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .cancel-btn:hover {
            background-color: #F5C6CB;
        }

        /* Modal Styles (Removed the Reserve Modal HTML since it's used on the Borrow Page) */
    </style>
</head>

<body>

    <div class="container">
        <div class="sidebar">
            <div class="logo">
                📚 Smart Library
            </div>
            <ul class="nav-list">
                <li class="nav-item"><a href="student.php">Dashboard</a></li>
                <li class="nav-item"><a href="student_borrow.php">Books</a></li>
                <li class="nav-item active"><a href="student_reservation.php">Reservations</a></li>
                <li class="nav-item"><a href="studentborrowed_books.php">Borrowed Books</a>
                </li>
            </ul>
            <div class="logout"><a href="login.php">Logout</a></div>
        </div>

        <div class="main-content">

            <div class="reservation-section">
                <h2>Manage Reservations</h2>
                <p class="subtitle">Search for unavailable books to place a reservation or manage your current queue.
                </p>

                <h3>Your Current Reservations (<span id="reservationCount">0</span>)</h3>

                <div class="reservation-list" id="reservationList">
                    <p style="color: #666; font-style: italic;" id="noReservationsText">You have no active reservations.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        // --- Data Persistence and Retrieval (Shared) ---
        function getReservations() {
            try {
                const data = localStorage.getItem('studentReservations');
                // Parse and convert ISO string back to Date objects
                const reservations = data ? JSON.parse(data) : [];
                return reservations.map(r => ({
                    ...r,
                    expiration: new Date(r.expiration)
                }));
            } catch (e) {
                console.error("Error reading reservations from localStorage:", e);
                return [];
            }
        }

        function saveReservations(reservationsArray) {
            localStorage.setItem('studentReservations', JSON.stringify(reservationsArray));
        }

        function getBorrowedCount() {
            return parseInt(localStorage.getItem('studentBorrowedCount') || '0');
        }

        // --- Utility Functions ---

        function formatExpirationDate(date) {
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        }

        // --- Reservation Management ---

        function renderReservations() {
            const listElement = document.getElementById('reservationList');
            const reservations = getReservations();

            listElement.innerHTML = ''; // Clear existing list

            document.getElementById('reservationCount').textContent = reservations.length;
            document.getElementById('sidebarReservationCount').textContent = reservations.length;

            if (reservations.length === 0) {
                listElement.innerHTML = '<p style="color: #666; font-style: italic;" id="noReservationsText">You have no active reservations.</p>';
                return;
            }

            reservations.forEach(r => {
                const expiresString = formatExpirationDate(r.expiration);
                const item = document.createElement('div');
                item.className = 'reservation-item';
                item.dataset.id = r.id;

                item.innerHTML = `
                    <div class="book-info">
                        <strong>${r.title}</strong>
                        <span>By: ${r.author}</span>
                    </div>
                    <div class="reservation-info">
                        Reservation expires: <span class="expires">${expiresString}</span>
                        <button class="cancel-btn" onclick="cancelReservation(${r.id}, '${r.title}')">Cancel Reservation</button>
                    </div>
                `;
                listElement.appendChild(item);
            });
        }

        function cancelReservation(reservationId, bookTitle) {
            if (confirm(`Are you sure you want to cancel the reservation for "${bookTitle}"?`)) {
                // 1. Update state
                let reservations = getReservations();
                reservations = reservations.filter(r => r.id !== reservationId);
                saveReservations(reservations); // Save the updated list

                // 2. Update UI
                renderReservations();
                alert(`Reservation for "${bookTitle}" has been cancelled. The book is now available for the next student.`);
            }
        }

        // --- Sidebar Initialization ---
        function updateSidebar() {
            const reservations = getReservations();
            document.getElementById('sidebarReservationCount').textContent = reservations.length;
            document.getElementById('borrowed-books-nav').querySelector('a').textContent = `Borrowed Books (${getBorrowedCount()})`;
        }


        // Initialize the UI on load
        document.addEventListener('DOMContentLoaded', () => {
            updateSidebar();
            renderReservations();
        });
    </script>
</body>

</html>