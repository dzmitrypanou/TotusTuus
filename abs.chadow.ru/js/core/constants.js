const AppConstants = {
    VERSION: '3.4.4',
    LANG: (typeof window !== 'undefined' && window.ABS_LANG === 'en') ? 'en' : 'ru',
    DEFAULT_VISIBLE_COLUMNS: {
        battles: true,
        damage: true,
        kills: true,
        assisted: true,
        wgs: true,
        penetrationRatio: true,
        hitRatio: false,
        survival: false,
        wins: false,
        losses: false,
        draws: false,
        winRate: false,
        spots: false,
        defense: false,
        capture: false,
        xp: false,
        avgXp: false,
        shots: false,
        hits: false,
        penetrations: false,
        received: false,
        avgReceived: false
    },
    
    COLUMN_HEADERS_RU: [
        { key: 'name', title: 'Игрок', always: true },
        { key: 'battles', title: 'Боёв' },
        { key: 'damage', title: 'Ср. урон' },
        { key: 'kills', title: 'Ср. фраги' },
        { key: 'assisted', title: 'Ср. ассист' },
        { key: 'wgs', title: 'WGSRT' },
        { key: 'penetrationRatio', title: '% пробитий' },
        { key: 'hitRatio', title: '% попаданий' },
        { key: 'survival', title: 'Выживаемость' },
        { key: 'wins', title: 'Побед' },
        { key: 'losses', title: 'Поражений' },
        { key: 'draws', title: 'Ничьих' },
        { key: 'winRate', title: '% побед' },
        { key: 'spots', title: 'Ср. обнаружения' },
        { key: 'defense', title: 'Ср. защита' },
        { key: 'capture', title: 'Ср. захват' },
        { key: 'xp', title: 'Опыт' },
        { key: 'avgXp', title: 'Ср. опыт' },
        { key: 'shots', title: 'Выстрелов' },
        { key: 'hits', title: 'Попаданий' },
        { key: 'penetrations', title: 'Пробитий' },
        { key: 'received', title: 'Заблокированный урон' },
        { key: 'avgReceived', title: 'Ср. заблокированный урон' }
    ],

    COLUMN_HEADERS_EN: [
        { key: 'name', title: 'Player', always: true },
        { key: 'battles', title: 'Battles' },
        { key: 'damage', title: 'Avg damage' },
        { key: 'kills', title: 'Avg kills' },
        { key: 'assisted', title: 'Avg assists' },
        { key: 'wgs', title: 'WGSRT' },
        { key: 'penetrationRatio', title: '% penetrations' },
        { key: 'hitRatio', title: '% hits' },
        { key: 'survival', title: 'Survival' },
        { key: 'wins', title: 'Wins' },
        { key: 'losses', title: 'Defeats' },
        { key: 'draws', title: 'Draws' },
        { key: 'winRate', title: '% wins' },
        { key: 'spots', title: 'Avg spots' },
        { key: 'defense', title: 'Avg defense' },
        { key: 'capture', title: 'Avg capture' },
        { key: 'xp', title: 'XP' },
        { key: 'avgXp', title: 'Avg XP' },
        { key: 'shots', title: 'Shots' },
        { key: 'hits', title: 'Hits' },
        { key: 'penetrations', title: 'Penetrations' },
        { key: 'received', title: 'Blocked damage' },
        { key: 'avgReceived', title: 'Avg blocked damage' }
    ],

    COLUMN_HEADERS: [],
    
    SERVER_REGIONS: {
        ru: 'https://tanki.su/ru/community/accounts/',
        eu: 'https://worldoftanks.eu/en/community/accounts/',
        na: 'https://worldoftanks.com/en/community/accounts/',
        asia: 'https://worldoftanks.asia/en/community/accounts/'
    }
};

AppConstants.COLUMN_HEADERS = AppConstants.LANG === 'en'
    ? AppConstants.COLUMN_HEADERS_EN
    : AppConstants.COLUMN_HEADERS_RU;

const VehicleDictionary = {};