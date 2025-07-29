<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

// Проверка авторизации администратора
if (!isset($_SESSION['user_id']) || $_SESSION['role_id'] !== 1) {
    header('Location: ../lk/authorisation.php');
    exit();
}

// Переменные для хранения результатов
$query = '';
$result = null;
$error = null;

// Обработка POST-запросов
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $query = trim($_POST['query'] ?? '');

    if ($query) {
        try {
            // Подготовка и выполнение запроса
            $stmt = $pdo->prepare($query);

            // Выполняем запрос
            if (stripos($query, 'SELECT') === 0) {
                // Если это SELECT-запрос
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                // Для других типов запросов (INSERT, UPDATE, DELETE)
                $stmt->execute();
                $result = "Запрос выполнен успешно.";
            }
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    } else {
        $error = 'Пустой запрос.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SQL-консоль</title>
    <link rel="stylesheet" href="../graphic/querries.css">
</head>
<body>
    <div class="container">
        <h1>SQL-консоль</h1>

        <!-- Форма для ввода SQL-запроса -->
        <form method="POST">
            <textarea name="query" placeholder="Введите SQL-запрос здесь..."><?= htmlspecialchars($query) ?></textarea>
            <button type="submit">Выполнить</button>
        </form>

        <!-- Отображение ошибок -->
        <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <!-- Отображение результатов -->
        <?php if ($result): ?>
            <div class="result">
                <?php if (is_array($result)): ?>
                    <table>
                        <thead>
                            <tr>
                                <?php foreach (array_keys($result[0]) as $column): ?>
                                    <th><?= htmlspecialchars($column) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($result as $row): ?>
                                <tr>
                                    <?php foreach ($row as $value): ?>
                                        <td><?= htmlspecialchars($value) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p><?= htmlspecialchars($result) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Кнопка "Назад" -->
        <a href="../lk/personal_admin_area.php" class="back-btn">Назад в админ-панель</a>
    </div>
</body>
</html>