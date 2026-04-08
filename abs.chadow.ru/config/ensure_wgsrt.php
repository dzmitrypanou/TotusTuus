<?php

function ensure_wgsrt_grades_lang_columns($db): void
{
    try {
        $db->query("ALTER TABLE wgsrt_grades ADD COLUMN grade_name_en VARCHAR(255) NULL AFTER grade_name");
    } catch (Throwable $e) {
        // column already exists
    }

    try {
        $db->query("ALTER TABLE wgsrt_grades ADD COLUMN description_en TEXT NULL AFTER description");
    } catch (Throwable $e) {
        // column already exists
    }
}

