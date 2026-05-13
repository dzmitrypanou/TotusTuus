package by.dzmitrypanou.catholicapp.ui.scripture

object ScriptureWordSearch {

    fun wholeWordRegex(query: String): Regex? {
        val trimmed = query.trim()
        if (trimmed.isEmpty()) return null
        val escaped = Regex.escape(trimmed)
        return Regex(
            "(?<![\\p{L}\\p{M}\\p{N}])$escaped(?![\\p{L}\\p{M}\\p{N}])",
            setOf(RegexOption.IGNORE_CASE)
        )
    }

    fun countMatches(text: String, regex: Regex): Int = regex.findAll(text).count()
}

data class ScriptureWordSearchResult(
    val queryDisplay: String,
    val totalOccurrences: Int,
    val versesWithMatches: Int,
    val hits: List<ScriptureWordSearchHit>
)

data class ScriptureWordSearchHit(
    val bookId: Int,
    val bookName: String,
    val chapter: Int,
    val verse: Int,
    val text: String,
    val matchesInVerse: Int
)
