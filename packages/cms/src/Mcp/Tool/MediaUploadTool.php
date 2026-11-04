<?php

declare(strict_types=1);

namespace Itk\Cms\Mcp\Tool;

use Itk\Cms\Mcp\AbstractMcpTool;
use Itk\Cms\Mcp\McpContext;
use Itk\Cms\Mcp\McpScopes;
use Itk\Cms\Mcp\McpToolException;
use Itk\Cms\Media\MediaService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** @internal */
final class MediaUploadTool extends AbstractMcpTool
{
    /** The biggest file this tool takes, whatever the site allows: the file travels inside a JSON message. */
    public const MAX_MB = 5;

    public function __construct(
        private readonly MediaService $media,
        #[Autowire(param: 'itk_cms.max_upload_mb')]
        private readonly int $maxMb,
    ) {
    }

    public function name(): string
    {
        return 'media_upload';
    }

    public function description(): string
    {
        return \sprintf('Upload a photo (JPG, PNG, WebP, GIF, AVIF) or a PDF to the media library from base64 data (at most %d MB). The type is checked by the content, not the name. Returns the media id and the src to put into image fields. '
            . 'The server never fetches addresses: you must send the file bytes.', \min(self::MAX_MB, $this->maxMb));
    }

    public function annotations(): array
    {
        return ['readOnly' => false, 'destructive' => false, 'idempotent' => false];
    }

    public function inputSchema(): array
    {
        return self::schema([
            'filename' => self::string('File name with extension, e.g. team-photo.jpg.', 120),
            'data_base64' => ['type' => 'string', 'description' => 'The file, base64-encoded (no data: prefix).'],
            'alt' => self::string('Alt text (what the picture shows).', 255),
            'folder_id' => self::string('Media folder id; default: the top level.', 64),
        ], ['filename', 'data_base64']);
    }

    public function requiredScope(): string
    {
        return McpScopes::WRITE;
    }

    public function call(array $args, McpContext $context): array
    {
        $context->require('media', 'edit');
        $limit = \min(self::MAX_MB, $this->maxMb) * 1024 * 1024;
        $b64 = (string) \preg_replace('/\s+/', '', (string) $args['data_base64']);
        if (\strlen($b64) > (int) \ceil($limit * 4 / 3) + 8) {
            throw new McpToolException(\sprintf('The file is larger than %d MB.', \intdiv($limit, 1024 * 1024)));
        }
        $bytes = \base64_decode($b64, true);
        if (false === $bytes || '' === $bytes) {
            throw new McpToolException('data_base64 is not valid base64 (send the plain base64 of the file, without "data:…;base64,").');
        }
        if (\strlen($bytes) > $limit) {
            throw new McpToolException(\sprintf('The file is larger than %d MB.', \intdiv($limit, 1024 * 1024)));
        }
        $tmp = \tempnam(\sys_get_temp_dir(), 'itkmcp');
        if (false === $tmp) {
            throw new McpToolException('The server could not take the file now. Try again later.');
        }
        try {
            \file_put_contents($tmp, $bytes);
            // the same service and the same checks as the admin's upload (type by content, size, name made safe)
            $file = new UploadedFile($tmp, \basename(\str_replace('\\', '/', (string) $args['filename'])), null, null, true);
            $m = $this->media->upload($file, isset($args['folder_id']) ? (string) $args['folder_id'] : null);
        } finally {
            if (\is_file($tmp)) {
                @\unlink($tmp);
            }
        }
        if (isset($args['alt']) && '' !== \trim((string) $args['alt'])) {
            $this->media->update($m->id, ['alt' => (string) $args['alt']]);
        }

        return ['uploaded' => true] + MediaSearchTool::row($this->media->present($this->media->find($m->id)));
    }
}
