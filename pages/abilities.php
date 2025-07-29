<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../lk/authorisation.php');
    exit();
}

// Получаем роль пользователя
$user_role = $_SESSION['role_id'];
$cooldowns = getAbilityCooldowns($pdo, $_SESSION['user_id']);
$active_help = getActiveHelp($pdo, $_SESSION['user_id']);

// Обработка использования способности
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ability_type = $_POST['ability_type'] ?? null;
    $action = $_POST['action'];
    $help_user_id = $_POST['help_user_id'] ?? null;
    $response = ['success' => true, 'message' => 'Способность активирована!'];
    try {
        switch ($user_role) {
            case 1:
                if ($ability_type === 'check_item') {
                    $response['content']=spyInventory($pdo, $_SESSION['user_id'], $_POST['target_id']);#Это абилка
                } elseif ($ability_type === 'investigate') {
                    $response['content']=spyUser($pdo, $_SESSION['user_id'], $_POST['target_id']);#Это абилка
                }elseif (in_array($ability_type, ['exorcism', 'resurrect'])) {
                    useHolyAbility($pdo, $_SESSION['user_id'], $ability_type, $_POST['target_id']);#Это абилка
                }elseif ($ability_type === 'berserk_strike') {
                    berserkStrike($pdo, $_SESSION['user_id'], $_POST['killer_id']);#Это абилка
                }
                break; 
            case 4: // Детектив
                if ($ability_type === 'check_item') {
                    $response['content']=spyInventory($pdo, $_SESSION['user_id'], $_POST['target_id']);
                } elseif ($ability_type === 'investigate') {
                    $response['content']=spyUser($pdo, $_SESSION['user_id'], $_POST['target_id']);
                }
                break;
                
            case 9: // Священник
                if (in_array($ability_type, ['exorcism', 'resurrect'])) {
                    useHolyAbility($pdo, $_SESSION['user_id'], $ability_type, $_POST['target_id']);
                }
                break;
                
            case 10: // Берсерк
                if ($ability_type === 'berserk_strike') {
                    berserkStrike($pdo, $_SESSION['user_id'], $_POST['killer_id']);
                }
                break;
        }
        
        // Обработка глобальных способностей
        if ($ability_type === 'fake_death') {
            useFakeDeath($pdo, $user_role, $_POST['target_id']);
        }
        switch ($action) {
            case 'yes':
                // Логика для ответа "Да"
                acceptHelpRequest($pdo, $help_user_id, $_SESSION['user_id']);
                $response['success'] = true;
                $response['message'] = 'Вы согласились помочь!';
                break;

            case 'no':
                // Логика для ответа "Нет"
                declineHelpRequest($pdo, $help_user_id, $_SESSION['user_id']);
                $response['success'] = true;
                $response['message'] = 'Вы отказались помогать.';
                break;
        }
        
    } catch (Exception $e) {
        $response = ['success' => false, 'message' => $e->getMessage()];
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Получаем доступные способности
$abilities = getRoleAbilities($pdo, $user_role);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Способности</title>
    <link rel="stylesheet" href="../graphic/abilities.css">
</head>
<body>
    <h1>Ваши способности</h1>
    <button name="back" class="back-btn" onclick="window.location.href='../lk/personal_area.php'">
        Назад
    </button>
    <?php if($active_help):?>
        <div class="active-ability-box">
            <h3>Просьба о притворной смерти</h3>
            <input type="hidden" name="help_user_id" value="<?= $active_help[0]['user_id'] ?>">
            <p><?= htmlspecialchars($active_help[0]['name']) ?> просит Вашей помощи в притворной смерти. Помочь?</p>
            <button class="active-help-btn" data-action="yes">Да</button>
            <button class="active-help-btn" data-action="no">Нет</button>
        </div>
    <?php endif;?>
    <?php foreach ($abilities as $ability): ?>
    <div class="ability-box">
        <h3><?= $ability['name'] ?></h3>
        <p><?= $ability['description'] ?? '-' ?></p>
        <?php if ($ability['needs_target']): ?>
            <form class="ability-form">
                <input type="hidden" name="ability_type" value="<?= $ability['ability_type'] ?>">
                <select name="target_id" required>
                    <option value=""><?=$ability['ability_type']=="fake_death"?"Выберите помощника":"Выберите цель"?></option>
                    <?php switch($ability['ability_type']) {
                        case 'heal':
                            echo 'heal';
                            foreach (getAliveUsers($pdo, $_SESSION['user_id']) as $user):
                                ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                        case 'fake_death':
                            echo 'heal';
                            foreach (getAliveUsers($pdo, $_SESSION['user_id']) as $user):
                                ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                        case 'investigate':
                            echo 'investigate';
                            foreach (getAliveUsers($pdo, $_SESSION['user_id']) as $user):
                            ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                        case 'check_item':
                            echo 'check_item';
                            foreach (getAliveUsers($pdo, $_SESSION['user_id']) as $user):
                            ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                        case 'exorcism':
                            echo 'exorcism';
                            foreach (getAliveUsers($pdo, $_SESSION['user_id']) as $user):
                            ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                        case 'resurrect':
                            echo 'resurrect';
                            foreach (getDeathUsers($pdo, $_SESSION['user_id']) as $user):
                            ?>
                                <option value="<?= $user['user_id'] ?>">
                                    <?= htmlspecialchars($user['name']) ?>
                                </option>
                            <?php endforeach;
                        break;
                    }
                        ?>
                        
                </select>
                <button type="submit" <?= $cooldowns[$ability['ability_type']]['available'] ? '' : 'disabled' ?>>
                    Использовать
                </button>
            </form>
            <div id=<?=$ability['ability_type']?>_result></div>
        <?php else: ?>
            <button class="ability-btn" 
                data-ability-type="<?= $ability['ability_type'] ?>" 
                <?= $cooldowns[$ability['ability_type']]['available'] ? '' : 'disabled' ?>>
                <?= $ability['action_text'] ?? 'Активировать' ?>
            </button>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
    
    <script>
        // Обработка AJAX-запросов
        // Динамическое создание элементов
        document.querySelectorAll('.ability-form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(form);
                const abilityType = formData.get('ability_type');
                console.log(abilityType);
                
                try {
                    const response = await fetch('abilities.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams(formData)
                    });
                    
                    const result = await response.json();
                    
                    if (result.content) {
                        const resultDiv = document.getElementById(`${abilityType}_result`);
                        if (resultDiv) {
                            resultDiv.innerHTML = ''; // Очищаем
                            
                            result.content.forEach(item => {
                                Object.entries(item).forEach(([key, value]) => {
                                    const li = document.createElement('li');
                                    li.textContent = `${key}: ${value}`;
                                    resultDiv.appendChild(li);
                                });
                                resultDiv.appendChild(document.createElement('hr'));
                            });
                            
                            const btn = document.createElement('button');
                            btn.textContent = 'Готово';
                            btn.classList.add('ready');
                            btn.addEventListener('click', () => window.location.reload());
                            resultDiv.appendChild(btn);
                        }
                    } else {
                        alert(result.message);
                        if (result.success) window.location.reload();
                    }
                    
                } catch (error) {
                    console.error(error);
                    alert('Сетевая ошибка');
                }
            });
        });
        document.querySelectorAll('.active-help-btn').forEach(button => {
        button.addEventListener('click', async () => {
            const action = button.dataset.action; // 'yes' или 'no'
            const helpUserId = document.querySelector('[name="help_user_id"]').value;
            try {
                const response = await fetch('abilities.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        action: action,
                        help_user_id: helpUserId
                    })
                });

                const result = await response.json();
                console.log(result);
                if (result.success) {
                    alert(result.message);
                    window.location.reload(); // Обновляем страницу
                } else {
                    alert(result.message || 'Ошибка при обработке запроса');
                }
            } catch (error) {
                console.error(error);
                alert('Сетевая ошибка');
            }
        });
    });
    document.querySelectorAll('.ability-btn').forEach(button => {
    button.addEventListener('click', async () => {
            const abilityType = button.dataset.abilityType; // Получаем тип способности

            if (!abilityType) {
                alert('Неизвестная способность');
                return;
            }

            try {
                const response = await fetch('abilities.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        ability_type: abilityType
                    })
                });

                const result = await response.json();

                if (result.success) {
                    alert(result.message || 'Способность активирована!');
                    window.location.reload(); // Обновляем страницу
                } else {
                    alert(result.message || 'Ошибка при активации способности');
                }
            } catch (error) {
                console.error(error);
                alert('Сетевая ошибка');
            }
        });
    });
    </script>
</body>
</html>