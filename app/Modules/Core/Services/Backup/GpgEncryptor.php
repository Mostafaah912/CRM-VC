<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

/**
 * Fallback when `age` cannot be installed. Encryption needs the recipient's PUBLIC key in the server's gpg keyring.
 * Decryption imports the (unprotected) private key file into a throw-away GNUPGHOME inside the scratch dir.
 */
final class GpgEncryptor implements BackupEncryptor
{
    public function __construct(private readonly string $binary, private readonly ?string $recipient) {}

    public function extension(): string
    {
        return 'gpg';
    }

    /** @return array<string, string> */
    public function encryptEnv(): array
    {
        $recipient = trim((string) $this->recipient);

        if ($recipient === '' || str_contains($recipient, 'PRIVATE KEY')) {
            throw new BackupFailedException('BACKUP_GPG_RECIPIENT is missing (key id, fingerprint or e-mail of a public key).');
        }

        return ['HM_ENC_BIN' => $this->binary, 'HM_ENC_RECIPIENT' => $recipient];
    }

    public function encryptFragment(): string
    {
        return '"$HM_ENC_BIN" --batch --yes --trust-model always --encrypt --recipient "$HM_ENC_RECIPIENT" --output "$HM_OUT"';
    }

    /** @return array<string, string> */
    public function decryptEnv(string $keyFile, string $scratchDir): array
    {
        return ['HM_ENC_BIN' => $this->binary, 'HM_KEYFILE' => $keyFile, 'GNUPGHOME' => $scratchDir.'/gnupg'];
    }

    public function decryptFragment(): string
    {
        return 'mkdir -m 700 -p "$GNUPGHOME" && "$HM_ENC_BIN" --batch --quiet --import "$HM_KEYFILE" 2>/dev/null && "$HM_ENC_BIN" --batch --quiet --decrypt "$HM_IN"';
    }
}
