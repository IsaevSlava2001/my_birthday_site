<?php
require '../config/connection.php';

// Обработка подтверждения
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_inactive'])) {
    $stmt = $pdo->prepare("UPDATE active_items SET is_active = 0 WHERE active_item_id = ?");
    $stmt->execute([$_POST['active_item_id']]);
    header("Location: camera.php");
    exit();
}

// Получение активных записей
$stmt = $pdo->prepare("
    SELECT * FROM active_items 
    WHERE item_id = 15 
    AND is_active = 1 
    AND details IS NOT NULL
");
$stmt->execute();
$items = $stmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Наблюдательный пост</title>
    <link rel="stylesheet" href="../graphic/camera.css">
</head>
<body>
    <div class="container">
    <div class="header-controls">
            <button onclick="window.location.href='../lk/personal_area.php'" class="btn-back">
                ← Назад в безопасную зону
            </button>
            <h1>Журнал наблюдений 🧟</h1>
        </div>
        
        <?php if (!empty($items)): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Оператор</th>
                            <th>Дата</th>
                            <th>Детали угрозы</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['active_item_id']) ?></td>
                                <td><?= 'Оператор #' . htmlspecialchars($row['user_id']) ?></td>
                                <td><?= date('d.m.Y H:i:s', strtotime($row['applied_at'])) ?></td>
                                <td>
                                    <?php
                                    if ($row['details']) {
                                        // Декодируем JSON и форматируем
                                        $details = json_decode($row['details'], true);
                                        if ($details) {
                                            echo 'Имя угрозы: ' . htmlspecialchars($details['killer_name']) . '<br>';
                                            echo 'Фракция: ' . htmlspecialchars($details['killer_faction']) . '<br>';
                                            echo 'HP: ' . htmlspecialchars($details['killer_hp']) . '<br>';
                                            echo 'Статус: ' . htmlspecialchars($details['killer_status']);
                                        } else {
                                            echo 'Ошибка декодирования данных';
                                        }
                                    } else {
                                        echo 'Камера пока ничего не заметила';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <form method="POST" class="action-form">
                                        <input type="hidden" name="active_item_id" value="<?= $row['active_item_id'] ?>">
                                        <button type="submit" name="mark_inactive" class="btn-danger">
                                            Подтвердить информацию
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="message empty">
                <span class="zombie-icon">👀</span> Камера не обнаружила движений<br>
                <small>Но помните - они могут быть уже рядом...</small>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>