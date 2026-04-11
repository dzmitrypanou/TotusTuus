package by.dzmitrypanou.catholicapp.ui.slideshow

import android.os.Bundle
import android.text.Spannable
import android.text.TextPaint
import android.text.method.LinkMovementMethod
import android.text.style.URLSpan
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.TextView
import androidx.fragment.app.Fragment
import by.dzmitrypanou.catholicapp.BuildConfig
import by.dzmitrypanou.catholicapp.R
import by.dzmitrypanou.catholicapp.databinding.FragmentSlideshowBinding

class SlideshowFragment : Fragment() {

    private var _binding: FragmentSlideshowBinding? = null

    // This property is only valid between onCreateView and
    // onDestroyView.
    private val binding get() = _binding!!

    override fun onCreateView(
        inflater: LayoutInflater,
        container: ViewGroup?,
        savedInstanceState: Bundle?
    ): View {
        _binding = FragmentSlideshowBinding.inflate(inflater, container, false)
        return binding.root
    }

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        binding.textAppVersion.text = getString(R.string.app_version_line, BuildConfig.VERSION_NAME)
        binding.root.post {
            stripUnderlinesFromLinks(binding.infoScreenContactText)
            stripUnderlinesFromLinks(binding.infoScreenWebText)
        }
    }

    override fun onDestroyView() {
        super.onDestroyView()
        _binding = null
    }

    private fun stripUnderlinesFromLinks(textView: TextView) {
        textView.movementMethod = LinkMovementMethod.getInstance()
        val text = textView.text
        if (text !is Spannable) return
        val spans = text.getSpans(0, text.length, URLSpan::class.java)
        for (span in spans) {
            val start = text.getSpanStart(span)
            val end = text.getSpanEnd(span)
            val flags = text.getSpanFlags(span)
            val url = span.url
            text.removeSpan(span)
            text.setSpan(
                object : URLSpan(url) {
                    override fun updateDrawState(ds: TextPaint) {
                        super.updateDrawState(ds)
                        ds.isUnderlineText = false
                    }
                },
                start,
                end,
                flags,
            )
        }
    }
}