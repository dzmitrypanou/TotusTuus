const Utils = {
    formatNumber(num) {
        return new Intl.NumberFormat('ru-RU').format(num);
    },
    generateFileHash(file) {
        return `${file.name}_${file.size}_${file.lastModified}`;
    },
    calculateWGS(player) {
        if (player.battles === 0) return 0;
        const coefs = API.getWgsrtCoefficients();
        const avgDamage = player.totalDamage / player.battles;
        const avgKills = player.totalKills / player.battles;
        const avgAssisted = player.totalAssisted / player.battles;
        const avgReceived = player.totalReceived / player.battles;
        const survivalRate = player.totalSurvived / player.battles;
        const hitRatio = player.totalShots > 0 ? player.totalHits / player.totalShots : 0;
        const penRatio = player.totalHits > 0 ? player.totalPenetrations / player.totalHits : 0;
        const avgSpots = player.totalSpots / player.battles;
        const winRate = player.battles > 0 ? player.wins / player.battles : 0;
        const normDamage = Math.min(avgDamage / (coefs.damage?.norm || 3000), 1);
        const normKills = Math.min(avgKills / (coefs.kills?.norm || 2), 1);
        const normAssisted = Math.min(avgAssisted / (coefs.assisted?.norm || 1500), 1);
        const normReceived = Math.min(avgReceived / (coefs.received?.norm || 2000), 1);
        const normSpots = Math.min(avgSpots / (coefs.spots?.norm || 2), 1);
        const weights = {
            damage: coefs.damage?.value || 0.30,
            kills: coefs.kills?.value || 0.15,
            assisted: coefs.assisted?.value || 0.15,
            received: coefs.received?.value || 0.10,
            survival: coefs.survival?.value || 0.10,
            hitRatio: coefs.hitRatio?.value || 0.05,
            penRatio: coefs.penRatio?.value || 0.05,
            spots: coefs.spots?.value || 0.05,
            winRate: coefs.winRate?.value || 0.05
        };
        const weightedSum = 
            normDamage * weights.damage +
            normKills * weights.kills +
            normAssisted * weights.assisted +
            normReceived * weights.received +
            survivalRate * weights.survival +
            hitRatio * weights.hitRatio +
            penRatio * weights.penRatio +
            normSpots * weights.spots +
            winRate * weights.winRate;
        const wgsrt = weightedSum * 2000 * 5;
        return isNaN(wgsrt) ? 0 : Math.min(wgsrt, 10000);
    },
    getWgsrtGradeInfo(wgsrtValue) {
        return API.getWgsrtGrade(wgsrtValue);
    },
    getWgsrtColor(wgsrtValue) {
        return API.getWgsrtColor(wgsrtValue);
    },
    getWgsrtClass(wgsrtValue) {
        return API.getWgsrtGradeClass(wgsrtValue);
    },
    getWinRateColor(winRate) {
        const rate = parseFloat(winRate);
        if (isNaN(rate)) return '';
        if (rate === 0) return 'stat-winrate-0';
        if (rate < 35) return 'stat-winrate-35';
        if (rate < 50) return 'stat-winrate-50';
        if (rate < 55) return 'stat-winrate-55';
        if (rate < 60) return 'stat-winrate-60';
        if (rate < 65) return 'stat-winrate-65';
        return 'stat-winrate-65plus';
    },
    getPlayerProfileUrl(playerName, playerInfo, serverRegion) {
        const info = playerInfo || {};
        const playerId = info.playerId || info.accountId;
        const baseUrl = 'https://tanki.su/ru/community/accounts/';
        if (playerId) {
            return `${baseUrl}${playerId}/`;
        }
        const encodedName = encodeURIComponent(playerName);
        return `${baseUrl}${encodedName}/`;
    },
    getColumnClass(columnKey, value, player) {
        if (columnKey === 'wgs') {
            const wgsValue = parseFloat(value);
            if (!isNaN(wgsValue)) {
                const gradeClass = API.getWgsrtGradeClass(wgsValue);
                if (gradeClass) {
                    return gradeClass;
                }
            }
        }
        switch(columnKey) {
            case 'damage':
                return 'stat-highlight';
            case 'survival':
                const rate = parseFloat(value);
                return rate >= 50 ? 'survival-good' : 'survival-bad';
            case 'hitRatio':
            case 'penetrationRatio':
                return 'stat-percent';
            case 'wins':
                return 'stat-win';
            case 'losses':
                return 'stat-loss';
            case 'winRate':
                return this.getWinRateColor(value);
            default:
                return '';
        }
    },
    formatValue(value, columnKey) {
        if (typeof value === 'number') {
            if (columnKey.includes('Damage') || columnKey.includes('Received') || 
                columnKey === 'xp' || columnKey === 'avgXp' || columnKey === 'damage') {
                return this.formatNumber(value);
            }
            return value;
        }
        return value;
    },
    getColumnValue(player, columnKey) {
        if (!player) return '';
        const isEn = AppConstants.LANG === 'en';
        switch(columnKey) {
            case 'name':
                return player.name || (isEn ? 'Unknown' : 'Неизвестно');
            case 'battles':
                return player.battles || 0;
            case 'damage':
                return player.battles > 0 ? Math.round(player.totalDamage / player.battles) : 0;
            case 'kills':
                return player.battles > 0 ? (player.totalKills / player.battles).toFixed(1) : '0.0';
            case 'assisted':
                return player.battles > 0 ? Math.round(player.totalAssisted / player.battles) : 0;
            case 'wgs':
                return Utils.calculateWGS(player).toFixed(2);
            case 'penetrationRatio':
                const penRate = player.totalHits > 0 ? ((player.totalPenetrations / player.totalHits) * 100).toFixed(1) : '0.0';
                return `${penRate}%`;
            case 'hitRatio':
                const hitRate = player.totalShots > 0 ? ((player.totalHits / player.totalShots) * 100).toFixed(1) : '0.0';
                return `${hitRate}%`;
            case 'survival':
                return player.battles > 0 ? (player.totalSurvived / player.battles * 100).toFixed(1) + '%' : '0%';
            case 'wins':
                return player.wins || 0;
            case 'losses':
                return player.losses || 0;
            case 'draws':
                return player.draws || 0;
            case 'winRate':
                if (player.battles > 0) {
                    return ((player.wins || 0) / player.battles * 100).toFixed(1) + '%';
                }
                return '0%';
            case 'spots':
                return player.battles > 0 ? (player.totalSpots / player.battles).toFixed(1) : '0.0';
            case 'defense':
                return player.battles > 0 ? (player.totalDefense / player.battles).toFixed(1) : '0.0';
            case 'capture':
                return player.battles > 0 ? (player.totalCapture / player.battles).toFixed(1) : '0.0';
            case 'xp':
                return player.totalXp || 0;
            case 'avgXp':
                return player.battles > 0 ? Math.round((player.totalXp || 0) / player.battles) : 0;
            case 'shots':
                return player.totalShots || 0;
            case 'hits':
                return player.totalHits || 0;
            case 'penetrations':
                return player.totalPenetrations || 0;
            case 'received':
                return player.totalReceived || 0;
            case 'avgReceived':
                return player.battles > 0 ? Math.round((player.totalReceived || 0) / player.battles) : 0;
            default:
                return '';
        }
    },
    downloadAsJPEG() {
        const isEn = AppConstants.LANG === 'en';
        const players = AppState.showEnemyTeam ? 
            Array.from(AppState.enemyStats.values()) : 
            Array.from(AppState.playersStats.values());
        const selectedPlayers = AppState.userSettings.selectedPlayers || { friendly: [], enemy: [] };
        const currentTeam = AppState.showEnemyTeam ? 'enemy' : 'friendly';
        const selectedForCurrentTeam = selectedPlayers[currentTeam] || [];
        const minBattles = Math.max(0, parseInt(AppState.userSettings.minBattles, 10) || 0);
        let filteredPlayers = players
            .filter(p => (p.battles || 0) >= minBattles)
            .filter(p => selectedForCurrentTeam.includes(p.name));
        filteredPlayers = StatsCalculator.sortPlayers(filteredPlayers);
        if (filteredPlayers.length === 0) {
            alert(isEn ? 'No data to download' : 'Нет данных для скачивания');
            return;
        }
        const visibleColumns = AppState.getVisibleColumns();
        const headers = AppConstants.COLUMN_HEADERS.filter(h => h.always || visibleColumns[h.key]);
        const measureCanvas = document.createElement('canvas');
        const measureCtx = measureCanvas.getContext('2d');
        measureCtx.font = '13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
        measureCtx.font = 'bold 14px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
        const columnWidths = [];
        const cellPadding = 20;
        headers.forEach((header, index) => {
            let headerText = header.title;
            if (header.key === AppState.currentSort.column) {
                headerText += AppState.currentSort.direction === 'asc' ? ' ↑' : ' ↓';
            }
            let maxWidth = measureCtx.measureText(headerText).width + cellPadding;
            filteredPlayers.forEach(player => {
                const value = this.getColumnValue(player, header.key);
                let displayValue = this.formatValue(value, header.key);
                if (header.key === 'name') {
                    displayValue = player.name;
                }
                const textWidth = measureCtx.measureText(displayValue.toString()).width + cellPadding;
                if (textWidth > maxWidth) {
                    maxWidth = textWidth;
                }
            });
            const minWidth = 60;
            const maxAllowedWidth = 400;
            columnWidths.push(Math.min(Math.max(Math.ceil(maxWidth), minWidth), maxAllowedWidth));
        });
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const rowHeight = 40;
        const headerHeight = 50;
        const footerHeight = 70;
        const totalWidth = columnWidths.reduce((a, b) => a + b, 0);
        const totalHeight = headerHeight + (filteredPlayers.length * rowHeight) + footerHeight;
        canvas.width = totalWidth;
        canvas.height = totalHeight;
        ctx.fillStyle = '#1a1a1a';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        let x = 0;
        headers.forEach((header, index) => {
            ctx.fillStyle = '#ffd966';
            ctx.font = 'bold 14px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
            ctx.textBaseline = 'middle';
            let title = header.title;
            if (header.key === AppState.currentSort.column) {
                title += AppState.currentSort.direction === 'asc' ? ' ↑' : ' ↓';
            }
            ctx.fillText(title, x + 10, headerHeight / 2);
            if (index < headers.length - 1) {
                ctx.strokeStyle = '#3a3a3a';
                ctx.lineWidth = 1;
                ctx.beginPath();
                ctx.moveTo(x + columnWidths[index] - 1, 0);
                ctx.lineTo(x + columnWidths[index] - 1, totalHeight - footerHeight);
                ctx.stroke();
            }
            x += columnWidths[index];
        });
        ctx.strokeStyle = '#3a3a3a';
        ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(0, headerHeight);
        ctx.lineTo(canvas.width, headerHeight);
        ctx.stroke();
        filteredPlayers.forEach((player, rowIndex) => {
            const y = headerHeight + (rowIndex * rowHeight) + rowHeight / 2;
            if (rowIndex % 2 === 0) {
                ctx.fillStyle = 'rgba(255,255,255,0.05)';
                ctx.fillRect(0, headerHeight + (rowIndex * rowHeight), canvas.width, rowHeight);
            }
            x = 0;
            headers.forEach((header, colIndex) => {
                const value = this.getColumnValue(player, header.key);
                let displayValue = this.formatValue(value, header.key);
                if (header.key === 'name') {
                    displayValue = player.name;
                }
                if (header.key === 'wgs') {
                    const wgsValue = parseFloat(displayValue);
                    const color = Utils.getWgsrtColor(wgsValue);
                    ctx.fillStyle = color;
                    ctx.font = 'bold 13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
                } else if (header.key === 'damage') {
                    ctx.fillStyle = '#ffd966';
                    ctx.font = 'bold 13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
                } else if (header.key === 'survival') {
                    const rate = parseFloat(player.totalSurvived / player.battles * 100);
                    ctx.fillStyle = rate >= 50 ? '#4caf50' : '#ff6b6b';
                    ctx.font = '13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
                } else if (header.key === 'hitRatio' || header.key === 'penetrationRatio') {
                    ctx.fillStyle = '#64b5f6';
                    ctx.font = '13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
                } else {
                    ctx.fillStyle = '#e0e0e0';
                    ctx.font = '13px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
                }
                ctx.fillText(displayValue, x + 10, y);
                if (colIndex < headers.length - 1) {
                    ctx.strokeStyle = '#3a3a3a';
                    ctx.lineWidth = 1;
                    ctx.beginPath();
                    ctx.moveTo(x + columnWidths[colIndex] - 1, headerHeight);
                    ctx.lineTo(x + columnWidths[colIndex] - 1, totalHeight - footerHeight);
                    ctx.stroke();
                }
                x += columnWidths[colIndex];
            });
        });
        ctx.strokeStyle = '#3a3a3a';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(0, totalHeight - footerHeight);
        ctx.lineTo(canvas.width, totalHeight - footerHeight);
        ctx.stroke();
        ctx.fillStyle = 'rgba(255,255,255,0.02)';
        ctx.fillRect(0, totalHeight - footerHeight, canvas.width, footerHeight);
        ctx.fillStyle = '#888';
        ctx.font = '11px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
        ctx.textBaseline = 'middle';
        const footerY1 = totalHeight - footerHeight + 20;
        ctx.textAlign = 'left';
        ctx.fillText(`${isEn ? 'Files' : 'Файлов'}: ${AppState.fileData.length}`, 10, footerY1);
        ctx.textAlign = 'center';
        const date = new Date().toLocaleDateString(isEn ? 'en-US' : 'ru-RU');
        ctx.fillText(date, canvas.width / 2, footerY1);
        ctx.textAlign = 'right';
        ctx.fillText(isEn ? 'ABS Replays Analysis' : 'Анализ АБС реплеев', canvas.width - 10, footerY1);
        const footerY2 = totalHeight - 20;
        ctx.textAlign = 'center';
        const twitchText = '📺 twitch.tv/chadowfriend';
        const telegramText = '📱 t.me/chadowfriend';
        const donateText = '🎁 donationalerts.com/r/chadowfriend';
        const fullLinksString = twitchText + ' • ' + telegramText + ' • ' + donateText;
        ctx.fillStyle = '#cccccc';
        ctx.font = '11px "Segoe UI", Tahoma, Geneva, Verdana, sans-serif';
        ctx.fillText(fullLinksString, canvas.width / 2, footerY2);
        canvas.toBlob((blob) => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            const now = new Date();
            const day = now.getDate().toString().padStart(2, '0');
            const month = (now.getMonth() + 1).toString().padStart(2, '0');
            const year = now.getFullYear();
            const hours = now.getHours().toString().padStart(2, '0');
            const minutes = now.getMinutes().toString().padStart(2, '0');
            const seconds = now.getSeconds().toString().padStart(2, '0');
            a.download = `Analysis-ABS-replays-${day}.${month}.${year}-${hours}-${minutes}-${seconds}.jpg`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }, 'image/jpeg', 0.95);
    }
};