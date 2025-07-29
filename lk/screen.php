<?php
session_start();
require '../config/connection.php';

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: authorisation.php');
    exit();
}

// Обработка нажатия кнопки обновления
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refresh_data'])) {
    // Просто перезагружаем страницу для повторного выполнения запроса
    $stmt = $pdo->query('
    SELECT 
        u.user_id,
        u.name as name,
        u.login, 
        u.status as user_status, 
        u.faction, 
        r.name as role_name, 
        u.vote_data
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.faction!=\'admin\'
');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Получение данных всех игроков (исходный запрос)
$stmt = $pdo->query('
    SELECT 
        u.user_id,
        u.name as name,
        u.login, 
        u.status as user_status, 
        u.faction, 
        r.name as role_name, 
        u.vote_data
    FROM users u
    LEFT JOIN roles r ON u.role_id = r.role_id
    WHERE u.faction!=\'admin\'
');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Статус игроков</title>
    <link href="https://fonts.googleapis.com/css2?family=Creepster&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../graphic/screen.css">

</head>
<body>
    <div class="zombie-container">
        <h1>Статус игроков <span class="zombie-hand">💀</span></h1>
        <!-- Кнопка обновления -->
        <form method="POST" style="text-align: center; margin: 20px 0;">
            <button type="submit" name="refresh_data" class="your-custom-class">
                Обновить данные
            </button>
        </form>

        <!-- Список игроков -->
        <div class="player-list">
            <?php foreach ($users as $user): ?>
                <div class="player-card">
                    <h2><?= htmlspecialchars($user['name']) ?></h2>
                    <script>console.log(<?=$user['vote_data']?>);</script>
                    <div class="player-info">
                        <?php if (!empty($user['vote_data'])): ?>
                            <?php $vote_data = json_decode($user['vote_data'], true); ?>
                            <?php if (isset($vote_data['status'])): ?>
                                <p><span class="label">Статус:</span> <span class="value"><?= htmlspecialchars($user['user_status']) ?></span></p>
                            <?php else: ?>
                                <p><span class="label">Статус:</span> <span class="value">Неизвестно</span></p>
                            <?php endif; ?>
                            <?php if (isset($vote_data['faction'])): ?>
                                <p><span class="label">Фракция:</span> <span class="value"><?= htmlspecialchars($user['faction']) ?></span></p>
                            <?php else: ?>
                                <p><span class="label">Фракция:</span> <span class="value">Неизвестно</span></p>
                            <?php endif; ?>
                            <?php if (isset($vote_data['role'])): ?>
                                <p><span class="label">Роль:</span> <span class="value"><?= htmlspecialchars($user['role_name']) ?></span></p>
                            <?php else: ?>
                                <p><span class="label">Роль:</span> <span class="value">Неизвестно</span></p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p><span class="label">Статус:</span> <span class="value">Неизвестно</span></p>
                            <p><span class="label">Фракция:</span> <span class="value">Неизвестно</span></p>
                            <p><span class="label">Роль:</span> <span class="value">Неизвестно</span></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</body>
</html>