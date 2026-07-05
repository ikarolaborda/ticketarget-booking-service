<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Jwks\JwkConverter;
use PHPUnit\Framework\TestCase;

final class JwkConverterTest extends TestCase
{
    public function test_it_rebuilds_a_pem_that_verifies_a_real_rs256_signature(): void
    {
        [$privatePem, $jwk] = $this->generateKeypairAndJwk();

        $pem = JwkConverter::rsaPemFromJwk($jwk);

        $this->assertNotNull($pem);

        $message = 'the end-to-end principle';
        $signature = '';
        $this->assertTrue(openssl_sign($message, $signature, $privatePem, OPENSSL_ALGO_SHA256));

        // The whole point: a signature made with the private key must verify
        // against the public key reconstructed purely from the JWK n/e.
        $this->assertSame(1, openssl_verify($message, $signature, $pem, OPENSSL_ALGO_SHA256));
    }

    public function test_it_matches_openssls_own_spki_pem_byte_for_byte(): void
    {
        [$privatePem, $jwk] = $this->generateKeypairAndJwk();

        $details = openssl_pkey_get_details(openssl_pkey_get_private($privatePem));
        $expected = $this->normalize((string) $details['key']);

        $this->assertSame($expected, $this->normalize((string) JwkConverter::rsaPemFromJwk($jwk)));
    }

    public function test_it_handles_a_modulus_whose_high_bit_is_set(): void
    {
        // A modulus with a leading 0x80+ byte must be padded so the DER
        // INTEGER is not read as negative; a real 2048-bit key hits this often.
        for ($i = 0; $i < 8; $i++) {
            [$privatePem, $jwk] = $this->generateKeypairAndJwk();
            $modulus = base64_decode(strtr($jwk['n'], '-_', '+/'), true);

            if ((ord($modulus[0]) & 0x80) === 0) {
                continue;
            }

            $pem = JwkConverter::rsaPemFromJwk($jwk);
            $signature = '';
            openssl_sign('x', $signature, $privatePem, OPENSSL_ALGO_SHA256);

            $this->assertSame(1, openssl_verify('x', $signature, (string) $pem, OPENSSL_ALGO_SHA256));

            return;
        }

        $this->markTestSkipped('No high-bit modulus generated in 8 attempts.');
    }

    public function test_it_rejects_a_non_rsa_or_malformed_jwk(): void
    {
        $this->assertNull(JwkConverter::rsaPemFromJwk(['kty' => 'EC', 'n' => 'x', 'e' => 'AQAB']));
        $this->assertNull(JwkConverter::rsaPemFromJwk(['kty' => 'RSA', 'e' => 'AQAB']));
        $this->assertNull(JwkConverter::rsaPemFromJwk(['kty' => 'RSA', 'n' => '', 'e' => '']));
        $this->assertNull(JwkConverter::rsaPemFromJwk(['kty' => 'RSA', 'n' => '!!!not-base64!!!', 'e' => '@@@']));
    }

    /**
     * @return array{0: string, 1: array{kty: string, n: string, e: string}}
     */
    private function generateKeypairAndJwk(): array
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $privatePem = '';
        openssl_pkey_export($key, $privatePem);

        $details = openssl_pkey_get_details($key);

        $encode = static fn (string $v): string => rtrim(strtr(base64_encode($v), '+/', '-_'), '=');

        return [$privatePem, [
            'kty' => 'RSA',
            'n' => $encode($details['rsa']['n']),
            'e' => $encode($details['rsa']['e']),
        ]];
    }

    private function normalize(string $pem): string
    {
        return trim(str_replace("\r\n", "\n", $pem));
    }
}
