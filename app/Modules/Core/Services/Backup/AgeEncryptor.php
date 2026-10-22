<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

/** https://age-encryption.org — the preferred driver. Needs only the public recipient ("age1...") on the server. */
final class AgeEncryptor implements BackupEncryptor
{
    public function __construct(private readonly string $binary, private readonly ?string $recipient) {}

    public function extension(): string
    {
        return 'age';
    }

    /** @return array<string, string> */
    public function encryptEnv(): array
    {
        $recipient = trim((string) $this->recipient);

        if (str_starts_with($recipient, 'AGE-SECRET-KEY')) {
            throw new BackupFailedException('BACKUP_AGE_RECIPIENT holds a PRIVATE key. Configure the public key (age1...) only.');
        }

        if (preg_match('/^age1[0-9a-z]{58}$/', $recipient) !== 1) {
            throw new BackupFailedException('BACKUP_AGE_RECIPIENT is missing or not a valid age public key (age1...).');
        }

        return ['HM_ENC_BIN' => $this->binary, 'HM_ENC_RECIPIENT' => $recipient];
    }

    public function encryptFragment(): string
    {
        return '"$HM_ENC_BIN" --encrypt --recipient "$HM_ENC_RECIPIENT" --output "$HM_OUT"';
    }

    /** @return array<string, string> */
    public function decryptEnv(string $keyFile, string $scratchDir): array
    {
        return ['HM_ENC_BIN' => $this->binary, 'HM_KEYFILE' => $keyFile];
    }

    public function decryptFragment(): string
    {
        return '"$HM_ENC_BIN" --decrypt --identity "$HM_KEYFILE" "$HM_IN"';
    }
}
