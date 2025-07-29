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
        $action = $_POST['action'] ?? null;

        if (!$action) {
            throw new Exception('Неверный тип запроса');
        }

        switch ($action) {
            case 'add_player':
                $login = trim($_POST['login'] ?? '');
                $password = trim($_POST['password'] ?? '');
                $status = trim($_POST['status'] ?? '');
                $game_status = trim($_POST['game_status'] ?? '');
                $name = trim($_POST['name'] ?? '');

                if (empty($login) || empty($password) || empty($status) || empty($game_status) || empty($name)) {
                    throw new Exception('Все поля обязательны для заполнения');
                }

                // Проверяем уникальность логина
                $stmt = $pdo->prepare('SELECT user_id FROM users WHERE login = ?');
                $stmt->execute([$login]);
                if ($stmt->fetchColumn()) {
                    throw new Exception('Логин уже занят');
                }

                // Хешируем пароль
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                // Добавляем нового игрока
                $stmt = $pdo->prepare('
                    INSERT INTO users (login, password_hash, name, status, game_status)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $stmt->execute([$login, $hashedPassword, $name, $status, $game_status]);

                break;

            case 'update_player':
                $userId = (int)($_POST['user_id'] ?? 0);
                $newName = trim($_POST['name'] ?? '');
                $newGameStatus = trim($_POST['game_status'] ?? '');
                $newVoteData = trim($_POST['vote_data'] ?? '');

                if ($userId <= 0 || empty($newName) || empty($newGameStatus)) {
                    throw new Exception('Неверные параметры');
                }

                // Обновляем данные игрока
                $stmt = $pdo->prepare('
                    UPDATE users 
                    SET name = ?, game_status = ?, vote_data = ?
                    WHERE user_id = ?
                ');
                $stmt->execute([$newName, $newGameStatus, $newVoteData, $userId]);

                break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: ../admin_pages/manage_players.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение данных всех игроков
$stmt = $pdo->query('SELECT user_id, login, name, status, game_status, vote_data FROM users WHERE status!=\'admin\' ORDER BY login ASC');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление игроками</title>
    <link rel="stylesheet" href="../graphic/manage_players.css">
</head>
<body>
    <h1>Управление игроками</h1>

    <!-- Форма добавления нового игрока -->
    <div class="zombie-box">
        <h2>Добавить нового игрока</h2>
        <form method="POST">
            <input type="hidden" name="action" value="add_player">
            <label for="new_login">Логин:</label>
            <input type="text" name="login" id="new_login" required>
            <label for="new_password">Пароль:</label>
            <input type="password" name="password" id="new_password" required>
            <label for="new_name">Имя:</label>
            <input type="text" name="name" id="new_name" required>
            <label for="new_status">Статус:</label>
            <select name="status" id="new_status" required>
                <option value="жив">жив</option>
                <option value="мёртв">мёртв</option>
                <option value="заражён">заражён</option>
            </select>
            <label for="new_game_status">Game Status:</label>
            <select name="game_status" id="new_game_status" required>
                <option value="not-started">не начато</option>
                <option value="started">начато</option>
                <option value="find">поиск</option>
                <option value="create">создание</option>
            </select>
            <button type="submit">Добавить</button>
        </form>
    </div>

    <!-- Список игроков -->
    <table>
        <thead>
            <tr>
                <th>Логин</th>
                <th>Имя</th>
                <th>Статус</th>
                <th>Game Status</th>
                <th>Информация о голосовании</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td><?= htmlspecialchars($user['name']) ?></td>
                    <td><?= htmlspecialchars($user['status']) ?></td>
                    <td><?= htmlspecialchars($user['game_status']) ?></td>
                    <td class="vote-info">
                        <?php
                        $voteData = json_decode($user['vote_data'], true);
                        if ($voteData && isset($voteData['target_id'])) {
                            echo '<span>ID цели: ' . htmlspecialchars($voteData['target_id']) . '</span>';
                        } else {
                            echo '<span>Нет данных</span>';
                        }
                        ?>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="action" value="update_player">
                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                            <label for="edit_name_<?= $user['user_id'] ?>">Имя:</label>
                            <input type="text" name="name" id="edit_name_<?= $user['user_id'] ?>" value="<?= htmlspecialchars($user['name']) ?>" required>
                            <label for="edit_game_status_<?= $user['user_id'] ?>">Game Status:</label>
                            <select name="game_status" id="edit_game_status_<?= $user['user_id'] ?>" required>
                                <option value="not-started" <?= $user['game_status'] === 'not-started' ? 'selected' : '' ?>>не начато</option>
                                <option value="started" <?= $user['game_status'] === 'started' ? 'selected' : '' ?>>начато</option>
                                <option value="find" <?= $user['game_status'] === 'find' ? 'selected' : '' ?>>поиск</option>
                                <option value="create" <?= $user['game_status'] === 'create' ? 'selected' : '' ?>>создание</option>
                            </select>
                            <label for="edit_vote_data_<?= $user['user_id'] ?>">Голосование:</label>
                            <input type="text" name="vote_data" id="edit_vote_data_<?= $user['user_id'] ?>" value="<?= htmlspecialchars($user['vote_data'] ?? '') ?>">
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