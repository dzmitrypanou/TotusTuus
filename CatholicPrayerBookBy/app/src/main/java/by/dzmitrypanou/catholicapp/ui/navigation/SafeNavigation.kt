package by.dzmitrypanou.catholicapp.ui.navigation

import android.os.Bundle
import androidx.annotation.IdRes
import androidx.navigation.NavController
import androidx.navigation.NavOptions
import androidx.navigation.Navigator

fun NavController.navigateSafely(
    @IdRes resId: Int,
    args: Bundle? = null,
    navOptions: NavOptions? = null,
    navigatorExtras: Navigator.Extras? = null
): Boolean {
    val destination = currentDestination ?: return false
    val canNavigate = destination.getAction(resId) != null || runCatching { graph.findNode(resId) != null }.getOrDefault(false)
    if (!canNavigate) return false
    return runCatching {
        navigate(resId, args, navOptions, navigatorExtras)
    }.isSuccess
}
