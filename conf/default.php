<?php
/**
 * Default settings for the publictunnel plugin
 */

$conf['binary']        = '';   // cloudflared 실행 파일 경로(비우면 data/publictunnel/bin 에 받아 둔 것을 씀)
$conf['port']          = '';   // 이 위키가 듣는 로컬 포트(비우면 지금 접속한 서버 포트를 씀)
$conf['verify_binary'] = 1;    // 시작할 때마다 cloudflared 의 SHA-256 을 공식 릴리스 값과 대조
$conf['readonly']      = 1;    // 공개 주소로는 로그인 외의 쓰기(POST)를 막음
$conf['block_admin']   = 1;    // 공개 주소로는 관리 화면에 못 들어가게 함
$conf['otp_ttl']       = 12;   // OTP 인증 후 접속이 유지되는 시간(시간 단위)
