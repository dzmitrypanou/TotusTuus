package by.dzmitrypanou.catholicapp.data.remote

import by.dzmitrypanou.catholicapp.BuildConfig
import okhttp3.Interceptor
import okhttp3.OkHttpClient
import okhttp3.Request
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.io.File
import java.io.FileOutputStream
import java.util.concurrent.TimeUnit

object PrayerApiClient {
    private const val BASE_URL = "https://api.kasciolhomiel.by/api/"

    /** Той жа ключ, што на серверы (X-Totus-Api-Key). */
    private const val HEADER_API_KEY = "X-Totus-Api-Key"

    private val apiKeyInterceptor = Interceptor { chain ->
        val key = BuildConfig.PUBLIC_API_KEY
        val builder = chain.request().newBuilder()
        if (key.isNotBlank()) {
            builder.header(HEADER_API_KEY, key)
        }
        chain.proceed(builder.build())
    }

    /** Карань сайта для WebView: адносныя `src`/`href` ў HTML з панэлі. */
    val siteOriginForHtml: String
        get() {
            val base = BASE_URL.trimEnd('/')
            val idx = base.lastIndexOf("/api")
            return if (idx >= 0) base.substring(0, idx) + "/" else "$base/"
        }

    /** Адносны шлях ад караня сайта (напрыклад uploads/songbook/12.webp) → поўны URL для загрузкі. */
    fun absoluteUrlForSitePath(path: String): String {
        val p = path.trim()
        if (p.startsWith("http://", ignoreCase = true) || p.startsWith("https://", ignoreCase = true)) {
            return p
        }
        if (p.startsWith("/")) {
            val apiBase = BASE_URL.trimEnd('/')
            val marker = "/api"
            val idx = apiBase.lastIndexOf(marker)
            val panelRoot = if (idx >= 0) apiBase.substring(0, idx) else siteOriginForHtml.trimEnd('/')
            return "$panelRoot$p"
        }
        val origin = siteOriginForHtml.trimEnd('/')
        return "$origin/$p"
    }

    private val sharedHttpClient: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .addInterceptor(apiKeyInterceptor)
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(30, TimeUnit.SECONDS)
            .writeTimeout(30, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()
    }

    /** Вялікія JSON Бібліі — больш таймаўт чытання. */
    private val httpClientLongRead: OkHttpClient by lazy {
        OkHttpClient.Builder()
            .addInterceptor(apiKeyInterceptor)
            .connectTimeout(20, TimeUnit.SECONDS)
            .readTimeout(120, TimeUnit.SECONDS)
            .writeTimeout(120, TimeUnit.SECONDS)
            .retryOnConnectionFailure(true)
            .build()
    }

    val service: PrayerApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(sharedHttpClient)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(PrayerApiService::class.java)
    }

    val scriptureService: ScriptureApiService by lazy {
        Retrofit.Builder()
            .baseUrl(BASE_URL)
            .client(httpClientLongRead)
            .addConverterFactory(GsonConverterFactory.create())
            .build()
            .create(ScriptureApiService::class.java)
    }

    /**
     * Загрузка файлаў медыя спеўніка. OkHttp над надзейней за [java.net.HttpURLConnection]
     * (рэдырэкты, User-Agent, вялікія файлы — даўжэйшы read timeout).
     */
    fun downloadBinaryToFile(url: String, dest: File): Boolean =
        runCatching {
            val reqBuilder = Request.Builder()
                .url(url)
                .header("User-Agent", "TotusTuusAndroid/1.0 (songbook)")
            val k = BuildConfig.PUBLIC_API_KEY
            if (k.isNotBlank()) {
                reqBuilder.header(HEADER_API_KEY, k)
            }
            val req = reqBuilder.build()
            httpClientLongRead.newCall(req).execute().use { resp ->
                if (!resp.isSuccessful) return@runCatching false
                val body = resp.body() ?: return@runCatching false
                dest.parentFile?.mkdirs()
                body.byteStream().use { input ->
                    FileOutputStream(dest).use { out -> input.copyTo(out) }
                }
                true
            }
        }.getOrDefault(false)
}
