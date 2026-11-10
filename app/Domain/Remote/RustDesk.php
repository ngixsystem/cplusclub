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
}
