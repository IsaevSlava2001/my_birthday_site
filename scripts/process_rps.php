<?php
session_start();
require '../config/connection.php';
require 'functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../lk/authorisation.php');
    exit();
}

$game_id = $_POST['game_id'] ?? null;
$choice = $_POST['choice'] ?? null;

if (!$game_id || !$choice) {
    die('Неверные параметры');
}

// Проверяем, что это вторая сторона игры
$stmt = $pdo->prepare('
    SELECT * FROM rps_games 
    WHERE game_id = ? AND player2_id = ? AND player2_choice IS NULL
');
$stmt->execute([$game_id, $_SESSION['user_id']]);
$game = $stmt->fetch();

if (!$game) {
    die('Игра не найдена');
}

// Сохраняем выбор второго игрока
$stmt = $pdo->prepare('
    UPDATE rps_games 
    SET player2_choice = ?, player2_timestamp = NOW()
    WHERE game_id = ?
');
$stmt->execute([$choice, $game_id]);
//снимаем деньги
$stmt = $pdo->prepare('
UPDATE users SET money = money - ? 
WHERE user_id = ?
');
$stmt->execute([$game['bet_amount'], $_SESSION['user_id']]);

// Определяем победителя
$result = determineWinner($game['player1_choice'], $choice);

// Начисляем деньги
if ($result === 'player1') {
    $winner_id = $game['player1_id'];
} elseif ($result === 'player2') {
    $winner_id = $game['player2_id'];
} else {
    $winner_id = null; // Ничья
}

// Обновляем балансы
if ($winner_id) {
    $stmt = $pdo->prepare('
        UPDATE users 
        SET money = money + ? 
        WHERE user_id = ?
    ');
    $stmt->execute([$game['bet_amount'] * 2, $winner_id]);
}

// Обновляем статус игры
$stmt = $pdo->prepare('
    UPDATE rps_games 
    SET winner_id = ? 
    WHERE game_id = ?
');
$stmt->execute([$winner_id, $game_id]);

// Логируем
logAction($pdo, $_SESSION['user_id'], 'gamble', [
    'game_id' => $game_id,
    'result' => $result
]);

header('Location: ../pages/money_earnings.php');
exit();
?>