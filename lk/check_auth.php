<?php
require '../config/connection.php';
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $login = $_GET['login']; // Получаем логин из формы
    $password = $_GET['password']; // Получаем пароль из формы
    //$password = password_hash($_GET['password'], PASSWORD_DEFAULT); // Хешируем пароль
    //echo $password.'<br>';

    // Подготовленный запрос с использованием существующего $pdo
    $stmt = $pdo->prepare('SELECT * FROM users WHERE login = ?');
    $stmt->execute([$login]);
    $user = $stmt->fetch();

    //var_dump($user);
    //echo $user['password_hash'].'<br>';
    //echo $password;
    //echo $user;
    //echo (password_verify($password, $user['password_hash']));
    if ($user && password_verify($password, $user['password_hash'])) {
        // Сохраняем данные в сессии
        $_SESSION['auth'] = true;
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['user'] = $user['login']; // Для отображения имени
        $stmt = $pdo->prepare('SELECT * FROM roles WHERE role_id = ?');
        $stmt->execute([$user['role_id']]);
        $role = $stmt->fetch();
        $_SESSION['role_id'] = $user['role_id'];
        $_SESSION['role'] = $role['name']; // Для отображения роли
        $_SESSION['faction'] = $user['faction'];
        $_SESSION['status'] = $user['status'];
        $_SESSION['game_status'] = $user['game_status'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['money'] = $user['money'];
        $_SESSION['hp'] = $user['hp'];
        
        header('Location: personal_area.php');
        exit();
    } else {
        header('Location: authorisation.php?error=1');
        exit();
    }
}
?>