<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../lk/authorisation.php');
    exit();
}

// Обработка выставления товара на продажу
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['list_item'])) {
    $item_id = $_POST['item_id'] ?? null;
    $price = (int)($_POST['price'] ?? 0);
    $user_to_id = $_POST['user_to_id'] ?? null;
    $quantity = (int)($_POST['quantity'] ?? 1);

    // Если user_to_id пустой, устанавливаем его как NULL
    $user_to_id = $user_to_id === '' ? null : (int)$user_to_id;

    // Проверка наличия предмета в инвентаре
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = ?');
    $stmt->execute([$_SESSION['user_id'], $item_id]);
    $available_quantity = $stmt->fetchColumn();

    if (!$available_quantity || $available_quantity < $quantity) {
        die('Недостаточно предметов в инвентаре');
    }

    // Выставление товара на продажу
    $stmt = $pdo->prepare('
        INSERT INTO trading (user_id, item_id, user_to_id, price, quantity)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$_SESSION['user_id'], $item_id, $user_to_id, $price, $quantity]);

    // Уменьшаем количество предметов в инвентаре
    $stmt = $pdo->prepare('
        UPDATE inventories 
        SET quantity = quantity - ? 
        WHERE user_id = ? AND item_id = ?
    ');
    $stmt->execute([$quantity, $_SESSION['user_id'], $item_id]);
    //логируем действие
    logAction($pdo, $_SESSION['user_id'], 'trade_sell', ['item' => $item_id, 'quantity' => $quantity, 'user_to' => $user_to_id]);

    header('Location: ../pages/trading.php');
    exit();
}

// Обработка покупки товара
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_item'])) {
    $trade_id = $_POST['trade_id'] ?? null;
    $buy_quantity = (int)($_POST['buy_quantity'] ?? 1); // Количество для покупки

    // Получаем информацию о товаре
    $stmt = $pdo->prepare('
        SELECT t.*, i.name as item_name, u.money as seller_money
        FROM trading t
        JOIN items i ON t.item_id = i.item_id
        JOIN users u ON t.user_id = u.user_id
        WHERE t.trade_id = ? AND t.status = "active"
    ');
    $stmt->execute([$trade_id]);
    $trade = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$trade) {
        die('Товар недоступен для покупки');
    }

    // Проверяем, достаточно ли товара на продаже
    if ($trade['quantity'] < $buy_quantity) {
        die('Недостаточно товара для покупки');
    }

    // Проверка баланса покупателя
    $total_price = $trade['price'] * $buy_quantity; // Общая стоимость
    $buyer_money = getUserMoney($pdo, $_SESSION['user_id']);
    if ($buyer_money < $total_price) {
        die('Недостаточно средств');
    }

    // Завершение сделки
    $pdo->beginTransaction();
    try {
        // Перевод денег продавцу
        $stmt = $pdo->prepare('UPDATE users SET money = money + ? WHERE user_id = ?');
        $stmt->execute([$total_price, $trade['user_id']]);

        // Списание денег у покупателя
        $stmt = $pdo->prepare('UPDATE users SET money = money - ? WHERE user_id = ?');
        $stmt->execute([$total_price, $_SESSION['user_id']]);

        // Передача предмета покупателю
        $stmt = $pdo->prepare('
            INSERT INTO inventories (user_id, item_id, quantity)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ');
        $stmt->execute([$_SESSION['user_id'], $trade['item_id'], $buy_quantity, $buy_quantity]);

        // Обновление количества товара в предложении
        $stmt = $pdo->prepare('
            UPDATE trading 
            SET quantity = quantity - ? 
            WHERE trade_id = ?
        ');
        $stmt->execute([$buy_quantity, $trade_id]);

        // Если товар закончился, меняем статус сделки
        if ($trade['quantity'] === $buy_quantity) {
            $stmt = $pdo->prepare('UPDATE trading SET status = "completed" WHERE trade_id = ?');
            $stmt->execute([$trade_id]);
        }
        //логируем действие
        logAction($pdo, $_SESSION['user_id'], 'trade_buy', ['item' => $trade['item_id'], 'quantity' => $buy_quantity]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        die('Ошибка при завершении сделки: ' . $e->getMessage());
    }

    header('Location: ../pages/trading.php');
    exit();
}

// Получение активных предложений
$stmt = $pdo->prepare('
    SELECT t.*, i.name as item_name, u.name as seller
    FROM trading t
    JOIN items i ON t.item_id = i.item_id
    JOIN users u ON t.user_id = u.user_id
    WHERE t.status = "active" AND (t.user_to_id IS NULL OR t.user_to_id = ? OR t.user_id = ?)
');
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$activeTrades = $stmt->fetchAll();

// Получение инвентаря пользователя
$stmt = $pdo->prepare('
    SELECT i.item_id, i.name, inv.quantity 
    FROM inventories inv
    JOIN items i ON inv.item_id = i.item_id
    WHERE inv.user_id = ?
');
$stmt->execute([$_SESSION['user_id']]);
$userInventory = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Обменная площадка</title>
    <link rel="stylesheet" href="../graphic/trading.css">
</head>
<body>
    <div class="zombie-container">
        <h1>Обменная площадка <span class="zombie-hand">🛒</span></h1>
        <a href="../lk/personal_area.php">
            <button class="back-btn">Назад</button>
        </a>

        <!-- Форма выставления товара -->
        <div class="trade-form">
            <h2>Выставить предмет</h2>
            <form method="POST">
                <select name="item_id" id="itemSelect" required>
                    <option value="">Выберите предмет</option>
                    <?php foreach ($userInventory as $item): ?>
                        <option value="<?= $item['item_id'] ?>" data-max="<?= $item['quantity'] ?>">
                            <?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="quantity" id="quantityInput" min="1" max="1" value="1">
                <input type="number" name="price" min="0" placeholder="Цена за единицу (0 = бесплатно)">
                <select name="user_to_id">
                    <option value="">Для всех</option>
                    <?php
                    $stmt = $pdo->query('SELECT user_id, login FROM users WHERE status = "жив"');
                    while ($user = $stmt->fetch()):
                    ?>
                        <option value="<?= $user['user_id'] ?>">
                            <?= htmlspecialchars($user['login']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button type="submit" name="list_item">Выставить</button>
            </form>
        </div>

        <!-- Список активных предложений -->
        <div class="trade-list">
            <h2>Активные предложения</h2>
            <?php if ($activeTrades): ?>
                <ul>
                    <?php foreach ($activeTrades as $trade): ?>
                        <li>
                            <strong><?= htmlspecialchars($trade['item_name']) ?></strong> 
                            от <?= htmlspecialchars($trade['seller']) ?> 
                            за <?= $trade['price'] ?> монет (x<?= $trade['quantity'] ?>)
                            <?php if ($trade['user_to_id']!=NULL): ?>
                                <?php
                                $stmt = $pdo->prepare('SELECT name FROM users WHERE user_id = ?');
                                $stmt->execute([$trade['user_to_id']]);
                                $userTo = $stmt->fetchColumn();
                                ?>
                                для <?= htmlspecialchars($userTo) ?>
                            <?php endif; ?>
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="trade_id" value="<?= $trade['trade_id'] ?>">
                                <input type="number" name="buy_quantity" min="1" max="<?= $trade['quantity'] ?>" value="1">
                                <button type="submit" name="buy_item">Купить</button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p>Нет активных предложений</p>
            <?php endif; ?>
        </div>
    </div>
    <script>
        document.getElementById('itemSelect').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const maxQuantity = parseInt(selectedOption.getAttribute('data-max')) || 1;

            const quantityInput = document.getElementById('quantityInput');
            quantityInput.setAttribute('max', maxQuantity);
            quantityInput.value = Math.min(quantityInput.value, maxQuantity); // Ограничиваем текущее значение
        });
    </script>
</body>
</html>