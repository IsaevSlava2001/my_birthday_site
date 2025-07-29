<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

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
            throw new Exception('Неверные параметры');
        }

        switch ($action) {
            case 'start_voting':
                // Запуск голосования
                $stmt = $pdo->prepare('
                    UPDATE rounds 
                    SET phase = \'voting\'
                    WHERE round_id = (SELECT MAX(round_id) FROM rounds)
                ');
                $stmt->execute();

                logAction($pdo, $_SESSION['user_id'], 'vote', [
                    'action' => 'start'
                ]);
                break;

            case 'stop_voting':
                // Прекращение голосования
                $stmt = $pdo->prepare('
                    UPDATE rounds 
                    SET phase = \'day\' 
                    WHERE round_id = (SELECT MAX(round_id) FROM rounds)
                ');
                $stmt->execute();

                logAction($pdo, $_SESSION['user_id'], 'vote', [
                    'action' => 'stop'
                ]);
                break;

                case 'change_vote':
                    // Изменение голоса игрока
                    $userId = $_POST['user_id'] ?? null;
                    $newTargetId = $_POST['new_target_id'] ?? null;
                    $voteType = $_POST['vote_type'] ?? null;
    
                    if (!$userId || !$newTargetId || !in_array($voteType, ['kill', 'reveal_role', 'reveal_faction'])) {
                        throw new Exception('Не выбран игрок, цель или тип голосования');
                    }
    
                    // Проверяем, что новый выбор цели допустим
                    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND status = "жив"');
                    $stmt->execute([$newTargetId]);
                    if (!$stmt->fetchColumn()) {
                        throw new Exception('Неверный выбор новой цели');
                    }
    
                    // Обновляем голос игрока
                    $stmt = $pdo->prepare('
                        UPDATE votes 
                        SET target_id = ?, type = ? 
                        WHERE user_id = ? AND round = (SELECT MAX(round_id) FROM rounds)
                    ');
                    $stmt->execute([$newTargetId, $voteType, $userId]);
    
                    logAction($pdo, $_SESSION['user_id'], 'vote', [
                        'action' => 'change_vote',
                        'user_id' => $userId,
                        'new_target_id' => $newTargetId,
                        'vote_type' => $voteType
                    ]);
                    break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: manage_voting.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение текущих голосов
$currentRound = getCurrentRound($pdo);
if ($currentRound) {
    $stmt = $pdo->prepare('
        SELECT u.name AS voter, t.name AS target, v.type
        FROM votes v
        JOIN users u ON v.user_id = u.user_id
        JOIN users t ON v.target_id = t.user_id
        WHERE v.round = ?
    ');
    $stmt->execute([$currentRound]);
    $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $votes = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление голосованием</title>
    <link rel="stylesheet" href="../graphic/manage_voting.css">
</head>
<body>
    <h1>Управление голосованием</h1>

    <!-- Запуск голосования -->
    <form method="POST">
        <h2>Запустить голосование</h2>
        <button type="submit" name="action" value="start_voting">Запустить</button>
    </form>

    <!-- Прекращение голосования -->
    <form method="POST">
        <h2>Прекратить голосование</h2>
        <button type="submit" name="action" value="stop_voting">Прекратить</button>
    </form>

    <!-- Изменение голоса игрока -->
    <form method="POST">
        <h2>Изменить голос игрока</h2>
        <label for="user_id">Игрок:</label>
        <select name="user_id" id="user_id" required>
            <?php
            $stmt = $pdo->query('SELECT user_id, name FROM users WHERE status = "жив"');
            while ($user = $stmt->fetch(PDO::FETCH_ASSOC)):
            ?>
                <option value="<?= $user['user_id'] ?>"><?= htmlspecialchars($user['name']) ?></option>
            <?php endwhile; ?>
        </select>

        <label for="new_target_id">Новая цель:</label>
        <select name="new_target_id" id="new_target_id" required>
            <?php
            $stmt = $pdo->query('SELECT user_id, name FROM users WHERE status = "жив"');
            while ($target = $stmt->fetch(PDO::FETCH_ASSOC)):
            ?>
                <option value="<?= $target['user_id'] ?>"><?= htmlspecialchars($target['name']) ?></option>
            <?php endwhile; ?>
        </select>

        <label for="vote_type">Тип голосования:</label>
        <select name="vote_type" id="vote_type" required>
            <option value="kill">Убийство</option>
            <option value="reveal_role">Раскрытие роли</option>
            <option value="reveal_faction">Раскрытие фракции</option>
        </select>

        <button type="submit" name="action" value="change_vote">Изменить голос</button>
    </form>

    <!-- Текущие голоса -->
    <h2>Текущие голоса</h2>
    <?php if (empty($votes)): ?>
        <p>Голосов пока нет.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Игрок</th>
                    <th>Цель</th>
                    <th>Тип</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($votes as $vote): ?>
                    <tr>
                        <td><?= htmlspecialchars($vote['voter']) ?></td>
                        <td><?= htmlspecialchars($vote['target']) ?></td>
                        <td><?= htmlspecialchars($vote['type']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <a href="../lk/personal_admin_area.php">
        <button class="back-btn">Назад в админ-панель</button>
    </a>
</body>
</html>