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

            case 'change_bet':
                // Изменение ставки игрока
                $userId = $_POST['user_id'] ?? null;
                $newTargetId = $_POST['new_target_id'] ?? null;
                $newBetAmount = $_POST['new_bet_amount_id'] ?? null;

                if (!$userId || !$newTargetId || $newBetAmount <= 0) {
                    throw new Exception('Не выбран игрок или новая цель');
                }

                // Проверяем, что новый выбор цели допустим
                $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND status = "жив"');
                $stmt->execute([$newTargetId]);
                if (!$stmt->fetchColumn()) {
                    throw new Exception('Неверный выбор новой цели');
                }

                // Обновляем ставку игрока
                $stmt = $pdo->prepare('
                    UPDATE bets 
                    SET target_id = ?, amount = ? 
                    WHERE user_id = ? AND round_id = (SELECT MAX(round_id) FROM rounds)
                ');
                $stmt->execute([$newTargetId, $newBetAmount, $userId]);

                logAction($pdo, $_SESSION['user_id'], 'bet', [
                    'action' => 'change_bet',
                    'user_id' => $userId,
                    'new_target_id' => $newTargetId,
                    'new_bet_amount' => $newBetAmount
                ]);
                break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: manage_bets.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение текущих ставок
$currentRound = getCurrentRound($pdo);
if ($currentRound) {
    $stmt = $pdo->prepare('
        SELECT u.name AS bettor, t.name AS target, b.amount
        FROM bets b
        JOIN users u ON b.user_id = u.user_id
        JOIN users t ON b.target_id = t.user_id
        WHERE b.round_id = ?
    ');
    $stmt->execute([$currentRound]);
    $bets = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $bets = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление ставками</title>
    <link rel="stylesheet" href="../graphic/manage_bets.css">
</head>
<body>
    <h1>Управление ставками</h1>

    <!-- Изменение ставки игрока -->
    <form method="POST">
        <h2>Изменить ставку игрока</h2>
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

        <label for="new_bet_amount_id">Новая ставка</label>
        <input type="number" name="new_bet_amount_id" id="new_bet_amount_id" value="0">

        <button type="submit" name="action" value="change_bet">Изменить ставку</button>
    </form>

    <!-- Текущие ставки -->
    <h2>Текущие ставки</h2>
    <?php if (empty($bets)): ?>
        <p>Ставок пока нет.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Игрок</th>
                    <th>Цель</th>
                    <th>Ставка</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bets as $bet): ?>
                    <tr>
                        <td><?= htmlspecialchars($bet['bettor']) ?></td>
                        <td><?= htmlspecialchars($bet['target']) ?></td>
                        <td><?= htmlspecialchars($bet['amount']) ?></td>
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