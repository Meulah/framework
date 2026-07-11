<?php

declare(strict_types=1);

namespace Meulah\Http;

use RuntimeException;

final class UploadedFile
{
    public function __construct(
        private readonly string $clientFilename,
        private readonly string $mediaType,
        private readonly string $temporaryPath,
        private readonly int $error,
        private readonly int $size,
    ) {
    }

    public function clientFilename(): string
    {
        return $this->clientFilename;
    }

    public function mediaType(): string
    {
        return $this->mediaType;
    }

    public function temporaryPath(): string
    {
        return $this->temporaryPath;
    }

    public function error(): int
    {
        return $this->error;
    }

    public function size(): int
    {
        return $this->size;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && is_uploaded_file($this->temporaryPath);
    }

    public function moveTo(string $destination): void
    {
        if ($this->error !== UPLOAD_ERR_OK) {
            throw new RuntimeException("Cannot move upload with error code {$this->error}.");
        }

        if (!is_uploaded_file($this->temporaryPath)) {
            throw new RuntimeException('The temporary file is not a valid HTTP upload.');
        }

        if (is_file($destination)) {
            throw new RuntimeException("Upload destination already exists: {$destination}");
        }

        $directory = dirname($destination);

        if (!is_dir($directory)) {
            throw new RuntimeException("Upload destination directory does not exist: {$directory}");
        }

        if (!move_uploaded_file($this->temporaryPath, $destination)) {
            throw new RuntimeException("Unable to move uploaded file to: {$destination}");
        }
    }
}

