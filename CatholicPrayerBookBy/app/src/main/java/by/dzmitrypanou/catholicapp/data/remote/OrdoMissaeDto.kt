package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class OrdoMissaeDto(
    @SerializedName("html") val html: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null,
)
