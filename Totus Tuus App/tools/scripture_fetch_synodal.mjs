

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));

const ORIGIN = process.env.SCRIPTURE_FETCH_ORIGIN?.replace(/\/$/, '') || '';
const CODE = process.env.SCRIPTURE_FETCH_CODE || 'syn';

if (!ORIGIN) {
  console.error('Задайце SCRIPTURE_FETCH_ORIGIN (карэнь сайта з HTML главамі).');
  process.exit(1);
}

const BOOKS = [
  [1, 'Бытие', 50],
  [2, 'Исход', 40],
  [3, 'Левит', 27],
  [4, 'Числа', 36],
  [5, 'Второзаконие', 34],
  [6, 'Иисус Навин', 24],
  [7, 'Судьи', 21],
  [8, 'Руфь', 4],
  [9, '1 Царств', 31],
  [10, '2 Царств', 24],
  [11, '3 Царств', 22],
  [12, '4 Царств', 25],
  [13, '1 Паралипоменон', 29],
  [14, '2 Паралипоменон', 36],
  [15, 'Ездра', 10],
  [16, 'Неемия', 13],
  [17, 'Есфирь', 10],
  [18, 'Иов', 42],
  [19, 'Псалтирь', 150],
  [20, 'Притчи', 31],
  [21, 'Екклесиаст', 12],
  [22, 'Песня Песней', 8],
  [23, 'Исаия', 66],
  [24, 'Иеремия', 52],
  [25, 'Плач Иеремии', 5],
  [26, 'Иезекииль', 48],
  [27, 'Даниил', 12],
  [28, 'Осия', 14],
  [29, 'Иоиль', 3],
  [30, 'Амос', 9],
  [31, 'Авдий', 1],
  [32, 'Иона', 4],
  [33, 'Михей', 7],
  [34, 'Наум', 3],
  [35, 'Аввакум', 3],
  [36, 'Софония', 3],
  [37, 'Аггей', 2],
  [38, 'Захария', 14],
  [39, 'Малахия', 4],
  [40, 'От Матфея', 28],
  [41, 'От Марка', 16],
  [42, 'От Луки', 24],
  [43, 'От Иоанна', 21],
  [44, 'Деяния', 28],
  [45, 'Иакова', 5],
  [46, '1 Петра', 5],
  [47, '2 Петра', 3],
  [48, '1 Иоанна', 5],
  [49, '2 Иоанна', 1],
  [50, '3 Иоанна', 1],
  [51, 'Иуды', 1],
  [52, 'Римлянам', 16],
  [53, '1 Коринфянам', 16],
  [54, '2 Коринфянам', 13],
  [55, 'Галатам', 6],
  [56, 'Ефесянам', 6],
  [57, 'Филиппийцам', 4],
  [58, 'Колоссянам', 4],
  [59, '1 Фессалоникийцам', 5],
  [60, '2 Фессалоникийцам', 3],
  [61, '1 Тимофею', 6],
  [62, '2 Тимофею', 4],
  [63, 'Титу', 3],
  [64, 'Филимону', 1],
  [65, 'Евреям', 13],
  [66, 'Откровение', 22],
];

function decodeHtmlEntities(s) {
  return s
    .replace(/&nbsp;/gi, ' ')
    .replace(/&mdash;/g, '—')
    .replace(/&ndash;/g, '–')
    .replace(/&laquo;/g, '«')
    .replace(/&raquo;/g, '»')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'")
    .replace(/&amp;/g, '&')
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
}

function stripTags(html) {
  return decodeHtmlEntities(html.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim());
}

function parseChapterVerses(html) {
  const marker = 'itemprop="articleBody"';
  const i = html.indexOf(marker);
  if (i < 0) return null;
  const slice = html.slice(i, i + Math.min(html.length - i, 900_000));
  const openRe = /<div class="text[^"]*"[^>]*data-book="\d+"[^>]*data-chapter="\d+"/;
  const m = slice.match(openRe);
  if (!m || m.index === undefined) return null;
  const from = slice.slice(m.index);
  const re = /<div id="(\d+)"[^>]*>\s*<sup>\s*\d+\s*<\/sup>\s*([\s\S]*?)<\/div>/g;
  const verses = [];
  let x;
  while ((x = re.exec(from)) !== null) {
    const n = parseInt(x[1], 10);
    const text = stripTags(x[2]);
    if (text.length > 0) verses.push({ verse: n, text });
  }
  verses.sort((a, b) => a.verse - b.verse);
  return verses;
}

function sleep(ms) {
  return new Promise((r) => setTimeout(r, ms));
}

async function fetchHtml(bookId, chapter) {
  const url = `${ORIGIN}/${CODE}/${bookId}/${chapter}/`;
  const res = await fetch(url, {
    headers: { 'User-Agent': 'CatholicPrayerBookBy-scripture-build/1.0' },
    redirect: 'follow',
  });
  if (!res.ok) throw new Error(`HTTP ${res.status} ${url}`);
  return res.text();
}

const DELAY_MS = 140;

async function main() {
  const outPath = path.join(__dirname, '..', 'app', 'src', 'main', 'assets', 'syn_full.json');
  const books = [];

  for (const [bookId, bookName, chapterCount] of BOOKS) {
    const chapters = [];
    for (let ch = 1; ch <= chapterCount; ch++) {
      let html;
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          html = await fetchHtml(bookId, ch);
          break;
        } catch (e) {
          if (attempt === 2) throw e;
          await sleep(500 * (attempt + 1));
        }
      }
      const verses = parseChapterVerses(html);
      if (!verses || verses.length === 0) {
        throw new Error(`Няма вершаў: кніга ${bookId} ${bookName}, глава ${ch}`);
      }
      chapters.push({
        chapter: ch,
        title: '',
        paragraphs: [],
        verses,
      });
      process.stdout.write(`\r${bookName} ${ch}/${chapterCount}   `);
      await sleep(DELAY_MS);
    }
    books.push({
      book_id: bookId,
      book_name: bookName,
      chapter_count: chapterCount,
      chapters,
    });
  }

  const root = {
    translation_code: 'synodal_ru',
    books,
  };
  fs.writeFileSync(outPath, JSON.stringify(root), 'utf8');
  console.log(`\nЗапісана: ${outPath}`);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
