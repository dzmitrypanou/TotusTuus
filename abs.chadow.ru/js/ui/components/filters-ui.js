const FiltersUI = {
    renderFilters() {
        const container = document.getElementById('filtersContainer');
        if (!container) return;
        const isEn = AppConstants.LANG === 'en';
        
        const savedColumns = AppState.getVisibleColumns();
        
        const allPlayers = StatsCalculator.getAllPlayers();
        const friendlyPlayers = allPlayers.friendly
            .map(p => ({ name: p.name, clan: p.clan, battles: p.battles }))
            .sort((a, b) => a.name.localeCompare(b.name));
        
        const enemyPlayers = allPlayers.enemy
            .map(p => ({ name: p.name, clan: p.clan, battles: p.battles }))
            .sort((a, b) => a.name.localeCompare(b.name));
        
        const selectedFriendly = AppState.userSettings.selectedPlayers?.friendly || [];
        const selectedEnemy = AppState.userSettings.selectedPlayers?.enemy || [];
        
        const showOnlySelectedFriendly = AppState.userSettings.showOnlySelectedFriendly || false;
        const showOnlySelectedEnemy = AppState.userSettings.showOnlySelectedEnemy || false;
        
        container.innerHTML = `
            <div class="filters-panel">
                <div class="filter-section">
                    <div class="filter-header" data-section="filters">
                        <span class="filter-expand-icon">▶</span>
                        <span>${isEn ? 'Filters' : 'Фильтры'}</span>
                    </div>
                    <div class="filter-content" style="display: none;">
                        <div class="filter-row">
                            <div class="filter-group players-filter-group">
                                <div class="players-header">
                                    <label class="filter-label">${isEn ? 'Allied team:' : 'Команда союзников:'}</label>
                                    <div class="players-header-right">
                                        <span class="players-counter badge filter-badge" id="friendlyCounter">
                                            ${selectedFriendly.length}/${friendlyPlayers.length}
                                        </span>
                                        <button class="players-show-btn ${showOnlySelectedFriendly ? 'active' : ''}" id="showSelectedFriendly" title="${isEn ? 'Show only selected' : 'Показать только выбранных'}">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="players-search">
                                    <input type="text" class="players-search-input" id="friendlySearch" placeholder="${isEn ? 'Search player...' : 'Поиск игрока...'}">
                                </div>
                                <div class="players-list-container">
                                    <div class="players-list-header">
                                        <span class="players-list-title">${isEn ? 'Players' : 'Игроки'}</span>
                                        <span class="players-list-actions">
                                            <button class="players-action-btn" id="selectAllFriendly">${isEn ? 'Select all' : 'Выбрать всех'}</button>
                                            <button class="players-action-btn" id="clearAllFriendly">${isEn ? 'Clear' : 'Очистить'}</button>
                                        </span>
                                    </div>
                                    <div class="players-list" id="friendlyPlayersList">
                                        ${this.renderPlayerCheckboxes(friendlyPlayers, 'friendly', selectedFriendly, showOnlySelectedFriendly)}
                                    </div>
                                    <div class="search-no-results" id="friendlyNoResults" style="display: none;">
                                        <i class="fas fa-search"></i> ${isEn ? 'No such player' : 'Такого игрока нет'}
                                    </div>
                                </div>
                            </div>
                            
                            <div class="filter-group players-filter-group">
                                <div class="players-header">
                                    <label class="filter-label">${isEn ? 'Enemy team:' : 'Команда противников:'}</label>
                                    <div class="players-header-right">
                                        <span class="players-counter badge filter-badge" id="enemyCounter">
                                            ${selectedEnemy.length}/${enemyPlayers.length}
                                        </span>
                                        <button class="players-show-btn ${showOnlySelectedEnemy ? 'active' : ''}" id="showSelectedEnemy" title="${isEn ? 'Show only selected' : 'Показать только выбранных'}">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="players-search">
                                    <input type="text" class="players-search-input" id="enemySearch" placeholder="${isEn ? 'Search player...' : 'Поиск игрока...'}">
                                </div>
                                <div class="players-list-container">
                                    <div class="players-list-header">
                                        <span class="players-list-title">${isEn ? 'Players' : 'Игроки'}</span>
                                        <span class="players-list-actions">
                                            <button class="players-action-btn" id="selectAllEnemy">${isEn ? 'Select all' : 'Выбрать всех'}</button>
                                            <button class="players-action-btn" id="clearAllEnemy">${isEn ? 'Clear' : 'Очистить'}</button>
                                        </span>
                                    </div>
                                    <div class="players-list" id="enemyPlayersList">
                                        ${this.renderPlayerCheckboxes(enemyPlayers, 'enemy', selectedEnemy, showOnlySelectedEnemy)}
                                    </div>
                                    <div class="search-no-results" id="enemyNoResults" style="display: none;">
                                        <i class="fas fa-search"></i> ${isEn ? 'No such player' : 'Такого игрока нет'}
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="filter-row">
                            <div class="filter-group columns-group">
                                <label class="filter-label">${isEn ? 'Visible columns:' : 'Отображаемые столбцы:'}</label>
                                <div class="columns-filter">
                                    <div class="columns-section">
                                        <div class="columns-grid">
                                            ${this.renderColumnCheckboxes(savedColumns)}
                                        </div>
                                    </div>
                                    <div class="columns-actions">
                                        <button class="columns-action-btn" id="selectAllColumns">${isEn ? 'Select all' : 'Заполнить все'}</button>
                                        <button class="columns-action-btn" id="setDefaultColumns">${isEn ? 'Default settings' : 'Базовые настройки'}</button>
                                        <button class="columns-action-btn" id="resetColumnsParams">${isEn ? 'Reset' : 'Очистить параметры'}</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        this.attachFilterEventHandlers();
        this.attachSearchHandlers();
        this.attachColumnsActionHandlers();
        this.attachPlayersHeaderHandlers();
        this.updateSelectedCounts();
    },

    renderPlayerCheckboxes(players, team, selected, showOnlySelected) {
        const isEn = AppConstants.LANG === 'en';
        if (players.length === 0) {
            return `
                <div class="no-players-message">
                    ${isEn ? 'No players in this team' : 'Нет игроков в этой команде'}
                </div>
            `;
        }
        
        let playersToShow = players;
        if (showOnlySelected) {
            playersToShow = players.filter(p => selected.includes(p.name));
        }
        
        if (playersToShow.length === 0) {
            return `
                <div class="no-players-message">
                    ${isEn ? 'No selected players' : 'Нет выбранных игроков'}
                </div>
            `;
        }
        
        return playersToShow.map(player => `
            <label class="player-checkbox-label">
                <input type="checkbox" class="player-checkbox" data-team="${team}" data-player="${player.name}" ${selected.includes(player.name) ? 'checked' : ''}>
                <span class="player-checkbox-name">${player.name}</span>
                ${player.clan ? `<span class="player-checkbox-clan">[${player.clan}]</span>` : ''}
                <span class="player-checkbox-battles battles-badge">${player.battles}</span>
            </label>
        `).join('');
    },

    getSortDisplayName(sortKey) {
        const col = (AppConstants.COLUMN_HEADERS || []).find(c => c.key === sortKey);
        if (col && col.title) return col.title;
        return AppConstants.LANG === 'en' ? 'Avg damage' : 'Ср. урон';
    },

    renderColumnCheckboxes(savedColumns) {
        const columns = AppConstants.COLUMN_HEADERS || [];

        return columns.map(col => `
            <div class="column-checkbox">
                <label class="checkbox-label">
                    <input type="checkbox" class="column-checkbox-input" data-column="${col.key}" ${savedColumns[col.key] ? 'checked' : ''}>
                    <span class="checkbox-text">${col.title}</span>
                </label>
            </div>
        `).join('');
    },

    attachFilterEventHandlers() {
        const filterHeaders = document.querySelectorAll('.filter-header');
        filterHeaders.forEach(header => {
            header.removeEventListener('click', Events.handleFilterClick);
            header.addEventListener('click', Events.handleFilterClick);
        });
        
        document.querySelectorAll('.column-checkbox-input').forEach(cb => {
            cb.removeEventListener('change', Events.handleColumnChange);
            cb.addEventListener('change', Events.handleColumnChange);
        });

        document.querySelectorAll('.player-checkbox').forEach(cb => {
            cb.addEventListener('change', (e) => {
                const team = e.target.dataset.team;
                const player = e.target.dataset.player;
                
                if (!AppState.userSettings.selectedPlayers) {
                    AppState.userSettings.selectedPlayers = { friendly: [], enemy: [] };
                }
                
                const key = team === 'friendly' ? 'friendly' : 'enemy';
                
                if (e.target.checked) {
                    if (!AppState.userSettings.selectedPlayers[key].includes(player)) {
                        AppState.userSettings.selectedPlayers[key].push(player);
                    }
                } else {
                    AppState.userSettings.selectedPlayers[key] = 
                        AppState.userSettings.selectedPlayers[key].filter(p => p !== player);
                }
                
                AppState.saveSettings();
                this.updateSelectedCounts();
                Renderer.updateDisplay();
                this.refreshPlayersLists();
            });
        });

        const selectAllFriendly = document.getElementById('selectAllFriendly');
        if (selectAllFriendly) {
            selectAllFriendly.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#friendlyPlayersList .player-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = true;
                });
                checkboxes.forEach(cb => {
                    const event = new Event('change', { bubbles: true });
                    cb.dispatchEvent(event);
                });
            });
        }

        const clearAllFriendly = document.getElementById('clearAllFriendly');
        if (clearAllFriendly) {
            clearAllFriendly.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#friendlyPlayersList .player-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });
                checkboxes.forEach(cb => {
                    const event = new Event('change', { bubbles: true });
                    cb.dispatchEvent(event);
                });
            });
        }

        const selectAllEnemy = document.getElementById('selectAllEnemy');
        if (selectAllEnemy) {
            selectAllEnemy.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#enemyPlayersList .player-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = true;
                });
                checkboxes.forEach(cb => {
                    const event = new Event('change', { bubbles: true });
                    cb.dispatchEvent(event);
                });
            });
        }

        const clearAllEnemy = document.getElementById('clearAllEnemy');
        if (clearAllEnemy) {
            clearAllEnemy.addEventListener('click', () => {
                const checkboxes = document.querySelectorAll('#enemyPlayersList .player-checkbox');
                checkboxes.forEach(cb => {
                    cb.checked = false;
                });
                checkboxes.forEach(cb => {
                    const event = new Event('change', { bubbles: true });
                    cb.dispatchEvent(event);
                });
            });
        }
    },

    attachSearchHandlers() {
        const friendlySearch = document.getElementById('friendlySearch');
        const friendlyList = document.getElementById('friendlyPlayersList');
        const friendlyNoResults = document.getElementById('friendlyNoResults');
        
        if (friendlySearch && friendlyList && friendlyNoResults) {
            friendlySearch.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase().trim();
                const checkboxes = friendlyList.querySelectorAll('.player-checkbox-label');
                let visibleCount = 0;
                
                checkboxes.forEach(label => {
                    const name = label.querySelector('.player-checkbox-name').textContent.toLowerCase();
                    if (name.includes(searchTerm) || searchTerm === '') {
                        label.style.display = 'flex';
                        visibleCount++;
                    } else {
                        label.style.display = 'none';
                    }
                });
                
                if (visibleCount === 0 && searchTerm !== '') {
                    friendlyNoResults.style.display = 'flex';
                } else {
                    friendlyNoResults.style.display = 'none';
                }
            });
        }

        const enemySearch = document.getElementById('enemySearch');
        const enemyList = document.getElementById('enemyPlayersList');
        const enemyNoResults = document.getElementById('enemyNoResults');
        
        if (enemySearch && enemyList && enemyNoResults) {
            enemySearch.addEventListener('input', (e) => {
                const searchTerm = e.target.value.toLowerCase().trim();
                const checkboxes = enemyList.querySelectorAll('.player-checkbox-label');
                let visibleCount = 0;
                
                checkboxes.forEach(label => {
                    const name = label.querySelector('.player-checkbox-name').textContent.toLowerCase();
                    if (name.includes(searchTerm) || searchTerm === '') {
                        label.style.display = 'flex';
                        visibleCount++;
                    } else {
                        label.style.display = 'none';
                    }
                });
                
                if (visibleCount === 0 && searchTerm !== '') {
                    enemyNoResults.style.display = 'flex';
                } else {
                    enemyNoResults.style.display = 'none';
                }
            });
        }
    },

    attachColumnsActionHandlers() {
        const selectAllColumns = document.getElementById('selectAllColumns');
        if (selectAllColumns) {
            selectAllColumns.addEventListener('click', () => {
                document.querySelectorAll('.column-checkbox-input').forEach(cb => {
                    cb.checked = true;
                    cb.dispatchEvent(new Event('change'));
                });
            });
        }

        const setDefaultColumns = document.getElementById('setDefaultColumns');
        if (setDefaultColumns) {
            setDefaultColumns.addEventListener('click', () => {
                const defaults = AppConstants.DEFAULT_VISIBLE_COLUMNS;
                document.querySelectorAll('.column-checkbox-input').forEach(cb => {
                    const column = cb.dataset.column;
                    cb.checked = defaults[column] || false;
                    cb.dispatchEvent(new Event('change'));
                });
            });
        }

        const resetColumnsParams = document.getElementById('resetColumnsParams');
        if (resetColumnsParams) {
            resetColumnsParams.addEventListener('click', () => {
                document.querySelectorAll('.column-checkbox-input').forEach(cb => {
                    cb.checked = false;
                    cb.dispatchEvent(new Event('change'));
                });
            });
        }
    },

    attachPlayersHeaderHandlers() {
        const showSelectedFriendly = document.getElementById('showSelectedFriendly');
        if (showSelectedFriendly) {
            showSelectedFriendly.addEventListener('click', () => {
                AppState.userSettings.showOnlySelectedFriendly = !AppState.userSettings.showOnlySelectedFriendly;
                AppState.saveSettings();
                this.refreshPlayersLists();
            });
        }

        const showSelectedEnemy = document.getElementById('showSelectedEnemy');
        if (showSelectedEnemy) {
            showSelectedEnemy.addEventListener('click', () => {
                AppState.userSettings.showOnlySelectedEnemy = !AppState.userSettings.showOnlySelectedEnemy;
                AppState.saveSettings();
                this.refreshPlayersLists();
            });
        }
    },

    refreshPlayersLists() {
        const friendlyList = document.getElementById('friendlyPlayersList');
        const enemyList = document.getElementById('enemyPlayersList');
        
        const allPlayers = StatsCalculator.getAllPlayers();
        const friendlyPlayers = allPlayers.friendly
            .map(p => ({ name: p.name, clan: p.clan, battles: p.battles }))
            .sort((a, b) => a.name.localeCompare(b.name));
        
        const enemyPlayers = allPlayers.enemy
            .map(p => ({ name: p.name, clan: p.clan, battles: p.battles }))
            .sort((a, b) => a.name.localeCompare(b.name));
        
        if (friendlyList) {
            const selectedFriendly = AppState.userSettings.selectedPlayers?.friendly || [];
            const showOnlySelectedFriendly = AppState.userSettings.showOnlySelectedFriendly || false;
            
            const validSelectedFriendly = selectedFriendly.filter(name => 
                friendlyPlayers.some(p => p.name === name)
            );
            
            if (validSelectedFriendly.length !== selectedFriendly.length) {
                AppState.userSettings.selectedPlayers.friendly = validSelectedFriendly;
                AppState.saveSettings();
            }
            
            friendlyList.innerHTML = this.renderPlayerCheckboxes(friendlyPlayers, 'friendly', validSelectedFriendly, showOnlySelectedFriendly);
            
            document.querySelectorAll('#friendlyPlayersList .player-checkbox').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const team = e.target.dataset.team;
                    const player = e.target.dataset.player;
                    
                    if (!AppState.userSettings.selectedPlayers) {
                        AppState.userSettings.selectedPlayers = { friendly: [], enemy: [] };
                    }
                    
                    const key = team === 'friendly' ? 'friendly' : 'enemy';
                    
                    if (e.target.checked) {
                        if (!AppState.userSettings.selectedPlayers[key].includes(player)) {
                            AppState.userSettings.selectedPlayers[key].push(player);
                        }
                    } else {
                        AppState.userSettings.selectedPlayers[key] = 
                            AppState.userSettings.selectedPlayers[key].filter(p => p !== player);
                    }
                    
                    AppState.saveSettings();
                    this.updateSelectedCounts();
                    Renderer.updateDisplay();
                });
            });
        }
        
        if (enemyList) {
            const selectedEnemy = AppState.userSettings.selectedPlayers?.enemy || [];
            const showOnlySelectedEnemy = AppState.userSettings.showOnlySelectedEnemy || false;
            
            const validSelectedEnemy = selectedEnemy.filter(name => 
                enemyPlayers.some(p => p.name === name)
            );
            
            if (validSelectedEnemy.length !== selectedEnemy.length) {
                AppState.userSettings.selectedPlayers.enemy = validSelectedEnemy;
                AppState.saveSettings();
            }
            
            enemyList.innerHTML = this.renderPlayerCheckboxes(enemyPlayers, 'enemy', validSelectedEnemy, showOnlySelectedEnemy);
            
            document.querySelectorAll('#enemyPlayersList .player-checkbox').forEach(cb => {
                cb.addEventListener('change', (e) => {
                    const team = e.target.dataset.team;
                    const player = e.target.dataset.player;
                    
                    if (!AppState.userSettings.selectedPlayers) {
                        AppState.userSettings.selectedPlayers = { friendly: [], enemy: [] };
                    }
                    
                    const key = team === 'friendly' ? 'friendly' : 'enemy';
                    
                    if (e.target.checked) {
                        if (!AppState.userSettings.selectedPlayers[key].includes(player)) {
                            AppState.userSettings.selectedPlayers[key].push(player);
                        }
                    } else {
                        AppState.userSettings.selectedPlayers[key] = 
                            AppState.userSettings.selectedPlayers[key].filter(p => p !== player);
                    }
                    
                    AppState.saveSettings();
                    this.updateSelectedCounts();
                    Renderer.updateDisplay();
                });
            });
        }
        
        this.updateSelectedCounts();
        
        const friendlySearch = document.getElementById('friendlySearch');
        const enemySearch = document.getElementById('enemySearch');
        const friendlyNoResults = document.getElementById('friendlyNoResults');
        const enemyNoResults = document.getElementById('enemyNoResults');
        
        if (friendlySearch) friendlySearch.value = '';
        if (enemySearch) enemySearch.value = '';
        if (friendlyNoResults) friendlyNoResults.style.display = 'none';
        if (enemyNoResults) enemyNoResults.style.display = 'none';
    },

    updateSelectedCounts() {
        const friendlyCounter = document.getElementById('friendlyCounter');
        const enemyCounter = document.getElementById('enemyCounter');
        
        const allPlayers = StatsCalculator.getAllPlayers();
        const friendlyTotal = allPlayers.friendly.length;
        const enemyTotal = allPlayers.enemy.length;
        
        if (friendlyCounter) {
            const selectedFriendly = AppState.userSettings.selectedPlayers?.friendly?.length || 0;
            friendlyCounter.textContent = `${selectedFriendly}/${friendlyTotal}`;
        }
        
        if (enemyCounter) {
            const selectedEnemy = AppState.userSettings.selectedPlayers?.enemy?.length || 0;
            enemyCounter.textContent = `${selectedEnemy}/${enemyTotal}`;
        }
        
        const showSelectedFriendly = document.getElementById('showSelectedFriendly');
        const showSelectedEnemy = document.getElementById('showSelectedEnemy');
        
        if (showSelectedFriendly) {
            if (AppState.userSettings.showOnlySelectedFriendly) {
                showSelectedFriendly.classList.add('active');
            } else {
                showSelectedFriendly.classList.remove('active');
            }
        }
        
        if (showSelectedEnemy) {
            if (AppState.userSettings.showOnlySelectedEnemy) {
                showSelectedEnemy.classList.add('active');
            } else {
                showSelectedEnemy.classList.remove('active');
            }
        }
    }
};