package by.dzmitrypanou.catholicapp.data.remote

import okhttp3.ResponseBody
import retrofit2.http.GET
import retrofit2.http.Query

interface ScriptureApiService {
    @GET("scripture_hash.php")
    suspend fun getScriptureContentHash(@Query("translation") translationId: String): PrayersHashDto

    @GET("scripture.php")
    suspend fun getScriptureJson(@Query("translation") translationId: String): ResponseBody
}
