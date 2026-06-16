import { writeFileSync } from "node:fs";

const TRANSLATION = process.argv[2];
if (!TRANSLATION) {
  console.error("Usage: SCRIPTURE_SCRAPE_BASE=https://host/path/ node scrape_bible_translation.mjs <translation-code>");
  process.exit(1);
}

const root = (process.env.SCRIPTURE_SCRAPE_BASE || "").replace(/\/?$/, "/");
if (!root || !/^https?:\/\
  console.error("Set SCRIPTURE_SCRAPE_BASE to the URL prefix before the translation folder (e.g. https://example.com/).");
  process.exit(1);
}
const BASE = `${root}${TRANSLATION}/`;
const RETRY_COUNT = 5;
const RETRY_DELAY_MS = 800;

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function fetchWithRetry(url, retries = RETRY_COUNT) {
  let lastError = null;
  for (let i = 0; i < retries; i += 1) {
    try {
      const res = await fetch(url);
      return res;
    } catch (err) {
      lastError = err;
      await sleep(RETRY_DELAY_MS * (i + 1));
    }
  }
  throw lastError;
}

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

function parseBooksFromIndex(html) {
  const books = [];
  const seen = new Set();
  const rx = new RegExp(`href=["']\\/${TRANSLATION}\\/([0-9]+)\\/1\\/["'][^>]*>([^<]+)<`, "gi");
  let m;
  while ((m = rx.exec(html)) !== null) {
    const id = Number(m[1]);
    if (seen.has(id)) continue;
    seen.add(id);
    books.push({ id, name: cleanText(m[2]) });
  }
  return books;
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
  const res = await fetchWithRetry(url);
  if (!res.ok) return null;
  const html = await res.text();
  if (html.includes("404") && html.includes("not found")) return null;
  const payload = buildChapterPayload(html);
  if (!payload) return null;
  return { chapter, ...payload };
}

const indexRes = await fetchWithRetry(BASE);
if (!indexRes.ok) {
  console.error(`Failed to fetch index: ${BASE}`);
  process.exit(2);
}
const indexHtml = await indexRes.text();
const books = parseBooksFromIndex(indexHtml);
if (!books.length) {
  console.error(`No books found for ${TRANSLATION}`);
  process.exit(3);
}

const output = {
  source: BASE,
  translation_code: TRANSLATION,
  books: [],
};

for (const book of books) {
  const chapters = [];
  for (let ch = 1; ch <= 200; ch += 1) {
    const data = await fetchChapter(book.id, ch);
    if (!data) break;
    chapters.push(data);
    process.stderr.write(`\r${TRANSLATION} ${book.name}: ${ch}`);
  }
  process.stderr.write("\n");
  output.books.push({
    book_id: book.id,
    book_name: book.name,
    chapter_count: chapters.length,
    chapters,
  });
}

const outFile = `${TRANSLATION}_full.json`;
writeFileSync(outFile, JSON.stringify(output, null, 2), "utf8");

const totalChapters = output.books.reduce((n, b) => n + b.chapter_count, 0);
const totalVerses = output.books.reduce(
  (n, b) => n + b.chapters.reduce((m, c) => m + c.verses.length, 0),
  0
);
console.log(`Saved ${outFile} | books=${output.books.length} chapters=${totalChapters} verses=${totalVerses}`);
