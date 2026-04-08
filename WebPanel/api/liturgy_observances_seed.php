<?php
declare(strict_types=1);

/**
 * Пачатковы пасеў liturgy_observances (адзін раз, калі табліца пустая).
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/liturgy_observances_lib.php';
require_once __DIR__ . '/liturgy_particular_calendar.php';

function liturgy_seed_observances_if_empty(): void
{
    if (liturgy_observances_count() > 0) {
        return;
    }

    $sort = 0;
    $rows = [];

    $add = function (array $r) use (&$rows, &$sort): void {
        $r['sort_order'] = $r['sort_order'] ?? (++$sort);
        $rows[] = $r;
    };

    $fixImp = function (string $md, string $title, string $color, string $src = 'fixed', int $pri = 0, string $rank = '', string $any = '', string $all = '', string $forbid = '') use ($add): void {
        [$m, $d] = array_map('intval', explode('-', $md));
        $add([
            'rule_type' => 'fixed_md',
            'month' => $m,
            'day' => $d,
            'easter_offset' => null,
            'advent_offset_days' => null,
            'observance_kind' => 'important',
            'regional_rank' => $rank,
            'title' => $title,
            'liturgical_color' => $color,
            'source_tag' => $src,
            'require_any_of' => $any,
            'require_all_of' => $all,
            'forbid_if_any_of' => $forbid,
            'match_priority' => $pri,
            'uses_cycle_suffix' => 0,
            'suppressed_by_ordinary_sunday' => 0,
            'patch_append_to_mmdd' => null,
            'patch_suffix' => null,
            'is_active' => 1,
        ]);
    };

    $fixOpt = function (string $md, string $title, string $any = '', string $all = '', string $forbid = '') use ($add): void {
        [$m, $d] = array_map('intval', explode('-', $md));
        $add([
            'rule_type' => 'fixed_md',
            'month' => $m,
            'day' => $d,
            'easter_offset' => null,
            'advent_offset_days' => null,
            'observance_kind' => 'optional',
            'regional_rank' => '',
            'title' => $title,
            'liturgical_color' => 'white',
            'source_tag' => 'fixed',
            'require_any_of' => $any,
            'require_all_of' => $all,
            'forbid_if_any_of' => $forbid,
            'match_priority' => 0,
            'uses_cycle_suffix' => 0,
            'suppressed_by_ordinary_sunday' => 0,
            'patch_append_to_mmdd' => null,
            'patch_suffix' => null,
            'is_active' => 1,
        ]);
    };

    $eastImp = function (int $off, string $title, string $color) use ($add): void {
        $add([
            'rule_type' => 'easter_offset',
            'month' => null,
            'day' => null,
            'easter_offset' => $off,
            'advent_offset_days' => null,
            'observance_kind' => 'important',
            'regional_rank' => '',
            'title' => $title,
            'liturgical_color' => $color,
            'source_tag' => 'movable',
            'require_any_of' => '',
            'require_all_of' => '',
            'forbid_if_any_of' => '',
            'match_priority' => 0,
            'uses_cycle_suffix' => 0,
            'suppressed_by_ordinary_sunday' => 0,
            'patch_append_to_mmdd' => null,
            'patch_suffix' => null,
            'is_active' => 1,
        ]);
    };

    $eastOpt = function (int $off, string $title) use ($add): void {
        $add([
            'rule_type' => 'easter_offset',
            'month' => null,
            'day' => null,
            'easter_offset' => $off,
            'advent_offset_days' => null,
            'observance_kind' => 'optional',
            'regional_rank' => '',
            'title' => $title,
            'liturgical_color' => 'white',
            'source_tag' => 'movable',
            'require_any_of' => '',
            'require_all_of' => '',
            'forbid_if_any_of' => '',
            'match_priority' => 0,
            'uses_cycle_suffix' => 0,
            'suppressed_by_ordinary_sunday' => 0,
            'patch_append_to_mmdd' => null,
            'patch_suffix' => null,
            'is_active' => 1,
        ]);
    };

    $advImp = function (int $advOff, string $title, string $color) use ($add): void {
        $add([
            'rule_type' => 'advent_offset',
            'month' => null,
            'day' => null,
            'easter_offset' => null,
            'advent_offset_days' => $advOff,
            'observance_kind' => 'important',
            'regional_rank' => '',
            'title' => $title,
            'liturgical_color' => $color,
            'source_tag' => 'movable',
            'require_any_of' => '',
            'require_all_of' => '',
            'forbid_if_any_of' => '',
            'match_priority' => 0,
            'uses_cycle_suffix' => 0,
            'suppressed_by_ordinary_sunday' => 0,
            'patch_append_to_mmdd' => null,
            'patch_suffix' => null,
            'is_active' => 1,
        ]);
    };

    // Эпіфанія (канфіг пераносу)
    $add([
        'rule_type' => 'epiphany_observed',
        'month' => null,
        'day' => null,
        'easter_offset' => null,
        'advent_offset_days' => null,
        'observance_kind' => 'important',
        'regional_rank' => '',
        'title' => 'Аб\'яўленне Пана',
        'liturgical_color' => 'white',
        'source_tag' => 'fixed',
        'require_any_of' => '',
        'require_all_of' => '',
        'forbid_if_any_of' => '',
        'match_priority' => 0,
        'uses_cycle_suffix' => 0,
        'suppressed_by_ordinary_sunday' => 0,
        'patch_append_to_mmdd' => null,
        'patch_suffix' => null,
        'is_active' => 1,
    ]);

    // Унебаўшэсце / Ціла Хрыста — liturgy_transfer_dependent_movables у liturgy_common.php

    // Фіксаваныя ўрачыстасці / святы (агульны каляндар)
    $fixImp('01-01', 'Урачыстасць святой Багародзіцы Марыі', 'white');
    $fixImp('01-02', 'Св. Базыля Вялікага І Грыгорыя Назіянзскага, біскупаў і доктараў Касцёла', 'white');
    $fixImp('01-25', 'Свята навяртання св. Паўла, апостала', 'white');
    $fixImp('02-02', 'Ахвяраванне Пана', 'white');
    $fixImp('02-14', 'Свята св. Кірыла, мніха, і Мятода, біскупа, апекуноў Еўропы', 'white');
    $fixImp('02-22', 'Свята Катэдры святога Пятра', 'white');
    $fixImp('03-19', 'Урачыстасць святога Юзафа', 'white');
    $fixImp('03-25', 'Звеставанне Пана', 'white');
    $fixImp('04-29', 'Свята св. Кацярыны Сіенскай, панны і доктара Касцёла, апякункі Еўропы', 'white');
    $fixImp('05-01', 'Свята св. Юзафа рамесніка', 'white');
    $fixImp('05-03', 'Свята св. Філіпа і Якуба, апосталаў', 'red');
    $fixImp('05-14', 'Свята св. Мацея, апостала', 'red');
    $fixImp('06-24', 'Нараджэнне святога Яна Хрысціцеля', 'white');
    $fixImp('06-29', 'Урачыстасць святых апосталаў Пятра і Паўла', 'white');
    $fixImp('07-03', 'Свята св. Тамаша, апостала', 'red');
    $fixImp('07-11', 'Свята св. Бэнэдыкта, абата, апекуна Еўропы', 'white');
    $fixImp('07-22', 'Свята св. Марыі Магдалены', 'white');
    $fixImp('07-23', 'Свята св. Брыгіты, законніцы, апякункі Еўропы', 'white');
    $fixImp('07-25', 'Свята св. Якуба, апостала', 'red');
    $fixImp('08-06', 'Перамяненне Пана', 'white');
    $fixImp('08-15', 'Унебаўзяцце Найсвяцейшай Панны Марыі', 'white');
    $fixImp('09-08', 'Свята Нараджэння Найсвяцейшай Панны Марыі', 'white');
    $fixImp('09-14', 'Свята Узвышэння Святога Крыжа', 'red');
    $fixImp('09-29', 'Свята св. Міхала Арханёла, св. Габрыэла і Рафала, арханёлаў', 'white', 'fixed', 0);
    $fixImp('09-29', 'Свята св. Міхала Арханёла (апекуна Мінска-Магілёўскай правінцыі Касцёла), св. Габрыэла і Рафала, арханёлаў', 'white', 'regional', 10, '', LITURGY_DIOCESE_MINSK_MOGILEV);
    $fixImp('10-18', 'Свята св. Лукі, евангеліста', 'red');
    $fixImp('10-28', 'Свята св. Сымона і Юды, апосталаў', 'red');
    $fixImp('11-01', 'Урачыстасць Усіх Святых', 'white');
    $fixImp('11-02', 'Успамін усіх памерлых вернікаў', 'purple');
    $fixImp('11-09', 'Свята гадавіны пасвячэння Латэранскай базылікі', 'white');
    $fixImp('12-08', 'Беззаганнае Зачацце Найсвяцейшай Панны Марыі', 'white');
    $fixImp('12-26', 'Свята св. Стэфана, першамучаніка', 'red');
    $fixImp('12-27', 'Свята св. Яна, апостала і евангеліста', 'white');
    $fixImp('12-28', 'Свята святых Немаўлят', 'red');
    $fixImp('12-25', 'Нараджэнне Пана', 'white');

    // Пераносныя (без Унебаўшэсця / Corpus з канфігам — у кодзе)
    $eastImp(-46, 'Папяльцовая серада', 'purple');
    $eastImp(-7, 'Пальмовая нядзеля', 'red');
    $eastImp(-6, 'Вялікі панядзелак', 'purple');
    $eastImp(-5, 'Вялікі аўторак', 'purple');
    $eastImp(-4, 'Вялікая серада', 'purple');
    $eastImp(-3, 'Вялікі чацвер', 'white');
    $eastImp(-2, 'Вялікая пятніца Мукі Пана', 'red');
    $eastImp(-1, 'Пасхальная вігілія ў святую ноч', 'white');
    $eastImp(0, 'Уваскрасенне Пана (Вялікдзень)', 'white');
    $eastImp(49, 'Нядзеля спаслання Духа Святога', 'red');
    $eastImp(56, 'Урачыстасць Найсвяцейшай Тройцы', 'white');
    $eastImp(68, 'Урачыстасць Найсвяцейшага Сэрца Пана Езуса', 'white');
    $eastImp(53, 'Свята Езуса Хрыста, Найвышэйшага і Вечнага Святара', 'white');

    $advImp(-7, 'Урачыстасць Хрыста Валадара Сусвету', 'white');
    $advImp(0, 'I Нядзеля Адвэнту', 'purple');

    // Даброўныя — фіксаваныя
    $optionalFixed = [
        '01-03' => 'Успамін Найсвяцейшага Імя Езуса',
        '01-07' => 'Успамін св. Раймунда Пеньяфорцкага, прэзбітэра',
        '01-13' => 'Успамін св. Гілярыя, біскупа і доктара Касцёла',
        '01-17' => 'Успамін св. Антонія, абата',
        '01-20' => 'Успамін св. Фабіяна, папы, і св. Себасцьяна, мучанікаў',
        '01-21' => 'Успамін св. Агнешкі, панны і мучаніцы',
        '01-22' => 'Успамін св. Вінцэнта, дыякана і мучаніка',
        '01-24' => 'Успамін св. Францішка Сальскага, біскупа і доктара Касцёла',
        '01-26' => 'Успамін св. Цімафея і Ціта, біскупаў',
        '01-28' => 'Успамін св. Тамаша Аквінскага, святара і доктара Касцёла',
        '01-31' => 'Успамін св. Яна Боска, святара',
        '02-03' => 'Успамін св. Блажэя, біскупа, і св. Анзгара (Аскара), біскупа',
        '02-05' => 'Успамін св. Агаты, панны і мучаніцы',
        '02-06' => 'Успамін св. Паўла Мікі і паплечнікаў, мучанікаў',
        '02-08' => 'Успамін св. Гераніма Эміліяні і св. Жазэфіны Бакіты, панны',
        '02-10' => 'Успамін св. Схалястыкі, панны',
        '02-11' => 'Успамін Найсвяцейшай Панны Марыі з Люрда',
        '02-23' => 'Успамін св. Палікарпа, біскупа і мучаніка',
        '03-04' => 'Успамін св. Казіміра',
        '03-07' => 'Успамін св. Пэрпэтуі і Фэліцыты, мучаніц',
        '03-08' => 'Успамін св. Яна Божага, законніка',
        '03-17' => 'Успамін св. Патрыка, біскупа',
        '03-18' => 'Успамін св. Кірыла Ерузалемскага, біскупа і доктара Касцёла',
        '04-02' => 'Успамін св. Францішка з Паолы, пустэльніка',
        '04-04' => 'Успамін св. Ізыдора, біскупа і доктара Касцёла',
        '04-05' => 'Успамін св. Вінцэнта Фэрэра, прэзбітэра',
        '04-07' => 'Успамін св. Жана Батыста Сальскага, святара',
        '04-11' => 'Успамін св. Станіслава, біскупа і мучаніка',
        '04-21' => 'Успамін св. Анзэльма, біскупа і доктара Касцёла',
        '04-23' => 'Успамін св. Юрыя, мучаніка, і св. Адальбэрта (Войцеха), біскупа і мучаніка',
        '04-24' => 'Успамін св. Фідэля Сігмарынгенскага, прэзбітэра і мучаніка',
        '05-02' => 'Успамін св. Атаназія, біскупа і доктара Касцёла',
        '05-16' => 'Успамін св. Андрэя Баболі, святара і мучаніка',
        '05-26' => 'Успамін св. Філіпа Нэры, святара',
        '06-03' => 'Успамін святых Караля Луангі і паплечнікаў, мучанікаў',
        '06-05' => 'Успамін св. Баніфацыя, біскупа і мучаніка',
        '06-06' => 'Успамін св. Норбэрта, біскупа',
        '06-11' => 'Успамін св. Барнавы, апостала',
        '06-13' => 'Успамін св. Антонія, прэзбітэра і доктара Касцёла',
        '06-21' => 'Успамін св. Алаізія Ганзагі, законніка',
        '06-22' => 'Успамін св. Паўліна Нольскага, біскупа',
        '07-15' => 'Успамін св. Бонавэнтуры, біскупа і доктара Касцёла',
        '07-16' => 'Успамін Найсвяцейшай Панны Марыі з Гары Кармэль',
        '07-20' => 'Успамін св. Апалінарыя, біскупа і мучаніка',
        '07-21' => 'Успамін св. Лаўрэнція з Брындызі, прэзбітэра і доктара Касцёла',
        '07-26' => 'Успамін св. Яўхіма і Ганны, бацькоў Найсвяцейшай Панны Марыі',
        '07-29' => 'Успамін св. Марты',
        '07-31' => 'Успамін св. Ігнацыя Лаёлы, святара',
        '08-01' => 'Успамін св. Альфонса Марыі Лігуоры, біскупа і доктара Касцёла',
        '08-02' => 'Успамін св. Эўзэбія Вэрчэльскага, біскупа',
        '08-04' => 'Успамін св. Яна Марыі Віянэя, святара',
        '08-08' => 'Успамін св. Дамініка, святара',
        '08-09' => 'Успамін св. Тэрэзы Бэнэдыкты ад Крыжа (Эдыты Штайн), панны і мучаніцы, апякункі Еўропы',
        '08-11' => 'Успамін св. Клары, панны',
        '08-14' => 'Успамін св. Максімільяна Марыі Кольбэ, прэзбітэра і мучаніка',
        '08-20' => 'Успамін св. Бэрнарда, абата і доктара Касцёла',
        '08-21' => 'Успамін св. Пія Х, папы',
        '08-22' => 'Успамін Найсвяцейшай Панны Марыі Каралевы',
        '08-27' => 'Успамін св. Монікі',
        '08-28' => 'Успамін св. Аўгустына, біскупа і доктара Касцёла',
        '08-29' => 'Успамін мучаніцтва св. Яна Хрысціцеля',
        '09-03' => 'Успамін св. Грыгорыя Вялікага, папы і доктара Касцёла',
        '09-05' => 'Успамін св. Тэрэзы з Калькуты, панны',
        '09-12' => 'Успамін Найсвяцейшага Імя Марыі',
        '09-13' => 'Успамін св. Яна Залатавуснага (Хрызастома), біскупа і доктара Касцёла',
        '09-15' => 'Успамін Найсвяцейшай Панны Марыі Балеснай',
        '09-16' => 'Успамін св. Карнэлія, папы, і Кіпрыяна, біскупа, мучанікаў',
        '09-19' => 'Успамін св. Януарыя, біскупа і мучаніка',
        '09-20' => 'Успамін св. Андрэя Кім Таэгона, прэзбітэра, Паўла Чон Хасана і паплечнікаў, мучанікаў',
        '09-23' => 'Успамін св. Піо з П’етрэльчыны, святара',
        '09-27' => 'Успамін св. Вінцэнта дэ Поля, святара',
        '09-30' => 'Успамін св. Гераніма, святара і доктара Касцёла',
        '10-01' => 'Успамін св. Тэрэзы ад Дзіцятка Езус, панны і доктара Касцёла',
        '10-02' => 'Успамін св. Анёлаў Ахоўнікаў',
        '10-04' => 'Успамін св. Францішка Асізскага',
        '10-07' => 'Успамін Найсвяцейшай Панны Марыі Ружанцовай',
        '10-09' => 'Успамін св. Дыянісія, біскупа, і паплечнікаў, мучанікаў',
        '10-15' => 'Успамін св. Тэрэзы ад Езуса, панны і доктара Касцёла',
        '10-17' => 'Успамін св. Ігнацыя з Антыёхіі, біскупа і мучаніка',
        '10-22' => 'Успамін св. Яна Паўла ІІ, папы',
        '10-23' => 'Успамін св. Яна Капістрана, прэзбітэра',
        '11-02' => 'Успамін усіх памерлых вернікаў',
        '11-03' => 'Успамін св. Марціна дэ Порэса, законніка',
        '11-04' => 'Успамін св. Караля Барамэя, біскупа',
        '11-10' => 'Успамін св. Льва Вялікага, папы і доктара Касцёла',
        '11-11' => 'Успамін св. Марціна Турскага, біскупа',
        '11-12' => 'Успамін св. Язафата, біскупа і мучаніка',
        '11-15' => 'Успамін св. Альбэрта Вялікага, біскупа і доктара Касцёла',
        '11-17' => 'Успамін св. Альжбеты Венгерскай, законніцы',
        '11-21' => 'Успамін Ахвяравання Найсвяцейшай Панны Марыі',
        '11-22' => 'Успамін св. Цэцыліі, панны і мучаніцы',
        '11-23' => 'Успамін св. Клімэнта І, папы і мучаніка',
        '11-24' => 'Успамін св. Андрэя Зунг Лака, прэзбітэра, і паплечнікаў, мучанікаў',
        '12-03' => 'Успамін св. Францішка Ксавэрыя, святара',
        '12-06' => 'Успамін св. Мікалая, біскупа',
        '12-07' => 'Успамін Амброзія, біскупа і доктара Касцёла',
        '12-13' => 'Успамін св. Люцыі, панны і мучаніцы',
        '12-14' => 'Успамін св. Яна ад Крыжа, святара і доктара Касцёла',
        '12-29' => 'Успамін св. Томаса Бэкета, біскупа і мучаніка',
    ];
    foreach ($optionalFixed as $md => $title) {
        $forbid = '';
        if ($md === '05-16') {
            $forbid = LITURGY_DIOCESE_PINSK;
        }
        if ($md === '03-04' || $md === '08-14') {
            $forbid = LITURGY_DIOCESE_GRODNO;
        }
        $fixOpt($md, $title, '', '', $forbid);
    }

    $eastOpt(50, 'Успамін Найсвяцейшай Панны Марыі, Маці Касцёла');
    $eastOpt(69, 'Успамін Беззаганнага Сэрца Найсвяцейшай Панны Марыі');

    // Рэгіянальныя важныя
    $fixImp('07-02', 'Урачыстасць Найсвяцейшай Панны Марыі Будслаўскай, апякункі Мінска-Магілёўскай архідыяцэзіі', 'white', 'regional', 0, 'solemnity', LITURGY_DIOCESE_MINSK_MOGILEV);
    $fixImp('03-16', 'Гадавіна пасвячэння катэдральнага касцёла (Мінска-Магілёўская архідыяцэзія)', 'white', 'regional', 0, 'feast', LITURGY_DIOCESE_MINSK_MOGILEV);
    $fixImp('07-02', 'Свята Найсвяцейшай Панны Марыі Будслаўскай', 'white', 'regional', 0, 'feast', LITURGY_DIOCESE_VITEBSK, '', LITURGY_DIOCESE_MINSK_MOGILEV);
    $fixImp('11-12', 'Урачыстасць св. Язафата, біскупа і мучаніка, галоўнага апекуна Віцебскай дыяцэзіі', 'red', 'regional', 0, 'solemnity', LITURGY_DIOCESE_VITEBSK);
    $fixImp('10-10', 'Гадавіна пасвячэння катэдральнага касцёла (Віцебская дыяцэзія)', 'white', 'regional', 0, 'feast', LITURGY_DIOCESE_VITEBSK);
    $fixImp('11-12', 'Свята св. Язафата, біскупа і мучаніка', 'red', 'regional', 0, 'feast', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_PINSK, '', LITURGY_DIOCESE_VITEBSK);
    $fixImp('05-16', 'Урачыстасць св. Андрэя Баболі, прэзбітэра і мучаніка, апекуна Пінскай дыяцэзіі', 'red', 'regional', 0, 'solemnity', LITURGY_DIOCESE_PINSK);
    $fixImp('03-04', 'Свята св. Казіміра, дадатковага апекуна Гродзенскай дыяцэзіі', 'white', 'regional', 0, 'feast', LITURGY_DIOCESE_GRODNO);
    $fixImp('08-14', 'Урачыстасць св. Максімільяна Марыі Кольбэ, прэзбітэра і мучаніка, дадатковага апекуна Гродзенскай дыяцэзіі', 'red', 'regional', 0, 'solemnity', LITURGY_DIOCESE_GRODNO);
    $fixImp('11-16', 'Урачыстасць Найсвяцейшай Панны Марыі, Маці Міласэрнасці (Маці Божай Вастрабрамскай), галоўнай апякункі Гродзенскай дыяцэзіі', 'white', 'regional', 0, 'solemnity', LITURGY_DIOCESE_GRODNO);
    $fixImp('12-04', 'Гадавіна пасвячэння катэдральнага касцёла (Гродзенская дыяцэзія)', 'white', 'regional', 0, 'feast', LITURGY_DIOCESE_GRODNO);

    // Дапаўненне загалovka 1 мая для Пінска (без асобнага «дня» у календары)
    $add([
        'rule_type' => 'fixed_md',
        'month' => null,
        'day' => null,
        'easter_offset' => null,
        'advent_offset_days' => null,
        'observance_kind' => 'patch',
        'regional_rank' => '',
        'title' => '',
        'liturgical_color' => 'white',
        'source_tag' => 'fixed',
        'require_any_of' => LITURGY_DIOCESE_PINSK,
        'require_all_of' => '',
        'forbid_if_any_of' => '',
        'match_priority' => 0,
        'uses_cycle_suffix' => 0,
        'suppressed_by_ordinary_sunday' => 0,
        'patch_append_to_mmdd' => '05-01',
        'patch_suffix' => ' — таксама ў Пінскай дыяцэзіі: гадавіна пасвячэння катэдральнага касцёла',
        'is_active' => 1,
    ]);

    // Рэгіянальныя даброўныя / дадаткі
    $fixOpt('07-02', 'Успамін Найсвяцейшай Панны Марыі Будслаўскай', LITURGY_DIOCESE_PINSK . ',' . LITURGY_DIOCESE_GRODNO, '', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_VITEBSK);
    $fixOpt('01-29', 'Успамін бл. Баляславы Марыі Лямэнт, панны', LITURGY_DIOCESE_PINSK . ',' . LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_VITEBSK . ',' . LITURGY_DIOCESE_GRODNO);
    $fixOpt('01-27', 'Успамін бл. Юрыя Матулевіча, біскупа', LITURGY_DIOCESE_VITEBSK);
    $fixOpt('09-08', 'Свята Найсвяцейшай Панны Марыі Браслаўскай', LITURGY_DIOCESE_VITEBSK);
    $fixOpt('06-06', 'Успамін св. Баніфацыя, біскупа і мучаніка', LITURGY_DIOCESE_VITEBSK);
    $fixOpt('02-15', 'Успамін бл. Міхала Сапоцькі, прэзбітэра', LITURGY_DIOCESE_GRODNO);
    $fixOpt('07-05', 'Успамін Найсвяцейшай Панны Марыі Тракельскай', LITURGY_DIOCESE_GRODNO);
    $fixOpt('08-05', 'Успамін Найсвяцейшай Панны Марыі Кангрэгацкай (Студэнцкай)', LITURGY_DIOCESE_GRODNO);
    $fixOpt('09-04', 'Успамін бл. Марыі Стэлы і паплечніц, паннаў і мучаніц', LITURGY_DIOCESE_GRODNO);
    $fixOpt('10-26', 'Успамін бл. Цэліны Бажэцкай', LITURGY_DIOCESE_GRODNO);
    $fixOpt('06-12', 'Успамін бл. Генрыха Глябовіча, прэзбітэра, і паплечнікаў, мучанікаў другой сусветнай вайны', LITURGY_DIOCESE_GRODNO);
    $fixOpt('09-13', 'Успамін Найсвяцейшага Імя Марыі', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_PINSK);
    $fixOpt('08-26', 'Успамін Найсвяцейшай Панны Марыі Чэнстахоўскай', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_PINSK . ',' . LITURGY_DIOCESE_GRODNO);
    $fixOpt('09-18', 'Успамін св. Станіслава Косткі, законніка', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_PINSK . ',' . LITURGY_DIOCESE_GRODNO);
    $fixOpt('11-16', 'Успамін Найсвяцейшай Панны Марыі, Маці Міласэрнасці (Маці Божай Вастрабрамскай)', LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_PINSK, '', LITURGY_DIOCESE_GRODNO);
    $fixOpt('11-20', 'Успамін св. Рафала Каліноўскага, прэзбітэра', LITURGY_DIOCESE_PINSK . ',' . LITURGY_DIOCESE_MINSK_MOGILEV . ',' . LITURGY_DIOCESE_VITEBSK . ',' . LITURGY_DIOCESE_GRODNO);

    $stmt = db()->prepare(
        'INSERT INTO liturgy_observances (
            rule_type, month, day, easter_offset, advent_offset_days,
            observance_kind, regional_rank, title, liturgical_color, source_tag,
            require_any_of, require_all_of, forbid_if_any_of,
            match_priority, uses_cycle_suffix, suppressed_by_ordinary_sunday,
            patch_append_to_mmdd, patch_suffix, is_active, sort_order
         ) VALUES (
            :rule_type, :month, :day, :easter_offset, :advent_offset_days,
            :observance_kind, :regional_rank, :title, :liturgical_color, :source_tag,
            :require_any_of, :require_all_of, :forbid_if_any_of,
            :match_priority, :uses_cycle_suffix, :suppressed_by_ordinary_sunday,
            :patch_append_to_mmdd, :patch_suffix, :is_active, :sort_order
         )'
    );

    foreach ($rows as $r) {
        $stmt->execute([
            ':rule_type' => $r['rule_type'],
            ':month' => $r['month'],
            ':day' => $r['day'],
            ':easter_offset' => $r['easter_offset'],
            ':advent_offset_days' => $r['advent_offset_days'],
            ':observance_kind' => $r['observance_kind'],
            ':regional_rank' => $r['regional_rank'],
            ':title' => $r['title'],
            ':liturgical_color' => $r['liturgical_color'],
            ':source_tag' => $r['source_tag'],
            ':require_any_of' => $r['require_any_of'],
            ':require_all_of' => $r['require_all_of'],
            ':forbid_if_any_of' => $r['forbid_if_any_of'],
            ':match_priority' => $r['match_priority'],
            ':uses_cycle_suffix' => $r['uses_cycle_suffix'],
            ':suppressed_by_ordinary_sunday' => $r['suppressed_by_ordinary_sunday'],
            ':patch_append_to_mmdd' => $r['patch_append_to_mmdd'],
            ':patch_suffix' => $r['patch_suffix'],
            ':is_active' => $r['is_active'],
            ':sort_order' => $r['sort_order'],
        ]);
    }

    liturgy_observances_invalidate_cache();
}
