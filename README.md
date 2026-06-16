# Totus Tuus

**[Беларуская](README.be.md)** · English

Catholic companion app in Belarusian: prayer book, hymnals, Scripture in Belarusian translations, and a liturgical calendar — in one place, online and offline.

- **Web app:** [app.kasciolhomiel.by](https://app.kasciolhomiel.by)
- **Android:** [Google Play](https://play.google.com/store/apps/details?id=by.totustuus.app)

## Features

| Section | Description |
|--------|-------------|
| **Prayer book** | Structured collection of prayers with search and bookmarks |
| **Songbook & Kantaral** | Liturgical hymns and the Kantaral |
| **Scripture** | Multiple Belarusian (and related) Bible translations, chapter search, reading plan |
| **Liturgical calendar** | Daily liturgical context by diocese |
| **Lectionary** | Readings for the selected day |
| **Solemnities & feasts** | Major solemnities and saints |
| **Ordo Missae** | Order of Mass |

The Android app caches content for offline use. The web app runs in the browser without installation (PWA-friendly).

## Repository layout

```
Totus Tuus/
├── Totus Tuus App/     # Android app (Kotlin)
├── Web App/            # Browser client (HTML, JS, Tailwind CSS)
├── Web Panel/          # PHP admin panel and public API
└── totus-app-version.properties   # Shared version & API key config
```

## Requirements

### Android app

- Android Studio with JDK 17+
- Android SDK 36 (compile), min SDK 24
- `totus-app-version.properties` in the repository root (see the existing file for the format)
- Optional: `Totus Tuus App/local.properties` with `totus.publicApiKey=…` if you do not rely on the shared properties file

Open the `Totus Tuus App` folder in Android Studio and run the `app` module.

### Web app

- Any static web server (Apache, nginx, etc.)
- For live content sync: a running **Web Panel** API (see below)
- Configure `Web App/api-config.js` (`apiBaseUrl`, `webPanelRootUrl`, `useServerProxy`)
- For the API proxy: copy `Web App/api/proxy-secrets.example.php` to `proxy-secrets.php` and set credentials

After changing `versionName` in `totus-app-version.properties`, update `meta name="totus-web-build"` and `?v=` cache-bust query strings in `Web App/index.html` to match.

### Web Panel (backend)

- PHP 8+ with PDO/MySQL
- MySQL/MariaDB database
- Configure database access in `Web Panel/api/db.php`
- Set the public API key via the `TOTUS_PUBLIC_API_KEY` environment variable or `publicApiKey` in `totus-app-version.properties` (must match the Android app and web proxy)

The admin UI manages prayers, Scripture, liturgy, lectionary, songbook, announcements, and related content.

## Privacy

The app does not collect names, email addresses, or phone numbers. Locally stored data includes cached content, user settings, and reading progress. See [Web App/privacy-policy.html](Web%20App/privacy-policy.html).

## License

Source code in this repository is released under [The Unlicense](LICENSE) (public domain). You may use, copy, modify, and distribute it for any purpose without attribution or permission. Bible translations and other liturgical content bundled with the app may remain under separate copyright from their publishers.
