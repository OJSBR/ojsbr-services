<?php

/**
 * @file plugins/generic/ojsbrServices/OjsbrServicesPlugin.php
 *
 * Copyright (c) 2026 OJSBR
 *
 * @brief GenericPlugin OJS 3.4 — page `ojsbr`, settings, pin e rotação de pública.
 */

namespace APP\plugins\generic\ojsbrServices;

use APP\core\Application;
use APP\core\Request;
use APP\plugins\generic\ojsbrServices\classes\OjsbrHttp;
use APP\plugins\generic\ojsbrServices\classes\OjsbrSignature;
use APP\plugins\generic\ojsbrServices\pages\OjsbrEditorHandler;
use APP\plugins\generic\ojsbrServices\pages\OjsbrServiceHandler;
use APP\template\TemplateManager;
use PKP\core\JSONMessage;
use PKP\core\PKPApplication;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\RedirectAction;
use PKP\notification\NotificationManager;
use PKP\notification\PKPNotification;
use PKP\plugins\GenericPlugin;

class OjsbrServicesPlugin extends GenericPlugin
{
    public const PAGE_NAME = 'ojsbr';
    public const SETTING_CONNECTOR_URL = 'ojsbrServices.connectorUrl';
    public const SETTING_TOKEN = 'ojsbrServices.token';
    public const SETTING_PUBLICA = 'ojsbrServices.trustedPublica';
    public const SETTING_VERSAO = 'ojsbrServices.chavePublicaVersao';
    public const SETTING_DT_FIM = 'ojsbrServices.chavePublicaDtFim';
    public const SETTING_PUBLICA_ANT = 'ojsbrServices.trustedPublicaAnterior';
    public const SETTING_VERSAO_ANT = 'ojsbrServices.chavePublicaVersaoAnterior';
    public const SETTING_DT_FIM_ANT = 'ojsbrServices.chavePublicaDtFimAnterior';
    public const SETTING_OS_REFS = 'ojsbrServices.osPorSubmission';

    public const SERVICE_OPS = ['heartbeat', 'callback', 'chave'];
    public const EDITOR_OPS = ['index', 'criar', 'status', 'poll', 'os'];

    public function register($category, $path, $mainContextId = null): bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            HookRegistry::register('LoadHandler', [$this, 'callbackLoadHandler']);
        }
        return $success;
    }

    /**
     * lazy-load: array vazio = todas as ops quando o plugin está enabled
     * (LoadHandler precisa estar registrado no pedido da page `ojsbr`).
     */
    public function registerOn(): array
    {
        return [];
    }

    public function getDisplayName(): string
    {
        return __('plugins.generic.ojsbrServices.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.generic.ojsbrServices.description');
    }

    public function getContextSpecificPluginSettingsFile(): string
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    /**
     * @param Request $request
     * @param array $actionArgs
     */
    public function getActions($request, $actionArgs): array
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }

        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, [
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                ]),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));

        $context = $request->getContext();
        if ($context) {
            $editorUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_PAGE,
                $context->getPath(),
                self::PAGE_NAME,
                'index'
            );
            array_unshift($actions, new LinkAction(
                'editor',
                new RedirectAction($editorUrl),
                __('plugins.generic.ojsbrServices.editor'),
                null
            ));
        }

        return $actions;
    }

    /**
     * @param array $args
     * @param Request $request
     */
    public function manage($args, $request): JSONMessage
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }

        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : PKPApplication::CONTEXT_ID_NONE;

        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false);
            }
            $this->updateSetting(
                $contextId,
                self::SETTING_CONNECTOR_URL,
                rtrim(trim((string) $request->getUserVar('connectorUrl')), '/')
            );
            $this->updateSetting(
                $contextId,
                self::SETTING_TOKEN,
                trim((string) $request->getUserVar('token'))
            );
            $user = $request->getUser();
            if ($user) {
                $notificationMgr = new NotificationManager();
                $notificationMgr->createTrivialNotification(
                    $user->getId(),
                    PKPNotification::NOTIFICATION_TYPE_SUCCESS,
                    ['contents' => __('plugins.generic.ojsbrServices.settings.saved')]
                );
            }
            return new JSONMessage(true);
        }

        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->getName(),
            'connectorUrl' => (string) $this->getSetting($contextId, self::SETTING_CONNECTOR_URL),
            'token' => (string) $this->getSetting($contextId, self::SETTING_TOKEN),
            'formAction' => $request->getRouter()->url($request, null, null, 'manage', null, [
                'verb' => 'settings',
                'plugin' => $this->getName(),
                'category' => 'generic',
            ]),
        ]);

        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings.tpl')));
    }

    /**
     * Page `ojsbr` — getName() do plugin NÃO entra na URL.
     *
     * @param array $args [page, op, handlerFile, &handler]
     */
    public function callbackLoadHandler(string $hookName, array $args): bool
    {
        $page = $args[0] ?? '';
        $op = $args[1] ?? 'index';
        if ($page !== self::PAGE_NAME) {
            return false;
        }

        $handler = &$args[3];
        if (in_array($op, self::SERVICE_OPS, true)) {
            $handler = new OjsbrServiceHandler($this);
            return true;
        }

        $handler = new OjsbrEditorHandler($this);
        return true;
    }

    public function getConnectorUrl(int $contextId): string
    {
        return rtrim((string) $this->getSetting($contextId, self::SETTING_CONNECTOR_URL), '/');
    }

    public function getPluginToken(int $contextId): string
    {
        return (string) $this->getSetting($contextId, self::SETTING_TOKEN);
    }

    public function getPinnedPublicKey(): string
    {
        foreach (['/keys/ojsbr.pub.local', '/keys/ojsbr.pub'] as $rel) {
            $path = $this->getPluginPath() . $rel;
            if (!is_readable($path)) {
                continue;
            }
            $pem = (string) file_get_contents($path);
            if ($pem !== '' && !str_contains($pem, 'PIN-PLACEHOLDER')) {
                return $pem;
            }
        }
        return '';
    }

    /**
     * Públicas confiáveis: vigente persistida (ou pin) + anterior até dtFim.
     *
     * @return string[]
     */
    public function getTrustedPublicPems(int $contextId): array
    {
        $current = (string) $this->getSetting($contextId, self::SETTING_PUBLICA);
        if ($current === '') {
            $current = $this->getPinnedPublicKey();
        }

        $pems = [$current];
        $anterior = (string) $this->getSetting($contextId, self::SETTING_PUBLICA_ANT);
        if ($anterior !== '') {
            $dtFim = (string) $this->getSetting($contextId, self::SETTING_DT_FIM_ANT);
            if ($dtFim === '' || strtotime($dtFim) >= time()) {
                $pems[] = $anterior;
            }
        }

        return array_values(array_filter($pems, static fn ($pem) => $pem !== ''));
    }

    public function getChavePublicaVersao(int $contextId): string
    {
        $versao = (string) $this->getSetting($contextId, self::SETTING_VERSAO);
        return $versao !== '' ? $versao : 'pin';
    }

    /**
     * Persiste a pública distribuída pelo painel (não aparece na UI).
     */
    public function persistPublica(int $contextId, string $versao, string $publica, ?string $dtFim): void
    {
        $atual = (string) $this->getSetting($contextId, self::SETTING_PUBLICA);
        if ($atual === '') {
            $atual = $this->getPinnedPublicKey();
        }
        if ($atual !== '') {
            $this->updateSetting($contextId, self::SETTING_PUBLICA_ANT, $atual);
            $this->updateSetting($contextId, self::SETTING_VERSAO_ANT, $this->getChavePublicaVersao($contextId));
            $this->updateSetting(
                $contextId,
                self::SETTING_DT_FIM_ANT,
                (string) $this->getSetting($contextId, self::SETTING_DT_FIM)
            );
        }

        $this->updateSetting($contextId, self::SETTING_PUBLICA, $publica);
        $this->updateSetting($contextId, self::SETTING_VERSAO, $versao);
        $this->updateSetting($contextId, self::SETTING_DT_FIM, (string) $dtFim);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function getOsRefs(int $contextId): array
    {
        $refs = $this->getSetting($contextId, self::SETTING_OS_REFS);
        return is_array($refs) ? $refs : [];
    }

    /**
     * @param array<string,mixed> $ref
     */
    public function persistOsRef(int $contextId, string $submissionId, array $ref): void
    {
        $refs = $this->getOsRefs($contextId);
        $refs[$submissionId] = array_merge($refs[$submissionId] ?? [], $ref, [
            'submissionId' => $submissionId,
            'dtalt' => date('c'),
        ]);
        $this->updateSetting($contextId, self::SETTING_OS_REFS, $refs);
    }

    /**
     * Verifica assinatura Ed25519 de um pedido ou resposta STNT.
     */
    public function verifySignedBody(int $contextId, ?string $timestamp, ?string $signature, string $body): bool
    {
        return OjsbrSignature::verify($timestamp, $signature, $body, $this->getTrustedPublicPems($contextId));
    }

    /**
     * @param list<array<string,mixed>>|null $files
     * @return array{status:int,body:string,headers:array<string,string>,json:?array,signed:bool}
     */
    public function callConnector(int $contextId, string $method, string $path, ?array $json = null, ?array $files = null): array
    {
        $base = $this->getConnectorUrl($contextId);
        $token = $this->getPluginToken($contextId);
        $url = $base . $path;

        if ($method === 'GET') {
            $response = OjsbrHttp::get($url, $token);
        } elseif ($files !== null) {
            $response = OjsbrHttp::postFiles($url, $token, $files);
        } else {
            $response = OjsbrHttp::postJson($url, $json ?? [], $token);
        }

        $sig = OjsbrSignature::fromHeaders($response['headers']);
        $signed = $this->verifySignedBody($contextId, $sig['timestamp'], $sig['signature'], $response['body']);
        $decoded = json_decode($response['body'], true);

        return [
            'status' => $response['status'],
            'body' => $response['body'],
            'headers' => $response['headers'],
            'json' => is_array($decoded) ? $decoded : null,
            'signed' => $signed,
        ];
    }
}

if (!PKP_STRICT_MODE) {
    class_alias(\APP\plugins\generic\ojsbrServices\OjsbrServicesPlugin::class, 'OjsbrServicesPlugin');
}
