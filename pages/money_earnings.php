<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: authorisation.php');
    exit();
}

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => 'Неизвестная ошибка'];
    
    try {
        // Проверяем наличие обязательных параметров
        if (!isset($_POST['bet_vote']) && !isset($_POST['play_rps'])) {
            throw new Exception('Неверный тип запроса');
        }

        // Ставка на голосование
        if (isset($_POST['bet_vote'])) {
            // Проверка фазы
            $stmt = $pdo->query('SELECT phase FROM rounds ORDER BY round_id DESC LIMIT 1');
            $current_phase = $stmt->fetchColumn() ?? 'day';

            if ($current_phase !== 'day') {
                throw new Exception('Ставки доступны только до голосования');
            }

            $target_id = $_POST['target_id'] ?? null;
            if (!$target_id) {
                throw new Exception('Не выбран игрок');
            }
            
            // Проверка цели
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND status = ?');
            $stmt->execute([$target_id, 'жив']);
            
            if (!$stmt->fetchColumn()) {
                throw new Exception('Неверный выбор цели');
            }

            // Создаем ставку
            $stmt = $pdo->prepare('
                INSERT INTO bets (user_id, round_id, target_id)
                VALUES (?, (SELECT round_id FROM rounds ORDER BY round_id DESC LIMIT 1), ?)
            ');
            $stmt->execute([$_SESSION['user_id'], $target_id]);
            
            logAction($pdo, $_SESSION['user_id'], 'bet', [
                'target_id' => $target_id
            ]);
            
            $response = [
                'success' => true,
                'message' => 'Ставка принята!'
            ];

        // Камень-Ножницы-Бумага
        } elseif (isset($_POST['play_rps'])) {
            $opponent_id = $_POST['opponent_id'] ?? null;
            $user_choice = $_POST['rps_choice'] ?? null;
            $bet_amount = (int)($_POST['bet_amount'] ?? 0);

            // Проверка обязательных параметров
            if (!$opponent_id || !$user_choice || $bet_amount <= 0) {
                throw new Exception('Неверные параметры');
            }

            // Проверка противника
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND status = ?');
            $stmt->execute([$opponent_id, 'жив']);
            
            if (!$stmt->fetchColumn()) {
                throw new Exception('Противник недоступен');
            }

            // Проверка баланса
            $current_money = getUserMoney($pdo, $_SESSION['user_id']);
            if ($current_money < $bet_amount) {
                throw new Exception('Недостаточно средств');
            }

            // Создаем игру
            $stmt = $pdo->prepare('
                INSERT INTO rps_games (player1_id, player2_id, bet_amount, player1_choice)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$_SESSION['user_id'], $opponent_id, $bet_amount, $user_choice]);
            
            
            $game_id = $pdo->lastInsertId();
            //снимаем деньги
            $stmt = $pdo->prepare('
                UPDATE users SET money = money - ? 
                WHERE user_id = ?
            ');
            $stmt->execute([$bet_amount, $_SESSION['user_id']]);
            
            logAction($pdo, $_SESSION['user_id'], 'gamble', [
                'opponent_id' => $opponent_id,
                'bet_amount' => $bet_amount
            ]);
            
            $response = [
                'success' => true,
                'message' => 'Ожидайте хода противника'
            ];

            // После INSERT в rps_games
            // После создания игры

            // Правильное формирование сообщения
            $message = "У вас новая игра КНБ: #" . $game_id;

            // Отправка уведомления
            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, message)
                VALUES (?, ?)
            ');
            $stmt->execute([$opponent_id, $message]);
            // После создания игры
            $response = [
                'success' => true,
                'message' => 'Игра создана! Ожидайте хода противника',
                'game_id' => $pdo->lastInsertId()
            ];
        }

    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
        logAction($pdo, $_SESSION['user_id'], 'error', [
            'error' => $e->getMessage(),
            'file' => __FILE__,
            'line' => __LINE__
        ]);
        
    }

    // Отправляем JSON
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Заработок денег</title>
    <link rel="stylesheet" href="../graphic/money_earnings.css">
</head>
<body>
    <div class="container">
        <h1>Заработок денег</h1>

        <!-- Камень-Ножницы-Бумага -->
        <div class="zombie-box">
            <h2>Камень-Ножницы-Бумага (PvP)</h2>
            <form id="rpsForm">
                <select name="opponent_id" required>
                    <option value="">Выберите противника</option>
                    <?php
                    $stmt = $pdo->prepare('SELECT user_id, login FROM users WHERE status = ? AND user_id != ?');
                    $stmt->execute(['жив', $_SESSION['user_id']]);
                    while ($user = $stmt->fetch()):
                    ?>
                        <option value="<?= $user['user_id'] ?>">
                            <?= htmlspecialchars($user['login']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <div>
                    <label><input type="radio" name="rps_choice" value="rock" required> 
                        <img src="../images/rock.png" class="rps-icon"></label>
                    <label><input type="radio" name="rps_choice" value="paper"> 
                        <img src="../images/paper.png" class="rps-icon"></label>
                    <label><input type="radio" name="rps_choice" value="scissors"> 
                        <img src="../images/scissors.png" class="rps-icon"></label>
                </div>
                <input type="number" name="bet_amount" min="1" value="1" class="bet-input">
                <button type="submit" name="play_rps">Сделать ставку</button>
            </form>
            <div id="rpsResult"></div>
        </div>

        <!-- Ставки на голосование -->
        <div class="zombie-box">
            <h2>Ставка на голосование (4 монеты)</h2>
            <form id="betForm">
                <select name="target_id" required>
                    <option value="">Выберите игрока</option>
                    <?php
                    $stmt = $pdo->prepare('SELECT user_id, login FROM users WHERE status = ?');
                    $stmt->execute(['жив']);
                    while ($user = $stmt->fetch()):
                    ?>
                        <option value="<?= $user['user_id'] ?>">
                            <?= htmlspecialchars($user['login']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" name="bet_vote">Поставить</button>
            </form>
            <div id="betResult"></div>
        </div>

        <a href="../lk/personal_area.php">
            <button class="back-btn">Назад</button>
        </a>
    </div>

    <script>
        // Обработка ставок на голосование
        document.getElementById('betForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('bet_vote', '1'); // ← Добавляем явный параметр
            
            try {
                const response = await fetch('money_earnings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams(formData)
                });
                
                const result = await response.json();
                const resultDiv = document.getElementById('betResult');
                resultDiv.textContent = result.message;
                resultDiv.className = result.success ? 'success' : 'error';
                
            } catch (error) {
                console.error(error);
                alert('Сетевая ошибка');
            }
        });

        // Обработка PvP-игры
        document.getElementById('rpsForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);
            formData.append('play_rps', '1'); // ← Добавляем явный параметр
            
            try {
                const response = await fetch('money_earnings.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams(formData)
                });
                
                const result = await response.json();
                const resultDiv = document.getElementById('rpsResult');
                resultDiv.textContent = result.message;
                resultDiv.className = result.success ? 'success' : 'error';
                
            } catch (error) {
                console.error(error);
                alert('Сетевая ошибка');
            }
        });
    </script>
</body>
</html>