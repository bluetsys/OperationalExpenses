# 서울시 본청 업무추진비 분석 리포트

서울시 본청 업무추진비 CSV를 SQLite 데이터베이스로 변환한 뒤, PHP 페이지에서 분석 결과를 조회하는 프로젝트입니다.

## 데이터 출처 및 갱신

CSV 원본은 [서울 열린데이터광장 - 서울시 본청 업무추진비 목록](https://data.seoul.go.kr/dataList/OA-22156/S/1/datasetView.do)에서 내려받습니다.

1. 위 데이터 페이지에서 CSV 파일을 내려받습니다.
2. 내려받은 파일 이름을 `서울시 본청 업무추진비 목록.csv`로 맞추고 프로젝트 폴더에 둡니다. 기존 CSV를 교체할 때도 같은 이름을 사용합니다.
3. 프로젝트 폴더에서 다음 명령을 실행합니다.

   ```powershell
   node convert-csv-to-sqlite.mjs
   ```

4. 변환이 끝나면 `서울시 본청 업무추진비 목록.sqlite`가 생성 또는 갱신됩니다. 성공 메시지에 변환된 행 수가 표시됩니다.
5. 웹 페이지를 새로고침하면 갱신된 데이터가 표시됩니다.

변환 스크립트는 UTF-8 및 CP949 계열 CSV를 처리하고, PHP 분석에 필요한 열만 SQLite에 저장합니다. 변환에는 Node.js 22.13 이상(Node.js 24 권장, `node:sqlite` 지원)이 필요합니다.

## 실행 조건

- PHP 8 이상
- PHP의 `PDO_SQLITE` 확장 활성화
- CSV 변환 시 Node.js 22.13 이상

### PHP 설치

OS별 설치 방법은 [Coddy PHP 설치 안내](https://coddy.tech/docs/ko/php/install-php)를 참고하세요. 설치 후 명령 프롬프트나 터미널에서 `php -v`를 실행해 설치를 확인합니다.

이 프로젝트는 SQLite를 사용하므로 PHP에서 `PDO_SQLITE` 확장도 활성화해야 합니다. 다음 명령으로 드라이버가 표시되는지 확인할 수 있습니다.

```sh
php -r "print_r(PDO::getAvailableDrivers());"
```

출력 목록에 `sqlite`가 있어야 합니다. 표시되지 않으면 사용 중인 PHP의 `php.ini`에서 `extension=pdo_sqlite` 설정을 확인하고 PHP를 다시 시작하세요.

### PHP 로컬 서버 실행

터미널에서 프로젝트 폴더로 이동한 다음 PHP 내장 서버를 실행합니다.

Windows PowerShell:

```powershell
cd D:\jobs\wwwroot\fun\OperationalExpenses
php -S localhost:8000
```

macOS 또는 Linux:

```sh
cd /path/to/OperationalExpenses
php -S localhost:8000
```

브라우저에서 <http://localhost:8000>을 열면 리포트를 볼 수 있습니다. 서버를 종료할 때는 실행 중인 터미널에서 `Ctrl+C`를 누릅니다. 이 서버는 개발 및 로컬 확인용입니다.

페이지 실행 전에 `서울시 본청 업무추진비 목록.sqlite`가 있어야 합니다. SQLite 파일이 없거나 `pdo_sqlite`가 활성화되지 않았으면 페이지에 안내 메시지가 표시됩니다.

## 파일 구성

- `index.php`: SQLite 데이터를 읽어 분석 리포트를 표시합니다.
- `convert-csv-to-sqlite.mjs`: CSV를 SQLite 데이터베이스로 변환합니다.
- `서울시 본청 업무추진비 목록.csv`: 서울 열린데이터광장에서 내려받은 원본 CSV입니다.
- `서울시 본청 업무추진비 목록.sqlite`: 변환된 데이터베이스입니다.

## 참고

페이지의 집행 건수는 장소 방문 횟수가 아니라 CSV의 집행 기록 건수입니다. 주소가 붙은 가맹점명은 주소 부분을 제거해 집계하며, 지점명이나 상호명 변형은 별도 장소로 처리합니다.
