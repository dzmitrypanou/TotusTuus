# Totus Tuus iPhone app

This is a native iPhone wrapper around the existing `WebApp` web application. It uses `WKWebView` and bundles the `WebApp` directory as an Xcode folder resource, so the app can open the same `index.html`, local assets, and bundled Scripture JSON files inside a native iOS shell.

## Requirements

- macOS with Xcode 15 or newer.
- iOS 15.0 or newer deployment target.
- An Apple Developer account for App Store/TestFlight distribution.

## How it works

- `TotusTuusIOS.xcodeproj` includes `../WebApp` as a folder resource.
- `TotusWebSchemeHandler` serves bundled web files through `totusapp://web/...` instead of `file://`.
- `api-config.js` is overridden at runtime for iOS and points directly to `https://api.kasciolhomiel.by/api` using the public API key already present in the repository backend.
- External `http` and `https` links are opened in Safari.

## Build steps

1. Open `TotusTuusIOS/TotusTuusIOS.xcodeproj` in Xcode.
2. Select the `TotusTuusIOS` target.
3. Set your Apple Team in `Signing & Capabilities`.
4. If needed, change the bundle identifier from `by.dzmitrypanou.totustuus` to your registered identifier.
5. Run on an iPhone simulator or device.
6. For distribution, use `Product > Archive` in Xcode.

## Updating the web app inside iOS

Because Xcode references `../WebApp` directly, edits to `WebApp/index.html`, `WebApp/src/main.js`, assets, and JSON bundles are included on the next iOS build without copying files manually.

## Notes

- The iOS project cannot be built from Windows; final compilation and signing must be done on macOS with Xcode.
- App icons in `Assets.xcassets/AppIcon.appiconset` are placeholder metadata. Add real icon PNGs before App Store submission.
- If App Store review requires fully offline dynamic sections, mirror the API endpoints into bundled JSON and adjust the web app data loaders accordingly.
