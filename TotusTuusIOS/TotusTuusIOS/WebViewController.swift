import UIKit
import WebKit

final class WebViewController: UIViewController, WKNavigationDelegate, WKUIDelegate {
    private lazy var schemeHandler = TotusWebSchemeHandler()
    private var webView: WKWebView!

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = UIColor(red: 0.055, green: 0.063, blue: 0.125, alpha: 1.0)

        let configuration = WKWebViewConfiguration()
        configuration.setURLSchemeHandler(schemeHandler, forURLScheme: TotusWebSchemeHandler.scheme)
        configuration.allowsInlineMediaPlayback = true
        configuration.defaultWebpagePreferences.allowsContentJavaScript = true

        let preferences = WKPreferences()
        preferences.javaScriptCanOpenWindowsAutomatically = true
        configuration.preferences = preferences

        webView = WKWebView(frame: .zero, configuration: configuration)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.isOpaque = false
        webView.backgroundColor = view.backgroundColor
        webView.scrollView.backgroundColor = view.backgroundColor
        webView.scrollView.contentInsetAdjustmentBehavior = .never
        webView.allowsBackForwardNavigationGestures = true

        view.addSubview(webView)
        webView.translatesAutoresizingMaskIntoConstraints = false
        NSLayoutConstraint.activate([
            webView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: view.trailingAnchor),
            webView.topAnchor.constraint(equalTo: view.topAnchor),
            webView.bottomAnchor.constraint(equalTo: view.bottomAnchor),
        ])

        loadHomePage()
    }

    private func loadHomePage() {
        guard let url = URL(string: "\(TotusWebSchemeHandler.scheme)://web/index.html") else { return }
        webView.load(URLRequest(url: url))
    }

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
    ) {
        guard let url = navigationAction.request.url else {
            decisionHandler(.cancel)
            return
        }

        if url.scheme == TotusWebSchemeHandler.scheme || url.scheme == "about" {
            decisionHandler(.allow)
            return
        }

        if url.scheme == "http" || url.scheme == "https" {
            if navigationAction.navigationType == .linkActivated {
                UIApplication.shared.open(url)
                decisionHandler(.cancel)
                return
            }
            decisionHandler(.allow)
            return
        }

        decisionHandler(.cancel)
    }
}

final class TotusWebSchemeHandler: NSObject, WKURLSchemeHandler {
    static let scheme = "totusapp"

    private let apiConfigJavaScript = """
    window.API_CONFIG = {
        useServerProxy: false,
        apiBaseUrl: 'https://api.kasciolhomiel.by/api',
        webPanelRootUrl: 'https://api.kasciolhomiel.by',
        apiKey: '1dfd6eaa86797feb6ac4989b9cd705432e81766f27a19730f67240c8360961fa'
    };
    """

    func webView(_ webView: WKWebView, start urlSchemeTask: WKURLSchemeTask) {
        guard let url = urlSchemeTask.request.url else {
            fail(urlSchemeTask, code: NSURLErrorBadURL)
            return
        }

        let requestPath = normalizedPath(from: url)
        if requestPath == "api-config.js" {
            respond(
                to: urlSchemeTask,
                url: url,
                data: Data(apiConfigJavaScript.utf8),
                mimeType: "application/javascript"
            )
            return
        }

        guard let resourceURL = bundledWebAppURL(for: requestPath) else {
            fail(urlSchemeTask, code: NSURLErrorFileDoesNotExist)
            return
        }

        do {
            let data = try Data(contentsOf: resourceURL)
            respond(to: urlSchemeTask, url: url, data: data, mimeType: mimeType(for: resourceURL.pathExtension))
        } catch {
            urlSchemeTask.didFailWithError(error)
        }
    }

    func webView(_ webView: WKWebView, stop urlSchemeTask: WKURLSchemeTask) {}

    private func normalizedPath(from url: URL) -> String {
        let path = url.path.trimmingCharacters(in: CharacterSet(charactersIn: "/"))
        return path.isEmpty ? "index.html" : path.removingPercentEncoding ?? path
    }

    private func bundledWebAppURL(for relativePath: String) -> URL? {
        guard let webAppRoot = Bundle.main.url(forResource: "WebApp", withExtension: nil) else {
            return nil
        }

        let candidate = webAppRoot.appendingPathComponent(relativePath, isDirectory: false)
        let resolvedRoot = webAppRoot.standardizedFileURL.path
        let resolvedCandidate = candidate.standardizedFileURL.path
        guard resolvedCandidate.hasPrefix(resolvedRoot) else { return nil }

        var isDirectory: ObjCBool = false
        guard FileManager.default.fileExists(atPath: resolvedCandidate, isDirectory: &isDirectory), !isDirectory.boolValue else {
            return nil
        }
        return candidate
    }

    private func respond(to task: WKURLSchemeTask, url: URL, data: Data, mimeType: String) {
        let response = URLResponse(
            url: url,
            mimeType: mimeType,
            expectedContentLength: data.count,
            textEncodingName: mimeType.hasPrefix("text/") || mimeType.contains("javascript") || mimeType.contains("json") ? "utf-8" : nil
        )
        task.didReceive(response)
        task.didReceive(data)
        task.didFinish()
    }

    private func fail(_ task: WKURLSchemeTask, code: Int) {
        task.didFailWithError(NSError(domain: NSURLErrorDomain, code: code))
    }

    private func mimeType(for ext: String) -> String {
        switch ext.lowercased() {
        case "html", "htm": return "text/html"
        case "css": return "text/css"
        case "js", "mjs": return "application/javascript"
        case "json", "webmanifest": return "application/json"
        case "png": return "image/png"
        case "jpg", "jpeg": return "image/jpeg"
        case "webp": return "image/webp"
        case "svg": return "image/svg+xml"
        case "gif": return "image/gif"
        case "woff": return "font/woff"
        case "woff2": return "font/woff2"
        case "ttf": return "font/ttf"
        case "otf": return "font/otf"
        case "ico": return "image/x-icon"
        default: return "application/octet-stream"
        }
    }
}
