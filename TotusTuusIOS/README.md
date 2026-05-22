# Totus Tuus iPhone app

This is a native iPhone wrapper around the existing `WebApp` web application. It uses `WKWebView` and bundles the `WebApp` directory as an Xcode folder resource, so the app can open the same `index.html`, local assets, and bundled Scripture JSON files inside a native iOS shell.

## Requirements

- macOS with Xcode 15 or newer.
- iOS 15.0 or newer deployment target.
- An Apple Developer account for App Store/TestFlight distribution.

## How it works

- `TotusTuusIOS.xcodeproj` includes `TotusTuusIOS/WebApp` as a folder resource.
- `TotusWebSchemeHandler` serves bundled web files through `totusapp://web/...` instead of `file://`.
- `api-config.js` is overridden at runtime for iOS and points directly to `https://api.kasciolhomiel.by/api` using the public API key already present in the repository backend.
- External `http` and `https` links are opened in Safari.

## Prepare the web bundle before moving to macOS

If you copy only the `TotusTuusIOS` folder to a Mac, Xcode must also have the web files inside `TotusTuusIOS/WebApp`. From the repository root on Windows, run:

```powershell
powershell -ExecutionPolicy Bypass -File .\TotusTuusIOS\prepare-ios-webapp.ps1
```

This copies the existing root `WebApp` directory into `TotusTuusIOS/WebApp`. Then copy the whole `TotusTuusIOS` folder to macOS.

If you prefer not to copy generated files, keep this layout on macOS and run the same copy manually:

```text
Documents/
├── TotusTuusIOS/
│   ├── TotusTuusIOS.xcodeproj
│   └── WebApp/
│       └── index.html
└── WebApp/
    └── index.html
```

## Build steps

1. Open `TotusTuusIOS/TotusTuusIOS.xcodeproj` in Xcode.
2. Select the `TotusTuusIOS` target.
3. Set your Apple Team in `Signing & Capabilities`.
4. The bundle identifier is `by.totustuus.app`.
5. If Xcode was already open before copying the corrected project, close Xcode, reopen the project, then run `Product > Clean Build Folder`.
6. Run on an iPhone simulator or device.
7. For distribution, use `Product > Archive` in Xcode.

If Simulator installation fails with `Missing bundle ID`, delete Xcode DerivedData for this project and build again:

```bash
rm -rf ~/Library/Developer/Xcode/DerivedData/TotusTuusIOS-*
```

## Updating the web app inside iOS

After edits to the root `WebApp/index.html`, `WebApp/src/main.js`, assets, or JSON bundles, run `prepare-ios-webapp.ps1` again so `TotusTuusIOS/WebApp` contains the latest web app before building in Xcode.

## Notes

- The iOS project cannot be built from Windows; final compilation and signing must be done on macOS with Xcode.
- App icons in `Assets.xcassets/AppIcon.appiconset` are placeholder metadata. Add real icon PNGs before App Store submission.
- If Xcode shows `The file “WebApp” couldn’t be opened because there is no such file`, the `TotusTuusIOS/WebApp` folder was not copied/prepared. Run `prepare-ios-webapp.ps1` and copy the complete `TotusTuusIOS` folder to the Mac again.
- If Xcode still shows `/Users/macuser/Documents/WebApp`, it is using an old project copy/cache. Close Xcode, delete the old `TotusTuusIOS` folder on the Mac, copy the corrected folder again, reopen `TotusTuusIOS.xcodeproj`, and clean the build folder.
- If App Store review requires fully offline dynamic sections, mirror the API endpoints into bundled JSON and adjust the web app data loaders accordingly.
