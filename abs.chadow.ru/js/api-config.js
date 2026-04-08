const API = {
    baseUrl: '/api',
    lang: (typeof window !== 'undefined' && window.ABS_LANG === 'en') ? 'en' : 'ru',
    cache: {
        tanks: null,
        tankRows: null,
        maps: null,
        mapRows: null,
        wgsrt: null,
        wgsrtGrades: null,
        nationLabels: null,
        nationLabelsRu: null,
        nationLabelsEn: null,
        tankTypeLabels: null,
        tankTypeLabelsRu: null,
        tankTypeLabelsEn: null,
        pendingAdds: new Set()
    },

    relocalizeCaches() {
        if (Array.isArray(this.cache.tankRows)) {
            const dictionary = {};
            this.cache.tankRows.forEach((tank) => {
                const name = this.lang === 'en'
                    ? (tank.display_name_en || tank.display_name_ru)
                    : tank.display_name_ru;
                const vc = tank.vehicle_code;
                dictionary[vc] = name;
                const norm = this.normalizeVehicleCode(vc);
                if (norm && norm !== vc) {
                    dictionary[norm] = name;
                }
            });
            this.cache.tanks = dictionary;
        }

        if (Array.isArray(this.cache.mapRows)) {
            const dictionary = {};
            this.cache.mapRows.forEach((row) => {
                dictionary[row.map_code] = this.lang === 'en'
                    ? (row.display_name_en || row.display_name_ru)
                    : row.display_name_ru;
            });
            this.cache.maps = dictionary;
        }

        if (this.lang === 'en') {
            this.cache.nationLabels = this.cache.nationLabelsEn || this.cache.nationLabelsRu || this.cache.nationLabels;
            this.cache.tankTypeLabels = this.cache.tankTypeLabelsEn || this.cache.tankTypeLabelsRu || this.cache.tankTypeLabels;
        } else {
            this.cache.nationLabels = this.cache.nationLabelsRu || this.cache.nationLabelsEn || this.cache.nationLabels;
            this.cache.tankTypeLabels = this.cache.tankTypeLabelsRu || this.cache.tankTypeLabelsEn || this.cache.tankTypeLabels;
        }
    },

    normalizeVehicleCode(vehicleCode) {
        if (!vehicleCode || typeof vehicleCode !== 'string') return '';
        const code = vehicleCode.trim();
        if (!code) return '';
        if (code.includes(':')) {
            const idx = code.indexOf(':');
            const nation = code.slice(0, idx).trim().toLowerCase();
            const rest = code.slice(idx + 1).trim();
            return rest === '' ? code : `${nation}:${rest}`;
        }
        const dash = code.indexOf('-');
        if (dash !== -1) {
            const nation = code.slice(0, dash).toLowerCase();
            const rest = code.slice(dash + 1);
            if (rest && /^[a-z][a-z0-9_]*$/.test(nation)) {
                return `${nation}:${rest}`;
            }
        }
        return code;
    },

    /** Код нации из vehicleType (nation:Id или nation-Id) — для ensure_nations */
    extractNationFromVehicleCode(vehicleCode) {
        if (!vehicleCode || typeof vehicleCode !== 'string') return '';
        const c = vehicleCode.trim();
        if (!c) return '';
        const colon = c.indexOf(':');
        if (colon !== -1) {
            return c.slice(0, colon).trim().toLowerCase();
        }
        const dash = c.indexOf('-');
        if (dash !== -1) {
            const nation = c.slice(0, dash).toLowerCase();
            if (/^[a-z][a-z0-9_]*$/.test(nation)) {
                return nation;
            }
        }
        return '';
    },

    async ensureNationsFromVehicleCodes(nationCodes) {
        if (!Array.isArray(nationCodes) || nationCodes.length === 0) {
            return;
        }
        const unique = [...new Set(
            nationCodes
                .map((n) => (typeof n === 'string' ? n.trim().toLowerCase() : ''))
                .filter((n) => n && n !== 'unknown')
        )];
        if (unique.length === 0) {
            return;
        }
        try {
            const response = await fetch(`${this.baseUrl}/ensure_nations.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nations: unique }),
            });
            const data = await response.json();
            const nationKey = this.lang === 'en' ? 'nation_labels_en' : 'nation_labels_ru';
            if (data.success && data[nationKey] && typeof data[nationKey] === 'object') {
                this.cache.nationLabels = data[nationKey];
            } else if (data.success && data.nation_labels && typeof data.nation_labels === 'object') {
                // fallback для старых клиентов
                this.cache.nationLabels = data.nation_labels;
            }
        } catch (e) {
            /* сетевой сбой — реплей всё равно обработаем */
        }
    },

    getSessionId() {
        let sessionId = localStorage.getItem('session_id');
        if (!sessionId) {
            sessionId = 'user_' + Math.random().toString(36).substring(2) + Date.now().toString(36);
            localStorage.setItem('session_id', sessionId);
        }
        return sessionId;
    },

    async loadTankDictionary() {
        if (this.cache.tankRows) {
            this.relocalizeCaches();
            return this.cache.tanks;
        }
        if (this.cache.tanks) return this.cache.tanks;
        try {
            const response = await fetch(`${this.baseUrl}/get_tanks.php`);
            const data = await response.json();
            if (data.success) {
                this.cache.tankRows = Array.isArray(data.data) ? data.data : [];
                const dictionary = {};
                this.cache.tankRows.forEach(tank => {
                    const name = this.lang === 'en'
                        ? (tank.display_name_en || tank.display_name_ru)
                        : tank.display_name_ru;
                    const vc = tank.vehicle_code;
                    dictionary[vc] = name;
                    const norm = this.normalizeVehicleCode(vc);
                    if (norm && norm !== vc) {
                        dictionary[norm] = name;
                    }
                });
                this.cache.tanks = dictionary;
                if (data.nation_labels_ru && typeof data.nation_labels_ru === 'object') {
                    this.cache.nationLabelsRu = data.nation_labels_ru;
                } else if (data.nation_labels && typeof data.nation_labels === 'object') {
                    // fallback for older API versions
                    this.cache.nationLabelsRu = data.nation_labels;
                }
                if (data.nation_labels_en && typeof data.nation_labels_en === 'object') {
                    this.cache.nationLabelsEn = data.nation_labels_en;
                }

                if (data.tank_type_labels_ru && typeof data.tank_type_labels_ru === 'object') {
                    this.cache.tankTypeLabelsRu = data.tank_type_labels_ru;
                } else if (data.tank_type_labels && typeof data.tank_type_labels === 'object') {
                    // fallback for older API versions
                    this.cache.tankTypeLabelsRu = data.tank_type_labels;
                }
                if (data.tank_type_labels_en && typeof data.tank_type_labels_en === 'object') {
                    this.cache.tankTypeLabelsEn = data.tank_type_labels_en;
                }
                this.relocalizeCaches();
                return dictionary;
            }
            return null;
        } catch (error) {
            return null;
        }
    },

    async loadMapDictionary() {
        if (this.cache.mapRows) {
            this.relocalizeCaches();
            return this.cache.maps;
        }
        if (this.cache.maps) return this.cache.maps;
        try {
            const response = await fetch(`${this.baseUrl}/get_maps.php`);
            const data = await response.json();
            if (data.success && Array.isArray(data.data)) {
                this.cache.mapRows = data.data;
                const dictionary = {};
                this.cache.mapRows.forEach(row => {
                    dictionary[row.map_code] = this.lang === 'en'
                        ? (row.display_name_en || row.display_name_ru)
                        : row.display_name_ru;
                });
                this.cache.maps = dictionary;
                this.relocalizeCaches();
                return dictionary;
            }
        } catch (e) {}
        this.cache.maps = {};
        return this.cache.maps;
    },

    getMapDisplayName(mapCode) {
        if (!mapCode) return this.lang === 'en' ? 'Unknown' : 'Неизвестно';
        const m = this.cache.maps;
        if (m && m[mapCode]) return m[mapCode];
        return mapCode;
    },

    getNationDisplayName(nationCode) {
        if (!nationCode) return '';
        const m = this.cache.nationLabels;
        if (m && m[nationCode]) return m[nationCode];
        return nationCode;
    },

    getTankTypeDisplayName(typeCode) {
        if (!typeCode) return '';
        const m = this.cache.tankTypeLabels;
        if (m && m[typeCode]) return m[typeCode];
        return typeCode;
    },

    async ensureMapFromReplay(mapCode, suggestedDisplay) {
        if (!mapCode) return;
        try {
            const response = await fetch(`${this.baseUrl}/ensure_map.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    map_code: mapCode,
                    suggested_display: typeof suggestedDisplay === 'string' ? suggestedDisplay : '',
                    suggested_display_en: typeof suggestedDisplay === 'string' ? suggestedDisplay : ''
                })
            });
            const data = await response.json();
            const pickedDisplay = this.lang === 'en' ? data.display_name_en : data.display_name_ru;
            if (data.success && data.map_code && pickedDisplay) {
                if (!this.cache.maps) this.cache.maps = {};
                this.cache.maps[data.map_code] = pickedDisplay;
            }
        } catch (e) {}
    },

    async loadWgsrtCoefficients() {
        if (this.cache.wgsrt) return this.cache.wgsrt;
        try {
            const response = await fetch(`${this.baseUrl}/get_wgsrt_coefficients.php`);
            const data = await response.json();
            if (data.success) {
                const coefficients = {};
                data.coefficients.forEach(coef => {
                    coefficients[coef.parameter_name] = {
                        value: parseFloat(coef.coefficient_value),
                        min: coef.min_value,
                        max: coef.max_value,
                        norm: parseFloat(coef.normalization_factor)
                    };
                });
                this.cache.wgsrt = coefficients;
                return coefficients;
            }
            return null;
        } catch (error) {
            return null;
        }
    },

    async loadWgsrtGrades() {
        if (this.cache.wgsrtGrades) return this.cache.wgsrtGrades;
        try {
            const response = await fetch(`${this.baseUrl}/get_wgsrt_grades.php`);
            const data = await response.json();
            if (data.success) {
                this.cache.wgsrtGrades = data.grades;
                return data.grades;
            }
            return null;
        } catch (error) {
            return null;
        }
    },

    getWgsrtGrade(wgsrtValue) {
        if (!this.cache.wgsrtGrades) return null;
        for (const grade of this.cache.wgsrtGrades) {
            if (wgsrtValue >= grade.min_value && wgsrtValue <= grade.max_value) {
                return grade;
            }
        }
        return null;
    },

    getWgsrtColor(wgsrtValue) {
        const grade = this.getWgsrtGrade(wgsrtValue);
        return grade ? grade.color : '#ffffff';
    },

    getWgsrtGradeClass(wgsrtValue) {
        const grade = this.getWgsrtGrade(wgsrtValue);
        return grade ? `wgsrt-${grade.grade_code}` : '';
    },

    async addNewTank(vehicleCode, displayName, tankType = 'unknown', tier = 8, isPremium = false, isCollectible = false) {
        const normalized = this.normalizeVehicleCode(vehicleCode);
        if (!this.cache.tanks) await this.loadTankDictionary();
        if (this.cache.tanks && normalized && this.cache.tanks[normalized]) return true;
        if (this.cache.tanks && vehicleCode && this.cache.tanks[vehicleCode]) return true;

        const pendingKey = `${normalized || vehicleCode}_${displayName}`;
        if (this.cache.pendingAdds.has(pendingKey)) return false;
        
        this.cache.pendingAdds.add(pendingKey);
        
        try {
            const response = await fetch(`${this.baseUrl}/add_tank.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    vehicle_code: normalized || vehicleCode,
                    display_name: displayName,
                    tank_type: tankType,
                    tier: tier,
                    is_premium: isPremium,
                    is_collectible: isCollectible,
                    source_file: window.location.pathname
                })
            });
            
            const data = await response.json();
            this.cache.pendingAdds.delete(pendingKey);
            
            if (data.success && this.cache.tanks) {
                this.cache.tanks[normalized || vehicleCode] = displayName;
                if (vehicleCode && vehicleCode !== normalized) {
                    this.cache.tanks[vehicleCode] = displayName;
                }
            }
            return data.success;
        } catch (error) {
            this.cache.pendingAdds.delete(pendingKey);
            return false;
        }
    },

    async saveReplayFile(file, battleInfo, hasConsent = false) {
        if (!hasConsent) {
            return null;
        }

        try {
            const fileContent = await this.readFileAsBase64(file);
            
            let playerName = battleInfo?.battleData?.playerName || 'unknown';
            playerName = playerName.replace(/[^a-zA-Z0-9_\-]/g, '_');
            if (playerName.length < 1) {
                playerName = 'unknown';
            }
            
            let mapName = battleInfo?.mapName || 'unknown';
            mapName = mapName.replace(/[^a-zA-Z0-9_\-]/g, '_');
            if (mapName.length < 1) {
                mapName = 'unknown';
            }
            
            let dateTime = battleInfo?.dateTime;
            if (!dateTime) {
                dateTime = new Date().toISOString();
            }
            
            const response = await fetch(`${this.baseUrl}/save_wgsrt_grades.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    file_content: fileContent,
                    file_name: file.name,
                    player_name: playerName,
                    map_name: mapName,
                    date_time: dateTime,
                    consent_to_store: true
                })
            });
            
            const data = await response.json();
            return data.success ? data : null;
            
        } catch (error) {
            return null;
        }
    },

    readFileAsBase64(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => {
                const base64 = reader.result.split(',')[1];
                resolve(base64);
            };
            reader.onerror = reject;
            reader.readAsDataURL(file);
        });
    },

    getTankName(vehicleCode, defaultValue = null) {
        const norm = this.normalizeVehicleCode(vehicleCode);
        if (this.cache.tanks && norm && this.cache.tanks[norm]) {
            return this.cache.tanks[norm];
        }
        if (this.cache.tanks && vehicleCode && this.cache.tanks[vehicleCode]) {
            return this.cache.tanks[vehicleCode];
        }
        if (defaultValue !== null && defaultValue !== undefined) {
            return defaultValue;
        }
        return this.extractTankNameFromCode(vehicleCode);
    },

    extractTankNameFromCode(vehicleCode) {
        if (!vehicleCode) return this.lang === 'en' ? 'Unknown' : 'Неизвестно';
        const normalized = this.normalizeVehicleCode(vehicleCode);
        const parts = normalized.split(':');
        let name = parts.pop() || normalized;
        name = name.replace(/_/g, ' ');
        name = name.replace(/([A-Z])/g, ' $1').trim();
        name = name.replace(/(\d+)/g, ' $1').trim();
        return name || (this.lang === 'en' ? 'Unknown' : 'Неизвестно');
    },

    determineTankType(vehicleCode) {
        const code = this.normalizeVehicleCode(vehicleCode || '').toLowerCase();
        if (code.includes('heavy') || code.includes('kv') || code.includes('is-') || code.includes('maus')) return 'heavy';
        if (code.includes('medium') || code.includes('t-') || code.includes('panzer')) return 'medium';
        if (code.includes('light') || code.includes('lt') || code.includes('elc')) return 'light';
        if (code.includes('td') || code.includes('skorp') || code.includes('waffent')) return 'td';
        if (code.includes('spg') || code.includes('arty')) return 'spg';
        return 'unknown';
    },

    determineTier(vehicleCode) {
        const vc = this.normalizeVehicleCode(vehicleCode || '');
        const match = vc.match(/[_-](\d+)[_-]/);
        if (match && match[1]) {
            const tier = parseInt(match[1]);
            if (tier >= 1 && tier <= 10) return tier;
        }
        if (vc.includes('IS-7') || vc.includes('Maus')) return 10;
        if (vc.includes('IS-3') || vc.includes('T54')) return 8;
        return 8;
    },

    getWgsrtCoefficients() {
        return this.cache.wgsrt || this.getDefaultWgsrtCoefficients();
    },

    getDefaultWgsrtCoefficients() {
        return {
            damage: { value: 0.30, norm: 3000 },
            kills: { value: 0.15, norm: 2 },
            assisted: { value: 0.15, norm: 1500 },
            received: { value: 0.10, norm: 2000 },
            survival: { value: 0.10, norm: 1 },
            hitRatio: { value: 0.05, norm: 1 },
            penRatio: { value: 0.05, norm: 1 },
            spots: { value: 0.05, norm: 2 },
            winRate: { value: 0.05, norm: 1 }
        };
    }
};