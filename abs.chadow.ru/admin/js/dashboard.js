function showNotification(message, type = 'success') {
    const existing = document.querySelector('.notification');
    if (existing) existing.remove();
    const n = document.createElement('div');
    n.className = `notification ${type}`;
    const icon = type === 'error' ? 'exclamation-circle' : 'check-circle';
    n.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    document.body.appendChild(n);
    setTimeout(() => {
        n.classList.add('fade-out');
        setTimeout(() => n.remove(), 300);
    }, 3500);
}

document.getElementById('dashboardPasswordForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const newPass = (fd.get('new_password') || '').toString();
    const confirm = (fd.get('new_password_confirm') || '').toString();

    if (newPass !== confirm) {
        showNotification('Новый пароль и подтверждение не совпадают', 'error');
        return;
    }

    try {
        const r = await fetch('/admin/ajax/profile_password.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            showNotification('Пароль успешно изменён');
            e.target.reset();
        } else {
            showNotification(data.error || 'Ошибка', 'error');
        }
    } catch (err) {
        showNotification('Ошибка сети', 'error');
    }
});
