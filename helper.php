<?php
/**
 * DokuWiki Plugin publictunnel (Helper Component)
 *
 * 관리 화면(admin.php)과 검문소(action.php)가 같이 쓰는 것: 상태 파일, 구글 OTP(TOTP, RFC 6238),
 * 서명한 인증 쿠키, 실패 횟수 제한.
 */

if (!defined('DOKU_INC')) die();

class helper_plugin_publictunnel extends DokuWiki_Plugin
{
    const COOKIE = 'ptunnel_ok';
    const FAIL_MAX = 5;          // 이 횟수만큼 틀리면
    const FAIL_WINDOW = 600;     // 10분 안에
    const LOCK_SECONDS = 900;    // 15분 동안 그 접속자는 막는다

    // ------------------------------------------------------------------ 파일

    public function dataDir()
    {
        global $conf;
        // savedir 은 './data' 처럼 상대 경로일 수 있다. 터널 프로그램은 절대 경로로 실행해야 하므로 절대 경로로 푼다.
        $base = (string) $conf['savedir'];
        if (!preg_match('~^([A-Za-z]:[\\\\/]|/)~', $base)) {
            $base = DOKU_INC . $base;
        }
        $dir = $base . '/publictunnel';
        io_mkdir_p($dir);
        $real = realpath($dir);
        return $real !== false ? $real : $dir;
    }

    public function path($name)
    {
        return $this->dataDir() . '/' . $name;
    }

    protected function readJson($name)
    {
        $f = $this->path($name);
        if (!is_file($f)) {
            return array();
        }
        $j = json_decode((string) @file_get_contents($f), true);
        return is_array($j) ? $j : array();
    }

    protected function writeJson($name, $data)
    {
        $f = $this->path($name);
        file_put_contents($f, json_encode($data), LOCK_EX);
        @chmod($f, 0600);
    }

    public function readState()
    {
        return $this->readJson('tunnel.json');
    }

    public function writeState($state)
    {
        $this->writeJson('tunnel.json', $state);
    }

    public function clearState()
    {
        @unlink($this->path('tunnel.json'));
    }

    // ------------------------------------------------------------------ 터널로 들어온 요청인가

    /**
     * Cloudflare 터널을 거쳐 온 요청인가. 공개 주소의 Host(…trycloudflare.com)나 Cloudflare 가 붙이는 머리글로 판단한다.
     * 방문자가 이 표시를 지울 수는 없다(터널이 붙이거나 Host 가 공개 주소여야 터널을 지나온다).
     */
    public function isTunnelRequest()
    {
        $host = strtolower(preg_replace('/:\d+$/', '', (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')));
        if (preg_match('/\.trycloudflare\.com$/', $host)) {
            return true;
        }
        return isset($_SERVER['HTTP_CF_RAY']) || isset($_SERVER['HTTP_CF_CONNECTING_IP']);
    }

    /** 접속자 식별(실패 횟수 제한용). 터널이 알려 주는 실제 IP 를 쓴다. */
    public function clientId()
    {
        $ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? $_SERVER['HTTP_CF_CONNECTING_IP']
            : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '');
        return substr(hash('sha256', (string) $ip), 0, 24);
    }

    // ------------------------------------------------------------------ 구글 OTP (TOTP, RFC 6238)

    const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function base32Encode($bin)
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::B32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $out;
    }

    public function base32Decode($s)
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos(self::B32, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    public function newSecret()
    {
        return $this->base32Encode(random_bytes(20));   // 160비트(구글 인증기 권장)
    }

    /** 시간 단계 step 의 6자리 코드 */
    public function code($secretB32, $step)
    {
        $key = $this->base32Decode($secretB32);
        $hash = hash_hmac('sha1', pack('N2', 0, (int) $step), $key, true);
        $o = ord($hash[19]) & 0x0f;
        $v = ((ord($hash[$o]) & 0x7f) << 24) | ((ord($hash[$o + 1]) & 0xff) << 16)
            | ((ord($hash[$o + 2]) & 0xff) << 8) | (ord($hash[$o + 3]) & 0xff);
        return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * 사용자가 낸 코드가 맞는가. 앞뒤 30초(시계 오차)까지 받고, 이미 쓴 단계(그 이전 포함)는 거절한다(재사용 방지).
     * 맞으면 그 단계 번호를, 틀리면 false 를 돌려준다.
     */
    public function verify($input, $secretB32, $lastStep)
    {
        $input = preg_replace('/\s+/', '', (string) $input);
        if (!preg_match('/^\d{6}$/', $input) || $secretB32 === '') {
            return false;
        }
        $now = (int) floor(time() / 30);
        $hit = false;
        for ($d = -1; $d <= 1; $d++) {               // 모든 후보를 같은 시간에 비교한다
            $step = $now + $d;
            if (hash_equals($this->code($secretB32, $step), $input) && $step > (int) $lastStep) {
                $hit = $step;
            }
        }
        return $hit;
    }

    public function otpInfo()
    {
        return $this->readJson('otp.json');
    }

    public function otpEnabled()
    {
        $o = $this->otpInfo();
        return !empty($o['enabled']) && !empty($o['secret']);
    }

    /** 새 비밀 만들기(아직 켜지 않음: 인증기에 등록하고 코드를 확인해야 켜진다) */
    public function otpCreatePending()
    {
        $o = $this->otpInfo();                    // 이미 켜 둔 OTP 는 새 비밀을 확인하기 전까지 그대로 둔다
        $o['pending'] = $this->newSecret();
        $o += array('secret' => '', 'enabled' => false, 'last_step' => 0);
        $this->writeJson('otp.json', $o);
        return $o;
    }

    public function otpCancelPending()
    {
        $o = $this->otpInfo();
        if (empty($o['secret'])) {
            @unlink($this->path('otp.json'));
            return;
        }
        $o['pending'] = '';
        $this->writeJson('otp.json', $o);
    }

    /** 대기 중인 비밀을 코드로 확인해 켠다 */
    public function otpConfirm($code)
    {
        $o = $this->otpInfo();
        if (empty($o['pending'])) {
            return false;
        }
        $step = $this->verify($code, $o['pending'], 0);
        if ($step === false) {
            return false;
        }
        $this->writeJson('otp.json', array('secret' => $o['pending'], 'enabled' => true, 'pending' => '', 'last_step' => $step));
        return true;
    }

    public function otpDisable()
    {
        @unlink($this->path('otp.json'));
    }

    /** 공개 주소의 OTP 확인. 성공하면 true. 이미 쓴 코드는 다시 못 쓴다. */
    public function otpCheck($code)
    {
        $o = $this->otpInfo();
        if (empty($o['enabled']) || empty($o['secret'])) {
            return false;
        }
        $step = $this->verify($code, $o['secret'], isset($o['last_step']) ? $o['last_step'] : 0);
        if ($step === false) {
            return false;
        }
        $o['last_step'] = $step;
        $this->writeJson('otp.json', $o);
        return true;
    }

    public function otpUri($secret, $label)
    {
        return 'otpauth://totp/' . rawurlencode('DokuWiki:' . $label) . '?secret=' . $secret
            . '&issuer=' . rawurlencode('DokuWiki') . '&algorithm=SHA1&digits=6&period=30';
    }

    // ------------------------------------------------------------------ 인증 쿠키(서명)

    protected function cookieKey()
    {
        $f = $this->path('cookie.key');
        if (!is_file($f) || filesize($f) < 32) {
            file_put_contents($f, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($f, 0600);
        }
        return (string) file_get_contents($f);
    }

    protected function b64u($s)
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    /** tunnelId 는 터널을 연 시각: 터널을 다시 열면 이전 쿠키는 모두 무효가 된다 */
    public function issueToken($tunnelId)
    {
        $ttl = max(1, (int) $this->getConf('otp_ttl')) * 3600;
        $payload = (time() + $ttl) . '|' . $tunnelId;
        return array($this->b64u($payload) . '.' . hash_hmac('sha256', $payload, $this->cookieKey()), $ttl);
    }

    public function checkToken($token, $tunnelId)
    {
        if (!is_string($token) || strpos($token, '.') === false) {
            return false;
        }
        list($p64, $sig) = explode('.', $token, 2);
        $payload = base64_decode(strtr($p64, '-_', '+/'), true);
        if ($payload === false || !hash_equals(hash_hmac('sha256', $payload, $this->cookieKey()), $sig)) {
            return false;
        }
        $parts = explode('|', $payload, 2);
        return count($parts) === 2 && (int) $parts[0] > time() && (string) $parts[1] === (string) $tunnelId;
    }

    // ------------------------------------------------------------------ 틀린 횟수 제한

    /** 지금 막힌 접속자라면 남은 초, 아니면 0 */
    public function lockedFor($id)
    {
        $db = $this->readJson('fail.json');
        return (isset($db[$id]['until']) && $db[$id]['until'] > time()) ? (int) $db[$id]['until'] - time() : 0;
    }

    public function recordFail($id)
    {
        $db = $this->readJson('fail.json');
        $now = time();
        foreach ($db as $k => $row) {               // 오래된 기록 정리
            if (empty($row['until']) && (!isset($row['first']) || $row['first'] < $now - self::FAIL_WINDOW) || (!empty($row['until']) && $row['until'] < $now - 3600)) {
                unset($db[$k]);
            }
        }
        $row = isset($db[$id]) ? $db[$id] : array('n' => 0, 'first' => $now, 'until' => 0);
        if ($row['first'] < $now - self::FAIL_WINDOW) {
            $row = array('n' => 0, 'first' => $now, 'until' => 0);
        }
        $row['n']++;
        if ($row['n'] >= self::FAIL_MAX) {
            $row['until'] = $now + self::LOCK_SECONDS;
            $row['n'] = 0;
            $row['first'] = $now;
        }
        $db[$id] = $row;
        $this->writeJson('fail.json', $db);
        usleep(700000);                              // 틀릴 때마다 조금 늦춰서 연속 시도를 느리게
    }

    public function clearFail($id)
    {
        $db = $this->readJson('fail.json');
        unset($db[$id]);
        $this->writeJson('fail.json', $db);
    }
}
