<?php
/**
 * DokuWiki Plugin publictunnel (Admin Component)
 *
 * cloudflared "빠른 터널"(Cloudflare, 계정 불필요)로 이 위키에 임시 공개 주소를 만든다.
 * 켤 때마다 https://….trycloudflare.com 형태의 새 주소가 나오고, 끄면 사라진다.
 * 공개 주소로 들어오려면 구글 OTP(6자리)를 맞춰야 한다(검문소: action.php).
 *
 * PHP 7.0 이상. Windows(popen + start /B)와 리눅스(nohup)를 지원한다.
 */

if (!defined('DOKU_INC')) die();

class admin_plugin_publictunnel extends DokuWiki_Admin_Plugin
{
    /** 받아 둘 cloudflared 릴리스(공식 GitHub). 해시는 그 릴리스 파일의 SHA-256. 새 버전을 쓰려면 둘 다 바꾼다. */
    const VERSION = '2026.9.3';
    const BASE = 'https://github.com/cloudflare/cloudflared/releases/download/';
    const MAX_DOWNLOAD = 157286400;   // 150MB

    protected $hashes = array(
        'cloudflared-windows-amd64.exe' => 'f096265ec2fcbe9bb6e2d64268db167ced3fcbb83d894bdb9e2fcdb26f2ea7e2',
        'cloudflared-linux-amd64'       => '77e26d8d900e0b8469f416239d14b5f296525fdf79fee6f511ef55609e3fbac2',
        'cloudflared-linux-arm64'       => 'aaeb2d7d0da3614634c7e03ab13487a1522c2e79165ed2929cfe23d5e95b326d',
    );

    public function getMenuSort()
    {
        return 9000;
    }

    public function forAdminOnly()
    {
        return true;
    }

    public function getMenuText($language)
    {
        return $this->getLang('menu');
    }

    /** @return helper_plugin_publictunnel */
    protected function h()
    {
        return plugin_load('helper', 'publictunnel');
    }

    // ------------------------------------------------------------------ 환경

    protected function isWindows()
    {
        return DIRECTORY_SEPARATOR === '\\';
    }

    /** 외부 프로그램을 실행할 수 있는 PHP 인가 */
    protected function canExec()
    {
        $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
        foreach (array('popen', 'pclose', 'shell_exec') as $f) {
            if (!function_exists($f) || in_array($f, $disabled, true)) {
                return false;
            }
        }
        return true;
    }

    protected function logFile()
    {
        return $this->h()->path('tunnel.log');
    }

    protected function defaultBinary()
    {
        return $this->h()->dataDir() . '/bin/' . ($this->isWindows() ? 'cloudflared.exe' : 'cloudflared');
    }

    protected function customBinary()
    {
        return trim((string) $this->getConf('binary'));
    }

    protected function binary()
    {
        $custom = $this->customBinary();
        return $custom !== '' ? $custom : $this->defaultBinary();
    }

    /** 이 운영체제에서 받을 파일(없으면 null) */
    protected function release()
    {
        if ($this->isWindows()) {
            $file = 'cloudflared-windows-amd64.exe';
        } elseif (stripos(PHP_OS, 'linux') === 0) {
            $arch = strtolower(php_uname('m'));
            $file = ($arch === 'aarch64' || $arch === 'arm64') ? 'cloudflared-linux-arm64' : 'cloudflared-linux-amd64';
        } else {
            return null;
        }
        return array('file' => $file, 'sha256' => $this->hashes[$file]);
    }

    /**
     * 실행해도 되는 터널 프로그램인가. 문제가 있으면 언어 키를, 없으면 ''.
     * - 파일 이름이 cloudflared(.exe) 여야 한다(설정으로 cmd.exe 같은 다른 프로그램을 돌리는 것을 막는다)
     * - 절대 경로여야 한다(UNC·상대 경로 거부)
     * - 알려진 공식 릴리스의 SHA-256 과 같아야 한다(verify_binary 를 끄지 않았다면). 바꿔치기·변조를 막는다.
     */
    protected function binaryError($bin)
    {
        if (!preg_match('/^cloudflared(\.exe)?$/i', basename(str_replace('\\', '/', $bin)))) {
            return 'err_binary_name';
        }
        $abs = $this->isWindows() ? (bool) preg_match('~^[A-Za-z]:[\\\\/]~', $bin) : (isset($bin[0]) && $bin[0] === '/');
        if (!$abs) {
            return 'err_binary_path';
        }
        if (!is_file($bin) || (!$this->isWindows() && !is_executable($bin))) {
            return 'err_nobinary';
        }
        if ($this->getConf('verify_binary')) {
            $sha = (string) @hash_file('sha256', $bin);
            if (!in_array($sha, $this->hashes, true)) {
                return 'err_binary_hash';
            }
        }
        return '';
    }

    // ------------------------------------------------------------------ 프로세스

    protected function winTasks($filter)
    {
        // filter 는 이 파일 안에서 만든 값(숫자 PID 또는 검증한 파일 이름)만 넘긴다
        $out = (string) shell_exec('tasklist /FI "' . $filter . '" /FO CSV /NH 2>NUL');
        preg_match_all('/^"([^"]*)","(\d+)"/m', $out, $m, PREG_SET_ORDER);
        $res = array();
        foreach ($m as $row) {
            $res[(int) $row[2]] = $row[1];
        }
        return $res;
    }

    protected function pidAlive($pid)
    {
        $pid = (int) $pid;
        if ($pid <= 0) {
            return false;
        }
        if ($this->isWindows()) {
            return count($this->winTasks('PID eq ' . $pid)) > 0;
        }
        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }
        return is_dir('/proc/' . $pid);
    }

    /** 그 PID 가 정말 우리가 띄운 터널 프로그램인가(PID 가 다른 프로그램에 재사용된 경우를 막는다) */
    protected function pidIsTunnel($pid)
    {
        $name = basename(str_replace('\\', '/', $this->binary()));
        if ($this->isWindows()) {
            $t = $this->winTasks('PID eq ' . (int) $pid);
            return isset($t[(int) $pid]) && strcasecmp($t[(int) $pid], $name) === 0;
        }
        $cmd = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
        return $cmd !== false && strpos($cmd, $name) !== false;
    }

    /** 열려 있는 터널의 상태(없으면 빈 배열). pid 가 0 이면 시작하는 중 */
    protected function running()
    {
        $st = $this->h()->readState();
        if (!$st) {
            return array();
        }
        if (!empty($st['pid']) && !$this->pidAlive($st['pid'])) {
            $this->h()->clearState();
            return array();
        }
        return $st;
    }

    /** 터널 기록에서 공개 주소를 찾는다 */
    protected function tunnelUrl()
    {
        $log = $this->logFile();
        if (!is_file($log)) {
            return '';
        }
        $txt = (string) @file_get_contents($log, false, null, max(0, filesize($log) - 65536));
        return preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~i', $txt, $m) ? $m[0] : '';
    }

    protected function logTail($bytes = 3000)
    {
        $log = $this->logFile();
        if (!is_file($log)) {
            return '';
        }
        return (string) @file_get_contents($log, false, null, max(0, filesize($log) - $bytes));
    }

    // ------------------------------------------------------------------ 동작

    protected function startTunnel($allowNoGate)
    {
        $h = $this->h();
        if ($this->running()) {
            return;
        }
        $bin = $this->binary();
        $err = $this->binaryError($bin);
        if ($err !== '') {
            msg($this->getLang($err), -1);
            return;
        }
        $gate = $h->otpEnabled() ? 'otp' : 'none';
        if ($gate === 'none' && !$allowNoGate) {
            msg($this->getLang('err_need_otp'), -1);
            return;
        }
        $port = (int) ($this->getConf('port') ?: (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0));
        if ($port < 1 || $port > 65535) {
            msg($this->getLang('err_port'), -1);
            return;
        }
        if ($this->isWindows() && !preg_match('/^[^"&|<>%^\r\n]+$/', $bin)) {
            msg($this->getLang('err_binary_path'), -1);
            return;
        }
        $local = 'http://127.0.0.1:' . $port;
        $log = $this->logFile();
        @unlink($log);
        $started = time();
        // 터널을 열기 전에 상태(검문소 설정)부터 적는다: 주소가 생기는 첫 순간부터 검문소가 켜져 있게
        $h->writeState(array('pid' => 0, 'started' => $started, 'port' => $port, 'local' => $local, 'gate' => $gate));
        $pid = 0;

        if ($this->isWindows()) {
            // start /B: 이 요청이 끝나도 계속 도는 백그라운드 프로세스. 새로 생긴 PID 를 찾아 기억한다.
            $image = basename(str_replace('\\', '/', $bin));
            $before = array_keys($this->winTasks('IMAGENAME eq ' . $image));
            $cmd = 'start "" /B "' . $bin . '" tunnel --no-autoupdate --url ' . $local . ' > "' . $log . '" 2>&1';
            pclose(popen($cmd, 'r'));
            for ($i = 0; $i < 20 && !$pid; $i++) {
                usleep(300000);
                $new = array_diff(array_keys($this->winTasks('IMAGENAME eq ' . $image)), $before);
                if ($new) {
                    $pid = max($new);
                }
            }
        } else {
            $cmd = 'nohup ' . escapeshellarg($bin) . ' tunnel --no-autoupdate --url ' . escapeshellarg($local)
                . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
            $pid = (int) trim((string) shell_exec($cmd));
        }
        if ($pid <= 0) {
            $h->clearState();
            msg($this->getLang('err_start'), -1);
            return;
        }
        $h->writeState(array('pid' => $pid, 'started' => $started, 'port' => $port, 'local' => $local, 'gate' => $gate));

        // 주소가 나올 때까지 잠깐 기다린다(보통 몇 초)
        @set_time_limit(60);
        for ($i = 0; $i < 40; $i++) {
            if ($this->tunnelUrl() !== '' || !$this->pidAlive($pid)) {
                break;
            }
            usleep(500000);
        }
        if ($this->tunnelUrl() !== '') {
            msg($this->getLang('msg_started'), 1);
        } elseif (!$this->pidAlive($pid)) {
            $h->clearState();
            msg($this->getLang('err_start'), -1);
        }
    }

    protected function stopTunnel()
    {
        $st = $this->h()->readState();
        if ($st && !empty($st['pid']) && $this->pidAlive($st['pid']) && $this->pidIsTunnel($st['pid'])) {
            $pid = (int) $st['pid'];
            if ($this->isWindows()) {
                shell_exec('taskkill /PID ' . $pid . ' /T /F 2>NUL');
            } elseif (function_exists('posix_kill')) {
                @posix_kill($pid, 15);
            } else {
                shell_exec('kill ' . $pid . ' 2>/dev/null');
            }
            for ($i = 0; $i < 10 && $this->pidAlive($pid); $i++) {
                usleep(200000);
            }
        }
        $this->h()->clearState();
        msg($this->getLang('msg_stopped'), 1);
    }

    protected function downloadBinary()
    {
        $r = $this->release();
        if (!$r) {
            msg($this->getLang('unsupported'), -1);
            return;
        }
        $dest = $this->defaultBinary();
        io_mkdir_p(dirname($dest));
        $part = $dest . '.part';
        @set_time_limit(600);
        $ctx = stream_context_create(array(
            'http' => array('timeout' => 180, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'dokuwiki-publictunnel'),
            'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
        ));
        $url = self::BASE . self::VERSION . '/' . $r['file'];
        $in = @fopen($url, 'rb', false, $ctx);
        $out = $in ? @fopen($part, 'wb') : false;
        if (!$in || !$out) {
            $e = error_get_last();
            if ($in) {
                fclose($in);
            }
            msg(sprintf($this->getLang('err_download'), hsc($e ? $e['message'] : '?')), -1);
            return;
        }
        $total = 0;
        $tooBig = false;
        while (!feof($in)) {
            $buf = fread($in, 1048576);
            if ($buf === false || $buf === '') {
                break;
            }
            $total += strlen($buf);
            if ($total > self::MAX_DOWNLOAD) {      // 공식 파일은 이보다 훨씬 작다: 이상하면 중단
                $tooBig = true;
                break;
            }
            fwrite($out, $buf);
        }
        fclose($in);
        fclose($out);
        if ($tooBig || !hash_equals($r['sha256'], (string) hash_file('sha256', $part))) {
            @unlink($part);
            msg($this->getLang('err_hash'), -1);
            return;
        }
        @unlink($dest);
        rename($part, $dest);
        if (!$this->isWindows()) {
            @chmod($dest, 0755);
        }
        msg($this->getLang('msg_downloaded'), 1);
    }

    // ------------------------------------------------------------------ 요청 처리

    public function handle()
    {
        global $INPUT;
        $act = $INPUT->post->str('ptunnel');            // 상태를 바꾸는 동작은 POST 로만 받는다
        if ($act === '' || !checkSecurityToken() || !auth_isadmin() || !$this->canExec()) {
            return;
        }
        $h = $this->h();
        $lock = @fopen($h->path('lock'), 'c');           // 동시에 두 번 눌러도 한 번에 하나씩 처리
        if ($lock) {
            flock($lock, LOCK_EX);
        }
        switch ($act) {
            case 'start':
                if ($h->otpEnabled() || $INPUT->post->bool('nogate')) {
                    if (!$INPUT->post->bool('confirm')) {
                        msg($this->getLang('err_confirm'), -1);
                        break;
                    }
                }
                $this->startTunnel($INPUT->post->bool('nogate'));
                break;
            case 'stop':
                $this->stopTunnel();
                break;
            case 'download':
                if ($this->customBinary() === '') {
                    $this->downloadBinary();
                }
                break;
            case 'otp_new':
                $h->otpCreatePending();
                break;
            case 'otp_cancel':
                $h->otpCancelPending();
                break;
            case 'otp_confirm':
                if ($h->otpConfirm($INPUT->post->str('code'))) {
                    @unlink($h->path('cookie.key'));    // 비밀이 바뀌었으니 이미 인증된 접속도 모두 해제
                    msg($this->getLang('msg_otp_on'), 1);
                } else {
                    msg($this->getLang('err_otp_code'), -1);
                }
                break;
            case 'otp_off':
                $h->otpDisable();
                @unlink($h->path('cookie.key'));
                msg($this->getLang('msg_otp_off'), 1);
                break;
            case 'revoke':
                @unlink($h->path('cookie.key'));
                msg($this->getLang('msg_revoked'), 1);
                break;
        }
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    // ------------------------------------------------------------------ 화면

    protected function form($action, $label, $extra = '', $class = '')
    {
        $s = '<form action="' . script() . '" method="post" style="display:inline-block;margin:0 .6em .6em 0">';
        $s .= '<input type="hidden" name="do" value="admin" /><input type="hidden" name="page" value="publictunnel" />';
        ob_start();
        formSecurityToken();
        $s .= ob_get_clean();
        $s .= '<input type="hidden" name="ptunnel" value="' . hsc($action) . '" />' . $extra;
        $s .= '<button type="submit" class="button ' . $class . '">' . hsc($label) . '</button></form>';
        return $s;
    }

    protected function otpBox()
    {
        $h = $this->h();
        $o = $h->otpInfo();
        echo '<h2>' . hsc($this->getLang('otp_title')) . '</h2>';
        if (!empty($o['pending'])) {
            $secret = $o['pending'];
            global $INFO;
            $label = !empty($INFO['userinfo']['name']) ? $INFO['userinfo']['name'] : 'wiki';
            echo '<div class="info">' . hsc($this->getLang('otp_setup')) . '</div>';
            echo '<p><strong>' . hsc($this->getLang('otp_secret')) . ':</strong> <code style="font-size:1.2em;letter-spacing:.1em">'
                . hsc(trim(chunk_split($secret, 4, ' '))) . '</code></p>';
            echo '<p style="color:#777;font-size:.9em;word-break:break-all">' . hsc($this->getLang('otp_uri')) . ': '
                . hsc($h->otpUri($secret, $label)) . '</p>';
            $in = '<p style="margin:.4em 0"><label>' . hsc($this->getLang('otp_code')) . ' <input type="text" name="code" inputmode="numeric" '
                . 'pattern="[0-9 ]*" maxlength="7" autocomplete="off" style="width:8em" required /></label></p>';
            echo $this->form('otp_confirm', $this->getLang('btn_otp_confirm'), $in);
            echo $this->form('otp_cancel', $this->getLang('btn_otp_cancel'));
        } elseif ($h->otpEnabled()) {
            echo '<div class="success">' . hsc($this->getLang('otp_on')) . '</div>';
            echo $this->form('otp_new', $this->getLang('btn_otp_new'));
            echo $this->form('otp_off', $this->getLang('btn_otp_off'));
            echo $this->form('revoke', $this->getLang('btn_revoke'));
        } else {
            echo '<div class="error">' . hsc($this->getLang('otp_off')) . '</div>';
            echo $this->form('otp_new', $this->getLang('btn_otp_make'));
        }
    }

    public function html()
    {
        global $conf;
        $h = $this->h();
        echo '<h1>' . hsc($this->getLang('title')) . '</h1>';
        echo '<p>' . hsc($this->getLang('intro')) . '</p>';

        if (!$this->canExec()) {
            echo '<div class="error">' . hsc($this->getLang('no_exec')) . '</div>';
            return;
        }

        $st = $this->running();
        $url = $st ? $this->tunnelUrl() : '';
        $reload = script() . '?do=admin&page=publictunnel';

        if ($st && $url !== '') {
            echo '<div class="success"><strong>' . hsc($this->getLang('state_on')) . '</strong></div>';
            echo '<p><strong>' . hsc($this->getLang('url')) . ':</strong> <a href="' . hsc($url) . '" target="_blank" rel="noopener" '
                . 'style="font-size:1.2em">' . hsc($url) . '</a></p>';
            echo '<p>' . hsc($this->getLang(isset($st['gate']) && $st['gate'] === 'otp' ? 'gate_on' : 'gate_none')) . '</p>';
            echo '<p>' . hsc($this->getLang('local')) . ': ' . hsc($st['local']) . ' · ' . hsc($this->getLang('started')) . ': '
                . hsc(date('Y-m-d H:i:s', (int) $st['started'])) . '</p>';
            echo $this->form('stop', $this->getLang('btn_stop'));
            echo '<a class="button" href="' . hsc($reload) . '">' . hsc($this->getLang('btn_refresh')) . '</a>';
        } elseif ($st) {
            echo '<div class="info">' . hsc($this->getLang('state_wait')) . '</div>';
            echo '<script>setTimeout(function(){location.href=' . json_encode($reload) . ';},3000);</script>';
            echo $this->form('stop', $this->getLang('btn_stop'));
        } else {
            echo '<p>' . hsc($this->getLang('state_off')) . '</p>';
            $bin = $this->binary();
            $r = $this->release();
            $binErr = $this->binaryError($bin);
            if ($binErr === 'err_nobinary' && $this->customBinary() === '') {
                if (!$r) {
                    echo '<div class="error">' . hsc($this->getLang('unsupported')) . '</div>';
                } else {
                    echo '<div class="info">' . hsc(sprintf($this->getLang('need_binary'), 'cloudflared ' . self::VERSION, '40MB')) . '</div>';
                    echo $this->form('download', $this->getLang('btn_download'));
                }
            } elseif ($binErr !== '') {
                echo '<div class="error">' . hsc($this->getLang($binErr)) . ' (' . hsc($bin) . ')</div>';
            } else {
                echo '<div class="info">' . hsc($this->getLang('warn_public')) . '</div>';
                if (empty($conf['useacl'])) {
                    echo '<div class="error"><strong>' . hsc($this->getLang('warn_noacl')) . '</strong></div>';
                }
                if (!empty($conf['remote'])) {
                    echo '<div class="info">' . hsc($this->getLang('warn_remote')) . '</div>';
                }
                $chk = '<p style="margin:.4em 0"><label><input type="checkbox" name="confirm" value="1" /> '
                    . hsc($this->getLang('confirm')) . '</label></p>';
                if (!$h->otpEnabled()) {
                    $chk .= '<p style="margin:.4em 0;color:#b42318"><label><input type="checkbox" name="nogate" value="1" /> '
                        . hsc($this->getLang('nogate')) . '</label></p>';
                }
                echo $this->form('start', $this->getLang('btn_start'), $chk);
            }
        }

        $this->otpBox();

        echo '<h2>' . hsc($this->getLang('sec_title')) . '</h2><ul>';
        foreach (array('sec_1', 'sec_2', 'sec_3', 'sec_4') as $k) {
            echo '<li>' . hsc($this->getLang($k)) . '</li>';
        }
        echo '</ul>';

        $tail = $this->logTail();
        if (trim($tail) !== '') {
            echo '<details style="margin-top:1em"><summary>' . hsc($this->getLang('log')) . '</summary><pre>'
                . hsc($tail) . '</pre></details>';
        }
        echo '<p style="margin-top:1.5em;color:#777;font-size:.9em">' . hsc($this->getLang('footer')) . '</p>';
    }
}
