<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrGalleyApplier.php
 *
 * @brief Aplica XML/galley na publication corrente (OJS 3.3 DAO).
 */

class OjsbrGalleyApplier
{
    public static function apply($contextId, $submissionId, $artefatos)
    {
        $applied = array();
        $submissionDao = DAORegistry::getDAO('SubmissionDAO');
        $submission = $submissionDao->getById((int) $submissionId, (int) $contextId);
        if (!$submission) {
            return $applied;
        }
        $publication = method_exists($submission, 'getCurrentPublication') ? $submission->getCurrentPublication() : null;
        $publicationId = $publication ? $publication->getId() : $submission->getCurrentPublicationId();
        if (!$publicationId) {
            return $applied;
        }
        $galleyDao = DAORegistry::getDAO('ArticleGalleyDAO');
        foreach ($artefatos as $art) {
            $b64 = isset($art['contentBase64']) ? (string) $art['contentBase64'] : '';
            if ($b64 === '') {
                continue;
            }
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                continue;
            }
            $role = isset($art['role']) ? strtolower((string) $art['role']) : 'xml';
            $fileName = isset($art['fileName']) ? basename((string) $art['fileName']) : ($role . '.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'ojsbr');
            if ($tmp === false) {
                continue;
            }
            file_put_contents($tmp, $bin);
            try {
                import('classes.article.ArticleGalley');
                $galley = $galleyDao->newDataObject();
                $galley->setData('publicationId', $publicationId);
                $galley->setLabel(strtoupper($role) === 'XML' ? 'XML' : strtoupper($role));
                $galley->setLocale($publication ? $publication->getData('locale') : $submission->getLocale());
                $galleyId = $galleyDao->insertObject($galley);
                if ($galleyId) {
                    $applied[] = array('role' => $role, 'fileName' => $fileName, 'galleyId' => $galleyId);
                }
            } finally {
                @unlink($tmp);
            }
        }
        return $applied;
    }
}
