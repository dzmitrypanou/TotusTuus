<?php
declare(strict_types=1);

/**
 * Аднольковы набор радкоў для prayers.php і prayers_hash.php.
 *
 * @return list<array<string, mixed>>
 */
function fetch_active_prayers_for_api(): array
{
    $stmt = db()->query(
        'SELECT
            p.id,
            MAX(p.title) AS title,
            MAX(p.text) AS text,
            MAX(COALESCE(parent.name, c.name, p.category)) AS category,
            MAX(CASE WHEN c.parent_id IS NULL THEN NULL ELSE c.name END) AS subcategory,
            GROUP_CONCAT(CASE WHEN pcl.is_primary = 0 THEN pc.name END ORDER BY pc.name SEPARATOR ", ") AS additional_categories,
            GROUP_CONCAT(CASE WHEN pcl.is_primary = 0 THEN pcl.category_id END ORDER BY pcl.category_id SEPARATOR ",") AS additional_category_ids,
            MAX(p.language) AS language,
            MAX(p.sort_order) AS sort_order
         FROM prayers p
         LEFT JOIN prayer_categories c ON p.category_id = c.id
         LEFT JOIN prayer_categories parent ON c.parent_id = parent.id
         LEFT JOIN prayer_category_links pcl ON p.id = pcl.prayer_id
         LEFT JOIN prayer_categories pc ON pcl.category_id = pc.id
         WHERE p.is_active = 1
         GROUP BY p.id
         ORDER BY MAX(p.sort_order) ASC, p.id ASC'
    );

    return $stmt->fetchAll();
}

/**
 * Актыўныя катэгорыі ў тым жа парадку, што ў адмінцы (sort_order).
 *
 * @return list<array<string, mixed>>
 */
function fetch_active_prayer_categories_for_api(): array
{
    $stmt = db()->query(
        'SELECT c.id, c.name, c.parent_id, c.sort_order
         FROM prayer_categories c
         WHERE c.is_active = 1
         ORDER BY (c.parent_id IS NULL) DESC, c.parent_id ASC, c.sort_order ASC, c.id ASC'
    );

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
