<?php
session_start();
require '../config/connection.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode([]);
    exit();
}

$stmt = $pdo->prepare('
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0 AND message LIKE "%КНБ%"
    ORDER BY created_at DESC
    LIMIT 1
');
$stmt->execute([$_SESSION['user_id']]);
$note = $stmt->fetch();

if ($note) {
    // Помечаем уведомление как прочитанное
    $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ?')
        ->execute([$note['notification_id']]);
    
    // Извлекаем game_id
    preg_match('/#(\d+)/', $note['message'], $matches);
    $game_id = $matches[1] ?? null;
    
    if ($game_id) {
        echo json_encode([
            'redirect' => "../pages/player2.php?game_id=$game_id"
        ]);
        exit();
    }
}

echo json_encode([]);
?>