const TableUI = {
    getPlayersTableHeaderAnchor(container) {
        return container.querySelector('.players-table-title-row') || container.querySelector('h3');
    },
    updateTeamTabs(showEnemy) {
        const isEn = AppConstants.LANG === 'en';
        const container = document.querySelector('.players-table-container');
        if (!container) return;
        const existingTabs = container.querySelector('.team-tabs');
        if (existingTabs) {
            existingTabs.remove();
        }
        const tabs = document.createElement('div');
        tabs.className = 'team-tabs';
        tabs.innerHTML = `
            <button class="team-tab ${!showEnemy ? 'active' : ''}" data-team="friendly">${isEn ? 'Allied team' : 'Команда союзников'}</button>
            <button class="team-tab ${showEnemy ? 'active' : ''}" data-team="enemy">${isEn ? 'Enemy team' : 'Команда противников'}</button>
        `;
        const anchor = this.getPlayersTableHeaderAnchor(container);
        if (!anchor) return;
        anchor.insertAdjacentElement('afterend', tabs);
        tabs.querySelectorAll('.team-tab').forEach(tab => {
            tab.addEventListener('click', () => {
                const newShowEnemy = tab.dataset.team === 'enemy';
                if (AppState.showEnemyTeam !== newShowEnemy) {
                    AppState.showEnemyTeam = newShowEnemy;
                    AppState.saveSettings();
                    Renderer.updateDisplay();
                }
            });
        });
    },
    updateMapTags() {
        const container = document.querySelector('.players-table-container');
        if (!container) return;
        const isEn = AppConstants.LANG === 'en';
        const existingTags = container.querySelector('.map-tags');
        if (existingTags) {
            existingTags.remove();
        }
        const selectedMap = AppState.currentMap;
        const maps = Array.from(AppState.availableMaps).sort();
        const tagsContainer = document.createElement('div');
        tagsContainer.className = 'map-tags';
        const allMapsTag = document.createElement('span');
        allMapsTag.className = `map-tag ${selectedMap === 'all' ? 'active' : ''}`;
        allMapsTag.textContent = isEn ? 'All maps' : 'Все карты';
        allMapsTag.addEventListener('click', () => {
            if (AppState.currentMap !== 'all') {
                AppState.currentMap = 'all';
                AppState.saveSettings();
                StatsCalculator.recalcStats();
                Renderer.updateDisplay();
            }
        });
        tagsContainer.appendChild(allMapsTag);
        maps.forEach(map => {
            const tag = document.createElement('span');
            tag.className = `map-tag ${selectedMap === map ? 'active' : ''}`;
            tag.textContent = API.getMapDisplayName(map);
            tag.title = map !== API.getMapDisplayName(map) ? (isEn ? `Code: ${map}` : `Код: ${map}`) : '';
            tag.addEventListener('click', () => {
                if (AppState.currentMap !== map) {
                    AppState.currentMap = map;
                    AppState.saveSettings();
                    StatsCalculator.recalcStats();
                    Renderer.updateDisplay();
                }
            });
            tagsContainer.appendChild(tag);
        });
        const anchor = this.getPlayersTableHeaderAnchor(container);
        if (!anchor) return;
        anchor.insertAdjacentElement('afterend', tagsContainer);
    },
    updateSortHeaders(currentSort) {
        document.querySelectorAll('th[data-sort]').forEach(th => {
            th.classList.remove('sorted-asc', 'sorted-desc');
            if (th.dataset.sort === currentSort.column) {
                th.classList.add(`sorted-${currentSort.direction}`);
            }
        });
    },
    updatePlayersTable(players, expandedPlayer) {
        const isEn = AppConstants.LANG === 'en';
        const tbody = document.getElementById('playersTableBody');
        const thead = document.querySelector('#playersTable thead tr');
        const teamTitle = document.querySelector('.players-table-container h3');
        const visibleColumns = AppState.getVisibleColumns();
        const headers = AppConstants.COLUMN_HEADERS;
        const colspan = headers.filter(h => h.always || visibleColumns[h.key]).length;
        if (!tbody || !thead) return;
        if (teamTitle) {
            teamTitle.innerHTML = AppState.showEnemyTeam ? 
                (isEn ? 'Enemy team statistics' : 'Статистика команды противников') : 
                (isEn ? 'Allied team statistics' : 'Статистика команды союзников');
        }
        this.updateMapTags();
        let headerHtml = '';
        headers.forEach(header => {
            if (header.always || visibleColumns[header.key]) {
                let title = header.title;
                if (header.key === 'wgs') {
                    title = `<span class="wgsrt-tooltip" title="${isEn ? 'WGSRT rating shows player efficiency within the loaded replays.' : 'Рейтинг WGSRT показывает эффективность игрока в рамках загруженных реплеев.'}">${header.title}</span>`;
                }
                headerHtml += `<th data-sort="${header.key}">${title}</th>`;
            }
        });
        thead.innerHTML = headerHtml;
        let html = '';
        if (AppState.fileData.length === 0) {
            html = `<tr><td colspan="${colspan}" class="empty-table-message"><div class="empty-table-content"><i class="fas fa-inbox empty-icon"></i><br>${isEn ? 'No replays uploaded' : 'Не загружены реплеи'}</div><\/td><\/tr>`;
        } else {
            const currentTeamPlayers = AppState.showEnemyTeam ? 
                Array.from(AppState.enemyStats.values()) : 
                Array.from(AppState.playersStats.values());
            if (currentTeamPlayers.length === 0) {
                html = `<tr><td colspan="${colspan}" class="empty-table-message"><div class="empty-table-content"><i class="fas fa-users empty-icon"></i><br>${isEn ? 'No players in this team' : 'В этой команде нет игроков'}</div><\/td><\/tr>`;
            } else {
                const minBattles = Math.max(0, parseInt(AppState.userSettings.minBattles, 10) || 0);
                const playersWithMinBattles = players.filter(p => (p.battles || 0) >= minBattles);
                const selectedPlayers = AppState.userSettings.selectedPlayers || { friendly: [], enemy: [] };
                const currentTeam = AppState.showEnemyTeam ? 'enemy' : 'friendly';
                const selectedForCurrentTeam = selectedPlayers[currentTeam] || [];
                if (selectedForCurrentTeam.length === 0) {
                    html = `<tr class="empty-table-row"><td colspan="${colspan}" class="empty-table-message-wrapper">
                        <div class="empty-table-message-centered">
                            <div class="empty-table-message-content">
                                <i class="fas fa-user-slash empty-icon-centered"></i>
                                <div>${isEn ? 'Players are present, but none are selected' : 'Игроки есть, но не выбраны'}</div>
                                <div class="empty-table-hint">${isEn ? 'Select players in the filters above' : 'Выберите игроков в фильтрах выше'}</div>
                            </div>
                        </div>
                     <\/td><\/tr>`;
                } else {
                    const filteredPlayers = playersWithMinBattles.filter(p => selectedForCurrentTeam.includes(p.name));
                    filteredPlayers.forEach(p => {
                        if (!p) return;
                        const isExpanded = expandedPlayer === p.name;
                        const displayName = p.name || (isEn ? 'Unknown' : 'Неизвестно');
                        const rightElements = [];
                        if (p.clan && p.clan.trim() !== '') {
                            rightElements.push(`<span class="player-clan-tag">[${p.clan}]</span>`);
                        }
                        if (p.battleName && p.battleName !== p.name) {
                            rightElements.push(`<i class="fas fa-eye battle-name-indicator" data-battlename="${p.battleName}" title="${isEn ? 'Nick in battle' : 'Ник в бою'}: ${p.battleName}"></i>`);
                        }
                        const rightPart = rightElements.length > 0 ? 
                            `<span class="player-right-elements">${rightElements.join(' ')}</span>` : '';
                        let rowHtml = `<tr class="player-row ${isExpanded ? 'expanded-row' : ''}" data-player="${p.name || ''}">`;
                        headers.forEach(header => {
                            if (header.always || visibleColumns[header.key]) {
                                const value = Utils.getColumnValue(p, header.key);
                                const columnClass = Utils.getColumnClass(header.key, value, p) || '';
                                if (header.key === 'name') {
                                    rowHtml += `
                                        <td>
                                            <span class="expand-icon">▶</span>
                                            <span class="player-name-container">
                                                <span class="player-name-text" title="${displayName}">${displayName}</span>
                                                ${rightPart}
                                            </span>
                                         <\/td>
                                    `;
                                } else {
                                    let displayValue = Utils.formatValue(value, header.key);
                                    if (header.key === 'wgs') {
                                        const wgsValue = parseFloat(displayValue);
                                        const gradeInfo = Utils.getWgsrtGradeInfo(wgsValue);
                                        const color = gradeInfo ? gradeInfo.color : '#ffffff';
                                        const gradeName = gradeInfo
                                            ? (isEn ? (gradeInfo.grade_name_en || gradeInfo.grade_name) : gradeInfo.grade_name)
                                            : '';
                                        const titleText = gradeInfo ? `${gradeName} (${gradeInfo.min_value}-${gradeInfo.max_value})` : `WGSRT: ${displayValue}`;
                                        rowHtml += `<td style="color: ${color}; font-weight: bold;" title="${titleText}">${displayValue}<\/td>`;
                                    } else {
                                        rowHtml += `<td class="${columnClass}" title="${value}">${displayValue}<\/td>`;
                                    }
                                }
                            }
                        });
                        rowHtml += '<\/tr>';
                        html += rowHtml;
                        if (isExpanded && p.name) {
                            const vehicles = AppState.getPlayerVehiclesStats(p.name);
                            html += this.renderVehiclesDetails(vehicles, colspan);
                        }
                    });
                    if (filteredPlayers.length === 0) {
                        const minHint =
                            minBattles > 0
                                ? (() => {
                                      const n = minBattles;
                                      const a = n % 100;
                                      const m = n % 10;
                                      let w = 'боёв';
                                      if (a < 11 || a > 19) {
                                          if (m === 1) w = 'бой';
                                          else if (m >= 2 && m <= 4) w = 'боя';
                                      }
                                      return `<div class="empty-table-hint">${isEn ? `No selected players meet the minimum of ${n} battles. Reduce "Min battles" or change filters.` : `Ни один из выбранных игроков не набирает минимум ${n} ${w} в этой выборке. Уменьшите «Мин. боёв» или измените фильтры.`}</div>`;
                                  })()
                                : `<div class="empty-table-hint">${isEn ? 'Change the selected players in the filters above.' : 'Измените выбор игроков в фильтрах выше.'}</div>`;
                        html = `<tr class="empty-table-row"><td colspan="${colspan}" class="empty-table-message-wrapper">
                            <div class="empty-table-message-centered">
                                <div class="empty-table-message-content">
                                    <i class="fas fa-filter empty-icon-centered"></i>
                                    <div>${isEn ? 'No rows to display' : 'Нет строк для отображения'}</div>
                                    ${minHint}
                                </div>
                            </div>
                         <\/td><\/tr>`;
                    }
                }
            }
        }
        tbody.innerHTML = html;
        tbody.querySelectorAll('.player-row').forEach(row => {
            row.addEventListener('click', (e) => {
                if (e.target.closest('.battle-name-indicator')) {
                    return;
                }
                const playerName = row.dataset.player;
                if (playerName) {
                    Events.togglePlayerExpand(playerName);
                }
            });
        });
        this.updateSortHeaders(AppState.currentSort);
    },
    renderVehiclesDetails(vehicles, colspan) {
        const isEn = AppConstants.LANG === 'en';
        if (!vehicles || vehicles.length === 0) {
            return `
                <tr class="expanded-details">
                    <td colspan="${colspan}" class="empty-table-message">
                        <div class="empty-table-content">
                            <i class="fas fa-tachometer-alt empty-icon-small"></i> ${isEn ? 'No vehicle data' : 'Нет данных по технике'}
                        </div>
                     <\/td>
                 <\/tr>
            `;
        }
        const vehiclesHtml = vehicles.map(v => {
            if (!v) return '';
            const hitRate = v.shots > 0 ? ((v.hits / v.shots) * 100).toFixed(1) : '0.0';
            const penRate = v.hits > 0 ? ((v.penetrations / v.hits) * 100).toFixed(1) : '0.0';
            const hitDisplay = v.shots > 0 ? `${hitRate}% (${v.hits}/${v.shots})` : '0% (0/0)';
            const penDisplay = v.hits > 0 ? `${penRate}% (${v.penetrations}/${v.hits})` : '0% (0/0)';
            const vehicleName = v.vehicle || (isEn ? 'Unknown' : 'Неизвестно');
            const mapCode = v.mapName || '';
            const mapLabel = mapCode ? API.getMapDisplayName(mapCode) : (isEn ? 'Unknown' : 'Неизвестно');
            const mapTitle = mapCode && mapLabel !== mapCode ? `${mapLabel} (${mapCode})` : mapLabel;
            const mapTitleAttr = mapTitle.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
            let resultClass = '';
            let resultText = isEn ? 'Unknown' : 'Неизвестно';
            if (v.isWin === true) {
                resultClass = 'win';
                resultText = isEn ? 'Win' : 'Победа';
            } else if (v.isDraw === true) {
                resultClass = 'draw';
                resultText = isEn ? 'Draw' : 'Ничья';
            } else if (v.isWin === false) {
                resultClass = 'loss';
                resultText = isEn ? 'Defeat' : 'Поражение';
            }
            const mapShort = mapLabel.length > 15 ? mapLabel.substring(0, 12) + '...' : mapLabel;
            return `
                <div class="vehicles-row">
                    <span class="vehicle-name" title="${vehicleName}">${vehicleName.length > 20 ? vehicleName.substring(0, 17) + '...' : vehicleName}</span>
                    <span class="damage-stat">${Utils.formatNumber(v.damage || 0)}</span>
                    <span>${v.kills || 0}</span>
                    <span>${Utils.formatNumber(v.assisted || 0)}</span>
                    <span class="stat-percent" title="${isEn ? ((v.hits || 0) + ' hits out of ' + (v.shots || 0) + ' shots') : ((v.hits || 0) + ' попаданий из ' + (v.shots || 0) + ' выстрелов')}">${hitDisplay}</span>
                    <span class="stat-percent" title="${isEn ? ((v.penetrations || 0) + ' penetrations out of ' + (v.hits || 0) + ' hits') : ((v.penetrations || 0) + ' пробитий из ' + (v.hits || 0) + ' попаданий')}">${penDisplay}</span>
                    <span class="${v.survived ? 'survived' : 'died'}">${isEn ? (v.survived ? 'Survived' : 'Destroyed') : (v.survived ? 'Выжил' : 'Уничтожен')}</span>
                    <span class="${resultClass}">${resultText}</span>
                    <span class="map-name" title="${mapTitleAttr}">${mapShort}</span>
                </div>
            `;
        }).join('');
        const detailColspan = colspan || 9;
        return `
            <tr class="expanded-details">
                <td colspan="${detailColspan}">
                    <div class="vehicles-details">
                        <div class="vehicles-header" style="grid-template-columns: 2fr 1fr 0.8fr 1fr 1.5fr 1.5fr 0.8fr 1.5fr 1.5fr;">
                            <span>${isEn ? 'Tank' : 'Танк'}</span>
                            <span>${isEn ? 'Damage' : 'Урон'}</span>
                            <span>${isEn ? 'Kills' : 'Фраги'}</span>
                            <span>${isEn ? 'Assists' : 'Ассист'}</span>
                            <span>${isEn ? '% hits' : '% попаданий'}</span>
                            <span>${isEn ? '% penetrations' : '% пробитий'}</span>
                            <span>${isEn ? 'Survived' : 'Выжил'}</span>
                            <span>${isEn ? 'Result' : 'Результат'}</span>
                            <span>${isEn ? 'Map' : 'Карта'}</span>
                        </div>
                        <div class="vehicles-rows">
                            ${vehiclesHtml}
                        </div>
                    </div>
                 <\/td>
             <\/tr>
        `;
    }
};