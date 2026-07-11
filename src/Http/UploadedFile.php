<?php

declare(strict_types=1);

namespace Meulah\Http;

use finfo;

final class UploadedFile
{
    private bool $moved = false;
    private ?string $movedPath = null;

    private function __construct(
        private readonly string $clientFilename,
        private readonly string $clientMediaType,
        private readonly string $temporaryPath,
        private readonly int $error,
        private readonly int $size,
        private readonly bool $testFile,
    ) {
    }

    public static function fromPhpUpload(
        string $clientFilename,
        string $clientMediaType,
        string $temporaryPath,
        int $error,
        int $size,
    ): self {
        return new self(
            $clientFilename,
            $clientMediaType,
            $temporaryPath,
            $error,
            $size,
            false,
        );
    }

    public static function forTesting(
        string $path,
        ?string $clientFilename = null,
        string $clientMediaType = 'application/octet-stream',
    ): self {
        if (!is_file($path)) {
            throw new UploadException("Test upload file does not exist: {$path}");
        }

        return new self(
            $clientFilename ?? basename($path),
            $clientMediaType,
            $path,
            UPLOAD_ERR_OK,
            (int) filesize($path),
            true,
        );
    }

    public function clientFilename(): string
    {
        return $this->clientFilename;
    }

    public function clientMediaType(): string
    {
        return $this->clientMediaType;
    }

    public function detectedMediaType(): string
    {
        $path = $this->movedPath ?? $this->temporaryPath;
        $type = (new finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($type === false) {
            throw new UploadException('Unable to detect the uploaded file media type.');
        }

        return $type;
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
        if ($this->moved || $this->error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $this->testFile
            ? is_file($this->temporaryPath)
            : is_uploaded_file($this->temporaryPath);
    }

    public function hasMoved(): bool
    {
        return $this->moved;
    }

    public function movedPath(): ?string
    {
        return $this->movedPath;
    }

    public function moveTo(string $destination): void
    {
        if ($this->moved) {
            throw new UploadException('The uploaded file has already been moved.');
        }

        if ($this->error !== UPLOAD_ERR_OK) {
            throw new UploadException("Cannot move upload with error code {$this->error}.");
        }

        if ($destination === '' || str_ends_with($destination, '/') || str_ends_with($destination, '\\')) {
            throw new UploadException('Upload destination must be a file path.');
        }

        $basename = basename($destination);

        if ($basename === '' || $basename === '.' || $basename === '..' || is_dir($destination)) {
            throw new UploadException('Upload destination must be a file path.');
        }

        if (file_exists($destination)) {
            throw new UploadException("Upload destination already exists: {$destination}");
        }

        $directory = dirname($destination);

        if (!is_dir($directory)) {
            throw new UploadException("Upload destination directory does not exist: {$directory}");
        }

        if (!is_writable($directory)) {
            throw new UploadException("Upload destination directory is not writable: {$directory}");
        }

        if (!$this->isValid()) {
            throw new UploadException('The temporary file is not a valid HTTP upload.');
        }

        $moved = $this->testFile
            ? rename($this->temporaryPath, $destination)
            : move_uploaded_file($this->temporaryPath, $destination);

        if (!$moved) {
            throw new UploadException("Unable to move uploaded file to: {$destination}");
        }

        $this->moved = true;
        $this->movedPath = $destination;
    }
}
