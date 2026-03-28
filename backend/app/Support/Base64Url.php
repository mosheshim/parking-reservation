<?php

namespace App\Support;

class Base64Url
{

    /**
     * Base64URL encode (RFC 7515) with padding removed.
     */
    public static function encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64URL decode (RFC 7515).
     *
     * Accepts an unpadded Base64URL string and restores padding before decoding.
     */
    public static function decode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad > 0) {
            $data .= str_repeat('=', 4 - $pad);
        }

        return (string) base64_decode($data, true);
    }
}
