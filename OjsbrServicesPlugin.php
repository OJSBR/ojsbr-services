<?php

/**
 * @file plugins/generic/ojsbrServices/OjsbrServicesPlugin.php
 *
 * @brief GenericPlugin OJS 3.3 — page `ojsbr`.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class OjsbrServicesPlugin extends GenericPlugin
{
    const PAGE_NAME = 'ojsbr';
    const SETTING_CONNECTOR_URL = 'ojsbrServices.connectorUrl';
    const SETTING_TOKEN = 'ojsbrServices.token';
    const SETTING_PUBLICA = 'ojsbrServices.trustedPublica';
    const SETTING_VERSAO = 'ojsbrServices.chavePublicaVersao';
    const SETTING_DT_FIM = 'ojsbrServices.chavePublicaDtFim';
    const SETTING_PUBLICA_ANT = 'ojsbrServices.trustedPublicaAnterior';
    const SETTING_VERSAO_ANT = 'ojsbrServices.chavePublicaVersaoAnterior';
    const SETTING_DT_FIM_ANT = 'ojsbrServices.chavePublicaDtFimAnterior';
    const SETTING_OS_REFS = 'ojsbrServices.osPorSubmission';

    public static $SERVICE_OPS = array('heartbeat', 'callback', 'chave');
    public static $EDITOR_OPS = array('index', 'criar', 'status', 'poll', 'os');

    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled($mainContextId)) {
            HookRegistry::register('LoadHandler', array($this, 'callbackLoadHandler'));
        }
        return $success;
    }

    public function getName()
    {
        return 'ojsbrServices';
    }

    public function getDisplayName()
    {
        return __('plugins.generic.ojsbrServices.displayName');
    }

    public function getDescription()
    {
        return __('plugins.generic.ojsbrServices.description');
    }

    public function getContextSpecificPluginSettingsFile()
    {
        return $this->getPluginPath() . '/settings.xml';
    }

    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        import('lib.pkp.classes.linkAction.LinkAction');
        import('lib.pkp.classes.linkAction.request.AjaxModal');
        import('lib.pkp.classes.linkAction.request.RedirectAction');
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, array(
                    'verb' => 'settings',
                    'plugin' => $this->getName(),
                    'category' => 'generic',
                )),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        $context = $request->getContext();
        if ($context) {
            $editorUrl = $request->getDispatcher()->url(
                $request,
                ROUTE_PAGE,
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

    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') !== 'settings') {
            return parent::manage($args, $request);
        }
        $context = $request->getContext();
        $contextId = $context ? (int) $context->getId() : CONTEXT_ID_NONE;
        import('lib.pkp.classes.core.JSONMessage');
        if ($request->isPost()) {
            if (!$request->checkCSRF()) {
                return new JSONMessage(false);
            }
            $this->updateSetting($contextId, self::SETTING_CONNECTOR_URL, rtrim(trim((string) $request->getUserVar('connectorUrl')), '/'));
            $this->updateSetting($contextId, self::SETTING_TOKEN, trim((string) $request->getUserVar('token')));
            return new JSONMessage(true);
        }
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign(array(
            'pluginName' => $this->getName(),
            'connectorUrl' => (string) $this->getSetting($contextId, self::SETTING_CONNECTOR_URL),
            'token' => (string) $this->getSetting($contextId, self::SETTING_TOKEN),
            'formAction' => $request->getRouter()->url($request, null, null, 'manage', null, array(
                'verb' => 'settings',
                'plugin' => $this->getName(),
                'category' => 'generic',
            )),
        ));
        return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settings.tpl')));
    }

    public function callbackLoadHandler($hookName, $args)
    {
        $page = isset($args[0]) ? $args[0] : '';
        $op = isset($args[1]) ? $args[1] : 'index';
        if ($page !== self::PAGE_NAME) {
            return false;
        }
        $handler = &$args[3];
        require_once($this->getPluginPath() . '/pages/OjsbrServiceHandler.php');
        require_once($this->getPluginPath() . '/pages/OjsbrEditorHandler.php');
        if (in_array($op, self::$SERVICE_OPS, true)) {
            $handler = new OjsbrServiceHandler($this);
            return true;
        }
        $handler = new OjsbrEditorHandler($this);
        return true;
    }

    public function getConnectorUrl($contextId)
    {
        return rtrim((string) $this->getSetting($contextId, self::SETTING_CONNECTOR_URL), '/');
    }

    public function getPluginToken($contextId)
    {
        return (string) $this->getSetting($contextId, self::SETTING_TOKEN);
    }

    public function getPinnedPublicKey()
    {
        foreach (array('/keys/ojsbr.pub.local', '/keys/ojsbr.pub') as $rel) {
            $path = $this->getPluginPath() . $rel;
            if (!is_readable($path)) {
                continue;
            }
            $pem = (string) file_get_contents($path);
            if ($pem !== '' && strpos($pem, 'PIN-PLACEHOLDER') === false) {
                return $pem;
            }
        }
        return '';
    }

    public function getTrustedPublicPems($contextId)
    {
        $current = (string) $this->getSetting($contextId, self::SETTING_PUBLICA);
        if ($current === '') {
            $current = $this->getPinnedPublicKey();
        }
        $pems = array($current);
        $anterior = (string) $this->getSetting($contextId, self::SETTING_PUBLICA_ANT);
        if ($anterior !== '') {
            $dtFim = (string) $this->getSetting($contextId, self::SETTING_DT_FIM_ANT);
            if ($dtFim === '' || strtotime($dtFim) >= time()) {
                $pems[] = $anterior;
            }
        }
        return array_values(array_filter($pems));
    }

    public function getChavePublicaVersao($contextId)
    {
        $versao = (string) $this->getSetting($contextId, self::SETTING_VERSAO);
        return $versao !== '' ? $versao : 'pin';
    }

    public function persistPublica($contextId, $versao, $publica, $dtFim)
    {
        $atual = (string) $this->getSetting($contextId, self::SETTING_PUBLICA);
        if ($atual === '') {
            $atual = $this->getPinnedPublicKey();
        }
        if ($atual !== '') {
            $this->updateSetting($contextId, self::SETTING_PUBLICA_ANT, $atual);
            $this->updateSetting($contextId, self::SETTING_VERSAO_ANT, $this->getChavePublicaVersao($contextId));
            $this->updateSetting($contextId, self::SETTING_DT_FIM_ANT, (string) $this->getSetting($contextId, self::SETTING_DT_FIM));
        }
        $this->updateSetting($contextId, self::SETTING_PUBLICA, $publica);
        $this->updateSetting($contextId, self::SETTING_VERSAO, $versao);
        $this->updateSetting($contextId, self::SETTING_DT_FIM, (string) $dtFim);
    }

    public function getOsRefs($contextId)
    {
        $refs = $this->getSetting($contextId, self::SETTING_OS_REFS);
        return is_array($refs) ? $refs : array();
    }

    public function persistOsRef($contextId, $submissionId, $ref)
    {
        $refs = $this->getOsRefs($contextId);
        $refs[$submissionId] = array_merge(isset($refs[$submissionId]) ? $refs[$submissionId] : array(), $ref, array(
            'submissionId' => $submissionId,
            'dtalt' => date('c'),
        ));
        $this->updateSetting($contextId, self::SETTING_OS_REFS, $refs);
    }

    public function verifySignedBody($contextId, $timestamp, $signature, $body)
    {
        require_once($this->getPluginPath() . '/classes/OjsbrSignature.php');
        return OjsbrSignature::verify($timestamp, $signature, $body, $this->getTrustedPublicPems($contextId));
    }

    public function callConnector($contextId, $method, $path, $json = null, $files = null)
    {
        require_once($this->getPluginPath() . '/classes/OjsbrHttp.php');
        require_once($this->getPluginPath() . '/classes/OjsbrSignature.php');
        $url = $this->getConnectorUrl($contextId) . $path;
        $token = $this->getPluginToken($contextId);
        if ($method === 'GET') {
            $response = OjsbrHttp::get($url, $token);
        } elseif ($files !== null) {
            $response = OjsbrHttp::postFiles($url, $token, $files);
        } else {
            $response = OjsbrHttp::postJson($url, $json ? $json : array(), $token);
        }
        $sig = OjsbrSignature::fromHeaders($response['headers']);
        $signed = $this->verifySignedBody($contextId, $sig['timestamp'], $sig['signature'], $response['body']);
        $decoded = json_decode($response['body'], true);
        return array(
            'status' => $response['status'],
            'body' => $response['body'],
            'headers' => $response['headers'],
            'json' => is_array($decoded) ? $decoded : null,
            'signed' => $signed,
        );
    }
}
