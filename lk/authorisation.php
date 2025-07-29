<?php
if (isset($_SESSION['auth'])) {
    header('Location: personal_area.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" type="text/css" href="../graphic/blocks.css">
    <title>Авторизация</title>
</head>
<body>
    <form action="check_auth.php" method="get" class="form">
        <label for="login">Логин:</label>
        <input type="text" id="login" name="login" required><br><br>
        
        <label for="password">Пароль:</label>
        <input type="password" id="password" name="password" required><br><br>
        
        <input type="submit" value="Войти">
    </form>
    <!-- После тега <form> -->
    <?php if (isset($_GET['error'])): ?>
    <div class="error-container">
        <div class="error-message">
            Мозги... не те! Попробуй еще!
        </div>
    </div>
<?php endif; ?>
</body>
</html>

<?php

?>