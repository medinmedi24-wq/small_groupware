# M&M WORKS — 개발자 전달 문서

기준일: 2026-09-15 · 대상: 현재 작업 트리의 PHP 소스

사용자 기능 설명은 [README.md](README.md)를 참고하세요. 아래 내용은 최신 소스 인수인계 기준입니다. 기존 QA·복구·검증 문서는 작성 당시 이력이며, 과거 ‘완료’ 표기를 최신 배포 완료로 해석하지 않습니다.

## 1. 인수 시 먼저 확인할 사항

- 현재 실행 소스는 `php-version/`입니다. React/Node 원본은 작업 폴더 외부 보관 자료입니다.
- 작업 트리에는 여러 차례 기능·QA 수정이 포함되어 있습니다. 인수 시 Git 상태와 실제 파일을 확인하고 변경분을 보존하세요.
- 최신 소스를 실제 호스팅에 배포한 상태가 아닙니다. 기존 release ZIP이 최신이라는 보장도 없습니다. 전달용 ZIP은 최신 소스로 다시 생성·검증해야 합니다.
- 현재 업무 DB는 `php-version/storage/live/groupware.db`, 파일 저장소는 `php-version/storage/live/files/`, 설정은 `php-version/config/local.php`입니다. 실제 실행 시 `MNM_CONFIG`가 다른 파일을 지정하는지도 확인하세요.
- `.tmp/phone-test-*`는 별도 DB·설정을 가진 휴대폰 테스트 복사본입니다. 최근 UI 수정은 해당 복사본에도 반영했지만 자동 배포·자동 동기화 구조가 아닙니다.
- 테스트 로그인 정보·임시 터널 주소·비공개 설정값은 이 문서에 포함하지 않습니다. 소스와 별도 경로로 전달하세요.

## 2. 런타임 및 로컬 실행

PHP 8.2 이상, PDO, mbstring, fileinfo, zip 및 DB 드라이버가 필요합니다. 로컬은 pdo_sqlite, 운영 대상은 pdo_mysql을 사용하는 MySQL/MariaDB(InnoDB, utf8mb4)입니다. Composer·Node·npm·별도 API 서버는 웹앱 실행에 필요하지 않습니다. Python과 Node는 일부 개발 테스트에만 사용합니다.

현재 PC에서 프로젝트 루트 기준:

```powershell
.\start-php.cmd
```

접속은 `http://127.0.0.1:4082`입니다. 스크립트는 `.tmp/php-runtime/bin/php.exe`와 `php-version/config/local.php`를 사용합니다. 새 개발 환경에서는 PHP를 별도로 준비해야 합니다.

별도 개발 DB로 실행하는 예시:

```powershell
$env:MNM_CONFIG = 'C:\개발용설정\local.php'
php -S 127.0.0.1:4082 -t php-version/public
```

기존 업무 설정을 새 예시 설정으로 덮어쓰지 마세요. PHP 내장 서버는 로컬 확인용입니다.

## 3. 코드 구조

| 위치 | 역할 |
| --- | --- |
| `php-version/public/index.php` | 진입점, 세션·요청 처리, 공통 레이아웃·권한별 메뉴 |
| `app/bootstrap.php` | 설정, PDO, 트랜잭션, 사용자 잠금, 인증·검증·URL |
| `app/domain.php` | 연차 계산·예약, 데이터 조회, 변경 이력, 검토 토큰·휴일 보호 |
| `app/approval-policy.php` | 지정 최종 관리자 및 결재·본인 수정 정책 |
| `app/actions.php` | 휴가·직원·카드·휴일 POST 처리, 증빙 교체 |
| `app/views.php`, `app/ui.php` | 폼·목록·대시보드·필터·보고서 진입 화면 |
| `app/attendance*.php` | 근태 규칙·화면·GPS 및 기존 네트워크 확인 기록 |
| `app/downloads.php` | 증빙 및 보고서 다운로드 |
| `public/assets/app.js` | 팝업, 모바일 메뉴, 입력 보조, 연차 미리보기 |
| `public/assets/attendance-gps.js`, `attendance-sync.js` | 위치 확인 및 근태 변경 감지 |
| `database/` | 기본 스키마와 근태 추가 스키마, SQLite/MySQL 구분 |
| `tools/`, `tests/` | 설치·검사·이전·패키징 및 격리 테스트 |

표에서 `app/` 이하 경로는 `php-version/` 기준입니다. 화면은 PHP 렌더링과 일반 HTML POST를 사용합니다. 주소는 `index.php?page=...`이며 기존 React/Express `/api` 계약을 유지하는 서버가 아닙니다. 수정 팝업도 서버 렌더링된 폼을 가져오며 JS 미사용 시 페이지로 이동합니다.

## 4. 설정과 데이터 기준

`config/example.php`를 기준으로 비공개 `local.php`를 준비합니다.

| 설정 | 의미 |
| --- | --- |
| `dsn`, `username`, `password` | DB 접속 |
| `storage` | 웹 공개 루트 밖의 영구 파일 저장 경로 |
| `secure_cookie` | HTTPS 운영에서는 true, 로컬 HTTP 검증에서만 false |
| `timezone` | Asia/Seoul |
| `final_approver_id` | 최종 승인 담당 ADMIN의 사용자 ID |
| `setup_token` | 최초 설치 시에만 사용하는 임의 문자열, 설치 후 비우기 |
| `attendance_office_ips` | 기존 회사 네트워크 확인 관련 설정. 모바일 GPS를 대체하지 않음 |

`MNM_FINAL_APPROVER_ID`가 있으면 `final_approver_id`보다 우선합니다. 프로젝트 루트 `.env`는 이 PHP 설정 체계에서 읽지 않습니다.

DB 사건 시각은 UTC로 보관하고 화면에서 KST로 표시합니다. 근무일·휴가일·지출일 같은 업무 날짜는 별도 날짜 값입니다. 날짜와 UTC 타임스탬프를 혼동하지 마세요.

## 5. 반드시 유지할 업무 규칙

### 권한·휴가

- 일반 직원·대리·과장: 팀장 → 지정 관리자. 팀장·이사·부장: 지정 관리자 직결.
- 모든 ADMIN이 최종 결재자가 되는 것은 아닙니다. 팀장 결재는 같은 부서의 타인 신청만 가능합니다.
- 최초 결재 전 본인 수정·취소가 가능하며, 직결 신청은 저장된 결재 경로·기존 처리 이력으로 판단합니다.
- `reviewToken`은 기간·종류·일수·사유·상태 등의 스냅샷입니다. 소유자 잠금 후 최신 데이터와 비교합니다. CSRF를 대체하지 않습니다.
- 휴가 승인/반려, 수정, 본인 취소, 관리자 승인 취소는 오래된 토큰을 차단합니다. 오류 재표시에서 제출한 토큰을 유지해 재제출 우회를 막습니다.
- `lockLeaveCalendar()`는 휴일 쓰기와 휴가 쓰기를 직렬화하기 위해 관리자 잠금을 소유자 잠금보다 먼저 취합니다. 잠금 순서를 임의로 바꾸지 마세요. 최신 변경의 MySQL 동시성은 별도 검증 대상입니다.
- 대기·승인 휴가에 영향을 주는 평일 휴일 추가·삭제는 409로 차단하고 영향 신청 링크를 제공합니다. 자동 재계산으로 기존 승인 일수를 변경하지 않습니다.
- 승인 직전 현재 휴일 기준과 저장 일수를 비교합니다. 불일치 시 수정·취소 후 재확인하며 반려는 가능합니다.

### 근태

- PC는 직접 입력, 모바일은 GPS + 서버 현재 시각입니다. 기기 구분은 보안 인증 수단이 아닙니다.
- GPS 기준은 `app/attendance-location.php`의 정책 버전·좌표·150m 반경·오차 100m·유효시간 2분을 확인하세요. 회사 경계와 오차가 겹치면 거부합니다.
- 출장 기록은 회사 밖 위치와 출장지·사유가 필요합니다. 위치 진위까지 보장하거나 백그라운드 이동을 추적하는 시스템은 아닙니다.
- 직원이 저장된 출퇴근을 바로 수정하지 않습니다. 정정 승인 전까지 원래 기록을 유지하며 관리자는 본인 정정을 승인할 수 없습니다.
- 24시간 초과 미퇴근은 정정으로 처리합니다. GPS와 PC의 같은 분 퇴근은 실제 저장 순서를 확인하는 보완 로직이 있습니다.
- 변경 감지는 약 10초 주기이며 입력·팝업·위치 확인 중 자동 새로고침을 보류합니다. 개인 조회 범위가 관리자 범위로 확대되지 않도록 유지하세요.

### 카드·증빙

- 본인 검토 대기 내역만 수정·취소 가능합니다. 카드 스냅샷 토큰으로 금액·내용·증빙 변경 후의 오래된 처리를 차단합니다.
- 생성 폼 등록 토큰으로 동일 제출의 중복 생성을 방지합니다. 새 폼에서 동일한 내역을 별도 등록하는 것은 가능합니다.
- 증빙은 JPEG/PNG/WebP/PDF, 최대 10MiB, 서버 MIME 검사 및 본인·관리자 접근 제한을 사용합니다.
- 교체 실패 시 기존 파일을 보존하고 새 업로드를 정리합니다. 정상 교체 후 다른 참조가 없는 이전 파일을 정리합니다. 과거 파일 일괄 정리는 수행하지 않았습니다.

## 6. 최근 UI 수정 상태

| 항목 | 상태 |
| --- | --- |
| 모바일 날짜·월 입력 넘침 | 대응 반영, 실기기 최종 확인 필요 |
| 관리자 휴가 지표 카드 | 항상 화살표 표시, 상태 필터로 이동 |
| 팀장·지정 관리자 결재 대기 | 여름휴가 여부와 분리, 해당 결재 단계로 이동 |
| 관리자 카드 검토 대기·승인 완료 | 조회 월 첫날~마지막 날 및 상태를 유지해 이동 |
| 관리자 타인 휴가 수정 제목 | 직원 연차 수정, 본인은 내 연차 유지 |
| 모바일 관리자 수정 후 복귀 | 기존 검색 조건·목록 페이지 유지 확인 |

현재 정적 파일 참조는 `php.css?v=26`, `app.js?v=7`, `attendance-gps.js?v=4`, `attendance-sync.js?v=1`, SW 캐시는 `mnm-php-assets-v28`입니다. 정적 파일 수정 시 실제 `public/index.php`와 `public/sw.js` 참조를 함께 확인하세요. 최근 제목·카드 링크 수정은 PHP만 변경했습니다. 로그인된 HTML은 서비스워커 캐시 대상이 아닙니다.

## 7. 테스트와 증거

최신 결과: **2026-09-15 SQLite 격리 HTTP 회귀 419개 통과**. 마지막 제목 수정은 별도 HTTP로 관리자/직원 제목과 타인 접근 차단도 확인했습니다. 이 수치는 전체 HTTP 테스트이며 419개 모두 새 기능 테스트라는 뜻은 아닙니다.

프로젝트 루트에서:

```powershell
$env:PHP_BIN = 'C:\workspace\mnm-groupware\.tmp\php-runtime\bin\php.exe'
python php-version/tests/http_test.py
python php-version/tests/attendance_test.py
node --test php-version/tests/attendance-gps.test.cjs php-version/tests/attendance-sync.test.cjs
python php-version/tests/deployment_test.py
python php-version/tests/package_test.py
```

위 명령은 인수 후 수행할 검증 목록입니다. 전부를 문서 정리 당일 다시 실행했다는 의미는 아닙니다. HTTP 테스트는 임시 DB·서버를 사용합니다. DB 환경변수에 실제 업무 DB를 지정하지 마세요.

MySQL 검증 도구는 `MNM_TEST_MYSQL_DSN`, `MNM_DEPLOY_MYSQL_DSN`, `MNM_SQL_IMPORT_DSN`을 사용하며 개발용 localhost의 별도 빈 `mnm_php_test_...` DB를 요구합니다. 운영 DB·계정을 넣어 실행하는 도구가 아닙니다.

- [권한·이동 QA 및 수정 완료 기록](php-version/QA-ROLE-NAVIGATION-20260915.md)
- [연차 QA 및 수정 완료 기록](php-version/QA-LEAVES-20260914.md)
- [카드 QA 기록](php-version/QA-CARDS-20260914.md)
- [근태 상세 설명](php-version/ATTENDANCE.md), [근태 QA 당시 기록](php-version/QA-ATTENDANCE-20260914.md)
- [초기 검증 이력](php-version/VALIDATION.md): 9월 초 MariaDB 결과는 최신 소스 전체에 대한 재검증 결과가 아닙니다.

Chrome 데스크톱·390px 모바일 점검 이력이 있으나 실제 iPhone Safari 전체 업무 흐름, 최신 MySQL 동시성, 장시간 부하, 운영 호스팅 조건은 남아 있습니다.

## 8. 배포 및 데이터 이전 — 우선 확인

**현재 `export_legacy.py`와 `import.php`는 완전한 현행 업무 DB 이전 도구가 아닙니다.** 지원 테이블은 User, CompanyHoliday, LeaveRequest, LeaveHistory, LeaveBalanceAdjustment, CardExpense, ReportDelivery의 7개이며 근태 테이블은 포함하지 않습니다. 이 도구만 사용하면 현재 출퇴근·정정·GPS 등의 데이터를 이전할 수 없습니다.

개발자가 먼저 해야 할 일:

1. 현재 DB 전체 테이블과 증빙·설정의 백업을 확보합니다. 일관된 복사 시점을 위해 쓰기 중단 또는 DB에 맞는 스냅샷 방식을 사용합니다.
2. 현재 근태 테이블까지 포함하는 전체 이전 방식을 구현·검증합니다. 기존 7개 테이블 exporter를 현행 DB의 전체 백업으로 안내하지 않습니다.
3. 새 빈 목적 DB에서 ID·관계·시각·행 수·증빙 해시·휴가 잔여·근태 이력을 대조합니다. 실제 DB에 초기 설치를 재실행하지 않습니다.
4. PHP 8.2+, 확장, 비공개 storage, HTTPS, 업로드 한도, DB 권한을 호스팅에서 확인합니다. 카페24는 기존 배포 안내의 대상이며 최종 운영 환경은 확정·확인해야 합니다.
5. 최신 소스로 ZIP을 생성하고 압축 해제 후 검증합니다.

```powershell
python php-version/tools/package.py
python php-version/tests/package_test.py
```

출력: `php-version/release/mnm-php-cafe24.zip`. 공개 `www/`와 비공개 `mnm-private/` 배치로 변환됩니다. 설정·업무 DB·업로드·테스트 데이터는 별도입니다. 이 개발자 전달 문서는 ZIP 자동 포함 대상이 아니므로 소스 문서와 함께 전달하세요.

자세한 배치는 [DEPLOY-CAFE24.md](php-version/DEPLOY-CAFE24.md)를 참고하되 그 문서의 legacy 이전 절차는 위 근태 이전 한계를 전제로 읽으세요. 근태 스키마는 첫 접근 시 추가되며 DB 계정에 생성 권한이 없다면 관리 도구로 먼저 적용해야 합니다. 빈 근태 테이블 생성은 기존 기록 이전과 다릅니다.

## 9. 인수 후 우선순위

1. 실제 폰으로 직원 → 팀장 → 관리자 전체 업무 흐름과 PC 반영을 확인합니다.
2. 근태를 포함한 전체 데이터 이전·백업·복구를 준비합니다.
3. 운영 주소/HTTPS/호스팅을 확정하고 최신 MySQL 회귀·동시성 및 파일 업로드를 검증합니다.
4. 최종 배포 ZIP·소스 버전·설정 전달 경로·롤백 시점을 확정합니다.

현재 근태 네이티브 앱 연동, Notion 연동, 자동 이메일 보고서, 급여·야근 계산은 구현 범위 밖입니다. 연차 발생 규칙은 기존 시스템을 따르며 출결 실적을 자동 반영하는 계산은 아닙니다.

과거 원본 복구 자료의 실제 보관 위치는 저장소 밖의 비공개 인수인계 자료로 별도 전달합니다. 최신 업무 데이터의 기준은 현재 PHP DB입니다. 옛 DB로 덮어쓰거나 옛 서버를 켜는 것만으로 최신 데이터가 이전되지는 않습니다.
