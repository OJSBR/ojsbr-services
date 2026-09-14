<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrSignature.php
 *
 * Copyright (c) 2026 OJSBR
 *
 * @brief Verifica X-OJSBR-Timestamp + X-OJSBR-Signature (Ed25519).
 *
 * O plugin NUNCA assina com a privada OJSBR — ela não existe aqui.
 * Mensagem: timestamp + "\n" + sha256_hex(body)
 * Assinatura: base64(ed25519(mensagem))
 */

class OjsbrSignature
{
    public const HEADER_TIMESTAMP = 'X-OJSBR-Timestamp';
    public const HEADER_SIGNATURE = 'X-OJSBR-Signature';
    public const SKEW_SECONDS = 300;

    /**
     * Lê timestamp e assinatura de um Request PKP (pedido STNT → plugin).
     *
     * @return array{timestamp:?string,signature:?string}
     */
    public static function fromRequest($request)
    {
        return [
            'timestamp' => self::firstNonEmpty([
                $request->getUserVar('HTTP_X_OJSBR_TIMESTAMP'),
                self::server($request, 'HTTP_X_OJSBR_TIMESTAMP'),
                self::server($request, 'X-OJSBR-Timestamp'),
            ]),
            'signature' => self::firstNonEmpty([
                $request->getUserVar('HTTP_X_OJSBR_SIGNATURE'),
                self::server($request, 'HTTP_X_OJSBR_SIGNATURE'),
                self::server($request, 'X-OJSBR-Signature'),
            ]),
        ];
    }

    /**
     * Lê timestamp e assinatura de headers HTTP (resposta do conector).
     *
     * @param array<string,string|string[]> $headers
     * @return array{timestamp:?string,signature:?string}
     */
    public static function fromHeaders(array $headers): array
    {
        return [
            'timestamp' => self::headerValue($headers, self::HEADER_TIMESTAMP),
            'signature' => self::headerValue($headers, self::HEADER_SIGNATURE),
        ];
    }

    /**
     * @param string[] $publicPems  PEMs ou material Ed25519 (32 bytes / base64 / hex)
     */
    public static function verify(?string $timestamp, ?string $signatureB64, string $body, array $publicPems): bool
    {
        if ($timestamp === null || $timestamp === '' || $signatureB64 === null || $signatureB64 === '') {
            return false;
        }
        if (!ctype_digit((string) $timestamp)) {
            return false;
        }
        if (abs(time() - (int) $timestamp) > self::SKEW_SECONDS) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $message = $timestamp . "\n" . hash('sha256', $body);

        foreach ($publicPems as $pem) {
            $publicKey = self::extractPublicKey((string) $pem);
            if ($publicKey === null) {
                continue;
            }
            try {
                if (sodium_crypto_sign_verify_detached($signature, $message, $publicKey)) {
                    return true;
                }
            } catch (Exception $e) {
                continue;
            }
        }

        return false;
    }

    /**
     * HMAC-SHA256(pluginToken, nonce) em base64 — prova de posse do token
     * (resposta de heartbeat/chave; sem Ed25519).
     */
    public static function hmacToken(string $pluginToken, string $nonce): string
    {
        return base64_encode(hash_hmac('sha256', $nonce, $pluginToken, true));
    }

    /**
     * Extrai 32 bytes de chave pública Ed25519 de PEM SPKI, base64, hex ou raw.
     */
    public static function extractPublicKey(string $material): ?string
    {
        $material = trim($material);
        if ($material === '' || strpos($material, 'PIN-PLACEHOLDER') !== false) {
            return null;
        }

        if (preg_match('/-----BEGIN PUBLIC KEY-----(.*)-----END PUBLIC KEY-----/s', $material, $m)) {
            $der = base64_decode(preg_replace('/\s+/', '', $m[1]) ?? '', true);
            if ($der === false || strlen($der) < SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return null;
            }
            return substr($der, -SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
        }

        $raw = base64_decode($material, true);
        if ($raw !== false && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $raw;
        }

        if (ctype_xdigit($material) && strlen($material) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES * 2) {
            $hex = hex2bin($material);
            return $hex === false ? null : $hex;
        }

        if (strlen($material) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return $material;
        }

        return null;
    }

    /**
     * @param array<string,string|string[]> $headers
     */
    public static function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) !== 0) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $value = trim((string) $value);
            return $value === '' ? null : $value;
        }
        return null;
    }

    private static function server($request, $key)
    {
        $value = $request->getServerVar($key);
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * @param array<int,mixed> $candidates
     */
    private static function firstNonEmpty(array $candidates): ?string
    {
        foreach ($candidates as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }
        return null;
    }
}
