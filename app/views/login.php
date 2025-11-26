<?php
session_start();

require_once __DIR__ . '/../../config.php'; 
require_once __DIR__ . '/../models/database.php'; 

$error_message = '';

// Check if the form was submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Sanitize and collect inputs
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error_message = "Please enter both your username and password.";
    } else {
        try {
            // 2. Prepare the query: Fetch user by ONLY Username
            $stmt = $pdo->prepare("SELECT UserID, Name, Role, PasswordHash FROM Users WHERE Username = ?");
            $stmt->execute([$username]);
            $user_data = $stmt->fetch();

            if ($user_data) {
                // 3. Verify the submitted password against the stored hash
                if (password_verify($password, $user_data['PasswordHash'])) {
                    
                    // Authentication successful!
                    $_SESSION['user_id'] = $user_data['UserID'];
                    $_SESSION['username'] = $username;
                    $_SESSION['name'] = $user_data['Name'];
                    $_SESSION['role'] = $user_data['Role']; // Role determined by the database
                    
                    // 4. Determine redirection based on the authenticated role
                    $role = $_SESSION['role'];
                    $dashboard_url = '';

                    switch ($role) {
                        case 'Librarian':
                            $dashboard_url = '/views/librarian.php';
                            break;
                        case 'Staff':
                            $dashboard_url = '/views/staff.php';
                            break;
                        case 'Student':
                            $dashboard_url = '/views/student.php'; 
                            break;
                        case 'Teacher':
                            $dashboard_url = '/views/teacher.php'; 
                            break;
                        default:
                            $dashboard_url = '/views/login.php'; 
                            break;
                    }

                    // Perform the final redirection using BASE_URL
                    header("Location: " . BASE_URL . $dashboard_url);
                    exit();
                    
                } else {
                    $error_message = "Invalid password.";
                }
            } else {
                $error_message = "User not found with the provided username.";
            }

        } catch (PDOException $e) {
            error_log("Login PDO Error: " . $e->getMessage()); 
            $error_message = "An internal error occurred. Please try again later.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Smart Library Web System Login</title>

    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;800&display=swap" rel="stylesheet">

    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">

    <style>
        body {
            font-family: 'Poppins', sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(to bottom, #E0F7FA, #FFFFFF);
            background-attachment: fixed;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            position: relative; 
        }

        /* --- Custom Container & Layout --- */
        .page-canvas {
            width: 1440px; 
            height: 690px; 
            position: relative;
            top: 0;
            left: 0;
        }

        /* Styling for BOTH Separate White Cards (Panels) */
        .info-panel, .login-panel {
            position: absolute;
            background-color: #ffffff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            border-radius: 15px;
            box-sizing: border-box;
        }
        
        /* --- INFO PANEL (Left Card) --- */
        .info-panel {
            width: 395px;
            height: 515px;
            top: 80px;
            left: 75px;
            padding: 30px; 
            display: flex;
            flex-direction: column;
            justify-content: left;
            align-items: center;
            text-align: left;
        }

        .info-panel h1 {
            font-size: 32px;
            color: #000;
            margin-left: -50px;
            margin-top: 5px;
            margin-bottom: 20px;
            font-weight: bold;
            line-height: 1.4;
        }

        .logo-icon {
            font-size: 33px; 
            margin-right: 5px;
        }

        .system-image {
            width: 100%;
            max-width: 290px; 
            height: 260px;
            margin-top: 40px;
            background-position: center;
            justify-content: center;
            align-items: center;
        }

        /* --- LOGIN PANEL (Right Card) --- */
        .login-panel {
            width: 600px;
            height: 450px;
            top: 145px;
            left: 550px; 
            padding: 70px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .welcome-header {
            font-size: 27px;
            font-weight: 600;
            margin-top: -25px;
            color: #000;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .login-prompt {
            color: #666;
            margin-top: 4px;
            margin-bottom: 30px;
            font-size: 14px;
        }

        /* Form Styling */
        .form-group {
            margin-bottom: 20px;
            position: relative; /* For icon placement */
        }
        
        /* Icon Styling */
        .form-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 20px;
        }
        
        .error-message {
            background-color: #ffcdd2;
            color: #d32f2f;
            border: 1px solid #d32f2f;
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
            width: 90%;
            box-sizing: border-box;
        }


        .form-input {
            width: 90%;
            padding: 12px 15px 12px 40px; /* Added left padding for icon */
            border: 2px solid #ccc;
            border-radius: 8px;
            box-sizing: border-box;
            font-size: 16px; /* Increased font size for input */
            outline: none;
            transition: border-color 0.3s, box-shadow 0.3s;
        }

        .form-input:focus {
            border-color: #00AFA0;
            box-shadow: 0 0 0 1px #00AFA0;
        }
        
        /* Button Styling */
        .login-button {
            width: 40%;
            background: linear-gradient(to right, #00AFA0, #00897B);
            color: white;
            padding: 14px 20px;
            margin-top: 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 17px;
            font-weight: bold;
            transition: background-color 0.2s, transform 0.2s;
        }
        
        .login-button:hover {
            transform: translateY(-1px); /* Subtle lift effect */
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
        }

        /* --- Responsive Design Warning/Fallback --- */
        @media (max-width: 1250px) {
            .page-canvas {
                width: 100%; 
                height: auto;
                position: static; 
                display: flex;
                flex-direction: column;
                align-items: center;
                gap: 20px;
                padding: 20px;
            }
            .info-panel, .login-panel {
                position: static; 
                width: 100%; 
                height: auto;
                max-width: 400px; 
                padding: 30px;
            }
            .form-input, .error-message {
                width: 100%;
                padding-left: 40px;
            }
            .login-panel {
                 padding: 50px 30px;
            }
            .login-button {
                 width: 100%;
            }
        }
    </style>
</head>

<body>
    <div class="page-canvas">
        <div class="info-panel">
            <h1><span class="logo-icon">📚</span>Smart Library<br>Web System</h1>
            <img class="system-image" src="/librarysystem/public/src/image.png" alt="Library System Illustration">
        </div>

        <div class="login-panel">
            <h2 class="welcome-header">Welcome!</h2>
            <p class="login-prompt">Login with your account</p>

            <?php
            // Display error message if authentication failed
            if (!empty($error_message)) {
                echo '<div class="error-message">' . htmlspecialchars($error_message) . '</div>';
            }
            ?>

            <form method="POST" action="login.php">
                <div class="form-group">
                    <span class="material-icons form-icon">person</span>
                    <input type="text" id="username" name="username" class="form-input" placeholder="Username" required>
                </div>

                <div class="form-group">
                    <span class="material-icons form-icon">lock</span>
                    <input type="password" id="password" name="password" class="form-input" placeholder="Password" required>
                </div>

                <button type="submit" class="login-button">Login</button>
            </form>
        </div>
    </div>

</body>
</html>