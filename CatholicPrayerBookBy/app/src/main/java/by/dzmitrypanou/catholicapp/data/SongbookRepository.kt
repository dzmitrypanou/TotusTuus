package by.dzmitrypanou.catholicapp.data

import android.content.Context
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.data.remote.SongbookDto
import com.google.gson.GsonBuilder
import com.google.gson.JsonDeserializer
import com.google.gson.reflect.TypeToken
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.async
import kotlinx.coroutines.awaitAll
import kotlinx.coroutines.coroutineScope
import kotlinx.coroutines.currentCoroutineContext
import kotlinx.coroutines.ensureActive
import kotlinx.coroutines.sync.Mutex
import kotlinx.coroutines.sync.Semaphore
import kotlinx.coroutines.sync.withLock
import kotlinx.coroutines.sync.withPermit
import kotlinx.coroutines.withContext
import java.io.File
import java.security.MessageDigest
import java.util.concurrent.atomic.AtomicInteger

class SongbookRepository(
    private val context: Context,
    private val catalog: Catalog = Catalog.SONGBOOK
) {
    data class SyncProgress(val done: Int, val total: Int)

    enum class Catalog {
        SONGBOOK,
        KANTARAL
    }

    private val refreshMutex = Mutex()

    private val appContext = context.applicationContext
    private val gson = GsonBuilder()
        .registerTypeAdapter(
            SongbookContentType::class.java,
            JsonDeserializer { el, _, _ ->
                when (el.asString.uppercase()) {
                    "IMAGE" -> SongbookContentType.IMAGE
                    "PDF" -> SongbookContentType.IMAGE
                    else -> SongbookContentType.TEXT
                }
            }
        )
        .create()
    private val prefs = appContext.getSharedPreferences(catalog.prefsName, Context.MODE_PRIVATE)
    private val rootDir = File(appContext.filesDir, catalog.dirName)
    private val mediaDir = File(rootDir, "media")

    init {
        rootDir.mkdirs()
        mediaDir.mkdirs()
    }

    fun mediaFile(fileName: String): File = File(mediaDir, fileName)

    fun getCachedEntries(): List<SongbookEntry> {
        val json = prefs.getString(KEY_ENTRIES, null) ?: return emptyList()
        val type = object : TypeToken<List<SongbookEntry>>() {}.type
        return gson.fromJson<List<SongbookEntry>>(json, type) ?: emptyList()
    }

    fun getCachedEntriesSorted(): List<SongbookEntry> =
        getCachedEntries().sortedWith(songbookOrderComparator)

    fun getCachedContentHash(): String? = prefs.getString(KEY_HASH, null)

    suspend fun getRemoteContentHashOrNull(allowNetwork: Boolean = false): String? =
        withContext(Dispatchers.IO) {
            if (!allowNetwork) return@withContext null
            runCatching { catalog.remoteHash() }.getOrNull()
        }

    suspend fun isRemoteContentCurrent(
        existingLocal: List<SongbookEntry> = getCachedEntries(),
        allowNetwork: Boolean = false
    ): Boolean = withContext(Dispatchers.IO) {
        if (!allowNetwork || existingLocal.isEmpty()) return@withContext false
        val remoteHash = runCatching { catalog.remoteHash() }.getOrNull()
            ?: return@withContext false
        val cachedHash = prefs.getString(KEY_HASH, null)
        cachedHash == remoteHash && cachedSongbookMediaFilesIntact(existingLocal)
    }

fun clearCache() {
        prefs.edit().clear().apply()
            SongbookBookmarksStore(appContext, catalog).clearAll()
        runCatching {
            mediaDir.listFiles()?.forEach { f ->
                if (f.isFile) f.delete()
            }
            val legacy = File(rootDir, "index.json")
            if (legacy.exists()) legacy.delete()
        }
    }

suspend fun getEntries(
        forceRefresh: Boolean = false,
        allowNetwork: Boolean = false
    ): List<SongbookEntry> = withContext(Dispatchers.IO) {
        val cached = getCachedEntriesSorted()
        if (!forceRefresh || !allowNetwork) {
            return@withContext cached
        }
        refreshFromApi(cached, allowHashShortCircuit = true, allowNetwork = true)
    }

suspend fun refreshFromApi(
        existingLocal: List<SongbookEntry> = getCachedEntries(),
        allowHashShortCircuit: Boolean = true,
        allowNetwork: Boolean = false,
        onProgress: ((SyncProgress) -> Unit)? = null
    ): List<SongbookEntry> = refreshMutex.withLock {
        withContext(Dispatchers.IO) {
        if (!allowNetwork) {
            return@withContext existingLocal.sortedWith(songbookOrderComparator)
        }
        val remoteHash = runCatching {
            catalog.remoteHash()
        }.getOrNull()
        currentCoroutineContext().ensureActive()

        if (allowHashShortCircuit && remoteHash != null) {
            val cachedHash = prefs.getString(KEY_HASH, null)
            if (cachedHash == remoteHash && existingLocal.isNotEmpty()) {
                if (cachedSongbookMediaFilesIntact(existingLocal)) {
                    onProgress?.invoke(SyncProgress(existingLocal.size, existingLocal.size))
                    return@withContext existingLocal.sortedWith(songbookOrderComparator)
                }
            }
        }

        val dtos = runCatching { catalog.remoteEntries() }.getOrElse {
            return@withContext existingLocal.sortedWith(songbookOrderComparator)
        }
        currentCoroutineContext().ensureActive()

        val oldRevisions = readMediaRevisionMap()
        val total = dtos.size.coerceAtLeast(1)
        onProgress?.invoke(SyncProgress(0, total))

        val oldRevReadOnly: Map<String, String> = oldRevisions
        val doneCounter = AtomicInteger(0)
        val mediaSemaphore = Semaphore(MAX_PARALLEL_SONGBOOK_MEDIA_DOWNLOADS)
        val indexedRows = coroutineScope {
            dtos.mapIndexed { idx, dto ->
                async {
                    currentCoroutineContext().ensureActive()
                    mediaSemaphore.withPermit {
                        currentCoroutineContext().ensureActive()
                        val row = syncOneSongbookDto(dto, oldRevReadOnly)
                        currentCoroutineContext().ensureActive()
                        val d = doneCounter.incrementAndGet()
                        onProgress?.invoke(SyncProgress(d, total))
                        idx to row
                    }
                }
            }.awaitAll()
        }
        val orderedRows = indexedRows.sortedBy { it.first }.map { it.second }

        val result = ArrayList<SongbookEntry>(orderedRows.size)
        val newRevisions = HashMap<String, String>()
        val neededMedia = HashSet<String>()
        for (row in orderedRows) {
            result.add(row.entry)
            newRevisions.putAll(row.revisionDeltas)
            row.neededMediaName?.let { neededMedia.add(it) }
        }

        val activeIds = result.map { it.id }.toSet()
        newRevisions.keys.retainAll { it in activeIds }
        for (e in result) {
            if (e.contentType != SongbookContentType.TEXT && e.id !in newRevisions) {
                newRevisions[e.id] = oldRevisions[e.id].orEmpty()
            }
        }

        mediaDir.listFiles()?.forEach { f ->
            if (f.isFile && f.name.startsWith("sb_") && f.name !in neededMedia) {
                f.delete()
            }
        }

        val hashToStore = remoteHash ?: computeEntriesHash(result)
        prefs.edit()
            .putString(KEY_ENTRIES, gson.toJson(result))
            .putString(KEY_HASH, hashToStore)
            .putString(KEY_MEDIA_REV, gson.toJson(newRevisions))
            .apply()

        SongbookBookmarksStore(appContext, catalog).retainOnly(activeIds)
        migrateAwayFromLegacyIndexJson()
        SongbookCacheInvalidationNotifier.notifySongbookSyncFinished()
        result.sortedWith(songbookOrderComparator)
        }
    }

    fun getEntriesByIds(ids: Collection<String>): List<SongbookEntry> {
        if (ids.isEmpty()) return emptyList()
        val order = ids.distinct().withIndex().associate { it.value to it.index }
        return getCachedEntries()
            .filter { it.id in order }
            .sortedBy { order[it.id] ?: Int.MAX_VALUE }
    }

    fun getById(id: String): SongbookEntry? =
        getCachedEntries().firstOrNull { it.id == id }

    private fun migrateAwayFromLegacyIndexJson() {
        val legacy = File(rootDir, "index.json")
        if (legacy.exists()) {
            legacy.delete()
        }
    }

    private fun readMediaRevisionMap(): MutableMap<String, String> {
        val json = prefs.getString(KEY_MEDIA_REV, null) ?: return mutableMapOf()
        val type = object : TypeToken<MutableMap<String, String>>() {}.type
        return gson.fromJson<MutableMap<String, String>>(json, type) ?: mutableMapOf()
    }

    private fun downloadToFile(url: String, dest: File): Boolean =
        PrayerApiClient.downloadBinaryToFile(url, dest)

    private data class SongbookSyncRow(
        val entry: SongbookEntry,
        val revisionDeltas: Map<String, String>,
        val neededMediaName: String?
    )

private fun syncOneSongbookDto(
        dto: SongbookDto,
        oldRevisions: Map<String, String>
    ): SongbookSyncRow {
        when (dto.contentType()) {
            SongbookContentType.TEXT ->
                return SongbookSyncRow(dto.toEntry(null), emptyMap(), null)
            SongbookContentType.IMAGE -> {
                val url = dto.mediaUrl?.trim().orEmpty()
                if (url.isEmpty()) {
                    return SongbookSyncRow(dto.toEntry(null), emptyMap(), null)
                }
                val mediaName = localMediaFileName(dto.id, url)
                val dest = File(mediaDir, mediaName)
                val idStr = dto.id.toString()
                val revRemote = dto.mediaRevision?.trim().orEmpty()
                val revStored = oldRevisions[idStr].orEmpty()
                val needDownload =
                    !dest.exists() || revRemote.isEmpty() || revRemote != revStored
                val deltas = HashMap<String, String>()
                if (needDownload) {
                    val ok = downloadToFile(
                        PrayerApiClient.absoluteUrlForSitePath(url),
                        dest
                    )
                    if (ok) {
                        val rev = revRemote.ifEmpty { sha256File(dest) }
                        deltas[idStr] = rev
                        storeMediaRevisionDelta(idStr, rev)
                    } else if (dest.isFile && dest.length() > 0L) {
                        deltas[idStr] = revStored
                    }
                } else {
                    deltas[idStr] = revRemote.ifEmpty { revStored }
                }
                if (!dest.isFile || dest.length() == 0L) {
                    runCatching { dest.delete() }
                    deltas.remove(idStr)
                    return SongbookSyncRow(dto.toEntry(null), deltas, null)
                }
                return SongbookSyncRow(dto.toEntry(mediaName), deltas, mediaName)
            }
        }
    }

private fun cachedSongbookMediaFilesIntact(entries: List<SongbookEntry>): Boolean {
        for (e in entries) {
            if (e.contentType == SongbookContentType.TEXT) continue
            val name = e.mediaFileName ?: return false
            val f = mediaFile(name)
            if (!f.isFile || f.length() == 0L) return false
        }
        return true
    }

    private fun storeMediaRevisionDelta(id: String, revision: String) {
        if (revision.isBlank()) return
        synchronized(mediaRevisionWriteLock) {
            val revisions = readMediaRevisionMap()
            revisions[id] = revision
            prefs.edit()
                .putString(KEY_MEDIA_REV, gson.toJson(revisions))
                .apply()
        }
    }

    private fun sha256File(file: File): String {
        val md = MessageDigest.getInstance("SHA-256")
        file.inputStream().use { stream ->
            val buf = ByteArray(8192)
            while (true) {
                val n = stream.read(buf)
                if (n <= 0) break
                md.update(buf, 0, n)
            }
        }
        return md.digest().joinToString("") { "%02x".format(it) }
    }

    private fun localMediaFileName(id: Long, mediaUrl: String): String {
        val path = mediaUrl.substringAfterLast('/').substringBefore('?')
        val ext = when {
            path.contains('.') -> path.substringAfterLast('.').lowercase().take(8)
            else -> "jpg"
        }
        val safeExt = ext.filter { it.isLetterOrDigit() }
        val e = if (safeExt.isNotEmpty()) safeExt else "jpg"
        return "${catalog.mediaPrefix}_${id}.$e"
    }

    private fun computeEntriesHash(entries: List<SongbookEntry>): String {
        val payload = entries
            .sortedWith(songbookOrderComparator)
            .joinToString("||") { e ->
                listOf(
                    e.id,
                    e.title,
                    e.categorySortKey(),
                    e.chapterMajor.toString(),
                    e.subchapter?.toString().orEmpty(),
                    e.contentType.name,
                    e.textBody,
                    e.mediaFileName.orEmpty(),
                    e.sortOrder.toString()
                ).joinToString("|")
            }
        val md = MessageDigest.getInstance("SHA-256").digest(payload.toByteArray(Charsets.UTF_8))
        return md.joinToString("") { "%02x".format(it) }
    }

    companion object {
        private const val MAX_PARALLEL_SONGBOOK_MEDIA_DOWNLOADS = 6

        private const val KEY_ENTRIES = "entries_json"
        private const val KEY_HASH = "content_hash"
        private const val KEY_MEDIA_REV = "media_revision_json"

        private val mediaRevisionWriteLock = Any()

        private val songbookOrderComparator = SongbookEntry.DISPLAY_ORDER
    }
}

private val SongbookRepository.Catalog.prefsName: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "songbook_cache"
        SongbookRepository.Catalog.KANTARAL -> "kantaral_cache"
    }

private val SongbookRepository.Catalog.dirName: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "songbook"
        SongbookRepository.Catalog.KANTARAL -> "kantaral"
    }

private val SongbookRepository.Catalog.mediaPrefix: String
    get() = when (this) {
        SongbookRepository.Catalog.SONGBOOK -> "sb"
        SongbookRepository.Catalog.KANTARAL -> "kt"
    }

private suspend fun SongbookRepository.Catalog.remoteHash(): String = when (this) {
    SongbookRepository.Catalog.SONGBOOK -> PrayerApiClient.service.getSongbookContentHash().hash
    SongbookRepository.Catalog.KANTARAL -> PrayerApiClient.service.getKantaralContentHash().hash
}

private suspend fun SongbookRepository.Catalog.remoteEntries(): List<SongbookDto> = when (this) {
    SongbookRepository.Catalog.SONGBOOK -> PrayerApiClient.service.getSongbook()
    SongbookRepository.Catalog.KANTARAL -> PrayerApiClient.service.getKantaral()
}
