(function () {
    const tbodyHeader = document.getElementById('siteMenuRowsHeader');
    const tbodyFooter = document.getElementById('siteMenuRowsFooter');
    const saveBtn = document.getElementById('siteMenuSaveBtn');
    if (!tbodyHeader || !tbodyFooter || !saveBtn) return;

    function rowHtml() {
        return (
            '<td><input type="text" class="site-menu-label" value="" placeholder="Название"></td>' +
            '<td><input type="text" class="site-menu-label-en" value="" placeholder="Name (EN)"></td>' +
            '<td><input type="text" class="site-menu-href" value="" placeholder="/page или https://…"></td>' +
            '<td class="site-menu-col-enabled">' +
            '<label class="site-menu-switch">' +
            '<input type="checkbox" class="site-menu-enabled" checked title="Показывать пункт">' +
            '<span class="site-menu-switch-slider" aria-hidden="true"></span>' +
            '</label></td>' +
            '<td class="site-menu-col-actions">' +
            '<button type="button" class="btn btn-danger site-menu-row-del" title="Удалить строку">Удалить</button>' +
            '</td>'
        );
    }

    function addRow(tbody) {
        const tr = document.createElement('tr');
        tr.className = 'site-menu-row';
        tr.innerHTML = rowHtml();
        tbody.appendChild(tr);
        bindRow(tr);
        const inp = tr.querySelector('.site-menu-label');
        if (inp) inp.focus();
    }

    function bindRow(tr) {
        const del = tr.querySelector('.site-menu-row-del');
        if (del) {
            del.addEventListener('click', function () {
                tr.remove();
            });
        }
    }

    tbodyHeader.querySelectorAll('.site-menu-row').forEach(bindRow);
    tbodyFooter.querySelectorAll('.site-menu-row').forEach(bindRow);

    document.querySelectorAll('.site-menu-add-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-target');
            const tb = id ? document.getElementById(id) : null;
            if (tb) addRow(tb);
        });
    });

    function collectRows(tbody) {
        const items = [];
        tbody.querySelectorAll('.site-menu-row').forEach(function (tr) {
            const label = (tr.querySelector('.site-menu-label') || {}).value || '';
            const labelEn = (tr.querySelector('.site-menu-label-en') || {}).value || '';
            const href = (tr.querySelector('.site-menu-href') || {}).value || '';
            const enabled = tr.querySelector('.site-menu-enabled');
            items.push({
                label: label.trim(),
                label_en: labelEn.trim(),
                href: href.trim(),
                is_enabled: enabled && enabled.checked
            });
        });
        return items;
    }

    saveBtn.addEventListener('click', function () {
        const payload = {
            header: collectRows(tbodyHeader),
            footer: collectRows(tbodyFooter)
        };

        saveBtn.disabled = true;
        fetch('ajax/site_menu_save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8' },
            body: JSON.stringify(payload)
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (typeof showNotification === 'function') {
                    if (data.success) {
                        showNotification('Меню сохранено', 'success');
                    } else {
                        showNotification(data.error || 'Ошибка сохранения', 'error');
                    }
                } else {
                    alert(data.success ? 'Сохранено' : (data.error || 'Ошибка'));
                }
            })
            .catch(function () {
                if (typeof showNotification === 'function') {
                    showNotification('Ошибка сети', 'error');
                } else {
                    alert('Ошибка сети');
                }
            })
            .finally(function () {
                saveBtn.disabled = false;
            });
    });
})();
