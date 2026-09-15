# 카페24 PHP 배포 안내

> 2026-09-15 주의: 현행 소스의 인수인계 기준은 [개발자 전달 문서](../DEVELOPER-HANDOFF.md)입니다. 아래 legacy 이전 도구는 7개 기본 업무 테이블만 지원하며 근태 데이터를 포함하지 않습니다. 현행 DB 이전에는 근태를 포함하는 별도 전체 이전 검증이 필요합니다. 기존 ZIP도 최신 소스로 다시 생성·검증하세요.

이 문서는 새 PHP 버전을 별도 DB에 준비한 뒤 전환하는 절차입니다. 원본 Node 프로젝트나 기존 카페24 사이트를 먼저 삭제하지 않습니다. 실제 카페24 계정에는 아직 업로드하지 않았습니다.

## 1. 상품 환경 확인

- PHP 8.2 이상, MariaDB/MySQL의 InnoDB 및 utf8mb4
- PHP 확장 PDO / pdo_mysql / mbstring / fileinfo / zip
- storage 디렉터리 쓰기 및 웹 문서 루트 상위의 비공개 파일 접근
- HTTPS 인증서
- PHP upload_max_filesize >= 10M, post_max_size >= 12M
- 보고서 생성량에 맞는 memory_limit 및 실행시간 (초기 검토값 256M / 120초)

제작 시 상품 정보가 제공되지 않아 PHP 8.2+ / MySQL 계열 기준으로 구현했습니다. 실제 카페24 상품의 PHP 확장·업로드 한도·상위 경로 접근 정책은 관리 화면 또는 고객센터에서 확인해야 합니다. PHP 7.x에서는 이 버전을 사용하지 않습니다.

카페24 공식 환경 안내: https://help.cafe24.com/faq/web-hosting/introduce/new-renewal-change/newautobahn_hosting_environment/

## 2. 파일 배치

배포 ZIP을 로컬에서 풀면 다음 구조가 나옵니다.

```text
호스팅 계정 홈/
  www/                 공개 영역
    index.php
    .htaccess
    assets/
    icons/
    sw.js
    manifest.webmanifest
  mnm-private/         공개 루트 밖
    app/
    config/
      example.php
      local.php        직접 준비
    database/
    storage/           PHP 사용자에게 쓰기 권한
    tools/
```

ZIP의 `www/` 내용은 카페24 공개 문서 루트에, `mnm-private/`는 그와 같은 레벨의 비공개 폴더로 업로드합니다. ZIP의 index.php는 이 배치를 기준으로 만들어집니다. 저장소의 `public/index.php`는 로컬 소스 구조용이므로 ZIP 파일을 사용하세요.

`config`, DB 덤프, `storage`, 기존 `.env`, `prisma/dev.db`를 www 안에 올리지 마세요. 상위 경로에 접근할 수 없는 상품이면 공개 배포 전에 호스팅 측의 지원 경로를 확인해야 합니다. `.htaccess`만을 믿고 비밀 파일을 공개 폴더로 옮기는 방식은 기본 배포 방식이 아닙니다.

기존 www에 index.html이 있다면 PHP 새 사이트의 테스트를 별도 테스트 도메인/계정에서 먼저 완료하세요. www/.htaccess의 DirectoryIndex는 index.php를 지정합니다. 서버 전체 정책에 따라 적용 여부를 확인합니다.

## 3. 설정 파일 준비

`mnm-private/config/example.php`를 같은 디렉터리의 `local.php`로 복사합니다. DB 호스트·이름·사용자·비밀번호를 카페24 DB 정보에 맞춥니다. `secure_cookie`는 true로 유지합니다. HTTPS 주소에서 접속해야 로그인됩니다.

기본 storage 경로는 `mnm-private/storage`입니다. PHP 실행 사용자가 그 폴더에 디렉터리와 파일을 생성할 수 있게 권한을 부여합니다. 과도한 전체 공개 쓰기 권한 대신 호스팅에서 안내하는 소유자·권한을 적용하세요.

SSH 사용이 가능하면 `php mnm-private/tools/check.php`로 확장·DB·storage·초기 관리자·쿠키·설치 비활성 상태를 점검할 수 있습니다. 설치 전에는 일부 점검이 실패하는 것이 정상입니다.

## 4-A. 기존 업무 데이터를 이전하는 경우

**초기 관리자 설치 화면을 실행하지 않습니다. 기존 관리자 계정을 함께 이전합니다.**

먼저 원본 PC에서 읽기 전용 검사:

```sh
python php-version/tools/export_legacy.py --database prisma/dev.db --receipts uploads/receipts --check
```

최종 이전 시점에는 원본 사이트의 신규 입력을 중단한 뒤 DB와 증빙 파일을 함께 내보냅니다. 읽기 스냅샷은 DB 일관성을 제공하지만 파일 업로드와 DB를 서로 다른 시점에 복사하는 것을 막으려면 짧은 전환 시간 동안 쓰기를 중단해야 합니다.

```sh
python php-version/tools/export_legacy.py --database prisma/dev.db --receipts uploads/receipts --output php-version/storage/legacy-export.zip
```

이 도구는 SQLite를 읽기 전용으로 열고 사용자 ID·관계·비밀번호 해시·휴가·결재 이력·잔여 조정·법인카드·휴일·보고서 기록을 보존합니다. 날짜가 SQLite 밀리초 숫자로 저장되어 있으면 UTC DATETIME으로 변환합니다. 로그인 세션은 이전하지 않아 전환 후 다시 로그인해야 합니다. 누락된 증빙 파일이나 DB 무결성 문제가 있으면 중단합니다. 기존 출력 파일을 덮어쓰지 않습니다.

생성 ZIP은 계정 및 업무 데이터가 들어 있는 비공개 백업입니다. 공개 www에 올리지 않습니다.

### SSH/PHP CLI가 가능한 경우

1. 새로운 **빈 DB**에 `mnm-private/database/mysql.sql`을 한 번 가져옵니다.
2. 내보낸 ZIP을 비공개 위치에 업로드합니다.
3. `php mnm-private/tools/import.php /비공개경로/legacy-export.zip`을 실행합니다.
4. 테이블별 건수·ID 관계·파일 SHA-256을 확인합니다. 이미 데이터가 있는 DB에는 importer가 실행을 거부합니다.

### SSH를 사용하지 않는 경우

1. 내보낸 ZIP을 로컬에서 풉니다.
2. phpMyAdmin 등 DB 관리 도구에서 새 빈 DB에 `mysql.sql`을 먼저 실행합니다.
3. 같은 DB에 `data.sql`을 실행합니다. 데이터 SQL에는 DROP/UPDATE가 없으며 INSERT만 사용합니다. 오류가 발생하면 해당 DB에서 계속 진행하지 말고 새 빈 DB에서 원인을 해결한 뒤 다시 시도합니다.
4. `receipts/`의 파일을 `mnm-private/storage/receipts/`에 업로드합니다.
5. `manifest.json`의 테이블별 건수와 증빙 파일 수를 대조합니다.

초기 schema는 현재 Prisma schema에 있는 영수증 등 7개 컬럼을 모두 포함합니다. 원본 저장소에서 누락되었던 마이그레이션을 그대로 재사용하지 않습니다.

## 4-B. 업무 데이터 없이 새로 시작하는 경우

1. 새 빈 DB를 지정합니다.
2. config/local.php의 setup_token에 충분히 긴 임의 문자열(32자 이상)을 잠시 지정합니다.
3. HTTPS의 `index.php?page=setup`에 접속합니다.
4. 설치 토큰과 최초 관리자 정보를 입력합니다.
5. 설치 후 setup_token을 빈 문자열로 변경합니다. 사용자 테이블에 데이터가 생기면 설치 화면도 재실행을 거부합니다.
6. 로그인 후 초기 비밀번호를 변경합니다.

CLI를 사용하려면 `MNM_ADMIN_PASSWORD` 환경변수를 설정한 뒤 `php mnm-private/tools/install.php 관리자아이디 관리자이름`을 실행할 수도 있습니다. 비밀번호를 명령행 인수나 공개 파일에 적지 않습니다.

## 5. 전환 전 확인

- 기존 계정으로 로그인, 최초 비밀번호 변경 대상 여부 확인
- 직원·휴가·결재·휴일·법인카드 건수 및 잔여 일수 대조
- 직원 → 팀장 → 관리자 지정 관리자, 팀장 → 관리자 지정 관리자 경로 및 다른 관리자의 최종 승인 차단 확인
- 대표 샘플로 휴가 신청 → 결재 → 이력 확인
- 증빙 업로드 및 본인·관리자 다운로드, 다른 직원 접근 차단
- 연차 XLSX를 Excel/LibreOffice에서 열기
- 모바일 메뉴·날짜 입력·PWA 설치
- HTTPS·Secure 쿠키·public 외 디렉터리 접근 차단

실제 운영 데이터를 사용한 검증에서는 테스트성 신청을 업무 내역과 구분하고 승인된 검증 범위에서만 처리합니다.

## 6. 백업과 되돌리기

- DB: 카페24 DB 백업 또는 InnoDB 일관성 옵션을 갖춘 DB 덤프
- 파일: mnm-private/storage/receipts 전체
- 설정: config/local.php를 접근이 제한된 백업으로 보관
- 원본 Node 소스·SQLite·uploads를 별도로 유지
- 서버 외부에도 복사본을 두고 복구를 실제로 점검

PHP 전환 후 새 입력은 원본 SQLite로 자동 반영되지 않습니다. 되돌릴 때는 먼저 PHP 입력을 중단하고 신규 데이터의 재이전 여부를 판단합니다. 원본 사이트를 다시 켜기만 하면 전환 후 입력이 사라진 것처럼 보일 수 있습니다.

새 PHP 버전은 기존 이메일 예약 전송이나 원본 Windows 재시작·백업 스크립트를 사용하지 않습니다. 카페24의 PHP 요청 처리와 호스팅 백업을 사용합니다.
