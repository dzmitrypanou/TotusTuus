(function () {
    if (typeof Quill === 'undefined') return;

    const mountRu = document.getElementById('quill-mount');
    const mountEn = document.getElementById('quill-mount-en');
    if (!mountRu && !mountEn) return;

    const toolbarOptions = [
        [{ header: [1, 2, 3, false] }],
        [{ color: [] }],
        [{ align: [] }],
        ['bold', 'italic', 'underline', 'strike'],
        ['blockquote', 'code-block'],
        [{ list: 'ordered' }, { list: 'bullet' }],
        ['link', 'clean']
    ];

    const quillRu = mountRu
        ? new Quill('#quill-mount', {
            theme: 'snow',
            modules: { toolbar: toolbarOptions },
            placeholder: 'Текст страницы…'
        })
        : null;

    const quillEn = mountEn
        ? new Quill('#quill-mount-en', {
            theme: 'snow',
            modules: { toolbar: toolbarOptions },
            placeholder: 'English page text…'
        })
        : null;

    const floatEl = document.getElementById('cms-float-tools');
    let activeQuill = quillRu || quillEn;

    function hideFloat() {
        if (floatEl) floatEl.classList.remove('is-visible');
    }

    function positionFloat(quill, range) {
        if (!floatEl || !quill || !range || range.length === 0) {
            hideFloat();
            return;
        }
        try {
            const bounds = quill.getBounds(range.index, range.length);
            const editorRect = quill.root.getBoundingClientRect();
            floatEl.classList.add('is-visible');
            floatEl.style.left = Math.max(8, editorRect.left + bounds.left) + 'px';
            const top = editorRect.top + bounds.top - floatEl.offsetHeight - 8;
            floatEl.style.top = Math.max(8, top) + 'px';
        } catch (e) {
            hideFloat();
        }
    }

    function bindQuillHandlers(quill) {
        if (!quill) return;
        quill.on('selection-change', function (range) {
            activeQuill = quill;
            if (!range || range.length === 0) {
                hideFloat();
                return;
            }
            requestAnimationFrame(function () {
                positionFloat(quill, range);
            });
        });
        quill.on('text-change', function () {
            const range = quill.getSelection();
            if (!range || range.length === 0) hideFloat();
        });
    }

    bindQuillHandlers(quillRu);
    bindQuillHandlers(quillEn);

    function applyFormat(name, value) {
        const quill = activeQuill;
        if (!quill) return;
        const range = quill.getSelection(true);
        if (!range) return;

        if (name === 'link') {
            const url = window.prompt('URL ссылки:', 'https://');
            if (url === null) return;
            if (url === '') {
                quill.format('link', false);
            } else {
                quill.format('link', url);
            }
        } else {
            quill.format(name, value !== undefined ? value : !quill.getFormat(range)[name]);
        }
        quill.focus();
    }

    function bindToolButtons(container) {
        if (!container) return;
        container.querySelectorAll('[data-format]').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                const fmt = btn.getAttribute('data-format');
                const quill = activeQuill;
                if (!quill) return;
                const range = quill.getSelection(true);
                if (!range) return;

                if (fmt === 'link') {
                    applyFormat('link');
                } else if (fmt === 'clean') {
                    quill.removeFormat(range.index, range.length);
                } else {
                    const cur = quill.getFormat(range);
                    quill.format(fmt, !cur[fmt]);
                }
                hideFloat();
            });
        });
    }

    bindToolButtons(floatEl);

    document.addEventListener('scroll', function () {
        if (!activeQuill) return;
        const range = activeQuill.getSelection();
        if (range && range.length > 0) positionFloat(activeQuill, range);
    }, true);

    // Backward compatibility for code that expects a single editor.
    window.__cmsQuill = quillRu || quillEn;

    window.cmsGetEditorHtml = function () {
        return quillRu ? quillRu.root.innerHTML : '';
    };
    window.cmsSetEditorHtml = function (html) {
        if (!quillRu || typeof html !== 'string') return;
        quillRu.root.innerHTML = html;
    };

    window.cmsGetEditorHtmlEn = function () {
        return quillEn ? quillEn.root.innerHTML : '';
    };
    window.cmsSetEditorHtmlEn = function (html) {
        if (!quillEn || typeof html !== 'string') return;
        quillEn.root.innerHTML = html;
    };
})();
