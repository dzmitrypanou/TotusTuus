const ErrorsUI = {
    updateErrorList(errors) {
        const isEn = AppConstants.LANG === 'en';
        const validErrors = errors.filter(e =>
            (e.files && e.files.length > 0) ||
            (e.type === 'batchLimitAbort' && typeof e.message === 'string' && e.message.length > 0)
        );
        
        const filesListElement = document.getElementById('filesList');
        if (!filesListElement) return;
        
        let wrapper = document.querySelector('.files-error-wrapper');
        
        if (validErrors.length === 0) {
            if (wrapper) {
                wrapper.parentNode.insertBefore(filesListElement, wrapper);
                wrapper.remove();
            }
            if (typeof UI !== 'undefined') {
                UI.checkAndHideContent();
            }
            return;
        }
        
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.className = 'files-error-wrapper';
            
            filesListElement.parentNode.insertBefore(wrapper, filesListElement);
            wrapper.appendChild(filesListElement);
        }
        
        const oldErrorContainer = wrapper.querySelector('#errorContainer');
        if (oldErrorContainer) {
            oldErrorContainer.remove();
        }
        
        const errorContainer = document.createElement('div');
        errorContainer.id = 'errorContainer';
        
        const totalFiles = validErrors.reduce((sum, e) => {
            if (e.type === 'batchLimitAbort') return sum + 1;
            return sum + e.files.length;
        }, 0);
        
        const header = document.createElement('div');
        header.className = 'error-header';
        header.innerHTML = `
            <span>${isEn ? 'Upload errors' : 'Ошибки загрузки'} <span class="badge error-badge">${totalFiles}</span></span>
        `;
        errorContainer.appendChild(header);
        
        const content = document.createElement('div');
        content.className = 'error-content';
        
        const errorFilesList = document.createElement('div');
        errorFilesList.className = 'error-files-list';
        
        validErrors.forEach(error => {
            if (error.type === 'batchLimitAbort' && error.message) {
                const errorText = error.message;
                const fileItem = document.createElement('div');
                fileItem.className = 'error-file-item';
                const fileNameSpan = document.createElement('span');
                fileNameSpan.className = 'error-file-name';
                fileNameSpan.textContent = errorText;
                fileNameSpan.title = errorText;
                const copyBtn = document.createElement('button');
                copyBtn.className = 'error-copy-btn';
                copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                copyBtn.title = isEn ? 'Copy error text' : 'Копировать текст ошибки';
                copyBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    navigator.clipboard.writeText(errorText).then(() => {
                        copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                        copyBtn.classList.add('copied');
                        setTimeout(() => {
                            copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                            copyBtn.classList.remove('copied');
                        }, 1500);
                    }).catch(() => {
                        const textarea = document.createElement('textarea');
                        textarea.value = errorText;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                        copyBtn.classList.add('copied');
                        setTimeout(() => {
                            copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                            copyBtn.classList.remove('copied');
                        }, 1500);
                    });
                });
                fileItem.appendChild(fileNameSpan);
                fileItem.appendChild(copyBtn);
                errorFilesList.appendChild(fileItem);
                return;
            }
            error.files.forEach(file => {
                const fileItem = document.createElement('div');
                fileItem.className = 'error-file-item';
                
                let reason = '';
                switch(error.type) {
                    case 'duplicate':
                        reason = isEn ? 'duplicate upload' : 'повторная загрузка';
                        break;
                    case 'invalid':
                        reason = isEn ? 'invalid file format' : 'неверный формат файла';
                        break;
                    case 'tooLarge':
                        reason = isEn ? 'file larger than 10 MB' : 'файл больше 10 МБ';
                        break;
                    case 'parse':
                        reason = isEn ? 'file is corrupted or not a replay' : 'файл поврежден или не является реплеем';
                        break;
                    default:
                        reason = isEn ? 'unknown error' : 'неизвестная ошибка';
                }
                
                const errorText = isEn
                    ? `${file} was not uploaded because: ${reason}.`
                    : `${file} не был загружен по причине: ${reason}.`;
                
                const fileNameSpan = document.createElement('span');
                fileNameSpan.className = 'error-file-name';
                fileNameSpan.textContent = errorText;
                fileNameSpan.title = errorText;
                
                const copyBtn = document.createElement('button');
                copyBtn.className = 'error-copy-btn';
                copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                copyBtn.title = isEn ? 'Copy error text' : 'Копировать текст ошибки';
                
                copyBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    navigator.clipboard.writeText(errorText).then(() => {
                        copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                        copyBtn.classList.add('copied');
                        setTimeout(() => {
                            copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                            copyBtn.classList.remove('copied');
                        }, 1500);
                    }).catch(err => {
                        const textarea = document.createElement('textarea');
                        textarea.value = errorText;
                        document.body.appendChild(textarea);
                        textarea.select();
                        document.execCommand('copy');
                        document.body.removeChild(textarea);
                        
                        copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                        copyBtn.classList.add('copied');
                        setTimeout(() => {
                            copyBtn.innerHTML = '<i class="fas fa-copy"></i>';
                            copyBtn.classList.remove('copied');
                        }, 1500);
                    });
                });
                
                fileItem.appendChild(fileNameSpan);
                fileItem.appendChild(copyBtn);
                errorFilesList.appendChild(fileItem);
            });
        });
        
        content.appendChild(errorFilesList);
        errorContainer.appendChild(content);
        
        wrapper.appendChild(errorContainer);
        
        if (AppState.fileData.length === 0) {
            filesListElement.style.display = 'none';
        } else {
            filesListElement.style.display = 'block';
        }
        
        if (typeof UI !== 'undefined') {
            UI.checkAndHideContent();
        }
    },

    clearErrors() {
        const wrapper = document.querySelector('.files-error-wrapper');
        if (wrapper) {
            const filesListElement = document.getElementById('filesList');
            wrapper.parentNode.insertBefore(filesListElement, wrapper);
            wrapper.remove();
        }
        
        const filesListElement = document.getElementById('filesList');
        if (filesListElement) {
            filesListElement.style.display = AppState.fileData.length > 0 ? 'block' : 'none';
        }
        
        if (typeof UI !== 'undefined') {
            UI.checkAndHideContent();
        }
    }
};
