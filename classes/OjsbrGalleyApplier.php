<?php

/**
 * @file plugins/generic/ojsbrServices/classes/OjsbrGalleyApplier.php
 *
 * Copyright (c) 2026 OJSBR
 *
 * @brief Aplica XML/galley de resultado na publication corrente (OJS 3.5).
 */

namespace APP\plugins\generic\ojsbrServices\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use PKP\galley\Galley;
use PKP\submissionFile\SubmissionFile;

class OjsbrGalleyApplier
{
    /**
     * @param list<array<string,mixed>> $artefatos
     */
    public static function apply(int $contextId, string $submissionId, array $artefatos): array
    {
        $applied = [];
        $submission = Repo::submission()->get((int) $submissionId);
        if (!$submission instanceof Submission) {
            return $applied;
        }
        $publication = $submission->getCurrentPublication();
        if (!$publication instanceof Publication) {
            return $applied;
        }

        foreach ($artefatos as $art) {
            $role = strtolower((string) ($art['role'] ?? 'xml'));
            $b64 = (string) ($art['contentBase64'] ?? '');
            if ($b64 === '') {
                continue;
            }
            $bin = base64_decode($b64, true);
            if ($bin === false) {
                continue;
            }
            $fileName = self::safeName((string) ($art['fileName'] ?? ($role . '.xml')));
            $tmp = tempnam(sys_get_temp_dir(), 'ojsbr');
            if ($tmp === false) {
                continue;
            }
            file_put_contents($tmp, $bin);
            try {
                $galleyId = self::criarGalley($submission, $publication, $tmp, $fileName, $role, (string) ($art['locale'] ?? ''));
                if ($galleyId) {
                    $applied[] = ['role' => $role, 'fileName' => $fileName, 'galleyId' => $galleyId];
                }
            } finally {
                @unlink($tmp);
            }
        }
        return $applied;
    }

    private static function criarGalley(
        Submission $submission,
        Publication $publication,
        string $tmpPath,
        string $fileName,
        string $role,
        string $locale
    ): ?int {
        $locale = $locale !== '' ? $locale : (string) ($publication->getData('locale') ?: $submission->getData('locale'));
        $label = strtoupper($role) === 'XML' ? 'XML' : strtoupper($role);

        $existing = Repo::galley()->getCollector()
            ->filterByPublicationIds([$publication->getId()])
            ->getMany();
        foreach ($existing as $g) {
            if (strcasecmp((string) $g->getData('label'), $label) === 0) {
                $label .= ' OJSBR';
                break;
            }
        }

        $galley = Repo::galley()->newDataObject();
        $galley->setData('publicationId', $publication->getId());
        $galley->setData('label', $label);
        $galley->setData('locale', $locale);
        $galleyId = (int) Repo::galley()->add($galley);
        if (!$galleyId) {
            return null;
        }

        $genreId = self::genreId((int) $submission->getData('contextId'), $role);
        $userId = (int) ($submission->getData('userId') ?: 0);
        $submissionFile = Repo::submissionFile()->newDataObject();
        $submissionFile->setData('submissionId', $submission->getId());
        $submissionFile->setData('fileStage', SubmissionFile::SUBMISSION_FILE_PROOF);
        $submissionFile->setData('name', [$locale => $fileName]);
        $submissionFile->setData('genreId', $genreId);
        $submissionFile->setData('assocType', Application::ASSOC_TYPE_REPRESENTATION);
        $submissionFile->setData('assocId', $galleyId);
        if ($userId) {
            $submissionFile->setData('uploaderUserId', $userId);
        }
        $fileId = Repo::submissionFile()->add($submissionFile, $tmpPath);
        if ($fileId) {
            /** @var Galley $saved */
            $saved = Repo::galley()->get($galleyId);
            if ($saved) {
                Repo::galley()->edit($saved, ['submissionFileId' => $fileId]);
            }
        }
        return $galleyId;
    }

    private static function genreId(int $contextId, string $role): ?int
    {
        $genres = Repo::genre()->getCollector()->filterByContextIds([$contextId])->getMany();
        $prefer = $role === 'xml' ? ['xml', 'jats'] : [$role];
        foreach ($genres as $genre) {
            $key = strtolower((string) ($genre->getData('key') ?: ''));
            foreach ($prefer as $p) {
                if (str_contains($key, $p)) {
                    return (int) $genre->getId();
                }
            }
        }
        foreach ($genres as $genre) {
            return (int) $genre->getId();
        }
        return null;
    }

    private static function safeName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?: 'arquivo.xml';
        return $base;
    }
}
