<?php

declare(strict_types=1);

/**
 * FileUploadHelper — Handles media file uploads.
 *
 * Stores files at:  uploads/{entity}/{id}/{filename}
 *   e.g.           uploads/user/1/avatar_1234567890.jpg
 *
 * Usage:
 *   $uploader = new FileUploadHelper();
 *   $result   = $uploader->upload($_FILES['avatar'], 'user', 1);
 *   // $result = ['path' => 'user/1/avatar_xxx.jpg', 'url' => 'https://...', 'size' => 12345]
 *
 *   // Upload multiple files
 *   $results = $uploader->uploadMultiple($_FILES['photos'], 'product', 5);
 *
 *   // Delete a file
 *   $uploader->delete('user/1/avatar_xxx.jpg');
 */
class FileUploadHelper
{
    private string $uploadDir;
    private string $baseUrl;
    private int    $maxSize;
    private array  $allowedMime;
    private array  $allowedExt;
    private Logger $logger;

    public function __construct()
    {
        $this->uploadDir   = rtrim(Config::get('upload.upload_dir', ROOT_PATH . '/uploads/'), '/') . '/';
        $this->baseUrl     = rtrim(Config::get('upload.base_url', ''), '/') . '/';
        $this->maxSize     = (int) Config::get('upload.max_size', 5242880);
        $this->allowedMime = Config::get('upload.allowed_mime', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
        $this->allowedExt  = Config::get('upload.allowed_ext', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $this->logger      = Logger::getInstance();
    }

    // ── Single file upload ───────────────────────────────────────────────────
    /**
     * Upload a single file.
     *
     * @param  array  $file      $_FILES['fieldname']
     * @param  string $entity    e.g. 'user', 'product'
     * @param  int    $entityId  e.g. 1
     * @param  array  $options   ['prefix' => 'avatar', 'allowed_mime' => [...], 'max_size' => int]
     * @return array  ['relative_path', 'url', 'filename', 'original_name', 'size', 'mime', 'extension']
     * @throws RuntimeException on validation or move failure
     */
    public function upload(array $file, string $entity, int $entityId, array $options = []): array
    {
        $this->validateUploadError($file);

        $maxSize     = $options['max_size']      ?? $this->maxSize;
        $allowedMime = $options['allowed_mime']  ?? $this->allowedMime;
        $allowedExt  = $options['allowed_ext']   ?? $this->allowedExt;
        $prefix      = $options['prefix']        ?? '';

        // Validate size
        if ($file['size'] > $maxSize) {
            throw new RuntimeException(
                'File too large. Max allowed: ' . CommonHelper::bytesToHuman($maxSize),
                400
            );
        }

        // Validate MIME (re-check from file content, not just header)
        $detectedMime = $this->detectMime($file['tmp_name']);
        if (!in_array($detectedMime, $allowedMime, true)) {
            throw new RuntimeException("File type '{$detectedMime}' is not allowed.", 400);
        }

        // Validate extension
        $originalExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($originalExt, $allowedExt, true)) {
            throw new RuntimeException("File extension '.{$originalExt}' is not allowed.", 400);
        }

        // Build destination
        $entity    = preg_replace('/[^a-z0-9_-]/i', '', $entity);
        $filename  = $this->buildFilename($prefix ?: $entity, $originalExt);
        $subDir    = "{$entity}/{$entityId}/";
        $destDir   = $this->uploadDir . $subDir;

        $this->ensureDir($destDir);

        $destPath     = $destDir . $filename;
        $relativePath = $subDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $this->logger->error('File move failed', ['tmp' => $file['tmp_name'], 'dest' => $destPath]);
            throw new RuntimeException('Failed to save uploaded file.', 500);
        }

        chmod($destPath, 0644);

        $this->logger->info('File uploaded', ['path' => $relativePath, 'size' => $file['size']]);

        return [
            'relative_path' => $relativePath,
            'url'           => $this->baseUrl . $relativePath,
            'filename'      => $filename,
            'original_name' => $file['name'],
            'size'          => $file['size'],
            'size_human'    => CommonHelper::bytesToHuman($file['size']),
            'mime'          => $detectedMime,
            'extension'     => $originalExt,
        ];
    }

    // ── Multiple file upload ─────────────────────────────────────────────────
    /**
     * Upload multiple files from a multi-file input.
     * $_FILES['photos'] where input has multiple attribute.
     */
    public function uploadMultiple(array $files, string $entity, int $entityId, array $options = []): array
    {
        // Normalize the $_FILES multi-array format
        $normalized = $this->normalizeMultipleFiles($files);
        $results    = [];

        foreach ($normalized as $file) {
            $results[] = $this->upload($file, $entity, $entityId, $options);
        }

        return $results;
    }

    // ── Delete ───────────────────────────────────────────────────────────────
    /**
     * Delete an uploaded file.
     * @param  string $relativePath  e.g. 'user/1/avatar_xxx.jpg'
     * @return bool
     */
    public function delete(string $relativePath): bool
    {
        $fullPath = $this->uploadDir . ltrim($relativePath, '/');

        // Security: ensure path is inside upload dir
        $realUpload = realpath($this->uploadDir);
        $realFile   = realpath($fullPath);

        if ($realFile === false || !str_starts_with($realFile, $realUpload)) {
            $this->logger->warning('Attempted delete outside upload dir', ['path' => $relativePath]);
            return false;
        }

        if (file_exists($realFile)) {
            $deleted = unlink($realFile);
            if ($deleted) {
                $this->logger->info('File deleted', ['path' => $relativePath]);
            }
            return $deleted;
        }

        return false;
    }

    // ── Helpers ──────────────────────────────────────────────────────────────
    private function validateUploadError(array $file): void
    {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension.',
        ];

        $errorCode = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new RuntimeException($errors[$errorCode] ?? 'Unknown upload error.', 400);
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            throw new RuntimeException('Invalid upload attempt.', 400);
        }
    }

    private function detectMime(string $tmpPath): string
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($tmpPath) ?: 'application/octet-stream';
    }

    private function buildFilename(string $prefix, string $ext): string
    {
        return preg_replace('/[^a-z0-9_-]/i', '_', $prefix)
             . '_'
             . time()
             . '_'
             . substr(bin2hex(random_bytes(4)), 0, 8)
             . '.'
             . $ext;
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException("Cannot create upload directory: {$dir}", 500);
            }
        }

        // Protect directory from direct execution
        $htaccess = $dir . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Deny from all\n</FilesMatch>\n");
        }
    }

    private function normalizeMultipleFiles(array $files): array
    {
        $normalized = [];

        if (isset($files['name']) && is_array($files['name'])) {
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                $normalized[] = [
                    'name'     => $files['name'][$i],
                    'type'     => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error'    => $files['error'][$i],
                    'size'     => $files['size'][$i],
                ];
            }
        } else {
            $normalized[] = $files;
        }

        return $normalized;
    }

    /** Get full URL from a relative path */
    public function url(string $relativePath): string
    {
        return $this->baseUrl . ltrim($relativePath, '/');
    }
}
