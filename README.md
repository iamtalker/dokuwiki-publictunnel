# publictunnel — 도쿠위키 임시 공개 주소 플러그인

[Cloudflare 빠른 터널](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/do-more-with-tunnels/trycloudflare/)(`cloudflared`)로
내 컴퓨터에서 돌리는 도쿠위키를 **인터넷에 잠깐 공개**합니다. 관리자 화면의 버튼 하나로 켜고 끕니다.
켤 때마다 `https://….trycloudflare.com` 형태의 **새 임시 주소**가 나오고, 끄면 사라집니다. 계정·가입·공유기 설정(포트 포워딩)이 필요 없습니다.

> DokuWiki admin plugin: opens your local wiki to the internet through a Cloudflare quick tunnel (no account), with one button. Korean/English UI.

## 설치

1. 이 폴더 전체를 도쿠위키의 `lib/plugins/publictunnel/` 로 복사합니다(폴더 이름이 `publictunnel` 이어야 함).
2. **관리자로 로그인**해서 [관리] → [임시 공개 주소] 를 엽니다.
3. 터널 프로그램(`cloudflared`)이 없으면 화면의 **[터널 프로그램 받기]** 를 누릅니다. 공식 배포처(GitHub `cloudflare/cloudflared`)에서 받아 **SHA-256 으로 검증**합니다.
   이미 가지고 있다면 [설정 관리자]에서 `binary` 에 경로를 적으면 됩니다.
4. 안내와 위험 확인에 체크하고 **[공개 시작]** → 몇 초 뒤 공개 주소가 나옵니다. 끝나면 **[공개 중지]**.

## 꼭 읽어 주세요 (보안)

- 공개하는 동안 **주소를 아는 인터넷의 누구나** 이 위키에 들어올 수 있습니다. 주소는 추측하기 어렵지만 비밀번호는 아닙니다.
- **로그인(`useacl`)을 켜고 쓰기 권한을 제한한 뒤에 공개하세요.** 꺼져 있으면 화면에 빨간 경고가 나옵니다.
  (도쿠위키는 `useacl` 을 꺼 두면 관리 화면 자체가 없으므로 이 플러그인을 쓰려면 어차피 로그인이 켜져 있어야 합니다.)
- 관리 화면과 모든 동작은 **관리자 전용**이고 보안 토큰(CSRF)을 검사합니다.
- 터널을 거친 접속은 위키 쪽에서 `127.0.0.1` 로 보입니다. IP 로 권한을 나누는 설정은 믿지 마세요.
- Cloudflare 의 무료 "빠른 터널"은 안정적인 서비스용이 아닙니다(끊기거나 느릴 수 있음). 오래 공개하려면 계정이 있는 이름 있는 터널을 쓰세요.

## 동작 방식

- 윈도: `start /B` 로 `cloudflared tunnel --no-autoupdate --url http://127.0.0.1:<포트>` 를 백그라운드로 띄우고, 새로 생긴 PID 를 `data/publictunnel/tunnel.json` 에 적습니다.
- 리눅스: `nohup … &` 로 띄우고 PID 를 적습니다.
- 출력은 `data/publictunnel/tunnel.log` 에 쌓이고, 거기서 `https://….trycloudflare.com` 주소를 읽어 보여 줍니다.
- 중지는 그 PID 가 **정말 터널 프로그램인지 확인한 뒤** 종료합니다(PID 재사용으로 남의 프로그램을 끄지 않도록).
- 외부 프로그램 실행(`popen`, `shell_exec`)이 막힌 PHP 에서는 쓸 수 없고, 화면에 이유를 알려 줍니다.

## 설정

| 이름 | 설명 |
|---|---|
| `binary` | `cloudflared` 실행 파일 경로. 비우면 `data/publictunnel/bin/` 에 받은 것을 씁니다. |
| `port` | 이 위키가 듣는 로컬 포트. 비우면 지금 접속한 서버 포트를 씁니다. |

## 확인한 것

도쿠위키 2025-05-14b "Librarian" + PHP 7.4(Apache, Windows)에서 실제로 시험했습니다.
터널 프로그램 없음/있음 화면, 확인 없이 시작 거부, 시작 → 공개 주소로 접속(문서가 보임), 비로그인 방문자는 읽기만 가능(저장 시도 무효, 관리 화면 안 보임),
중지(프로세스 종료, 이전 주소 닫힘), 다시 시작(새 주소). 리눅스 경로는 코드만 있고 아직 실제로 돌려 보지 못했습니다.

## 라이선스

MIT. `cloudflared` 는 Cloudflare 의 별도 프로그램(Apache-2.0)이며 이 저장소에 포함되어 있지 않습니다.
