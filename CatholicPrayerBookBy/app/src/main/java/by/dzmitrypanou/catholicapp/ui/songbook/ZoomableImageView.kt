package by.dzmitrypanou.catholicapp.ui.songbook

import android.content.Context
import android.graphics.Matrix
import android.graphics.PointF
import android.graphics.drawable.Drawable
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.ScaleGestureDetector
import androidx.appcompat.widget.AppCompatImageView

class ZoomableImageView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : AppCompatImageView(context, attrs) {

    private val imageMatrixValues = FloatArray(9)
    private val matrix = Matrix()
    private val last = PointF()
    private val start = PointF()
    private val scaleDetector = ScaleGestureDetector(context, ScaleListener())

    private var mode = MODE_NONE
    private var saveScale = 1f
    private var minScale = 1f
    private var maxScale = 4f

    private var viewWidth = 0f
    private var viewHeight = 0f
    private var origWidth = 0f
    private var origHeight = 0f

    init {
        super.setClickable(true)
        scaleType = ScaleType.MATRIX
        imageMatrix = matrix
    }

    fun resetZoom() {
        saveScale = 1f
        fitImageToView()
    }

    override fun setImageDrawable(drawable: Drawable?) {
        super.setImageDrawable(drawable)
        post { resetZoom() }
    }

    override fun onSizeChanged(w: Int, h: Int, oldw: Int, oldh: Int) {
        super.onSizeChanged(w, h, oldw, oldh)
        viewWidth = w.toFloat()
        viewHeight = h.toFloat()
        post { fitImageToView() }
    }

private fun fitImageToView() {
        val drawable = drawable ?: return
        if (viewWidth <= 0f || viewHeight <= 0f) return
        val bmWidth = drawable.intrinsicWidth.toFloat().coerceAtLeast(1f)
        val bmHeight = drawable.intrinsicHeight.toFloat().coerceAtLeast(1f)

        matrix.reset()
        val scale = viewWidth / bmWidth
        matrix.postScale(scale, scale)
        matrix.postTranslate(0f, 0f)

        origWidth = bmWidth * scale
        origHeight = bmHeight * scale
        imageMatrix = matrix
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        scaleDetector.onTouchEvent(event)
        val canDragAtBaseScale = origHeight * saveScale > viewHeight + 1f

        val curr = PointF(event.x, event.y)
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                last.set(curr)
                start.set(last)
                mode = MODE_DRAG
            }
            MotionEvent.ACTION_MOVE -> {
                if (mode == MODE_DRAG && (saveScale > minScale + 0.05f || canDragAtBaseScale)) {
                    val deltaX = curr.x - last.x
                    val deltaY = curr.y - last.y
                    val fixTransX = getFixDragTrans(deltaX, viewWidth, origWidth * saveScale)
                    val fixTransY = getFixDragTrans(deltaY, viewHeight, origHeight * saveScale)
                    matrix.postTranslate(fixTransX, fixTransY)
                    fixTrans()
                    last.set(curr.x, curr.y)
                }
            }
            MotionEvent.ACTION_UP, MotionEvent.ACTION_POINTER_UP -> {
                mode = MODE_NONE
            }
        }

        imageMatrix = matrix

        val shouldDisallowParentIntercept =
            event.pointerCount > 1 ||
                scaleDetector.isInProgress ||
                saveScale > minScale + 0.05f ||
                canDragAtBaseScale
        parent?.requestDisallowInterceptTouchEvent(shouldDisallowParentIntercept)
        return true
    }

    private inner class ScaleListener : ScaleGestureDetector.SimpleOnScaleGestureListener() {
        override fun onScaleBegin(detector: ScaleGestureDetector): Boolean {
            mode = MODE_ZOOM
            return true
        }

        override fun onScale(detector: ScaleGestureDetector): Boolean {
            val prevScale = saveScale
            saveScale = (saveScale * detector.scaleFactor).coerceIn(minScale, maxScale)
            val scaleFactor = saveScale / prevScale

            if (origWidth * saveScale <= viewWidth || origHeight * saveScale <= viewHeight) {
                matrix.postScale(scaleFactor, scaleFactor, viewWidth / 2f, viewHeight / 2f)
            } else {
                matrix.postScale(scaleFactor, scaleFactor, detector.focusX, detector.focusY)
            }
            fixTrans()
            return true
        }
    }

    private fun fixTrans() {
        matrix.getValues(imageMatrixValues)
        val transX = imageMatrixValues[Matrix.MTRANS_X]
        val transY = imageMatrixValues[Matrix.MTRANS_Y]

        val fixTransX = getFixTrans(transX, viewWidth, origWidth * saveScale)
        val fixTransY = fixTransTopAlignY(transY, viewHeight, origHeight * saveScale)
        if (fixTransX != 0f || fixTransY != 0f) {
            matrix.postTranslate(fixTransX, fixTransY)
        }
    }

private fun fixTransTopAlignY(trans: Float, viewSize: Float, contentSize: Float): Float {
        if (contentSize <= viewSize + 0.5f) {
            return -trans
        }
        return getFixTrans(trans, viewSize, contentSize)
    }

    private fun getFixTrans(trans: Float, viewSize: Float, contentSize: Float): Float {
        val minTrans: Float
        val maxTrans: Float
        if (contentSize <= viewSize) {
            minTrans = 0f
            maxTrans = viewSize - contentSize
        } else {
            minTrans = viewSize - contentSize
            maxTrans = 0f
        }
        return when {
            trans < minTrans -> -trans + minTrans
            trans > maxTrans -> -trans + maxTrans
            else -> 0f
        }
    }

    private fun getFixDragTrans(delta: Float, viewSize: Float, contentSize: Float): Float =
        if (contentSize <= viewSize) 0f else delta

    private companion object {
        const val MODE_NONE = 0
        const val MODE_DRAG = 1
        const val MODE_ZOOM = 2
    }
}
