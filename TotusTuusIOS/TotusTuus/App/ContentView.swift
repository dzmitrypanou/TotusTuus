import SwiftUI
import WebKit

struct ContentView: View {
    @StateObject private var store = TotusStore()

    var body: some View {
        NavigationStack {
            HomeScreen()
                .environmentObject(store)
        }
        .tint(.totusAccent)
        .preferredColorScheme(store.colorScheme == .dark ? .dark : .light)
        .task {
            await store.bootstrap()
        }
    }
}

// MARK: - Theme

extension Color {
    static let totusBg = Color(red: 0.055, green: 0.063, blue: 0.125)
    static let totusBg2 = Color(red: 0.09, green: 0.10, blue: 0.18)
    static let totusSurface = Color(red: 0.12, green: 0.14, blue: 0.24)
    static let totusStroke = Color(red: 0.23, green: 0.27, blue: 0.39)
    static let totusAccent = Color(red: 0.58, green: 0.64, blue: 0.85)
    static let totusText = Color(red: 0.94, green: 0.96, blue: 0.98)
    static let totusTextSec = Color(red: 0.72, green: 0.76, blue: 0.85)
}

enum TotusColorScheme: String, CaseIterable, Identifiable {
    case dark
    case light
    var id: String { rawValue }
    var title: String { self == .dark ? "Цёмная" : "Светлая" }
}

struct AppBackground<Content: View>: View {
    @EnvironmentObject private var store: TotusStore
    let content: Content

    init(@ViewBuilder content: () -> Content) {
        self.content = content()
    }

    var body: some View {
        ZStack {
            LinearGradient(
                colors: store.colorScheme == .dark ? [.totusBg, .totusBg2] : [Color(red: 0.94, green: 0.90, blue: 0.84), .white],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
            .ignoresSafeArea()
            content
        }
    }
}

struct Card<Content: View>: View {
    @EnvironmentObject private var store: TotusStore
    let content: Content

    init(@ViewBuilder content: () -> Content) {
        self.content = content()
    }

    var body: some View {
        content
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(store.colorScheme == .dark ? Color.totusSurface : Color.white.opacity(0.84))
            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
            .overlay(
                RoundedRectangle(cornerRadius: 16, style: .continuous)
                    .stroke(store.colorScheme == .dark ? Color.totusStroke : Color.black.opacity(0.12), lineWidth: 1)
            )
    }
}

// MARK: - Models

struct Prayer: Identifiable, Codable, Hashable {
    let id: Int64
    let title: String
    let text: String
    let category: String?
    let subcategory: String?
    let language: String?
    let additionalCategoriesRaw: String?
    let sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, title, text, category, subcategory, language
        case additionalCategoriesRaw = "additional_categories"
        case sortOrder = "sort_order"
    }

    var additionalCategories: [String] {
        (additionalCategoriesRaw ?? "").split(separator: ",").map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
    }
}

struct PrayerCategoryMeta: Identifiable, Codable, Hashable {
    let id: Int64
    let name: String
    let parentId: Int64?
    let sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, name
        case parentId = "parent_id"
        case sortOrder = "sort_order"
    }
}

enum SongbookContentType: String, Codable {
    case text
    case image
}

struct SongbookEntry: Identifiable, Codable, Hashable {
    let id: Int64
    let title: String
    let category: String?
    let chapterMajor: Int
    let subchapter: Int?
    let contentTypeRaw: String
    let text: String?
    let mediaUrl: String?
    let sortOrder: Int?
    let showNumber: Bool?
    let showBadge: Bool?

    enum CodingKeys: String, CodingKey {
        case id, title, category, text
        case chapterMajor = "chapter_major"
        case subchapter
        case contentTypeRaw = "content_type"
        case mediaUrl = "media_url"
        case sortOrder = "sort_order"
        case showNumber = "show_number"
        case showBadge = "show_badge"
    }

    var contentType: SongbookContentType { contentTypeRaw.lowercased() == "image" ? .image : .text }
    var numberPrefix: String { subchapter == nil ? "\(chapterMajor)." : "\(chapterMajor).\(subchapter!)" }
    var listLabel: String {
        if showNumber == false { return title.isEmpty ? numberPrefix : title }
        return title.isEmpty ? numberPrefix : "\(numberPrefix) \(title)"
    }
    var categoryKey: String { (category ?? "").trimmingCharacters(in: .whitespacesAndNewlines) }
}

struct LiturgyCalendarMonth: Codable {
    let year: Int
    let month: Int
    let days: [LiturgyDayCell]
}

struct LiturgyDayCell: Identifiable, Codable, Hashable {
    var id: String { date }
    let date: String
    let day: Int
    let isCurrentMonth: Bool
    let isToday: Bool
    let isImportant: Bool
    let title: String
    let autoTitle: String?
    let liturgicalColor: String
    let liturgicalColorHex: String
    let hasContent: Bool

    enum CodingKeys: String, CodingKey {
        case date, day, title
        case isCurrentMonth = "is_current_month"
        case isToday = "is_today"
        case isImportant = "is_important"
        case autoTitle = "auto_title"
        case liturgicalColor = "liturgical_color"
        case liturgicalColorHex = "liturgical_color_hex"
        case hasContent = "has_content"
    }
}

struct LiturgyDay: Codable, Hashable {
    let date: String?
    let title: String?
    let autoTitle: String?
    let isImportant: Bool?
    let liturgicalColor: String?
    let liturgicalColorHex: String?
    let readings: String?
    let readingsFull: String?

    enum CodingKeys: String, CodingKey {
        case date, title, readings
        case autoTitle = "auto_title"
        case isImportant = "is_important"
        case liturgicalColor = "liturgical_color"
        case liturgicalColorHex = "liturgical_color_hex"
        case readingsFull = "readings_full"
    }
}

struct Solemnity: Identifiable, Codable, Hashable {
    let id: Int64
    let dateLabel: String
    let title: String
    let sectionTitle: String?
    let sortOrder: Int?

    enum CodingKeys: String, CodingKey {
        case id, title
        case dateLabel = "date_label"
        case sectionTitle = "section_title"
        case sortOrder = "sort_order"
    }
}

struct OrdoMissae: Codable, Hashable {
    let html: String?
    let updatedAt: String?

    enum CodingKeys: String, CodingKey {
        case html
        case updatedAt = "updated_at"
    }
}

struct ScriptureTranslation: Identifiable, Hashable {
    let id: String
    let title: String
    let shortTitle: String
    let fileName: String
}

struct ScriptureData: Codable {
    let source: String?
    let books: [ScriptureBook]
}

struct ScriptureBook: Identifiable, Codable, Hashable {
    var id: Int { bookId }
    let bookId: Int
    let bookName: String
    let chapterCount: Int
    let chapters: [ScriptureChapter]

    enum CodingKeys: String, CodingKey {
        case bookId = "book_id"
        case bookName = "book_name"
        case chapterCount = "chapter_count"
        case chapters
    }
}

struct ScriptureChapter: Identifiable, Codable, Hashable {
    var id: Int { chapter }
    let chapter: Int
    let title: String?
    let verses: [ScriptureVerse]
}

struct ScriptureVerse: Identifiable, Codable, Hashable {
    var id: Int { verse }
    let verse: Int
    let text: String
}

struct ScriptureSearchResult: Identifiable, Hashable {
    let book: ScriptureBook
    let chapter: ScriptureChapter
    let verse: ScriptureVerse
    var id: String { "\(book.bookId)-\(chapter.chapter)-\(verse.verse)" }
}

// MARK: - API

actor TotusAPI {
    static let shared = TotusAPI()
    private let base = URL(string: "https://api.kasciolhomiel.by/api")!
    private let apiKey = "1dfd6eaa86797feb6ac4989b9cd705432e81766f27a19730f67240c8360961fa"

    func get<T: Decodable>(_ script: String, query: [URLQueryItem] = []) async throws -> T {
        var components = URLComponents(url: base.appendingPathComponent(script), resolvingAgainstBaseURL: false)!
        components.queryItems = query.isEmpty ? nil : query
        var request = URLRequest(url: components.url!)
        request.setValue("application/json", forHTTPHeaderField: "Accept")
        request.setValue(apiKey, forHTTPHeaderField: "X-Totus-Api-Key")
        let (data, response) = try await URLSession.shared.data(for: request)
        guard let http = response as? HTTPURLResponse, (200..<300).contains(http.statusCode) else {
            throw URLError(.badServerResponse)
        }
        return try JSONDecoder().decode(T.self, from: data)
    }
}

@MainActor
final class TotusStore: ObservableObject {
    @AppStorage("totus_ios_color_scheme") var colorScheme: TotusColorScheme = .dark
    @AppStorage("totus_ios_text_step") var textStep: Int = 0
    @AppStorage("totus_ios_scripture_translation") var selectedTranslationId: String = "catholic_nt"
    @AppStorage("totus_ios_prayer_bookmarks") private var prayerBookmarkRaw = "[]"
    @AppStorage("totus_ios_songbook_bookmarks") private var songBookmarkRaw = "[]"
    @AppStorage("totus_ios_kantaral_bookmarks") private var kantaralBookmarkRaw = "[]"

    @Published var prayers: [Prayer] = []
    @Published var prayerMeta: [PrayerCategoryMeta] = []
    @Published var songbook: [SongbookEntry] = []
    @Published var kantaral: [SongbookEntry] = []
    @Published var ordo: OrdoMissae?
    @Published var isLoading = false
    @Published var lastError: String?

    let translations: [ScriptureTranslation] = [
        .init(id: "catholic_nt", title: "Новы Запавет Рыма-Каталіцкага Касцёла", shortTitle: "BCAT", fileName: "catholic_nt"),
        .init(id: "bokun", title: "Біблія ў перакладзе Антонія Бокуна", shortTitle: "BBB", fileName: "bokun"),
        .init(id: "semiukha", title: "Біблія беларуская ў перакладзе Сёмухі", shortTitle: "BBS", fileName: "semiukha"),
        .init(id: "charniauski_2017", title: "Пераклад Уладзіслава Чарняўскага 2017", shortTitle: "BVC-2017", fileName: "charniauski_2017"),
        .init(id: "stankevich", title: "Сьвятая Бібля у перакладзе Яна Станкевіча", shortTitle: "BJS", fileName: "stankevich"),
        .init(id: "synodal_ru", title: "Синодальный перевод Библии", shortTitle: "SYN", fileName: "synodal_ru")
    ]

    var textScale: CGFloat { 1.0 + CGFloat(max(0, min(4, textStep))) * 0.18 }
    var selectedTranslation: ScriptureTranslation { translations.first { $0.id == selectedTranslationId } ?? translations[0] }

    func bootstrap() async {
        guard prayers.isEmpty && songbook.isEmpty && kantaral.isEmpty else { return }
        await refreshAll()
    }

    func refreshAll() async {
        isLoading = true
        defer { isLoading = false }
        async let prayersTask: [Prayer] = TotusAPI.shared.get("prayers.php")
        async let metaTask: [PrayerCategoryMeta] = TotusAPI.shared.get("prayer_category_meta.php")
        async let songTask: [SongbookEntry] = TotusAPI.shared.get("songbook.php")
        async let kantaralTask: [SongbookEntry] = TotusAPI.shared.get("kantaral.php")
        async let ordoTask: OrdoMissae = TotusAPI.shared.get("ordo_missae.php")
        do {
            prayers = try await prayersTask.sorted { lhs, rhs in
                if (lhs.sortOrder ?? 0) != (rhs.sortOrder ?? 0) { return (lhs.sortOrder ?? 0) < (rhs.sortOrder ?? 0) }
                return lhs.id < rhs.id
            }
            prayerMeta = try await metaTask
            songbook = try await songTask.sorted(by: songSort)
            kantaral = try await kantaralTask.sorted(by: songSort)
            ordo = try await ordoTask
            lastError = nil
        } catch {
            lastError = "Не ўдалося загрузіць даныя: \(error.localizedDescription)"
        }
    }

    func songSort(_ a: SongbookEntry, _ b: SongbookEntry) -> Bool {
        if a.categoryKey != b.categoryKey { return a.categoryKey < b.categoryKey }
        if a.chapterMajor != b.chapterMajor { return a.chapterMajor < b.chapterMajor }
        if (a.subchapter ?? 0) != (b.subchapter ?? 0) { return (a.subchapter ?? 0) < (b.subchapter ?? 0) }
        if (a.sortOrder ?? 0) != (b.sortOrder ?? 0) { return (a.sortOrder ?? 0) < (b.sortOrder ?? 0) }
        return a.id < b.id
    }

    func loadScripture(_ tr: ScriptureTranslation) throws -> ScriptureData {
        guard let url = Bundle.main.url(forResource: tr.fileName, withExtension: "json", subdirectory: "WebApp/assets/scripture") else {
            throw URLError(.fileDoesNotExist)
        }
        let data = try Data(contentsOf: url)
        return try JSONDecoder().decode(ScriptureData.self, from: data)
    }

    func prayerBookmarks() -> Set<Int64> { decodeSet(prayerBookmarkRaw) }
    func songBookmarks(catalog: SongCatalog) -> Set<Int64> { decodeSet(catalog == .kantaral ? kantaralBookmarkRaw : songBookmarkRaw) }

    func togglePrayerBookmark(_ id: Int64) {
        var set = prayerBookmarks()
        if set.contains(id) { set.remove(id) } else { set.insert(id) }
        prayerBookmarkRaw = encodeSet(set)
    }

    func toggleSongBookmark(_ id: Int64, catalog: SongCatalog) {
        var set = songBookmarks(catalog: catalog)
        if set.contains(id) { set.remove(id) } else { set.insert(id) }
        if catalog == .kantaral { kantaralBookmarkRaw = encodeSet(set) } else { songBookmarkRaw = encodeSet(set) }
    }

    private func decodeSet(_ raw: String) -> Set<Int64> {
        guard let data = raw.data(using: .utf8), let values = try? JSONDecoder().decode([Int64].self, from: data) else { return [] }
        return Set(values)
    }

    private func encodeSet(_ set: Set<Int64>) -> String {
        guard let data = try? JSONEncoder().encode(Array(set).sorted()) else { return "[]" }
        return String(data: data, encoding: .utf8) ?? "[]"
    }
}

// MARK: - Home

struct HomeScreen: View {
    @EnvironmentObject private var store: TotusStore

    private let columns = [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)]

    var body: some View {
        AppBackground {
            ScrollView {
                VStack(spacing: 16) {
                    header
                    if let error = store.lastError {
                        Text(error).foregroundStyle(.red).font(.footnote).padding(.horizontal)
                    }
                    LazyVGrid(columns: columns, spacing: 10) {
                        HomeCard(title: "Ordo Missae", image: "ordo_missae_header_image.jpg", destination: OrdoMissaeScreen())
                        HomeCard(title: "Малітоўнік", image: "prayerbook_header_image.jpg", destination: PrayerCategoriesScreen())
                        HomeCard(title: "Літургічны каляндар", image: "liturgy_calendar_header_image.jpg", destination: LiturgyCalendarScreen())
                        HomeCard(title: "Кантарал", image: "kantaral_header_image.jpg", destination: SongbookScreen(catalog: .kantaral))
                        HomeCard(title: "Спеўнік", image: "songbook_header_image.jpg", destination: SongbookScreen(catalog: .songbook))
                        HomeCard(title: "Урачыстасці і святы", image: "solemnities_header_image.jpg", destination: SolemnitiesScreen())
                        HomeCard(title: "Святое Пісанне", image: "scripture_header_bible.jpg", destination: ScriptureScreen())
                        HomeCard(title: "Інфармацыя", image: nil, destination: InfoScreen())
                    }
                    .padding(.horizontal, 10)
                }
                .padding(.bottom, 24)
            }
        }
        .navigationTitle("Totus Tuus")
        .toolbar {
            ToolbarItem(placement: .topBarLeading) {
                NavigationLink(destination: SettingsScreen()) { Image(systemName: "gearshape") }
            }
            ToolbarItem(placement: .topBarTrailing) {
                Button { Task { await store.refreshAll() } } label: { Image(systemName: "arrow.clockwise") }
            }
        }
    }

    private var header: some View {
        HStack(spacing: 12) {
            BundleImage(path: "logo_brand_cross.png")
                .frame(width: 46, height: 46)
            VStack(alignment: .leading, spacing: 2) {
                Text("Totus Tuus")
                    .font(.system(size: 34, weight: .semibold, design: .serif))
                    .foregroundStyle(.totusText)
                Text("Малітвы анлайн і афлайн")
                    .font(.subheadline)
                    .foregroundStyle(.totusTextSec)
            }
            Spacer()
        }
        .padding(.horizontal)
        .padding(.top, 8)
    }
}

struct HomeCard<Destination: View>: View {
    let title: String
    let image: String?
    let destination: Destination

    var body: some View {
        NavigationLink(destination: destination) {
            ZStack(alignment: .bottomLeading) {
                if let image {
                    BundleImage(path: "WebApp/assets/home/\(image)")
                        .frame(height: 132)
                } else {
                    LinearGradient(colors: [.totusSurface, .totusBg2], startPoint: .top, endPoint: .bottom)
                        .frame(height: 132)
                }
                LinearGradient(colors: [.clear, .black.opacity(0.76)], startPoint: .center, endPoint: .bottom)
                Text(title)
                    .font(.headline)
                    .foregroundStyle(.white)
                    .padding(12)
            }
            .clipShape(RoundedRectangle(cornerRadius: 16, style: .continuous))
        }
        .buttonStyle(.plain)
    }
}

struct BundleImage: View {
    let path: String

    var body: some View {
        if let image = loadImage() {
            Image(uiImage: image).resizable().scaledToFill().clipped()
        } else {
            Rectangle().fill(Color.totusSurface)
        }
    }

    private func loadImage() -> UIImage? {
        if let url = Bundle.main.url(forResource: path, withExtension: nil) { return UIImage(contentsOfFile: url.path) }
        return nil
    }
}

// MARK: - Prayers

struct PrayerCategoriesScreen: View {
    @EnvironmentObject private var store: TotusStore
    @State private var search = ""

    var categories: [String] {
        let names = Set(store.prayers.compactMap { $0.category?.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty })
        return names.sorted { $0.localizedCompare($1) == .orderedAscending }
    }

    var body: some View {
        AppBackground {
            List {
                Section {
                    NavigationLink("Пошук у малітоўніку", destination: PrayerSearchScreen())
                    NavigationLink("Выбранае", destination: BookmarkedPrayersScreen())
                }
                Section("Катэгорыі") {
                    ForEach(categories, id: \.self) { cat in
                        NavigationLink(cat, destination: PrayerListScreen(category: cat))
                    }
                }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Малітоўнік")
    }
}

struct PrayerListScreen: View {
    @EnvironmentObject private var store: TotusStore
    let category: String

    var grouped: [(String, [Prayer])] {
        let items = store.prayers.filter { ($0.category ?? "") == category }
        let dict = Dictionary(grouping: items) { ($0.subcategory?.isEmpty == false ? $0.subcategory! : "Без подкатегории") }
        return dict.keys.sorted().map { key in
            let sorted = (dict[key] ?? []).sorted { lhs, rhs in
                if (lhs.sortOrder ?? 0) != (rhs.sortOrder ?? 0) { return (lhs.sortOrder ?? 0) < (rhs.sortOrder ?? 0) }
                return lhs.id < rhs.id
            }
            return (key, sorted)
        }
    }

    var body: some View {
        AppBackground {
            List {
                ForEach(grouped, id: \.0) { sub, prayers in
                    Section(sub) {
                        ForEach(prayers) { prayer in
                            NavigationLink(prayer.title, destination: PrayerDetailScreen(prayer: prayer))
                        }
                    }
                }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle(category)
    }
}

struct PrayerSearchScreen: View {
    @EnvironmentObject private var store: TotusStore
    @State private var query = ""

    var results: [Prayer] {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !q.isEmpty else { return [] }
        return store.prayers.filter { $0.title.lowercased().contains(q) || $0.text.lowercased().contains(q) }
    }

    var body: some View {
        AppBackground {
            List(results) { prayer in
                NavigationLink(prayer.title, destination: PrayerDetailScreen(prayer: prayer))
            }
            .searchable(text: $query, prompt: "Назва або слова з тэксту…")
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Пошук")
    }
}

struct BookmarkedPrayersScreen: View {
    @EnvironmentObject private var store: TotusStore
    var items: [Prayer] { store.prayers.filter { store.prayerBookmarks().contains($0.id) } }

    var body: some View {
        AppBackground {
            List(items) { prayer in
                NavigationLink(prayer.title, destination: PrayerDetailScreen(prayer: prayer))
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Выбранае")
    }
}

struct PrayerDetailScreen: View {
    @EnvironmentObject private var store: TotusStore
    let prayer: Prayer

    var body: some View {
        AppBackground {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text(prayer.title).font(.title2.bold()).foregroundStyle(.totusText)
                    HtmlText(html: prayer.text, scale: store.textScale)
                }
                .padding()
            }
        }
        .navigationTitle("Тэкст малітвы")
        .toolbar {
            Button { store.togglePrayerBookmark(prayer.id) } label: {
                Image(systemName: store.prayerBookmarks().contains(prayer.id) ? "bookmark.fill" : "bookmark")
            }
        }
    }
}

// MARK: - Songbook

enum SongCatalog { case songbook, kantaral }

struct SongbookScreen: View {
    @EnvironmentObject private var store: TotusStore
    let catalog: SongCatalog
    @State private var query = ""

    var title: String { catalog == .kantaral ? "Кантарал" : "Спеўнік" }
    var source: [SongbookEntry] { catalog == .kantaral ? store.kantaral : store.songbook }
    var items: [SongbookEntry] {
        let q = query.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !q.isEmpty else { return source }
        return source.filter { $0.listLabel.lowercased().contains(q) || ($0.text ?? "").lowercased().contains(q) }
    }
    var grouped: [(String, [SongbookEntry])] {
        let dict = Dictionary(grouping: items) { $0.categoryKey.isEmpty ? "Без раздзелу" : $0.categoryKey }
        return dict.keys.sorted().map { ($0, dict[$0] ?? []) }
    }

    var body: some View {
        AppBackground {
            List {
                NavigationLink("Выбранае", destination: BookmarkedSongsScreen(catalog: catalog))
                ForEach(grouped, id: \.0) { group, entries in
                    Section(group) {
                        ForEach(entries) { entry in
                            NavigationLink(entry.listLabel, destination: SongDetailScreen(entry: entry, catalog: catalog))
                        }
                    }
                }
            }
            .searchable(text: $query, prompt: catalog == .kantaral ? "Пошук у кантарале…" : "Пошук у спеўніку…")
            .scrollContentBackground(.hidden)
        }
        .navigationTitle(title)
    }
}

struct BookmarkedSongsScreen: View {
    @EnvironmentObject private var store: TotusStore
    let catalog: SongCatalog
    var items: [SongbookEntry] {
        let source = catalog == .kantaral ? store.kantaral : store.songbook
        let bookmarks = store.songBookmarks(catalog: catalog)
        return source.filter { bookmarks.contains($0.id) }
    }

    var body: some View {
        AppBackground {
            List(items) { entry in
                NavigationLink(entry.listLabel, destination: SongDetailScreen(entry: entry, catalog: catalog))
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Выбранае")
    }
}

struct SongDetailScreen: View {
    @EnvironmentObject private var store: TotusStore
    let entry: SongbookEntry
    let catalog: SongCatalog

    var body: some View {
        AppBackground {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Text(entry.listLabel).font(.title2.bold()).foregroundStyle(.totusText)
                    if entry.contentType == .image, let url = entry.mediaUrl, let remote = URL(string: url) {
                        AsyncImage(url: remote) { phase in
                            switch phase {
                            case .success(let image): image.resizable().scaledToFit()
                            case .failure: Text("Не ўдалося паказаць відарыс").foregroundStyle(.red)
                            default: ProgressView()
                            }
                        }
                    } else {
                        HtmlText(html: entry.text ?? "", scale: store.textScale)
                    }
                }
                .padding()
            }
        }
        .navigationTitle(catalog == .kantaral ? "Кантарал" : "Спеўнік")
        .toolbar {
            Button { store.toggleSongBookmark(entry.id, catalog: catalog) } label: {
                Image(systemName: store.songBookmarks(catalog: catalog).contains(entry.id) ? "bookmark.fill" : "bookmark")
            }
        }
    }
}

// MARK: - Scripture

struct ScriptureScreen: View {
    @EnvironmentObject private var store: TotusStore
    @State private var data: ScriptureData?
    @State private var error: String?

    var body: some View {
        AppBackground {
            List {
                Section {
                    NavigationLink("Пераклады Бібліі", destination: ScriptureTranslationsScreen())
                    NavigationLink("Пошук па Пісанні", destination: ScriptureSearchScreen(data: data))
                }
                if let data {
                    Section(store.selectedTranslation.shortTitle) {
                        ForEach(data.books) { book in
                            NavigationLink("\(book.bookName) • \(book.chapterCount) глаў", destination: ScriptureChaptersScreen(book: book))
                        }
                    }
                } else if let error {
                    Text(error).foregroundStyle(.red)
                } else {
                    ProgressView()
                }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Святое Пісанне")
        .task(id: store.selectedTranslationId) { load() }
    }

    private func load() {
        do { data = try store.loadScripture(store.selectedTranslation); error = nil }
        catch { self.error = "Не ўдалося загрузіць пераклад" }
    }
}

struct ScriptureTranslationsScreen: View {
    @EnvironmentObject private var store: TotusStore
    var body: some View {
        AppBackground {
            List(store.translations) { tr in
                Button { store.selectedTranslationId = tr.id } label: {
                    HStack {
                        VStack(alignment: .leading) { Text(tr.title); Text(tr.shortTitle).font(.caption).foregroundStyle(.secondary) }
                        Spacer()
                        if tr.id == store.selectedTranslationId { Image(systemName: "checkmark") }
                    }
                }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Пераклады Бібліі")
    }
}

struct ScriptureChaptersScreen: View {
    let book: ScriptureBook
    var body: some View {
        AppBackground {
            List(book.chapters) { chapter in
                NavigationLink("Глава \(chapter.chapter)", destination: ScriptureChapterTextScreen(book: book, chapter: chapter))
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle(book.bookName)
    }
}

struct ScriptureChapterTextScreen: View {
    @EnvironmentObject private var store: TotusStore
    let book: ScriptureBook
    let chapter: ScriptureChapter

    var body: some View {
        AppBackground {
            ScrollView {
                VStack(alignment: .leading, spacing: 12) {
                    Text("\(book.bookName) • \(chapter.chapter)").font(.title2.bold()).foregroundStyle(.totusText)
                    ForEach(chapter.verses) { verse in
                        HStack(alignment: .top, spacing: 10) {
                            Text("\(verse.verse)").font(.caption.bold()).foregroundStyle(.totusAccent).frame(width: 30, alignment: .trailing)
                            Text(verse.text).font(.system(size: 17 * store.textScale)).foregroundStyle(.totusText)
                        }
                    }
                }
                .padding()
            }
        }
        .navigationTitle("Тэкст главы")
    }
}

struct ScriptureSearchScreen: View {
    let data: ScriptureData?
    @State private var query = ""

    var results: [ScriptureSearchResult] {
        let q = query.lowercased().trimmingCharacters(in: .whitespacesAndNewlines)
        guard let data, !q.isEmpty else { return [] }
        return data.books.flatMap { book in
            book.chapters.flatMap { chapter in
                chapter.verses
                    .filter { $0.text.lowercased().contains(q) }
                    .map { ScriptureSearchResult(book: book, chapter: chapter, verse: $0) }
            }
        }
    }

    var body: some View {
        AppBackground {
            List(results) { result in
                NavigationLink(destination: ScriptureChapterTextScreen(book: result.book, chapter: result.chapter)) {
                    VStack(alignment: .leading) {
                        Text("\(result.book.bookName) \(result.chapter.chapter):\(result.verse.verse)").font(.headline)
                        Text(result.verse.text).font(.caption).lineLimit(2)
                    }
                }
            }
            .searchable(text: $query, prompt: "Слова для пошуку…")
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Пошук па Пісанні")
    }
}

// MARK: - Calendar / Solemnities / Ordo

struct LiturgyCalendarScreen: View {
    @State private var date = Date()
    @State private var month: LiturgyCalendarMonth?
    @State private var error: String?
    private let calendar = Calendar.current

    var body: some View {
        AppBackground {
            List {
                HStack {
                    Button("←") { shift(-1) }
                    Spacer()
                    Text(monthTitle).font(.headline)
                    Spacer()
                    Button("→") { shift(1) }
                }
                if let month {
                    ForEach(month.days.filter(\.isCurrentMonth)) { day in
                        NavigationLink(destination: LiturgyDayScreen(date: day.date)) {
                            VStack(alignment: .leading) {
                                Text("\(day.day). \(day.title)").font(day.isToday ? .headline.bold() : .headline)
                                Text(day.liturgicalColor).font(.caption).foregroundStyle(.secondary)
                            }
                        }
                    }
                } else if let error {
                    Text(error).foregroundStyle(.red)
                } else { ProgressView() }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Літургічны каляндар")
        .task(id: monthTitle) { await load() }
    }

    private var monthTitle: String {
        let fmt = DateFormatter(); fmt.locale = Locale(identifier: "be_BY"); fmt.dateFormat = "LLLL yyyy"; return fmt.string(from: date)
    }
    private func shift(_ value: Int) { date = calendar.date(byAdding: .month, value: value, to: date) ?? date }
    private func load() async {
        let comps = calendar.dateComponents([.year, .month], from: date)
        do {
            month = try await TotusAPI.shared.get("liturgy_calendar_month.php", query: [.init(name: "year", value: "\(comps.year ?? 2026)"), .init(name: "month", value: "\(comps.month ?? 1)")])
            error = nil
        } catch { error = "Не ўдалося загрузіць каляндар месяца" }
    }
}

struct LiturgyDayScreen: View {
    let date: String
    @State private var day: LiturgyDay?
    @State private var error: String?

    var body: some View {
        AppBackground {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    if let day {
                        Text(day.title ?? day.autoTitle ?? date).font(.title2.bold()).foregroundStyle(.totusText)
                        Text(day.liturgicalColor ?? "").font(.caption).foregroundStyle(.totusTextSec)
                        HtmlText(html: day.readingsFull ?? day.readings ?? "", scale: 1.0)
                    } else if let error { Text(error).foregroundStyle(.red) } else { ProgressView() }
                }.padding()
            }
        }
        .navigationTitle("Лекцыянарый дня")
        .task { await load() }
    }

    private func load() async {
        do { day = try await TotusAPI.shared.get("liturgy_day.php", query: [.init(name: "date", value: date)]); error = nil }
        catch { error = "Не ўдалося загрузіць чытанні дня" }
    }
}

struct SolemnitiesScreen: View {
    @State private var year = Calendar.current.component(.year, from: Date())
    @State private var items: [Solemnity] = []
    @State private var error: String?
    var grouped: [(String, [Solemnity])] {
        let dict = Dictionary(grouping: items) { $0.sectionTitle ?? "Урачыстасці і святы" }
        return dict.keys.sorted().map { ($0, dict[$0] ?? []) }
    }
    var body: some View {
        AppBackground {
            List {
                HStack { Button("←") { year -= 1 }; Spacer(); Text("\(year)").font(.headline); Spacer(); Button("→") { year += 1 } }
                ForEach(grouped, id: \.0) { group, rows in
                    Section(group) { ForEach(rows) { Text("\($0.dateLabel) — \($0.title)") } }
                }
                if let error { Text(error).foregroundStyle(.red) }
            }.scrollContentBackground(.hidden)
        }
        .navigationTitle("Урачыстасці і святы")
        .task(id: year) { await load() }
    }
    private func load() async {
        do { items = try await TotusAPI.shared.get("solemnities.php", query: [.init(name: "year", value: "\(year)")]); error = nil }
        catch { error = "Не ўдалося загрузіць даныя" }
    }
}

struct OrdoMissaeScreen: View {
    @EnvironmentObject private var store: TotusStore
    var body: some View {
        AppBackground {
            if let html = store.ordo?.html { HTMLWebView(html: html) }
            else { ProgressView().task { await store.refreshAll() } }
        }
        .navigationTitle("Ordo Missae")
    }
}

// MARK: - Settings / Info

struct SettingsScreen: View {
    @EnvironmentObject private var store: TotusStore
    var body: some View {
        AppBackground {
            Form {
                Section("Тэкст і шрыфты") {
                    Stepper("Крок \(store.textStep + 1) з 5", value: $store.textStep, in: 0...4)
                    Button("Скінуць налады тэксту") { store.textStep = 0 }
                }
                Section("Колеравая схема") {
                    Picker("Колеравая схема", selection: $store.colorScheme) {
                        ForEach(TotusColorScheme.allCases) { Text($0.title).tag($0) }
                    }
                }
                Section("Даныя") {
                    Button("Абнавіць даныя") { Task { await store.refreshAll() } }
                }
            }
            .scrollContentBackground(.hidden)
        }
        .navigationTitle("Налады")
    }
}

struct InfoScreen: View {
    var body: some View {
        AppBackground {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Card { Text("Totus Tuus - гэта прыкладанне для каталікоў на беларускай мове. Дадатак аб'ядноўвае ў адным месцы малітоўнік, спеўнік, Святое Пісанне і літургічны каляндар, каб патрэбныя тэксты заўсёды былі пад рукой.").foregroundStyle(.totusText) }
                    Card { Text("Просьба пра памылкі ці ідэі пісаць на Email:\ndzmitrypanou@gmail.com").foregroundStyle(.totusText) }
                    Card { Text("Версія: v1.5.0").foregroundStyle(.totusTextSec) }
                }.padding()
            }
        }
        .navigationTitle("Інфармацыя")
    }
}

// MARK: - HTML helpers

struct HtmlText: View {
    let html: String
    let scale: CGFloat

    var body: some View {
        if let attributed = try? AttributedString(markdown: html.strippingHTML) {
            Text(attributed)
                .font(.system(size: 18 * scale))
                .foregroundStyle(.totusText)
                .textSelection(.enabled)
        } else {
            Text(html.strippingHTML).font(.system(size: 18 * scale)).foregroundStyle(.totusText).textSelection(.enabled)
        }
    }
}

extension String {
    var strippingHTML: String {
        var text = replacingOccurrences(of: "<br\\s*/?>", with: "\n", options: .regularExpression)
        text = text.replacingOccurrences(of: "</p>", with: "\n\n", options: .caseInsensitive)
        text = text.replacingOccurrences(of: "<[^>]+>", with: "", options: .regularExpression)
        text = text.replacingOccurrences(of: "&nbsp;", with: " ")
        text = text.replacingOccurrences(of: "&quot;", with: "\"")
        text = text.replacingOccurrences(of: "&amp;", with: "&")
        return text.trimmingCharacters(in: .whitespacesAndNewlines)
    }
}

struct HTMLWebView: UIViewRepresentable {
    let html: String
    func makeUIView(context: Context) -> WKWebView {
        let web = WKWebView()
        web.isOpaque = false
        web.backgroundColor = .clear
        web.scrollView.backgroundColor = .clear
        return web
    }
    func updateUIView(_ webView: WKWebView, context: Context) {
        let page = """
        <html><head><meta name='viewport' content='width=device-width, initial-scale=1.0'><style>body{font-family:-apple-system;color:#f1f5f9;background:#0e1020;line-height:1.45;padding:16px;} a{color:#99f6e4;} details{margin:8px 0;} summary{font-weight:700;}</style></head><body>\(html)</body></html>
        """
        webView.loadHTMLString(page, baseURL: nil)
    }
}

#Preview {
    ContentView()
}

