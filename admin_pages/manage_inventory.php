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
        $userId = $_POST['user_id'] ?? null;
        $action = $_POST['action'] ?? null;

        if (!$userId || !$action) {
            throw new Exception('Неверные параметры');
        }

        // Получаем текущие данные пользователя
        $stmt = $pdo->prepare('SELECT money FROM users WHERE user_id = ?');
        $stmt->execute([$userId]);
        $userData = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$userData) {
            throw new Exception('Пользователь не найден');
        }

        switch ($action) {
            case 'add_item':
                $itemId = (int)$_POST['item_id'];
                $quantity = (int)$_POST['quantity'];

                if ($itemId <= 0 || $quantity <= 0) {
                    throw new Exception('Неверные параметры предмета');
                }

                // Добавляем или обновляем предмет в инвентаре
                $stmt = $pdo->prepare('
                    INSERT INTO inventories (user_id, item_id, quantity)
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE quantity = quantity + ?
                ');
                $stmt->execute([$userId, $itemId, $quantity, $quantity]);
                break;

            case 'remove_item':
                $itemId = (int)$_POST['item_id'];
                $quantity = (int)$_POST['quantity'];

                if ($itemId <= 0 || $quantity <= 0) {
                    throw new Exception('Неверные параметры предмета');
                }

                // Уменьшаем количество предметов или удаляем запись
                $stmt = $pdo->prepare('
                    UPDATE inventories 
                    SET quantity = GREATEST(quantity - ?, 0) 
                    WHERE user_id = ? AND item_id = ?
                ');
                $stmt->execute([$quantity, $userId, $itemId]);

                // Удаляем запись, если количество стало 0
                $stmt = $pdo->prepare('
                    DELETE FROM inventories 
                    WHERE user_id = ? AND item_id = ? AND quantity = 0
                ');
                $stmt->execute([$userId, $itemId]);
                break;

            case 'update_money':
                $newMoney = (int)$_POST['money'];

                if ($newMoney < 0) {
                    throw new Exception('Недопустимое значение денег');
                }

                // Обновляем деньги пользователя
                $stmt = $pdo->prepare('UPDATE users SET money = ? WHERE user_id = ?');
                $stmt->execute([$newMoney, $userId]);
                break;

            default:
                throw new Exception('Неизвестное действие');
        }

        // Перезагружаем страницу после выполнения действия
        header('Location: ../admin_pages/manage_inventory.php');
        exit();
    } catch (Exception $e) {
        echo '<div style="color: red;">Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Получение данных всех игроков
$stmt = $pdo->query('SELECT user_id, login, money FROM users WHERE status!=\'admin\' ORDER BY login ASC');
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение списка предметов
$stmt = $pdo->query('SELECT item_id, name FROM items ORDER BY name ASC');
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Получение текущего инвентаря для каждого игрока
$userInventories = [];
foreach ($users as $user) {
    $stmt = $pdo->prepare('
        SELECT i.item_id, i.name, iv.quantity 
        FROM inventories iv
        JOIN items i ON iv.item_id = i.item_id
        WHERE iv.user_id = ?
    ');
    $stmt->execute([$user['user_id']]);
    $userInventories[$user['user_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Управление инвентарем</title>
    <link rel="stylesheet" href="../graphic/manage_inventory.css">
</head>
<body>
    <h1>Управление инвентарем</h1>

    <table>
        <thead>
            <tr>
                <th>Логин</th>
                <th>Деньги</th>
                <th>Изменить деньги</th>
                <th>Добавить предмет</th>
                <th>Удалить предмет</th>
                <th>Текущий инвентарь</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['login']) ?></td>
                    <td><?= htmlspecialchars($user['money']) ?></td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                            <input type="number" name="money" value="<?= $user['money'] ?>" min="0">
                            <button type="submit" name="action" value="update_money">Сохранить</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                            <select name="item_id" required>
                                <option value="">Выберите предмет</option>
                                <?php foreach ($items as $item): ?>
                                    <option value="<?= $item['item_id'] ?>">
                                        <?= htmlspecialchars($item['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantity" value="1" min="1">
                            <button type="submit" name="action" value="add_item">Добавить</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST">
                            <input type="hidden" name="user_id" value="<?= $user['user_id'] ?>">
                            <select name="item_id" required>
                                <option value="">Выберите предмет</option>
                                <?php foreach ($userInventories[$user['user_id']] as $inventoryItem): ?>
                                    <option value="<?= $inventoryItem['item_id'] ?>">
                                        <?= htmlspecialchars($inventoryItem['name']) ?> (<?= $inventoryItem['quantity'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="number" name="quantity" value="1" min="1">
                            <button type="submit" name="action" value="remove_item">Удалить</button>
                        </form>
                    </td>
                    <td class="inventory">
                        <?php if (!empty($userInventories[$user['user_id']])): ?>
                            <?php foreach ($userInventories[$user['user_id']] as $inventoryItem): ?>
                                <span><?= htmlspecialchars($inventoryItem['name']) ?>: <?= $inventoryItem['quantity'] ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span>Инвентарь пуст</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
</body>
</html>