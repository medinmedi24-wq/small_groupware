# 현재 작업 상태

최신 상태 (2026-09-08): 사용자의 추가 복원 조치 후 bootstrap.php와 tools/import.php가 모두 존재합니다. 배포 ZIP 생성과 tests/package_test.py의 압축 해제 후 설치·로그인·페이지 렌더링 검증을 완료했습니다. 설치·MariaDB 이전·동시 신청·XLSX·SQL 이전 검증과 원본 54개 파일 보존 확인도 통과했습니다. 결과물은 release/mnm-php-cafe24.zip입니다. 로컬 테스트 서버는 종료했습니다. 실제 호스팅 배포·운영 데이터 이전은 수행하지 않았습니다. 아래는 해결 전 경과 기록이며 현재 차단 상태가 아닙니다.

기존 React/Node 프로젝트는 수정하지 않았습니다. PHP 구현과 검증 결과는 README.md 및 VALIDATION.md에 있습니다.

2026-09-08 갱신: 사용자가 두 필수 파일을 복원했습니다. 구문 검사와 SQLite HTTP 62개·MariaDB HTTP 59개가 다시 통과했으나, Avast가 `app/bootstrap.php`를 다시 격리했습니다(격리 기록 TransferTime 1788825178). `tools/import.php`는 복원된 상태입니다. 단순 복원을 반복하기 전에 Avast의 해당 파일 재탐지 원인을 검토해야 합니다. 초기 설치·이전 재검증과 최종 ZIP 생성은 보류했습니다.

## 과거 외부 차단 경과

Avast Antivirus가 2026-09-07 작업 중 다음 새 파일을 IDP.Generic으로 격리했습니다. `C:/ProgramData/Avast Software/Avast/chest/index.xml`의 해당 경로 항목으로 확인했습니다.

- app/bootstrap.php — DB 연결, 세션, 공통 함수. **실행 필수**
- tools/import.php — 기존 데이터 가져오기. **이전 도구 필수**
- tests/fixtures.php, tests/domain.php, tests/query.php — 초기 테스트 보조 파일. 이후 재현 테스트는 Python 파일 내부에서 PHP CLI를 호출하는 형태로 정리되어 이 세 파일은 현재 필수 아님.

동일 경로로 다시 저장하려 해도 접근 거부가 발생합니다. 격리 복원·보안 예외 설정·보안 기능 해제는 수행하지 않았습니다. 일반적인 탐지명만으로 오탐이라고 확정할 수는 없으므로 해당 파일과 실행 경로를 검토한 뒤 조치해야 합니다.

불완전한 배포 ZIP은 제거했습니다. tools/package.py에는 필수 파일 누락 검사를 추가했습니다.

## 이어서 할 일

1. 사용자가 Avast 격리 항목을 검토하고 두 필수 파일의 복원 여부 결정
2. 복원되면 소스 내용·구문·실행 재확인
3. 격리 원인이 해소된 검증 환경에서 HTTP·데이터 이전 테스트 재실행
4. 필수 파일과 민감정보 제외 여부를 확인하고 배포 ZIP 재생성
5. 실제 카페24 PHP·DB·확장 환경 확인 후 업로드 준비

임시 로컬 PHP/MariaDB 검증 서버는 종료했습니다. 실제 카페24 배포나 운영 데이터 이전은 수행하지 않았습니다.
