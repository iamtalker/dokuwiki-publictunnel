<?php
/**
 * DokuWiki Plugin publictunnel (Admin Component)
 *
 * cloudflared "빠른 터널"(Cloudflare, 계정 불필요)로 이 위키에 임시 공개 주소를 만든다.
 * 켤 때마다 https://….trycloudflare.com 형태의 새 주소가 나오고, 끄면 사라진다.
 *
 * PHP 7.0 이상에서 동작한다. Windows(popen + start /B)와 리눅스(nohup)를 지원한다.
 */

if (!defined('DOKU_INC')) die();

class admin_plugin_publictunnel extends DokuWiki_Admin_Plugin
{
    /** 받아 둘 cloudflared 릴리스(공식 GitHub). 해시는 그 릴리스 파일의 SHA-256. 새 버전을 쓰려면 둘 다 바꾼다. */
    const VERSION = '2026.9.3';
    const BASE = 'https://github.com/cloudflare/cloudflared/releases/download/';

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

    protected function dataDir()
    {
        global $conf;
        $dir = $conf['savedir'] . '/publictunnel';
        io_mkdir_p($dir);
        return $dir;
    }

    protected function logFile()
    {
        return $this->dataDir() . '/tunnel.log';
    }

    protected function stateFile()
    {
        return $this->dataDir() . '/tunnel.json';
    }

    protected function defaultBinary()
    {
        return $this->dataDir() . '/bin/' . ($this->isWindows() ? 'cloudflared.exe' : 'cloudflared');
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

    protected function hasBinary()
    {
        $b = $this->binary();
        return is_file($b) && ($this->isWindows() || is_executable($b));
    }

    /** 이 운영체제에서 받을 파일(없으면 null) */
    protected function release()
    {
        if ($this->isWindows()) {
            return array('file' => 'cloudflared-windows-amd64.exe',
                         'sha256' => 'f096265ec2fcbe9bb6e2d64268db167ced3fcbb83d894bdb9e2fcdb26f2ea7e2');
        }
        if (stripos(PHP_OS, 'linux') === 0) {
            $arch = strtolower(php_uname('m'));
            if ($arch === 'aarch64' || $arch === 'arm64') {
                return array('file' => 'cloudflared-linux-arm64',
                             'sha256' => 'aaeb2d7d0da3614634c7e03ab13487a1522c2e79165ed2929cfe23d5e95b326d');
            }
            return array('file' => 'cloudflared-linux-amd64',
                         'sha256' => '77e26d8d900e0b8469f416239d14b5f296525fdf79fee6f511ef55609e3fbac2');
        }
        return null;
    }

    // ------------------------------------------------------------------ 상태

    protected function readState()
    {
        $f = $this->stateFile();
        if (!is_file($f)) {
            return array();
        }
        $j = json_decode((string) file_get_contents($f), true);
        return is_array($j) ? $j : array();
    }

    protected function writeState($state)
    {
        file_put_contents($this->stateFile(), json_encode($state));
    }

    protected function clearState()
    {
        @unlink($this->stateFile());
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
        $name = basename($this->binary());
        if ($this->isWindows()) {
            $t = $this->winTasks('PID eq ' . (int) $pid);
            return isset($t[(int) $pid]) && strcasecmp($t[(int) $pid], $name) === 0;
        }
        $cmd = @file_get_contents('/proc/' . (int) $pid . '/cmdline');
        return $cmd !== false && strpos($cmd, $name) !== false;
    }

    protected function running()
    {
        $st = $this->readState();
        if (!$st || empty($st['pid'])) {
            return array();
        }
        if (!$this->pidAlive($st['pid'])) {
            $this->clearState();
            return array();
        }
        return $st;
    }

    // ------------------------------------------------------------------ 동작

    protected function startTunnel()
    {
        if ($this->running()) {
            return;
        }
        if (!$this->hasBinary()) {
            msg($this->getLang('err_nobinary'), -1);
            return;
        }
        $bin = $this->binary();
        $port = (int) ($this->getConf('port') ?: (isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : 0));
        if ($port < 1 || $port > 65535) {
            msg($this->getLang('err_port'), -1);
            return;
        }
        if ($this->isWindows() && !preg_match('/^[^"&|<>%^\r\n]+$/', $bin)) {
            msg($this->getLang('err_nobinary') . ' (' . hsc($bin) . ')', -1);
            return;
        }
        $local = 'http://127.0.0.1:' . $port;
        $log = $this->logFile();
        @unlink($log);
        $pid = 0;

        if ($this->isWindows()) {
            // start /B: 이 요청이 끝나도 계속 도는 백그라운드 프로세스. 새로 생긴 PID 를 찾아 기억한다.
            $image = basename($bin);
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
            msg($this->getLang('err_start'), -1);
            return;
        }
        $this->writeState(array('pid' => $pid, 'started' => time(), 'port' => $port, 'local' => $local));

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
            $this->clearState();
            msg($this->getLang('err_start'), -1);
        }
    }

    protected function stopTunnel()
    {
        $st = $this->readState();
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
        $this->clearState();
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
        $ctx = stream_context_create(array('http' => array(
            'timeout' => 180, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'dokuwiki-publictunnel',
        )));
        $url = self::BASE . self::VERSION . '/' . $r['file'];
        if (!@copy($url, $part, $ctx)) {
            $e = error_get_last();
            @unlink($part);
            msg(sprintf($this->getLang('err_download'), hsc($e ? $e['message'] : '?')), -1);
            return;
        }
        if (!hash_equals($r['sha256'], (string) hash_file('sha256', $part))) {
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
        $act = $INPUT->str('ptunnel');
        if ($act === '' || !checkSecurityToken() || !$this->canExec()) {
            return;
        }
        switch ($act) {
            case 'start':
                if (!$INPUT->bool('confirm')) {
                    msg($this->getLang('err_confirm'), -1);
                    break;
                }
                $this->startTunnel();
                break;
            case 'stop':
                $this->stopTunnel();
                break;
            case 'download':
                if ($this->customBinary() === '') {
                    $this->downloadBinary();
                }
                break;
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

    public function html()
    {
        global $conf;
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
            if (!$this->hasBinary()) {
                $r = $this->release();
                if ($this->customBinary() !== '') {
                    echo '<div class="error">' . hsc($this->getLang('err_nobinary')) . ' (' . hsc($this->customBinary()) . ')</div>';
                } elseif (!$r) {
                    echo '<div class="error">' . hsc($this->getLang('unsupported')) . '</div>';
                } else {
                    echo '<div class="info">' . hsc(sprintf($this->getLang('need_binary'), 'cloudflared ' . self::VERSION, '40MB')) . '</div>';
                    echo $this->form('download', $this->getLang('btn_download'));
                }
            } else {
                echo '<div class="info">' . hsc($this->getLang('warn_public')) . '</div>';
                if (empty($conf['useacl'])) {
                    echo '<div class="error"><strong>' . hsc($this->getLang('warn_noacl')) . '</strong></div>';
                }
                $chk = '<p style="margin:.4em 0"><label><input type="checkbox" name="confirm" value="1" /> '
                    . hsc($this->getLang('confirm')) . '</label></p>';
                echo $this->form('start', $this->getLang('btn_start'), $chk);
            }
        }

        $tail = $this->logTail();
        if (trim($tail) !== '') {
            echo '<details style="margin-top:1em"><summary>' . hsc($this->getLang('log')) . '</summary><pre>'
                . hsc($tail) . '</pre></details>';
        }
        echo '<p style="margin-top:1.5em;color:#777;font-size:.9em">' . hsc($this->getLang('footer')) . '</p>';
    }
}
