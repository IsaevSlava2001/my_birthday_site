<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] !== 1) {
    header('Location: ../lk/authorisation.php');
    exit();
}

// Получение текущего раунда
$currentRound = null;
$stmt = $pdo->query('SELECT * FROM rounds ORDER BY round_id DESC LIMIT 1');
$currentRound = $stmt->fetch(PDO::FETCH_ASSOC);

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $action = $_POST['action'] ?? null;

        if (!$action) {
            throw new Exception('Неверные параметры');
        }

        switch ($action) {
            case 'assign_roles':
                // Распределение ролей и фракций
                assignRolesAndFactions($pdo);
                break;

            case 'start_game':
                // Начало игры (создание первого раунда)
                startGame($pdo);
                break;

            case 'change_phase':
                // Смена фазы
                changePhase($pdo);
                break;

            case 'end_day':
                // Завершение дня
                endDay($pdo);
                break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: manage_game.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Функция распределения ролей и фракций
function assignRolesAndFactions($pdo) {
    // Проверяем, что игра еще не началась
    $stmt = $pdo->query('SELECT COUNT(*) FROM rounds');
    $roundCount = $stmt->fetchColumn();
    if ($roundCount > 0) {
        throw new Exception('Игра уже начата, роли нельзя распределять');
    }
    // Получаем всех живых игроков
    $stmt = $pdo->query('SELECT user_id FROM users WHERE status = "жив" AND faction != "admin"');
    $users = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($users)) {
        throw new Exception('Нет активных игроков для распределения ролей');
    }

    // Получаем список ролей
    $stmt = $pdo->query('SELECT role_id FROM roles WHERE role_id != 1 AND role_id != 11');
    $roles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Распределяем роли случайным образом
    shuffle($roles);
    foreach ($users as $index => $userId) {
        $roleId = $roles[$index % count($roles)];
        $stmt = $pdo->prepare('UPDATE users SET role_id = ? WHERE user_id = ?');
        $stmt->execute([$roleId, $userId]);
    }

    // Распределяем фракции поровну
    $factions = ['человек', 'зомби'];
    shuffle($users); // Перемешиваем игроков
    $factionSize = ceil(count($users) / count($factions));
    foreach ($factions as $index => $faction) {
        $factionUsers = array_slice($users, $index * $factionSize, $factionSize);
        foreach ($factionUsers as $userId) {
            $stmt = $pdo->prepare('UPDATE users SET faction = ? WHERE user_id = ?');
            $stmt->execute([$faction, $userId]);
        }
    }
}

// Функция начала игры
function startGame($pdo) {
    // Проверяем, что игры еще нет
    $stmt = $pdo->query('SELECT COUNT(*) FROM rounds');
    $roundCount = $stmt->fetchColumn();

    if ($roundCount > 0) {
        throw new Exception('Игра уже начата');
    }

    // Создаем первый раунд
    $stmt = $pdo->prepare('INSERT INTO rounds (phase) VALUES ("day")');
    $stmt->execute();
}

// Функция смены фазы
function changePhase($pdo) {
    // Получаем текущий раунд
    $stmt = $pdo->query('SELECT round_id, phase FROM rounds ORDER BY round_id DESC LIMIT 1');
    $currentRound = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentRound) {
        throw new Exception('Нет активного раунда');
    }

    // Меняем фазу
    $newPhase = $currentRound['phase'] === 'day' ? 'voting' : 'day';
    $stmt = $pdo->prepare('UPDATE rounds SET phase = ? WHERE round_id = ?');
    $stmt->execute([$newPhase, $currentRound['round_id']]);
}

// Функция завершения дня
function endDay($pdo) {
    // Получаем текущий раунд
    $stmt = $pdo->query('SELECT round_id, phase FROM rounds ORDER BY round_id DESC LIMIT 1');
    $currentRound = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$currentRound || $currentRound['phase'] !== 'voting') {
        throw new Exception('Нельзя завершить день в текущей фазе');
    }

    // Получаем результаты голосования
    $stmt = $pdo->prepare('
        SELECT target_id, COUNT(target_id) as target_count, type
        FROM votes 
        WHERE round = ? 
        GROUP BY target_id, type
        ORDER BY target_count DESC
    ');
    $stmt->execute([$currentRound['round_id']]);
    $votes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($votes)) {
        throw new Exception('Нет данных о голосовании');
    }

    // Проверяем, есть ли ничья(сделать это в будущем)
    // Берём самого популярного игрока и тип действия
    $targetId = $votes[0]['target_id'] ?? null;
    $voteType = $votes[0]['type'] ?? null;

    if (!$targetId || !$voteType) {
        throw new Exception('Не удалось определить результаты голосования');
    }

    // Получаем vote_data для целевого игрока
    $stmt = $pdo->prepare('SELECT vote_data FROM users WHERE user_id = ?');
    $stmt->execute([$targetId]);
    $voteDataJson = $stmt->fetchColumn(); // Это JSON-строка

    // Преобразуем JSON в массив
    $voteData = json_decode($voteDataJson, true);
    if (!is_array($voteData)) {
        $voteData = []; // Если vote_data пуст или некорректен, создаём пустой массив
    }

    // Обработка типа голосования
    switch ($voteType) {
        case 'kill':
            // Убиваем игрока
            $stmt = $pdo->prepare('UPDATE users SET status = "мертв" WHERE user_id = ?');
            $stmt->execute([$targetId]);

            // Добавляем информацию о статусе в vote_data
            $voteData['status'] = 'revealed';
            //обновляем vote_data
            break;

        case 'reveal_role':
            // Добавляем информацию о статусе в vote_data
            $voteData['role'] = 'revealed';
            //обновляем vote_data
            break;

        case 'reveal_faction':
            // Добавляем информацию о статусе в vote_data
            $voteData['faction'] = 'revealed';
            //обновляем vote_data
            
            break;

        default:
            throw new Exception('Неизвестный тип голосования');
    }


    // Сохраняем обновлённые данные vote_data обратно в базу данных
    $updatedVoteData = json_encode($voteData);
    $stmt = $pdo->prepare('UPDATE users SET vote_data = ? WHERE user_id = ?');
    $stmt->execute([$updatedVoteData, $targetId]);

    // Начисляем деньги за ставки
    $stmt = $pdo->prepare('
        SELECT u.user_id, b.target_id, amount 
        FROM bets b
        JOIN users u ON b.user_id = u.user_id
        WHERE b.round_id = ? AND b.status = "active"
    ');
    $stmt->execute([$currentRound['round_id']]);
    $bets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($bets as $bet) {
        if ($bet['target_id'] == $targetId) {
            $stmt = $pdo->prepare('UPDATE users SET money = money + ? WHERE user_id = ?');
            $stmt->execute([$bet['user_id'], $bet['amount']]);
            //доделать логи
        }
    }
    // Закрываем ставку
    $stmt = $pdo->prepare('UPDATE bets SET status = "resolved" WHERE round_id = ?');
    $stmt->execute([$currentRound['round_id']]);

    // Заканчиваем текущий раунд
    $stmt = $pdo->prepare('UPDATE rounds SET end_time = NOW() WHERE round_id = ?');
    $stmt->execute([$currentRound['round_id']]);
    //убираем все fake_death
    $stmt = $pdo->prepare('UPDATE users SET status = \'жив\' WHERE status = \'фальшивая смерть\'');
    $stmt->execute([]);

    // Создаем новый раунд
    $stmt = $pdo->prepare('INSERT INTO rounds (phase, start_time) VALUES ("day", NOW())');
    $stmt->execute();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление игрой</title>
    <link rel="stylesheet" href="../graphic/manage_game.css">
    </style>
</head>
<body>
    <h1>Управление игрой</h1>

    <!-- Отображение текущего состояния раунда -->
    <div class="round-info">
        <h2>Текущий раунд</h2>
        <?php if ($currentRound): ?>
            <table>
                <tr>
                    <th>ID раунда</th>
                    <td><?= htmlspecialchars($currentRound['round_id']) ?></td>
                </tr>
                <tr>
                    <th>Фаза</th>
                    <td><?= htmlspecialchars($currentRound['phase']) ?></td>
                </tr>
                <tr>
                    <th>Время начала</th>
                    <td><?= htmlspecialchars($currentRound['start_time']) ?></td>
                </tr>
                <tr>
                    <th>Время окончания</th>
                    <td><?= htmlspecialchars($currentRound['end_time'] ?? 'Не завершен') ?></td>
                </tr>
                <tr>
                    <th>Активные события</th>
                    <td><?= htmlspecialchars($currentRound['active_events'] ?? 'Нет активных событий') ?></td>
                </tr>
            </table>
        <?php else: ?>
            <p>Нет активных раундов.</p>
        <?php endif; ?>
    </div>

    <div class="controls">
        <form method="POST">
            <button type="submit" name="action" value="assign_roles">Распределить роли и фракции</button>
        </form>
        <form method="POST">
            <button type="submit" name="action" value="start_game">Начать игру</button>
        </form>
        <form method="POST">
            <button type="submit" name="action" value="change_phase">Сменить фазу</button>
        </form>
        <form method="POST">
            <button type="submit" name="action" value="end_day">Завершить день</button>
        </form>
        <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
    </div>
</body>
</html>