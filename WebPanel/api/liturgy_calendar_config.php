<?php
declare(strict_types=1);

/**
 * Налады пераносаў літургічнага года (нормы канферэнцыі біскупаў).
 * Файл вяртае масіў ключоў; адсутнія ключы = значэнні па змаўчанні ў liturgy_calendar_transfers().
 *
 * epiphany_transfer_to_sunday — Аб'яўленне ў нядзелю паміж 2 і 8 студзеня; Крэшчанне — панядзелак пасля гэтай нядзелі.
 * ascension_on_sunday            — Унебаўшэсце ў VII нядзелю Велікоднага перыяду (Пасха + 42 дні), замест чацвера.
 * corpus_christi_on_sunday       — Цела і Кроў у нядзелю пасля Троіцы (Пасха + 63 дні), замест чацвера пасля Троіцы.
 *
 * Па змаўчанні ўсе false — універсальныя даты RM (6 студзеня, чацвер +39, чацвер +60).
 */
return [
    'epiphany_transfer_to_sunday' => false,
    'ascension_on_sunday' => false,
    'corpus_christi_on_sunday' => false,
];
