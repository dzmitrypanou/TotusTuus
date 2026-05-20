# Totus Tuus iOS

Гэта iPhone/iPad версія Totus Tuus на базе SwiftUI. Асноўныя экраны перанесены ў натыўны iOS UI: галоўны экран, малітоўнік, пошук і закладкі, спеўнік/кантарал, Святое Пісанне з лакальных JSON, літургічны каляндар, урачыстасці, Ordo Missae, налады і інфармацыя.

## Як адкрыць у Xcode

1. Скапіруйце папку `TotusTuusIOS` на Mac.
2. Адкрыйце `TotusTuusIOS/TotusTuus.xcodeproj` у Xcode.
3. У `Signing & Capabilities` выберыце свой `Team`.
4. Калі трэба, змяніце `Bundle Identifier` з `by.totustuus.ios` на свой.
5. Запусціце на iPhone simulator або рэальнай прыладзе.

## Што ўжо ўключана

- Натыўны SwiftUI інтэрфейс у `TotusTuus/App/ContentView.swift`.
- Лакальныя JSON-пераклады Святога Пісання ў `WebApp/assets/scripture`.
- Лакальныя выявы галоўнага экрана ў `WebApp/assets/home`.
- API настроены на прамы доступ да `https://api.kasciolhomiel.by/api` праз Swift `URLSession`.
- Версія: `1.5.0`, build: `47`.

## Адрозненні ад Android

Натыўны iOS порт рэалізуе асноўную логіку і экраны. Android-спецыфічныя `WorkManager`, Android notification channels і некаторыя дробныя toolbar-анімацыі не маюць прамога 1:1 эквіваленту і патрабуюць асобнай iOS-рэалізацыі праз `BGTaskScheduler`/`UNUserNotificationCenter`, калі гэта будзе патрэбна для App Store версіі.

## Важна

Для App Store лепш замяніць `Assets.xcassets/AppIcon.appiconset/ic_launcher.png` на поўны iOS App Icon 1024×1024 без празрыстасці. Цяпер выкарыстаны Android-значок як стартовы варыянт.

