<?php

declare(strict_types=1);

namespace PopNow;

/**
 * Media types the upload ticket endpoint accepts.
 *
 * openapi.yaml `UploadURLRequest.contentType`. Anything else is rejected before
 * a ticket is issued, so the enum is the whole list. `tests/unit.php` and
 * `scripts/check_contract.php` both compare it with the contract.
 *
 * Documents (PDF, Office, iWork, plain text and Markdown) are reference files
 * for the models whose schema asks for one. `application/octet-stream` is for
 * LoRA weights only: a `.safetensors` file whose committed URI goes into
 * `loras[].path`.
 */
enum UploadContentType: string
{
    case Jpeg = 'image/jpeg';
    case Png = 'image/png';
    case Webp = 'image/webp';
    case Gif = 'image/gif';
    case Mp4 = 'video/mp4';
    case Webm = 'video/webm';
    case Mp3 = 'audio/mpeg';
    case Wav = 'audio/wav';
    case Pdf = 'application/pdf';
    case Doc = 'application/msword';
    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
    case Xls = 'application/vnd.ms-excel';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Ppt = 'application/vnd.ms-powerpoint';
    case Pptx = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';
    case Keynote = 'application/vnd.apple.keynote';
    case Pages = 'application/vnd.apple.pages';
    case Numbers = 'application/vnd.apple.numbers';
    case Text = 'text/plain';
    case Markdown = 'text/markdown';
    case LoraWeights = 'application/octet-stream';

    /** File extensions this client can map on its own. */
    private const EXTENSIONS = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'key' => 'application/vnd.apple.keynote',
        'pages' => 'application/vnd.apple.pages',
        'numbers' => 'application/vnd.apple.numbers',
        'txt' => 'text/plain',
        'md' => 'text/markdown',
        'markdown' => 'text/markdown',
        'safetensors' => 'application/octet-stream',
    ];

    /** 10 MiB: the image ceiling from the `createUploadUrl` description. */
    public const IMAGE_MAX_BYTES = 10 * 1024 * 1024;

    /** 90 MiB: audio, video and documents. */
    public const MEDIA_MAX_BYTES = 90 * 1024 * 1024;

    /** 2 GiB: LoRA weights, which is also the `bytes` maximum of the request schema. */
    public const LORA_MAX_BYTES = 2 * 1024 * 1024 * 1024;

    public function isImage(): bool
    {
        return str_starts_with($this->value, 'image/');
    }

    /** True for `application/octet-stream`, the `.safetensors` LoRA weights type. */
    public function isLoraWeights(): bool
    {
        return $this === self::LoraWeights;
    }

    /** True for the reference document types: neither image, audio, video nor LoRA weights. */
    public function isDocument(): bool
    {
        return !$this->isImage()
            && !$this->isLoraWeights()
            && !str_starts_with($this->value, 'video/')
            && !str_starts_with($this->value, 'audio/');
    }

    /**
     * Per-type ceiling, from the `createUploadUrl` description: 10 MiB for
     * images, 90 MiB for audio, video and documents, 2 GiB for LoRA weights.
     * Audio and video must additionally be at most 600 seconds, which only the
     * server can measure.
     *
     * This is a local sanity check. The ticket's own `maxBytes` is what the
     * server will enforce, and {@see Client::uploadBytes()} checks that too.
     */
    public function maxBytes(): int
    {
        if ($this->isImage()) {
            return self::IMAGE_MAX_BYTES;
        }

        return $this->isLoraWeights() ? self::LORA_MAX_BYTES : self::MEDIA_MAX_BYTES;
    }

    /**
     * Guess the media type from a file extension.
     *
     * Deliberately not `mime_content_type()` / ext-fileinfo: that reads the
     * file's magic bytes, which is better, but it is an optional extension and
     * this client promises to run on a default build. Pass `contentType`
     * explicitly whenever the extension may lie.
     */
    public static function forPath(string $path): self
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $value = self::EXTENSIONS[$extension] ?? null;
        if ($value === null) {
            throw new \InvalidArgumentException(sprintf(
                'cannot infer contentType from "%s"; pass it explicitly. Known extensions: %s',
                $path,
                implode(', ', array_keys(self::EXTENSIONS)),
            ));
        }

        return self::from($value);
    }
}
