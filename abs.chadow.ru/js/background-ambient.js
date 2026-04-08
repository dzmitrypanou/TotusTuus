(() => {
    const layer = document.getElementById('ambientBg');
    if (!layer) return;

    const isEn = document.documentElement.lang === 'en';
    const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const maxWidgets = reducedMotion ? 3 : (window.innerWidth < 768 ? 5 : 8);
    const replayParams = [
        { ru: 'Ср. урон', en: 'Avg damage', value: () => `${Math.floor(Math.random() * 2400 + 600)}` },
        { ru: 'Ср. фраги', en: 'Avg kills', value: () => `${(Math.random() * 3.2 + 0.2).toFixed(2)}` },
        { ru: 'Ср. ассист', en: 'Avg assists', value: () => `${(Math.random() * 2.8 + 0.1).toFixed(2)}` },
        { ru: 'Выживаемость', en: 'Survival', value: () => `${(Math.random() * 70 + 20).toFixed(1)}%` },
        { ru: '% попаданий', en: '% hits', value: () => `${(Math.random() * 25 + 65).toFixed(1)}%` },
        { ru: '% пробитий', en: '% penetrations', value: () => `${(Math.random() * 30 + 55).toFixed(1)}%` },
        { ru: 'WGSRT', en: 'WGSRT', value: () => `${Math.floor(Math.random() * 7000 + 1000)}` },
        { ru: 'Карта', en: 'Map', value: () => ['Химмельсдорф', 'Рудники', 'Прохоровка', 'Энск', 'Ласвилль'][Math.floor(Math.random() * 5)] },
        { ru: 'Боёв', en: 'Battles', value: () => `${Math.floor(Math.random() * 45 + 5)}` },
        { ru: '% побед', en: '% wins', value: () => `${(Math.random() * 35 + 45).toFixed(1)}%` }
    ];

    function randomParam(index) {
        return replayParams[index % replayParams.length];
    }

    function randomLeft() {
        return `${Math.random() * 88 + 4}%`;
    }

    function randomTop() {
        return `${Math.random() * 82 + 8}%`;
    }

    function randomCycleMs() {
        return Math.floor(5000 + Math.random() * 25000);
    }

    function scheduleRelocation(item, valueEl, param, baseOpacity) {
        const waitMs = randomCycleMs();
        window.setTimeout(() => {
            item.style.opacity = '0.02';
            window.setTimeout(() => {
                item.style.left = randomLeft();
                item.style.top = randomTop();
                valueEl.textContent = param.value();
                item.style.opacity = String(baseOpacity);
                scheduleRelocation(item, valueEl, param, baseOpacity);
            }, 1300);
        }, waitMs);
    }

    for (let i = 0; i < maxWidgets; i += 1) {
        const param = randomParam(i);
        const item = document.createElement('div');
        item.className = 'ambient-widget';
        item.style.left = randomLeft();
        item.style.top = randomTop();
        item.style.animationDelay = `${Math.random() * 6}s`;
        item.style.animationDuration = `${12 + Math.random() * 12}s`;
        const baseOpacity = reducedMotion ? 0.16 : (0.16 + Math.random() * 0.1);
        item.style.opacity = String(baseOpacity);

        const label = document.createElement('span');
        label.className = 'ambient-widget-label';
        label.textContent = isEn ? param.en : param.ru;

        const value = document.createElement('strong');
        value.className = 'ambient-widget-value';
        value.textContent = param.value();

        item.appendChild(label);
        item.appendChild(value);
        layer.appendChild(item);

        if (!reducedMotion) {
            window.setInterval(() => {
                value.textContent = param.value();
                item.classList.remove('is-updating');
                void item.offsetWidth;
                item.classList.add('is-updating');
            }, 2200 + Math.random() * 2600);

            // Each widget has its own slow random relocation cycle.
            window.setTimeout(() => {
                scheduleRelocation(item, value, param, baseOpacity);
            }, Math.floor(1000 + Math.random() * 7000));
        }
    }
})();
