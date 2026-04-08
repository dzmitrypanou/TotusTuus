function showDictNotification(message, type = 'success') {
    const existing = document.querySelector('.notification');
    if (existing) existing.remove();
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    const icon = type === 'error' ? 'exclamation-circle' : 'check-circle';
    notification.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 300);
    }, 2500);
}

function bindSaveNation() {
    document.querySelectorAll('.save-nation-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            if (!tr) return;
            const code = tr.getAttribute('data-code');
            const ruInput = tr.querySelector('input[name="display_name_ru"]');
            const enInput = tr.querySelector('input[name="display_name_en"]');
            const displayRu = ruInput ? ruInput.value.trim() : '';
            const displayEn = enInput ? enInput.value.trim() : '';
            if (!displayRu) {
                showDictNotification('Введите название', 'error');
                return;
            }
            const body = new URLSearchParams();
            body.set('nation_code', code);
            body.set('display_name_ru', displayRu);
            body.set('display_name_en', displayEn);
            fetch('ajax/save_nation_label.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showDictNotification('Сохранено', 'success');
                    } else {
                        showDictNotification(data.error || 'Ошибка', 'error');
                    }
                })
                .catch(() => showDictNotification('Ошибка запроса', 'error'));
        });
    });
}

function bindSaveType() {
    document.querySelectorAll('.save-type-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            if (!tr) return;
            const code = tr.getAttribute('data-code');
            const ruInput = tr.querySelector('input[name="display_name_ru"]');
            const enInput = tr.querySelector('input[name="display_name_en"]');
            const displayRu = ruInput ? ruInput.value.trim() : '';
            const displayEn = enInput ? enInput.value.trim() : '';
            if (!displayRu) {
                showDictNotification('Введите название', 'error');
                return;
            }
            const body = new URLSearchParams();
            body.set('type_code', code);
            body.set('display_name_ru', displayRu);
            body.set('display_name_en', displayEn);
            fetch('ajax/save_tank_type_label.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        showDictNotification('Сохранено', 'success');
                    } else {
                        showDictNotification(data.error || 'Ошибка', 'error');
                    }
                })
                .catch(() => showDictNotification('Ошибка запроса', 'error'));
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    bindSaveNation();
    bindSaveType();
});
