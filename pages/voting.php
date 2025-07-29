<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: authorisation.php');
    exit();
}

// Проверка текущей фазы
$stmt = $pdo->query('SELECT phase FROM rounds ORDER BY round_id DESC LIMIT 1');
$current_phase = $stmt->fetchColumn() ?? 'day';

if ($current_phase !== 'voting') {
    die('Голосование доступно только во время фазы голосования.');
}
//получение информации о текущем игроке
$stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$current_user = $stmt->fetch(PDO::FETCH_ASSOC);

// Получение списка живых пользователей
$stmt = $pdo->query('
    SELECT user_id, login, role_id, faction, status, vote_data 
    FROM users 
    WHERE status = "жив"
');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Обработка POST-запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $response = ['success' => false, 'message' => 'Неизвестная ошибка'];

    try {
        //проверяем, голосовал ли уже пользователь
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM votes WHERE user_id = ? AND round = ?');
        $stmt->execute([$_SESSION['user_id'], getCurrentRound($pdo)]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Вы уже проголосовали в этом раунде!');
        }
        // Проверяем обязательные параметры
        if (!isset($_POST['vote_type']) || !isset($_POST['target_id'])) {
            throw new Exception('Неверные параметры');
        }

        $vote_type = $_POST['vote_type']; // Тип голосования (role, faction, kill)
        $target_id = (int)$_POST['target_id']; // ID цели

        // Проверка допустимых значений vote_type
        if (!in_array($vote_type, ['reveal_role', 'reveal_faction', 'kill'])) {
            throw new Exception('Недопустимый тип голосования');
        }

        // Проверка цели
        $stmt = $pdo->prepare('SELECT user_id FROM users WHERE user_id = ? AND status = ?');
        $stmt->execute([$target_id, 'жив']);
        if (!$stmt->fetchColumn()) {
            throw new Exception('Неверный выбор цели');
        }

        // Создаем запись о голосе
        $stmt = $pdo->prepare('
            INSERT INTO votes (user_id, target_id, type, round)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$_SESSION['user_id'], $target_id, $vote_type, getCurrentRound($pdo)]);

        logAction($pdo, $_SESSION['user_id'], 'vote', [
            'target_id' => $target_id,
            'vote_type' => $vote_type
        ]);

        $response = [
            'success' => true,
            'message' => 'Ваш голос принят!'
        ];
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
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
    <title>Голосование</title>
    <link rel="stylesheet" href="../graphic/voting.css">
</head>
<body>
    <div class="zombie-container">
        <h1>Голосование <span class="zombie-hand">🗳️</span></h1>

        <!-- Форма голосования -->
        <div class="voting-form">
            <h2>Голосование</h2>
            <form id="voteForm" method="POST">
                <!-- Выбор типа голосования -->
                <div class="vote-options">
                    <label>
                        <input type="radio" name="vote_type" value="reveal_role" required>
                        Раскрыть роль
                    </label>
                    <label>
                        <input type="radio" name="vote_type" value="reveal_faction">
                        Раскрыть фракцию
                    </label>
                    <label>
                        <input type="radio" name="vote_type" value="kill">
                        Убить игрока
                    </label>
                </div>

                <!-- Выбор игрока -->
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

                <button type="submit">Отправить голос</button>
            </form>
            <div id="voteResult"></div>
        </div>

        <!-- Отображение раскрытой информации -->
        <div class="revealed-info">
            <h2>Раскрытая информация обо мне:</h2>
            <div class="user-info">
                <strong><?= htmlspecialchars($current_user['name']) ?>:</strong>
                <?php
                $vote_data = json_decode($current_user['vote_data'], true) ?? [];
                if (empty($vote_data)) {
                    echo '<em>Нет раскрытой информации</em>';
                } else {
                    if (isset($vote_data['role'])) {
                        echo '<div><span class="label">Роль раскрыта:</span> </div>';
                    }
                    if (isset($vote_data['faction'])) {
                        echo '<div><span class="label">Фракция раскрыта:</span> </div>';
                    }
                    if (isset($vote_data['status'])) {
                        echo '<div><span class="label">Статус раскрыт:</span> </div>';
                    }
                }
                ?>
        </div>

        <a href="../lk/personal_area.php">
            <button class="back-btn">Назад</button>
        </a>
    </div>
    <script>
        document.getElementById('voteForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const formData = new FormData(e.target);

            try {
                const response = await fetch('voting.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams(formData)
                });

                const result = await response.json();
                const resultDiv = document.getElementById('voteResult');
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