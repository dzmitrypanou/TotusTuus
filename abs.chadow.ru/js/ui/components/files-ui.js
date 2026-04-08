const FilesUI = {
    updateFilesList(fileData, userSettings) {
        const filesList = document.getElementById('filesList');
        if (!filesList) return;

        const isEn = AppConstants.LANG === 'en';
        
        if (fileData.length === 0) {
            filesList.innerHTML = '';
            filesList.style.display = 'none';
            return;
        }
        
        filesList.style.display = 'block';
        
        filesList.innerHTML = `
            <div class="files-header">
                <span>${isEn ? 'Loaded files' : 'Загруженные файлы'} <span class="badge">${fileData.length}</span></span>
            </div>
            <div class="files-content">
                <div class="files-list-container">
                    ${fileData.map((file, index) => `
                        <div class="file-item">
                            <span class="file-name" title="${file.name}">${file.name}</span>
                            <span class="file-map" title="${isEn ? 'Map: ' : 'Карта: '}${API.getMapDisplayName(file.battleInfo.mapName)}${API.getMapDisplayName(file.battleInfo.mapName) !== file.battleInfo.mapName ? ' (' + file.battleInfo.mapName + ')' : ''}">${API.getMapDisplayName(file.battleInfo.mapName)}</span>
                            <button class="delete-file" data-index="${index}" title="${isEn ? 'Delete file' : 'Удалить файл'}">✕</button>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;

        filesList.querySelectorAll('.delete-file').forEach(btn => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const index = parseInt(e.currentTarget.dataset.index);
                FileHandler.deleteFile(index);
            });
        });
    }
};