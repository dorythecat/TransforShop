<?php
require_once 'secrets.php';

if (session_status() == PHP_SESSION_NONE) session_start();
$db = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_DATABASE);
if (!$db) die("Connection failed: " . mysqli_connect_error());
$login_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = mysqli_real_escape_string($db, $_POST['username']);
    $password = mysqli_real_escape_string($db, $_POST['password']);
    $password_hash = md5($password); // Use MD5 hashing for password comparison
    $result = mysqli_query($db, "SELECT * FROM admins WHERE username='$username' AND password_hash='$password_hash';");
    if (mysqli_num_rows($result) === 1) {
        $_SESSION['admin_logged_in'] = true;
        header("Location: admin.php");
        exit();
    } else $login_error = 'Invalid username or password.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - TransforShop</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header id="navbar">
        <h1>TransforShop Admin Login</h1>
    </header>
    <main>
        <section class="login-form">
            <h2>Login</h2>
            <?php if ($login_error): ?>
                <p class="error"><?php echo $login_error; ?></p>
            <?php endif; ?>
            <form method="POST" action="">
                <input type="text" name="username" placeholder="Username" required>
                <input type="password" name="password" placeholder="Password" required>
                <button type="submit" name="login">Login</button>
            </form>
        </section>
    </main>
</body>
</html>
