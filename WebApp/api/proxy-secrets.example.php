<?php
/**
 * Скапіюйце ў proxy-secrets.php (не камітуйце ў git).
 * Альнатыва: зменныя асяроддзя TOTUS_PUBLIC_API_KEY і пры неабходнасці TOTUS_UPSTREAM_API_BASE.
 */
return [
    /** Той самы ключ, што public_api_key у WebPanel/api/api_secrets.php */
    'public_api_key' => 'УСТАЎЦЕ_СВАЙ_КЛЮЧ',
    /**
     * Поўны URL каталога API без слеша ў канцы.
     * Калі WebPanel на api.kasciolhomiel.by, а WebApp на іншым хосце/шляху — усталюйце, напрыклад:
     * 'https://api.kasciolhomiel.by/api'
     * Пакіньце null, калі WebApp і WebPanel на адным хосце ў структуры праекта (/WebApp → /WebPanel/api).
     */
    'upstream_api_base' => null,
];
