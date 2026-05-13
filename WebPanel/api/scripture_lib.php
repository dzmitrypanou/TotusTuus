<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function scriptureEnsureSchema(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS scripture_translation_meta (
            id VARCHAR(32) NOT NULL PRIMARY KEY,
            title VARCHAR(512) NOT NULL,
            description TEXT NULL,
            source_url VARCHAR(512) NULL,
            sort_order INT NOT NULL DEFAULT 0
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS scripture_books (
            translation_id VARCHAR(32) NOT NULL,
            book_id INT NOT NULL,
            book_name VARCHAR(255) NOT NULL,
            chapter_count INT NOT NULL,
            sort_index INT NOT NULL DEFAULT 0,
            PRIMARY KEY (translation_id, book_id),
            KEY idx_tr_sort (translation_id, sort_index)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS scripture_chapter_meta (
            translation_id VARCHAR(32) NOT NULL,
            book_id INT NOT NULL,
            chapter INT NOT NULL,
            title VARCHAR(512) NULL,
            paragraphs_json JSON NULL,
            PRIMARY KEY (translation_id, book_id, chapter)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );

    db()->exec(
        'CREATE TABLE IF NOT EXISTS scripture_verses (
            translation_id VARCHAR(32) NOT NULL,
            book_id INT NOT NULL,
            chapter INT NOT NULL,
            verse INT NOT NULL,
            text LONGTEXT NOT NULL,
            PRIMARY KEY (translation_id, book_id, chapter, verse),
            KEY idx_tr_book (translation_id, book_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

function scriptureEnsureAllTranslationMeta(): void
{
    $rows = [
        ['catholic_nt', 'Новы Запавет Рыма-Каталіцкага Касцёла', 1],
        ['bokun', 'Біблія ў перакладзе Антонія Бокуна', 2],
        ['semiukha', 'Біблія беларуская ў перакладзе Сёмухі', 3],
        ['charniauski_2017', 'Пераклад Уладзіслава Чарняўскага 2017', 4],
        ['stankevich', 'Сьвятая Бібля у перакладзе Яна Станкевіча', 5],
        ['synodal_ru', 'Синодальный перевод Библии', 6],
    ];

    $ins = db()->prepare(
        'INSERT INTO scripture_translation_meta (id, title, description, source_url, sort_order)
         VALUES (:id, :title, \'\', NULL, :sort_order)
         ON DUPLICATE KEY UPDATE
           title = VALUES(title),
           sort_order = VALUES(sort_order)'
    );
    foreach ($rows as $r) {
        $ins->execute([
            ':id' => $r[0],
            ':title' => $r[1],
            ':sort_order' => $r[2],
        ]);
    }
}

function scriptureDecodeJsonFile(string $json): ?array
{
    try {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : null;
    } catch (Throwable $e) {
        return null;
    }
}

function scriptureImportFromArray(string $translationId, array $root): void
{
    if (!isset($root['books']) || !is_array($root['books'])) {
        throw new InvalidArgumentException('Няма ключа books');
    }

    $check = db()->prepare('SELECT 1 FROM scripture_translation_meta WHERE id = :id LIMIT 1');
    $check->execute([':id' => $translationId]);
    if (!$check->fetch()) {
        throw new InvalidArgumentException('Невядомы пераклад (спачатку мета-запіс у БД)');
    }

    db()->beginTransaction();
    try {
        db()->prepare('DELETE FROM scripture_verses WHERE translation_id = :t')->execute([':t' => $translationId]);
        db()->prepare('DELETE FROM scripture_chapter_meta WHERE translation_id = :t')->execute([':t' => $translationId]);
        db()->prepare('DELETE FROM scripture_books WHERE translation_id = :t')->execute([':t' => $translationId]);

        $stmtBook = db()->prepare(
            'INSERT INTO scripture_books (translation_id, book_id, book_name, chapter_count, sort_index)
             VALUES (:tr, :bid, :bname, :cc, :si)
             ON DUPLICATE KEY UPDATE
               book_name = VALUES(book_name),
               chapter_count = VALUES(chapter_count),
               sort_index = VALUES(sort_index)'
        );
        $stmtCh = db()->prepare(
            'INSERT INTO scripture_chapter_meta (translation_id, book_id, chapter, title, paragraphs_json)
             VALUES (:tr, :bid, :ch, :title, :par)
             ON DUPLICATE KEY UPDATE
               title = VALUES(title),
               paragraphs_json = VALUES(paragraphs_json)'
        );
        $stmtV = db()->prepare(
            'INSERT INTO scripture_verses (translation_id, book_id, chapter, verse, text)
             VALUES (:tr, :bid, :ch, :vn, :txt)
             ON DUPLICATE KEY UPDATE text = VALUES(text)'
        );

        $sortIndex = 0;
        foreach ($root['books'] as $book) {
            if (!is_array($book)) {
                continue;
            }
            $bookId = (int)($book['book_id'] ?? 0);
            $bookName = (string)($book['book_name'] ?? '');
            $chapterCount = (int)($book['chapter_count'] ?? 0);
            if ($bookId <= 0 || $bookName === '') {
                continue;
            }
            $stmtBook->execute([
                ':tr' => $translationId,
                ':bid' => $bookId,
                ':bname' => $bookName,
                ':cc' => $chapterCount,
                ':si' => $sortIndex,
            ]);
            $sortIndex++;

            $chapters = $book['chapters'] ?? [];
            if (!is_array($chapters)) {
                continue;
            }
            foreach ($chapters as $chObj) {
                if (!is_array($chObj)) {
                    continue;
                }
                $chNum = (int)($chObj['chapter'] ?? 0);
                if ($chNum <= 0) {
                    continue;
                }
                $title = isset($chObj['title']) ? (string)$chObj['title'] : null;
                if ($title === '') {
                    $title = null;
                }
                $parJson = null;
                if (isset($chObj['paragraphs']) && is_array($chObj['paragraphs'])) {
                    $parJson = json_encode($chObj['paragraphs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                $stmtCh->execute([
                    ':tr' => $translationId,
                    ':bid' => $bookId,
                    ':ch' => $chNum,
                    ':title' => $title,
                    ':par' => $parJson,
                ]);

                $verses = $chObj['verses'] ?? [];
                if (!is_array($verses)) {
                    continue;
                }
                foreach ($verses as $vObj) {
                    if (!is_array($vObj)) {
                        continue;
                    }
                    $vn = (int)($vObj['verse'] ?? 0);
                    $txt = (string)($vObj['text'] ?? '');
                    if ($vn <= 0) {
                        continue;
                    }
                    $stmtV->execute([
                        ':tr' => $translationId,
                        ':bid' => $bookId,
                        ':ch' => $chNum,
                        ':vn' => $vn,
                        ':txt' => $txt,
                    ]);
                }
            }
        }

        $src = null;
        if (isset($root['source'])) {
            $src = (string)$root['source'];
        }
        $stmtMeta = db()->prepare(
            'UPDATE scripture_translation_meta SET source_url = :src WHERE id = :id'
        );
        $stmtMeta->execute([':src' => $src, ':id' => $translationId]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }
}

function scriptureHasData(string $translationId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM scripture_verses WHERE translation_id = :t LIMIT 1');
    $stmt->execute([':t' => $translationId]);
    return (bool)$stmt->fetch();
}

function scriptureExportToArray(string $translationId): array
{
    $stmt = db()->prepare('SELECT source_url FROM scripture_translation_meta WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $translationId]);
    $metaRow = $stmt->fetch();
    $source = is_array($metaRow) ? ($metaRow['source_url'] ?? null) : null;

    $stmtBooks = db()->prepare(
        'SELECT book_id, book_name, chapter_count
         FROM scripture_books
         WHERE translation_id = :tr
         ORDER BY sort_index ASC, book_id ASC'
    );
    $stmtBooks->execute([':tr' => $translationId]);
    $booksRows = $stmtBooks->fetchAll();
    if (!is_array($booksRows)) {
        $booksRows = [];
    }

    $stmtCh = db()->prepare(
        'SELECT chapter, title, paragraphs_json
         FROM scripture_chapter_meta
         WHERE translation_id = :tr AND book_id = :bid
         ORDER BY chapter ASC'
    );
    $stmtV = db()->prepare(
        'SELECT verse, text
         FROM scripture_verses
         WHERE translation_id = :tr AND book_id = :bid AND chapter = :ch
         ORDER BY verse ASC'
    );

    $books = [];
    foreach ($booksRows as $br) {
        $bookId = (int)$br['book_id'];
        $stmtCh->execute([':tr' => $translationId, ':bid' => $bookId]);
        $chRows = $stmtCh->fetchAll();
        if (!is_array($chRows)) {
            $chRows = [];
        }
        $chapters = [];
        foreach ($chRows as $cr) {
            $chNum = (int)$cr['chapter'];
            $stmtV->execute([':tr' => $translationId, ':bid' => $bookId, ':ch' => $chNum]);
            $vRows = $stmtV->fetchAll();
            if (!is_array($vRows)) {
                $vRows = [];
            }
            $verses = [];
            foreach ($vRows as $vr) {
                $verses[] = [
                    'verse' => (int)$vr['verse'],
                    'text' => (string)$vr['text'],
                ];
            }
            $chObj = [
                'chapter' => $chNum,
                'verses' => $verses,
            ];
            $t = $cr['title'] ?? null;
            if ($t !== null && $t !== '') {
                $chObj['title'] = (string)$t;
            }
            $pj = $cr['paragraphs_json'] ?? null;
            if ($pj !== null && $pj !== '') {
                $decoded = json_decode((string)$pj, true);
                if (is_array($decoded)) {
                    $chObj['paragraphs'] = $decoded;
                }
            }
            $chapters[] = $chObj;
        }

        $books[] = [
            'book_id' => $bookId,
            'book_name' => (string)$br['book_name'],
            'chapter_count' => (int)$br['chapter_count'],
            'chapters' => $chapters,
        ];
    }

    $out = ['books' => $books];
    if ($source !== null && $source !== '') {
        $out['source'] = $source;
    }
    return $out;
}

function scriptureExportJson(string $translationId): string
{
    $data = scriptureExportToArray($translationId);
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function scriptureComputeHash(string $translationId): ?string
{
    if (!scriptureHasData($translationId)) {
        return null;
    }
    $json = scriptureExportJson($translationId);
    return hash('sha256', $json);
}

function scriptureListTranslations(): array
{
    $stmt = db()->query(
        'SELECT id, title FROM scripture_translation_meta ORDER BY sort_order ASC, id ASC'
    );
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function scriptureListBooks(string $translationId): array
{
    $stmt = db()->prepare(
        'SELECT book_id, book_name, chapter_count
         FROM scripture_books
         WHERE translation_id = :tr
         ORDER BY sort_index ASC, book_id ASC'
    );
    $stmt->execute([':tr' => $translationId]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function scriptureListChapters(string $translationId, int $bookId): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT chapter
         FROM scripture_chapter_meta
         WHERE translation_id = :tr AND book_id = :bid
         ORDER BY chapter ASC'
    );
    $stmt->execute([':tr' => $translationId, ':bid' => $bookId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!is_array($rows)) {
        return [];
    }
    return array_map('intval', $rows);
}

function scriptureGetChapterVerses(string $translationId, int $bookId, int $chapter): array
{
    $stmt = db()->prepare(
        'SELECT verse, text
         FROM scripture_verses
         WHERE translation_id = :tr AND book_id = :bid AND chapter = :ch
         ORDER BY verse ASC'
    );
    $stmt->execute([':tr' => $translationId, ':bid' => $bookId, ':ch' => $chapter]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function scriptureUpdateVerseText(
    string $translationId,
    int $bookId,
    int $chapter,
    int $verse,
    string $text
): void {
    $stmt = db()->prepare(
        'UPDATE scripture_verses
         SET text = :txt
         WHERE translation_id = :tr AND book_id = :bid AND chapter = :ch AND verse = :vn'
    );
    $stmt->execute([
        ':txt' => $text,
        ':tr' => $translationId,
        ':bid' => $bookId,
        ':ch' => $chapter,
        ':vn' => $verse,
    ]);

    if ($stmt->rowCount() > 0) {
        return;
    }
    $check = db()->prepare(
        'SELECT 1 FROM scripture_verses
         WHERE translation_id = :tr AND book_id = :bid AND chapter = :ch AND verse = :vn
         LIMIT 1'
    );
    $check->execute([
        ':tr' => $translationId,
        ':bid' => $bookId,
        ':ch' => $chapter,
        ':vn' => $verse,
    ]);
    if (!$check->fetch()) {
        throw new RuntimeException('Верш не знойдзены');
    }
}
