function startLongPolling() {
    let lastUpdate = 0;
    let retryCount = 0;
    const maxRetries = 5;
    const baseTimeout = 5000;

    function poll() {
        $.ajax({
            url: '../scripts/long_pooling_handler_personal_area.php',
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-Last-Update': lastUpdate
            },
            timeout: 70000,
            success: function(response) {
                retryCount = 0;
                // ← Добавить проверку структуры ответа
                if (response && response.data && response.last_update > lastUpdate) {
                    lastUpdate = response.last_update;
                    updateUI(response.data);
                }
                setTimeout(poll, 5000);
            },
            error: function(xhr) {
                retryCount++;
                const delay = baseTimeout * Math.pow(2, retryCount);
                console.error('Polling error:', xhr.status);
                setTimeout(poll, Math.min(delay, 60000));
            }
        });
    }

    poll();
}

function updateUI(data) {
    const gameStatus = data.game_status;

    // Обновляем данные во всех секциях
    $(`.greeting-${gameStatus}`).text(data.name);
    $(`.status-${gameStatus}`).text(data.status);
    $(`.role-${gameStatus}`).text(data.role);
    $(`.faction-${gameStatus}`).text(data.faction);
    $(`.hp-${gameStatus}`).text(data.hp);
    
    // Скрываем все секции
    $('.status-dependent').hide();
    
    // Показываем нужную секцию
    $(`.${gameStatus}-section`)
        .show()
        .find('.value') // Анимируем только видимые элементы
        .addClass('updated');
        
    setTimeout(() => $('.value').removeClass('updated'), 1000);
}

$(document).ready(startLongPolling);