const Renderer = {
    updateDisplay() {
        FilesUI.updateFilesList(AppState.fileData, AppState.userSettings);
        TableUI.updateSortHeaders(AppState.currentSort);
        TableUI.updateTeamTabs(AppState.showEnemyTeam);
        
        const players = AppState.showEnemyTeam ? 
            Array.from(AppState.enemyStats.values()) : 
            Array.from(AppState.playersStats.values());
        
        const sortedPlayers = StatsCalculator.sortPlayers(players);
        TableUI.updatePlayersTable(sortedPlayers, AppState.expandedPlayer);
    }
};