import UIKit

final class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    func scene(
        _ scene: UIScene,
        willConnectTo session: UISceneSession,
        options connectionOptions: UIScene.ConnectionOptions
    ) {
        guard let windowScene = scene as? UIWindowScene else { return }

        let window = UIWindow(windowScene: windowScene)
        window.rootViewController = WebViewController()
        window.tintColor = UIColor(red: 0.78, green: 0.63, blue: 0.33, alpha: 1.0)
        self.window = window
        window.makeKeyAndVisible()
    }
}
