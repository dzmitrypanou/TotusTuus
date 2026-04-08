const ReplayParser = {
    extractAllJSON(text) {
        const results = [];
        let pos = 0;
        
        while (pos < text.length) {
            const startIdx = text.indexOf('{', pos);
            if (startIdx === -1) break;
            
            let braceCount = 0;
            let inString = false;
            let escapeNext = false;
            let endIdx = -1;
            
            for (let i = startIdx; i < text.length; i++) {
                const char = text[i];
                
                if (escapeNext) {
                    escapeNext = false;
                    continue;
                }
                
                if (char === '\\' && inString) {
                    escapeNext = true;
                    continue;
                }
                
                if (char === '"' && !escapeNext) {
                    inString = !inString;
                    continue;
                }
                
                if (!inString) {
                    if (char === '{') {
                        braceCount++;
                    } else if (char === '}') {
                        braceCount--;
                        if (braceCount === 0) {
                            endIdx = i + 1;
                            break;
                        }
                    }
                }
            }
            
            if (endIdx !== -1) {
                try {
                    const jsonStr = text.substring(startIdx, endIdx);
                    const parsed = JSON.parse(jsonStr);
                    results.push(parsed);
                } catch (e) {}
                pos = endIdx;
            } else {
                pos = text.length;
            }
        }
        
        return results;
    },

    async getNormalizedVehicleName(vehicleType) {
        if (!vehicleType) return AppConstants.LANG === 'en' ? 'Unknown' : 'Неизвестно';
        
        let displayName = await API.getTankName(vehicleType);
        
        if (displayName === API.extractTankNameFromCode(vehicleType)) {
            const generatedName = API.extractTankNameFromCode(vehicleType);
            const tankType = API.determineTankType(vehicleType);
            const tier = API.determineTier(vehicleType);
            
            const added = await API.addNewTank(vehicleType, generatedName, tankType, tier, false);
            if (added) {
                displayName = await API.getTankName(vehicleType);
            } else {
                displayName = generatedName;
            }
        }
        
        return displayName;
    },

    collectNationCodesFromBattleData(battleData, jsonObjects) {
        const set = new Set();
        const addVt = (vt) => {
            const n = API.extractNationFromVehicleCode(vt);
            if (n) {
                set.add(n);
            }
        };
        if (battleData) {
            if (battleData.playerVehicle) {
                addVt(battleData.playerVehicle);
            }
            if (battleData.vehicles && typeof battleData.vehicles === 'object') {
                Object.values(battleData.vehicles).forEach((v) => {
                    if (v && v.vehicleType) {
                        addVt(v.vehicleType);
                    }
                });
            }
        }
        if (Array.isArray(jsonObjects)) {
            for (let i = 1; i < jsonObjects.length; i++) {
                const obj = jsonObjects[i];
                if (!obj || typeof obj !== 'object') {
                    continue;
                }
                if (obj.players) {
                    Object.values(obj.players).forEach((p) => {
                        if (p && p.vehicleType) {
                            addVt(p.vehicleType);
                        }
                    });
                }
                const keys = Object.keys(obj);
                if (keys.length > 0 && /^\d+$/.test(keys[0])) {
                    Object.values(obj).forEach((v) => {
                        if (v && typeof v === 'object' && v.vehicleType) {
                            addVt(v.vehicleType);
                        }
                    });
                }
            }
        }
        return Array.from(set);
    },

    cleanMapName(mapName) {
        if (!mapName) return 'unknown';
        let cleaned = mapName.replace(/^\d+_/, '');
        if (!cleaned || cleaned.length < 1) {
            cleaned = mapName;
        }
        cleaned = cleaned.replace(/[^a-zA-Z0-9_\-]/g, '_');
        cleaned = cleaned.replace(/_+/g, '_');
        cleaned = cleaned.replace(/^_|_$/g, '');
        return cleaned;
    },

    async findPlayerStats(jsonObjects) {
        if (!jsonObjects || jsonObjects.length === 0) {
            return null;
        }
        
        const battleData = jsonObjects[0];
        if (!battleData) {
            return null;
        }

        const nationCodes = ReplayParser.collectNationCodesFromBattleData(battleData, jsonObjects);
        await API.ensureNationsFromVehicleCodes(nationCodes);

        const battleType = battleData.battleType;
        const gameplayID = battleData.gameplayID;
        
        let playerStats = null;
        let playerStatsObj = null;
        let vehiclesStatsObj = null;
        let playersInfoObj = null;
        let allPlayersData = null;
        
        const battleNameToRealName = new Map();
        const battleNameToClan = new Map();
        const battleNameToGlobalId = new Map();
        
        for (let i = 1; i < jsonObjects.length; i++) {
            const obj = jsonObjects[i];
            if (!obj) continue;
            
            if (obj && obj.personal) {
                playerStatsObj = obj;
            }
            
            if (obj && obj.vehicles && typeof obj.vehicles === 'object') {
                const firstKey = Object.keys(obj.vehicles)[0];
                if (firstKey) {
                    const sample = obj.vehicles[firstKey];
                    if (Array.isArray(sample)) {
                        vehiclesStatsObj = obj;
                    }
                }
            }
            
            if (obj && obj.players) {
                playersInfoObj = obj;
                
                Object.entries(obj.players).forEach(([globalId, playerInfo]) => {
                    if (playerInfo && playerInfo.name) {
                        const battleName = playerInfo.name.trim();
                        const realName = (playerInfo.realName || playerInfo.name).trim();
                        const clan = playerInfo.clanAbbrev || '';
                        
                        battleNameToRealName.set(battleName, realName);
                        battleNameToClan.set(battleName, clan);
                        battleNameToGlobalId.set(battleName, globalId);
                    }
                });
            }
            
            if (obj && typeof obj === 'object' && !obj.vehicles && !obj.players && !obj.personal) {
                const keys = Object.keys(obj);
                if (keys.length > 0 && /^\d+$/.test(keys[0])) {
                    const firstKey = keys[0];
                    const firstValue = obj[firstKey];
                    
                    if (firstValue && (firstValue.vehicleType !== undefined || firstValue.name !== undefined)) {
                        allPlayersData = obj;
                    }
                }
            }
        }
        
        if (playerStatsObj && playerStatsObj.personal) {
            for (let key in playerStatsObj.personal) {
                const stats = playerStatsObj.personal[key];
                if (stats && typeof stats === 'object' && stats.damageDealt !== undefined) {
                    playerStats = stats;
                    break;
                }
            }
        }
        
        let playerSessionId = null;
        let playerTeam = null;
        
        if (battleData.vehicles && battleData.playerName) {
            for (let sessionId in battleData.vehicles) {
                const vehicle = battleData.vehicles[sessionId];
                if (vehicle && vehicle.name === battleData.playerName) {
                    playerSessionId = sessionId;
                    playerTeam = vehicle.team;
                    break;
                }
            }
        }
        
        let teamStats = [];
        let enemyTeamStats = [];
        
        const isComp7 = battleType === 43 || battleType === '43' || gameplayID === 'comp7' || gameplayID === 34;
        const battleVehicles = battleData.vehicles && typeof battleData.vehicles === 'object'
            ? Object.values(battleData.vehicles).filter(v => v && v.team !== undefined)
            : [];
        const battleVehicleTeamsCount = new Set(battleVehicles.map(v => v.team)).size;
        const battleVehiclesCount = battleVehicles.length;
        const allPlayersCount = allPlayersData && typeof allPlayersData === 'object'
            ? Object.keys(allPlayersData).filter(key => /^\d+$/.test(key)).length
            : 0;
        const shouldUseAllPlayersData = !!allPlayersData && (
            isComp7 ||
            battleVehicleTeamsCount < 2 ||
            allPlayersCount > battleVehiclesCount
        );
        
        const allPlayers = [];
        
        const createPlayerStat = async (sessionId, playerData, stats) => {
            if (!playerData) return null;
            
            const battleName = playerData.name || (AppConstants.LANG === 'en' ? 'Unknown' : 'Неизвестно');
            const battleNameTrimmed = battleName.trim();
            
            const realName = battleNameToRealName.get(battleNameTrimmed) || battleNameTrimmed;
            const clan = battleNameToClan.get(battleNameTrimmed) || playerData.clanAbbrev || '';
            const globalId = battleNameToGlobalId.get(battleNameTrimmed);
            
            const team = playerData.team;
            if (team === undefined) return null;
            
            let survived = 1;
            if (stats) {
                if (stats.deathCount !== undefined) {
                    survived = stats.deathCount === 0 ? 1 : 0;
                } else if (stats.deathReason !== undefined) {
                    survived = stats.deathReason === -1 ? 1 : 0;
                }
            }
            
            const vehicleName = await ReplayParser.getNormalizedVehicleName(playerData.vehicleType || playerData.vehicle);
            
            return {
                sessionId,
                name: realName,
                battleName: battleNameTrimmed,
                playerId: globalId || sessionId,
                globalId: globalId,
                clan: clan,
                vehicle: vehicleName,
                originalVehicleType: playerData.vehicleType || playerData.vehicle,
                damage: stats?.damageDealt || 0,
                damageBlocked: stats?.damageBlockedByArmor || 0,
                kills: stats?.kills || 0,
                assisted: (stats?.damageAssistedTrack || 0) + (stats?.damageAssistedRadio || 0) + (stats?.damageAssistedStun || 0),
                survived: survived,
                xp: stats?.xp || 0,
                shots: stats?.shots || 0,
                hits: stats?.directEnemyHits || stats?.directHits || 0,
                penetrations: stats?.piercingEnemyHits || stats?.piercings || 0,
                spots: stats?.enemySpotted || stats?.spotted || 0,
                defense: stats?.droppedCapturePoints || 0,
                capture: stats?.capturePoints || 0,
                team: team
            };
        };
        
        const promises = [];
        
        if (shouldUseAllPlayersData) {
            Object.entries(allPlayersData).forEach(([sessionId, playerData]) => {
                if (!/^\d+$/.test(sessionId) || !playerData) return;
                
                let stats = {};
                if (vehiclesStatsObj && vehiclesStatsObj.vehicles && vehiclesStatsObj.vehicles[sessionId]) {
                    if (Array.isArray(vehiclesStatsObj.vehicles[sessionId])) {
                        stats = vehiclesStatsObj.vehicles[sessionId][0] || {};
                    }
                }
                
                promises.push(createPlayerStat(sessionId, playerData, stats).then(playerStat => {
                    if (playerStat) {
                        allPlayers.push(playerStat);
                    }
                }));
            });
        } else {
            if (battleData.vehicles) {
                Object.entries(battleData.vehicles).forEach(([sessionId, vehicleData]) => {
                    if (!vehicleData) return;
                    
                    let stats = {};
                    if (vehiclesStatsObj && vehiclesStatsObj.vehicles && vehiclesStatsObj.vehicles[sessionId]) {
                        if (Array.isArray(vehiclesStatsObj.vehicles[sessionId])) {
                            stats = vehiclesStatsObj.vehicles[sessionId][0] || {};
                        }
                    }
                    
                    promises.push(createPlayerStat(sessionId, vehicleData, stats).then(playerStat => {
                        if (playerStat) {
                            allPlayers.push(playerStat);
                        }
                    }));
                });
            }
        }
        
        await Promise.all(promises);
        
        if (playerTeam === null || playerTeam === undefined) {
            const me = (battleData.playerName || '').trim();
            if (me) {
                const meEntry = allPlayers.find(p => p && p.battleName === me);
                if (meEntry) {
                    playerTeam = meEntry.team;
                }
            }
        }
        
        let winnerTeam = null;
        
        if (battleData.winnerTeam !== undefined && battleData.winnerTeam !== null) {
            winnerTeam = battleData.winnerTeam;
        } else if (battleData.common && battleData.common.winnerTeam !== undefined) {
            winnerTeam = battleData.common.winnerTeam;
        } else if (battleData.battleResult && battleData.battleResult.winnerTeam !== undefined) {
            winnerTeam = battleData.battleResult.winnerTeam;
        } else {
            const team1Capture = allPlayers
                .filter(p => p.team === 1)
                .reduce((sum, p) => sum + p.capture, 0);
                
            const team2Capture = allPlayers
                .filter(p => p.team === 2)
                .reduce((sum, p) => sum + p.capture, 0);
            
            if (team1Capture >= 100 || team2Capture >= 100) {
                winnerTeam = team1Capture >= 100 ? 1 : 2;
            } else {
                const team2Alive = allPlayers.filter(p => p.team === 2 && p.survived === 1).length;
                const team1Alive = allPlayers.filter(p => p.team === 1 && p.survived === 1).length;
                
                if (team2Alive === 0 && team1Alive > 0) {
                    winnerTeam = 1;
                } else if (team1Alive === 0 && team2Alive > 0) {
                    winnerTeam = 2;
                }
            }
        }

        /* 0 / -1 в реплее — ничья (нет победителя) */
        const wn = winnerTeam != null ? Number(winnerTeam) : NaN;
        if (wn === 0 || wn === -1) {
            winnerTeam = null;
        }
        
        teamStats = allPlayers.filter(p => p.team === playerTeam);
        enemyTeamStats = allPlayers.filter(p => p.team !== playerTeam);

        const wtNum = winnerTeam != null ? Number(winnerTeam) : NaN;
        const hasWinner = winnerTeam != null && !Number.isNaN(wtNum) && wtNum !== 0 && wtNum !== -1;
        
        if (hasWinner) {
            const wt = wtNum;
            teamStats.forEach(p => {
                p.isWin = wt === p.team;
                p.isDraw = false;
            });
            enemyTeamStats.forEach(p => {
                p.isWin = wt === p.team;
                p.isDraw = false;
            });
        } else {
            teamStats.forEach(p => {
                p.isWin = false;
                p.isDraw = true;
            });
            enemyTeamStats.forEach(p => {
                p.isWin = false;
                p.isDraw = true;
            });
        }
        
        const isWin = hasWinner && wtNum === playerTeam;
        const isDraw = !hasWinner;
        
        let rawMapName = battleData.mapName;
        if (!rawMapName) {
            rawMapName = battleData.mapDisplayName || 'unknown';
        }
        
        const cleanMapName = this.cleanMapName(rawMapName);
        let mapDisplayName = '';
        if (typeof battleData.mapDisplayName === 'string' && battleData.mapDisplayName.trim() !== '') {
            mapDisplayName = battleData.mapDisplayName.trim();
        }
        
        let dateTime = battleData.dateTime;
        if (!dateTime) {
            const now = new Date();
            dateTime = now.toLocaleString('ru-RU', {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            }).replace(/\./g, '.').replace(/,/, '');
        }
        
        return {
            battleData,
            playerStats,
            playerTeam,
            playerSessionId,
            teamStats,
            enemyTeamStats,
            isWin,
            isDraw,
            mapName: cleanMapName,
            mapDisplayName,
            originalMapName: battleData.mapName,
            dateTime: dateTime,
            playerVehicle: await ReplayParser.getNormalizedVehicleName(battleData.playerVehicle) || (AppConstants.LANG === 'en' ? 'Unknown' : 'Неизвестно')
        };
    }
};