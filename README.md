# publictunnel — 도쿠위키 임시 공개 주소 플러그인 (구글 OTP 보호)

[Cloudflare 빠른 터널](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/)(`cloudflared`)로
내 컴퓨터에서 돌리는 도쿠위키를 **인터넷에 잠깐 공개**합니다. 관리자 화면의 버튼 하나로 켜고 끕니다.
켤 때마다 `https://….trycloudflare.com` 형태의 **새 임시 주소**가 나오고, 끄면 사라집니다. 계정·가입·공유기 설정(포트 포워딩)이 필요 없습니다.
**공개 주소로 들어오는 사람은 구글 OTP(6자리)를 맞춰야** 위키(로그인 화면 포함)를 볼 수 있습니다.

> DokuWiki admin plugin: opens your local wiki to the internet through a Cloudflare quick tunnel (no account) with one button, and puts a
> Google-Authenticator (TOTP) gate in front of it that runs *before* DokuWiki's login. Korean/English UI.

## 설치

1. 이 폴더 전체를 도쿠위키의 `lib/plugins/publictunnel/` 로 복사합니다(폴더 이름이 `publictunnel` 이어야 함).
2. **관리자로 로그인**해서 [관리] → [임시 공개 주소] 를 엽니다.
3. 터널 프로그램(`cloudflared`)이 없으면 **[터널 프로그램 받기]**. 공식 배포처(GitHub `cloudflare/cloudflared`)에서 받아 **SHA-256 으로 검증**합니다.
4. **[OTP 만들기]** → 구글 인증기(Google Authenticator 등)에서 [+] → "설정 키 입력"으로 화면의 키를 넣고, 앱에 나온 6자리 코드를 넣어 확인하면 켜집니다.
5. 위험 확인에 체크하고 **[공개 시작]** → 몇 초 뒤 공개 주소가 나옵니다. 끝나면 **[공개 중지]**.

## 보안 동작

| 위협 | 대응 |
|---|---|
| 주소를 알아낸 낯선 사람이 로그인 화면을 두드림 | **OTP 검문소**가 DokuWiki 의 로그인 처리(`auth_setup`)보다 먼저(`INIT_LANG_LOAD`) 막는다. `init.php` 를 거치는 모든 진입점(`doku.php`, `fetch.php`, `ajax.php`, `detail.php`, `feed.php`, `css.php`, `js.php`, 원격 API …)이 한꺼번에 막힌다. OTP 전에는 문서도, 로그인 화면도, 첨부 파일도 볼 수 없다. |
| OTP 코드 무작위 대입 | 5번 틀리면 그 접속자(터널이 알려 주는 실제 IP 기준)를 15분 잠그고, 틀릴 때마다 응답을 늦춘다. |
| 엿들은 코드 재사용 | 한 번 쓴 코드(와 그 이전 단계)는 다시 못 쓴다(RFC 6238 §5.2). 시계 오차는 앞뒤 30초까지 허용. |
| 인증 쿠키 탈취·위조 | HMAC 서명 쿠키(`HttpOnly; Secure; SameSite=Lax`). 터널을 다시 열거나, OTP 를 바꾸거나, [인증된 접속 모두 해제]를 누르면 이전 쿠키는 모두 무효. |
| 관리자 비밀번호·OTP 가 둘 다 뚫린 경우 | 공개 주소로는 **관리 화면에 못 들어가고**, 로그인 외의 모든 쓰기 요청(POST: 저장·업로드·ajax·원격 API)이 막힌다(설정으로 끌 수 있음). |
| 터널 프로그램 바꿔치기·설정으로 아무 프로그램 실행 | 시작할 때마다 파일 이름이 `cloudflared(.exe)` 인지, 절대 경로인지(UNC·상대 경로 거부), **공식 릴리스의 SHA-256 과 같은지** 확인한다. |
| 내려받기 변조 | HTTPS(인증서 검증) + SHA-256 대조 + 크기 상한(150MB). 다르면 지운다. |
| 관리 화면 악용(CSRF·권한) | 상태를 바꾸는 동작은 POST + 보안 토큰 + 관리자 확인. 동시에 두 번 눌러도 잠금으로 한 번에 하나씩. |
| 터널이 열리는 첫 순간의 틈 | 터널을 열기 **전에** 검문소 설정(상태 파일)부터 쓴다. |
| 중지할 때 엉뚱한 프로그램 종료 | PID 가 정말 터널 프로그램인지 확인한 뒤에만 종료(PID 재사용 방지). |
| 검문 화면 악용 | 열린 리다이렉트 차단(사이트 안 경로만), 캐시·검색 금지, 프레임 삽입 금지, 엄격한 CSP. |

OTP 비밀·쿠키 키·실패 기록은 `data/publictunnel/` 에 파일 권한 `0600` 으로 저장됩니다(`data/` 는 웹에서 막혀 있음).

### 한계 — 꼭 알아 두세요

- 검문 대상은 **터널을 거쳐 온 요청**(Host 가 `….trycloudflare.com` 이거나 Cloudflare 머리글이 있는 요청)뿐입니다. 이 컴퓨터(`localhost`)나 같은 네트워크의 다른 기기가 위키 포트로 직접 오는 접속은 건드리지 않습니다.
- 이 플러그인이 연 터널이 **아니면**(상태 파일 없음) 검문하지 않습니다. 직접 `cloudflared` 를 돌렸다면 보호받지 못합니다.
- **OTP 없이 열기**를 고르면(위험 확인을 따로 체크해야 함) 검문소는 쓰기 제한만 적용합니다.
- 코드는 30초마다 한 번만 쓸 수 있으므로, 같은 30초 안에 두 사람이 연달아 인증하면 두 번째 사람은 다음 코드를 기다려야 합니다.
- 터널을 거친 접속은 위키 쪽에서 `127.0.0.1` 로 보입니다. IP 로 권한을 나누는 설정은 믿지 마세요.
- Cloudflare 의 무료 "빠른 터널"은 안정적인 서비스용이 아닙니다(끊기거나 느릴 수 있음).
- 이 플러그인은 모든 요청에서 실행되는 검문 코드를 포함합니다. 처음에는 시험용 위키에 먼저 설치해 보세요.
- OTP 는 **공개 주소로 들어오는 문**을 지키는 것이고, 위키 계정 자체의 2단계 인증이 아닙니다. 계정 로그인에도 OTP 를 쓰려면 [twofactor](https://www.dokuwiki.org/plugin:twofactor) 같은 플러그인을 함께 쓰세요.

## 설정

| 이름 | 기본 | 설명 |
|---|---|---|
| `binary` | (비움) | `cloudflared` 실행 파일의 **절대 경로**. 비우면 `data/publictunnel/bin/` 의 것을 씁니다. 파일 이름은 `cloudflared` 여야 합니다. |
| `port` | (비움) | 이 위키가 듣는 로컬 포트. 비우면 지금 접속한 서버 포트. |
| `verify_binary` | 켬 | 시작할 때마다 SHA-256 을 공식 릴리스와 대조. 직접 확인한 다른 버전을 쓸 때만 끄세요. |
| `readonly` | 켬 | 공개 주소로는 로그인 외의 쓰기 요청을 막음. |
| `block_admin` | 켬 | 공개 주소로는 관리 화면을 막음. |
| `otp_ttl` | 12 | OTP 인증 후 접속이 유지되는 시간(시간). |

## 동작 방식

- 윈도: `start /B` 로 `cloudflared tunnel --no-autoupdate --url http://127.0.0.1:<포트>` 를 백그라운드로 띄우고 새로 생긴 PID 를 기록합니다. 리눅스: `nohup … &`.
- 출력은 `data/publictunnel/tunnel.log` 에 쌓이고, 거기서 `https://….trycloudflare.com` 주소를 읽어 보여 줍니다.
- 외부 프로그램 실행(`popen`, `shell_exec`)이 막힌 PHP 에서는 쓸 수 없고, 화면에 이유를 알려 줍니다. PHP 7.0 이상, 32비트 PHP 에서도 동작합니다.

## 확인한 것

도쿠위키 2025-05-14b "Librarian" + PHP 7.4(32비트, Apache, Windows)에서 시험했습니다. 터널은 진짜 `cloudflared` 로 한 번 실제 접속을 확인했고,
보안 시험은 외부 네트워크 없이 `cloudflared` 인 척하는 가짜 프로그램으로 했습니다(공개 주소 방문자는 `Host` 머리글로 흉내).
- 구글 OTP: 공식 RFC 6238 시험 값으로 구현을 확인한 뒤, 같은 코드를 PHP 가 받아들이는지 확인
- OTP 전에는 문서·로그인·첨부·ajax·RSS·css·js·detail 이 모두 401, 로컬 주소는 영향 없음
- 틀린 코드·5회 잠금(429)·코드 재사용 거부·위조/엉터리 쿠키 거부·터널 재시작/OTP 변경 시 쿠키 무효
- 열린 리다이렉트(`//…`) 차단, 쓰기 POST·ajax·업로드 진입점 403, 관리자로 로그인해도 공개 주소로는 관리 화면 차단
- GET/토큰 없음/비관리자 요청으로는 시작 안 됨, 이름·경로·해시가 맞지 않는 터널 프로그램 거부, 공식 해시의 진짜 `cloudflared` 는 통과
- 리눅스 경로(`nohup`)는 코드만 있고 아직 실제로 돌려 보지 못했습니다.

## 라이선스

MIT. `cloudflared` 는 Cloudflare 의 별도 프로그램(Apache-2.0)이며 이 저장소에 포함되어 있지 않습니다.
