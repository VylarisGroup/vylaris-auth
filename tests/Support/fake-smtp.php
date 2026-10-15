<?php
// Mini-SMTP-Server für Tests: php fake-smtp.php <basis-pfad> [auth]
// Schreibt Port nach <basis>.port, Transkript nach <basis>.log; bedient genau eine Verbindung.
$base = $argv[1];
$needAuth = ($argv[2] ?? '') === 'auth';
$srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
file_put_contents($base . '.port', (string) parse_url(stream_socket_get_name($srv, false), PHP_URL_PORT));
$c = stream_socket_accept($srv, 15);
$log = '';
$w = static function (string $l) use ($c, &$log): void { fwrite($c, $l . "\r\n"); $log .= "S: $l\n"; };
$w('220 fake ESMTP');
$data = false;
while (($line = fgets($c)) !== false) {
    $line = rtrim($line, "\r\n");
    $log .= "C: $line\n";
    if ($data) {
        if ($line === '.') { $data = false; $w('250 queued'); }
        continue;
    }
    $cmd = strtoupper(strtok($line, ' '));
    switch ($cmd) {
        case 'EHLO': fwrite($c, "250-fake\r\n250 AUTH PLAIN LOGIN\r\n"); $log .= "S: 250 caps\n"; break;
        case 'AUTH': $w('235 ok'); break;
        case 'MAIL': case 'RCPT': $w('250 ok'); break;
        case 'DATA': $data = true; $w('354 go'); break;
        case 'QUIT': $w('221 bye'); break 2;
        default: $w('500 what');
    }
}
file_put_contents($base . '.log', $log);
