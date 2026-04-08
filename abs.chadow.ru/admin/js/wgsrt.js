let originalCoefficients = {};
let originalGrades = {};
let currentActiveTab = 'coefficients';
let activeColorPickerPanel = null;
document.addEventListener('DOMContentLoaded', function() {
    saveOriginalValues();
    document.querySelectorAll('.tab').forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const tabName = this.dataset.tab;
            currentActiveTab = tabName;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            this.classList.add('active');
            document.getElementById(`tab-${tabName}`).classList.add('active');
        });
    });
    document.getElementById('coefficientsForm').addEventListener('submit', function(e) {
        e.preventDefault();
        saveCoefficients();
    });
    document.getElementById('cancelCoefficientsBtn').addEventListener('click', function() {
        loadCoefficients();
    });
    document.getElementById('resetCoefficientsBtn').addEventListener('click', function() {
        resetCoefficients();
    });
    document.getElementById('gradesForm').addEventListener('submit', function(e) {
        e.preventDefault();
        saveGrades();
    });
    document.getElementById('cancelGradesBtn').addEventListener('click', function() {
        loadGrades();
    });
    document.getElementById('addGradeBtn').addEventListener('click', function() {
        addGrade();
    });
    validateGradeRanges();
});
function saveOriginalValues() {
    document.querySelectorAll('.coef-value').forEach(input => {
        const param = input.name.replace('coef_', '');
        originalCoefficients[param] = {
            coef: input.value,
            norm: document.querySelector(`[name="norm_${param}"]`).value,
            min: document.querySelector(`[name="min_${param}"]`).value,
            max: document.querySelector(`[name="max_${param}"]`).value
        };
    });
    originalGrades = {};
    document.querySelectorAll('.grade-row').forEach(row => {
        const id = row.dataset.gradeId;
        if (id && !id.toString().startsWith('new_')) {
            originalGrades[id] = {
                name: row.querySelector(`[name^="name_"]`).value,
                nameEn: (row.querySelector(`[name^="name_en_"]`) || { value: '' }).value,
                code: row.querySelector(`[name^="code_"]`).value,
                min: row.querySelector(`[name^="min_"]`).value,
                max: row.querySelector(`[name^="max_"]`).value,
                desc: row.querySelector(`[name^="desc_"]`).value,
                descEn: (row.querySelector(`[name^="desc_en_"]`) || { value: '' }).value,
                order: row.querySelector(`[name^="order_"]`).value,
                color: row.querySelector('.grade-color-input').value
            };
        }
    });
}
function loadCoefficients() {
    for (const [param, values] of Object.entries(originalCoefficients)) {
        document.querySelector(`[name="coef_${param}"]`).value = values.coef;
        document.querySelector(`[name="norm_${param}"]`).value = values.norm;
        document.querySelector(`[name="min_${param}"]`).value = values.min;
        document.querySelector(`[name="max_${param}"]`).value = values.max;
    }
    showNotification('Значения сброшены', 'info');
}
function loadGrades() {
    for (const [id, values] of Object.entries(originalGrades)) {
        const row = document.querySelector(`.grade-row[data-grade-id="${id}"]`);
        if (row) {
            row.querySelector(`[name^="name_"]`).value = values.name;
            const nameEnInput = row.querySelector(`[name^="name_en_"]`);
            if (nameEnInput) {
                nameEnInput.value = values.nameEn || '';
            }
            row.querySelector(`[name^="code_"]`).value = values.code;
            row.querySelector(`[name^="min_"]`).value = values.min;
            row.querySelector(`[name^="max_"]`).value = values.max;
            row.querySelector(`[name^="desc_"]`).value = values.desc || '';
            const descEnInput = row.querySelector(`[name^="desc_en_"]`);
            if (descEnInput) {
                descEnInput.value = values.descEn || '';
            }
            row.querySelector(`[name^="order_"]`).value = values.order;
            const colorInput = row.querySelector('.grade-color-input');
            colorInput.value = values.color;
            row.querySelector('.grade-color').style.background = values.color;
        }
    }
    validateGradeRanges();
    showNotification('Значения сброшены', 'info');
}
async function saveCoefficients() {
    const formData = new FormData();
    formData.append('action', 'save_coefficients');
    document.querySelectorAll('.coef-value').forEach(input => {
        const param = input.name.replace('coef_', '');
        formData.append(`coef_${param}`, input.value);
        formData.append(`norm_${param}`, document.querySelector(`[name="norm_${param}"]`).value);
        formData.append(`min_${param}`, document.querySelector(`[name="min_${param}"]`).value);
        formData.append(`max_${param}`, document.querySelector(`[name="max_${param}"]`).value);
    });
    try {
        const response = await fetch('/admin/ajax/save_wgsrt.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            showNotification('Коэффициенты сохранены', 'success');
            saveOriginalValues();
            localStorage.setItem('wgsrt_coefficients_updated', Date.now());
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    } catch (error) {
        showNotification('Ошибка при сохранении', 'error');
    }
}
async function saveGrades() {
    const formData = new FormData();
    formData.append('action', 'save_grades');
    let gradeIndex = 0;
    document.querySelectorAll('.grade-row').forEach(row => {
        const id = row.dataset.gradeId;
        formData.append(`grades[${gradeIndex}][id]`, id || 'new');
        formData.append(`grades[${gradeIndex}][name]`, row.querySelector(`[name^="name_"]`).value);
        formData.append(`grades[${gradeIndex}][name_en]`, (row.querySelector(`[name^="name_en_"]`) || { value: '' }).value);
        formData.append(`grades[${gradeIndex}][code]`, row.querySelector(`[name^="code_"]`).value);
        formData.append(`grades[${gradeIndex}][min]`, row.querySelector(`[name^="min_"]`).value);
        formData.append(`grades[${gradeIndex}][max]`, row.querySelector(`[name^="max_"]`).value);
        formData.append(`grades[${gradeIndex}][desc]`, row.querySelector(`[name^="desc_"]`).value || '');
        formData.append(`grades[${gradeIndex}][desc_en]`, (row.querySelector(`[name^="desc_en_"]`) || { value: '' }).value || '');
        formData.append(`grades[${gradeIndex}][order]`, row.querySelector(`[name^="order_"]`).value);
        formData.append(`grades[${gradeIndex}][color]`, row.querySelector('.grade-color-input').value);
        gradeIndex++;
    });
    try {
        const response = await fetch('/admin/ajax/save_wgsrt.php', {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.success) {
            showNotification('Градация сохранена', 'success');
            await reloadGradesData();
            saveOriginalValues();
            validateGradeRanges();
            localStorage.setItem('wgsrt_grades_updated', Date.now());
        } else {
            showNotification('Ошибка: ' + data.error, 'error');
        }
    } catch (error) {
        showNotification('Ошибка при сохранении', 'error');
    }
}
async function reloadGradesData() {
    try {
        const response = await fetch('/admin/ajax/get_wgsrt_grades.php');
        const data = await response.json();
        if (data.success && data.grades) {
            const tbody = document.getElementById('gradesList');
            tbody.innerHTML = '';
            data.grades.forEach(grade => {
                const row = document.createElement('tr');
                row.className = 'grade-row';
                row.dataset.gradeId = grade.id;
                row.innerHTML = `
                    <td class="grade-color-cell">
                        <div class="grade-color" style="background: ${grade.color}" onclick="openColorPicker(this, event)"></div>
                        <input type="hidden" name="color_${grade.id}" value="${grade.color}" class="grade-color-input">
                    </td>
                    <td>
                        <input type="text" name="name_${grade.id}" value="${escapeHtml(grade.grade_name)}" 
                               class="grade-input" placeholder="Название (RU)" required>
                    </td>
                    <td>
                        <input type="text" name="name_en_${grade.id}" value="${escapeHtml(grade.grade_name_en || '')}" 
                               class="grade-input" placeholder="Name (EN)">
                    </td>
                    <td>
                        <input type="text" name="code_${grade.id}" value="${grade.grade_code}" 
                               class="grade-input" placeholder="Код CSS" required pattern="[a-z-]+">
                    </td>
                    <td>
                        <div class="number-wrapper">
                            <input type="number" step="any" 
                                   name="min_${grade.id}" value="${grade.min_value}" 
                                   class="number-input number-input-small" id="min_${grade.id}" required>
                            <div class="number-controls">
                                <button type="button" class="number-up" onclick="incrementNumber('min_${grade.id}', 1)">▲</button>
                                <button type="button" class="number-down" onclick="decrementNumber('min_${grade.id}', 1)">▼</button>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div class="number-wrapper">
                            <input type="number" step="any" 
                                   name="max_${grade.id}" value="${grade.max_value}" 
                                   class="number-input number-input-small" id="max_${grade.id}" required>
                            <div class="number-controls">
                                <button type="button" class="number-up" onclick="incrementNumber('max_${grade.id}', 1)">▲</button>
                                <button type="button" class="number-down" onclick="decrementNumber('max_${grade.id}', 1)">▼</button>
                            </div>
                        </div>
                    </td>
                    <td>
                        <input type="text" name="desc_${grade.id}" value="${escapeHtml(grade.description || '')}" 
                               class="grade-input" placeholder="Описание (RU)">
                    </td>
                    <td>
                        <input type="text" name="desc_en_${grade.id}" value="${escapeHtml(grade.description_en || '')}" 
                               class="grade-input" placeholder="Description (EN)">
                    </td>
                    <td>
                        <div class="number-wrapper">
                            <input type="number" step="any" 
                                   name="order_${grade.id}" value="${grade.sort_order}" 
                                   class="number-input number-input-small" id="order_${grade.id}">
                            <div class="number-controls">
                                <button type="button" class="number-up" onclick="incrementNumber('order_${grade.id}', 1)">▲</button>
                                <button type="button" class="number-down" onclick="decrementNumber('order_${grade.id}', 1)">▼</button>
                            </div>
                        </div>
                    </td>
                    <td class="grade-actions">
                        <button type="button" class="btn btn-icon" onclick="deleteGrade(this, ${grade.id})">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }
    } catch (error) {
        console.error('Error reloading grades:', error);
    }
}
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
function addGrade() {
    const tbody = document.getElementById('gradesList');
    const newId = 'new_' + Date.now();
    const rowCount = document.querySelectorAll('.grade-row').length;
    const newRow = document.createElement('tr');
    newRow.className = 'grade-row';
    newRow.dataset.gradeId = newId;
    newRow.innerHTML = `
        <td class="grade-color-cell">
            <div class="grade-color" style="background: #ffd966" onclick="openColorPicker(this, event)"></div>
            <input type="hidden" name="color_${newId}" value="#ffd966" class="grade-color-input">
        </td>
        <td>
            <input type="text" name="name_${newId}" value="Новая градация" class="grade-input" placeholder="Название (RU)" required>
        </td>
        <td>
            <input type="text" name="name_en_${newId}" value="New grade" class="grade-input" placeholder="Name (EN)">
        </td>
        <td>
            <input type="text" name="code_${newId}" value="new-grade" class="grade-input" placeholder="Код CSS" required pattern="[a-z-]+">
        </td>
        <td>
            <div class="number-wrapper">
                <input type="number" step="any" name="min_${newId}" value="0" 
                       class="number-input number-input-small" id="min_${newId}" required>
                <div class="number-controls">
                    <button type="button" class="number-up" onclick="incrementNumber('min_${newId}', 1)">▲</button>
                    <button type="button" class="number-down" onclick="decrementNumber('min_${newId}', 1)">▼</button>
                </div>
            </div>
         </td>
         <td>
            <div class="number-wrapper">
                <input type="number" step="any" name="max_${newId}" value="1000" 
                       class="number-input number-input-small" id="max_${newId}" required>
                <div class="number-controls">
                    <button type="button" class="number-up" onclick="incrementNumber('max_${newId}', 1)">▲</button>
                    <button type="button" class="number-down" onclick="decrementNumber('max_${newId}', 1)">▼</button>
                </div>
            </div>
         </td>
         <td>
            <input type="text" name="desc_${newId}" class="grade-input" placeholder="Описание (RU)">
         </td>
         <td>
            <input type="text" name="desc_en_${newId}" class="grade-input" placeholder="Description (EN)">
         </td>
         <td>
            <div class="number-wrapper">
                <input type="number" step="any" name="order_${newId}" value="${rowCount + 1}" 
                       class="number-input number-input-small" id="order_${newId}">
                <div class="number-controls">
                    <button type="button" class="number-up" onclick="incrementNumber('order_${newId}', 1)">▲</button>
                    <button type="button" class="number-down" onclick="decrementNumber('order_${newId}', 1)">▼</button>
                </div>
            </div>
         </td>
        <td class="grade-actions">
            <button type="button" class="btn btn-icon" onclick="deleteGrade(this, null)">
                <i class="fas fa-trash"></i>
            </button>
         </td>
    `;
    tbody.appendChild(newRow);
    validateGradeRanges();
}
async function deleteGrade(element, gradeId) {
    const row = element.closest('tr');
    if (gradeId && !gradeId.toString().startsWith('new_')) {
        if (confirm('Удалить эту градацию?')) {
            try {
                const response = await fetch('/admin/ajax/save_wgsrt.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=delete_grade&id=${gradeId}`
                });
                const data = await response.json();
                if (data.success) {
                    row.remove();
                    showNotification('Градация удалена', 'success');
                    validateGradeRanges();
                    saveOriginalValues();
                    localStorage.setItem('wgsrt_grades_updated', Date.now());
                } else {
                    showNotification('Ошибка: ' + data.error, 'error');
                }
            } catch (error) {
                showNotification('Ошибка при удалении', 'error');
            }
        }
    } else {
        row.remove();
        validateGradeRanges();
    }
}
function openColorPicker(element, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    if (activeColorPickerPanel) {
        activeColorPickerPanel.remove();
        activeColorPickerPanel = null;
    }
    let currentColor = element.style.background;
    if (!currentColor || currentColor === 'rgb(255, 217, 102)' || currentColor === '#ffd966' || currentColor === 'rgb(255, 221, 102)') {
        currentColor = '#ffd966';
    } else {
        currentColor = rgbToHex(currentColor);
    }
    const rect = element.getBoundingClientRect();
    const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
    const scrollLeft = window.pageXOffset || document.documentElement.scrollLeft;
    const panelWidth = 210;
    const leftOffset = 20;
    let leftPos = rect.left + scrollLeft + (rect.width / 2) - (panelWidth / 2) - leftOffset;
    if (leftPos < 10) {
        leftPos = 10;
    }
    const maxRight = window.innerWidth - panelWidth - 10;
    if (leftPos > maxRight) {
        leftPos = maxRight;
    }
    let topPos;
    let arrowDirection;
    const panelOffset = 12;
    const verticalShift = 33;
    if (rect.top > 150 + verticalShift) {
        topPos = rect.top + scrollTop - panelOffset - 85 - verticalShift;
        arrowDirection = 'bottom';
    } else {
        topPos = rect.top + scrollTop + rect.height + panelOffset;
        arrowDirection = 'top';
    }
    const panel = document.createElement('div');
    panel.className = 'color-picker-panel';
    panel.style.position = 'absolute';
    panel.style.top = topPos + 'px';
    panel.style.left = leftPos + 'px';
    panel.style.zIndex = '10001';
    panel.style.background = '#14181c';
    panel.style.border = '1px solid #2a3138';
    panel.style.borderRadius = '8px';
    panel.style.padding = '12px';
    panel.style.boxShadow = '0 4px 20px rgba(0,0,0,0.4)';
    panel.style.display = 'flex';
    panel.style.flexDirection = 'column';
    panel.style.gap = '10px';
    panel.style.width = panelWidth + 'px';
    const arrow = document.createElement('div');
    arrow.style.position = 'absolute';
    arrow.style.width = '0';
    arrow.style.height = '0';
    arrow.style.borderStyle = 'solid';
    const targetCenterX = rect.left + scrollLeft + (rect.width / 2);
    let arrowLeftOffset = (targetCenterX - leftPos) - 8;
    const minArrowOffset = 10;
    const maxArrowOffset = panelWidth - 26;
    if (arrowLeftOffset < minArrowOffset) {
        arrowLeftOffset = minArrowOffset;
    }
    if (arrowLeftOffset > maxArrowOffset) {
        arrowLeftOffset = maxArrowOffset;
    }
    if (arrowDirection === 'top') {
        arrow.style.top = '-8px';
        arrow.style.left = arrowLeftOffset + 'px';
        arrow.style.borderWidth = '0 8px 8px 8px';
        arrow.style.borderColor = 'transparent transparent #2a3138 transparent';
    } else {
        arrow.style.bottom = '-8px';
        arrow.style.left = arrowLeftOffset + 'px';
        arrow.style.borderWidth = '8px 8px 0 8px';
        arrow.style.borderColor = '#2a3138 transparent transparent transparent';
    }
    panel.appendChild(arrow);
    const topRow = document.createElement('div');
    topRow.style.display = 'flex';
    topRow.style.alignItems = 'center';
    topRow.style.gap = '12px';
    const colorPicker = document.createElement('input');
    colorPicker.type = 'color';
    colorPicker.value = currentColor;
    colorPicker.style.width = '50px';
    colorPicker.style.height = '50px';
    colorPicker.style.border = '2px solid #ffd966';
    colorPicker.style.borderRadius = '4px';
    colorPicker.style.cursor = 'pointer';
    colorPicker.style.background = '#14181c';
    colorPicker.style.padding = '4px';
    const title = document.createElement('div');
    title.textContent = 'Выберите цвет';
    title.style.color = '#ffd966';
    title.style.fontSize = '14px';
    title.style.fontWeight = '500';
    title.style.flex = '1';
    topRow.appendChild(colorPicker);
    topRow.appendChild(title);
    const bottomRow = document.createElement('div');
    bottomRow.style.display = 'flex';
    bottomRow.style.gap = '8px';
    bottomRow.style.marginTop = '4px';
    const saveBtn = document.createElement('button');
    saveBtn.textContent = 'Сохранить';
    saveBtn.style.flex = '1';
    saveBtn.style.background = '#ffd966';
    saveBtn.style.border = 'none';
    saveBtn.style.color = '#0a0c0f';
    saveBtn.style.padding = '8px 12px';
    saveBtn.style.borderRadius = '4px';
    saveBtn.style.cursor = 'pointer';
    saveBtn.style.fontSize = '12px';
    saveBtn.style.fontWeight = '500';
    saveBtn.style.transition = 'all 0.2s';
    saveBtn.onmouseenter = function() {
        saveBtn.style.background = '#ffd44d';
        saveBtn.style.transform = 'translateY(-1px)';
    };
    saveBtn.onmouseleave = function() {
        saveBtn.style.background = '#ffd966';
        saveBtn.style.transform = 'translateY(0)';
    };
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = 'Отмена';
    cancelBtn.style.flex = '1';
    cancelBtn.style.background = 'transparent';
    cancelBtn.style.border = '1px solid #2a3138';
    cancelBtn.style.color = '#ff8a8a';
    cancelBtn.style.padding = '8px 12px';
    cancelBtn.style.borderRadius = '4px';
    cancelBtn.style.cursor = 'pointer';
    cancelBtn.style.fontSize = '12px';
    cancelBtn.style.fontWeight = '500';
    cancelBtn.style.transition = 'all 0.2s';
    cancelBtn.onmouseenter = function() {
        cancelBtn.style.background = 'rgba(255, 138, 138, 0.1)';
        cancelBtn.style.borderColor = '#ff8a8a';
    };
    cancelBtn.onmouseleave = function() {
        cancelBtn.style.background = 'transparent';
        cancelBtn.style.borderColor = '#2a3138';
    };
    bottomRow.appendChild(saveBtn);
    bottomRow.appendChild(cancelBtn);
    panel.appendChild(topRow);
    panel.appendChild(bottomRow);
    document.body.appendChild(panel);
    activeColorPickerPanel = panel;
    const saveColor = function() {
        const newColor = colorPicker.value;
        element.style.background = newColor;
        const row = element.closest('tr');
        const colorInput = row.querySelector('.grade-color-input');
        if (colorInput) {
            colorInput.value = newColor;
        }
        validateGradeRanges();
        panel.remove();
        activeColorPickerPanel = null;
        document.removeEventListener('click', closeOnOutsideClick);
        document.removeEventListener('keydown', escapeHandler);
    };
    const cancelColor = function() {
        panel.remove();
        activeColorPickerPanel = null;
        document.removeEventListener('click', closeOnOutsideClick);
        document.removeEventListener('keydown', escapeHandler);
    };
    saveBtn.addEventListener('click', saveColor);
    cancelBtn.addEventListener('click', cancelColor);
    const closeOnOutsideClick = function(e) {
        if (!panel.contains(e.target) && e.target !== element) {
            panel.remove();
            activeColorPickerPanel = null;
            document.removeEventListener('click', closeOnOutsideClick);
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    const escapeHandler = function(e) {
        if (e.key === 'Escape') {
            panel.remove();
            activeColorPickerPanel = null;
            document.removeEventListener('click', closeOnOutsideClick);
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    setTimeout(() => {
        document.addEventListener('click', closeOnOutsideClick);
        document.addEventListener('keydown', escapeHandler);
    }, 100);
    return false;
}
function rgbToHex(rgb) {
    if (!rgb) return '#ffd966';
    const result = rgb.match(/\d+/g);
    if (!result) return '#ffd966';
    return '#' + result.map(x => {
        const hex = parseInt(x).toString(16);
        return hex.length === 1 ? '0' + hex : hex;
    }).join('');
}
function validateGradeRanges() {
    const grades = [];
    document.querySelectorAll('.grade-row').forEach(row => {
        const min = parseInt(row.querySelector(`[name^="min_"]`).value);
        const max = parseInt(row.querySelector(`[name^="max_"]`).value);
        const order = parseInt(row.querySelector(`[name^="order_"]`).value) || 0;
        grades.push({ row, min, max, order });
    });
    grades.sort((a, b) => a.order - b.order);
    let previousMax = -1;
    let isValid = true;
    for (let i = 0; i < grades.length; i++) {
        const grade = grades[i];
        if (grade.min !== previousMax + 1 && previousMax !== -1) {
            grade.row.style.border = '2px solid #ff8a8a';
            isValid = false;
        } else {
            grade.row.style.border = '1px solid #2a3138';
        }
        if (grade.max < grade.min) {
            grade.row.style.border = '2px solid #ff8a8a';
            isValid = false;
        }
        previousMax = grade.max;
    }
    if (grades.length > 0 && grades[grades.length - 1].max !== 10000) {
        grades[grades.length - 1].row.style.border = '2px solid #ff8a8a';
        isValid = false;
    }
    return isValid;
}
function resetCoefficients() {
    if (confirm('Сбросить все коэффициенты к стандартным значениям?')) {
        const standardValues = {
            damage: { coef: 0.30, norm: 3000, min: 0, max: 10000 },
            kills: { coef: 0.15, norm: 2, min: 0, max: 10 },
            assisted: { coef: 0.15, norm: 1500, min: 0, max: 5000 },
            received: { coef: 0.10, norm: 2000, min: 0, max: 5000 },
            survival: { coef: 0.10, norm: 1, min: 0, max: 1 },
            hitRatio: { coef: 0.05, norm: 1, min: 0, max: 1 },
            penRatio: { coef: 0.05, norm: 1, min: 0, max: 1 },
            spots: { coef: 0.05, norm: 2, min: 0, max: 10 },
            winRate: { coef: 0.05, norm: 1, min: 0, max: 1 }
        };
        for (const [param, values] of Object.entries(standardValues)) {
            document.querySelector(`[name="coef_${param}"]`).value = values.coef;
            document.querySelector(`[name="norm_${param}"]`).value = values.norm;
            document.querySelector(`[name="min_${param}"]`).value = values.min;
            document.querySelector(`[name="max_${param}"]`).value = values.max;
        }
        showNotification('Коэффициенты сброшены к стандартным', 'info');
        saveOriginalValues();
    }
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
document.addEventListener('input', function(e) {
    if (e.target.matches('.grade-row input')) {
        validateGradeRanges();
    }
});
function incrementNumber(inputId, step) {
    const input = document.getElementById(inputId);
    if (input) {
        let value = parseFloat(input.value) || 0;
        let newValue = value + step;
        input.value = newValue;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
function decrementNumber(inputId, step) {
    const input = document.getElementById(inputId);
    if (input) {
        let value = parseFloat(input.value) || 0;
        let newValue = value - step;
        input.value = newValue;
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}