<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';
date_default_timezone_set('Europe/Moscow');

// Проверка авторизации пользователя
if (!isset($_SESSION['user_id'])) {
    die('Для покупок необходимо авторизоваться');
}

$user_id = $_SESSION['user_id'];
$user_role=$_SESSION['role_id'];

// Получаем баланс пользователя
$stmt = $pdo->prepare("SELECT `money` FROM users WHERE user_id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch();
$balance = $user['money'];

// Обработка покупки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    $item_id = $_POST['item_id'];
    
    $current_time = date('Y-m-d H:i:s');
    // Начинаем транзакцию
    $pdo->beginTransaction();
    
    try {
        // Получаем информацию о товаре
        $stmt = $pdo->prepare("SELECT * FROM items WHERE item_id = ? FOR UPDATE");
        $stmt->execute([$item_id]);
        $item = $stmt->fetch();
        
        if (!$item) {
            throw new Exception('Товар не найден');
        }
        
        if ($balance >= $item['price']) {
            // Снимаем средства
            $new_balance = $balance - $item['base_price'];
            $stmt = $pdo->prepare("UPDATE users SET `money` = ? WHERE user_id = ?");
            $stmt->execute([$new_balance, $user_id]);
            
            // Записываем покупку
            logAction($pdo, $user_id, 'buy', [
                'item_id' => $item_id,
                'item_name' => $item['name'],
                'price' => $item['base_price']
            ]);

            // Записываем в таблицу inventories
            //проверяем, есть ли у пользователя уже этот предмет
            $stmt = $pdo->prepare("SELECT * FROM inventories WHERE user_id = ? AND item_id = ?");
            $stmt->execute([$user_id, $item_id]);
            $inventory_item = $stmt->fetch();
            if (!$inventory_item) {
                // Если нет, то добавляем
                $stmt = $pdo->prepare("INSERT INTO inventories (user_id, item_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$user_id, $item_id, 1]);
            } else {
                // Если есть, то увеличиваем количество
                $new_quantity = $inventory_item['quantity'] + 1;
                $stmt = $pdo->prepare("UPDATE inventories SET quantity = ? WHERE user_id = ? AND item_id = ?");
                $stmt->execute([$new_quantity, $user_id, $item_id]);
            }
            
            $pdo->commit();
            $balance = $new_balance; // Обновляем баланс для отображения
            echo '<div class="success">Покупка успешна!</div>';
        } else {
            throw new Exception('Недостаточно средств');
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo '<div class="error">' . $e->getMessage() . '</div>';
    }
}

// Получаем список товаров
$stmt = $pdo->query("SELECT * FROM items");
$items = $stmt->fetchAll();
//проверяем есть ли запущенное событие riot
$stmt = $pdo->query("SELECT * FROM events WHERE event_type = 'riot' AND status = 'active'");
$event = $stmt->fetch();
if ($event) {
    // Если событие активно, то проверяем роль текущего пользователя
    // и добавляем к цене 100% если пользователь не контрабандист(роль 7)
    if ($user_role != 7) {
        foreach ($items as &$item) {
            $item['base_price'] *= 2; // Увеличиваем цену на 100%
        }
    }
}
?>
<!--Здесь вся обработка -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Creepster&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../graphic/shop.css">
    <title>Магазин</title>
</head>
<body class="rot-effect">
    <h1>Ваш баланс: <?= number_format($balance) ?> монет</h1>
    <button class="btn back" onclick="window.location.href='../lk/personal_area.php'">Назад</button>
    
    <?php if (isset($success)): ?>
        <div class="success"><?= $success ?></div>
    <?php endif; ?>
    
    <?php if (isset($error)): ?>
        <div class="error"><?= $error ?></div>
    <?php endif; ?>
    <div class="shop-container">
        <?php foreach ($items as $item):?>
            <?php if (($item['avail']==1 && ($user_role==1 || $user_role==7)) || $item['avail']==0): ?>
                <div class="item">
                    <h2><?= htmlspecialchars($item['name']) ?></h2>
                    
                    <!-- Основное описание -->
                    <div class="description">
                        <?= htmlspecialchars($item['description']) ?>
                    </div>
                    
                    <!-- Эффект товара -->
                    <div class="effect">
                        <svg class="skull-icon" viewBox="0 0 24 24">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-11h2v2h-2zm0 4h2v6h-2zm-5-4h2v2H6zm10 0h2v2h-2z"/>
                        </svg>
                        <?= htmlspecialchars($item['effect']) ?>
                    </div>
                    
                    <div class="price">Цена: <?= number_format($item['base_price'], 2) ?> монет</div>
                    
                    <form method="post" class = "submit_button" style="display: inline;">
                        <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                        <button type="submit" name="buy" value="1"
                                <?= $balance < $item['base_price'] ? 'disabled' : '' ?>>
                            <?= $balance >= $item['base_price'] ? 'Купить' : 'Мозги нужны...' ?>
                        </button>
                    </form>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</body>
</html>