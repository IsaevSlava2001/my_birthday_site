<?php
session_start();
require '../scripts/functions.php';
require '../config/connection.php';
date_default_timezone_set('Europe/Moscow');

if (!isset($_SESSION['user_id'])) {
    header('Location: ../lk/authorisation.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_id'];

// Получаем доступные рецепты
$stmt = $pdo->prepare("
    SELECT r.*, i.name, i.effect 
    FROM craft_recipes r
    JOIN items i ON r.item_id = i.item_id
    WHERE r.required_role_id = ? OR ? = 1
");
$stmt->execute([$user_role, $user_role]);
$recipes = $stmt->fetchAll();
// После получения $recipes добавляем:
$itemNames = [];

foreach ($recipes as &$recipe) {
    $components = json_decode($recipe['components'], true)['items'];
    
    // Собираем все item_id
    foreach ($components as $comp) {
        $itemIds[] = $comp['item_id'];
    }
}

// Получаем названия всех компонентов
if (!empty($itemIds)) {
    $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
    $stmt = $pdo->prepare("SELECT item_id, name FROM items WHERE item_id IN ($placeholders)");
    $stmt->execute($itemIds);
    $itemNames = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
}

// Обновляем данные рецептов
foreach ($recipes as &$recipe) {
    $components = json_decode($recipe['components'], true)['items'];
    
    foreach ($components as &$comp) {
        $comp['name'] = $itemNames[$comp['item_id']] ?? 'Неизвестный предмет';
    }
    
    $recipe['components'] = $components;
}
unset($recipe); // Освобождаем память

// Обработка крафта
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['craft'])) {
    $recipe_id = $_POST['recipe_id'];
    
    $pdo->beginTransaction();
    
    try {
        // Получаем рецепт
        $stmt = $pdo->prepare("SELECT * FROM craft_recipes WHERE recipe_id = ?");
        $stmt->execute([$recipe_id]);
        $recipe = $stmt->fetch();
        
        if (!$recipe) throw new Exception('Рецепт не найден');
        
        // Проверка роли
        if ($user_role != 1 && $recipe['required_role_id'] != $user_role) {
            throw new Exception('Недостаточно прав');
        }

        $components = json_decode($recipe['components'], true)['items'];
        
        // Проверка компонентов
        foreach ($components as $component) {
            $stmt = $pdo->prepare("
                SELECT quantity 
                FROM inventories 
                WHERE user_id = ? AND item_id = ?
                FOR UPDATE
            ");
            $stmt->execute([$user_id, $component['item_id']]);
            $current = $stmt->fetchColumn();
            
            if ($current < $component['item_count']) {
                throw new Exception('Не хватает компонентов');
            }
        }

        // Списание компонентов
        foreach ($components as $component) {
            $stmt = $pdo->prepare("
                UPDATE inventories 
                SET quantity = quantity - ? 
                WHERE user_id = ? AND item_id = ?
            ");
            $stmt->execute([$component['item_count'], $user_id, $component['item_id']]);
        }

        // Добавление предмета
        $stmt = $pdo->prepare("
            INSERT INTO inventories (user_id, item_id, quantity)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1
        ");
        $stmt->execute([$user_id, $recipe['item_id']]);

        // Логирование
        logAction($pdo, $user_id, 'craft', [
            'recipe_id' => $recipe_id,
            'item_id' => $recipe['item_id'],
            'item_name' => $recipe['name'],
            'components' => json_encode($components)
        ]);

        // Завершаем транзакцию
        $pdo->commit();
        $_SESSION['success'] = 'Предмет успешно создан!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = $e->getMessage();
    }
    
    header("Location: crafting.php");
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Крафтинг</title>
    <link href="https://fonts.googleapis.com/css2?family=Creepster&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../graphic/crafting.css">
</head>
<body class="rot-effect">
    <h1>Стол зомби-алхимика</h1>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']) ?>
    <?php endif ?>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']) ?>
    <?php endif ?>
    <button name="back" class="back-btn" onclick="window.location.href='../lk/personal_area.php'">
        Назад
    </button>
    <div class="craft-container">
        <?php foreach ($recipes as $recipe): ?>
            <?php $components = json_decode(json_encode($recipe['components']), true); ?>
            <div class="recipe-card">
                <h3><?= htmlspecialchars($recipe['name']) ?></h3>

                <!-- Эффект предмета -->
                <div class="item-effect">
                    <svg class="effect-icon" viewBox="0 0 24 24">
                        <path d="M12 2L2 12l10 10 10-10L12 2zm0 18l-7-7 7-7 7 7-7 7z"/>
                    </svg>
                    <span><?= htmlspecialchars($recipe['effect']) ?></span>
                </div>
                
                <div class="components">
                    <?php foreach ($components as $component): ?>
                        <div class="component">
                            <img src="../images/skull-icon.png" alt="Череп">
                            <span><?= $component['item_count'] ?>x <?= htmlspecialchars($component['name']) ?></span>
                        </div>
                    <?php endforeach ?>
                </div>
                
                <form method="post" class="craft-form">
                    <input type="hidden" name="recipe_id" value="<?= $recipe['recipe_id'] ?>">
                    <button type="submit" name="craft" class="craft-btn">
                        Создать
                    </button>
                </form>
            </div>
        <?php endforeach ?>
    </div>
</body>
</html>