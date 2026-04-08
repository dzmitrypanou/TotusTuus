const MAX_REPLAY_FILE_BYTES = 10 * 1024 * 1024;
const MAX_REPLAY_BATCH_WITH_SERVER_SAVE = 50;

const FileHandler = {
    async processFiles(files) {
        const isEn = AppConstants.LANG === 'en';
        const oldWrapper = document.querySelector('.files-error-wrapper');
        if (oldWrapper) {
            const filesListElement = document.getElementById('filesList');
            oldWrapper.parentNode.insertBefore(filesListElement, oldWrapper);
            oldWrapper.remove();
        }
        
        const newFiles = [];
        const duplicateFiles = [];
        const invalidFiles = [];
        const oversizedFiles = [];
        
        files.forEach(file => {
            if (!file.name.match(/\.(mtreplay|wotreplay)$/i)) {
                invalidFiles.push(file.name);
                return;
            }
            if (file.size > MAX_REPLAY_FILE_BYTES) {
                oversizedFiles.push(file.name);
                return;
            }
            
            const fileHash = Utils.generateFileHash(file);
            if (AppState.processedFileHashes.has(fileHash)) {
                duplicateFiles.push(file.name);
            } else {
                AppState.processedFileHashes.add(fileHash);
                newFiles.push(file);
            }
        });
        
        const consentCheckbox = document.getElementById('saveReplayConsent');
        const saveToServer =
            AppState.userSettings.saveReplayConsent === true ||
            (consentCheckbox && consentCheckbox.checked === true);
        
        let batchLimitAborted = false;
        if (saveToServer && newFiles.length > MAX_REPLAY_BATCH_WITH_SERVER_SAVE) {
            batchLimitAborted = true;
            newFiles.forEach(file => {
                AppState.processedFileHashes.delete(Utils.generateFileHash(file));
            });
            newFiles.length = 0;
        }
        
        const errors = [];
        if (invalidFiles.length) errors.push({ type: 'invalid', files: invalidFiles });
        if (oversizedFiles.length) errors.push({ type: 'tooLarge', files: oversizedFiles });
        if (duplicateFiles.length) errors.push({ type: 'duplicate', files: duplicateFiles });
        if (batchLimitAborted) {
            errors.push({
                type: 'batchLimitAbort',
                message:
                    isEn
                        ? 'When server saving is enabled, no more than 50 replays are allowed per batch. You selected more — upload is cancelled and no files will be processed.'
                        : 'При включённом сохранении на сервере за один раз допускается не более 50 реплеев. Выбрано больше — загрузка отменена, ни один файл не обработан.'
            });
        }
        
        ErrorsUI.updateErrorList(errors);
        
        if (newFiles.length === 0) {
            UI.hideLoading();
            FilesUI.updateFilesList(AppState.fileData, AppState.userSettings);
            return;
        }
        
        UI.updateFileInfo(`${isEn ? 'Files' : 'Файлов'}: ${AppState.fileData.length + newFiles.length}`);
        UI.showLoading();
        
        let processed = 0;
        let failedFiles = [];
        const total = newFiles.length;
        
        const batchSize = 3;
        for (let i = 0; i < newFiles.length; i += batchSize) {
            const batch = newFiles.slice(i, i + batchSize);
            await Promise.all(batch.map(file => this.processSingleFile(file, failedFiles)));
            processed += batch.length;
            if (processed === total) {
                this.finalizeProcessing(errors, failedFiles);
            }
        }
    },

    async processSingleFile(file, failedFiles) {
        try {
            const text = await this.readFileAsync(file);
            const jsonObjects = ReplayParser.extractAllJSON(text);
            const battleInfo = await ReplayParser.findPlayerStats(jsonObjects);
            
            if (!battleInfo || !battleInfo.battleData || !battleInfo.playerStats) {
                throw new Error(AppConstants.LANG === 'en'
                    ? 'Unable to find player statistics'
                    : 'Не удалось найти статистику игрока');
            }
            
            if (battleInfo.battleData.regionCode) {
                AppState.serverRegion = battleInfo.battleData.regionCode.toLowerCase();
            }
            
            const consentCheckbox = document.getElementById('saveReplayConsent');
            const hasConsent = AppState.userSettings.saveReplayConsent === true ||
                (consentCheckbox && consentCheckbox.checked === true);

            const savedFile = await API.saveReplayFile(file, battleInfo, hasConsent);

            await API.ensureMapFromReplay(battleInfo.mapName, battleInfo.mapDisplayName);
            
            if (battleInfo.mapName) {
                AppState.availableMaps.add(battleInfo.mapName);
            }
            
            AppState.fileData.push({
                name: file.name,
                content: text,
                battleInfo: battleInfo,
                fileHash: Utils.generateFileHash(file),
                savedPath: savedFile?.file_path
            });
            
        } catch (error) {
            AppState.processedFileHashes.delete(Utils.generateFileHash(file));
            failedFiles.push(file.name);
        }
    },

    readFileAsync(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve(e.target.result);
            reader.onerror = (e) => reject(e);
            reader.readAsText(file);
        });
    },

    finalizeProcessing(errors, failedFiles) {
        const parseErrors = failedFiles.length ? [{ type: 'parse', files: failedFiles }] : [];
        const allErrors = [...errors, ...parseErrors];
        
        ErrorsUI.updateErrorList(allErrors);
        
        if (AppState.fileData.length > 0) {
            AppState.currentMap = 'all';
            StatsCalculator.recalcStats(true);
            FiltersUI.renderFilters();
            UI.hideLoading();
            Renderer.updateDisplay();
            FilesUI.updateFilesList(AppState.fileData, AppState.userSettings);
        } else {
            UI.hideLoading();
            FilesUI.updateFilesList([], AppState.userSettings);
        }
        
        const isEn = AppConstants.LANG === 'en';
        UI.updateFileInfo(AppState.fileData.length ? `${isEn ? 'Files' : 'Файлов'}: ${AppState.fileData.length}` : '');
        UI.checkAndHideContent();
    },

    deleteFile(index) {
        const isEn = AppConstants.LANG === 'en';
        if (!confirm(isEn ? 'Delete this file from the analysis?' : 'Удалить этот файл из анализа?')) return;
        
        const deletedFile = AppState.fileData[index];
        if (deletedFile?.fileHash) {
            AppState.processedFileHashes.delete(deletedFile.fileHash);
        }
        
        AppState.fileData.splice(index, 1);
        AppState.availableMaps.clear();
        AppState.fileData.forEach(file => AppState.availableMaps.add(file.battleInfo.mapName));
        
        StatsCalculator.recalcStats(false);
        FiltersUI.renderFilters();
        Renderer.updateDisplay();
        FilesUI.updateFilesList(AppState.fileData, AppState.userSettings);
        
        UI.updateFileInfo(AppState.fileData.length ? `${isEn ? 'Files' : 'Файлов'}: ${AppState.fileData.length}` : '');
        UI.checkAndHideContent();
        
        if (AppState.fileData.length === 0) {
            ErrorsUI.clearErrors();
        }
    },

    clearAllData() {
        AppState.fileData = [];
        AppState.playersStats.clear();
        AppState.playersInfo.clear();
        AppState.enemyStats.clear();
        AppState.processedFileHashes.clear();
        AppState.availableMaps.clear();
        AppState.expandedPlayer = null;
        AppState.currentMap = 'all';
        AppState.isNewFilesLoaded = false;
        
        StatsCalculator.recalcStats(false);
        FiltersUI.renderFilters();
        Renderer.updateDisplay();
        FilesUI.updateFilesList([], AppState.userSettings);
        UI.updateFileInfo('');
        UI.checkAndHideContent();
        ErrorsUI.clearErrors();
    }
};
