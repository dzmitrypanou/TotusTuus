/**
 * Лакальны канфіг. Пры useServerProxy: true ключ X-Totus-Api-Key падстаўляе серверны WebApp/api (з totus-app-version.properties).
 * Прамы доступ да API: apiKey тут павінен супадаць з publicApiKey у totus-app-version.properties у карані рэпа.
 * upstream: proxy-secrets.php (не ў git), глядзі proxy-secrets.example.php; webPanelRootUrl — для медыя спеўніка.
 */
window.API_CONFIG = {
    useServerProxy: true,
    apiBaseUrl: 'auto',
    webPanelRootUrl: 'https://api.kasciolhomiel.by',
};
