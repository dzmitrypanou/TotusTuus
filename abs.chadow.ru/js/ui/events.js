const Events = {
    initEventListeners() {
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const resetBtn = document.getElementById('resetBtn');
        const downloadBtn = document.getElementById('downloadBtn');
        const saveReplayConsent = document.getElementById('saveReplayConsent');

        if (uploadArea && fileInput) {
            uploadArea.addEventListener('click', () => fileInput.click());
            
            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.classList.add('drag-over');
            });

            uploadArea.addEventListener('dragleave', () => {
                uploadArea.classList.remove('drag-over');
            });

            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.classList.remove('drag-over');
                const files = Array.from(e.dataTransfer.files);
                if (files.length > 0 && typeof FileHandler !== 'undefined') {
                    FileHandler.processFiles(files);
                }
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', (e) => {
                const files = Array.from(e.target.files);
                if (files.length > 0 && typeof FileHandler !== 'undefined') {
                    FileHandler.processFiles(files);
                }
                fileInput.value = '';
            });
        }

        if (resetBtn) {
            resetBtn.addEventListener('click', () => {
                const isEn = AppConstants.LANG === 'en';
                if (confirm(isEn ? 'Clear all uploaded data?' : 'Очистить все загруженные данные?') && typeof FileHandler !== 'undefined') {
                    AppState.resetAllData();
                    StatsCalculator.recalcStats(false);
                    FiltersUI.renderFilters();
                    Renderer.updateDisplay();
                    UI.updateFileInfo('');
                    UI.checkAndHideContent();
                }
            });
        }

        if (downloadBtn) {
            downloadBtn.addEventListener('click', () => {
                const players = AppState.showEnemyTeam ? 
                    Array.from(AppState.enemyStats.values()) : 
                    Array.from(AppState.playersStats.values());
                if (typeof Utils !== 'undefined') {
                    Utils.downloadAsJPEG();
                }
            });
        }

        if (saveReplayConsent) {
            saveReplayConsent.checked = AppState.userSettings.saveReplayConsent === true;
            saveReplayConsent.addEventListener('change', (e) => {
                AppState.userSettings.saveReplayConsent = e.target.checked;
                AppState.saveSettings();
            });
        }

        const minBattlesInput = document.getElementById('minBattlesInput');
        if (minBattlesInput) {
            const applyMinBattles = () => {
                let v = parseInt(minBattlesInput.value, 10);
                if (Number.isNaN(v) || v < 0) v = 0;
                AppState.userSettings.minBattles = v;
                minBattlesInput.value = String(v);
                AppState.saveSettings();
                if (typeof Renderer !== 'undefined') {
                    Renderer.updateDisplay();
                }
            };
            const stepMinBattles = (delta) => {
                let v = parseInt(minBattlesInput.value, 10);
                if (Number.isNaN(v)) v = 0;
                v = Math.max(0, v + delta);
                minBattlesInput.value = String(v);
                applyMinBattles();
            };
            minBattlesInput.value = String(
                Math.max(0, parseInt(AppState.userSettings.minBattles, 10) || 0)
            );
            minBattlesInput.addEventListener('change', applyMinBattles);
            minBattlesInput.addEventListener('input', applyMinBattles);
            const minBattlesUp = document.getElementById('minBattlesUp');
            const minBattlesDown = document.getElementById('minBattlesDown');
            if (minBattlesUp) {
                minBattlesUp.addEventListener('click', () => stepMinBattles(1));
            }
            if (minBattlesDown) {
                minBattlesDown.addEventListener('click', () => stepMinBattles(-1));
            }
        }

        document.addEventListener('click', (e) => {
            const th = e.target.closest('th[data-sort]');
            if (!th) return;
            
            const column = th.dataset.sort;
            if (AppState.currentSort.column === column) {
                AppState.currentSort.direction = AppState.currentSort.direction === 'asc' ? 'desc' : 'asc';
            } else {
                AppState.currentSort.column = column;
                AppState.currentSort.direction = 'desc';
            }
            
            AppState.saveSettings();
            Renderer.updateDisplay();
        });
    },

    handleFilterClick(e) {
        const header = e.currentTarget;
        const section = header.dataset.section;
        const content = header.nextElementSibling;
        const icon = header.querySelector('.filter-expand-icon');
        
        if (!content) return;
        
        if (content.style.display === 'none' || !content.style.display) {
            content.style.display = 'block';
            header.classList.add('expanded');
            if (icon) icon.style.transform = 'rotate(90deg)';
            AppState.userSettings.expandedSections = AppState.userSettings.expandedSections || {};
            AppState.userSettings.expandedSections[section] = true;
        } else {
            content.style.display = 'none';
            header.classList.remove('expanded');
            if (icon) icon.style.transform = 'rotate(0deg)';
            AppState.userSettings.expandedSections = AppState.userSettings.expandedSections || {};
            AppState.userSettings.expandedSections[section] = false;
        }
        
        AppState.saveSettings();
    },

    handleFilesListClick(e) {
        const header = e.currentTarget;
        const section = header.dataset.section;
        const content = header.nextElementSibling;
        const icon = header.querySelector('.filter-expand-icon');
        
        if (content.style.display === 'none' || !content.style.display) {
            content.style.display = 'block';
            header.classList.add('expanded');
            if (icon) icon.style.transform = 'rotate(90deg)';
            AppState.userSettings.expandedSections = AppState.userSettings.expandedSections || {};
            AppState.userSettings.expandedSections[section] = true;
        } else {
            content.style.display = 'none';
            header.classList.remove('expanded');
            if (icon) icon.style.transform = 'rotate(0deg)';
            AppState.userSettings.expandedSections = AppState.userSettings.expandedSections || {};
            AppState.userSettings.expandedSections[section] = false;
        }
        
        AppState.saveSettings();
    },

    handleSortChange(e) {
        AppState.currentSort.column = e.target.value;
        AppState.saveSettings();
        Renderer.updateDisplay();
    },

    handleDirectionChange(e) {
        AppState.currentSort.direction = e.target.value;
        AppState.saveSettings();
        Renderer.updateDisplay();
    },

    handleMapChange(e) {
        AppState.currentMap = e.target.value;
        AppState.saveSettings();
        if (typeof StatsCalculator !== 'undefined') {
            StatsCalculator.recalcStats(false);
        }
        FiltersUI.renderFilters();
        Renderer.updateDisplay();
    },
    
    handleColumnChange() {
        const visibleColumns = {};
        document.querySelectorAll('.column-checkbox-input').forEach(c => {
            visibleColumns[c.dataset.column] = c.checked;
        });
        AppState.userSettings.visibleColumns = visibleColumns;
        AppState.saveSettings();
        Renderer.updateDisplay();
    },

    togglePlayerExpand(playerName) {
        if (AppState.expandedPlayer === playerName) {
            AppState.expandedPlayer = null;
        } else {
            AppState.expandedPlayer = playerName;
        }
        Renderer.updateDisplay();
    }
};