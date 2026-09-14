<?php

/**
 * @file plugins/generic/ojsbrServices/pages/OjsbrEditorHandler.php
 *
 * @brief UI Manager/Editor OJS 3.3.
 */

import('classes.handler.Handler');

class OjsbrEditorHandler extends Handler
{
    /** @var OjsbrServicesPlugin */
    public $plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
        $this->addRoleAssignment(
            array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR),
            OjsbrServicesPlugin::$EDITOR_OPS
        );
    }

    public function authorize($request, &$args, $roleAssignments)
    {
        import('lib.pkp.classes.security.authorization.UserRequiredPolicy');
        import('lib.pkp.classes.security.authorization.ContextAccessPolicy');
        import('lib.pkp.classes.security.authorization.CsrfPolicy');
        $this->addPolicy(new UserRequiredPolicy($request));
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        if ($request->isPost()) {
            $this->addPolicy(new CsrfPolicy($request));
        }
        return parent::authorize($request, $args, $roleAssignments);
    }

    public function index($args, $request)
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign(array(
            'pageTitle' => __('plugins.generic.ojsbrServices.editor.title'),
            'pluginPageUrl' => $this->pageUrl($request, 'index'),
            'criarUrl' => $this->pageUrl($request, 'criar'),
            'statusUrl' => $this->pageUrl($request, 'status'),
            'pollUrl' => $this->pageUrl($request, 'poll'),
            'osUrl' => $this->pageUrl($request, 'os'),
            'rows' => $this->listSubmissionRows($contextId),
            'osRefs' => $this->plugin->getOsRefs($contextId),
            'hasSettings' => $this->plugin->getConnectorUrl($contextId) !== '' && $this->plugin->getPluginToken($contextId) !== '',
            'flash' => (string) $request->getUserVar('flash'),
            'flashNumero' => (string) $request->getUserVar('numero'),
            'flashFaltante' => (string) $request->getUserVar('faltante'),
        ));
        $templateMgr->display($this->plugin->getTemplateResource('editor.tpl'));
    }

    public function criar($args, $request)
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $ids = $request->getUserVar('submissionIds');
        if (!is_array($ids) || !$ids) {
            $this->redirectFlash($request, 'missingItems');
        }
        if ($this->plugin->getConnectorUrl($contextId) === '' || $this->plugin->getPluginToken($contextId) === '') {
            $this->redirectFlash($request, 'missingSettings');
        }
        $payload = $this->buildCreatePayload($request, array_map('intval', $ids));
        $response = $this->plugin->callConnector($contextId, 'POST', '/plugin/v1/ordens', $payload['json']);
        if (empty($response['signed']) || !is_array($response['json'])) {
            $this->redirectFlash($request, 'signatureFailed');
        }
        $data = $response['json'];
        $numero = isset($data['numero']) ? (string) $data['numero'] : '';
        foreach ($payload['uploads'] as $sid => $files) {
            if ($files) {
                $this->plugin->callConnector(
                    $contextId,
                    'POST',
                    '/plugin/v1/ordens/' . rawurlencode($numero) . '/itens/' . rawurlencode((string) $sid) . '/arquivos',
                    null,
                    $files
                );
            }
            $fresh = $this->plugin->callConnector($contextId, 'GET', '/plugin/v1/ordens/' . rawurlencode($numero));
            if (!empty($fresh['signed']) && is_array($fresh['json'])) {
                $data = $fresh['json'];
            }
        }
        foreach ($payload['json']['items'] as $item) {
            $this->plugin->persistOsRef($contextId, (string) $item['submissionId'], array(
                'numero' => $numero,
                'situacaoProducao' => isset($data['situacaoProducao']) ? $data['situacaoProducao'] : null,
                'situacaoFinanceira' => isset($data['situacaoFinanceira']) ? $data['situacaoFinanceira'] : null,
                'origem' => 'criar',
                'creditoFaltante' => isset($data['creditoFaltante']) ? $data['creditoFaltante'] : null,
            ));
        }
        $this->redirectToOs($request, $numero, isset($data['creditoFaltante']) ? (string) $data['creditoFaltante'] : '');
    }

    public function os($args, $request)
    {
        $this->setupTemplate($request);
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $numero = trim((string) $request->getUserVar('numero'));
        if ($numero === '') {
            $this->redirectFlash($request, 'missingItems');
        }
        $response = $this->plugin->callConnector($contextId, 'GET', '/plugin/v1/ordens/' . rawurlencode($numero));
        if (empty($response['signed']) || !is_array($response['json'])) {
            $this->redirectFlash($request, 'signatureFailed');
        }
        $data = $response['json'];
        foreach (isset($data['itens']) ? $data['itens'] : array() as $item) {
            $sid = isset($item['submissionId']) ? (string) $item['submissionId'] : '';
            if ($sid === '') {
                continue;
            }
            $this->plugin->persistOsRef($contextId, $sid, array(
                'numero' => isset($data['numero']) ? $data['numero'] : $numero,
                'origem' => 'os',
                'situacaoProducao' => isset($data['situacaoProducao']) ? $data['situacaoProducao'] : null,
                'situacaoFinanceira' => isset($data['situacaoFinanceira']) ? $data['situacaoFinanceira'] : null,
                'itemStatus' => isset($item['status']) ? $item['status'] : null,
                'creditoFaltante' => isset($data['creditoFaltante']) ? $data['creditoFaltante'] : null,
            ));
        }
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign(array(
            'pageTitle' => __('plugins.generic.ojsbrServices.editor.osTitle', array('numero' => isset($data['numero']) ? $data['numero'] : $numero)),
            'pluginPageUrl' => $this->pageUrl($request, 'index'),
            'pollUrl' => $this->pageUrl($request, 'poll'),
            'os' => $data,
            'numero' => isset($data['numero']) ? $data['numero'] : $numero,
            'flash' => (string) $request->getUserVar('flash'),
            'flashFaltante' => (string) $request->getUserVar('faltante'),
        ));
        $templateMgr->display($this->plugin->getTemplateResource('os.tpl'));
    }

    public function status($args, $request)
    {
        $this->refreshOs($request, true);
    }

    public function poll($args, $request)
    {
        $this->refreshOs($request, false);
    }

    private function refreshOs($request, $redirect)
    {
        $context = $request->getContext();
        $contextId = (int) $context->getId();
        $numero = trim((string) $request->getUserVar('numero'));
        if ($numero === '') {
            if ($redirect) {
                $this->redirectFlash($request, 'missingItems');
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(400);
            echo json_encode(array('ok' => false));
            exit;
        }
        $response = $this->plugin->callConnector($contextId, 'GET', '/plugin/v1/ordens/' . rawurlencode($numero));
        if (empty($response['signed']) || !is_array($response['json'])) {
            if ($redirect) {
                $this->redirectFlash($request, 'signatureFailed');
            }
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(401);
            echo json_encode(array('ok' => false));
            exit;
        }
        $data = $response['json'];
        foreach (isset($data['itens']) ? $data['itens'] : array() as $item) {
            $sid = isset($item['submissionId']) ? (string) $item['submissionId'] : '';
            if ($sid === '') {
                continue;
            }
            $this->plugin->persistOsRef($contextId, $sid, array(
                'numero' => isset($data['numero']) ? $data['numero'] : $numero,
                'origem' => $redirect ? 'status' : 'poll',
                'situacaoProducao' => isset($data['situacaoProducao']) ? $data['situacaoProducao'] : null,
                'situacaoFinanceira' => isset($data['situacaoFinanceira']) ? $data['situacaoFinanceira'] : null,
                'itemStatus' => isset($item['status']) ? $item['status'] : null,
                'creditoFaltante' => isset($data['creditoFaltante']) ? $data['creditoFaltante'] : null,
            ));
        }
        if ($redirect) {
            $this->redirectToOs($request, isset($data['numero']) ? $data['numero'] : $numero, isset($data['creditoFaltante']) ? (string) $data['creditoFaltante'] : '');
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array(
            'ok' => true,
            'numero' => isset($data['numero']) ? $data['numero'] : $numero,
            'situacaoProducao' => isset($data['situacaoProducao']) ? $data['situacaoProducao'] : null,
            'situacaoFinanceira' => isset($data['situacaoFinanceira']) ? $data['situacaoFinanceira'] : null,
            'creditoFaltante' => isset($data['creditoFaltante']) ? $data['creditoFaltante'] : 0,
            'itens' => isset($data['itens']) ? $data['itens'] : array(),
        ));
        exit;
    }

    private function listSubmissionRows($contextId)
    {
        $osRefs = $this->plugin->getOsRefs($contextId);
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $result = $submissionDao->getByContextId($contextId);
        $rows = array();
        while ($submission = $result->next()) {
            $publication = method_exists($submission, 'getCurrentPublication') ? $submission->getCurrentPublication() : null;
            $sid = (string) $submission->getId();
            $title = $publication ? $publication->getLocalizedTitle() : $submission->getLocalizedTitle();
            $doi = '';
            if ($publication && method_exists($publication, 'getStoredPubId')) {
                $doi = (string) $publication->getStoredPubId('doi');
            }
            $rows[] = array(
                'submissionId' => $sid,
                'publicationId' => $publication ? $publication->getId() : null,
                'title' => $title,
                'doi' => $doi,
                'os' => isset($osRefs[$sid]) ? $osRefs[$sid] : null,
            );
        }
        return $rows;
    }

    private function buildCreatePayload($request, $submissionIds)
    {
        $context = $request->getContext();
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $items = array();
        $uploads = array();
        foreach ($submissionIds as $submissionId) {
            $submission = $submissionDao->getById($submissionId, $context->getId());
            if (!$submission) {
                continue;
            }
            $publication = method_exists($submission, 'getCurrentPublication') ? $submission->getCurrentPublication() : null;
            $title = $publication ? $publication->getLocalizedTitle() : $submission->getLocalizedTitle();
            $doi = '';
            if ($publication && method_exists($publication, 'getStoredPubId')) {
                $doi = (string) $publication->getStoredPubId('doi');
            }
            $filesMeta = array(array('role' => 'pdf_final', 'fileName' => 'submission.pdf'));
            $items[] = array(
                'submissionId' => (string) $submission->getId(),
                'publicationId' => $publication ? (string) $publication->getId() : null,
                'title' => $title,
                'doi' => $doi,
                'locale' => $submission->getLocale(),
                'metadata' => array(),
                'galleys' => array(),
                'files' => $filesMeta,
            );
            $uploads[$submission->getId()] = $this->collectFiles($submission, $publication);
        }
        return array(
            'json' => array(
                'service' => 'OS_JATS_XML',
                'ojsVersion' => '3.3',
                'journalPath' => (string) $context->getPath(),
                'journal' => array(
                    'title' => (string) $context->getLocalizedName(),
                    'acronym' => '',
                    'issnPrint' => (string) $context->getData('printIssn'),
                    'issnOnline' => (string) $context->getData('onlineIssn'),
                    'publisher' => (string) $context->getData('publisherInstitution'),
                    'locales' => array_values($context->getSupportedLocales()),
                    'metadata' => array(),
                ),
                'items' => $items,
            ),
            'uploads' => $uploads,
        );
    }

    private function collectFiles($submission, $publication)
    {
        $uploads = array();
        $galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
        $publicationId = $publication ? $publication->getId() : null;
        if ($publicationId && $galleyDao) {
            $galleys = $galleyDao->getByPublicationId($publicationId);
            while ($galleys && ($galley = $galleys->next())) {
                $file = method_exists($galley, 'getFile') ? $galley->getFile() : null;
                if (!$file) {
                    continue;
                }
                $path = method_exists($file, 'getData') ? $file->getData('path') : null;
                $name = method_exists($file, 'getOriginalFileName') ? $file->getOriginalFileName() : 'galley';
                $bytes = $this->readPath($path);
                if ($bytes === null) {
                    continue;
                }
                $uploads[] = array(
                    'role' => 'galley',
                    'galleyId' => (string) $galley->getId(),
                    'fileName' => $name,
                    'contents' => $bytes,
                );
                if (preg_match('/\.pdf$/i', $name)) {
                    $uploads[] = array('role' => 'pdf_final', 'fileName' => $name, 'contents' => $bytes);
                }
            }
        }
        return $uploads;
    }

    private function readPath($path)
    {
        if (!$path || !is_readable($path)) {
            return null;
        }
        $bytes = file_get_contents($path);
        return $bytes === false ? null : $bytes;
    }

    private function pageUrl($request, $op)
    {
        return $request->getDispatcher()->url($request, ROUTE_PAGE, $request->getContext()->getPath(), 'ojsbr', $op);
    }

    private function redirectToOs($request, $numero, $faltante = '')
    {
        $url = $this->pageUrl($request, 'os');
        $qs = array('numero' => $numero, 'flash' => 'created', 'faltante' => $faltante);
        $request->redirectUrl($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($qs));
        exit;
    }

    private function redirectFlash($request, $flash, $params = array())
    {
        $url = $this->pageUrl($request, 'index');
        $qs = array_merge(array('flash' => $flash), $params);
        $request->redirectUrl($url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($qs));
        exit;
    }
}
