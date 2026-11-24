<?php

declare(strict_types=1);

namespace Itk\Cms\Health;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What GET /healthz asks: does the database answer, and can a file be written to the folder of uploads. Yes or no, nothing about why (the address is open to everyone).
 *
 * @internal
 */
final class LivenessProbe
{
    public function __construct(
        private readonly Connection $db,
        #[Autowire('%itk_cms.upload_dir%')]
        private readonly string $uploadDir,
    ) {
    }

    public function isAlive(): bool
    {
        return $this->databaseAnswers() && $this->uploadsAreWritable();
    }

    private function databaseAnswers(): bool
    {
        try {
            $this->db->executeQuery('SELECT 1')->fetchOne();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** A file is really written and removed; a folder that is not made yet is fine while the nearest folder above it takes files (it is made by the first upload). */
    private function uploadsAreWritable(): bool
    {
        return UploadsWritable::check($this->uploadDir);
    }
}
