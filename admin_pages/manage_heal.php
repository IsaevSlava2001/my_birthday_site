<?php
session_start();
require '../config/connection.php';

// Проверка авторизации администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] !== 1) {
    header('Location: ../lk/authorisation.php');
    exit();
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $userId = $_POST['user_id'] ?? null;
        $newHp = (int)($_POST['hp'] ?? 0);
        $newStatus = $_POST['status'] ?? null;

        if (!$userId || $newHp < 0 || !$newStatus) {
            throw new Exception('Неверные параметры');
        }

        // Получаем текущие данные пользователя
        $stmt = $pdo->prepare('SELECT hp, status FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            throw new Exception('Пользователь не найден');
        }

        // Обновляем данные
        $stmt = $pdo->prepare('UPDATE users SET hp = ?, status = ? WHERE user_id = ?');
        $stmt->execute([$newHp, $newStatus, $userId]);

        // Перезагружаем страницу после выполнения действия
        header('Location: manage_heal.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение данных всех игроков
$stmt = $pdo->query('SELECT user_id, login, hp, status FROM users WHERE faction!=\'admin\' ORDER BY login ASC');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление жизнью</title>
    <link rel="stylesheet" href="../graphic/manage_heal.css">
</head>
<body>
    <h1>Управление жизнью</h1>

    <table>
        <thead>
            <tr>
                <th>Логин</th>
                <th>Текущее здоровье (HP)</th>
                <th>Текущий статус</th>
                <th>Изменить здоровье (HP)</th>
                <th>Изменить статус</th>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td><?= htmlspecialchars($user['hp']) ?></td>
                    <td><?= htmlspecialchars($user['status']) ?></td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                            <input type="number" name="hp" value="<?= $user['hp'] ?>" min="0">
                    </td>
                    <td>
                        <select name="status">
                            <option value="жив" <?= $user['status'] === 'жив' ? 'selected' : '' ?>>жив</option>
                            <option value="мёртв" <?= $user['status'] === 'мёртв' ? 'selected' : '' ?>>мёртв</option>
                            <option value="заражён" <?= $user['status'] === 'заражён' ? 'selected' : '' ?>>заражён</option>
                            <option value="фальшивая смерть" <?= $user['status'] === 'фальшивая смерть' ? 'selected' : '' ?>>фальшивая смерть</option>
                        </select>
                    </td>
                    <td>
                        <button type="submit">Сохранить</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
</body>
</html>