let allMaps = [];

function showNotification(message, type = 'success') {
    const existingNotification = document.querySelector('.notification');
    if (existingNotification) existingNotification.remove();
    const notification = document.createElement('div');
    notification.className = `notification ${type}`;
    let icon = 'check-circle';
    if (type === 'error') icon = 'exclamation-circle';
    notification.innerHTML = `<i class="fas fa-${icon}"></i><span>${message}</span>`;
    document.body.appendChild(notification);
    setTimeout(() => {
        notification.classList.add('fade-out');
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

function isMapModerated(m) {
    return Number(m.is_moderated) === 1 || m.is_moderated === true;
}

function updateMapsStats() {
    const total = allMaps.length;
    const mod = allMaps.filter(isMapModerated).length;
    const unmod = total - mod;
    const countEl = document.getElementById('mapsCountDisplay');
    const modEl = document.getElementById('mapsModeratedCount');
    const unmodEl = document.getElementById('mapsUnmoderatedCount');
    if (countEl) countEl.textContent = String(total);
    if (modEl) modEl.textContent = String(mod);
    if (unmodEl) unmodEl.textContent = String(unmod);
}

function getFilteredMaps() {
    const q = (document.getElementById('mapsSearch')?.value || '').trim().toLowerCase();
    const modFilter = document.getElementById('mapsModeration')?.value || 'all';

    let rows = allMaps;
    if (q) {
        rows = rows.filter(
            m =>
                (m.map_code && m.map_code.toLowerCase().includes(q)) ||
                ((m.display_name_ru && m.display_name_ru.toLowerCase().includes(q)) ||
                    (m.display_name_en && m.display_name_en.toLowerCase().includes(q)))
        );
    }
    if (modFilter === 'moderated') {
        rows = rows.filter(isMapModerated);
    } else if (modFilter === 'unmoderated') {
        rows = rows.filter(m => !isMapModerated(m));
    }
    return rows;
}

function renderMapsTable() {
    const tbody = document.getElementById('mapsTableBody');
    const rows = getFilteredMaps();

    if (!tbody) return;

    if (rows.length === 0) {
        tbody.innerHTML =
            '<tr><td colspan="4" style="text-align: center;">Нет записей</td></tr>';
        return;
    }

    tbody.innerHTML = rows
        .map(m => {
            const moderated = isMapModerated(m);
            const rowClass = moderated ? 'moderated' : 'unmoderated';
            const modCell = moderated
                ? `<span class="moderation-badge moderated"><i class="fas fa-check-circle"></i> Проверено</span>`
                : `<span class="moderation-badge unmoderated js-quick-moderate" role="button" tabindex="0" title="Отметить проверенным"><i class="fas fa-clock"></i> На проверке</span>`;
            return `
        <tr class="${rowClass}">
            <td><code>${escapeHtml(m.map_code)}</code></td>
            <td>${escapeHtml(m.display_name_ru)}${m.display_name_en ? ' / ' + escapeHtml(m.display_name_en) : ''}</td>
            <td>${modCell}</td>
            <td>
                <div class="action-buttons">
                    <button type="button" class="action-btn js-edit-map" title="Изменить">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button" class="action-btn delete js-delete-map" title="Удалить">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </td>
        </tr>`;
        })
        .join('');

    tbody.querySelectorAll('tr').forEach((tr, i) => {
        const m = rows[i];
        if (!m) return;
        const editBtn = tr.querySelector('.js-edit-map');
        if (editBtn) {
            editBtn.addEventListener('click', () => openEditMapModal(m));
        }
        const delBtn = tr.querySelector('.js-delete-map');
        if (delBtn) {
            delBtn.addEventListener('click', () => deleteMap(m.map_code));
        }
        const quick = tr.querySelector('.js-quick-moderate');
        if (quick) {
            quick.addEventListener('click', () => markMapModerated(m.map_code));
            quick.addEventListener('keydown', e => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    markMapModerated(m.map_code);
                }
            });
        }
    });
}

function escapeHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function openEditMapModal(m) {
    document.getElementById('edit_map_code').value = m.map_code;
    document.getElementById('edit_map_code_hidden').value = m.map_code;
    document.getElementById('edit_map_display').value = m.display_name_ru || '';
    document.getElementById('edit_map_display_en').value = m.display_name_en || '';
    document.getElementById('edit_map_moderated').checked = isMapModerated(m);
    document.getElementById('editMapModal').classList.add('active');
}

function closeEditMapModal() {
    document.getElementById('editMapModal').classList.remove('active');
}

async function markMapModerated(mapCode) {
    const fd = new FormData();
    fd.append('map_code', mapCode);
    fd.append('moderated', '1');
    try {
        const response = await fetch('/admin/ajax/moderate_map.php', {
            method: 'POST',
            body: fd
        });
        const result = await response.json();
        if (result.success) {
            showNotification('Отмечено как проверенное');
            const row = allMaps.find(x => x.map_code === mapCode);
            if (row) row.is_moderated = 1;
            updateMapsStats();
            renderMapsTable();
        } else {
            showNotification(result.error || 'Ошибка', 'error');
        }
    } catch (e) {
        showNotification('Ошибка сети', 'error');
    }
}

async function deleteMap(mapCode) {
    if (!confirm('Удалить карту из словаря? Отображение на главной вернётся к техническому коду до следующей загрузки реплея.')) {
        return;
    }
    const fd = new FormData();
    fd.append('map_code', mapCode);
    try {
        const response = await fetch('/admin/ajax/delete_map.php', {
            method: 'POST',
            body: fd
        });
        const result = await response.json();
        if (result.success) {
            showNotification('Карта удалена');
            allMaps = allMaps.filter(x => x.map_code !== mapCode);
            updateMapsStats();
            renderMapsTable();
        } else {
            showNotification(result.error || 'Ошибка удаления', 'error');
        }
    } catch (e) {
        showNotification('Ошибка сети', 'error');
    }
}

async function loadMaps() {
    try {
        const response = await fetch('/api/get_maps.php');
        const data = await response.json();
        if (!data.success || !Array.isArray(data.data)) {
            showNotification('Не удалось загрузить словарь карт', 'error');
            allMaps = [];
        } else {
            allMaps = data.data;
        }
        updateMapsStats();
        renderMapsTable();
    } catch (e) {
        showNotification('Ошибка сети при загрузке карт', 'error');
        const tbody = document.getElementById('mapsTableBody');
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="4" style="text-align: center;">Ошибка загрузки</td></tr>';
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadMaps();

    const search = document.getElementById('mapsSearch');
    if (search) {
        search.addEventListener('input', () => renderMapsTable());
    }

    const modSel = document.getElementById('mapsModeration');
    if (modSel) {
        modSel.addEventListener('change', () => renderMapsTable());
    }

    document.getElementById('mapsResetFilters')?.addEventListener('click', () => {
        if (search) search.value = '';
        if (modSel) modSel.value = 'all';
        renderMapsTable();
    });

    const form = document.getElementById('editMapForm');
    if (form) {
        form.addEventListener('submit', async e => {
            e.preventDefault();
            const fd = new FormData(form);
            try {
                const response = await fetch('/admin/ajax/update_map.php', {
                    method: 'POST',
                    body: fd
                });
                const result = await response.json();
                if (result.success) {
                    showNotification('Сохранено');
                    closeEditMapModal();
                    await loadMaps();
                } else {
                    showNotification(result.error || 'Ошибка сохранения', 'error');
                }
            } catch (err) {
                showNotification('Ошибка сети', 'error');
            }
        });
    }

    document.getElementById('editMapModal')?.addEventListener('click', e => {
        if (e.target.id === 'editMapModal') closeEditMapModal();
    });
});
