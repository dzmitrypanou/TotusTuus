package by.dzmitrypanou.catholicapp.sync

import android.content.Context
import by.dzmitrypanou.catholicapp.data.PrayerAutoUpdateConsentStore
import by.dzmitrypanou.catholicapp.data.remote.PrayerApiClient
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureTextRepository
import by.dzmitrypanou.catholicapp.ui.scripture.ScriptureTranslationStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONObject
import java.io.File

object ScriptureRemoteSync {

    enum class Result {

        SkippedNoConsent,

        Unchanged,

        DiskCacheUpdated,

        SyncFailed
    }

    private const val PREFS = "scripture_remote_cache"
    private const val KEY_HASH_PREFIX = "hash_"

    fun clearCache(context: Context) {
        val app = context.applicationContext
        app.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit().clear().apply()
        val dir = File(app.filesDir, "scripture_cache")
        runCatching {
            dir.listFiles()?.forEach { file ->
                if (file.isFile) file.delete()
            }
        }
        ScriptureTextRepository.clearMemoryCache()
    }

    fun cacheFile(context: Context, translationId: String): File =
        File(context.filesDir, "scripture_cache/$translationId.json")

    fun getStoredHash(context: Context, translationId: String): String? =
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
            .getString(KEY_HASH_PREFIX + translationId, null)

    private fun setStoredHash(context: Context, translationId: String, hash: String) {
        context.applicationContext.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_HASH_PREFIX + translationId, hash)
            .apply()
    }

suspend fun refreshTranslation(
        context: Context,
        translationId: String,
        forceRefresh: Boolean = false
    ): Result = withContext(Dispatchers.IO) {
        val app = context.applicationContext
        if (!forceRefresh && !PrayerAutoUpdateConsentStore.isGranted(app)) {
            return@withContext Result.SkippedNoConsent
        }

        val remoteHash = runCatching {
            PrayerApiClient.scriptureService.getScriptureContentHash(translationId).hash
        }.getOrNull() ?: return@withContext Result.SyncFailed

        val localHash = getStoredHash(app, translationId)
        val file = cacheFile(app, translationId)
        if (remoteHash == localHash && file.isFile && file.length() > 0L) {
            return@withContext Result.Unchanged
        }

        val body = runCatching {
            PrayerApiClient.scriptureService.getScriptureJson(translationId).string()
        }.getOrNull() ?: return@withContext Result.SyncFailed

        runCatching {
            JSONObject(body).getJSONArray("books")
        }.getOrNull() ?: return@withContext Result.SyncFailed

        file.parentFile?.mkdirs()
        file.writeText(body, Charsets.UTF_8)
        setStoredHash(app, translationId, remoteHash)
        ScriptureTextRepository.clearMemoryCache()
        Result.DiskCacheUpdated
    }

suspend fun downloadIfChanged(context: Context): Boolean {
        val id = ScriptureTranslationStore.getSelectedTranslationId(context.applicationContext)
        return refreshTranslation(context, id, forceRefresh = true) != Result.SyncFailed
    }
}
