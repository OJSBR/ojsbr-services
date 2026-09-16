<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrHttp.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @brief The plugin's HTTP client to the connector (Bearer token). It never
 *        signs anything with the OJSBR Ed25519 private key: that key does not
 *        exist here.
 *
 *        Requests go through Application::getHttpClient(), the client the
 *        application configures (proxy, user agent, TLS), never through curl
 *        handles of our own.
 */

namespace APP\plugins\generic\ojsbrServices\classes;

use APP\core\Application;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class OjsbrHttp
{
    public const TIMEOUT_SECONDS = 120;
    public const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function postJson(string $url, array $payload, string $token): array
    {
        return self::send('POST', $url, $token, [
            'json' => $payload,
        ]);
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function get(string $url, string $token): array
    {
        return self::send('GET', $url, $token, []);
    }

    /**
     * The files of one article, as a multipart request: each part carries its
     * role (and galley, and locale) next to the file itself.
     *
     * @param array<int,array{role:string,fileName:string,contents:string,galleyId?:?string,locale?:?string}> $files
     *
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    public static function postFiles(string $url, string $token, array $files): array
    {
        $multipart = [];
        foreach ($files as $file) {
            $multipart[] = ['name' => 'role', 'contents' => (string) ($file['role'] ?? '')];
            if (!empty($file['galleyId'])) {
                $multipart[] = ['name' => 'galleyId', 'contents' => (string) $file['galleyId']];
            }
            if (!empty($file['locale'])) {
                $multipart[] = ['name' => 'locale', 'contents' => (string) $file['locale']];
            }
            $multipart[] = [
                'name' => 'file',
                'contents' => (string) ($file['contents'] ?? ''),
                // A line break or a quote in the name would break the part header.
                'filename' => str_replace(["\r", "\n", '"'], '', (string) ($file['fileName'] ?? 'file')),
                'headers' => ['Content-Type' => 'application/octet-stream'],
            ];
        }

        return self::send('POST', $url, $token, ['multipart' => $multipart]);
    }

    /**
     * @param array<string,mixed> $options Body options of the request (json, multipart)
     *
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private static function send(string $method, string $url, string $token, array $options): array
    {
        $options = array_merge($options, [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'X-OJSBR-Token' => $token,
            ],
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            // The answer is read as it comes: a redirect or an error status is
            // for the caller to deal with, not for the client to follow or throw.
            'allow_redirects' => false,
            'http_errors' => false,
        ]);

        try {
            $response = Application::get()->getHttpClient()->request($method, $url, $options);
        } catch (GuzzleException $exception) {
            error_log('OJSBR Services: ' . $method . ' ' . $url . ' failed: ' . $exception->getMessage());
            return ['status' => 0, 'body' => '', 'headers' => []];
        }

        return self::result($response);
    }

    /**
     * @return array{status:int,body:string,headers:array<string,string>}
     */
    private static function result(ResponseInterface $response): array
    {
        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
            'headers' => $headers,
        ];
    }
}
