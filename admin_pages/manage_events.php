<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php'; // Подключаем файл с функцией triggerRandomEvent

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
            case 'start_random_gift':
                triggerRandomEvent($pdo, 100, 'random_gift');
                break;

            case 'start_riot':
                triggerRandomEvent($pdo, 100, 'riot');
                break;

            case 'start_robbery':
                triggerRandomEvent($pdo, 100, 'robbery');
                break;

            case 'start':
                // Запуск существующего события
                $eventId = (int)($_POST['event_id'] ?? 0);
                if (!$eventId) {
                    throw new Exception('Неверный ID события');
                }

                $stmt = $pdo->prepare('UPDATE events SET status = "active", start_time = NOW() WHERE event_id = ?');
                $stmt->execute([$eventId]);
                break;

            case 'stop':
                // Остановка существующего события
                $eventId = (int)($_POST['event_id'] ?? 0);
                if (!$eventId) {
                    throw new Exception('Неверный ID события');
                }

                $stmt = $pdo->prepare('UPDATE events SET status = "completed", end_time = NOW() WHERE event_id = ?');
                $stmt->execute([$eventId]);
                break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: manage_events.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение списка всех событий
$stmt = $pdo->query('SELECT event_id, event_type, data, start_time, end_time, status FROM events ORDER BY start_time DESC');
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление событиями</title>
    <link rel="stylesheet" href="../graphic/manage_events.css">
</head>
<body>
    <h1>Управление событиями</h1>

    <!-- Кнопки запуска событий -->
    <div class="event-buttons">
        <form method="POST">
            <button type="submit" name="action" value="start_random_gift">Запустить случайный подарок</button>
        </form>
        <form method="POST">
            <button type="submit" name="action" value="start_riot">Запустить бунт</button>
        </form>
        <form method="POST">
            <button type="submit" name="action" value="start_robbery">Запустить ограбление</button>
        </form>
    </div>

    <!-- Список событий -->
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Тип события</th>
                <th>Данные</th>
                <th>Время начала</th>
                <th>Время окончания</th>
                <th>Статус</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= htmlspecialchars($event['event_id']) ?></td>
                    <td><?= htmlspecialchars($event['event_type']) ?></td>
                    <td><?= htmlspecialchars(substr($event['data'] ?? '', 0, 50))!=NULL?htmlspecialchars(substr($event['data'] ?? '', 0, 50)):"..."?></td>
                    <td><?= htmlspecialchars($event['start_time'] ?? 'Не задано') ?></td>
                    <td><?= htmlspecialchars($event['end_time'] ?? 'Не задано') ?></td>
                    <td class="<?= $event['status'] === 'active' ? 'status-active' : 'status-completed' ?>">
                        <?= $event['status'] === 'active' ? 'Активно' : 'Завершено' ?>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="event_id" value="<?= $event['event_id'] ?>">
                            <?php if ($event['status'] === 'active'): ?>
                                <button type="submit" name="action" value="stop">Остановить</button>
                            <?php else: ?>
                                <button type="submit" name="action" value="start">Запустить</button>
                            <?php endif; ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
</body>
</html>