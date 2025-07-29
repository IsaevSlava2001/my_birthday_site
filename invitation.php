<?php
session_start();
require 'config/connection.php';

// Получаем user_id из URL
$user_id = $_GET['user_id'] ?? null;

if (!$user_id || !is_numeric($user_id)) {
    die('Неверная ссылка');
}

$error = '';
$invitation = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyword = $_POST['keyword'] ?? '';

    // Проверяем, что кодовое слово соответствует этому user_id
    $stmt = $pdo->prepare("SELECT * FROM passwords WHERE user_id = ? AND keyword = ?");
    $stmt->execute([$user_id, $keyword]);
    $passwordData = $stmt->fetch();

    if ($passwordData) {
        // Получаем данные пользователя
        $stmt = $pdo->prepare("SELECT login FROM users WHERE user_id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if ($user) {
            $invitation = "
                <div class='invitation'>
                    <h2>Приглашение для {$user['login']}</h2>
                    <p>Логин: {$user['login']}</p>
                    <p>Пароль: {$passwordData['password']}</p>
                    <div class='event-details'>
                        <div>Дата сбора: <strong>26.04.2025</strong></div>
                        <div>Время: <strong>13:00 (может измениться. Следи за информацией в личных сообщениях)</strong></div>
                        <div>Адрес: <strong>г. Москва, Новоясеневский проспект, д. 17/50, кв. 67</strong></div>
                    </div>

                    <!-- ТЕКСТ ПРИГЛАШЕНИЯ -->
                    <div class='invitation-text'>
                        <h3>🌍 3 года спустя...</h3>
                        <p>Разумные зомби больше не скрываются в тени. Они эволюционировали, стали сильнее, умнее... и теперь жаждут уничтожить человечество. Но люди не сдаются!</p>
                        <p>💥 <strong>Великая битва за выживание начинается!</strong></p>
                        <p>🎮 Что тебя ждёт?</p>
                        <ul>
                            <li>✅ Стратегические сражения в реальном времени.</li>
                            <li>✅ Уникальные способности для каждой фракции.</li>
                            <li>✅ Система крафта: создавай оружие из подручных материалов.</li>
                            <li>✅ Рынок снаряжения: покупай и продавай ресурсы, чтобы выжить.</li>
                        </ul>
                        <p>⏳ Время на исходе! Кто останется в живых? Люди, зомби или... те, кто скрывается в тени?</p>
                        <p>👉 Измени судьбу мира!</p>
                        <p>#ZombieApocalypse #SurvivalWars #КонецНачинается</p>
                    </div>
                </div>
            ";
        } else {
            $error = 'Пользователь не найден';
        }
    } else {
        $error = 'Неверное кодовое слово';
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Проверка приглашения</title>
    <link rel="stylesheet" href="graphic/invitation.css">
</head>
<body>
    <div class="zombie-container">
        <h1>Введите кодовое слово</h1>
        
        <?php if ($error): ?>
            <div class="error-message"><?= $error ?></div>
        <?php endif; ?>

        <?php if ($invitation): ?>
            <?= $invitation ?>
        <?php else: ?>
            <form method="POST" class="zombie-form">
                <input type="text" name="keyword" placeholder="Кодовое слово" required>
                <button type="submit">Проверить</button>
            </form>
        <?php endif; ?>
    </div>
</body>
</html>