document.addEventListener('DOMContentLoaded', async () => {
    UI.showLoading();
    try {
        await Promise.all([
            API.loadTankDictionary(),
            API.loadMapDictionary(),
            API.loadWgsrtCoefficients(),
            API.loadWgsrtGrades(),
            AppState.loadSettings()
        ]);
        Events.initEventListeners();
        FiltersUI.renderFilters();
        UI.checkAndHideContent();
        UI.addWgsrtLegend();
    } catch (error) {
        console.error('Initialization error:', error);
        Events.initEventListeners();
        FiltersUI.renderFilters();
        UI.checkAndHideContent();
    } finally {
        UI.hideLoading();
    }
});
const UI = {
    showLoading() {
        const isEn = AppConstants.LANG === 'en';
        document.getElementById('loading').classList.remove('hidden');
        document.getElementById('loading').innerHTML = `
            <div class="loading-content">
                <div class="spinner"></div>
                <span class="loading-text">${isEn ? 'Loading' : 'Загрузка'}</span>
            </div>
        `;
        document.getElementById('content').classList.add('hidden');
    },
    hideLoading() {
        document.getElementById('loading').classList.add('hidden');
        this.checkAndHideContent();
    },
    addWgsrtLegend() {
        const grades = API.cache.wgsrtGrades;
        if (!grades || grades.length === 0) return;
        const container = document.getElementById('filtersContainer');
        if (!container) return;
        if (document.querySelector('.wgsrt-legend')) return;
        const legend = document.createElement('div');
        legend.className = 'wgsrt-legend';
        const isEn = AppConstants.LANG === 'en';
        grades.forEach(grade => {
            const item = document.createElement('div');
            item.className = 'wgsrt-legend-item';
            const gradeName = isEn ? (grade.grade_name_en || grade.grade_name) : grade.grade_name;
            const gradeDesc = isEn ? (grade.description_en || grade.description || '') : (grade.description || '');
            item.title = `${gradeName}: ${grade.min_value}-${grade.max_value} - ${gradeDesc}`;
            item.innerHTML = `
                <div class="wgsrt-legend-color" style="background: ${grade.color};"></div>
                <span class="wgsrt-legend-text">${gradeName}</span>
                <span class="wgsrt-legend-value">(${grade.min_value}-${grade.max_value})</span>
            `;
            legend.appendChild(item);
        });
        const filtersPanel = document.querySelector('.filters-panel');
        if (filtersPanel) {
            filtersPanel.parentNode.insertBefore(legend, filtersPanel);
        } else {
            container.appendChild(legend);
        }
    },
    showNotifications(notifications) {
        const container = document.createElement('div');
        container.className = 'notifications-container';
        notifications.forEach(notif => {
            const notifElement = document.createElement('div');
            notifElement.className = `notification-banner priority-${notif.priority}`;
            notifElement.innerHTML = `
                <div class="notification-banner-title">${notif.title}</div>
                <div class="notification-banner-content">${notif.content}</div>
            `;
            container.appendChild(notifElement);
        });
        document.body.appendChild(container);
        setTimeout(() => container.remove(), 10000);
    },
    updateFileInfo(info) {
        document.getElementById('fileInfo').textContent = info;
    },
    checkAndHideContent() {
        const content = document.getElementById('content');
        const filtersContainer = document.getElementById('filtersContainer');
        const playersTableContainer = document.querySelector('.players-table-container');
        const actionButtons = document.querySelector('.action-buttons');
        if (AppState.fileData.length > 0) {
            content.classList.remove('hidden');
            if (filtersContainer) filtersContainer.style.display = 'block';
            if (playersTableContainer) playersTableContainer.style.display = 'block';
            if (actionButtons) actionButtons.style.display = 'flex';
        } else {
            content.classList.add('hidden');
            const errorContainer = document.getElementById('errorContainer');
            const filesList = document.getElementById('filesList');
            if (errorContainer?.style.display !== 'none' && errorContainer?.children.length > 1) {
                content.classList.remove('hidden');
                if (filtersContainer) filtersContainer.style.display = 'none';
                if (playersTableContainer) playersTableContainer.style.display = 'none';
                if (actionButtons) actionButtons.style.display = 'none';
                if (filesList) filesList.style.display = 'none';
            }
        }
        const isEn = AppConstants.LANG === 'en';
        this.updateFileInfo(AppState.fileData.length ? `${isEn ? 'Files' : 'Файлов'}: ${AppState.fileData.length}` : '');
        const filesList = document.getElementById('filesList');
        if (filesList) {
            filesList.style.display = AppState.fileData.length ? 'block' : 'none';
        }
    }
};