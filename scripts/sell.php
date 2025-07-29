<?php
// Убедитесь, что нет пробелов до <?php

session_start();
require '../config/connection.php';
require 'functions.php';

header('Content-Type: application/json'); // Явно указываем формат ответа

$response = ['success' => false, 'error' => 'Неизвестная ошибка'];

if (!isset($_SESSION['user_id'])) {
    $response['error'] = 'Не авторизован';
    echo json_encode($response);
    exit();
}

try {
    $item_id = $_POST['item_id'] ?? null;
    $quantity = (int)($_POST['quantity'] ?? 0);

    if (!$item_id || $quantity <= 0) {
        throw new Exception('Неверные параметры');
    }

    // Проверка наличия
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = ?');
    $stmt->execute([$_SESSION['user_id'], $item_id]);
    $current_quantity = $stmt->fetchColumn();

    if ($current_quantity < $quantity) {
        throw new Exception('Недостаточно предметов');
    }

    // Получаем данные предмета
    $stmt = $pdo->prepare('SELECT * FROM items WHERE item_id = ?');
    $stmt->execute([$item_id]);
    $item = $stmt->fetch();
    $earned = $item['base_price'] * $quantity / 2;

    // Обновление инвентаря
    if ($current_quantity == $quantity) {
        $pdo->prepare('DELETE FROM inventories WHERE user_id = ? AND item_id = ?')
            ->execute([$_SESSION['user_id'], $item_id]);
    } else {
        $pdo->prepare('UPDATE inventories SET quantity = quantity - ? WHERE user_id = ? AND item_id = ?')
            ->execute([$quantity, $_SESSION['user_id'], $item_id]);
    }

    // Обновление баланса
    $pdo->prepare('UPDATE users SET money = money + ? WHERE user_id = ?')
        ->execute([$earned, $_SESSION['user_id']]);

    // Логирование
    logAction($pdo, $_SESSION['user_id'], 'sell', [
        'item_id' => $item_id,
        'item_name' => $item['name'],
        'quantity' => $quantity,
        'earned' => $earned,
        'item_id' => $item_id
    ]);

    $response = [
        'success' => true,
        'new_balance' => getUserMoney($pdo, $_SESSION['user_id']),
        'current_quantity' => $current_quantity - $quantity,
        'item_id' => $item_id
    ];
    //echo json_encode($response);

} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
// Убедитесь, что нет пробелов после ?>