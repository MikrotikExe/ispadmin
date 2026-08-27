<?php
/**
 * RouterOS API klient (pure PHP, bez zavislosti).
 * Podporuje novy plain login (ROS 6.43+) aj stary challenge-response login.
 * Vychadza z verejne dostupnej Mikrotik PHP API triedy, upravene pre PHP 8.
 */
class RouterosApi
{
    public bool  $debug      = false;
    public bool  $connected  = false;
    public int   $port       = 8728;
    public bool  $ssl        = false;
    public int   $timeout    = 5;
    public int   $attempts   = 2;
    public int   $delay      = 1;
    public string $error     = '';

    /** @var resource|null */
    private $socket = null;

    private function dbg(string $text): void
    {
        if ($this->debug) {
            echo $text . "\n";
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /** Kodovanie dlzky slova podla RouterOS API protokolu. */
    private function encodeLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        if ($length < 0x4000) {
            $length |= 0x8000;
            return chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x200000) {
            $length |= 0xC00000;
            return chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        if ($length < 0x10000000) {
            $length |= 0xE0000000;
            return chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
                 . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }
        return chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF)
             . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
    }

    /** Zapise jedno slovo. $last=true posle ukoncovaci nulovy bajt vety. */
    private function write(string $command, bool $last = true): bool
    {
        if ($command === '' && !$last) {
            return false;
        }
        if ($command !== '') {
            fwrite($this->socket, $this->encodeLength(strlen($command)) . $command);
            $this->dbg('<<< [' . strlen($command) . '] ' . $command);
        }
        if ($last) {
            fwrite($this->socket, chr(0));
        }
        return true;
    }

    /** Precita odpoved zo socketu ako pole slov. */
    private function read(): array
    {
        $response = [];
        $lastWord = '';
        $receivedDone = false;

        while (true) {
            $byte = ord(fread($this->socket, 1));
            $length = 0;

            if ($byte & 128) {
                if (($byte & 192) === 128) {
                    $length = (($byte & 63) << 8) + ord(fread($this->socket, 1));
                } elseif (($byte & 224) === 192) {
                    $length = (($byte & 31) << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                } elseif (($byte & 240) === 224) {
                    $length = (($byte & 15) << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                } else {
                    $length = ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                    $length = ($length << 8) + ord(fread($this->socket, 1));
                }
            } else {
                $length = $byte;
            }

            $lastWord = '';
            if ($length > 0) {
                $got = 0;
                while ($got < $length) {
                    $chunk = fread($this->socket, $length - $got);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $lastWord .= $chunk;
                    $got = strlen($lastWord);
                }
                $response[] = $lastWord;
                $this->dbg('>>> [' . $got . '] ' . $lastWord);
            }

            if ($lastWord === '!done') {
                $receivedDone = true;
            }

            $status = stream_get_meta_data($this->socket);
            $unread = $status['unread_bytes'] ?? 0;

            if ($length === 0 && $receivedDone && $unread === 0) {
                break;
            }
            // ochrana proti zaseknutiu po neuspesnom logine
            if (!$this->connected && $length === 0 && $unread === 0) {
                break;
            }
        }
        return $response;
    }

    /** Prevedie surovu odpoved na pole asociativnych zaznamov (!re) + status. */
    private function parse(array $raw): array
    {
        $parsed = ['items' => [], 'status' => 'unknown', 'message' => ''];
        $current = null;

        foreach ($raw as $word) {
            if ($word === '!re') {
                if ($current !== null) {
                    $parsed['items'][] = $current;
                }
                $current = [];
            } elseif ($word === '!done') {
                $parsed['status'] = 'done';
            } elseif ($word === '!trap' || $word === '!fatal') {
                $parsed['status'] = 'error';
            } elseif (strlen($word) > 0 && $word[0] === '=') {
                $pos = strpos($word, '=', 1);
                if ($pos !== false) {
                    $key = substr($word, 1, $pos - 1);
                    $val = substr($word, $pos + 1);
                    if ($parsed['status'] === 'error' && $key === 'message') {
                        $parsed['message'] = $val;
                    }
                    if ($current !== null) {
                        $current[$key] = $val;
                    }
                }
            }
        }
        if ($current !== null) {
            $parsed['items'][] = $current;
        }
        return $parsed;
    }

    /**
     * Posle prikaz. $args je pole: bezne =key=value zadavaj ako 'key'=>'value',
     * query daj s prefixom '?' v kluci, napr. '?name'=>'1234'.
     * Vrati parsovanu odpoved.
     */
    public function comm(string $command, array $args = []): array
    {
        if (!$this->connected) {
            return ['items' => [], 'status' => 'error', 'message' => 'not connected'];
        }
        $count = count($args);
        $this->write($command, $count === 0);
        $i = 0;
        foreach ($args as $key => $value) {
            $i++;
            $last = ($i === $count);
            if ($key !== '' && $key[0] === '?') {
                $this->write($key . '=' . $value, $last);
            } else {
                $this->write('=' . $key . '=' . $value, $last);
            }
        }
        return $this->parse($this->read());
    }

    public function connect(string $ip, string $login, string $password): bool
    {
        $this->error = '';
        for ($a = 1; $a <= $this->attempts; $a++) {
            $this->connected = false;
            $proto = $this->ssl ? 'ssl://' : '';
            $ctx = stream_context_create([
                'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
            ]);
            $errno = 0;
            $errstr = '';
            $this->socket = @stream_socket_client(
                $proto . $ip . ':' . $this->port,
                $errno, $errstr, $this->timeout,
                STREAM_CLIENT_CONNECT, $ctx
            );

            if (!$this->socket) {
                $this->error = "spojenie zlyhalo ($errno): $errstr";
                sleep($this->delay);
                continue;
            }
            stream_set_timeout($this->socket, $this->timeout);

            // Novy plain login (ROS 6.43+)
            $this->write('/login', false);
            $this->write('=name=' . $login, false);
            $this->write('=password=' . $password, true);
            $raw = $this->read();

            if (isset($raw[0]) && $raw[0] === '!done' && !isset($raw[1])) {
                $this->connected = true;
                return true;
            }

            // Stary challenge-response login
            $challenge = '';
            foreach ($raw as $w) {
                if (str_starts_with($w, '=ret=')) {
                    $challenge = substr($w, 5);
                }
            }
            if ($challenge !== '') {
                $bin = pack('H*', $challenge);
                $resp = '00' . md5(chr(0) . $password . $bin);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=response=' . $resp, true);
                $raw2 = $this->read();
                if (isset($raw2[0]) && $raw2[0] === '!done') {
                    $this->connected = true;
                    return true;
                }
                $this->error = function_exists('t') ? t('prihlásenie odmietnuté (nesprávne meno/heslo)') : 'login refused (wrong username/password)';
            } else {
                // !trap pri novom logine = zle udaje
                $this->error = function_exists('t') ? t('prihlásenie odmietnuté (nesprávne meno/heslo)') : 'login refused (wrong username/password)';
            }

            fclose($this->socket);
            $this->socket = null;
            sleep($this->delay);
        }
        return false;
    }

    public function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->connected = false;
    }
}
