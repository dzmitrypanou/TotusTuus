function showNotification(message, type = 'success') {
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) existingNotification.remove();
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    const icon = type === 'error' ? 'exclamation-circle' : 'check-circle';
    notification.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function openUserModal(user) {
    const modal = document.getElementById('userModal');
    const title = document.getElementById('userModalTitle');
    const idEl = document.getElementById('user_id');
    const pw = document.getElementById('user_password');
    const hint = document.getElementById('user_password_hint');

    document.getElementById('userForm').reset();
    if (user && user.id) {
        title.innerHTML = '<i class="fas fa-edit"></i> Редактировать пользователя';
        idEl.value = String(user.id);
        document.getElementById('user_username').value = user.username || '';
        document.getElementById('user_role').value = user.role === 'admin' ? 'admin' : 'user';
        pw.required = false;
        pw.placeholder = 'Оставьте пустым, чтобы не менять';
        if (hint) hint.style.display = 'block';
    } else {
        title.innerHTML = '<i class="fas fa-user-plus"></i> Новый пользователь';
        idEl.value = '';
        pw.required = true;
        pw.placeholder = 'Не менее 8 символов';
        if (hint) hint.style.display = 'block';
    }
    modal.classList.add('active');
}

function closeUserModal() {
    document.getElementById('userModal').classList.remove('active');
}

async function deleteUser(id, username) {
    if (!confirm(`Удалить пользователя «${username}»?`)) return;
    const fd = new FormData();
    fd.append('id', String(id));
    try {
        const r = await fetch('/admin/ajax/users_delete.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            showNotification('Пользователь удалён');
            window.location.reload();
        } else {
            showNotification(data.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showNotification('Ошибка сети', 'error');
    }
}

document.getElementById('userForm')?.addEventListener('submit', async e => {
    e.preventDefault();
    const fd = new FormData(e.target);
    const id = fd.get('id');
    if (!id) {
        fd.delete('id');
    }
    try {
        const r = await fetch('/admin/ajax/users_save.php', { method: 'POST', body: fd });
        const data = await r.json();
        if (data.success) {
            showNotification('Сохранено');
            closeUserModal();
            window.location.reload();
        } else {
            showNotification(data.error || 'Ошибка', 'error');
        }
    } catch (err) {
        showNotification('Ошибка сети', 'error');
    }
});

document.getElementById('userModal')?.addEventListener('click', e => {
    if (e.target.id === 'userModal') closeUserModal();
});
