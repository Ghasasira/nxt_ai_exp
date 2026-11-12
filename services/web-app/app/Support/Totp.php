<?php

namespace App\Support;

/**
 * Minimal RFC 6238 TOTP (SHA-1, 6 digits, 30 s window) helper for 2FA (FR-AUTH-04).
 */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 16): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public static function provisioningUri(string $secret, string $email, string $issuer = 'RAG Knowledge Agent'): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($email),
            $secret,
            rawurlencode($issuer)
        );
    }

    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $clean = preg_replace('/\s+/', '', $code) ?? '';
        if (! preg_match('/^\d{6}$/', $clean)) {
            return false;
        }

        $counter = (int) floor(time() / 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::hotp($secret, $counter + $offset), $clean)) {
                return true;
            }
        }

        return false;
    }

    public static function hotp(string $base32Secret, int $counter): string
    {
        $key = self::base32Decode($base32Secret);
        $binCounter = pack('N*', 0).pack('N*', $counter);
        $hash = hash_hmac('sha1', $binCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($truncated % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $input): string
    {
        $input = strtoupper(rtrim($input, '='));
        $bits = '';
        foreach (str_split($input) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byteBits) {
            if (strlen($byteBits) === 8) {
                $bytes .= chr(bindec($byteBits));
            }
        }

        return $bytes;
    }
}
