<?php
session_start();
$config = require __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pass = $_POST['password'] ?? '';
    if (password_verify($pass, $config['admin_password_hash'])) {
        $_SESSION['admin'] = true;
        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Błędne hasło";
    }
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Login</title><link rel="stylesheet" href="styles.css"></head>
<body>
<h1>Logowanie</h1>
<?php if(isset($error)) echo "<p style='color:red'>$error</p>"; ?>
<form method="post">
    <input type="password" name="password" placeholder="Hasło">
    <button>Zaloguj</button>
</form>
</body>
</html>
