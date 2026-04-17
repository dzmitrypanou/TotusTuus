/**
 * Той жа рэдактар, што ў admin/index.php (initRichEditors / syncRichEditors).
 * Глабальныя функцыі для onsubmit формы.
 */
'use strict';

function decodeBase64Unicode(value) {
    if (!value) return '';
    try {
        return decodeURIComponent(
            Array.prototype.map
                .call(atob(value), function (c) {
                    return '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2);
                })
                .join('')
        );
    } catch (e) {
        return '';
    }
}

function initRichEditors() {
    document.querySelectorAll('.rich-quick-toolbar').forEach(function (toolbar) {
        if (toolbar.parentNode) toolbar.parentNode.removeChild(toolbar);
    });

    function createQuickToolbar() {
        var quick = document.createElement('div');
        quick.className = 'rich-quick-toolbar';
        quick.innerHTML =
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="bold" title="Тоўсты" aria-label="Тоўсты"><b>B</b></button>' +
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="italic" title="Курсіў" aria-label="Курсіў"><i>I</i></button>' +
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyLeft" title="Улева" aria-label="Улева">L</button>' +
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyCenter" title="Па цэнтры" aria-label="Па цэнтры">C</button>' +
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyRight" title="Управа" aria-label="Управа">R</button>' +
            '<button type="button" class="rich-btn rich-btn-icon" data-cmd="justifyFull" title="Па шырыні" aria-label="Па шырыні">J</button>' +
            '<div class="rich-color-picker-wrap">' +
            '<button type="button" class="rich-color-toggle" data-color="#ffffff" style="background:#ffffff;" title="Абраць колер" aria-label="Абраць колер"></button>' +
            '<div class="rich-color-dropdown" role="group" aria-label="Колер тэксту">' +
            '<button type="button" class="rich-color-swatch" data-color="#000000" style="background:#000000;" title="Чорны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#1f2937" style="background:#1f2937;" title="Графіт"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#374151" style="background:#374151;" title="Цёмна-шэры"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#6b7280" style="background:#6b7280;" title="Шэры"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#9ca3af" style="background:#9ca3af;" title="Светла-шэры"></button>' +
            '<button type="button" class="rich-color-swatch rich-color-swatch--white active" data-color="#ffffff" style="background:#ffffff;" title="Белы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#7f1d1d" style="background:#7f1d1d;" title="Бардовы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#b91c1c" style="background:#b91c1c;" title="Цёмна-чырвоны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#ef4444" style="background:#ef4444;" title="Чырвоны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#f87171" style="background:#f87171;" title="Светла-чырвоны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#7c2d12" style="background:#7c2d12;" title="Карычневы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#c2410c" style="background:#c2410c;" title="Цёмна-аранжавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#f97316" style="background:#f97316;" title="Аранжавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#fb923c" style="background:#fb923c;" title="Светла-аранжавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#854d0e" style="background:#854d0e;" title="Гарчычны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#eab308" style="background:#eab308;" title="Жоўты"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#fde047" style="background:#fde047;" title="Светла-жоўты"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#3f6212" style="background:#3f6212;" title="Аліўкавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#15803d" style="background:#15803d;" title="Цёмна-зялёны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#22c55e" style="background:#22c55e;" title="Зялёны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#4ade80" style="background:#4ade80;" title="Светла-зялёны"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#0f766e" style="background:#0f766e;" title="Цёмна-бірузовы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#14b8a6" style="background:#14b8a6;" title="Бірузовы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#2dd4bf" style="background:#2dd4bf;" title="Светла-бірузовы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#1e3a8a" style="background:#1e3a8a;" title="Цёмна-сіні"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#2563eb" style="background:#2563eb;" title="Сіні"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#60a5fa" style="background:#60a5fa;" title="Светла-сіні"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#312e81" style="background:#312e81;" title="Індыга"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#4f46e5" style="background:#4f46e5;" title="Светла-індыга"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#581c87" style="background:#581c87;" title="Цёмна-фіялетавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#9333ea" style="background:#9333ea;" title="Фіялетавы"></button>' +
            '<button type="button" class="rich-color-swatch" data-color="#d946ef" style="background:#d946ef;" title="Пурпурны"></button>' +
            '</div>' +
            '</div>';
        document.body.appendChild(quick);
        return quick;
    }

    var editors = document.querySelectorAll('.js-rich-editor');
    editors.forEach(function (editor) {
        if (editor.dataset.editorBound === '1') return;
        editor.dataset.editorBound = '1';
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;

        var initialEncoded = editor.getAttribute('data-initial-html');
        if (initialEncoded && editor.innerHTML.trim() === '') {
            editor.innerHTML = decodeBase64Unicode(initialEncoded);
        }
        if (!initialEncoded && hiddenField.value && editor.innerHTML.trim() === '') {
            editor.innerHTML = hiddenField.value;
        }
        if (editor.innerHTML.trim() === '') {
            editor.innerHTML = '<p></p>';
        }
        hiddenField.value = editor.innerHTML.trim();
        var quickToolbar = createQuickToolbar();
        var savedRange = null;

        function saveSelectionRange() {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0) return;
            var range = sel.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) return;
            savedRange = range.cloneRange();
        }

        function restoreSelectionRange() {
            if (!savedRange) return false;
            var sel = window.getSelection();
            if (!sel) return false;
            sel.removeAllRanges();
            sel.addRange(savedRange);
            return true;
        }

        function hideQuickToolbar() {
            quickToolbar.style.display = 'none';
            quickToolbar.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
                pickerWrap.classList.remove('open');
            });
        }

        function positionQuickToolbar() {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) {
                hideQuickToolbar();
                return;
            }
            var range = sel.getRangeAt(0);
            if (!editor.contains(range.commonAncestorContainer)) {
                hideQuickToolbar();
                return;
            }
            saveSelectionRange();
            var rect = range.getBoundingClientRect();
            if (!rect || (rect.width === 0 && rect.height === 0)) {
                hideQuickToolbar();
                return;
            }
            quickToolbar.style.display = 'flex';
            var top = window.scrollY + rect.top - quickToolbar.offsetHeight - 10;
            if (top < window.scrollY + 10) {
                top = window.scrollY + rect.bottom + 10;
            }
            var left = window.scrollX + rect.left + rect.width / 2 - quickToolbar.offsetWidth / 2;
            var maxLeft = window.scrollX + window.innerWidth - quickToolbar.offsetWidth - 10;
            if (left < window.scrollX + 10) left = window.scrollX + 10;
            if (left > maxLeft) left = maxLeft;
            quickToolbar.style.top = top + 'px';
            quickToolbar.style.left = left + 'px';
        }

        editor.addEventListener('input', function () {
            hiddenField.value = editor.innerHTML.trim();
            positionQuickToolbar();
        });
        editor.addEventListener('mouseup', positionQuickToolbar);
        editor.addEventListener('keyup', positionQuickToolbar);
        editor.addEventListener('blur', function () {
            setTimeout(function () {
                var active = document.activeElement;
                if (quickToolbar.contains(active)) return;
                hideQuickToolbar();
            }, 0);
        });
        document.addEventListener('selectionchange', positionQuickToolbar);
        window.addEventListener('scroll', positionQuickToolbar, true);
        window.addEventListener('resize', positionQuickToolbar);

        var toolbar = editor.closest('.rich-editor-wrap');
        if (!toolbar) return;
        function runCommand(cmd, value) {
            editor.focus();
            restoreSelectionRange();
            try {
                document.execCommand(cmd, false, value || null);
            } catch (e) {
                return;
            }
            hiddenField.value = editor.innerHTML.trim();
            saveSelectionRange();
            positionQuickToolbar();
        }
        function setActiveColor(color) {
            var normalized = (color || '').toLowerCase();
            var allScopes = [toolbar, quickToolbar];
            allScopes.forEach(function (scope) {
                scope.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
                    var swatchColor = (swatch.getAttribute('data-color') || '').toLowerCase();
                    swatch.classList.toggle('active', swatchColor === normalized);
                });
                scope.querySelectorAll('.rich-color-toggle').forEach(function (toggle) {
                    toggle.setAttribute('data-color', color);
                    toggle.style.background = color;
                });
            });
        }
        function closeAllColorPickers(exceptWrap) {
            [toolbar, quickToolbar].forEach(function (scope) {
                scope.querySelectorAll('.rich-color-picker-wrap.open').forEach(function (pickerWrap) {
                    if (exceptWrap && pickerWrap === exceptWrap) return;
                    pickerWrap.classList.remove('open');
                });
            });
        }
        function bindColorPickers(scope, keepSelectionOnToggle) {
            scope.querySelectorAll('.rich-color-picker-wrap').forEach(function (pickerWrap) {
                if (pickerWrap.dataset.bound === '1') return;
                pickerWrap.dataset.bound = '1';
                var toggle = pickerWrap.querySelector('.rich-color-toggle');
                if (toggle) {
                    toggle.addEventListener('click', function () {
                        if (keepSelectionOnToggle) {
                            restoreSelectionRange();
                        } else {
                            saveSelectionRange();
                        }
                        var willOpen = !pickerWrap.classList.contains('open');
                        closeAllColorPickers(pickerWrap);
                        pickerWrap.classList.toggle('open', willOpen);
                    });
                }
                pickerWrap.querySelectorAll('.rich-color-swatch').forEach(function (swatch) {
                    swatch.addEventListener('mousedown', function (event) {
                        event.preventDefault();
                        restoreSelectionRange();
                    });
                    swatch.addEventListener('click', function () {
                        var color = swatch.getAttribute('data-color');
                        runCommand('foreColor', color);
                        setActiveColor(color);
                        pickerWrap.classList.remove('open');
                    });
                });
            });
        }
        toolbar.querySelectorAll('.rich-btn').forEach(function (button) {
            if (button.dataset.bound === '1') return;
            button.dataset.bound = '1';
            button.addEventListener('click', function () {
                var cmd = button.getAttribute('data-cmd');
                var value = button.getAttribute('data-value') || null;
                runCommand(cmd, value);
            });
        });
        bindColorPickers(toolbar, false);
        quickToolbar.querySelectorAll('.rich-btn').forEach(function (button) {
            button.addEventListener('mousedown', function (event) {
                event.preventDefault();
                restoreSelectionRange();
            });
            button.addEventListener('click', function () {
                var cmd = button.getAttribute('data-cmd');
                runCommand(cmd, null);
            });
        });
        bindColorPickers(quickToolbar, true);
        document.addEventListener('mousedown', function (event) {
            if (editor.contains(event.target)) return;
            if (event.target.closest('.rich-color-picker-wrap')) return;
            closeAllColorPickers(null);
            if (!quickToolbar.contains(event.target)) {
                hideQuickToolbar();
            }
        });
    });
}

function syncRichEditors() {
    document.querySelectorAll('.js-rich-editor').forEach(function (editor) {
        var targetId = editor.getAttribute('data-target-id');
        if (!targetId) return;
        var hiddenField = document.getElementById(targetId);
        if (!hiddenField) return;
        hiddenField.value = editor.innerHTML.trim();
    });
}

function panelRichEditorBoot() {
    initRichEditors();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', panelRichEditorBoot);
} else {
    panelRichEditorBoot();
}
