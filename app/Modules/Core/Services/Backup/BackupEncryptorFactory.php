<?php

declare(strict_types=1);

namespace App\Modules\Core\Services\Backup;

final class BackupEncryptorFactory
{
    public function make(): BackupEncryptor
    {
        return match ((string) config('backup.encryption')) {
            'age' => new AgeEncryptor((string) config('backup.age.binary'), config('backup.age.recipient')),
            'gpg' => new GpgEncryptor((string) config('backup.gpg.binary'), config('backup.gpg.recipient')),
            default => throw new BackupFailedException('BACKUP_ENCRYPTION must be "age" or "gpg".'),
        };
    }
}
