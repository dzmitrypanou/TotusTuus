<?php
/**
 * Скапіюйце ў proxy-secrets.php (не камітуйце ў git).
 * Альнатыва: зменныя асяроддзя TOTUS_PUBLIC_API_KEY; па змаўчанні ключ бярэцца з publicApiKey у totus-app-version.properties (карань рэпа).
 */
return [
    /** Перавызначэнне; калі пуста — як у totus-app-version.properties */
    'public_api_key' => '',
    /**
     * Поўны URL каталога API без слеша ў канцы.
     * Калі WebPanel на api.kasciolhomiel.by, а WebApp на іншым хосце/шляху — усталюйце, напрыклад:
     * 'https://api.kasciolhomiel.by/api'
     * Пакіньце null, калі WebApp і WebPanel на адным хосце ў структуры праекта (/WebApp → /WebPanel/api).
     */
    'upstream_api_base' => null,
];
