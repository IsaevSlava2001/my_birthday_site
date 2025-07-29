<?php
/*********************************************************************************
 * ОБЩИЕ ФУНКЦИИ ЛОГИРОВАНИЯ И ПОЛУЧЕНИЯ ДАННЫХ
 * Здесь находятся функции для записи действий в лог, получения текущего раунда и
 * состояния пользователя (например, деньги).
 *********************************************************************************/
 
/**
 * Записывает действие в лог игры.
 *
 * @param PDO    $pdo         Объект подключения к БД.
 * @param int    $user_id     Идентификатор пользователя.
 * @param string $action_type Тип действия.
 * @param mixed  $details     Подробности, которые будут записаны в лог в формате JSON. Пример ['type' => 'fake_death', 'target' => $target_id].
 */
function logAction($pdo, $user_id, $action_type, $details) {
    $stmt = $pdo->prepare("
        INSERT INTO game_logs (user_id, action_type, details)
        VALUES (?, ?, ?)
    ");
    $stmt->execute([$user_id, $action_type, json_encode($details)]);
}

/**
 * Получает идентификатор текущего раунда.
 *
 * @param PDO $pdo Объект подключения к БД.
 *
 * @return mixed Идентификатор последнего раунда.
 */
function getCurrentRound($pdo) {
    $stmt = $pdo->query('SELECT MAX(round_id) FROM rounds');
    return $stmt->fetchColumn();
}

/**
 * Запускает случайное событие в игре.
 *
 * @param PDO $pdo Объект подключения к БД.
 * @param int|null $probability Вероятность события (если не передано, null).
 * @param string|null $eventType Тип события (если не передано, null).
 */
function triggerRandomEvent($pdo, $probability = null, $eventType = null) {
    // Вероятность события (например, 10%)
    if ($probability === null) {
        $probability = rand(1, 100); // 10% вероятность
    }

    if ($probability > 90) {
        // Случайный выбор типа события
        if ($eventType === null) {
            $eventTypes = ['random_gift', 'riot', 'robbery'];
            $eventType = $eventTypes[array_rand($eventTypes)];
        }

        $data = null;

        switch ($eventType) {
            case 'random_gift':
                // Выбираем случайного игрока и предмет
                $stmtUser = $pdo->query('SELECT user_id FROM users WHERE status = "жив" AND faction != \'admin\' ORDER BY RAND() LIMIT 1');
                $userId = $stmtUser->fetchColumn();

                if (!$userId) {
                    break; // Если нет живых игроков, выходим
                }

                $stmtItem = $pdo->query('SELECT item_id FROM items ORDER BY RAND() LIMIT 1');
                $itemId = $stmtItem->fetchColumn();

                // Добавляем предмет игроку
                $stmtAddItem = $pdo->prepare('
                    INSERT INTO inventories (user_id, item_id, quantity)
                    VALUES (?, ?, 1)
                    ON DUPLICATE KEY UPDATE quantity = quantity + 1
                ');
                $stmtAddItem->execute([$userId, $itemId]);

                // Добавляем запись в events
                $data = json_encode(['user_id' => $userId, 'item_id' => $itemId]);
                break;

            case 'riot':
                break;

            case 'robbery':
                // Выбираем случайного игрока с достаточным количеством предметов
                $stmtUser = $pdo->query('
                    SELECT i.user_id, u.faction 
                    FROM inventories i
                    JOIN users u ON i.user_id = u.user_id 
                    WHERE i.quantity >= 2 AND u.status = "жив" AND u.faction != \'admin\'
                    GROUP BY user_id 
                    ORDER BY RAND() 
                    LIMIT 1
                ');
                $userId = $stmtUser->fetchColumn();

                if (!$userId) {
                    break; // Если нет игроков с достаточным количеством предметов, выходим
                }

                // Выбираем 2 случайных предмета у игрока
                $stmtItems = $pdo->prepare('
                    SELECT item_id 
                    FROM inventories 
                    WHERE user_id = ? AND quantity > 0 
                    ORDER BY RAND() 
                    LIMIT 2
                ');
                $stmtItems->execute([$userId]);
                $items = $stmtItems->fetchAll(PDO::FETCH_COLUMN);

                // Уменьшаем количество предметов у игрока
                foreach ($items as $itemId) {
                    $stmtRemoveItem = $pdo->prepare('
                        UPDATE inventories 
                        SET quantity = quantity - 1 
                        WHERE user_id = ? AND item_id = ?
                    ');
                    $stmtRemoveItem->execute([$userId, $itemId]);
                }

                // Формируем данные события
                $data = json_encode(['user_id' => $userId, 'items' => $items]);
                break;
        }

        // Логируем событие и добавляем его в таблицу events
        logAction($pdo, null, 'event', ['type' => $eventType, 'data' => $data]);

        // Добавляем событие в таблицу events
        $stmtInsertEvent = $pdo->prepare('
            INSERT INTO events (event_type, data, start_time, status)
            VALUES (?, ?, NOW(), "active")
        ');
        $stmtInsertEvent->execute([$eventType, $data]);

        // Обновляем активные события в таблице rounds
        $currentRound = getCurrentRound($pdo);
        if ($currentRound) {
            $stmtUpdateRound = $pdo->prepare('
                UPDATE rounds 
                SET active_events = TRIM(BOTH "," FROM CONCAT_WS(",", active_events, ?))
                WHERE round_id = ?
            ');
            $stmtUpdateRound->execute([$eventType, $currentRound]);
        }
    }
}


/***
 * Получает идентификатор предмета по его QR-коду.
 *
 * @param PDO $pdo Объект подключения к БД.
 * @param string $item_code QR-код предмета.
 *
 * @return mixed Идентификатор предмета или null, если не найден.
 */
function getItemID($pdo, $item_code){
    $stmt = $pdo->prepare('SELECT item_id FROM items WHERE qr_code = ?');
    $stmt->execute([$item_code]);
    
    $item_id = $stmt->fetchColumn();
    
    if (!$item_id) {
        throw new Exception('Предмет не найден');
    }
    
    return $item_id;
}

/***
 * Добавляет предмет в инвентарь пользователя.
 *
 * @param PDO $pdo Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя.
 * @param int $item_id Идентификатор предмета.
 */
function addItemtoUserInventory($pdo, $user_id, $item_id) {
    if (!$user_id || !$item_id) {
        throw new Exception('Неверные параметры инвентаря');
    }

    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = ?');
    $stmt->execute([$user_id, $item_id]);
    $current_quantity = $stmt->fetchColumn();
    
    if ($current_quantity !== false) {
        $stmt = $pdo->prepare('UPDATE inventories SET quantity = quantity + 1 WHERE user_id = ? AND item_id = ?');
        $stmt->execute([$user_id, $item_id]);
    } else {
        $stmt = $pdo->prepare('INSERT INTO inventories (user_id, item_id, quantity) VALUES (?, ?, 1)');
        $stmt->execute([$user_id, $item_id]);
    }
}

/**
 * Получает все предметы из инвентаря.
 *
 * @param PDO $pdo Объект подключения к БД.
 *
 * @return array Массив с информацией о предметах.
 */
function getAllItems($pdo) {
    $stmt = $pdo->query('SELECT * FROM items');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Получает баланс денег пользователя.
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя.
 *
 * @return mixed Количество денег.
 */
function getUserMoney($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT money FROM users WHERE user_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}


/*********************************************************************************
 * ФУНКЦИИ ИГРОВОЙ ЛОГИКИ И ОПРЕДЕЛЕНИЯ РЕЗУЛЬТАТА
 * Функция для определения победителя в игре (например, камень-ножницы-бумага).
 *********************************************************************************/

/**
 * Определяет победителя на основе выбора игроков.
 *
 * @param string $player1 Выбор первого игрока ('rock', 'paper', 'scissors').
 * @param string $player2 Выбор второго игрока.
 *
 * @return string 'draw' если ничья, 'player1' или 'player2' в зависимости от победителя.
 */
function determineWinner($player1, $player2) {
    if ($player1 === $player2) {
        return 'draw';
    }
    
    $rules = [
        'rock' => 'scissors',
        'paper' => 'rock',
        'scissors' => 'paper'
    ];
    
    if ($rules[$player1] === $player2) {
        return 'player1';
    } else {
        return 'player2';
    }
    //запуск рандомного события
    triggerRandomEvent($pdo);
}


/*********************************************************************************
 * ФУНКЦИИ СПОСОБНОСТЕЙ И РОЛЕЙ
 * В этом блоке находятся функции для получения способностей, использования умений
 * (например, притворная смерть, лечение, убийство и т.д.) и проверки состояния КД.
 *********************************************************************************/

/**
 * Получает активные просьбы о помощи по определённой роли.
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $role_id Идентификатор роли.
 *
 * @return array Список активных запросов.
 */
function getActiveHelp($pdo, $role_id){
    $stmt = $pdo->prepare('
        SELECT u.name, au.user_id
        FROM ability_uses au INNER JOIN users u ON u.user_id=au.user_id
        WHERE ability_type=? AND target=? AND round_id=? AND is_read is NULL
    ');
    $stmt->execute(['fake_death', $role_id, getCurrentRound($pdo)]);
    $info = $stmt->fetchAll();
    return $info;
}

/**
 * Получает способности роли.
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $role_id Идентификатор роли.
 *
 * @return array Массив с информацией о способностях.
 */
function getRoleAbilities($pdo, $role_id) {
    $stmt = $pdo->prepare('
        SELECT a.ability_type, a.name, a.description, a.cooldown, a.is_global, a.needs_target 
        FROM abilities a
        WHERE (a.role_id = ? OR a.is_global = 1) OR ? = 1
    ');
    $stmt->execute([$role_id, $role_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Шпионский просмотр инвентаря другой персоны.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @return array Инвентарь цели.
 *
 * @throws Exception Если способность недоступна.
 */
function spyInventory($pdo, $user_id, $target_id) {
    $current_round = getCurrentRound($pdo);
    $last_used_round = getLastAbilityUse($pdo, $user_id, 'spy_inventory');
    if (!isAbilityAvailable($pdo, 'check_item', $last_used_round)) {
        throw new Exception('Способность недоступна');
    }
    
    // Получаем инвентарь цели
    $stmt = $pdo->prepare('
        SELECT i.name, inv.quantity 
        FROM inventories inv 
        JOIN items i ON inv.item_id = i.item_id 
        WHERE inv.user_id = ?
    ');
    $stmt->execute([$target_id]);
    $inventory = $stmt->fetchAll();
    
    logAbilityUse($pdo, $user_id, 'spy_inventory', $target_id);
    logAction($pdo, $user_id, 'ability', ['type' => 'spy_inventory', 'target' => $target_id]);
    //запуск рандомного события
    triggerRandomEvent($pdo);
    return $inventory;
}

/**
 * Шпионский просмотр информации о пользователе.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @return array Данные пользователя.
 *
 * @throws Exception Если способность недоступна.
 */
function spyUser($pdo, $user_id, $target_id) {
    $current_round = getCurrentRound($pdo);
    $last_used_round = getLastAbilityUse($pdo, $user_id, 'spy_user');
    if (!isAbilityAvailable($pdo, 'investigate', $last_used_round)) {
        throw new Exception('Способность недоступна');
    }
    
    // Получаем информацию о пользователе
    $stmt = $pdo->prepare('
        SELECT name, faction, hp, status, infection_round, money 
        FROM users
        WHERE user_id = ?
    ');
    $stmt->execute([$target_id]);
    $user = $stmt->fetchAll();
    
    logAbilityUse($pdo, $user_id, 'spy_user', $target_id);
    logAction($pdo, $user_id, 'ability', ['type' => 'spy_user', 'target' => $target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    return $user;
}

/**
 * Использование "святой" способности (экзорцизм или воскрешение).
 *
 * @param PDO    $pdo          Объект подключения к БД.
 * @param int    $user_id      Идентификатор пользователя, использующего способность.
 * @param string $ability_type Тип способности ('exorcism' или 'resurrect').
 * @param int    $target_id    Идентификатор цели.
 *
 * @throws Exception Если способность уже использована.
 */
function useHolyAbility($pdo, $user_id, $ability_type, $target_id) {
    // Проверка уникальности использования способности
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ability_uses WHERE user_id = ? AND ability_type = ?');
    $stmt->execute([$user_id, $ability_type]);
    if ($stmt->fetchColumn() > 0) {
        throw new Exception('Способность уже использована');
    }
    
    if ($ability_type === 'exorcism') {
        $pdo->prepare('UPDATE users SET infection_round = NULL WHERE user_id = ?')
            ->execute([$target_id]);
    } elseif ($ability_type === 'resurrect') {
        $pdo->prepare('UPDATE users SET status = "жив" WHERE user_id = ?')
            ->execute([$target_id]);
    }
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, $ability_type, $target_id);
    logAction($pdo, $user_id, 'ability', ['type' => $ability_type, 'target' => $target_id]);
}

/**
 * Контратакующая способность "бешеный удар".
 *
 * @param PDO $pdo      Объект подключения к БД.
 * @param int $user_id  Идентификатор пользователя, применяющего способность.
 *
 * @throws Exception Если пользователь ещё жив или если не найден убийца.
 */
function berserkStrike($pdo, $user_id) {
    // Проверяем статус hp пользователя
    $stmt = $pdo->prepare('SELECT hp FROM users WHERE user_id = ?');
    $stmt->execute([$user_id]);
    $hp = $stmt->fetchColumn();
    
    if ($hp <= 0) {
        $killer_id = 0;
        $log_id = 0;
        $stmt = $pdo->prepare('SELECT log_id, user_id, details FROM game_logs WHERE action_type=? ORDER BY timestamp DESC');
        $stmt->execute(['ability']);
        $details = $stmt->fetchAll();
        foreach ($details as $detail) {
            $decoded = json_decode($detail["details"]);
            if (isset($decoded->type) && $decoded->type == "kill" && isset($decoded->target) && $decoded->target == $user_id && (!isset($decoded->is_read) || $decoded->is_read != 1)) {
                $killer_id = $detail['user_id'];
                $log_id = $detail['log_id'];
                break;
            }
        }
        if($killer_id != 0){
            $pdo->prepare('UPDATE users SET hp = 0 WHERE user_id = ?')
                ->execute([$killer_id]);
        } else {
            throw new Exception('Нет убийцы');
        }
        logAbilityUse($pdo, $user_id, 'berserk_strike', NULL);
        $stmt = $pdo->prepare('UPDATE game_logs SET details = ? WHERE log_id = ?');
        // Формирование строки лога с обновлённой информацией
        $str = '{"type":"kill","target":"$user_id", "is_read":"1"}';
        $stmt->execute([$str, $log_id]);
        //запуск рандомного события
        triggerRandomEvent($pdo);
        logAction($pdo, $user_id, 'ability', ['type' => 'berserk_strike', 'target' => $killer_id]);
    } else {
        throw new Exception('Вы не мертвы');
    }
}

/**
 * Использование способности "притворная смерть".
 *
 * @param PDO $pdo      Объект подключения к БД.
 * @param int $user_id  Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если все слоты заняты или способность недоступна.
 */
function useFakeDeath($pdo, $user_id, $target_id) {
    /* Проверяем глобальный лимит использования способности на текущем раунде */
    $stmt = $pdo->prepare('
        SELECT COUNT(*) FROM ability_uses 
        WHERE ability_type = "fake_death" AND round_id = ?
    ');
    $stmt->execute([getCurrentRound($pdo)]);
    $used = $stmt->fetchColumn();
    
    if ($used >= 2) {
        throw new Exception('Все слоты притворной смерти заняты');
    }
    
    /* Проверяем личный cooldown способности */
    if (!isAbilityAvailable($pdo, 'fake_death', getLastAbilityUse($pdo, $user_id, 'fake_death'))) {
        throw new Exception('Способность недоступна');
    }
    
    /* Активация способности */
    $pdo->prepare('
        INSERT INTO ability_uses (user_id, ability_type, round_id, target)
        VALUES (?, "fake_death", ?, ?)
    ')->execute([$user_id, getCurrentRound($pdo), $target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAction($pdo, $user_id, 'ability', ['type' => 'fake_death', 'target' => $target_id]);
}

/**
 * Получает время последнего использования способности.
 *
 * @param PDO    $pdo          Объект подключения к БД.
 * @param int    $user_id      Идентификатор пользователя.
 * @param string $ability_type Тип способности.
 *
 * @return mixed Последний раунд использования или false.
 */
function getLastAbilityUse($pdo, $user_id, $ability_type) {
    $stmt = $pdo->prepare('
        SELECT round_id 
        FROM ability_uses 
        WHERE user_id = ? 
            AND ability_type = ?
        ORDER BY round_id DESC
        LIMIT 1
    ');
    $stmt->execute([$user_id, $ability_type]);
    return $stmt->fetchColumn();
}

/**
 * Проверка доступности способности с учётом cooldown.
 *
 * @param PDO    $pdo           Объект подключения к БД.
 * @param mixed  $ability_type  Тип способности или её значение cooldown.
 * @param mixed  $last_used_round Последний раунд, когда способность была использована.
 *
 * @return bool True если способность доступна.
 */
function isAbilityAvailable($pdo, $ability_type, $last_used_round) {
    $stmt = $pdo->prepare('SELECT cooldown FROM abilities WHERE ability_type = ?');
    $stmt->execute([$ability_type]);
    $cooldown = $stmt->fetchColumn();
    
    if ($cooldown == 0) return true;
    if (!$last_used_round) return true;
    
    $current_round = getCurrentRound($pdo);
    return ($current_round > $last_used_round + $cooldown);
}

/**
 * Проверка cooldown для конкретной способности.
 *
 * @param PDO    $pdo           Объект подключения к БД.
 * @param int    $user_id       Идентификатор пользователя.
 * @param string $ability_type  Тип способности.
 *
 * @return bool True если способность доступна.
 */
function checkCooldown($pdo, $user_id, $ability_type) {
    $last_used = getLastAbilityUse($pdo, $user_id, $ability_type);
    return isAbilityAvailable($pdo, $ability_type, $last_used);
}

/**
 * Получение cooldown'ов всех способностей пользователя.
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя.
 *
 * @return array Массив с информацией о доступности и cooldown для каждой способности.
 */
function getAbilityCooldowns($pdo, $user_id) {
    $current_round = getCurrentRound($pdo);
    $cooldowns = [];
    
    /* Получаем все способности пользователя с информацией о последнем использовании */
    $stmt = $pdo->prepare('
        SELECT a.ability_type, a.cooldown, MAX(au.created_at) AS last_used 
        FROM abilities a
        LEFT JOIN ability_uses au 
            ON a.ability_type = au.ability_type 
            AND au.user_id = ?
            AND au.round_id = ?
        GROUP BY a.ability_type
    ');
    $stmt->execute([$user_id, $current_round]);
    
    while ($row = $stmt->fetch()) {
        $cooldowns[$row['ability_type']] = [
            'available' => isAbilityAvailable(
                $pdo,
                $row['ability_type'], 
                $row['last_used']
            ),
            'cooldown' => $row['cooldown']
        ];
    }
    
    /* Обработка особой логики для способности "притворная смерть" */
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS used 
        FROM ability_uses 
        WHERE ability_type = "fake_death" 
            AND round_id = ?
    ');
    $stmt->execute([$current_round]);
    $fake_death_used = $stmt->fetchColumn();
    
    $cooldowns['fake_death'] = [
        'available' => ($fake_death_used < 2),
        'cooldown' => 2,
        'available_count' => 2 - $fake_death_used
    ];
    
    return $cooldowns;
}

/**
 * Логирование использования способности.
 *
 * @param PDO    $pdo          Объект подключения к БД.
 * @param int    $user_id      Идентификатор пользователя, использующего способность.
 * @param string $ability_type Тип способности.
 * @param mixed  $target_id    Идентификатор цели (если применимо).
 */
function logAbilityUse($pdo, $user_id, $ability_type, $target_id) {
    $stmt = $pdo->prepare('
        INSERT INTO ability_uses (user_id, ability_type, round_id, target)
        VALUES (?, ?, (SELECT MAX(round_id) FROM rounds), ?)
    ');
    $stmt->execute([$user_id, $ability_type, $target_id]);
}


/*********************************************************************************
 * ФУНКЦИИ ИСПОЛЬЗОВАНИЯ РАЗЛИЧНЫХ СПОСОБНОСТЕЙ И ПРЕДМЕТОВ
 * Здесь находятся функции для использования отдельных способностей, таких как
 * лечение, убийство, просмотр инвентаря, шпионские способности и т.д.
 *********************************************************************************/

/**
 * Использование аптечки (heal item).
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели для лечения.
 *
 * @throws Exception Если способность недоступна или аптечек нет.
 */
function useHealItem($pdo, $user_id, $target_id) {
    // Проверка cooldown
    $current_round = getCurrentRound($pdo);
    $last_used_round = getLastAbilityUse($pdo, $user_id, 'heal');
    if (!isAbilityAvailable($pdo, 'heal', $last_used_round)) {
        throw new Exception('Способность недоступна');
    }
    
    // Проверка наличия аптечки
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 12');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет аптечек');
    }
    
    // Лечение цели
    $pdo->prepare('UPDATE users SET hp = hp + 1 WHERE user_id = ?')
        ->execute([$target_id]);
    // Уменьшение количества аптечек
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 12')
        ->execute([$user_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    // Запись использования способности
    logAbilityUse($pdo, $user_id, 'heal', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'heal', 'target' => $target_id]);
}

/**
 * Использование пули из пистолета.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели для атаки.
 *
 * @throws Exception Если отсутствует пистолет или пуля.
 */
function useBullet($pdo, $user_id, $target_id){
    // Проверка наличия пистолета
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 9');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет пистолета');
    } else {
        // Проверка наличия пули
        $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 10');
        $stmt->execute([$user_id]);
        if ($stmt->fetchColumn() < 1) {
            throw new Exception('Нет пули');
        }
    }
    //проверка на наличие защиты у цели
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE user_id = ? AND is_active = ?');
    $stmt->execute([$target_id, 1]);
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(14, $active_items)) {
        //Убираем защиту у цели
        $stmt = $pdo->prepare('UPDATE active_items SET is_active = 0 WHERE user_id = ? AND item_id = 14');
        $stmt->execute([$target_id]);
        throw new Exception('Цель защищена!');
    }
    
    // Атака: уменьшаем hp цели случайным числом от 1 до 10
    $damage = rand(1, 10);
    $pdo->prepare('UPDATE users SET hp = hp - ? WHERE user_id = ?')
        ->execute([$damage, $target_id]);
    // Если цель убита (hp <= 0), обновляем статус пользователя
    $stmt = $pdo->prepare('SELECT hp FROM users WHERE user_id = ?');
    $stmt->execute([$target_id]);
    if ($stmt->fetchColumn() <= 0) {
        $stmt = $pdo->prepare('UPDATE users SET `status` = "мёртв", hp = 0 WHERE user_id = ?');
        $stmt->execute([$target_id]);
    }
    //меняем фазу раунда на "голосование"
    $stmt = $pdo->prepare('UPDATE rounds SET phase = "voting" WHERE round_id = ?');
    $stmt->execute([getCurrentRound($pdo)]);
    // Удаляем использованную пулю из инвентаря
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 10')
        ->execute([$user_id]);
    

    // Если есть камера-ловушка, то записываем информацию о убийстве в неё
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE item_id = 15 AND is_active = 1');
    $stmt->execute();
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(15, $active_items)) {
        // Записываем информацию о убийстве в камеру-ловушку
        // Получаем информацию об убийце
        $stmt = $pdo->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([$user_id]);
        $killer = $stmt->fetch(PDO::FETCH_ASSOC);
        //формируем JSON строку с информацией об убийце
        $killer_info = json_encode([
            'killer_name' => $killer['name'],
            'killer_faction' => $killer['faction'],
            'killer_hp' => $killer['hp'],
            'killer_status' => $killer['status']
        ]);
        // Записываем информацию о убийстве в камеру-ловушку
        // Получаем все активные камеры-ловушки
        $stmt = $pdo->prepare('SELECT active_item_id FROM active_items WHERE item_id = 15 AND is_active = 1');
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN, 0) as $id) {
            // Записываем информацию о убийстве в камеру-ловушку
            $stmt = $pdo->prepare('UPDATE active_items SET details = ? WHERE item_id = 15 AND active_item_id = ?');
            $stmt->execute([$killer_info, $id]);
        }
    }

    //запуск рандомного события
    triggerRandomEvent($pdo);
    // Записываем использование способности
    logAbilityUse($pdo, $user_id, 'kill', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'kill', 'target' => $target_id, 'урон'=>$damage]);
}

/**
 * Способность "антидот".
 * 
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 * 
 * @throws Exception Если антидот недоступен.
 * 
 */

function useAntidote($pdo, $user_id, $target_id){
    // Проверка наличия антидота
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 8');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет антидота');
    }
    
    // Лечение цели
    $pdo->prepare('UPDATE users SET infection_round = NULL WHERE user_id = ?')
        ->execute([$target_id]);
    
    // Уменьшение количества антидотов
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 8')
        ->execute([$user_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'antidote', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'antidote', 'target' => $target_id]);
}

/**
 * Использование противогаза.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если противогаз недоступен.
 * @throws Exception Если у цели уже есть противогаз.
 */
function useGasMask($pdo, $user_id, $target_id){
    // Проверка наличия противогаза
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 11');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет противогаза');
    }
    //Проверка на наличие противогаза у цели
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE user_id = ? AND item_id = 11');
    $stmt->execute([$target_id]);
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(11, $active_items)) {
        throw new Exception('У цели уже есть противогаз!');
    }
    //Применение противогаза к цели
    $stmt = $pdo->prepare('INSERT INTO active_items (user_id, item_id, applied_at) VALUES (?, ?, NOW())');
    $stmt->execute([$target_id, 11]);
    
    // Уменьшение количества противогазов
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 11')
        ->execute([$user_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'gas_mask', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'gas_mask', 'target' => $target_id]);
}

/***
 * Использование детектора яда.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если детектор яда недоступен.
 * returns int|null Идентификатор раунда заражения или null, если цель не заражена.
 */
function usePoisonDetector($pdo, $user_id, $target_id){
    // Проверка наличия детектора яда
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 13');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет детектора яда');
    }
    
    // Уменьшение количества детекторов яда
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 13')
        ->execute([$user_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'poison_detector', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'poison_detector', 'target' => $target_id]);
    //Проверка заражения цели
    $stmt = $pdo->prepare('SELECT infection_round FROM users WHERE user_id = ?');
    $stmt->execute([$target_id]);
    $infection_round = $stmt->fetchColumn();
    return $infection_round;
}

/***
 * Использование защитного костюма.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если защитный костюм недоступен.
 * @throws Exception Если у цели уже есть защитный костюм.
 */
function useProtectiveSuit($pdo, $user_id, $target_id){
    //Проверка наличия защитного костюма
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 14');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет защитного костюма');
    }
    //Проверка на наличие защитного костюма у цели
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE user_id = ? AND item_id = 14');
    $stmt->execute([$target_id]);
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(14, $active_items)) {
        throw new Exception('У цели уже есть защитный костюм!');
    }
    //Применение защитного костюма к цели
    $stmt = $pdo->prepare('INSERT INTO active_items (user_id, item_id, applied_at) VALUES (?, ?, NOW())');
    $stmt->execute([$target_id, 14]);
    // Уменьшение количества защитных костюмов
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 14')
        ->execute([$user_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'protective_suit', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'protective_suit', 'target' => $target_id]);
}

/***
 * Использование камеры слежения.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если камера слежения недоступна.
 */
function useTrapCamera($pdo, $user_id){
    // Проверка наличия камеры слежения
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 15');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет камеры слежения');
    }
    
    // Уменьшение количества камер слежения
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 15')
        ->execute([$user_id]);
    
    // Запись использования способности
    $stmt = $pdo->prepare('INSERT INTO active_items (user_id, item_id, applied_at) VALUES (?, ?, NOW())');
    $stmt->execute([$user_id, 15]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'trap_camera', NULL);
    logAction($pdo, $user_id, 'item', ['type' => 'trap_camera']);
}

/**
 * Использование чипа хакера.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param string $action Действие ('steal' или 'modify_inventory').
 * @param int|null $item_id Идентификатор предмета (только для 'modify_inventory').
 *
 * @throws Exception Если чип хакера недоступен.
 */

function useHackerChip($pdo, $user_id, $action, $item_id = null){
    // Проверка наличия чипа хакера
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 16');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет чипа хакера');
    }
    
    // Уменьшение количества чипов хакера
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 16')
        ->execute([$user_id]);
    
    // Шанс успеха (70%)
    $success_chance = rand(1, 100) <= 70;

    if (!$success_chance) {
        throw new Exception('Взлом не удался');
    }

    if ($action === 'steal') {
        // Украсть 5 монет
        $stmt = $pdo->prepare('UPDATE users SET money = money + 5 WHERE user_id = ?');
        $stmt->execute([$user_id]);
        logAction($pdo, $user_id, 'item', ['type' => 'steal', 'amount' => 5]);
    } elseif ($action === 'modify_inventory') {
        // Изменить инвентарь (например, добавить предмет)
        if (!$item_id) {
            throw new Exception('Не выбран предмет для добавления');
        }
        $stmt = $pdo->prepare('
            INSERT INTO inventories (user_id, item_id, quantity)
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + 1
        ');
        $stmt->execute([$user_id, $item_id]);

        //запуск рандомного события
        triggerRandomEvent($pdo);
        logAction($pdo, $user_id, 'item', ['type' => 'modify_inventory', 'item_id' => $item_id]);
    } else {
        throw new Exception('Неизвестное действие');
    }

    // Записываем использование способности
    logAbilityUse($pdo, $user_id, 'hack', null);
    
}

/***
 * Использование яда.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если яд недоступен.
 * @throws Exception Если цель защищена.
 * @throws Exception Если цель уже заражена.
 */
function usePoison($pdo, $user_id, $target_id){
    // Проверка наличия яда
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 17');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет яда');
    }
    
    // Уменьшение количества ядов
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 17')
        ->execute([$user_id]);
    
    // Проверка на наличие противогаза у цели
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE user_id = ? AND item_id = 11');
    $stmt->execute([$target_id]);
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(11, $active_items)) {
        // Убираем защиту у цели
        $stmt = $pdo->prepare('UPDATE active_items SET is_active = 0 WHERE user_id = ? AND item_id = 11');
        $stmt->execute([$target_id]);
        throw new Exception('Цель защищена!');
    }
    // Проверка на наличие защитного костюма у цели
    $stmt = $pdo->prepare('SELECT item_id FROM active_items WHERE user_id = ? AND item_id = 14');
    $stmt->execute([$target_id]);
    $active_items = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (in_array(14, $active_items)) {
        // Убираем защиту у цели
        $stmt = $pdo->prepare('UPDATE active_items SET is_active = 0 WHERE user_id = ? AND item_id = 14');
        $stmt->execute([$target_id]);
        throw new Exception('Цель защищена!');
    }
    //проверка на наличие заражения у цели
    $stmt = $pdo->prepare('SELECT infection_round FROM users WHERE user_id = ?');
    $stmt->execute([$target_id]);
    $infection_round = $stmt->fetchColumn();
    if ($infection_round != null) {
        throw new Exception('Цель уже заражена!');
    }
    
    // Заражение цели
    $pdo->prepare('UPDATE users SET infection_round = (SELECT MAX(round_id) FROM rounds) WHERE user_id = ?')
        ->execute([$target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'poison', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'poison', 'target' => $target_id]);
}

/**
 * Использование мутагена.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если мутаген недоступен.
 */
function useMutagen($pdo, $user_id, $target_id){
    // Проверка наличия мутагена
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 18');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет мутагена');
    }
    
    // Уменьшение количества мутагенов
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 18')
        ->execute([$user_id]);
    
    // Заражение цели
    $pdo->prepare('UPDATE users SET faction = ? WHERE user_id = ?')
        ->execute(['зомби',$target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'mutagen', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'mutagen', 'target' => $target_id]);
}

/***
 * Использование сыворотки прогресса.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если сыворотка прогресса недоступна.
 */
function useProgressSerum($pdo, $user_id, $target_id){
    // Проверка наличия сыворотки прогресса
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 19');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет сыворотки прогресса');
    }
    
    // Уменьшение количества сывороток прогресса
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 19')
        ->execute([$user_id]);
    
    // Заражение цели
    $pdo->prepare('UPDATE users SET faction = ? WHERE user_id = ?')
        ->execute(['человек',$target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'progress_serum', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'progress_serum', 'target' => $target_id]);
}

/***
 * Использование дымовой гранаты.
 *
 * @param PDO $pdo       Объект подключения к БД.
 * @param int $user_id   Идентификатор пользователя, использующего способность.
 * @param int $target_id Идентификатор цели.
 *
 * @throws Exception Если дымовая граната недоступна.
 */
function useSmokeGrenade($pdo, $user_id, $target_id){
    // Проверка наличия дымовой гранаты
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 20');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет дымовой гранаты');
    }
    // Уменьшение количества дымовых гранат
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 20')
        ->execute([$user_id]);
    
    // Применение дымовой гранаты к цели
    $stmt = $pdo->prepare('UPDATE users SET status = ? WHERE user_id = ?');
    $stmt->execute(['фальшивая смерть', $target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'smoke_grenade', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'smoke_grenade', 'target' => $target_id]);
}

/*
    * Использование гранаты.
    *
    * @param PDO $pdo       Объект подключения к БД.
    * @param int $user_id   Идентификатор пользователя, использующего способность.
    * @param int $target_id Идентификатор цели.
    *
    * @throws Exception Если граната недоступна.
    */
function useGrenade($pdo, $user_id){
    // Проверка наличия гранаты
    $stmt = $pdo->prepare('SELECT quantity FROM inventories WHERE user_id = ? AND item_id = 21');
    $stmt->execute([$user_id]);
    if ($stmt->fetchColumn() < 1) {
        throw new Exception('Нет гранаты');
    }
    
    // Уменьшение количества гранат
    $pdo->prepare('UPDATE inventories SET quantity = quantity - 1 WHERE user_id = ? AND item_id = 21')
        ->execute([$user_id]);
    
    // Применение гранаты к цели
    //определение цели
    $all_users = getAliveUsers($pdo, $user_id);
    $target_id = $all_users[array_rand($all_users)]['user_id']; //выбор случайной цели
    $stmt = $pdo->prepare('UPDATE users SET hp = ?, status = ? WHERE user_id = ?');
    $stmt->execute([0, 'мёртв', $target_id]);
    
    //запуск рандомного события
    triggerRandomEvent($pdo);
    logAbilityUse($pdo, $user_id, 'grenade', $target_id);
    logAction($pdo, $user_id, 'item', ['type' => 'boom_grenade', 'target' => $target_id]);
}


/*********************************************************************************
 * ФУНКЦИИ РАБОТЫ С ИНВЕНТАРЁМ И ПОЛУЧЕНИЯ СПИСКА ЦЕЛЕЙ
 * Здесь находятся функции для получения инвентаря, получения списка целей для
 * использования предметов, а также списки живых, заражённых и мёртвых пользователей.
 *********************************************************************************/

/**
 * Получает инвентарь пользователя.
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя.
 *
 * @return array Массив предметов из инвентаря.
 */
function getUserInventory($pdo, $user_id) {
    $stmt = $pdo->prepare('
        SELECT i.*, inv.quantity 
        FROM inventories inv
        JOIN items i ON inv.item_id = i.item_id
        WHERE inv.user_id = ? AND inv.quantity > 0
    ');
    $stmt->execute([$user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Возвращает список целей для предметов, требующих выбора цели.
 *
 * @param PDO   $pdo     Объект подключения к БД.
 * @param mixed $item    Идентификатор предмета.
 * @param int   $user_id Идентификатор пользователя.
 *
 * @return array|null Список целей или null, если цель не выбирается.
 */
function getAvailableItemsTargets($pdo, $item, $user_id){
    // Список предметов, требующих выбора цели
    $targetRequiredItems = [8, 9, 11, 12, 13, 14, 17, 18, 19, 20];

    if (in_array($item, $targetRequiredItems)) {
        $stmt = $pdo->prepare('
            SELECT user_id, name
            FROM users
            WHERE (status != "мёртв" OR status != "фальшивая смерть")
                AND faction != \'admin\'
        ');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } 

    // Предметы без необходимости выбора цели
    return null;
}

/**
 * Получает список живых пользователей (исключая администратора).
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя (не используется в запросе).
 *
 * @return array Список живых пользователей.
 */
function getAliveUsers($pdo, $user_id) {
    $stmt = $pdo->prepare('
        SELECT user_id, name 
        FROM users 
        WHERE (status != "мёртв" OR status != "фальшивая смерть") AND faction != \'admin\'
    ');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Получает список заражённых пользователей (исключая администратора).
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя (не используется в запросе).
 *
 * @return array Список заражённых пользователей.
 */
function getInfectedUsers($pdo, $user_id) {
    $stmt = $pdo->prepare('
        SELECT user_id, name 
        FROM users 
        WHERE status = "заражён" AND faction != \'admin\'
    ');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Получает список мёртвых пользователей (исключая администратора).
 *
 * @param PDO $pdo     Объект подключения к БД.
 * @param int $user_id Идентификатор пользователя (не используется в запросе).
 *
 * @return array Список мёртвых пользователей.
 */
function getDeathUsers($pdo, $user_id) {
    $stmt = $pdo->prepare('
        SELECT user_id, name 
        FROM users 
        WHERE status = "мёртв" AND faction != \'admin\'
    ');
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


/*********************************************************************************
 * ФУНКЦИИ ОБРАБОТКИ ЗАПРОСОВ НА ПОМОЩЬ
 * Эти функции отвечают за принятие или отклонение запросов на помощь (например,
 * при использовании способности "притворная смерть").
 *********************************************************************************/

/**
 * Принятие запроса на помощь.
 *
 * @param PDO $pdo            Объект подключения к БД.
 * @param int $help_user_id   Идентификатор пользователя, нуждающегося в помощи.
 * @param int $helper_id      Идентификатор пользователя, оказавшего помощь.
 *
 * @throws Exception Если запрос не найден или уже обработан.
 */
function acceptHelpRequest($pdo, $help_user_id, $helper_id) {
    // Проверяем наличие запроса помощи
    $stmt = $pdo->prepare('
        SELECT u.name, au.user_id
        FROM ability_uses au INNER JOIN users u ON u.user_id=au.user_id
        WHERE ability_type=? AND target=? AND round_id=? AND is_read is NULL
    ');
    $stmt->execute(['fake_death', $helper_id, getCurrentRound($pdo)]);
    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception('Запрос не найден или уже обработан');
    }

    // Обновляем статус запроса, помечая его как обработанный
    $stmt = $pdo->prepare('
        UPDATE ability_uses
        SET is_read = 1
        WHERE user_id = ? AND ability_type = ? AND round_id = ? AND target = ?
    ');
    $stmt->execute([$help_user_id, 'fake_death', getCurrentRound($pdo), $helper_id]);

    // Обновляем статус пользователя, устанавливая "фальшивая смерть"
    $stmt = $pdo->prepare('
        UPDATE users 
        SET status = ?
        WHERE user_id = ?
    ');

    //запуск рандомного события
    triggerRandomEvent($pdo);
    $stmt->execute(['фальшивая смерть', $help_user_id]);
    logAction($pdo, $helper_id, 'ability', ['type' => 'accept_fake_death', 'target' => $help_user_id]);
}

/**
 * Отклонение запроса на помощь.
 *
 * @param PDO $pdo            Объект подключения к БД.
 * @param int $help_user_id   Идентификатор пользователя, нуждающегося в помощи.
 * @param int $helper_id      Идентификатор пользователя, отклоняющего помощь.
 *
 * @throws Exception Если запрос не найден или уже обработан.
 */
function declineHelpRequest($pdo, $help_user_id, $helper_id) {
    // Проверяем наличие запроса помощи
    $stmt = $pdo->prepare('
        SELECT u.name, au.user_id
        FROM ability_uses au INNER JOIN users u ON u.user_id=au.user_id
        WHERE ability_type=? AND target=? AND round_id=? AND is_read is NULL
    ');
    $stmt->execute(['fake_death', $helper_id, getCurrentRound($pdo)]);
    $request = $stmt->fetch();

    if (!$request) {
        throw new Exception('Запрос не найден или уже обработан');
    }

    // Обновляем запрос, помечая его как обработанный
    $stmt = $pdo->prepare('
        UPDATE ability_uses
        SET is_read = 1
        WHERE user_id = ? AND ability_type = ? AND round_id = ? AND target = ?
    ');

    //запуск рандомного события
    triggerRandomEvent($pdo);
    $stmt->execute([$help_user_id, 'fake_death', getCurrentRound($pdo), $helper_id]);
    logAction($pdo, $helper_id, 'ability', ['type' => 'decline_fake_death', 'target' => $help_user_id]);
}


/*********************************************************************************
 * ЗАГЛУШКИ ФУНКЦИЙ ДЛЯ РАЗНЫХ ПРЕДМЕТОВ
 * Ниже представлены функции для способностей, пока не реализованных:
 *
 * Функции пока не содержат реализации, их можно доработать при необходимости.
 *********************************************************************************/

?>
