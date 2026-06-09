package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class AppVersionDto(
    @SerializedName("version_name") val versionName: String? = null,
    @SerializedName("version_code") val versionCode: Int? = null,
    @SerializedName("play_store_url") val playStoreUrl: String? = null,
    @SerializedName("update_required") val updateRequired: Boolean? = null,
    @SerializedName("message") val message: String? = null,
)
