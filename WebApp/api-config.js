/**
 * Лакальны канфіг. Ключ API — у WebApp/api/proxy-secrets.php (не ў git), глядзі proxy-secrets.example.php.
 * WebPanel на api.kasciolhomiel.by: у proxy-secrets.php задаецца upstream_api_base; webPanelRootUrl — для медыя спеўніка.
 * Лакальна без гэтага URL: закаментуйце webPanelRootUrl.
 */
window.API_CONFIG = {
    useServerProxy: true,
    apiBaseUrl: 'auto',
    webPanelRootUrl: 'https://api.kasciolhomiel.by',
};
