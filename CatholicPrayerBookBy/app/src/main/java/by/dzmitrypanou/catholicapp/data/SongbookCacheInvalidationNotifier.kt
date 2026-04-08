package by.dzmitrypanou.catholicapp.data

import kotlinx.coroutines.channels.BufferOverflow
import kotlinx.coroutines.flow.MutableSharedFlow
import kotlinx.coroutines.flow.asSharedFlow

/**
 * Пасля [SongbookRepository.clearCache] або паспяховай сінхранізацыі — каб экраны спеўніка перачыталі кэш.
 * [updates] — калі фрагмент у STARTED, можна collect і абнавіць UI без сеткавых запытаў з экрана.
 */
object SongbookCacheInvalidationNotifier {

    @Volatile
    private var generation: Long = 0L

    private val _updates = MutableSharedFlow<Unit>(
        extraBufferCapacity = 1,
        onBufferOverflow = BufferOverflow.DROP_OLDEST
    )
    val updates = _updates.asSharedFlow()

    private fun bump() {
        synchronized(this) {
            generation++
        }
        _updates.tryEmit(Unit)
    }

    fun signalCacheCleared() {
        bump()
    }

    fun notifySongbookSyncFinished() {
        bump()
    }

    fun currentGeneration(): Long = generation
}
