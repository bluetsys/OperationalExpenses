<?php

declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
ini_set('memory_limit', '512M');
set_time_limit(120);

// CSV는 node convert-csv-to-sqlite.mjs로 SQLite로 변환해 둡니다.
$dbFile = __DIR__ . '/서울시 본청 업무추진비 목록.sqlite';
$selectedYear = (string)($_GET['year'] ?? 'all');
$selectedDept = (string)($_GET['dept'] ?? 'all');
$selectedCategory = (string)($_GET['category'] ?? 'all');
$search = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'count');
if (!in_array($sort, ['count', 'amount', 'average'], true)) $sort = 'count';
if (!in_array($selectedCategory, ['all', 'external', 'internal'], true)) $selectedCategory = 'all';
function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function won($n): string
{
    return number_format((float)$n) . '원';
}
function num($n): string
{
    return number_format((float)$n);
}
function normalizePlace(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
    // 가맹점 뒤 괄호 안 주소 제거: 상호명(서울 중구 ...)
    $s = preg_replace('/\s*\([^)]*(?:서울|경기|인천|부산|대구|광주|대전|울산|세종|강원|충청|전라|경상|제주|\d{2,})[^)]*\)\s*$/u', '', $s) ?? $s;
    return trim($s);
}
function internalPlace(string $p): bool
{
    return (bool)preg_match('/서울시청|서울특별시청|구내식당|카페테리아|간담회장|시청매점|시청신매점/u', $p);
}
function addStat(array &$a, string $key, int $amount): void
{
    if (!isset($a[$key])) $a[$key] = ['count' => 0, 'amount' => 0];
    $a[$key]['count']++;
    $a[$key]['amount'] += $amount;
}
$errors = [];
$allYears = [];
$allDepts = [];
$places = [];
$departments = [];
$months = [];
$totalRows = 0;
$matchedRows = 0;
$totalAmount = 0;
$noPlace = 0;
if (!is_file($dbFile)) {
    $errors[] = 'SQLite 데이터베이스가 없습니다. 먼저 node convert-csv-to-sqlite.mjs를 실행하세요.';
} elseif (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    $errors[] = 'PHP PDO SQLite 확장이 활성화되어 있지 않습니다. pdo_sqlite를 활성화하세요.';
} else {
    try {
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $headers = json_decode((string)$pdo->query("SELECT value FROM metadata WHERE key='headers'")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
        $index = array_flip($headers);
        $required = ['해당년도', '전체부서명', '부서명', '집행일시', '집행장소', '집행금액'];
        foreach ($required as $c) if (!isset($index[$c])) $errors[] = '필수 컬럼이 없습니다: ' . $c;
        if (!$errors) {
            $columns = array_map(fn($i) => '"c' . $i . '"', array_keys($headers));
            $query = $pdo->query('SELECT ' . implode(', ', $columns) . ' FROM records');
            while (($row = $query->fetch(PDO::FETCH_NUM)) !== false) {
                $totalRows++;
                $year = trim((string)$row[$index['해당년도']]);
                $dept = trim((string)($row[$index['전체부서명']] ?: $row[$index['부서명']]));
                if ($dept === '') $dept = '미분류';
                $allYears[$year] = true;
                $allDepts[$dept] = true;
                if ($selectedYear !== 'all' && $year !== $selectedYear) continue;
                if ($selectedDept !== 'all' && $dept !== $selectedDept) continue;
                $place = normalizePlace((string)$row[$index['집행장소']]);
                if ($selectedCategory === 'external' && internalPlace($place)) continue;
                if ($selectedCategory === 'internal' && !internalPlace($place)) continue;
                if ($search !== '' && mbSafePos($place, $search) === false) continue;
                $amountText = preg_replace('/[^\d-]/', '', (string)$row[$index['집행금액']]);
                $amount = (int)$amountText;
                $matchedRows++;
                $totalAmount += $amount;
                addStat($departments, $dept, $amount);
                $date = substr(trim((string)$row[$index['집행일시']]), 0, 7);
                if (preg_match('/^\d{4}-\d{2}$/', $date)) addStat($months, $date, $amount);
                if ($place === '') {
                    $noPlace++;
                    continue;
                }
                addStat($places, $place, $amount);
            }
        }
        $totalRows = (int)$pdo->query("SELECT value FROM metadata WHERE key='row_count'")->fetchColumn();
    } catch (Throwable $e) {
        $errors[] = 'SQLite 데이터베이스를 읽지 못했습니다: ' . $e->getMessage();
    }
}
function mbSafePos(string $haystack, string $needle)
{
    if (function_exists('mb_stripos')) return mb_stripos($haystack, $needle, 0, 'UTF-8');
    return stripos($haystack, $needle);
}
uksort($allYears, 'strnatcmp');
uksort($allDepts, 'strnatcmp');
foreach ($places as &$v) $v['average'] = $v['count'] ? $v['amount'] / $v['count'] : 0;
unset($v);
$placeRows = [];
foreach ($places as $name => $v) $placeRows[] = ['place' => $name] + $v;
usort($placeRows, function ($a, $b) use ($sort) {
    $r = $b[$sort] <=> $a[$sort];
    return $r ?: strcmp((string)$a['place'], (string)$b['place']);
});
$deptRows = [];
foreach ($departments as $name => $v) $deptRows[] = ['name' => $name] + $v;
usort($deptRows, fn($a, $b) => ($b['amount'] <=> $a['amount']) ?: strcmp((string)$a['name'], (string)$b['name']));
ksort($months);
$top = array_slice($placeRows, 0, 100);
$topChart = array_slice($placeRows, 0, 15);
$chartMax = max(array_merge([1], array_map(fn($v) => (float)$v[$sort], $topChart)));
$monthly = array_slice($months, -24, null, true);
$maxMonthly = max(array_merge([1], array_values(array_map(fn($v) => $v['amount'], $monthly))));
$downloadParams = $_GET;
$downloadParams['export'] = 'csv';
if (($_GET['export'] ?? '') === 'csv' && !$errors) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="seoul_places_report.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['순위', '집행장소', '이용건수', '총집행금액(원)', '1건평균(원)']);
    foreach ($placeRows as $i => $p) fputcsv($out, [$i + 1, $p['place'], $p['count'], $p['amount'], round($p['average'])]);
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>서울시 본청 업무추진비 분석 리포트</title>
    <link rel="stylesheet" href="style.css">
</head>

<body>
    <main class="wrap">
        <h1>서울시 본청 업무추진비 분석 리포트</h1>
        <p class="sub">CSV 원본 자동 분석 · 가장 많이 이용한 장소 / 가장 많이 지출한 장소 / 부서별 지출 / 월별 추이</p>
        <?php foreach ($errors as $e): ?><div class="empty"><?= h($e) ?></div><?php endforeach; ?>
        <form class="filters" method="get">
            <div><label for="year">연도</label><select id="year" name="year">
                    <option value="all">전체 연도</option><?php foreach ($allYears as $y => $_): ?><option value="<?= h($y) ?>" <?= $selectedYear === (string)$y ? 'selected' : '' ?>><?= h($y) ?>년</option><?php endforeach; ?>
                </select></div>
            <div><label for="dept">부서</label><select id="dept" name="dept">
                    <option value="all">전체 부서</option><?php foreach ($allDepts as $d => $_): ?><option value="<?= h($d) ?>" <?= $selectedDept === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?>
                </select></div>
            <div><label for="category">장소 구분</label><select id="category" name="category">
                    <option value="all" <?= $selectedCategory === 'all' ? 'selected' : '' ?>>전체 장소</option>
                    <option value="external" <?= $selectedCategory === 'external' ? 'selected' : '' ?>>시청 내부 시설 제외</option>
                    <option value="internal" <?= $selectedCategory === 'internal' ? 'selected' : '' ?>>시청 내부 시설만</option>
                </select></div>
            <div><label for="sort">순위 기준</label><select id="sort" name="sort">
                    <option value="count" <?= $sort === 'count' ? 'selected' : '' ?>>이용 건수</option>
                    <option value="amount" <?= $sort === 'amount' ? 'selected' : '' ?>>총 집행금액</option>
                    <option value="average" <?= $sort === 'average' ? 'selected' : '' ?>>1건 평균금액</option>
                </select></div>
            <div><label for="q">장소명 검색</label><input id="q" name="q" value="<?= h($search) ?>" placeholder="예: 삼우정"></div>
            <div class="actions"><button type="submit">분석</button><a class="btn gray" href="<?= h(basename($_SERVER['SCRIPT_NAME'] ?? 'index.php')) ?>">초기화</a></div>
        </form>
        <div class="stats">
            <div class="stat"><span>조건에 맞는 집행 건수</span><strong><?= num($matchedRows) ?></strong></div>
            <div class="stat"><span>총 집행금액</span><strong><?= number_format($totalAmount / 100000000, 2) ?>억 원</strong></div>
            <div class="stat"><span>이용 장소 수</span><strong><?= num(count($places)) ?></strong></div>
            <div class="stat"><span>장소 미기재 건수</span><strong><?= num($noPlace) ?></strong></div>
        </div>
        <div class="two">
            <section class="panel">
                <h2><?= $sort === 'count' ? '이용 건수' : ($sort === 'amount' ? '총 집행금액' : '1건 평균금액') ?> TOP 15</h2>
                <div class="bars"><?php foreach ($topChart as $p): ?><div class="barrow">
                            <div class="name" title="<?= h($p['place']) ?>"><?= h($p['place']) ?></div>
                            <div class="track">
                                <div class="fill" style="width:<?= number_format(100 * $p[$sort] / $chartMax, 2, '.', '') ?>%"></div>
                            </div>
                            <div class="val"><?= $sort === 'count' ? num($p['count']) . '건' : ($sort === 'amount' ? num($p['amount']) . '원' : num(round($p['average'])) . '원') ?></div>
                        </div><?php endforeach; ?><?php if (!$topChart): ?><p class="sub">해당 조건의 장소 자료가 없습니다.</p><?php endif; ?></div>
            </section>
            <section class="panel">
                <h2>월별 집행금액 추이 (최근 최대 24개월)</h2>
                <div class="monthbars"><?php foreach ($monthly as $m => $v): ?><div class="monthitem" tabindex="0" title="<?= h($m) ?> · <?= num($v['count']) ?>건 · <?= won($v['amount']) ?>" style="height:<?= max(1, 100 * $v['amount'] / $maxMonthly) ?>%"></div><?php endforeach; ?></div>
                <div class="monthlabels"><span><?= h((string)array_key_first($monthly)) ?></span><span><?= h((string)array_key_last($monthly)) ?></span></div>
                <p class="hint">막대에 마우스를 올리면 월별 건수·금액을 볼 수 있습니다. 집행일시 기준입니다.</p>
            </section>
        </div>
        <section class="panel">
            <div class="sectionHead">
                <h2>장소별 상세 순위 TOP 100</h2>
                <div class="actions"><a class="btn" href="?<?= h(http_build_query($downloadParams)) ?>">전체 장소 CSV 저장</a></div>
            </div>
            <div class="tablewrap">
                <table>
                    <thead>
                        <tr>
                            <th>순위</th>
                            <th>집행장소</th>
                            <th class="right">이용 건수</th>
                            <th class="right">집행금액</th>
                            <th class="right">1건 평균</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach ($top as $i => $p): ?><tr>
                                <td><?= num($i + 1) ?></td>
                                <td><?= h($p['place']) ?></td>
                                <td class="right"><?= num($p['count']) ?></td>
                                <td class="right"><?= won($p['amount']) ?></td>
                                <td class="right"><?= won(round($p['average'])) ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <section class="panel">
            <h2>지출금액 상위 부서 TOP 30</h2>
            <div class="tablewrap">
                <table>
                    <thead>
                        <tr>
                            <th>순위</th>
                            <th>부서명</th>
                            <th class="right">집행 건수</th>
                            <th class="right">집행금액</th>
                        </tr>
                    </thead>
                    <tbody><?php foreach (array_slice($deptRows, 0, 30) as $i => $d): ?><tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= h($d['name']) ?></td>
                                <td class="right"><?= num($d['count']) ?></td>
                                <td class="right"><?= won($d['amount']) ?></td>
                            </tr><?php endforeach; ?></tbody>
                </table>
            </div>
        </section>
        <footer>데이터 출처: <a href="https://data.seoul.go.kr/dataList/OA-22156/S/1/datasetView.do" target="_blank" rel="noopener noreferrer">서울 열린데이터광장 · 서울시 본청 업무추진비 목록</a> · <?= h(basename($dbFile)) ?> 기준 원본 <?= num($totalRows) ?>건. 장소별 이용 횟수는 방문 횟수가 아닌 집행 기록 건수입니다. 주소가 뒤에 붙은 가맹점명은 주소 부분을 제거하여 집계합니다. 상호명 변형 및 지점명은 별도 장소로 처리하며, 내부 시설 판별은 명칭 기반입니다.</footer>
    </main>
</body>

</html>
