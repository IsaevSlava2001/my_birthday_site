<?php
// Отключаем буферизацию
@ini_set('output_buffering', 'Off');
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
@ob_end_clean();

session_start();
require '../config/connection.php';

// ← УБРАТЬ ЭТУ СТРОКУ: session_write_close();

if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    exit();
}

$user_id = $_SESSION['user_id'];
$last_check = $_SERVER['HTTP_IF_MODIFIED_SINCE'] 
    ? strtotime(preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE'])) 
    : 0;

$timeout = 60;
$start_time = time();
set_time_limit($timeout + 5);

while (time() - $start_time < $timeout) {
    if (connection_aborted()) break;

    $stmt = $pdo->prepare("
        SELECT 
            game_status,
            name,
            status,
            role_id,
            faction,
            hp,
            money,
            UNIX_TIMESTAMP(last_update) as last_update 
        FROM users 
        WHERE user_id = ?
    ");
    $stmt->execute([$user_id]);
    $data = $stmt->fetch();

    if ($data && $data['last_update'] > $last_check) {
        // Получаем роль
        $roleStmt = $pdo->prepare("SELECT name FROM roles WHERE role_id = ?");
        $roleStmt->execute([$data['role_id']]);
        $role = $roleStmt->fetchColumn();

        // ← ОБНОВЛЕНИЕ СЕССИИ
        $_SESSION['game_status'] = $data['game_status'];
        $_SESSION['name'] = $data['name'];
        $_SESSION['status'] = $data['status'];
        $_SESSION['role'] = $role;
        $_SESSION['faction'] = $data['faction'];
        $_SESSION['hp'] = $data['hp'];
        $_SESSION['money'] = $data['money'];
        $_SESSION['last_update'] = $data['last_update'];

        // ← ПОСЛЕ ОБНОВЛЕНИЯ СЕССИИ
        session_write_close();

        header("Last-Modified: " . gmdate('D, d M Y H:i:s', $data['last_update']) . ' GMT');
        header('Content-Type: application/json');
        echo json_encode([
            'last_update' => $data['last_update'],
            'data' => [
                'game_status' => $data['game_status'],
                'status' => $data['status'],
                'role' => $role,
                'faction' => $data['faction'],
                'hp' => $data['hp'],
                'name' => $data['name'],
            ]
        ]);
        exit();
    }

    sleep(1);
}

echo json_encode(['last_update' => 0, 'data' => null]);
exit();
?>