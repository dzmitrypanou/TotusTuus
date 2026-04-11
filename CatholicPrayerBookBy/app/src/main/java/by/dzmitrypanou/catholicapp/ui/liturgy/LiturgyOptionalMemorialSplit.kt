package by.dzmitrypanou.catholicapp.ui.liturgy

/**
 * Некалькі даброўных успамінаў у адным радку з API: аднолькавае раскладванне ў календары і на экране дня.
 */
internal object LiturgyOptionalMemorialSplit {

    private val COMBINED_TITLES_REGEX = Regex(
        """\s+(?:альбо|або|или)\s+|[/;\n]+|,\s*(?=(?:Даброўны\s+успамін|Успамін|Урачыстасць|Свята)(?:\s|[—–\-]|$))""",
        RegexOption.IGNORE_CASE
    )

    fun split(combined: String): List<String> {
        val raw = combined.trim()
        if (raw.isEmpty()) return emptyList()
        return raw.split(COMBINED_TITLES_REGEX).map { it.trim() }.filter { it.isNotEmpty() }
    }
}
