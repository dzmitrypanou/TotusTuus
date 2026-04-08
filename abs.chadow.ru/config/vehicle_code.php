<?php
/**
 * Канонический формат кода техники: nation:vehicle_id (как в клиенте WoT).
 * Вариант nation-vehicle_id считается тем же танком.
 */

/**
 * @return string[]
 */
function vehicle_code_known_nations() {
    return [
        'ussr', 'germany', 'usa', 'france', 'uk', 'china', 'japan', 'czech',
        'poland', 'sweden', 'italy', 'european', 'intunion', 'unknown',
    ];
}

/**
 * @param string|null $vehicleCode
 */
function normalize_vehicle_code($vehicleCode) {
    if ($vehicleCode === null || $vehicleCode === '') {
        return '';
    }
    $code = trim($vehicleCode);
    if ($code === '') {
        return '';
    }
    if (strpos($code, ':') !== false) {
        $parts = explode(':', $code, 2);
        $nation = strtolower(trim($parts[0]));
        $rest = isset($parts[1]) ? trim($parts[1]) : '';
        return $rest === '' ? $code : ($nation . ':' . $rest);
    }
    $known = vehicle_code_known_nations();
    $pos = strpos($code, '-');
    if ($pos !== false) {
        $nation = strtolower(substr($code, 0, $pos));
        $rest = substr($code, $pos + 1);
        $isKnownNation = in_array($nation, $known, true);
        $looksLikeNation = (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $nation);
        if ($rest !== '' && ($isKnownNation || $looksLikeNation)) {
            return $nation . ':' . $rest;
        }
    }
    return $code;
}

/**
 * Объединяет строки с одним normalize(vehicle_code), оставляет канонический код, снимает проверку модерации.
 *
 * @param Database $db
 */
function merge_duplicate_vehicle_codes($db) {
    $rows = $db->fetchAll(
        'SELECT id, vehicle_code, display_name_ru, display_name_en, nation, tank_type, tier, is_premium, is_collectible, is_moderated
         FROM tank_dictionary ORDER BY id ASC'
    );
    if (!$rows) {
        return;
    }
    $byNorm = [];
    foreach ($rows as $r) {
        $norm = normalize_vehicle_code($r['vehicle_code']);
        if (!isset($byNorm[$norm])) {
            $byNorm[$norm] = [];
        }
        $byNorm[$norm][] = $r;
    }
    foreach ($byNorm as $norm => $group) {
        if (count($group) < 2) {
            continue;
        }
        $keeper = pick_vehicle_row_keeper($group, $norm);
        $others = [];
        foreach ($group as $row) {
            if ((int) $row['id'] !== (int) $keeper['id']) {
                $others[] = $row;
            }
        }
        $nation = (strpos($norm, ':') !== false) ? explode(':', $norm, 2)[0] : ($keeper['nation'] ?? 'unknown');
        $bestName = trim((string) ($keeper['display_name_ru'] ?? ''));
        foreach ($group as $g) {
            $cand = trim((string) ($g['display_name_ru'] ?? ''));
            if ($cand !== '' && mb_strlen($cand) > mb_strlen($bestName)) {
                $bestName = $cand;
            }
        }
        if ($bestName === '') {
            $bestName = $norm;
        }
        try {
            $db->beginTransaction();
            foreach ($others as $o) {
                $db->delete('DELETE FROM tank_dictionary WHERE id = ?', [(int) $o['id']]);
            }
            $db->update(
                'UPDATE tank_dictionary SET vehicle_code = ?, display_name_ru = ?, display_name_en = ?, nation = ?, is_moderated = 0 WHERE id = ?',
                [$norm, $bestName, $bestName, $nation, (int) $keeper['id']]
            );
            $db->commit();
        } catch (Exception $e) {
            try {
                $db->rollback();
            } catch (Exception $ignored) {
            }
        }
    }
}

/**
 * @param array<int, array<string, mixed>> $group
 * @param string $norm
 * @return array<string, mixed>
 */
function pick_vehicle_row_keeper(array $group, $norm) {
    foreach ($group as $r) {
        if (!empty($r['is_moderated'])) {
            return $r;
        }
    }
    foreach ($group as $r) {
        if (($r['vehicle_code'] ?? '') === $norm) {
            return $r;
        }
    }
    foreach ($group as $r) {
        if (strpos($r['vehicle_code'] ?? '', ':') !== false) {
            return $r;
        }
    }
    usort($group, function ($a, $b) {
        return (int) $a['id'] - (int) $b['id'];
    });
    return $group[0];
}
