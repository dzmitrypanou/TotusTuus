function showNotification(message, type) {
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    const notification = document.createElement('div');
    notification.className = 'notification ' + type;
    let icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    if (type === 'info') icon = 'info-circle';
    const span = document.createElement('span');
    span.textContent = message;
    const ic = document.createElement('i');
    ic.className = 'fas fa-' + icon;
    notification.appendChild(ic);
    notification.appendChild(span);
    document.body.appendChild(notification);
    const delay = type === 'error' ? 5500 : 3000;
    setTimeout(function () {
        notification.classList.add('fade-out');
        setTimeout(function () {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, delay);
}

document.addEventListener('DOMContentLoaded', function () {
    var err = window.__loginToastError;
    if (err) {
        showNotification(err, 'error');
    }
});
