<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

// Проверка прав администратора
if ($_SESSION['role_id'] !== 1) { // Предположим, что role_id=1 это админ
    die('Доступ запрещен');
}

// Проверка непрочитанных уведомлений
$stmt = $pdo->prepare('
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC
');
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

foreach ($notifications as $note) {
    if (strpos($note['message'], 'КНБ') !== false) {
        // Автоматически перенаправляем на игру
        $game_id = explode('#', $note['message'])[1];
        header('Location: ../pages/player2.php?game_id=' . $game_id);
        exit();
    }
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action_type = $_POST['action_type'] ?? null;
    $user_id = $_POST['user_id'] ?? null;
    $round_id = $_POST['round_id'] ?? null;
    
    try {
        switch ($action_type) {
            case 'start_voting':
                // Начать голосование
                $pdo->prepare('UPDATE rounds SET phase = "voting" WHERE round_id = ?')
                    ->execute([$round_id]);
                
                logAction($pdo, $_SESSION['user_id'], 'admin_action', [
                    'action' => 'start_voting',
                    'round_id' => $round_id
                ]);
                break;

            case 'end_voting':
                // Завершить голосование
                $pdo->prepare('UPDATE rounds SET phase = "day" WHERE round_id = ?')
                    ->execute([$round_id]);
                
                // Начисляем деньги за ставки
                $stmt = $pdo->prepare('
                    SELECT u.user_id, b.target_id 
                    FROM bets b
                    JOIN users u ON b.user_id = u.user_id
                    WHERE b.round_id = ? AND b.status = "active"
                ');
                $stmt->execute([$round_id]);
                $bets = $stmt->fetchAll();
                
                foreach ($bets as $bet) {
                    if ($bet['target_id'] == $_POST['killed_user_id']) {
                        $pdo->prepare('UPDATE users SET money = money + 4 WHERE user_id = ?')
                            ->execute([$bet['user_id']]);
                    }
                }
                
                logAction($pdo, $_SESSION['user_id'], 'admin_action', [
                    'action' => 'vote',
                    'round_id' => $round_id,
                    'killed_user_id' => $_POST['killed_user_id']
                ]);
                break;

            case 'kill_player':
                // Убить игрока
                $pdo->prepare('UPDATE users SET status = "мёртв" WHERE user_id = ?')
                    ->execute([$user_id]);
                
                logAction($pdo, $_SESSION['user_id'], 'admin_action', [
                    'action' => 'kill_player',
                    'target_user_id' => $user_id
                ]);
                break;

            case 'cure_infection':
                // Вылечить инфекцию
                $pdo->prepare('
                    UPDATE users 
                    SET status = "жив", infection_round = NULL 
                    WHERE user_id = ?
                ')->execute([$user_id]);
                
                logAction($pdo, $_SESSION['user_id'], 'admin_action', [
                    'action' => 'cure_infection',
                    'target_user_id' => $user_id
                ]);
                break;

            default:
                throw new Exception('Неизвестное действие');
        }
        
        $message = 'Действие выполнено успешно';

    } catch (Exception $e) {
        $message = 'Ошибка: ' . $e->getMessage();
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет администратора</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../scripts/admin_script.js"></script>
    <script src="../scripts/redirects.js"></script>
</head>
<body>
<?php if (isset($message)): ?>
        <p><?= $message ?></p>
    <?php endif; ?>
    <div class="controls">
        <!-- Основные кнопки (появляются после начала игры) -->
        <div class="button-group" id="gameControls">
            <button class="btn life">Управление жизнью</button>
            <button class="btn man_inventory">Управление инвентарём</button>
            <button class="btn players">Управление игроками</button>
            <button class="btn events">Управление событиями</button>
            <button class="btn game">Управление игрой</button>
            <button class="btn man_voting">Управление голосованием</button>
            <button class="btn gambling">Управление ставками</button>
            <button class="btn logs">Просмотр логов</button>
            <button class="btn querries">Запросы</button>
        </div>
        <div class="button-group" id="gameControls">
            <button class="btn crafting">Крафтинг</button>
            <button class="btn shop">Магазин</button>
            <button class="btn earn">Заработок</button>
            <button class="btn abilities">Способности</button>
            <button class="btn inventory">Инвентарь</button>
            <button class="btn voting">Голосование</button>
            <button class="btn trade">Обменная площадка</button>
            <button class="btn camera">Камера</button>
            </div>
    </div>
</body>
</html>
</body>
</html>