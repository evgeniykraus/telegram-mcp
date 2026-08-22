<?php

namespace App\Factories;

use danog\MadelineProto\API;
use danog\MadelineProto\Logger;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Stream\Proxy\HttpProxy;
use danog\MadelineProto\Stream\Proxy\SocksProxy;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;
use PHPCore\MadelineProto\Factories\MadelineProtoFactory as BaseMadelineProtoFactory;
use PHPCore\MadelineProto\MadelineProto;

class CustomMadelineProtoFactory extends BaseMadelineProtoFactory
{
    private Connection $database;

    private string $table;

    public function __construct(DatabaseManager $manager, string $table = 'telegram_sessions')
    {
        parent::__construct($manager, $table);
        $this->database = $manager->connection();
        $this->table = $table;
    }

    /**
     * @param  mixed  $session
     * @param  array<string, mixed>|null  $config
     */
    public function get($session = null, ?array $config = null): MadelineProto
    {
        if (is_int($session)) {
            $session = $this->database->table($this->table)->find($session);
        }

        if ($config === null) {
            $config = (array) config('telegram.settings', []);
            if (is_object($session) && isset($session->app_info)) {
                $config['app_info'] = is_string($session->app_info)
                    ? json_decode($session->app_info, true)
                    : (array) $session->app_info;
            }
        }

        $sessionFile = is_object($session) && isset($session->session_file)
            ? (string) $session->session_file
            : (string) config('telegram.sessions.single.session_file', 'session.madeline');

        return $this->make($sessionFile, $config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    public function make(string $sessionFile, ?array $config = null): MadelineProto
    {
        if ($config === null) {
            $config = (array) config('telegram.settings', []);
        }

        $settings = $this->buildFullSettings($config);

        $storageDir = storage_path('app/telegram');
        File::ensureDirectoryExists($storageDir);

        $tmpDir = '/tmp/telegram';
        File::ensureDirectoryExists($tmpDir);

        $storageSession = "{$storageDir}/{$sessionFile}";
        $tmpSession = "{$tmpDir}/{$sessionFile}";

        // If session exists in storage but not in /tmp, copy it over
        if (file_exists($storageSession) && ! file_exists($tmpSession)) {
            if (is_dir($storageSession)) {
                File::copyDirectory($storageSession, $tmpSession);
            } else {
                File::copy($storageSession, $tmpSession);
            }
        }

        register_shutdown_function(function () use ($tmpSession, $storageSession): void {
            if (! file_exists($tmpSession)) {
                return;
            }

            if (is_dir($tmpSession)) {
                File::ensureDirectoryExists($storageSession);
                foreach (File::files($tmpSession) as $file) {
                    if (! in_array($file->getExtension(), ['ipc', 'lock'], true) && $file->getFilename() !== 'ipc') {
                        File::copy($file->getPathname(), $storageSession.'/'.$file->getFilename());
                    }
                }
            } else {
                File::copy($tmpSession, $storageSession);
            }
        });

        $client = new API($tmpSession, $settings);

        return new MadelineProto($client);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function buildFullSettings(array $config): Settings
    {
        $settings = new Settings;

        // 1. App Info (API ID & Hash)
        if (isset($config['app_info'])) {
            $appInfo = $settings->getAppInfo();
            if (! empty($config['app_info']['api_id'])) {
                $appInfo->setApiId((int) $config['app_info']['api_id']);
            }
            if (! empty($config['app_info']['api_hash'])) {
                $appInfo->setApiHash((string) $config['app_info']['api_hash']);
            }
        }

        // 2. Fast connection: Disable DoH to prevent slow DNS-over-HTTPS timeouts
        $connection = $settings->getConnection();
        $connection->setUseDoH(false);
        $connection->setRetry(false);

        // 3. Logger settings
        if (isset($config['logger'])) {
            $logger = $settings->getLogger();
            if (isset($config['logger']['logger'])) {
                $logger->setType((int) $config['logger']['logger']);
            }
            if (! empty($config['logger']['logger_param'])) {
                $logger->setExtra((string) $config['logger']['logger_param']);
            }
            if (isset($config['logger']['logger_level'])) {
                $logger->setLevel((int) $config['logger']['logger_level']);
            }
        }

        // 4. Proxy settings if configured
        $proxyConfig = $config['connection']['proxy'] ?? null;
        if (! empty($proxyConfig) && is_array($proxyConfig) && ! empty($proxyConfig['address'])) {
            $rawAddress = (string) ($proxyConfig['address'] ?? '');
            $cleanAddress = preg_replace('#^[a-z0-9]+://#i', '', $rawAddress);
            $cleanAddress = explode(':', $cleanAddress)[0];

            $class = $this->proxyClass((string) ($proxyConfig['type'] ?? 'socks5'));
            $extra = [
                'address' => $cleanAddress,
                'port' => (int) ($proxyConfig['port'] ?? 0),
            ];

            if (! empty($proxyConfig['username'])) {
                $extra['username'] = (string) $proxyConfig['username'];
            }
            if (! empty($proxyConfig['password'])) {
                $extra['password'] = (string) $proxyConfig['password'];
            }
            if (isset($proxyConfig['secret'])) {
                $extra['secret'] = (string) $proxyConfig['secret'];
            }

            $connection->setProxies([
                $class => [$extra],
            ]);
        }

        return $settings;
    }

    private function proxyClass(string $type): string
    {
        return match (strtolower($type)) {
            'http', 'https' => HttpProxy::class,
            default => SocksProxy::class,
        };
    }
}
