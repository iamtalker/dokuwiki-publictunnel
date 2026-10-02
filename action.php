<?php
/**
 * DokuWiki Plugin publictunnel (Action Component) — 공개 주소의 검문소
 *
 * 공개 주소(터널)로 들어온 요청만 검사한다. 이 플러그인이 터널을 열어 둔 동안:
 *   1. 구글 OTP 6자리를 맞추지 못하면 위키(로그인 화면 포함)를 보여 주지 않는다.
 *      검사는 DokuWiki 가 로그인을 처리하기 전(INIT_LANG_LOAD)에 한다. 이 이벤트는 init.php 를 거치는 모든 진입점
 *      (doku.php, fetch.php, ajax.php, 첨부 파일, RSS, 원격 API …)에서 일어나므로 한꺼번에 막힌다.
 *   2. 로그인 외의 쓰기 요청(POST)을 막고, 관리 화면에 못 들어가게 한다(설정으로 끌 수 있음).
 * 이 위키의 로컬 주소(localhost 등)로 오는 요청은 건드리지 않는다.
 */

if (!defined('DOKU_INC')) die();

class action_plugin_publictunnel extends DokuWiki_Action_Plugin
{
    public function register(Doku_Event_Handler $controller)
    {
        $controller->register_hook('INIT_LANG_LOAD', 'BEFORE', $this, 'gate', null, -1000);
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'restrict', null, -1000);
    }

    /** @return helper_plugin_publictunnel|null */
    protected function helper()
    {
        $h = plugin_load('helper', 'publictunnel');
        return $h ? $h : null;
    }

    /** 이 요청이 검문 대상인가(터널을 거쳐 왔고, 이 플러그인이 연 터널이 열려 있는가). 대상이면 상태를 돌려준다. */
    protected function target($h)
    {
        if (!$h || !$h->isTunnelRequest()) {
            return null;
        }
        $st = $h->readState();
        return ($st && !empty($st['gate'])) ? $st : null;
    }

    // ------------------------------------------------------------------ 검문소

    public function gate(Doku_Event $event, $param)
    {
        $h = $this->helper();
        $st = $this->target($h);
        if (!$st) {
            return;
        }
        $script = basename(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '');
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';

        if ($st['gate'] === 'otp') {
            $tunnelId = isset($st['started']) ? $st['started'] : 0;
            $cookie = isset($_COOKIE[helper_plugin_publictunnel::COOKIE]) ? $_COOKIE[helper_plugin_publictunnel::COOKIE] : '';
            if (!$h->checkToken($cookie, $tunnelId)) {
                $who = $h->clientId();
                $left = $h->lockedFor($who);
                if ($left > 0) {
                    $this->deny(429, 'gate_locked', $script, $left);
                }
                if ($script === 'doku.php' && $method === 'POST' && isset($_POST['ptotp'])) {
                    if ($h->otpCheck($_POST['ptotp'])) {
                        $h->clearFail($who);
                        list($tok, $ttl) = $h->issueToken($tunnelId);
                        header('Set-Cookie: ' . helper_plugin_publictunnel::COOKIE . '=' . $tok . '; Max-Age=' . $ttl
                            . '; Path=/; HttpOnly; Secure; SameSite=Lax');
                        header('Location: ' . $this->safePath(), true, 303);
                        header('Cache-Control: no-store');
                        exit;
                    }
                    $h->recordFail($who);
                    $this->deny(401, 'gate_bad', $script, $h->lockedFor($who));
                }
                $this->deny(401, '', $script, 0);
            }
        }

        // 여기까지 왔으면 인증을 통과했거나(또는 OTP 없이 열기로 한 경우) — 공개 중 제한을 적용한다
        if ($this->getConf('readonly') && in_array($method, array('POST', 'PUT', 'DELETE', 'PATCH'), true)) {
            $do = isset($_REQUEST['do']) ? $_REQUEST['do'] : '';
            if (!($script === 'doku.php' && $do === 'login')) {
                $this->deny(403, 'deny_readonly', $script, 0, true);
            }
        }
    }

    /** 공개 주소로는 관리 화면·쓰기 화면을 열지 못하게 한다 */
    public function restrict(Doku_Event $event, $param)
    {
        $h = $this->helper();
        if (!$this->target($h)) {
            return;
        }
        $act = is_array($event->data) ? key($event->data) : $event->data;
        $blocked = array();
        if ($this->getConf('block_admin')) {
            $blocked[] = 'admin';
        }
        if ($this->getConf('readonly')) {
            $blocked = array_merge($blocked, array('edit', 'preview', 'save', 'draft', 'draftdel', 'recover', 'revert',
                'register', 'resendpwd', 'profile', 'profile_delete'));
        }
        if (in_array($act, $blocked, true)) {
            $event->data = 'show';
            msg($this->getLang($act === 'admin' ? 'deny_admin' : 'deny_readonly'), -1);
        }
    }

    // ------------------------------------------------------------------ 응답

    /** 같은 위치로 돌려보낼 안전한 경로(열린 리다이렉트 방지: 항상 이 사이트 안의 경로만) */
    protected function safePath()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        $uri = preg_replace('/[\x00-\x1f\x7f]/', '', $uri);
        if ($uri === '' || $uri[0] !== '/' || strpos($uri, '//') === 0 || strpos($uri, '\\') !== false) {
            return '/';
        }
        return $uri;
    }

    protected function deny($code, $msgKey, $script, $left, $plain = false)
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        http_response_code($code);
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');
        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        if ($plain || $script !== 'doku.php' || !in_array($method, array('GET', 'HEAD', 'POST'), true)) {
            header('Content-Type: text/plain; charset=utf-8');
            echo $code . ' ' . ($msgKey !== '' ? $this->getLang($msgKey) : $this->getLang('gate_title'));
            exit;
        }
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
        $note = '';
        if ($msgKey === 'gate_bad') {
            $note = $this->getLang('gate_bad');
        } elseif ($msgKey === 'gate_locked') {
            $note = sprintf($this->getLang('gate_locked'), ceil($left / 60));
        }
        $e = function ($s) {
            return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        };
        echo '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow"><title>' . $e($this->getLang('gate_title')) . '</title>'
            . '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f4f5f7;color:#222;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}'
            . '.box{background:#fff;border:1px solid #d8dbe0;border-radius:12px;padding:28px;max-width:340px;width:90%;text-align:center}'
            . 'input{font:28px/1.2 ui-monospace,monospace;letter-spacing:.3em;text-align:center;width:100%;box-sizing:border-box;padding:.4em;margin:.8em 0}'
            . 'button{font:inherit;padding:.6em 1.4em;border-radius:8px;border:0;background:#2a5bd7;color:#fff}.err{color:#b42318}'
            . '@media(prefers-color-scheme:dark){body{background:#14171d;color:#e7eaf0}.box{background:#1c2029;border-color:#323847}input{background:#0f1218;color:#e7eaf0;border:1px solid #323847}}</style></head><body>'
            . '<div class="box"><h1 style="font-size:1.2em;margin:0 0 .4em">' . $e($this->getLang('gate_title')) . '</h1>'
            . '<p style="margin:0;color:#667085">' . $e($this->getLang('gate_help')) . '</p>';
        if ($left > 0 && $msgKey === 'gate_locked') {
            echo '<p class="err">' . $e($note) . '</p>';
        } else {
            echo ($note !== '' ? '<p class="err">' . $e($note) . '</p>' : '')
                . '<form method="post" action="' . $e($this->safePath()) . '" autocomplete="off">'
                . '<input name="ptotp" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code" autofocus required>'
                . '<button type="submit">' . $e($this->getLang('gate_btn')) . '</button></form>';
        }
        echo '</div></body></html>';
        exit;
    }
}
