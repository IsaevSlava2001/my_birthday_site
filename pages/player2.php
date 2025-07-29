<?php
session_start();
require '../config/connection.php';

$game_id = $_GET['game_id'] ?? null;

if (!$game_id) {
    die('Неверный ID игры');
}

// Проверяем, что это вторая сторона
$stmt = $pdo->prepare('
    SELECT * FROM rps_games 
    WHERE game_id = ? AND player2_id = ? AND player2_choice IS NULL
');
$stmt->execute([$game_id, $_SESSION['user_id']]);
$game = $stmt->fetch();

if (!$game) {
    die('Игра не найдена или уже завершена');
}

// Помечаем уведомление как прочитанное
$pdo->prepare('
    UPDATE notifications 
    SET is_read = 1 
    WHERE user_id = ? AND message LIKE "%КНБ: #' . $game_id . '%"
')->execute([$_SESSION['user_id']]);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Ваш ход в КНБ</title>
    <link rel="stylesheet" href="../graphic/process_rps.css">
</head>
<body>
    <div class="container">
        <?php if ($game): ?>
            <form method="post" action="../scripts/process_rps.php">
                <input type="hidden" name="game_id" value="<?= $game['game_id'] ?>">
                
                <h2>Ваш ход в игре <?= $game['game_id'] ?></h2>
                <div class="bet-amount">Ставка: <?= $game['bet_amount'] ?> монет</div>
                
                <div class="choice-container">
                    <label class="choice-option">
                        <input type="radio" name="choice" value="rock" required>
                        <img src="../images/rock.png" class="choice-icon">
                    </label>
                    <label class="choice-option">
                        <input type="radio" name="choice" value="paper">
                        <img src="../images/paper.png" class="choice-icon">
                    </label>
                    <label class="choice-option">
                        <input type="radio" name="choice" value="scissors">
                        <img src="../images/scissors.png" class="choice-icon">
                    </label>
                </div>
                
                <button type="submit">Сделать выбор</button>
            </form>
        <?php else: ?>
            <div class="error">Нет активных игр</div>
        <?php endif; ?>
    </div>
</body>
</html>