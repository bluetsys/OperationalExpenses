import { readFile, rename, rm } from 'node:fs/promises';
import { DatabaseSync } from 'node:sqlite';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const dir = dirname(fileURLToPath(import.meta.url));
const csvPath = join(dir, '서울시 본청 업무추진비 목록.csv');
const dbPath = join(dir, '서울시 본청 업무추진비 목록.sqlite');
const tmpPath = `${dbPath}.tmp`;

// 서울시 CSV는 CP949로 제공되는 경우가 많습니다. UTF-8로 먼저 해석하고
// 유효하지 않으면 브라우저와 동일한 WHATWG euc-kr 디코더(CP949 호환)를 씁니다.
const bytes = await readFile(csvPath);
let content;
try {
  const utf8 = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
  content = utf8;
} catch {
  content = new TextDecoder('euc-kr').decode(bytes);
}

function* parseCsv(text) {
  let row = [], field = '', quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') { field += '"'; i++; }
      else if (c === '"') quoted = false;
      else field += c;
    } else if (c === '"' && field === '') quoted = true;
    else if (c === ',') { row.push(field); field = ''; }
    else if (c === '\n') { row.push(field.replace(/\r$/, '')); yield row; row = []; field = ''; }
    else field += c;
  }
  if (field !== '' || row.length) { row.push(field.replace(/\r$/, '')); yield row; }
}

await rm(tmpPath, { force: true });
const db = new DatabaseSync(tmpPath);
try {
  // DELETE 모드를 사용해 DB 옆에 -wal/-shm 파일이 남지 않게 합니다.
  db.exec('PRAGMA journal_mode = DELETE; PRAGMA synchronous = NORMAL;');
  const iterator = parseCsv(content);
  const sourceHeader = iterator.next().value?.map((v, i) => i === 0 ? v.replace(/^\uFEFF/, '').trim() : v.trim());
  if (!sourceHeader?.length) throw new Error('CSV 헤더를 읽을 수 없습니다.');
  const required = ['해당년도', '전체부서명', '부서명', '집행일시', '집행장소', '집행금액'];
  for (const col of required) if (!sourceHeader.includes(col)) throw new Error(`필수 컬럼이 없습니다: ${col}`);
  const sourceIndexes = required.map(col => sourceHeader.indexOf(col));
  const header = required;

  const columns = header.map((_, i) => `c${i}`);
  db.exec(`CREATE TABLE records (${columns.map(c => `"${c}" TEXT`).join(', ')})`);
  db.exec('CREATE TABLE metadata (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
  const insert = db.prepare(`INSERT INTO records VALUES (${columns.map(() => '?').join(', ')})`);
  db.exec('BEGIN');
  let count = 0;
  for (const row of iterator) {
    if (row.length < sourceHeader.length) continue;
    insert.run(...sourceIndexes.map(i => row[i] ?? ''));
    count++;
  }
  const meta = db.prepare('INSERT INTO metadata(key,value) VALUES (?,?)');
  meta.run('headers', JSON.stringify(header));
  meta.run('row_count', String(count));
  db.exec('COMMIT');
  db.exec('CREATE INDEX idx_records_year ON records(c0)');
  db.close();
  await rename(tmpPath, dbPath);
  console.log(`완료: ${count.toLocaleString()}건 → ${dbPath}`);
} catch (error) {
  try { db.exec('ROLLBACK'); } catch { }
  db.close();
  await rm(tmpPath, { force: true });
  throw error;
}
