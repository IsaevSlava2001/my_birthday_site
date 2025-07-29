$(document).ready(() => {
    // Скрываем элементы при загрузке
    $('.input-method').hide();

    // Переключатель методов ввода
    $('.manual-btn').click((e) => {
        e.preventDefault();
        $('.input-method').not('.manual').hide();
        $('.manual').toggle();
        $('.success_info').empty(); // Сброс сообщений
        $('#manualCode').val('');
    });

    // Обработка отправки кода
    let isProcessing = false;
    $('.submit-btn').click(async (e) => {
        e.preventDefault();
        
        if (isProcessing) return;
        isProcessing = true;

        const code = $('#manualCode').val().trim();
        if (!code) {
            alert('Введите код');
            isProcessing = false;
            return;
        }

        try {
            const response = await $.ajax({
                url: '../scripts/process_code.php',
                method: 'POST',
                data: { code },
                dataType: 'json'
            });

            if (response.success) {
                $('.success_info')
                    .text(response.message)
                    .css('color', '#4CAF50')
                    .fadeIn()
                    .delay(3000)
                    .fadeOut();
                    
                $('#manualCode').val('');
                // Обновляем интерфейс через long polling
            }

        } catch (error) {
            const errorMessage = error.responseJSON?.error || 'Неизвестная ошибка';
            $('.success_info')
                .text(errorMessage)
                .css('color', '#f44336')
                .fadeIn()
                .delay(3000)
                .fadeOut();
                
        } finally {
            isProcessing = false;
        }
    });
});