<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">

    <style>
        /* Global Styles */
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background-color: #F7FCFC; /* Requested background color */
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
            box-shadow: 2px 0 5px rgba(0,0,0,0.05);
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
            padding: 30px 50px;
        }

        /* Header/Welcome Message */
        .header {
            text-align: right;
            padding-bottom: 20px;
            font-size: 16px;
            color: #666;
        }
        
        .header span {
            font-weight: bold;
            color: #333;
        }

        /* Reservations Section */
        .reservations-section h2 {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 7px;
            margin-top: -7px;
        }

        .reservations-section p.subtitle {
            font-size: 0.9em;
            color: #666;
            margin-bottom: 45px;
        }

        /* Reservation Card Style */
        .reservation-card {
            background-color: #fff;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            display: flex;
            justify-content: space-between; /* Space out book info and button */
            align-items: center;
            margin-bottom: 25px;
            width: 90%;
        }

        .book-info strong {
            display: block;
            font-size: 1.1em;
            margin-bottom: 5px;
            margin-left: 6px;
        }

        .book-info span {
            font-size: 0.9em;
            color: #666;
            margin-left: 6px;
        }

        /* Cancel Button Styling */
        .cancel-button {
            background-color: #fff;
            color: #E53935; /* Red text color */
            border: 2px solid #E53935; /* Red border */
            padding: 8px 15px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.2s;
            font-weight: 500;
            margin-right: 18px;
        }

        .cancel-button:hover {
            background-color: #FFEBEB; /* Light red hover effect */
        }
        
        /* Empty Reservations Message */
        .no-reservations {
            text-align: center;
            margin-top: 100px; /* Space from the top */
            font-size: 0.95em;
            color: #777;
        }

    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">
                📚 Smart Library
            </div>
            <ul class="nav-list">
                <li class="nav-item"><a href="teacher.html">Dashboard</a></li>
                <li class="nav-item"><a href="#">Books</a></li>
                <li class="nav-item active"><a href="teacher_reservation.html">Reservations</a></li>
                <li class="nav-item"><a href="#">Borrowed Books</a></li>
                <li class="nav-item"><a href="#">Clearance Status</a></li>
            </ul>
            <div class="logout"><a href="login.html">Logout</a></div>
        </div>

        <div class="main-content">
            <div class="header">
                Welcome, <span>[Teacher's Name]</span>
            </div>

            <div class="reservations-section">
                <h2>My Reservations</h2>
                <p class="subtitle">Manage reserved books</p>

                <div class="reservation-card">
                    <div class="book-info">
                        <strong>[Book Title] by [Author]</strong>
                        <span>Reserved on: Sep 12, 2025 • Expires: Sep 19, 2025</span>
                    </div>
                    <button class="cancel-button">Cancel</button>
                </div>
                
                <div class="no-reservations">
                    No more reservations. Use "Reserve Book" to add reservations.
                </div>
            </div>
        </div>
    </div>

</body>
</html>