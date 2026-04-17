package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class OrdoMissaeVersionDto(
    @SerializedName("updated_at") val updatedAt: String? = null,
)
