package by.dzmitrypanou.catholicapp.data.remote

import com.google.gson.annotations.SerializedName

data class PrayersHashDto(
    @SerializedName("hash") val hash: String
)
