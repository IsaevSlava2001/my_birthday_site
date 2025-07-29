<?php
session_start();
require '../config/connection.php';
require '../scripts/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../lk/authorisation.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role_id'];
$inventory = getUserInventory($pdo, $user_id);

// Обработка использования способностей
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = $_POST['item_ID'];
    $target_id = $_POST['target_ID'];
    $response = ['success' => false, 'message' => 'Неизвестная ошибка'];
    $action = $_POST['action']; // 'steal' или 'modify_inventory'
    $upd_item_id = $_POST['upd_item_id'] ?? null;
    
    // Если форма взлома содержит скрытое поле ability_type со значением "hack",
    // то принудительно задаем item_id = 16 и target_id обнуляем.
    if (isset($_POST['ability_type']) && $_POST['ability_type'] === 'hack') {
        $item_id = 16;
        $target_id = null;
    }
    
    try {
        switch ($item_id) {
            case 8: //Антидот
                useAntidote($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 9: //Пистолет
                useBullet($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 10: //1 пуля
                throw new Exception('Пулю нельзя использовать отдельно!');
                break;
            case 11: //Противогаз
                useGasMask($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 12: //Аптечка
                if ($user_role === 2) {
                    useHealItem($pdo, $user_id, $target_id);
                    getUserInventory($pdo, $user_id); // Обновляем инвентарь
                } else {
                    throw new Exception('Аптечку может использовать только врач!');
                }
                break;
            case 13: //Детектор яда
                $response['content']=usePoisonDetector($pdo, $user_id, $target_id); 
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 14: //Защитный костюм
                useProtectiveSuit($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 15: //Камера-ловушка
                useTrapCamera($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 16: //Хакерский чип (взлом сайта)
                useHackerChip($pdo, $user_id, $action, $item_id);
                $response = ['success' => true, 'message' => 'Взлом успешен!'];
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 17: //Яд
                usePoison($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 18: //Мутаген
                useMutagen($pdo, $user_id, $target_id); 
                getUserInventory($pdo, $user_id); // Обновляем инвентарь 
                break;
            case 19: //Сыворотка Прогресса
                useProgressSerum($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 20: //Граната(дымовая)
                useSmokeGrenade($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            case 21: //Граната(взрывная)   
                useGrenade($pdo, $user_id, $target_id);
                getUserInventory($pdo, $user_id); // Обновляем инвентарь
                break;
            default:
                throw new Exception('Неизвестный предмет!');
                break;
        }
        // Для всех, кроме кейса 16, если выполнение прошло успешно:
        if ($item_id !== 16) {
            $response['success'] = true;
            $response['message'] = 'Объект активирован!';
        }
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Инвентарь</title>
    <link rel="stylesheet" href="../graphic/inventory.css">
</head>
<body>
    <h1>Инвентарь</h1>
    <div class="inventory-section">
        <?php foreach ($inventory as $item): ?>
            <?php if ($item['item_id'] == 16): ?>
                <!-- Блок для формы взлома сайта (хакерский чип) -->
                <div class="item" data-item-id="<?= $item['item_id'] ?>">
                    <div class="item-box">
                        <h3>Взлом сайта</h3>
                        <div class="item-quantity" id="quantity-<?= $item['item_id'] ?>">
                            Количество: <?= $item['quantity'] ?>
                        </div>
                        <div class="item-type"><?= ucfirst($item['type']) ?></div>
                        <div class="item-effects"><?= ucfirst($item['effect']) ?></div>
                        <p>Выберите действие:</p>
                        <form class="hack-form">
                            <input type="hidden" name="ability_type" value="hack">
                            <label>
                                <input type="radio" name="action" value="steal" required> Украсть 5 монет
                            </label>
                            <label>
                                <input type="radio" name="action" value="modify_inventory"> Изменить инвентарь
                            </label>
                            <!-- Селект остаётся, как в оригинале -->
                            <select name="item_id" disabled>
                                <option value="">Выберите предмет</option>
                                <?php foreach (getAllItems($pdo) as $allItem): ?>
                                    <option value="<?= $allItem['item_id'] ?>"><?= htmlspecialchars($allItem['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Взломать</button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="item" data-item-id="<?= $item['item_id'] ?>">
                    <div class="item-info">
                        <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                        <div class="item-type"><?= ucfirst($item['type']) ?></div>
                        <div class="item-effects"><?= ucfirst($item['effect']) ?></div>
                        <div class="item-quantity" id="quantity-<?= $item['item_id'] ?>">
                            Количество: <?= $item['quantity'] ?>
                        </div>
                    </div>
                    <div class="item-actions">
                        <form class="sell-form">
                            <input type="hidden" name="item_id" value="<?= $item['item_id'] ?>">
                            <input type="number" name="quantity" value="1" min="1" max="<?= $item['quantity'] ?>">
                            <button type="submit">Продать за <?= $item['base_price'] / 2 ?>$</button>
                        </form>
                        
                        <?php if ($item['avail'] > 0):

                            $targets = getAvailableItemsTargets($pdo, $item['item_id'], $user_id); ?>
                            <?php if ($targets != NULL): ?>
                                <select name="target_ID" required>
                                    <option value=""> Выберите цель</option>
                                    <?php foreach ($targets as $target): ?>
                                        <option value="<?= $target['user_id'] ?>">
                                            <?= htmlspecialchars($target['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                                <button class="use-item-btn" data-item-id="<?= $item['item_id'] ?>">
                                    Применить
                                </button>
                        <?php endif; ?>
                    </div>
                    <div id="<?= $item['item_id'] ?>_result"></div>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    
    <a href="../lk/personal_area.php">
        <button class="back-btn">Назад</button>
    </a>

    <script>
        // Обработка формы продажи предметов
        document.querySelectorAll('.sell-form').forEach(form => {
            form.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(form);
                
                try {
                    const response = await fetch('../scripts/sell.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: new URLSearchParams(formData)
                    });
                    
                    const result = await response.json();
                    alert(`Продано! Баланс: ${result.new_balance}$`);
                    
                    if (result.current_quantity < 1) {
                        form.closest('.item').remove();
                    } else {
                        document.querySelector(`#quantity-${result.item_id}`)
                            .textContent = `Количество: ${result.current_quantity}`;
                    }
                } catch (error) {
                    console.error(error);
                    alert('Сетевая ошибка');
                }
            });
        });

        // Обработка применения предметов для остальных кейсов
        document.querySelectorAll('.use-item-btn').forEach(button => {
            button.addEventListener('click', async () => {
                const itemId = parseInt(button.dataset.itemId, 10);
                const itemElement = button.closest('.item');
                const targetId = itemElement.querySelector('select[name="target_ID"]')?.value;
                // Добавляем массив исключений
                const noTargetItems = [10, 15, 16, 21];

                if (!itemId || (!noTargetItems.includes(itemId) && !targetId)) {
                    alert('Ошибка: не выбран предмет или цель');
                    return;
                }

                try {
                    const response = await fetch('inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({
                            item_ID: itemId,
                            target_ID: targetId,
                            action: 'use'
                        }),
                    });

                    const result = await response.json();
                    if (result.success) {
                        if (result.content) {
                        const resultDiv = document.getElementById(`${itemId}_result`);
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
                    } else {
                        alert(result.message || 'Ошибка при активации предмета');
                    }
                } catch (error) {
                    console.error(error);
                    alert('Сетевая ошибка');
                }
            });
        });

        // Обработка формы взлома сайта (хакерский чип)
        document.querySelectorAll('.hack-form').forEach(form => {
            // Добавляем обработчик изменения выбранного радио
            const radios = form.querySelectorAll('input[name="action"]');
            const selectElement = form.querySelector('select[name="item_id"]');
            radios.forEach(radio => {
                radio.addEventListener('change', () => {
                    // Если выбран пункт "изменить инвентарь", снимаем disabled со списка
                    if (radio.value === 'modify_inventory') {
                        selectElement.disabled = false;
                    } else {
                        selectElement.disabled = true;
                    }
                });
            });

            form.addEventListener('submit', async (e) => {
                e.preventDefault();

                const actionRadios = form.querySelectorAll('input[name="action"]');
                const selectedAction = Array.from(actionRadios).find(radio => radio.checked)?.value;
                if (!selectedAction) {
                    alert('Выберите действие');
                    return;
                }

                const formData = new FormData(form);
                try {
                    const response = await fetch('inventory.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams(formData)
                    });
                    const result = await response.json();
                    if (result.success) {
                        alert(result.message);
                        window.location.reload();
                    } else {
                        alert(result.message || 'Ошибка при взломе');
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
