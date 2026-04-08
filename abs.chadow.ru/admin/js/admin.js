function normalizeVehicleCode(code) {
    if (!code || typeof code !== 'string') return '';
    const c = code.trim();
    if (!c) return '';
    if (c.includes(':')) {
        const idx = c.indexOf(':');
        const nation = c.slice(0, idx).trim().toLowerCase();
        const rest = c.slice(idx + 1).trim();
        return rest === '' ? c : `${nation}:${rest}`;
    }
    const dash = c.indexOf('-');
    if (dash !== -1) {
        const nation = c.slice(0, dash).toLowerCase();
        const rest = c.slice(dash + 1);
        if (rest && /^[a-z][a-z0-9_]*$/.test(nation)) {
            return `${nation}:${rest}`;
        }
    }
    return c;
}
function showNotification(message, type = 'success') {
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) {
        existingNotification.remove();
    }
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    let icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    if (type === 'info') icon = 'info-circle';
    notification.innerHTML = `
        <i class="fas fa-${icon}"></i>
        <span>${message}</span>
    `;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => {
            if (notification.parentNode) {
                notification.remove();
            }
        }, 300);
    }, 3000);
}
function copyToClipboard(elementId) {
    const input = document.getElementById(elementId);
    if (!input) return;
    const wasDisabled = input.disabled;
    if (input.disabled) {
        input.disabled = false;
    }
    input.select();
    input.setSelectionRange(0, 99999);
    try {
        const successful = document.execCommand('copy');
        if (successful) {
            const btn = document.querySelector(`.copy-btn[data-copy="${elementId}"]`);
            if (btn) {
                const originalHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-check"></i>';
                btn.classList.add('copied');
                setTimeout(() => {
                    btn.innerHTML = originalHtml;
                    btn.classList.remove('copied');
                }, 2000);
            }
            showNotification('Скопировано в буфер обмена', 'info');
        } else {
            showNotification('Ошибка при копировании', 'error');
        }
    } catch (err) {
        showNotification('Ошибка при копировании', 'error');
    }
    if (wasDisabled) {
        input.disabled = true;
    }
}
function openEditModal(tank) {
    document.getElementById('edit_id').value = tank.id;
    document.getElementById('edit_code').value = tank.vehicle_code;
    document.getElementById('edit_name').value = tank.display_name_ru;
    const editNameEn = document.getElementById('edit_name_en');
    if (editNameEn) {
        editNameEn.value = tank.display_name_en || tank.display_name_ru;
    }
    const editNation = document.getElementById('edit_nation');
    if (editNation) {
        editNation.value = tank.nation || 'unknown';
    }
    document.getElementById('edit_type').value = tank.tank_type;
    document.getElementById('edit_tier').value = tank.tier;
    document.getElementById('edit_regular_label').classList.remove('active');
    document.getElementById('edit_premium_label').classList.remove('active');
    document.getElementById('edit_collectible_label').classList.remove('active');
    if (tank.is_premium == 1) {
        document.querySelector('#edit_premium_label input').checked = true;
        document.getElementById('edit_premium_label').classList.add('active');
    } else if (tank.is_collectible == 1) {
        document.querySelector('#edit_collectible_label input').checked = true;
        document.getElementById('edit_collectible_label').classList.add('active');
    } else {
        document.querySelector('#edit_regular_label input').checked = true;
        document.getElementById('edit_regular_label').classList.add('active');
    }
    document.getElementById('edit_moderated').checked = tank.is_moderated == 1;
    document.getElementById('editModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editModal').classList.remove('active');
}
function syncAddNationFromCode() {
    const codeInput = document.getElementById('add_code');
    const nationSel = document.getElementById('add_nation');
    if (!codeInput || !nationSel) return;
    const norm = normalizeVehicleCode(codeInput.value || '');
    const prefix = norm.indexOf(':') >= 0 ? norm.split(':')[0].trim().toLowerCase() : '';
    if (!prefix) return;
    const opt = Array.from(nationSel.options).find(o => o.value === prefix);
    if (opt) {
        nationSel.value = prefix;
    }
}
function openAddModal() {
    document.getElementById('addForm').reset();
    document.querySelector('#add_regular_label input').checked = true;
    document.getElementById('add_regular_label').classList.add('active');
    document.getElementById('add_premium_label').classList.remove('active');
    document.getElementById('add_collectible_label').classList.remove('active');
    document.getElementById('add_moderated').checked = false;
    syncAddNationFromCode();
    document.getElementById('addModal').classList.add('active');
}
function closeAddModal() {
    document.getElementById('addModal').classList.remove('active');
}
function markAsModerated(tankId) {
    if (confirm('Отметить этот танк как проверенный?')) {
        fetch('ajax/moderate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + tankId + '&moderated=1'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Танк отмечен как проверенный', 'success');
                loadTanks();
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка при отправке запроса', 'error');
        });
    }
}
let searchTimeout = null;
async function loadTanks() {
    const tableBody = document.querySelector('#tanks-table tbody');
    if (!tableBody) return;
    const params = new URLSearchParams();
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput && searchInput.value) params.set('search', searchInput.value);
    const typeSelect = document.querySelector('select[name="type"]');
    if (typeSelect && typeSelect.value !== 'all') params.set('type', typeSelect.value);
    const nationSelect = document.querySelector('select[name="nation"]');
    if (nationSelect && nationSelect.value !== 'all') params.set('nation', nationSelect.value);
    const moderationSelect = document.querySelector('select[name="moderation"]');
    if (moderationSelect && moderationSelect.value !== 'all') params.set('moderation', moderationSelect.value);
    const perPageSelect = document.getElementById('perPage');
    if (perPageSelect) params.set('perPage', perPageSelect.value);
    const pageParam = new URLSearchParams(window.location.search).get('page');
    if (pageParam) params.set('page', pageParam);
    try {
        const response = await fetch('ajax/get_tanks.php?' + params.toString());
        if (!response.ok) {
            throw new Error('HTTP error: ' + response.status);
        }
        const data = await response.json();
        if (data.success) {
            tableBody.innerHTML = data.html;
            updatePagination(data.total, data.page, data.perPage, data.totalPages);
            updateStats();
            attachSwitcherListeners();
        } else {
            tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #ff8a8a;">Ошибка: ' + (data.error || 'Неизвестная ошибка') + '<\/td><\/tr>';
            if (data.error === 'Не авторизован') {
                window.location.href = '/admin/';
            }
        }
    } catch (error) {
        tableBody.innerHTML = '<tr><td colspan="8" style="text-align: center; color: #ff8a8a;">Ошибка загрузки данных: ' + error.message + '<\/td><\/tr>';
    }
}
async function updateStats() {
    try {
        const response = await fetch('ajax/get_stats.php');
        const data = await response.json();
        if (data.success) {
            const statCards = document.querySelectorAll('.stat-card');
            if (statCards.length >= 6) {
                statCards[0].querySelector('.value').textContent = data.stats.total;
                statCards[1].querySelector('.value').textContent = data.stats.moderated;
                statCards[2].querySelector('.value').textContent = data.stats.unmoderated;
                statCards[3].querySelector('.value').textContent = data.stats.premium;
                statCards[4].querySelector('.value').textContent = data.stats.collectible;
                statCards[5].querySelector('.value').textContent = data.stats.regular;
            }
        }
    } catch (error) {}
}
function updatePagination(total, currentPage, perPage, totalPages) {
    const paginationContainer = document.getElementById('paginationInfo');
    if (!paginationContainer) return;
    const currentPerPage = perPage;
    let paginationHtml = `
        <div class="per-page-selector">
            <label for="perPage">Показывать:</label>
            <select id="perPage" onchange="changePerPage(this.value)">
                <option value="50" ${currentPerPage == 50 ? 'selected' : ''}>50</option>
                <option value="100" ${currentPerPage == 100 ? 'selected' : ''}>100</option>
                <option value="250" ${currentPerPage == 250 ? 'selected' : ''}>250</option>
            </select>
            <span style="margin-left: 10px; color: #9aa7b2;">Всего: ${total} записей</span>
        </div>
    `;
    if (totalPages > 1) {
        paginationHtml += '<div class="pagination">';
        if (currentPage > 1) {
            paginationHtml += `<a href="#" onclick="changePage(1); return false;">«</a>`;
        }
        let startPage = Math.max(1, currentPage - 2);
        let endPage = Math.min(totalPages, currentPage + 2);
        if (startPage > 1) {
            paginationHtml += `<span style="padding: 8px 12px;">...</span>`;
        }
        for (let i = startPage; i <= endPage; i++) {
            paginationHtml += `<a href="#" onclick="changePage(${i}); return false;" class="${i == currentPage ? 'active' : ''}">${i}</a>`;
        }
        if (endPage < totalPages) {
            paginationHtml += `<span style="padding: 8px 12px;">...</span>`;
        }
        if (currentPage < totalPages) {
            paginationHtml += `<a href="#" onclick="changePage(${totalPages}); return false;">»</a>`;
        }
        paginationHtml += '</div>';
    }
    paginationContainer.innerHTML = paginationHtml;
}
function changePage(page) {
    const url = new URL(window.location.href);
    url.searchParams.set('page', page);
    window.history.pushState({}, '', url);
    loadTanks();
}
function changePerPage(perPage) {
    const url = new URL(window.location.href);
    url.searchParams.set('perPage', perPage);
    url.searchParams.delete('page');
    window.history.pushState({}, '', url);
    loadTanks();
}
function handleSearchInput() {
    if (searchTimeout) {
        clearTimeout(searchTimeout);
    }
    searchTimeout = setTimeout(() => {
        const url = new URL(window.location.href);
        url.searchParams.delete('page');
        window.history.pushState({}, '', url);
        loadTanks();
    }, 300);
}
function handleFilterChange() {
    const url = new URL(window.location.href);
    url.searchParams.delete('page');
    window.history.pushState({}, '', url);
    loadTanks();
}
function resetFilters() {
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) searchInput.value = '';
    const typeSelect = document.querySelector('select[name="type"]');
    if (typeSelect) typeSelect.value = 'all';
    const nationSelect = document.querySelector('select[name="nation"]');
    if (nationSelect) nationSelect.value = 'all';
    const moderationSelect = document.querySelector('select[name="moderation"]');
    if (moderationSelect) moderationSelect.value = 'all';
    const url = new URL(window.location.href);
    url.search = '';
    window.history.pushState({}, '', url);
    loadTanks();
}
function submitAddTank(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    fetch('ajax/add_tank.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Танк успешно добавлен', 'success');
            closeAddModal();
            loadTanks();
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка при отправке запроса', 'error');
    });
}
function submitUpdateTank(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    fetch('ajax/update_tank.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Танк успешно обновлен', 'success');
            closeEditModal();
            loadTanks();
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    })
    .catch(error => {
        showNotification('Ошибка при отправке запроса', 'error');
    });
}
function deleteTank(tankId) {
    if (confirm('Удалить этот танк? Это действие нельзя отменить.')) {
        fetch('ajax/delete_tank.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'id=' + tankId
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Танк успешно удален', 'success');
                loadTanks();
            } else {
                showNotification('Ошибка: ' + data.error, 'error');
            }
        })
        .catch(error => {
            showNotification('Ошибка при отправке запроса', 'error');
        });
    }
}
function attachSwitcherListeners() {
    const switcherOptions = document.querySelectorAll('.modal .switcher-option');
    switcherOptions.forEach(option => {
        option.removeEventListener('click', handleSwitcherClick);
        option.addEventListener('click', handleSwitcherClick);
    });
}
function handleSwitcherClick(e) {
    e.preventDefault();
    const radio = this.querySelector('input[type="radio"]');
    if (radio) {
        radio.checked = true;
        const parent = this.closest('.tech-switcher');
        if (parent) {
            parent.querySelectorAll('.switcher-option').forEach(opt => {
                opt.classList.remove('active');
            });
        }
        this.classList.add('active');
    }
}
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        const selection = window.getSelection().toString();
        if (!selection) {
            closeEditModal();
            closeAddModal();
        }
    }
}
function loadTankDetails(tankId) {
    fetch('ajax/get_tank_details.php?id=' + tankId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const editNation = document.getElementById('edit_nation');
                if (editNation) {
                    editNation.value = data.tank.nation || 'unknown';
                }
                document.getElementById('edit_type').value = data.tank.tank_type;
                document.getElementById('edit_tier').value = data.tank.tier;
                document.getElementById('edit_regular_label').classList.remove('active');
                document.getElementById('edit_premium_label').classList.remove('active');
                document.getElementById('edit_collectible_label').classList.remove('active');
                if (data.tank.is_premium == 1) {
                    document.querySelector('#edit_premium_label input').checked = true;
                    document.getElementById('edit_premium_label').classList.add('active');
                } else if (data.tank.is_collectible == 1) {
                    document.querySelector('#edit_collectible_label input').checked = true;
                    document.getElementById('edit_collectible_label').classList.add('active');
                } else {
                    document.querySelector('#edit_regular_label input').checked = true;
                    document.getElementById('edit_regular_label').classList.add('active');
                }
                document.getElementById('edit_moderated').checked = data.tank.is_moderated == 1;
            }
        })
        .catch(error => console.error('Ошибка загрузки данных танка:', error));
}
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('search')) {
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) searchInput.value = urlParams.get('search');
    }
    if (urlParams.has('type')) {
        const typeSelect = document.querySelector('select[name="type"]');
        if (typeSelect) typeSelect.value = urlParams.get('type');
    }
    if (urlParams.has('nation')) {
        const nationSelect = document.querySelector('select[name="nation"]');
        if (nationSelect) nationSelect.value = urlParams.get('nation');
    }
    if (urlParams.has('moderation')) {
        const moderationSelect = document.querySelector('select[name="moderation"]');
        if (moderationSelect) moderationSelect.value = urlParams.get('moderation');
    }
    if (urlParams.has('perPage')) {
        const perPageSelect = document.getElementById('perPage');
        if (perPageSelect) perPageSelect.value = urlParams.get('perPage');
    }
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', handleSearchInput);
    }
    const typeSelect = document.querySelector('select[name="type"]');
    if (typeSelect) {
        typeSelect.addEventListener('change', handleFilterChange);
    }
    const nationSelect = document.querySelector('select[name="nation"]');
    if (nationSelect) {
        nationSelect.addEventListener('change', handleFilterChange);
    }
    const moderationSelect = document.querySelector('select[name="moderation"]');
    if (moderationSelect) {
        moderationSelect.addEventListener('change', handleFilterChange);
    }
    const resetFiltersBtn = document.getElementById('resetFiltersBtn');
    if (resetFiltersBtn) {
        resetFiltersBtn.addEventListener('click', resetFilters);
    }
    const addCodeInput = document.getElementById('add_code');
    if (addCodeInput) {
        addCodeInput.addEventListener('input', syncAddNationFromCode);
        addCodeInput.addEventListener('blur', syncAddNationFromCode);
    }
    const addForm = document.getElementById('addForm');
    if (addForm) {
        addForm.addEventListener('submit', submitAddTank);
    }
    const editForm = document.getElementById('editForm');
    if (editForm) {
        editForm.addEventListener('submit', submitUpdateTank);
    }
    const copyButtons = document.querySelectorAll('.copy-btn');
    copyButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const targetId = this.getAttribute('data-copy');
            if (targetId) {
                copyToClipboard(targetId);
            }
        });
    });
    attachSwitcherListeners();
    loadTanks();
});