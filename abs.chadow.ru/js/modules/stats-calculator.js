const StatsCalculator = {
    allPlayersStats: {
        friendly: new Map(),
        enemy: new Map()
    },

    updatePlayerStats(statsMap, player) {
        if (!player || !player.name) return;
        
        const key = player.name;
        
        if (!statsMap.has(key)) {
            statsMap.set(key, {
                name: player.name,
                battles: 0,
                totalDamage: 0,
                totalKills: 0,
                totalAssisted: 0,
                totalReceived: 0,
                totalSurvived: 0,
                totalShots: 0,
                totalHits: 0,
                totalPenetrations: 0,
                wins: 0,
                losses: 0,
                draws: 0,
                totalSpots: 0,
                totalDefense: 0,
                totalCapture: 0,
                totalXp: 0,
                clan: player.clan || '',
                battleName: player.battleName || player.name,
                playerId: player.playerId || ''
            });
        }
        
        const stats = statsMap.get(key);
        stats.battles++;
        stats.totalDamage += player.damage || 0;
        stats.totalKills += player.kills || 0;
        stats.totalAssisted += player.assisted || 0;
        stats.totalReceived += player.damageBlocked || 0;
        stats.totalSurvived += player.survived || 0;
        stats.totalShots += player.shots || 0;
        stats.totalHits += player.hits || 0;
        stats.totalPenetrations += player.penetrations || 0;
        stats.totalXp += player.xp || 0;
        stats.totalSpots += player.spots || 0;
        stats.totalDefense += player.defense || 0;
        stats.totalCapture += player.capture || 0;
        
        if (player.isWin === true) {
            stats.wins++;
        } else if (player.isDraw === true) {
            stats.draws++;
        } else {
            stats.losses++;
        }
    },

    updateAllPlayersStats() {
        this.allPlayersStats.friendly.clear();
        this.allPlayersStats.enemy.clear();
        
        AppState.fileData.forEach(file => {
            const battleInfo = file.battleInfo;
            if (!battleInfo) return;
            
            if (battleInfo.teamStats && Array.isArray(battleInfo.teamStats)) {
                battleInfo.teamStats.forEach(player => {
                    this.updatePlayerStats(this.allPlayersStats.friendly, player);
                });
            }
            
            if (battleInfo.enemyTeamStats && Array.isArray(battleInfo.enemyTeamStats)) {
                battleInfo.enemyTeamStats.forEach(player => {
                    this.updatePlayerStats(this.allPlayersStats.enemy, player);
                });
            }
        });
        
        AppState.availableMaps.clear();
        AppState.fileData.forEach(file => {
            if (file.battleInfo && file.battleInfo.mapName) {
                AppState.availableMaps.add(file.battleInfo.mapName);
            }
        });
    },

    filterStatsByMap() {
        AppState.playersStats.clear();
        AppState.enemyStats.clear();
        
        const filteredFriendly = new Map();
        const filteredEnemy = new Map();
        
        AppState.fileData.forEach(file => {
            const battleInfo = file.battleInfo;
            if (!battleInfo) return;
            
            if (AppState.currentMap !== 'all' && battleInfo.mapName !== AppState.currentMap) {
                return;
            }
            
            if (battleInfo.teamStats && Array.isArray(battleInfo.teamStats)) {
                battleInfo.teamStats.forEach(player => {
                    this.updatePlayerStats(filteredFriendly, player);
                });
            }
            
            if (battleInfo.enemyTeamStats && Array.isArray(battleInfo.enemyTeamStats)) {
                battleInfo.enemyTeamStats.forEach(player => {
                    this.updatePlayerStats(filteredEnemy, player);
                });
            }
        });
        
        AppState.playersStats = filteredFriendly;
        AppState.enemyStats = filteredEnemy;
    },

    recalcStats(isNewFiles = false) {
        this.updateAllPlayersStats();
        this.filterStatsByMap();
        
        if (isNewFiles) {
            this.selectAllPlayers();
            AppState.isNewFilesLoaded = true;
        } else {
            this.filterSelectedPlayers();
        }
    },

    selectAllPlayers() {
        const allFriendlyPlayers = Array.from(this.allPlayersStats.friendly.values()).map(p => p.name);
        const allEnemyPlayers = Array.from(this.allPlayersStats.enemy.values()).map(p => p.name);
        
        if (!AppState.userSettings.selectedPlayers) {
            AppState.userSettings.selectedPlayers = { friendly: [], enemy: [] };
        }
        
        AppState.userSettings.selectedPlayers.friendly = [...allFriendlyPlayers];
        AppState.userSettings.selectedPlayers.enemy = [...allEnemyPlayers];
        
        AppState.saveSettings();
    },

    filterSelectedPlayers() {
        const allFriendlyPlayers = Array.from(this.allPlayersStats.friendly.values()).map(p => p.name);
        const allEnemyPlayers = Array.from(this.allPlayersStats.enemy.values()).map(p => p.name);
        
        if (!AppState.userSettings.selectedPlayers) {
            AppState.userSettings.selectedPlayers = { friendly: [], enemy: [] };
            return;
        }
        
        if (AppState.userSettings.selectedPlayers.friendly) {
            AppState.userSettings.selectedPlayers.friendly = AppState.userSettings.selectedPlayers.friendly.filter(
                name => allFriendlyPlayers.includes(name)
            );
        } else {
            AppState.userSettings.selectedPlayers.friendly = [];
        }
        
        if (AppState.userSettings.selectedPlayers.enemy) {
            AppState.userSettings.selectedPlayers.enemy = AppState.userSettings.selectedPlayers.enemy.filter(
                name => allEnemyPlayers.includes(name)
            );
        } else {
            AppState.userSettings.selectedPlayers.enemy = [];
        }
        
        AppState.saveSettings();
    },

    getAllPlayers() {
        return {
            friendly: Array.from(this.allPlayersStats.friendly.values()),
            enemy: Array.from(this.allPlayersStats.enemy.values())
        };
    },

    getNumericValue(player, columnKey) {
        if (!player) return 0;
        
        switch(columnKey) {
            case 'name':
                return player.name || '';
            case 'battles':
                return player.battles || 0;
            case 'damage':
                return player.battles > 0 ? player.totalDamage / player.battles : 0;
            case 'kills':
                return player.battles > 0 ? player.totalKills / player.battles : 0;
            case 'assisted':
                return player.battles > 0 ? player.totalAssisted / player.battles : 0;
            case 'wgs':
                return Utils.calculateWGS(player);
            case 'penetrationRatio':
                return player.totalHits > 0 ? player.totalPenetrations / player.totalHits : 0;
            case 'hitRatio':
                return player.totalShots > 0 ? player.totalHits / player.totalShots : 0;
            case 'survival':
                return player.battles > 0 ? player.totalSurvived / player.battles : 0;
            case 'wins':
                return player.wins || 0;
            case 'losses':
                return player.losses || 0;
            case 'draws':
                return player.draws || 0;
            case 'winRate':
                return player.battles > 0 ? (player.wins || 0) / player.battles : 0;
            case 'spots':
                return player.battles > 0 ? player.totalSpots / player.battles : 0;
            case 'defense':
                return player.battles > 0 ? player.totalDefense / player.battles : 0;
            case 'capture':
                return player.battles > 0 ? player.totalCapture / player.battles : 0;
            case 'xp':
                return player.totalXp || 0;
            case 'avgXp':
                return player.battles > 0 ? (player.totalXp || 0) / player.battles : 0;
            case 'shots':
                return player.totalShots || 0;
            case 'hits':
                return player.totalHits || 0;
            case 'penetrations':
                return player.totalPenetrations || 0;
            case 'received':
                return player.totalReceived || 0;
            case 'avgReceived':
                return player.battles > 0 ? (player.totalReceived || 0) / player.battles : 0;
            default:
                return 0;
        }
    },

    sortPlayers(players) {
        const selectedPlayers = AppState.userSettings.selectedPlayers || { friendly: [], enemy: [] };
        const currentTeam = AppState.showEnemyTeam ? 'enemy' : 'friendly';
        const selectedForCurrentTeam = selectedPlayers[currentTeam] || [];
        
        let filteredPlayers = players;
        if (selectedForCurrentTeam && selectedForCurrentTeam.length > 0) {
            filteredPlayers = players.filter(p => selectedForCurrentTeam.includes(p.name));
        } else {
            filteredPlayers = [];
        }
        
        return [...filteredPlayers].sort((a, b) => {
            let aVal = this.getNumericValue(a, AppState.currentSort.column);
            let bVal = this.getNumericValue(b, AppState.currentSort.column);
            
            if (AppState.currentSort.column === 'name') {
                aVal = aVal.toLowerCase();
                bVal = bVal.toLowerCase();
            }
            
            if (AppState.currentSort.direction === 'asc') {
                return aVal > bVal ? 1 : -1;
            } else {
                return aVal < bVal ? 1 : -1;
            }
        });
    }
};