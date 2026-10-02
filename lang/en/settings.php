<?php
$lang['binary']        = 'Path to the cloudflared executable (empty: use the one downloaded to data/publictunnel/bin; the file must be named cloudflared)';
$lang['port']          = 'Local port this wiki listens on (empty: use the server port of the current request)';
$lang['verify_binary'] = 'Check cloudflared\'s SHA-256 against the official release on every start (keep this on)';
$lang['readonly']      = 'Refuse every write request except login through the public address (off: edit, delete and upload as the wiki permissions allow)';
$lang['block_admin']   = 'Block the admin screen through the public address (off: it can be opened after admin login)';
$lang['otp_ttl']       = 'How long a verified visitor stays signed in (hours)';
