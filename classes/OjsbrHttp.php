<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrHttp.php
 *
 * Copyright (c) 2026 OJSBR
 *
 * @brief Cliente HTTP do plugin → conector (Bearer token). Não assina Ed25519.
 */

namespace APP\plugins\generic\ojsbrServices\classes;

class OjsbrHttp
{
    public const TIMEOUT_SECONDS = 120;
    public const MAX_BODY_BYTES = 33554432; // 32 MiB

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function postJson(string $url, array $payload, string $token): array
    {
        return self::request('POST', $url, $token, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}', [
            'Content-Type: application/json',
        ]);
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function get(string $url, string $token): array
    {
        return self::request('GET', $url, $token, null, []);
    }

    /**
     * Multipart de um artigo. Cada parte: role (+ galleyId) + arquivo.
     *
     * @param array<int,array{role:string,fileName:string,contents:string,galleyId?:?string,locale?:?string}> $files
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function postFiles(string $url, string $token, array $files): array
    {
        $boundary = '----ojsbr' . bin2hex(random_bytes(16));
        $body = '';
        foreach ($files as $i => $file) {
            $role = (string) ($file['role'] ?? '');
            $fileName = (string) ($file['fileName'] ?? 'file');
            $contents = (string) ($file['contents'] ?? '');
            $body .= self::multipartField($boundary, 'role', $role);
            if (!empty($file['galleyId'])) {
                $body .= self::multipartField($boundary, 'galleyId', (string) $file['galleyId']);
            }
            if (!empty($file['locale'])) {
                $body .= self::multipartField($boundary, 'locale', (string) $file['locale']);
            }
            $safeName = str_replace(["\r", "\n", '"'], '', $fileName);
            $body .= "--{$boundary}\r\n";
            $body .= 'Content-Disposition: form-data; name="file"; filename="' . $safeName . '"' . "\r\n";
            $body .= "Content-Type: application/octet-stream\r\n\r\n";
            $body .= $contents . "\r\n";
            unset($files[$i]['contents']);
        }
        $body .= "--{$boundary}--\r\n";

        return self::request('POST', $url, $token, $body, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
        ]);
    }

    /**
     * @param string[] $extraHeaders
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private static function request(string $method, string $url, string $token, ?string $body, array $extraHeaders): array
    {
        $headers = array_merge([
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'X-OJSBR-Token: ' . $token,
        ], $extraHeaders);

        $headerBag = [];
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'headers' => []];
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::MAX_BODY_BYTES,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($raw === false) {
            return ['status' => $status ?: 0, 'body' => '', 'headers' => []];
        }

        $headerBlock = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        foreach (preg_split("/\r\n|\n|\r/", $headerBlock) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headerBag[trim($name)] = trim($value);
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $headerBag,
        ];
    }

    private static function multipartField(string $boundary, string $name, string $value): string
    {
        return "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
            . $value . "\r\n";
    }
}
