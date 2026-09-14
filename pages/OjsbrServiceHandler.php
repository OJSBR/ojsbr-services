<?php

/**
 * @file plugins/generic/ojsbrServices/pages/OjsbrServiceHandler.php
 *
 * @brief Ops públicas heartbeat|callback|chave (OJS 3.3).
 */

import('classes.handler.Handler');

class OjsbrServiceHandler extends Handler
{
    /** @var OjsbrServicesPlugin */
    public $plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        return true;
    }

    public function heartbeat($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400);
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);
        $nonce = isset($payload['nonce']) ? (string) $payload['nonce'] : '';
        if ($nonce === '') {
            $this->jsonError(400);
        }
        require_once($this->plugin->getPluginPath() . '/classes/OjsbrSignature.php');
        $this->jsonOk(array(
            'ok' => true,
            'chavePublicaVersao' => $this->plugin->getChavePublicaVersao($contextId),
            'journalPath' => (string) $context->getPath(),
            'hmac' => OjsbrSignature::hmacToken($this->plugin->getPluginToken($contextId), $nonce),
        ));
    }

    public function callback($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400);
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);
        $submissionId = isset($payload['submissionId']) ? (string) $payload['submissionId'] : '';
        if ($submissionId === '' && isset($payload['item']['submissionId'])) {
            $submissionId = (string) $payload['item']['submissionId'];
        }
        $numero = isset($payload['numero']) ? (string) $payload['numero'] : (isset($payload['os']) ? (string) $payload['os'] : '');
        if ($submissionId === '' || $numero === '') {
            $this->jsonError(400);
        }
        $applied = array();
        if (!empty($payload['artefatos']) && is_array($payload['artefatos'])) {
            require_once($this->plugin->getPluginPath() . '/classes/OjsbrGalleyApplier.php');
            try {
                $applied = OjsbrGalleyApplier::apply($contextId, $submissionId, $payload['artefatos']);
            } catch (Exception $e) {
                error_log('OJSBR callback galley: ' . $e->getMessage());
            }
        }
        $this->plugin->persistOsRef($contextId, $submissionId, array(
            'numero' => $numero,
            'publicationId' => isset($payload['publicationId']) ? $payload['publicationId'] : null,
            'situacaoProducao' => isset($payload['situacaoProducao']) ? $payload['situacaoProducao'] : null,
            'situacaoFinanceira' => isset($payload['situacaoFinanceira']) ? $payload['situacaoFinanceira'] : null,
            'itemStatus' => isset($payload['itemStatus']) ? $payload['itemStatus'] : null,
            'origem' => 'callback',
            'galleysAplicados' => $applied,
        ));
        $this->jsonOk(array('ok' => true, 'submissionId' => $submissionId, 'numero' => $numero, 'galleys' => $applied));
    }

    public function chave($args, $request)
    {
        $context = $request->getContext();
        if (!$context) {
            $this->jsonError(400);
        }
        $contextId = (int) $context->getId();
        $payload = $this->requireSignedJson($request, $contextId);
        $versao = isset($payload['versao']) ? (string) $payload['versao'] : '';
        $publica = isset($payload['publica']) ? (string) $payload['publica'] : '';
        $dtFim = isset($payload['dtFim']) ? (string) $payload['dtFim'] : null;
        require_once($this->plugin->getPluginPath() . '/classes/OjsbrSignature.php');
        if ($versao === '' || $publica === '' || OjsbrSignature::extractPublicKey($publica) === null) {
            $this->jsonError(400);
        }
        $this->plugin->persistPublica($contextId, $versao, $publica, $dtFim);
        $nonce = isset($payload['nonce']) ? (string) $payload['nonce'] : $versao;
        $this->jsonOk(array(
            'versao' => $this->plugin->getChavePublicaVersao($contextId),
            'hmac' => OjsbrSignature::hmacToken($this->plugin->getPluginToken($contextId), $nonce),
        ));
    }

    private function requireSignedJson($request, $contextId)
    {
        $body = (string) file_get_contents('php://input');
        require_once($this->plugin->getPluginPath() . '/classes/OjsbrSignature.php');
        $headers = OjsbrSignature::fromRequest($request);
        if (!$this->plugin->verifySignedBody($contextId, $headers['timestamp'], $headers['signature'], $body)) {
            $this->jsonError(401);
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            $this->jsonError(400);
        }
        return $decoded;
    }

    private function jsonOk($payload)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(200);
        echo json_encode($payload);
        exit;
    }

    private function jsonError($status)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode(array('ok' => false));
        exit;
    }
}
