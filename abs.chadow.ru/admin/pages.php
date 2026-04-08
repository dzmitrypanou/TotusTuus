<?php
require_once __DIR__ . '/includes/bootstrap.php';

$_versionRaw = @file_get_contents(__DIR__ . '/../config/version.json');
$_versionData = $_versionRaw ? json_decode($_versionRaw, true) : null;
$appVersion = (is_array($_versionData) && !empty($_versionData['version'])) ? $_versionData['version'] : '3.4.4';

admin_require_web();

$db_error = null;
$pagesList = [];

try {
    $pagesList = $db->fetchAll(
        'SELECT id, slug, title, is_published, updated_at FROM cms_pages ORDER BY updated_at DESC'
    );
} catch (Exception $e) {
    $db_error = $e->getMessage();
}

$pageTitle = 'Страницы сайта | Админка';
$extraHead = '<link rel="stylesheet" href="https://cdn.quilljs.com/1.3.6/quill.snow.css">'
    . '<link rel="stylesheet" href="/admin/css/pages-editor.css?v=' . htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') . '">';
$bodyClass = 'admin-pages-cms';
require __DIR__ . '/includes/admin_head.php';
?>

    <div class="container">
        <div class="header">
            <h1>
                <i class="fas fa-file-alt" style="color: #ffd966;"></i>
                Страницы сайта
            </h1>
            <?php $navCurrent = 'pages'; include __DIR__ . '/includes/header_nav.php'; ?>
        </div>

        <?php if ($db_error !== null): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i>
                Ошибка БД: <?php echo htmlspecialchars($db_error); ?>
            </div>
        <?php else: ?>
            <div class="pages-cms-layout">
                <aside class="pages-cms-sidebar">
                    <h3>Список страниц</h3>
                    <p style="margin: 0 0 10px 0;">
                        <button type="button" class="btn btn-primary" id="cmsNewPageBtn" style="width: 100%;">
                            <i class="fas fa-plus"></i> Новая страница
                        </button>
                    </p>
                    <ul class="pages-cms-list" id="pagesCmsList">
                        <?php foreach ($pagesList as $p): ?>
                            <li>
                                <button type="button" class="pages-cms-item" data-id="<?php echo (int) $p['id']; ?>">
                                    <?php echo htmlspecialchars($p['title']); ?>
                                    <span class="pages-cms-meta"><?php echo htmlspecialchars($p['slug']); ?>
                                        <?php echo !empty($p['is_published']) ? ' · опубл.' : ' · черновик'; ?>
                                    </span>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </aside>

                <div class="pages-cms-main">
                    <form id="cmsPageForm" class="pages-cms-form">
                        <input type="hidden" name="id" id="cmsPageId" value="0">

                        <div class="pages-cms-form-row">
                            <div class="form-group">
                                <label for="cmsPageTitle">Заголовок</label>
                                <input type="text" id="cmsPageTitle" name="title" required placeholder="Название в шапке браузера и на странице">
                            </div>

                            <div class="form-group">
                                <label for="cmsPageTitleEn">English title</label>
                                <input type="text" id="cmsPageTitleEn" name="title_en"
                                       placeholder="Title for /en page header and title">
                            </div>

                            <div class="form-group">
                                <label for="cmsPageSlug">Адрес (slug)</label>
                                <input type="text" id="cmsPageSlug" name="slug" required pattern="[a-z0-9\-]{1,128}" placeholder="например: about или help-page">
                                <div class="pages-cms-hint">Только латиница, цифры и дефис. Адрес на сайте: <span id="cmsSlugPreview">/</span></div>
                            </div>
                        </div>

                        <div class="form-group form-group--cms-published">
                            <label class="cms-published-switch" for="cmsPagePublished">
                                <input type="checkbox" name="is_published" id="cmsPagePublished" value="1">
                                <span class="cms-published-slider" aria-hidden="true"></span>
                                <span class="cms-published-label">Опубликована (видна на сайте)</span>
                            </label>
                        </div>

                        <span class="pages-cms-label-text">Текст (RU)</span>
                        <div class="pages-editor-shell">
                            <div id="quill-mount"></div>
                        </div>

                        <span class="pages-cms-label-text">Text (EN)</span>
                        <div class="pages-editor-shell">
                            <div id="quill-mount-en"></div>
                        </div>

                        <div id="cms-float-tools" class="cms-float-tools" aria-hidden="true">
                            <button type="button" data-format="bold" title="Жирный"><strong>B</strong></button>
                            <button type="button" data-format="italic" title="Курсив"><em>I</em></button>
                            <button type="button" data-format="underline" title="Подчёркнутый"><u>U</u></button>
                            <button type="button" data-format="strike" title="Зачёркнутый"><s>S</s></button>
                            <button type="button" data-format="clean" title="Сбросить стили">Tx</button>
                            <button type="button" data-format="link" title="Ссылка">🔗</button>
                        </div>

                        <div class="pages-cms-actions">
                            <button type="submit" class="btn btn-primary" id="cmsSaveBtn">
                                <i class="fas fa-save"></i> Сохранить
                            </button>
                            <button type="button" class="btn btn-danger" id="cmsDeleteBtn" disabled>
                                <i class="fas fa-trash"></i> Удалить
                            </button>
                            <a href="/" class="btn" id="cmsOpenPublic" target="_blank" rel="noopener" style="display: none;">
                                <i class="fas fa-external-link-alt"></i> Открыть на сайте
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($db_error === null): ?>
    <script src="/admin/js/admin.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <script src="/admin/js/pages-editor.js?v=<?php echo htmlspecialchars($appVersion); ?>"></script>
    <script>
    (function () {
        const form = document.getElementById('cmsPageForm');
        const idInput = document.getElementById('cmsPageId');
        const titleInput = document.getElementById('cmsPageTitle');
        const slugInput = document.getElementById('cmsPageSlug');
        const pubInput = document.getElementById('cmsPagePublished');
        const deleteBtn = document.getElementById('cmsDeleteBtn');
        const newBtn = document.getElementById('cmsNewPageBtn');
        const slugPreview = document.getElementById('cmsSlugPreview');
        const openPublic = document.getElementById('cmsOpenPublic');
        const listEl = document.getElementById('pagesCmsList');

        function notify(msg, type) {
            if (typeof showNotification === 'function') {
                showNotification(msg, type || 'success');
            } else {
                alert(msg);
            }
        }

        function sidebarMeta(slug, isPublished) {
            return slug + (isPublished ? ' · опубл.' : ' · черновик');
        }

        function upsertSidebarRow(id, title, slug, isPublished) {
            if (!listEl) return;
            const idStr = String(id);
            let btn = listEl.querySelector('.pages-cms-item[data-id="' + idStr + '"]');
            const metaText = sidebarMeta(slug, isPublished);
            if (btn) {
                btn.replaceChildren();
                btn.appendChild(document.createTextNode(title));
                const span = document.createElement('span');
                span.className = 'pages-cms-meta';
                span.textContent = metaText;
                btn.appendChild(span);
                const li = btn.closest('li');
                if (li && listEl.firstChild !== li) {
                    listEl.insertBefore(li, listEl.firstChild);
                }
            } else {
                const li = document.createElement('li');
                btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'pages-cms-item';
                btn.setAttribute('data-id', idStr);
                btn.appendChild(document.createTextNode(title));
                const span = document.createElement('span');
                span.className = 'pages-cms-meta';
                span.textContent = metaText;
                btn.appendChild(span);
                btn.addEventListener('click', function () {
                    loadPage(btn.getAttribute('data-id'));
                });
                li.appendChild(btn);
                listEl.insertBefore(li, listEl.firstChild);
            }
        }

        function removeSidebarRow(id) {
            if (!listEl) return;
            const btn = listEl.querySelector('.pages-cms-item[data-id="' + String(id) + '"]');
            if (!btn) return;
            const li = btn.closest('li');
            if (li) li.remove();
        }

        /** Адрес вида …/pages.php?edit=123 — при обновлении страницы открыта та же запись */
        function setEditInUrl(id) {
            const url = new URL(window.location.href);
            if (id != null && id !== '' && String(id) !== '0') {
                url.searchParams.set('edit', String(id));
            } else {
                url.searchParams.delete('edit');
            }
            history.replaceState({}, '', url.pathname + url.search + url.hash);
        }

        function updateSlugPreview() {
            const s = (slugInput.value || '').trim();
            if (slugPreview) slugPreview.textContent = s ? '/' + s : '/';
            if (openPublic) {
                if (s && idInput.value !== '0' && pubInput.checked) {
                    openPublic.href = '/' + encodeURIComponent(s).replace(/%2F/g, '');
                    openPublic.style.display = 'inline-flex';
                } else {
                    openPublic.style.display = 'none';
                }
            }
        }
        slugInput.addEventListener('input', updateSlugPreview);
        pubInput.addEventListener('change', updateSlugPreview);

        function setActiveItem(id) {
            document.querySelectorAll('.pages-cms-item').forEach(function (b) {
                b.classList.toggle('is-active', id && b.getAttribute('data-id') === String(id));
            });
        }

        function resetForm() {
            idInput.value = '0';
            titleInput.value = '';
            const titleEnInput = document.getElementById('cmsPageTitleEn');
            if (titleEnInput) titleEnInput.value = '';
            slugInput.value = '';
            pubInput.checked = false;
            deleteBtn.disabled = true;
            openPublic.style.display = 'none';
            if (window.cmsSetEditorHtml) window.cmsSetEditorHtml('');
            if (window.cmsSetEditorHtmlEn) window.cmsSetEditorHtmlEn('');
            setActiveItem(null);
            updateSlugPreview();
            setEditInUrl(null);
        }

        newBtn.addEventListener('click', function () {
            resetForm();
        });

        function loadPage(id) {
            fetch('ajax/cms_page_get.php?id=' + encodeURIComponent(id))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success || !data.page) {
                        notify(data.error || 'Не удалось загрузить страницу', 'error');
                        const cur = new URLSearchParams(window.location.search).get('edit');
                        if (cur != null && String(cur) === String(id)) {
                            setEditInUrl(null);
                        }
                        return;
                    }
                    const p = data.page;
                    idInput.value = p.id;
                    titleInput.value = p.title;
                    const titleEnInput = document.getElementById('cmsPageTitleEn');
                    if (titleEnInput) titleEnInput.value = p.title_en || '';
                    slugInput.value = p.slug;
                    pubInput.checked = !!parseInt(p.is_published, 10);
                    deleteBtn.disabled = false;
                    if (window.cmsSetEditorHtml) window.cmsSetEditorHtml(p.body_html || '');
                    if (window.cmsSetEditorHtmlEn) window.cmsSetEditorHtmlEn(p.body_html_en || '');
                    setActiveItem(p.id);
                    updateSlugPreview();
                    setEditInUrl(p.id);
                })
                .catch(function () { notify('Ошибка сети', 'error'); });
        }

        document.querySelectorAll('.pages-cms-item').forEach(function (btn) {
            btn.addEventListener('click', function () {
                loadPage(btn.getAttribute('data-id'));
            });
        });

        form.addEventListener('submit', function (e) {
            e.preventDefault();
            const bodyHtmlRu = window.cmsGetEditorHtml ? window.cmsGetEditorHtml() : '';
            const bodyHtmlEn = window.cmsGetEditorHtmlEn ? window.cmsGetEditorHtmlEn() : '';
            const titleEnInput = document.getElementById('cmsPageTitleEn');
            const fd = new FormData();
            fd.set('id', idInput.value);
            fd.set('title', titleInput.value.trim());
            fd.set('title_en', titleEnInput ? titleEnInput.value.trim() : '');
            fd.set('slug', slugInput.value.trim());
            fd.set('body_html', bodyHtmlRu);
            fd.set('body_html_en', bodyHtmlEn);
            if (pubInput.checked) fd.set('is_published', '1');

            fetch('ajax/cms_page_save.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        notify(data.error || 'Ошибка сохранения', 'error');
                        return;
                    }
                    const savedId = data.id ? parseInt(data.id, 10) : 0;
                    if (savedId) idInput.value = String(savedId);
                    deleteBtn.disabled = false;
                    upsertSidebarRow(
                        savedId,
                        titleInput.value.trim(),
                        slugInput.value.trim(),
                        pubInput.checked
                    );
                    setActiveItem(savedId);
                    updateSlugPreview();
                    setEditInUrl(savedId);
                    notify('Страница сохранена', 'success');
                })
                .catch(function () { notify('Ошибка сети', 'error'); });
        });

        deleteBtn.addEventListener('click', function () {
            const id = idInput.value;
            if (!id || id === '0') return;
            if (!confirm('Удалить эту страницу?')) return;
            const fd = new FormData();
            fd.set('id', id);
            fetch('ajax/cms_page_delete.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data.success) {
                        notify(data.error || 'Ошибка удаления', 'error');
                        return;
                    }
                    const deletedId = parseInt(id, 10);
                    removeSidebarRow(deletedId);
                    resetForm();
                    notify('Страница удалена', 'success');
                })
                .catch(function () { notify('Ошибка сети', 'error'); });
        });

        updateSlugPreview();

        (function initEditFromQuery() {
            const params = new URLSearchParams(window.location.search);
            const eid = params.get('edit');
            if (eid && /^\d+$/.test(eid)) {
                loadPage(eid);
            }
        })();
    })();
    </script>
<?php endif; ?>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
