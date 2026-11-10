<?php

namespace App\Console\Commands;

use App\Domain\Accounts\Actions\UserDataService;
use Illuminate\Console\Command;
use Throwable;

class ExportUserData extends Command
{
    protected $signature = 'nunez:user:export {email} {--output= : New JSON file path}';

    protected $description = 'Write an operator-requested user data export to a private JSON file';

    public function handle(UserDataService $data): int
    {
        $path = $this->option('output');
        if (! is_string($path) || $path === '') {
            $this->error('Provide --output with a new file path. Existing files are not overwritten.');

            return self::FAILURE;
        }

        $created = false;
        try {
            $json = json_encode($data->export((string) $this->argument('email')), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $previousUmask = umask(0077);
            try {
                $handle = @fopen($path, 'x');
            } finally {
                umask($previousUmask);
            }
            if ($handle === false) {
                throw new \RuntimeException('Could not create a new export file.');
            }
            $created = true;
            try {
                if (fwrite($handle, $json) !== strlen($json)) {
                    throw new \RuntimeException('Could not write export file.');
                }
            } finally {
                fclose($handle);
            }
        } catch (Throwable) {
            if ($created) {
                @unlink($path);
            }
            $this->error('Export failed. Check the account and output path, then retry.');

            return self::FAILURE;
        }

        $this->info('User export written. Store it securely and delete it when no longer needed.');

        return self::SUCCESS;
    }
}
