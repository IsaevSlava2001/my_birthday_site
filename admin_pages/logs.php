<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] !== 1) {
    header('Location: ../lk/authorisation.php');
    exit();
}


// Параметры фильтрации
$filter_action = $_GET['action_type'] ?? null;
$filter_date = $_GET['date_range'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15; // Количество записей на странице

// Базовый запрос
$query = '
    SELECT g.log_id, u.login, g.timestamp, g.action_type, g.details
    FROM game_logs g
    LEFT JOIN users u ON g.user_id = u.user_id
';
$params = [];
$where = [];

// Добавляем фильтр по типу действия
if ($filter_action) {
    $where[] = 'g.action_type = ?';
    $params[] = $filter_action;
}

// Добавляем фильтр по дате
if ($filter_date) {
    switch ($filter_date) {
        case '24h':
            $where[] = 'g.timestamp >= NOW() - INTERVAL 1 DAY';
            break;
        case '7d':
            $where[] = 'g.timestamp >= NOW() - INTERVAL 7 DAY';
            break;
        case '30d':
            $where[] = 'g.timestamp >= NOW() - INTERVAL 30 DAY';
            break;
    }
}

// Формируем полный запрос
if (!empty($where)) {
    $query .= ' WHERE ' . implode(' AND ', $where);
}
$query .= ' ORDER BY g.timestamp DESC LIMIT ? OFFSET ?';
$params[] = $per_page;
$params[] = ($page - 1) * $per_page;

// Выполняем запрос
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Подсчет общего количества записей для пагинации
$count_query = 'SELECT COUNT(*) as total FROM game_logs g';
if (!empty($where)) {
    $count_query .= ' WHERE ' . implode(' AND ', $where);
}
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute(array_slice($params, 0, count($params) - 2)); // Убираем LIMIT и OFFSET
$total_logs = $count_stmt->fetchColumn();
$total_pages = ceil($total_logs / $per_page);

//получаем все типы действий для фильтрации
$stmt = $pdo->query('SELECT DISTINCT action_type FROM game_logs');
$actions = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Функция для форматирования JSON
function formatDetails($details) {
    $decoded = json_decode($details, true);
    if (is_array($decoded)) {
        return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
    return htmlspecialchars($details);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Просмотр логов</title>
    <link rel="stylesheet" href="../graphic/logs.css">
</head>
<body>
    <h1>Просмотр логов</h1>
    <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
    <!-- Фильтры -->
    <div class="filters">
        <form method="GET">
            <label for="action_type">Тип действия:</label>
            <select name="action_type" id="action_type">
                <option value="">Все</option>
                <?php
                foreach ($actions as $action):
                ?>
                    <option value="<?= $action ?>" <?= ($action === $filter_action) ? 'selected' : '' ?>>
                        <?= ucfirst($action) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="date_range">Дата:</label>
            <select name="date_range" id="date_range">
                <option value="">Все</option>
                <option value="24h" <?= ($filter_date === '24h') ? 'selected' : '' ?>>Последние 24 часа</option>
                <option value="7d" <?= ($filter_date === '7d') ? 'selected' : '' ?>>Последние 7 дней</option>
                <option value="30d" <?= ($filter_date === '30d') ? 'selected' : '' ?>>Последние 30 дней</option>
            </select>

            <button type="submit">Применить фильтр</button>
        </form>
    </div>

    <?php if (empty($logs)): ?>
        <p>Логов пока нет.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Пользователь</th>
                    <th>Время</th>
                    <th>Тип действия</th>
                    <th>Детали</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?= htmlspecialchars($log['log_id']) ?></td>
                        <td><?= htmlspecialchars($log['login'] ?? 'Система') ?></td>
                        <td><?= htmlspecialchars($log['timestamp']) ?></td>
                        <td><?= htmlspecialchars($log['action_type']) ?></td>
                        <td>
                            <div class="details">
                                <?= formatDetails($log['details']) ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Пагинация -->
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Назад</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                   style="<?= ($i === $page) ? 'background-color: #0056b3;' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Вперед</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</body>
</html>