<?php
$lang = 'ru';
try {
    require_once __DIR__ . '/includes/lang.php';
    $lang = abs_detect_lang();
} catch (Throwable $e) {
    $lang = 'ru';
}
$pageTitle = $lang === 'en' ? 'ABS Replays Analysis' : 'Анализ АБС реплеев';
$metaDescription = $lang === 'en'
    ? 'Upload .mtreplay and .wotreplay files to analyze team performance, WGSRT, and detailed battle statistics.'
    : 'Загружайте файлы .mtreplay и .wotreplay для анализа статистики команды, WGSRT и детальных метрик боя.';
require __DIR__ . '/includes/site_header.php';
?>

        <div class="upload-area" id="uploadArea">
            <div class="upload-icon">📁</div>
            <div class="upload-text" id="uploadText">
                <?php if ($lang === 'en'): ?>
                    Drag replay files here <span>choose files</span>
                <?php else: ?>
                    Перетащите файлы реплеев сюда <span>выберите файлы</span>
                <?php endif; ?>
            </div>
            <div class="upload-format-hint" id="uploadFormatHint">
                <?php if ($lang === 'en'): ?>
                    Formats: .mtreplay, .wotreplay. Maximum file size: 10 MB.
                <?php else: ?>
                    Форматы: .mtreplay, .wotreplay. Максимальный размер одного файла: 10 МБ.
                <?php endif; ?>
            </div>
            <div class="file-info" id="fileInfo"></div>
            <input type="file" id="fileInput" accept=".mtreplay,.wotreplay" multiple style="display: none;">
        </div>
        <div class="save-replay-consent">
            <label class="save-replay-switch" for="saveReplayConsent">
                <input type="checkbox" id="saveReplayConsent">
                <span class="save-replay-slider"></span>
                <span class="save-replay-switch-text" id="saveReplaySwitchText">
                    <?php echo $lang === 'en'
                        ? 'Save replay copies on the server for diagnostics'
                        : 'Сохранять копии реплеев на сервере для диагностики'; ?>
                </span>
            </label>
            <div class="save-replay-consent-hint" id="saveReplayConsentHint">
                <?php if ($lang === 'en'): ?>
                    By default disabled. Without consent, files are analyzed only in your browser. Files are stored for 30 days.
                    When enabled, no more than 50 replays per batch; if you select more, the upload will be cancelled.
                <?php else: ?>
                    Без согласия файлы анализируются только в браузере. При включённой опции за один раз не более 50 реплеев; если выбрано больше, загрузка не выполняется.
                <?php endif; ?>
            </div>
        </div>

        <div class="loading hidden" id="loading">
            <?php echo $lang === 'en' ? 'Analyzing replays...' : 'Анализ реплеев...'; ?>
        </div>

        <div id="content" class="hidden">
            <div class="files-list" id="filesList"></div>
            <div id="filtersContainer"></div>

            <div class="players-table-container">
                <div class="players-table-title-row">
                    <h3 id="teamStatsTitle"><?php echo $lang === 'en' ? 'Allied team statistics' : 'Статистика команды союзников'; ?></h3>
                    <div class="min-battles-control">
                        <label for="minBattlesInput" id="minBattlesLabel"><?php echo $lang === 'en' ? 'Min. battles' : 'Мин. боёв'; ?></label>
                        <div class="number-wrapper">
                            <input type="number" id="minBattlesInput" class="number-input number-input-small" min="0" step="1" value="1" inputmode="numeric" />
                            <div class="number-controls">
                                <button type="button" class="number-up" id="minBattlesUp"
                                    title="<?php echo $lang === 'en' ? 'Increase' : 'Увеличить'; ?>"
                                    aria-label="<?php echo $lang === 'en' ? 'Increase min battles' : 'Увеличить минимум боёв'; ?>">▲</button>
                                <button type="button" class="number-down" id="minBattlesDown"
                                    title="<?php echo $lang === 'en' ? 'Decrease' : 'Уменьшить'; ?>"
                                    aria-label="<?php echo $lang === 'en' ? 'Decrease min battles' : 'Уменьшить минимум боёв'; ?>">▼</button>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="players-table-wrapper">
                    <table class="players-table" id="playersTable">
                        <thead>
                            <tr>
                                <th data-sort="name"><?php echo $lang === 'en' ? 'Player' : 'Игрок'; ?></th>
                                <th data-sort="battles"><?php echo $lang === 'en' ? 'Battles' : 'Боёв'; ?></th>
                                <th data-sort="damage"><?php echo $lang === 'en' ? 'Avg damage' : 'Ср. урон'; ?></th>
                                <th data-sort="kills"><?php echo $lang === 'en' ? 'Avg kills' : 'Ср. фраги'; ?></th>
                                <th data-sort="assisted"><?php echo $lang === 'en' ? 'Avg assists' : 'Ср. ассист'; ?></th>
                                <th data-sort="survival"><?php echo $lang === 'en' ? 'Survival' : 'Выживаемость'; ?></th>
                                <th data-sort="hitRatio"><?php echo $lang === 'en' ? '% hits' : '% попаданий'; ?></th>
                                <th data-sort="penetrationRatio"><?php echo $lang === 'en' ? '% penetrations' : '% пробитий'; ?></th>
                                <th data-sort="wgs">WGSRT</th>
                             </tr>
                        </thead>
                        <tbody id="playersTableBody">
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="action-buttons">
                <button class="download-btn" id="downloadBtn" title="<?php echo $lang === 'en' ? 'Download table as JPEG' : 'Скачать таблицу как JPEG'; ?>">
                    <i class="fas fa-download"></i> <?php echo $lang === 'en' ? 'Download statistics' : 'Скачать статистику'; ?>
                </button>
                <button class="reset-btn" id="resetBtn">
                    <i class="fas fa-trash-alt"></i> <?php echo $lang === 'en' ? 'Clear all data' : 'Очистить все данные'; ?>
                </button>
            </div>
        </div>

<?php require __DIR__ . '/includes/site_footer.php'; ?>

    <script src="/js/api-config.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/core/constants.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/core/utils.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/core/app-state.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/modules/replay-parser.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/modules/stats-calculator.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/components/errors-ui.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/components/files-ui.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/components/filters-ui.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/components/table-ui.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/modules/file-handler.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/events.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script src="/js/ui/renderer.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
    <script>
        fetch('/api/get_version.php')
            .then(response => response.ok ? response.json() : null)
            .then(data => {
                if (!data || !data.success || !data.version) return;
                const versionEl = document.getElementById('siteVersion');
                if (versionEl) {
                    versionEl.textContent = data.version;
                }
            })
            .catch(() => {});
    </script>
    <script src="/js/main.js?v=<?php echo htmlspecialchars($siteVersion); ?>" defer></script>
</body>
</html>
