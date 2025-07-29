<?php
// Уберите session_start() отсюда - он уже в connection.php
// require '../config/connection.php' уже содержит проверку сессии

require '../config/connection.php';
require '../scripts/functions.php';

// ... остальной код ...
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['error' => 'Метод не разрешен']));
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    die(json_encode(['error' => 'Не авторизован']));
}

$code = trim($_POST['code'] ?? '');
$userId = $_SESSION['user_id'];

if (!$code || !preg_match('/^[A-Za-z0-9\-_]{3,}$/', $code)) {
    http_response_code(400);
    die(json_encode(['error' => 'Неверный формат кода']));
}

try {
    // Проверка существования кода
    $stmt = $pdo->prepare('SELECT * FROM items WHERE qr_code = ?');
    $stmt->execute([$code]);
    $item = $stmt->fetch();
    
    if (!$item) {
        throw new Exception('Код не найден');
    }

    // Добавление в инвентарь
    addItemtoUserInventory($pdo, $userId, $item['item_id']);
    
    // Логирование
    logAction($pdo, $userId, 'add_item', [
        'code' => $code,
        'item_id' => $item['item_id']
    ]);

    echo json_encode([
        'success' => true,
        'message' => "Предмет '{$item['name']}' добавлен",
        'item' => $item
    ]);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>