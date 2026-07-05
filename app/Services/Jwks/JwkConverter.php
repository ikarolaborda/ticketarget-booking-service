<?php

declare(strict_types=1);

namespace App\Services\Jwks;

/**
 * Converts an RSA JWK (RFC 7517/7518: base64url unsigned big-endian n/e)
 * into the PEM SubjectPublicKeyInfo form the openssl extension consumes.
 * Deliberately narrow: RSA signature keys only, and the produced PEM is
 * validated through openssl before it is handed out.
 */
final class JwkConverter
{
    /** @param array<string, mixed> $jwk */
    public static function rsaPemFromJwk(array $jwk): ?string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || ! is_string($jwk['n'] ?? null) || ! is_string($jwk['e'] ?? null)) {
            return null;
        }

        $modulus = self::base64UrlDecode($jwk['n']);
        $exponent = self::base64UrlDecode($jwk['e']);

        if ($modulus === '' || $exponent === '') {
            return null;
        }

        $rsaPublicKey = self::derSequence(self::derUnsignedInteger($modulus).self::derUnsignedInteger($exponent));

        // AlgorithmIdentifier: rsaEncryption OID 1.2.840.113549.1.1.1 + NULL.
        $algorithm = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        $subjectPublicKey = "\x03".self::derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;

        $der = self::derSequence($algorithm.$subjectPublicKey);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            .'-----END PUBLIC KEY-----';

        return openssl_pkey_get_public($pem) === false ? null : $pem;
    }

    private static function derUnsignedInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '') {
            $bytes = "\x00";
        }

        // A set high bit would flip the DER integer negative; pad it back.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    private static function derSequence(string $content): string
    {
        return "\x30".self::derLength(strlen($content)).$content;
    }

    private static function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    private static function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
