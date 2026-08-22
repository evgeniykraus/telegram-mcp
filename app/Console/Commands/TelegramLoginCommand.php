<?php

namespace App\Console\Commands;

use App\Factories\CustomMadelineProtoFactory;
use danog\MadelineProto\RPCError\SessionPasswordNeededError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use PHPCore\MadelineProto\Exceptions\NeedTwoFactorAuthException;
use PHPCore\MadelineProto\Exceptions\SignUpNeededException;

class TelegramLoginCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:login
                            {--reset : Force delete existing session files before login}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive Telegram login via MadelineProto with automatic session recovery and 2FA support';

    public function handle(CustomMadelineProtoFactory $factory): int
    {
        $apiId = config('telegram.settings.app_info.api_id');
        $apiHash = config('telegram.settings.app_info.api_hash');

        if (empty($apiId) || empty($apiHash)) {
            $this->error('Telegram API credentials missing!');
            $this->line('Please set MP_TELEGRAM_API_ID and MP_TELEGRAM_API_HASH in your .env file.');
            $this->line('Get them from https://my.telegram.org/apps');

            return self::FAILURE;
        }

        $sessionFile = (string) config('telegram.sessions.single.session_file', 'session.madeline');

        if ($this->option('reset')) {
            $this->info('Resetting Telegram session files...');
            $this->cleanSession($sessionFile);
        }

        $this->info('--- Telegram Authorization (MadelineProto) ---');
        $phoneNumber = (string) $this->ask('Phone number with country code (e.g. +79991234567 or +380991234567)?');

        if (trim($phoneNumber) === '') {
            $this->error('Phone number cannot be empty.');

            return self::FAILURE;
        }

        try {
            $madelineProto = $factory->get();

            $this->line('Sending login code...');
            $madelineProto->phoneLogin($phoneNumber);

            $code = (string) $this->ask('Enter confirmation code received in Telegram / SMS:');
            if (trim($code) === '') {
                $this->error('Code cannot be empty.');

                return self::FAILURE;
            }

            try {
                $madelineProto->completePhoneLogin($code);
            } catch (NeedTwoFactorAuthException|SessionPasswordNeededError $e) {
                $this->info('2FA Cloud Password is required.');
                $password = (string) $this->secret('Enter your 2FA Cloud Password:');
                $madelineProto->submit2FA($password);
            } catch (RPCErrorException $e) {
                if ($e->rpc === 'SESSION_PASSWORD_NEEDED') {
                    $this->info('2FA Cloud Password is required.');
                    $password = (string) $this->secret('Enter your 2FA Cloud Password:');
                    $madelineProto->submit2FA($password);
                } else {
                    throw $e;
                }
            } catch (SignUpNeededException $e) {
                $this->info('New account sign up required.');
                $firstName = (string) $this->ask('First name:');
                $lastName = (string) $this->ask('Last name (optional):', '');
                $madelineProto->completeSignUp($firstName, $lastName);
            }

            $this->info('✓ Telegram account successfully authorized and session saved!');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Authorization failed: '.$e->getMessage());

            $this->warn('Cleaning unauthenticated session to prevent lockups on next attempt...');
            $this->cleanSession($sessionFile);

            $this->line('Please re-run: vendor/bin/sail artisan postiz:login');

            return self::FAILURE;
        }
    }

    private function cleanSession(string $sessionFile): void
    {
        $dir = storage_path('app/telegram');

        File::deleteDirectory($dir);
        File::makeDirectory($dir, 0755, true, true);
    }
}
