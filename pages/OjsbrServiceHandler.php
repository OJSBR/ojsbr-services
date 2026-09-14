<?php

/**
 * @file plugins/generic/ojsbrServices/pages/OjsbrServiceHandler.php
 *
 * Copyright (c) 2026 OJSBR
 *
 * @brief Ops públicas heartbeat|callback|chave. Auth = Ed25519, sem login/CSRF.
 */

namespace APP\plugins\generic\ojsbrServices\pages;

use APP\core\Request;
use APP\handler\Handler;
use APP\plugins\generic\ojsbrServices\classes\OjsbrGalleyApplier;
use APP\plugins\generic\ojsbrServices\classes\OjsbrSignature;
use APP\plugins\generic\ojsbrServices\OjsbrServicesPlugin;

class OjsbrServiceHandler extends Handler
{
    public function __construct(protected OjsbrServicesPlugin $plugin)
    {
        parent::__construct();
    }

    /**
     * Público: o conector não manda cookie. Sem UserRequired / Role / CSRF.
     *
     * @param Request $request
     * @param array $args
     * @param array $roleAssignments
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        return true;
    }

    /**
     * POST {baseUrl}/index.php/{journalPath}/ojsbr/heartbeat
     * Pedido assinado. Resposta 200 SEM Ed25519.
     */
    public function heartbeat(array $args, Request $request): void
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);
        $nonce = (string) ($payload['nonce'] ?? '');
        if ($nonce === '') {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }

        $this->jsonOk([
            'ok' => true,
            'chavePublicaVersao' => $this->plugin->getChavePublicaVersao($contextId),
            'journalPath' => (string) $context->getPath(),
            'hmac' => OjsbrSignature::hmacToken($this->plugin->getPluginToken($contextId), $nonce),
        ]);
    }

    /**
     * POST …/ojsbr/callback — status + aplica XML/galley na publication corrente.
     */
    public function callback(array $args, Request $request): void
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);

        $submissionId = (string) ($payload['submissionId'] ?? $payload['item']['submissionId'] ?? '');
        $numero = (string) ($payload['numero'] ?? $payload['os'] ?? '');
        if ($submissionId === '' || $numero === '') {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }

        $applied = [];
        $artefatos = $payload['artefatos'] ?? [];
        if (is_array($artefatos) && $artefatos) {
            try {
                $applied = OjsbrGalleyApplier::apply($contextId, $submissionId, $artefatos);
            } catch (\Throwable $e) {
                error_log('OJSBR callback galley: ' . $e->getMessage());
            }
        }

        $this->plugin->persistOsRef($contextId, $submissionId, [
            'numero' => $numero,
            'publicationId' => $payload['publicationId'] ?? ($payload['item']['publicationId'] ?? null),
            'situacaoProducao' => $payload['situacaoProducao'] ?? null,
            'situacaoFinanceira' => $payload['situacaoFinanceira'] ?? null,
            'itemStatus' => $payload['itemStatus'] ?? ($payload['item']['status'] ?? null),
            'origem' => 'callback',
            'galleysAplicados' => $applied,
        ]);

        $this->jsonOk([
            'ok' => true,
            'submissionId' => $submissionId,
            'numero' => $numero,
            'galleys' => $applied,
        ]);
    }

    /**
     * POST …/ojsbr/chave — { versao, publica, dtFim }. Persiste pública, devolve { versao, hmac }.
     */
    public function chave(array $args, Request $request): void
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);

        $versao = (string) ($payload['versao'] ?? '');
        $publica = (string) ($payload['publica'] ?? '');
        $dtFim = isset($payload['dtFim']) ? (string) $payload['dtFim'] : null;
        if ($versao === '' || $publica === '' || OjsbrSignature::extractPublicKey($publica) === null) {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }

        $this->plugin->persistPublica($contextId, $versao, $publica, $dtFim);

        $nonce = (string) ($payload['nonce'] ?? $versao);
        $this->jsonOk([
            'versao' => $this->plugin->getChavePublicaVersao($contextId),
            'hmac' => OjsbrSignature::hmacToken($this->plugin->getPluginToken($contextId), $nonce),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function requireSignedJson(Request $request, int $contextId): array
    {
        $body = (string) file_get_contents('php://input');
        $headers = OjsbrSignature::fromRequest($request);
        if (!$this->plugin->verifySignedBody($contextId, $headers['timestamp'], $headers['signature'], $body)) {
            $this->jsonError(401, 'plugins.generic.ojsbrServices.error.unauthorized');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->jsonError(400, 'plugins.generic.ojsbrServices.error.badRequest');
        }
        return $decoded;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function jsonOk(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->halt();
    }

    private function jsonError(int $status, string $localeKey): void
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode([
            'ok' => false,
            'error' => __($localeKey),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->halt();
    }

    private function halt(): never
    {
        exit;
    }
}
