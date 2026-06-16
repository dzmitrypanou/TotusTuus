import { writeFileSync } from "node:fs";

const BASE = (process.env.SCRIPTURE_SCRAPE_BASE || "").replace(/\/?$/, "/");
if (!BASE || !/^https?:\/\
  console.error("Set SCRIPTURE_SCRAPE_BASE to the NT catalog root URL (trailing slash optional).");
  process.exit(1);
}

const BOOKS = [
  { id: 40, name: "Мацвея" },
  { id: 41, name: "Марка" },
  { id: 42, name: "Лукі" },
  { id: 43, name: "Яна" },
  { id: 44, name: "Дзеі" },
  { id: 52, name: "Да Рымлянаў" },
  { id: 53, name: "1 да Карынцянаў" },
  { id: 54, name: "2 да Карынцянаў" },
  { id: 55, name: "Да Галатаў" },
  { id: 56, name: "Да Эфесцаў" },
  { id: 57, name: "Да Філіпянаў" },
  { id: 58, name: "Да Каласянаў" },
  { id: 59, name: "1 да Тэсаланікійцаў" },
  { id: 60, name: "2 да Тэсаланікійцаў" },
  { id: 61, name: "1 да Цімафея" },
  { id: 62, name: "2 да Цімафея" },
  { id: 63, name: "Да Ціта" },
  { id: 64, name: "Да Філімона" },
  { id: 65, name: "Да Габрэяў" },
  { id: 45, name: "Якуба" },
  { id: 46, name: "1 Пятра" },
  { id: 47, name: "2 Пятра" },
  { id: 48, name: "1 Яна" },
  { id: 49, name: "2 Яна" },
  { id: 50, name: "3 Яна" },
  { id: 51, name: "Юды" },
  { id: 66, name: "Апакаліпсіс" },
];

function cleanText(html) {
  return html
    .replace(/<script[\s\S]*?<\/script>/gi, "")
    .replace(/<style[\s\S]*?<\/style>/gi, "")
    .replace(/<br\s*\/?>/gi, "\n")
    .replace(/<\/p>/gi, "\n")
    .replace(/<\/h[1-6]>/gi, "\n")
    .replace(/<[^>]+>/g, " ")
    .replace(/&nbsp;/g, " ")
    .replace(/&laquo;/g, "«")
    .replace(/&raquo;/g, "»")
    .replace(/&quot;/g, "\"")
    .replace(/&#39;/g, "'")
    .replace(/&amp;/g, "&")
    .replace(/[ \t]+\n/g, "\n")
    .replace(/\n{3,}/g, "\n\n")
    .trim();
}

function extractTextBlock(html) {
  const match = html.match(/<div class="text[^"]*"[^>]*>([\s\S]*?)<\/div>\s*<script/i);
  return match ? match[1] : "";
}

function toVerses(htmlBlock) {
  const verses = [];
  const rx = /<sup[^>]*>(\d+)<\/sup>\s*([\s\S]*?)(?=<sup[^>]*>\d+<\/sup>|$)/gi;
  let m;
  while ((m = rx.exec(htmlBlock)) !== null) {
    const verseNum = Number(m[1]);
    const verseText = cleanText(m[2]).replace(/\s+/g, " ").trim();
    if (!Number.isNaN(verseNum) && verseText) {
      verses.push({ verse: verseNum, text: verseText });
    }
  }
  return verses;
}

function chapterTitle(htmlBlock) {
  const lines = cleanText(htmlBlock).split("\n").map((l) => l.trim()).filter(Boolean);
  const firstNonVerse = lines.find((l) => !/^\d+\s+/.test(l));
  return firstNonVerse || "";
}

function toParagraphs(htmlBlock) {
  const lines = cleanText(htmlBlock).split("\n").map((l) => l.trim()).filter(Boolean);
  return lines.filter((l) => !/^\d+\s+/.test(l));
}

function buildChapterPayload(html) {
  const block = extractTextBlock(html);
  if (!block) return null;
  const verses = toVerses(block);
  if (!verses.length) return null;
  return {
    title: chapterTitle(block),
    paragraphs: toParagraphs(block),
    verses,
  };
}

async function fetchChapter(bookId, chapter) {
  const url = `${BASE}${bookId}/${chapter}/`;
  const res = await fetch(url);
  if (!res.ok) return null;
  const html = await res.text();
  if (html.includes("404") && html.includes("not found")) return null;
  const payload = buildChapterPayload(html);
  if (!payload) return null;
  return { chapter, ...payload };
}

const output = { source: BASE, books: [] };

for (const book of BOOKS) {
  const chapters = [];
  for (let ch = 1; ch <= 200; ch += 1) {
    const data = await fetchChapter(book.id, ch);
    if (!data) break;
    chapters.push(data);
    process.stderr.write(`\r${book.name}: ${ch}`);
  }
  process.stderr.write("\n");
  output.books.push({
    book_id: book.id,
    book_name: book.name,
    chapter_count: chapters.length,
    chapters,
  });
}

writeFileSync("bcat_nt_full.json", JSON.stringify(output, null, 2), "utf8");

const totalChapters = output.books.reduce((n, b) => n + b.chapter_count, 0);
const totalVerses = output.books.reduce(
  (n, b) => n + b.chapters.reduce((m, c) => m + c.verses.length, 0),
  0
);
console.log(`Saved bcat_nt_full.json | books=${output.books.length} chapters=${totalChapters} verses=${totalVerses}`);
