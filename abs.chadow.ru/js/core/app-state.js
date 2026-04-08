const AppState = {
    fileData: [],
    playersStats: new Map(),
    playersInfo: new Map(),
    currentSort: { column: 'damage', direction: 'desc' },
    serverRegion: 'ru',
    processedFileHashes: new Set(),
    expandedPlayer: null,
    currentBattleType: 'all',
    currentMap: 'all',
    showEnemyTeam: false,
    enemyStats: new Map(),
    availableMaps: new Set(),
    isNewFilesLoaded: false,
    version: '3.4.4',
    userSettings: {
        battleType: 'all',
        map: 'all',
        showEnemy: false,
        sortColumn: 'damage',
        sortDirection: 'desc',
        expandedSections: {},
        visibleColumns: { ...AppConstants.DEFAULT_VISIBLE_COLUMNS },
        selectedPlayers: {
            friendly: [],
            enemy: []
        },
        showOnlySelectedFriendly: false,
        showOnlySelectedEnemy: false,
        saveReplayConsent: false,
        minBattles: 1
    },

    async loadSettings() {
        try {
            const saved = localStorage.getItem('absReplaysSettings');
            if (saved) {
                const settings = JSON.parse(saved);
                this.applySettings(settings);
            }
        } catch (e) {}
    },

    applySettings(settings) {
        this.currentBattleType = settings.battleType || 'all';
        this.currentMap = 'all';
        this.showEnemyTeam = settings.showEnemy || false;
        this.currentSort.column = settings.sortColumn || 'damage';
        this.currentSort.direction = settings.sortDirection || 'desc';
        this.userSettings.expandedSections = settings.expandedSections || {};
        this.userSettings.visibleColumns = settings.visibleColumns || { ...AppConstants.DEFAULT_VISIBLE_COLUMNS };
        this.userSettings.selectedPlayers = settings.selectedPlayers || { friendly: [], enemy: [] };
        this.userSettings.showOnlySelectedFriendly = settings.showOnlySelectedFriendly || false;
        this.userSettings.showOnlySelectedEnemy = settings.showOnlySelectedEnemy || false;
        this.userSettings.saveReplayConsent = settings.saveReplayConsent === true;
        const mb = settings.minBattles;
        this.userSettings.minBattles =
            mb !== undefined && mb !== null && String(mb).trim() !== ''
                ? Math.max(0, parseInt(mb, 10) || 0)
                : 1;
    },

    saveSettings() {
        try {
            const settings = {
                battleType: this.currentBattleType,
                map: this.currentMap,
                showEnemy: this.showEnemyTeam,
                sortColumn: this.currentSort.column,
                sortDirection: this.currentSort.direction,
                expandedSections: this.userSettings.expandedSections,
                visibleColumns: this.userSettings.visibleColumns,
                selectedPlayers: this.userSettings.selectedPlayers,
                showOnlySelectedFriendly: this.userSettings.showOnlySelectedFriendly,
                showOnlySelectedEnemy: this.userSettings.showOnlySelectedEnemy,
                saveReplayConsent: this.userSettings.saveReplayConsent === true,
                minBattles: Math.max(0, parseInt(this.userSettings.minBattles, 10) || 0),
                version: this.version
            };
            
            localStorage.setItem('absReplaysSettings', JSON.stringify(settings));
        } catch (e) {}
    },

    resetAllData() {
        this.fileData = [];
        this.playersStats.clear();
        this.playersInfo.clear();
        this.enemyStats.clear();
        this.processedFileHashes.clear();
        this.expandedPlayer = null;
        this.availableMaps.clear();
        this.currentMap = 'all';
        this.isNewFilesLoaded = false;
        this.userSettings.selectedPlayers = { friendly: [], enemy: [] };
        this.saveSettings();
    },

    getVisibleColumns() {
        return this.userSettings.visibleColumns || { ...AppConstants.DEFAULT_VISIBLE_COLUMNS };
    },

    getPlayerVehiclesStats(playerName) {
        const vehiclesStats = [];
        
        this.fileData.forEach(file => {
            if (this.currentMap !== 'all' && file.battleInfo.mapName !== this.currentMap) {
                return;
            }
            
            const battleInfo = file.battleInfo;
            const teamToCheck = this.showEnemyTeam ? battleInfo.enemyTeamStats : battleInfo.teamStats;
            
            teamToCheck.forEach(player => {
                if (player.name === playerName) {
                    vehiclesStats.push({
                        vehicle: player.vehicle,
                        damage: player.damage,
                        kills: player.kills,
                        assisted: player.assisted,
                        survived: player.survived,
                        shots: player.shots,
                        hits: player.hits,
                        penetrations: player.penetrations,
                        xp: player.xp,
                        isWin: player.isWin,
                        isDraw: player.isDraw === true,
                        mapName: battleInfo.mapName,
                        dateTime: battleInfo.dateTime
                    });
                }
            });
        });
        
        return vehiclesStats.sort((a, b) => new Date(b.dateTime) - new Date(a.dateTime));
    }
};