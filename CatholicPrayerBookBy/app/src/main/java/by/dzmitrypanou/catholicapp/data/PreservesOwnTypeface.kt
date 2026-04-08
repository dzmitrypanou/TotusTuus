package by.dzmitrypanou.catholicapp.data

/**
 * Пазначае [android.widget.TextView], які сам вызначае шрыфт (напрыклад, бранд «Totus Tuus» у шапцы)
 * і не павінен атрымліваць [AppFontFamilyStore.applyToTextView].
 */
interface PreservesOwnTypeface

/**
 * Загаловак шапкі (не бранд): [AppFontFamilyStore] ставіць Inter medium / serif / mono як у WebApp.
 */
interface UsesToolbarTitleTypeface
