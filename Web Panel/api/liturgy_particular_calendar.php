<?php
declare(strict_types=1);

const LITURGY_DIOCESE_PINSK = 'pinskaya';
const LITURGY_DIOCESE_MINSK_MOGILEV = 'minsk_mogilev';
const LITURGY_DIOCESE_VITEBSK = 'vitebskaya';
const LITURGY_DIOCESE_GRODNO = 'grodzenskaya';

function liturgy_diocese_keys(): array
{
    return [
        LITURGY_DIOCESE_PINSK,
        LITURGY_DIOCESE_MINSK_MOGILEV,
        LITURGY_DIOCESE_VITEBSK,
        LITURGY_DIOCESE_GRODNO,
    ];
}

function liturgy_diocese_options_default(): array
{
    return array_fill_keys(liturgy_diocese_keys(), false);
}

function liturgy_normalize_diocese_options(array $raw): array
{
    $out = liturgy_diocese_options_default();
    foreach ($raw as $k => $v) {
        $key = is_string($k) ? $k : '';
        if ($key !== '' && array_key_exists($key, $out)) {
            $out[$key] = (bool)$v;
        }
    }

    return $out;
}

function liturgy_calendar_diocese_options_from_request(): array
{
    $raw = liturgy_diocese_options_default();
    $q = isset($_GET['dioceses']) ? trim((string)$_GET['dioceses']) : '';
    if ($q === '') {
        return $raw;
    }
    foreach (explode(',', $q) as $part) {
        $k = strtolower(trim($part));
        if ($k !== '' && array_key_exists($k, $raw)) {
            $raw[$k] = true;
        }
    }

    return $raw;
}

function liturgy_particular_calendar_movable_important(DateTimeImmutable $pentecost): array
{
    $christEternalHighPriest = $pentecost->modify('+4 day');

    return [
        $christEternalHighPriest->format('Y-m-d') => ['Свята Езуса Хрыста, Найвышэйшага і Вечнага Святара', 'white'],
    ];
}
