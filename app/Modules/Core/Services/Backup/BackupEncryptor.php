<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

/**
 * Public-key encryption of a backup stream. Implementations only build shell fragments that read their
 * inputs from ENVIRONMENT VARIABLES ($HM_ENC_*), never from interpolated values, so nothing configurable can
 * ever be injected into a command line. The plaintext dump is piped straight into the encryptor: it never
 * exists as a file.
 */
interface BackupEncryptor
{
    /** File extension appended after ".dump", e.g. "age". */
    public function extension(): string;

    /**
     * Environment the fragments below rely on (binary + PUBLIC recipient).
     *
     * @return array<string, string>
     */
    public function encryptEnv(): array;

    /** Reads plaintext on stdin, writes ciphertext to "$HM_OUT". */
    public function encryptFragment(): string;

    /**
     * Environment for decryption: binary, the private-key FILE path, scratch dir.
     *
     * @return array<string, string>
     */
    public function decryptEnv(string $keyFile, string $scratchDir): array;

    /** Reads "$HM_IN", writes plaintext on stdout. May contain several `;`/`&&` steps, ending in the decrypt. */
    public function decryptFragment(): string;
}
