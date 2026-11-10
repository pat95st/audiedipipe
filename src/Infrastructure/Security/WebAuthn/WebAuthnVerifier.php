<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security\WebAuthn;

use InvalidArgumentException;

/**
 * Server side WebAuthn (passkey) ceremonies without external libraries.
 *
 * Attestation statements are not verified ("none" attestation is requested),
 * which is the recommended setting for passkeys in consumer-style apps.
 */
final class WebAuthnVerifier
{
    private const FLAG_USER_PRESENT = 0x01;
    private const FLAG_USER_VERIFIED = 0x04;
    private const FLAG_ATTESTED_DATA = 0x40;

    public function __construct(
        private readonly string $rpId,
        private readonly string $rpName,
        private readonly string $origin,
    ) {
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Invalid base64url data.');
        }

        return $decoded;
    }

    /**
     * @param list<string> $excludeCredentialIds raw credential ids
     *
     * @return array<string, mixed> PublicKeyCredentialCreationOptions (binary values base64url encoded)
     */
    public function creationOptions(string $challenge, string $userHandle, string $userName, string $displayName, array $excludeCredentialIds = []): array
    {
        return [
            'challenge' => self::base64UrlEncode($challenge),
            'rp' => ['id' => $this->rpId, 'name' => $this->rpName],
            'user' => [
                'id' => self::base64UrlEncode($userHandle),
                'name' => $userName,
                'displayName' => $displayName,
            ],
            'pubKeyCredParams' => [
                ['type' => 'public-key', 'alg' => CoseKey::ALG_ES256],
                ['type' => 'public-key', 'alg' => CoseKey::ALG_RS256],
            ],
            'timeout' => 120000,
            'attestation' => 'none',
            'authenticatorSelection' => [
                'residentKey' => 'required',
                'requireResidentKey' => true,
                'userVerification' => 'preferred',
            ],
            'excludeCredentials' => array_map(
                static fn (string $id): array => ['type' => 'public-key', 'id' => self::base64UrlEncode($id)],
                $excludeCredentialIds,
            ),
        ];
    }

    /**
     * @param list<string> $allowCredentialIds raw credential ids (empty = discoverable credentials)
     *
     * @return array<string, mixed>
     */
    public function requestOptions(string $challenge, array $allowCredentialIds = []): array
    {
        return [
            'challenge' => self::base64UrlEncode($challenge),
            'rpId' => $this->rpId,
            'timeout' => 120000,
            'userVerification' => 'preferred',
            'allowCredentials' => array_map(
                static fn (string $id): array => ['type' => 'public-key', 'id' => self::base64UrlEncode($id)],
                $allowCredentialIds,
            ),
        ];
    }

    /**
     * @return array{credentialId: string, publicKeyPem: string, signCount: int, userVerified: bool}
     */
    public function verifyRegistration(string $clientDataJson, string $attestationObject, string $expectedChallenge): array
    {
        $this->verifyClientData($clientDataJson, 'webauthn.create', $expectedChallenge);

        $attestation = Cbor::decode($attestationObject);
        if (!is_array($attestation) || !($attestation['authData'] ?? null) instanceof ByteString) {
            throw new InvalidArgumentException('Invalid attestation object.');
        }
        $authData = $attestation['authData']->bytes;
        $parsed = $this->parseAuthenticatorData($authData);
        if (($parsed['flags'] & self::FLAG_ATTESTED_DATA) === 0) {
            throw new InvalidArgumentException('No attested credential data.');
        }
        $offset = 37 + 16; // rpIdHash(32) + flags(1) + counter(4) + aaguid(16)
        $lengthData = unpack('n', substr($authData, $offset, 2));
        $credentialIdLength = (int) ($lengthData[1] ?? 0);
        $offset += 2;
        $credentialId = substr($authData, $offset, $credentialIdLength);
        if (strlen($credentialId) !== $credentialIdLength || $credentialIdLength === 0) {
            throw new InvalidArgumentException('Invalid credential id.');
        }
        $offset += $credentialIdLength;
        [$coseKey] = Cbor::decodeFirst($authData, $offset);
        if (!is_array($coseKey)) {
            throw new InvalidArgumentException('Invalid credential public key.');
        }

        return [
            'credentialId' => $credentialId,
            'publicKeyPem' => CoseKey::toPem($coseKey),
            'signCount' => $parsed['signCount'],
            'userVerified' => ($parsed['flags'] & self::FLAG_USER_VERIFIED) !== 0,
        ];
    }

    /**
     * @return int the new signature counter
     */
    public function verifyAssertion(
        string $clientDataJson,
        string $authenticatorData,
        string $signature,
        string $expectedChallenge,
        string $publicKeyPem,
        int $storedSignCount,
    ): int {
        $this->verifyClientData($clientDataJson, 'webauthn.get', $expectedChallenge);
        $parsed = $this->parseAuthenticatorData($authenticatorData);

        $signedData = $authenticatorData . hash('sha256', $clientDataJson, true);
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw new InvalidArgumentException('Stored public key is invalid.');
        }
        if (openssl_verify($signedData, $signature, $key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('Invalid passkey signature.');
        }
        $signCount = $parsed['signCount'];
        if ($signCount !== 0 && $storedSignCount !== 0 && $signCount <= $storedSignCount) {
            throw new InvalidArgumentException('Signature counter did not increase - possible cloned authenticator.');
        }

        return $signCount;
    }

    private function verifyClientData(string $clientDataJson, string $expectedType, string $expectedChallenge): void
    {
        $clientData = json_decode($clientDataJson, true);
        if (!is_array($clientData)) {
            throw new InvalidArgumentException('Invalid client data.');
        }
        if (($clientData['type'] ?? '') !== $expectedType) {
            throw new InvalidArgumentException('Unexpected client data type.');
        }
        $challenge = self::base64UrlDecode((string) ($clientData['challenge'] ?? ''));
        if (!hash_equals($expectedChallenge, $challenge)) {
            throw new InvalidArgumentException('Challenge mismatch.');
        }
        if (rtrim((string) ($clientData['origin'] ?? ''), '/') !== rtrim($this->origin, '/')) {
            throw new InvalidArgumentException('Origin mismatch.');
        }
    }

    /**
     * @return array{flags: int, signCount: int}
     */
    private function parseAuthenticatorData(string $authData): array
    {
        if (strlen($authData) < 37) {
            throw new InvalidArgumentException('Authenticator data too short.');
        }
        if (!hash_equals(hash('sha256', $this->rpId, true), substr($authData, 0, 32))) {
            throw new InvalidArgumentException('RP ID hash mismatch.');
        }
        $flags = ord($authData[32]);
        if (($flags & self::FLAG_USER_PRESENT) === 0) {
            throw new InvalidArgumentException('User presence flag not set.');
        }
        $counter = unpack('N', substr($authData, 33, 4));

        return ['flags' => $flags, 'signCount' => (int) ($counter[1] ?? 0)];
    }
}
