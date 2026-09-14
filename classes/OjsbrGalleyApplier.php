<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrGalleyApplier.php
 *
 * @brief Aplica XML/galley na publication corrente (OJS 3.3 DAO + arquivo no storage).
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
        $publicationId = $publication ? $publication->getId() : (method_exists($submission, 'getCurrentPublicationId') ? $submission->getCurrentPublicationId() : null);
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
            $fileName = isset($art['fileName']) ? basename(str_replace('\\', '/', (string) $art['fileName'])) : ($role . '.xml');
            $fileName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fileName) ?: ($role . '.xml');
            $tmp = tempnam(sys_get_temp_dir(), 'ojsbr');
            if ($tmp === false) {
                continue;
            }
            file_put_contents($tmp, $bin);
            try {
                import('classes.article.ArticleGalley');
                $galley = $galleyDao->newDataObject();
                $galley->setData('publicationId', $publicationId);
                $label = strtoupper($role) === 'XML' ? 'XML' : strtoupper($role);
                $galley->setLabel($label);
                $locale = $publication ? (string) $publication->getData('locale') : (string) $submission->getLocale();
                if (isset($art['locale']) && $art['locale']) {
                    $locale = (string) $art['locale'];
                }
                $galley->setLocale($locale);
                $galleyId = $galleyDao->insertObject($galley);
                if (!$galleyId) {
                    continue;
                }
                $fileId = self::anexarArquivo($submission, $galleyId, $tmp, $fileName, $locale, $role);
                if ($fileId) {
                    $saved = $galleyDao->getById($galleyId, $publicationId);
                    if ($saved) {
                        if (method_exists($saved, 'setFileId')) {
                            $saved->setFileId($fileId);
                        }
                        if (method_exists($saved, 'setData')) {
                            $saved->setData('submissionFileId', $fileId);
                        }
                        $galleyDao->updateObject($saved);
                    }
                    $applied[] = array('role' => $role, 'fileName' => $fileName, 'galleyId' => $galleyId, 'fileId' => $fileId);
                } else {
                    $applied[] = array('role' => $role, 'fileName' => $fileName, 'galleyId' => $galleyId);
                }
            } finally {
                @unlink($tmp);
            }
        }
        return $applied;
    }

    /**
     * Grava o binário no storage PKP e devolve o id do submission file.
     *
     * @return int|null
     */
    private static function anexarArquivo($submission, $galleyId, $tmpPath, $fileName, $locale, $role)
    {
        import('lib.pkp.classes.submission.SubmissionFile');
        $genreId = self::genreId((int) $submission->getData('contextId'), $role);
        $userId = (int) $submission->getData('userId');
        $fileStage = 10;
        if (class_exists('SubmissionFile')) {
            $fileStage = SubmissionFile::SUBMISSION_FILE_PROOF;
        }
        $assocType = defined('ASSOC_TYPE_REPRESENTATION') ? ASSOC_TYPE_REPRESENTATION : 0x0000211;

        $submissionFileDao = DAORegistry::getDAO('SubmissionFileDAO');
        $submissionFile = $submissionFileDao->newDataObject();
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('fileStage', $fileStage);
        $submissionFile->setData('name', array($locale => $fileName));
        $submissionFile->setData('genreId', $genreId);
        $submissionFile->setData('assocType', $assocType);
        $submissionFile->setData('assocId', $galleyId);
        if ($userId) {
            $submissionFile->setData('uploaderUserId', $userId);
        }

        if (class_exists('Services')) {
            try {
                $service = Services::get('submissionFile');
                if ($service && method_exists($service, 'add')) {
                    $added = $service->add($submissionFile, array(
                        'file' => array(
                            'tmp_name' => $tmpPath,
                            'name' => $fileName,
                        ),
                    ));
                    if ($added && method_exists($added, 'getId')) {
                        return (int) $added->getId();
                    }
                }
            } catch (Exception $e) {
                error_log('OJSBR 3.3 galley Services: ' . $e->getMessage());
            }
        }

        try {
            $inserted = $submissionFileDao->insertObject($submissionFile, $tmpPath);
            if (is_object($inserted) && method_exists($inserted, 'getId')) {
                return (int) $inserted->getId();
            }
            if (is_object($inserted) && method_exists($inserted, 'getFileId')) {
                return (int) $inserted->getFileId();
            }
            if (is_numeric($inserted)) {
                return (int) $inserted;
            }
        } catch (Exception $e) {
            error_log('OJSBR 3.3 galley DAO: ' . $e->getMessage());
        }
        return null;
    }

    private static function genreId($contextId, $role)
    {
        $genreDao = DAORegistry::getDAO('GenreDAO');
        if (!$genreDao) {
            return null;
        }
        $prefer = $role === 'xml' ? array('JATSXML', 'XML', 'STYLE') : array(strtoupper($role), 'MULTIMEDIA');
        foreach ($prefer as $key) {
            $genre = $genreDao->getByKey($key, $contextId);
            if ($genre) {
                return (int) $genre->getId();
            }
        }
        $genres = $genreDao->getByContextId($contextId);
        if ($genres && method_exists($genres, 'next')) {
            $first = $genres->next();
            return $first ? (int) $first->getId() : null;
        }
        return null;
    }
}
