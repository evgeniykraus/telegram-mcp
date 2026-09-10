<?php

namespace App\Console\Commands;

use App\Factories\CustomMadelineProtoFactory;
use danog\MadelineProto\API;
use danog\MadelineProto\RPCError\SessionPasswordNeededError;
use danog\MadelineProto\RPCErrorException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class TelegramLoginCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'postiz:login
                            {--reset : Force delete existing session files before login}
                            {--phone : Fall back to phone-number login with an SMS/app code instead of QR}
                            {--timeout=300 : Max seconds to wait for the QR login}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Interactive Telegram login via MadelineProto (QR by default, phone code via --phone)';

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

        try {
            $api = $factory->get($sessionFile);

            if ($this->option('phone')) {
                return $this->phoneLogin($api);
            }

            return $this->qrLogin($api);
        } catch (\Throwable $e) {
            $this->error('Authorization failed: '.$e->getMessage());

            $this->warn('Cleaning unauthenticated session to prevent lockups on next attempt...');
            $this->cleanSession($sessionFile);

            $this->line('Please re-run: vendor/bin/sail artisan postiz:login');

            return self::FAILURE;
        }
    }

    private function qrLogin(API $api): int
    {
        $timeout = max(30, (int) $this->option('timeout'));
        $deadline = time() + $timeout;

        $svgPath = public_path('qr-login.svg');
        $url = url('qr-login.svg');

        $this->info('--- Telegram QR Authorization (MadelineProto) ---');
        $this->line('1. A QR image will be written to: '.$svgPath);
        $this->line('   Open it in a browser: '.$url);
        $this->line('2. In the Telegram app where this account is ALREADY logged in:');
        $this->line('   Settings → Devices → Link Desktop Device → scan the QR.');
        $this->line('Scanning with a different/unauthorized device will NOT work.');
        $this->line('If this account has 2FA enabled, you will be asked for the Cloud Password.');

        try {
            while (time() < $deadline) {
                $state = $api->getAuthorization();

                if ($state === API::LOGGED_IN) {
                    $this->confirmAuthorized();

                    return self::SUCCESS;
                }

                if ($state === API::WAITING_PASSWORD) {
                    $this->line('');
                    $this->info('2FA Cloud Password is required to finish the QR login.');
                    $password = (string) $this->secret('Enter your 2FA Cloud Password:');
                    $api->complete2faLogin($password);
                    $this->confirmAuthorized();

                    return self::SUCCESS;
                }

                $qr = $api->qrLogin();

                if ($qr === null) {
                    usleep(500_000);

                    continue;
                }

                File::put($svgPath, $qr->getQRSvg(600));

                $this->line('');
                $this->info('Scan the QR code in the authorized Telegram app (file refreshes automatically):');
                $this->line('');
                $this->line('  Image file: '.$svgPath);
                $this->line('  Open in browser: '.$url);
                $this->line('  Direct link: '.$qr->link);
                $this->line('');

                $wait = max(1, min(10, $qr->expiresIn()));

                $start = time();
                while (time() - $start < $wait && time() < $deadline) {
                    if ($api->getAuthorization() === API::LOGGED_IN) {
                        $this->confirmAuthorized();

                        return self::SUCCESS;
                    }
                    usleep(500_000);
                }
            }

            $this->error('Timed out waiting for QR login ('.$timeout.'s). Re-run: vendor/bin/sail artisan postiz:login');

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->error('QR authorization failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            if (File::exists($svgPath)) {
                File::delete($svgPath);
            }
        }
    }

    private function phoneLogin(API $api): int
    {
        $this->info('--- Telegram Phone Authorization (MadelineProto) ---');
        $phoneNumber = (string) $this->ask('Phone number with country code (e.g. +79991234567)?');

        if (trim($phoneNumber) === '') {
            $this->error('Phone number cannot be empty.');

            return self::FAILURE;
        }

        $this->line('Sending login code...');
        $api->phoneLogin($phoneNumber);

        $code = (string) $this->ask('Enter confirmation code received in Telegram / SMS:');
        if (trim($code) === '') {
            $this->error('Code cannot be empty.');

            return self::FAILURE;
        }

        try {
            $api->completePhoneLogin($code);
        } catch (SessionPasswordNeededError $e) {
            $this->info('2FA Cloud Password is required.');
            $password = (string) $this->secret('Enter your 2FA Cloud Password:');
            $api->complete2faLogin($password);
        } catch (RPCErrorException $e) {
            if ($e->rpc === 'SESSION_PASSWORD_NEEDED') {
                $this->info('2FA Cloud Password is required.');
                $password = (string) $this->secret('Enter your 2FA Cloud Password:');
                $api->complete2faLogin($password);
            } elseif ($e->rpc === 'SIGN_UP_REQUIRED') {
                $this->info('New account sign up required.');
                $firstName = (string) $this->ask('First name:');
                $lastName = (string) $this->ask('Last name (optional):', '');
                $api->completeSignup($firstName, $lastName);
            } else {
                throw $e;
            }
        }

        $this->confirmAuthorized();

        return self::SUCCESS;
    }

    private function confirmAuthorized(): void
    {
        $this->info('✓ Telegram account successfully authorized and session saved!');
    }

    private function cleanSession(string $sessionFile): void
    {
        $path = storage_path("app/telegram/{$sessionFile}");

        if (is_dir($path)) {
            File::deleteDirectory($path);
        } elseif (file_exists($path)) {
            File::delete($path);
        }
    }
}
