package by.dzmitrypanou.catholicapp.ui.scripture

enum class ScriptureReadingPlanKind(val storageKey: String) {
    LINEAR("linear"),
    CHRONOLOGICAL("chronological"),
    MIXED("mixed");

    companion object {
        const val NAV_ARG_PLAN_KIND = "planKind"

        fun fromStorage(key: String?): ScriptureReadingPlanKind =
            entries.firstOrNull { it.storageKey == key } ?: LINEAR
    }
}
