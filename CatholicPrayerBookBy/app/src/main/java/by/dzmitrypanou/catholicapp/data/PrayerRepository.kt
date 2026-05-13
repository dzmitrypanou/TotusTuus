package by.dzmitrypanou.catholicapp.data

import android.content.Context
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import com.google.gson.Gson
import com.google.gson.reflect.TypeToken
import java.security.MessageDigest
import kotlinx.coroutines.async
import kotlinx.coroutines.coroutineScope

class PrayerRepository(context: Context) {

    private val appContext = context.applicationContext
    private val gson = Gson()
    private val prefs = appContext.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
    @Volatile
    private var prayersJsonSnapshot: String? = null
    @Volatile
    private var prayersCache: List<Prayer>? = null
    @Volatile
    private var categoryMetaJsonSnapshot: String? = null
    @Volatile
    private var categoryMetaCache: List<PrayerCategoryMeta>? = null

    suspend fun getPrayers(forceRefresh: Boolean = false): List<Prayer> {
        val cached = getCachedPrayers()
        if (!forceRefresh) {

return cached
        }

        return refreshPrayers(cached, allowHashShortCircuit = false)
    }

suspend fun checkUpdateAvailable(): Boolean {
        val cached = getCachedPrayers()
        if (cached.isEmpty()) return false
        val remoteHash = runCatching {
            PrayerApiClient.service.getPrayersContentHash().hash
        }.getOrNull() ?: return true
        val cachedHash = prefs.getString(KEY_PRAYERS_HASH, null) ?: return true
        return cachedHash != remoteHash
    }

suspend fun refreshPrayers(
        existingLocal: List<Prayer> = getCachedPrayers(),
        allowHashShortCircuit: Boolean = true
    ): List<Prayer> {
        val remoteHashFromApi = runCatching {
            PrayerApiClient.service.getPrayersContentHash().hash
        }.getOrNull()

        if (allowHashShortCircuit && remoteHashFromApi != null) {
            val cachedHash = prefs.getString(KEY_PRAYERS_HASH, null)
            if (cachedHash == remoteHashFromApi && existingLocal.isNotEmpty()) {
                return existingLocal
            }
        }

        val nextList: List<Prayer>
        val categoryMeta: List<PrayerCategoryMeta>
        coroutineScope {
            val prayersJob = async {
                PrayerApiClient.service.getPrayers()
                    .map { it.toDomain() }
                    .distinctBy { it.id }
                    .sortedWith(compareBy({ it.sortOrder }, { it.id }))
            }
            val metaJob = async {
                runCatching {
                    PrayerApiClient.service.getPrayerCategoryMeta().map { it.toDomain() }
                }.getOrElse { getCachedCategoryMeta() }
            }
            nextList = prayersJob.await()
            categoryMeta = metaJob.await()
        }
        PrayerBookmarksStore(appContext).retainOnly(nextList.map { it.id }.toSet())
        val hashToStore = remoteHashFromApi ?: computeHash(nextList, categoryMeta)
        cachePrayers(nextList, hashToStore, categoryMeta)
        return nextList
    }

    fun getCachedPrayers(): List<Prayer> {
        val json = prefs.getString(KEY_PRAYERS, null) ?: return emptyList()
        val cached = prayersCache
        if (cached != null && prayersJsonSnapshot == json) {
            return cached
        }
        val type = object : TypeToken<List<Prayer>>() {}.type
        val parsed = gson.fromJson<List<Prayer>>(json, type) ?: emptyList()
        prayersJsonSnapshot = json
        prayersCache = parsed
        return parsed
    }

    private fun cachePrayers(
        prayers: List<Prayer>,
        hash: String? = null,
        categoryMeta: List<PrayerCategoryMeta>? = null
    ) {
        val meta = categoryMeta ?: getCachedCategoryMeta()
        val effectiveHash = hash ?: computeHash(prayers, meta)
        val editor = prefs.edit()
            .putString(KEY_PRAYERS, gson.toJson(prayers))
            .putString(KEY_PRAYERS_HASH, effectiveHash)
        if (categoryMeta != null) {
            editor.putString(KEY_CATEGORY_META, gson.toJson(categoryMeta))
        }
        editor.apply()
        prayersCache = prayers
        prayersJsonSnapshot = gson.toJson(prayers)
        if (categoryMeta != null) {
            categoryMetaCache = categoryMeta
            categoryMetaJsonSnapshot = gson.toJson(categoryMeta)
        }
    }

    fun getCachedCategoryMeta(): List<PrayerCategoryMeta> {
        val json = prefs.getString(KEY_CATEGORY_META, null) ?: return emptyList()
        val cached = categoryMetaCache
        if (cached != null && categoryMetaJsonSnapshot == json) {
            return cached
        }
        val type = object : TypeToken<List<PrayerCategoryMeta>>() {}.type
        val parsed = gson.fromJson<List<PrayerCategoryMeta>>(json, type) ?: emptyList()
        categoryMetaJsonSnapshot = json
        categoryMetaCache = parsed
        return parsed
    }

    fun clearCache() {
        prefs.edit()
            .remove(KEY_PRAYERS)
            .remove(KEY_PRAYERS_HASH)
            .remove(KEY_CATEGORY_META)
            .apply()
        prayersJsonSnapshot = null
        prayersCache = null
        categoryMetaJsonSnapshot = null
        categoryMetaCache = null
    }

    fun getCategoryNames(): List<String> {
        val prayers = getCachedPrayers()
        val fromPrayers = prayers
            .mapNotNull { it.category?.takeIf { name -> name.isNotBlank() } }
            .distinct()
        val normPrayerRoots = fromPrayers.map { normalize(it) }.toSet()
        val roots = getCachedCategoryMeta()
            .filter { it.parentId == null }
            .sortedWith(compareBy({ it.sortOrder }, { it.id }))
        if (roots.isEmpty()) {
            return fromPrayers.sortedBy { it.lowercase() }
        }
        val seenNorm = mutableSetOf<String>()
        val ordered = mutableListOf<String>()
        for (node in roots) {
            val n = normalize(node.name)
            if (n in normPrayerRoots && n !in seenNorm) {
                ordered.add(fromPrayers.first { normalize(it) == n })
                seenNorm.add(n)
            }
        }
        val orphans = fromPrayers
            .filter { normalize(it) !in seenNorm }
            .sortedBy { it.lowercase() }
        val base = ordered + orphans
        val hasUncategorized = prayers.any { it.category.isNullOrBlank() }
        return if (hasUncategorized) listOf(NO_CATEGORY_TITLE) + base else base
    }

    fun getSubcategoryNames(category: String): List<String> {
        if (category == NO_CATEGORY_TITLE) {
            return emptyList()
        }
        val prayers = getCachedPrayers()
        val parentNorm = normalize(category)
        val fromPrayers = prayers
            .filter { normalize(it.category) == parentNorm }
            .mapNotNull { it.subcategory?.takeIf { name -> name.isNotBlank() } }
            .distinct()
        val normSet = fromPrayers.map { normalize(it) }.toSet()
        val meta = getCachedCategoryMeta()
        val parentNode = meta.firstOrNull { it.parentId == null && normalize(it.name) == parentNorm }
        if (parentNode == null) {
            return fromPrayers.sortedBy { it.lowercase() }
        }
        val children = meta
            .filter { it.parentId == parentNode.id }
            .sortedWith(compareBy({ it.sortOrder }, { it.id }))
        val seenNorm = mutableSetOf<String>()
        val ordered = mutableListOf<String>()
        for (node in children) {
            val n = normalize(node.name)
            if (n in normSet && n !in seenNorm) {
                ordered.add(fromPrayers.first { normalize(it) == n })
                seenNorm.add(n)
            }
        }
        val orphans = fromPrayers
            .filter { normalize(it) !in seenNorm }
            .sortedBy { it.lowercase() }
        return ordered + orphans
    }

    fun getPrayersInSubcategory(category: String, subcategory: String): List<Prayer> {
        if (category == NO_CATEGORY_TITLE) {
            return getCachedPrayers()
                .filter { it.category.isNullOrBlank() }
                .distinctBy { it.id }
                .sortedWith(compareBy({ it.sortOrder }, { it.id }))
        }
        return getCachedPrayers()
            .filter {
                val normalizedCategory = normalize(category)
                val primaryMatch = normalize(it.category) == normalizedCategory
                val additionalMatch = it.additionalCategories.any { addCategory ->
                    normalize(addCategory) == normalizedCategory
                }
                val additionalSubcategoryMatch = subcategory != NO_SUBCATEGORY_TITLE &&
                    it.additionalCategories.any { addCategory ->
                        normalize(addCategory) == normalize(subcategory)
                    }
                val primarySubcategoryMatch = if (subcategory == NO_SUBCATEGORY_TITLE) {
                    it.subcategory.isNullOrBlank()
                } else {
                    normalize(it.subcategory) == normalize(subcategory)
                }
                (primaryMatch && primarySubcategoryMatch) ||
                    (additionalMatch && subcategory == NO_SUBCATEGORY_TITLE) ||
                    additionalSubcategoryMatch
            }
            .distinctBy { it.id }
            .sortedWith(compareBy({ it.sortOrder }, { it.id }))
    }

    fun getPrayersByIds(ids: Collection<Long>): List<Prayer> {
        if (ids.isEmpty()) return emptyList()
        val idSet = ids.toSet()
        return getCachedPrayers()
            .filter { it.id in idSet }
            .distinctBy { it.id }
            .sortedWith(compareBy({ it.sortOrder }, { it.id }))
    }

    private fun computeHash(prayers: List<Prayer>, categories: List<PrayerCategoryMeta> = getCachedCategoryMeta()): String {
        val prayerPart = prayers
            .sortedWith(compareBy({ it.sortOrder }, { it.id }))
            .joinToString("||") { prayer ->
                listOf(
                    prayer.id.toString(),
                    prayer.sortOrder.toString(),
                    prayer.title,
                    prayer.text,
                    prayer.category.orEmpty(),
                    prayer.subcategory.orEmpty(),
                    prayer.language.orEmpty(),
                    prayer.additionalCategories.joinToString("::")
                ).joinToString("|")
            }
        val catPart = categories
            .sortedWith(compareBy({ it.parentId ?: Long.MIN_VALUE }, { it.sortOrder }, { it.id }))
            .joinToString("||") { c ->
                listOf(
                    c.id.toString(),
                    c.name,
                    c.parentId?.toString().orEmpty(),
                    c.sortOrder.toString()
                ).joinToString("|")
            }
        val payload = "$prayerPart::$catPart"
        val md = MessageDigest.getInstance("SHA-256").digest(payload.toByteArray(Charsets.UTF_8))
        return md.joinToString("") { "%02x".format(it) }
    }

    private fun normalize(value: String?): String = value?.trim()?.lowercase().orEmpty()

    companion object {
        private const val PREFS_NAME = "prayer_cache"
        private const val KEY_PRAYERS = "prayers_json"
        private const val KEY_PRAYERS_HASH = "prayers_hash"
        private const val KEY_CATEGORY_META = "prayer_category_meta_json"
        const val NO_SUBCATEGORY_TITLE = "Без подкатегории"

        const val NO_CATEGORY_TITLE = "Без катэгорыі"
    }
}
