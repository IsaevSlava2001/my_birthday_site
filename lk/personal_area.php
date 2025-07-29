<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';
if (empty($_SESSION['auth'])) {
    header('Location: authorisation.php');
    exit();
}
if ($_SESSION['role_id']==1){
    header('Location: personal_admin_area.php');
    exit();
}
else if ($_SESSION['role_id']==11){
    header('Location: screen.php');
    exit();
}
// Проверка непрочитанных уведомлений
$stmt = $pdo->prepare('
    SELECT * FROM notifications 
    WHERE user_id = ? AND is_read = 0
    ORDER BY created_at DESC
');
$stmt->execute([$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();


?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Личный кабинет</title>
    <link href="https://fonts.googleapis.com/css2?family=Creepster&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../graphic/profile.css"> <!-- Подключите ваш CSS -->
    <!-- Внутри <head> -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="../scripts/script.js"></script>
    <script src="../scripts/redirects.js"></script>
    <script src="../scripts/long_pooling_personal_area.js"></script>
</head>
<body>
    <div class="zombie-profile">
        <!--Если game_status==create, то нет ничего
        если game_status==find, то есть поле поиска
        если game_status==not_started, то есть личные данные
        если game_status==started, то есть кнопки-->
        <input type="hidden" id="game_status" value="<?= $_SESSION['game_status'] ?? '' ?>">
        <div class="status-dependent find-section" data-status="<?= $_SESSION['game_status'] ?>">
            <h1 class="greeting-find">Добро пожаловать, <?= $_SESSION['name'] ?>!</h1>
            <div class="status-container">
                <div class="status-block">
                    <span class="label">Статус:</span>
                    <span class="value status-find"><?=$_SESSION['status']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Роль:</span>
                    <span class="value role-find"><?=$_SESSION['role']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Фракция:</span>
                    <span class="value faction-find"><?=$_SESSION['faction']?></span>
                </div>
                
                <div class="status-block hp">
                    <span class="label">ХП:</span>
                    <span class="value hp-find"><?=$_SESSION['hp']?></span>
                </div>
            </div>
            <div class="controls">
                <div class="scan-options">
                    <button class="btn option-btn manual-btn">
                        <i class="icon-keyboard"></i> Ввести код
                    </button>
                </div>

                <div class="input-method manual">
                    <input type="text" id="manualCode" class="zombie-input" placeholder="Введите код">
                    <button class="btn submit-btn">Подтвердить</button>
                    <div class="success_info">
                        <span class="value"></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="status-dependent not_started-section" data-status="<?= $_SESSION['game_status'] ?>">
            <h1 class="greeting-not_started">Добро пожаловать, <?= $_SESSION['name'] ?>!</h1>
            <div class="status-container">
                <div class="status-block">
                    <span class="label">Статус:</span>
                    <span class="value status-not_started"><?=$_SESSION['status']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Роль:</span>
                    <span class="value role-not_started"><?=$_SESSION['role']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Фракция:</span>
                    <span class="value faction-not_started"><?=$_SESSION['faction']?></span>
                </div>
                
                <div class="status-block hp">
                    <span class="label">ХП:</span>
                    <span class="value hp-not_started"><?=$_SESSION['hp']?></span>
                </div>
            </div>
        </div>
        <div class="status-dependent started-section" data-status="<?= $_SESSION['game_status'] ?>">
            <h1 class="greeting-started">Добро пожаловать, <?= $_SESSION['name'] ?>!</h1>
            <div class="status-container">
                <div class="status-block">
                    <span class="label">Статус:</span>
                    <span class="value status-started"><?=$_SESSION['status']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Роль:</span>
                    <span class="value role-started"><?=$_SESSION['role']?></span>
                </div>
                
                <div class="status-block">
                    <span class="label">Фракция:</span>
                    <span class="value faction-started"><?=$_SESSION['faction']?></span>
                </div>
                
                <div class="status-block hp">
                    <span class="label">ХП:</span>
                    <span class="value hp-started"><?=$_SESSION['hp']?></span>
                </div>
            </div>
            <div class="button-group" id="gameControls">
                <button class="btn crafting">Крафтинг</button>
                <button class="btn shop">Магазин</button>
                <button class="btn earn">Заработок</button>
                <button class="btn abilities">Способности</button>
                <button class="btn inventory">Инвентарь</button>
                <button class="btn voting">Голосование</button>
                <button class="btn trade">Обменная площадка</button>
                <button class="btn camera">Камера</button>
            </div>
        </div>
    </div>
    <script>
// Проверка уведомлений каждые 5 секунд
setInterval(() => {
    fetch('../scripts/check_notifications.php')
        .then(response => response.json())
        .then(data => {
            if (data.redirect) {
                window.location.href = data.redirect;
            }
        })
        .catch(error => console.error('Ошибка:', error));
}, 5000);
</script>
</body>
</html>