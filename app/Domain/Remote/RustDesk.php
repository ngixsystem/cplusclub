<?php

namespace App\Domain\Remote;

use App\Models\RemoteHost;

/**
 * Links and settings for the self-hosted RustDesk server. The connect link carries the
 * server and its key («<id>@<host>?key=…»), so the operator's RustDesk needs no setup.
 */
final class RustDesk
{
    public static function host(): string { return (string) config('services.rustdesk.host'); }
    public static function key(): string { return (string) config('services.rustdesk.key'); }

    /** rustdesk://connection/new/<id>@<host>?key=…&password=… */
    public static function connectUrl(RemoteHost $h): string
    {
        $id = preg_replace('/\D/', '', (string) $h->rustdesk_id);
        return 'rustdesk://connection/new/'.$id.'@'.self::host().'?'.http_build_query(['key' => self::key(), 'password' => $h->password], '', '&', PHP_QUERY_RFC3986);
    }

    /** The string «rustdesk.exe --config» accepts: base64url(JSON) reversed. */
    public static function configString(): string
    {
        $json = json_encode(['host' => self::host(), 'relay' => self::host(), 'api' => '', 'key' => self::key()], JSON_UNESCAPED_SLASHES);
        return strrev(rtrim(strtr(base64_encode($json), '+/', '-_'), '='));
    }

    /** RustDesk shows IDs as «123 456 789». */
    public static function formatId(?string $id): ?string
    {
        if (! $id) return null;
        return trim(chunk_split(preg_replace('/\D/', '', $id), 3, ' '));
    }

    public static function password(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $out;
    }

    /**
     * Which RustDesk IDs are online right now, asked from hbbs the way the RustDesk client
     * does it: an OnlineRequest on the NAT-test port (main port - 1), answered with a
     * bitmask. A peer counts as online when it registered within the last 30 s.
     * Returns null when the server can't be asked.
     *
     * @param  list<string>  $ids
     * @return array<string, bool>|null
     */
    public static function online(array $ids, float $timeout = 2.0): ?array
    {
        $ids = array_values(array_unique(array_filter(array_map(fn ($i) => preg_replace('/\D/', '', (string) $i), $ids))));
        if (! $ids) return [];
        $host = (string) (config('services.rustdesk.status_host') ?: self::host());
        $port = (int) config('services.rustdesk.port', 21116) - 1;
        $req = self::pbBytes(1, 'cplusclub');
        foreach ($ids as $id) $req .= self::pbBytes(2, $id);
        $msg = self::pbBytes(23, $req);                       // RendezvousMessage.online_request
        $sock = @stream_socket_client("tcp://{$host}:{$port}", $errno, $err, $timeout);
        if (! $sock) return null;
        try {
            stream_set_timeout($sock, (int) ceil($timeout));
            fwrite($sock, self::frame($msg));
            $body = self::readFrame($sock);
        } finally { fclose($sock); }
        if ($body === null) return null;
        $resp = self::pbField($body, 24);                      // RendezvousMessage.online_response
        if ($resp === null) return null;
        $states = self::pbField($resp, 1) ?? '';               // OnlineResponse.states
        $out = [];
        foreach ($ids as $i => $id) {
            $byte = ord($states[intdiv($i, 8)] ?? "\0");
            $out[$id] = (bool) ($byte & (1 << (7 - $i % 8)));
        }
        return $out;
    }

    /** hbb_common BytesCodec: length << 2 with the header size in the low two bits, little-endian. */
    private static function frame(string $data): string
    {
        $n = strlen($data);
        if ($n <= 0x3F) return chr($n << 2).$data;
        if ($n <= 0x3FFF) return pack('v', ($n << 2) | 1).$data;
        if ($n <= 0x3FFFFF) return substr(pack('V', ($n << 2) | 2), 0, 3).$data;
        return pack('V', ($n << 2) | 3).$data;
    }

    /** @param resource $sock */
    private static function readFrame($sock): ?string
    {
        $first = fread($sock, 1);
        if ($first === false || $first === '') return null;
        $headLen = (ord($first) & 3) + 1;
        $head = $first.($headLen > 1 ? self::readN($sock, $headLen - 1) : '');
        if (strlen($head) !== $headLen) return null;
        $n = unpack('V', str_pad($head, 4, "\0"))[1] >> 2;
        if ($n > 1 << 20) return null;
        $body = self::readN($sock, $n);
        return strlen($body) === $n ? $body : null;
    }

    /** @param resource $sock */
    private static function readN($sock, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $chunk = fread($sock, $n - strlen($buf));
            if ($chunk === false || $chunk === '') break;
            $buf .= $chunk;
        }
        return $buf;
    }

    private static function varint(int $v): string
    {
        $out = '';
        while ($v > 0x7F) { $out .= chr(($v & 0x7F) | 0x80); $v >>= 7; }
        return $out.chr($v);
    }

    private static function pbBytes(int $field, string $data): string
    {
        return self::varint(($field << 3) | 2).self::varint(strlen($data)).$data;
    }

    /** The first length-delimited field with this number in a protobuf message, or null. */
    private static function pbField(string $msg, int $want): ?string
    {
        $pos = 0; $len = strlen($msg);
        $read = function () use ($msg, &$pos, $len): ?int {
            $v = 0; $shift = 0;
            while ($pos < $len) {
                $b = ord($msg[$pos++]);
                $v |= ($b & 0x7F) << $shift;
                if (! ($b & 0x80)) return $v;
                $shift += 7;
                if ($shift > 56) return null;
            }
            return null;
        };
        while ($pos < $len) {
            $tag = $read();
            if ($tag === null) return null;
            [$field, $type] = [$tag >> 3, $tag & 7];
            if ($type === 0) { if ($read() === null) return null; }
            elseif ($type === 1) $pos += 8;
            elseif ($type === 5) $pos += 4;
            elseif ($type === 2) {
                $n = $read();
                if ($n === null || $pos + $n > $len) return null;
                if ($field === $want) return substr($msg, $pos, $n);
                $pos += $n;
            } else return null;
        }
        return null;
    }
}
