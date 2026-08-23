<?php

namespace App\Factories;

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Stream\Proxy\HttpProxy;
use danog\MadelineProto\Stream\Proxy\SocksProxy;
use Illuminate\Support\Facades\File;

class CustomMadelineProtoFactory
{
    /**
     * Get or create a native MadelineProto API instance for the given session.
     */
    public function get(?string $sessionFile = null): API
    {
        $file = $sessionFile ?? (string) config('telegram.sessions.single.session_file', 'session.madeline');
        $sessionDir = storage_path('app/telegram');
        File::ensureDirectoryExists($sessionDir);

        $sessionPath = "{$sessionDir}/{$file}";
        $settings = $this->buildSettings();

        return new API($sessionPath, $settings);
    }

    /**
     * Build MadelineProto Settings from configuration.
     */
    private function buildSettings(): Settings
    {
        $settings = new Settings;

        $apiId = config('telegram.settings.app_info.api_id');
        $apiHash = config('telegram.settings.app_info.api_hash');

        if (! empty($apiId)) {
            $settings->getAppInfo()->setApiId((int) $apiId);
        }
        if (! empty($apiHash)) {
            $settings->getAppInfo()->setApiHash((string) $apiHash);
        }

        // Fast connection: Disable DoH to prevent slow DNS-over-HTTPS timeouts
        $connection = $settings->getConnection();
        $connection->setUseDoH(false);
        $connection->setRetry(false);

        // Logger settings
        $loggerConfig = (array) config('telegram.settings.logger', []);
        if (isset($loggerConfig['logger'])) {
            $settings->getLogger()->setType((int) $loggerConfig['logger']);
        }
        if (! empty($loggerConfig['logger_param'])) {
            $settings->getLogger()->setExtra((string) $loggerConfig['logger_param']);
        }
        if (isset($loggerConfig['logger_level'])) {
            $settings->getLogger()->setLevel((int) $loggerConfig['logger_level']);
        }

        // Proxy settings
        $proxyConfig = config('telegram.settings.connection.proxy');
        if (! empty($proxyConfig) && is_array($proxyConfig) && ! empty($proxyConfig['address'])) {
            $this->configureProxy($connection, $proxyConfig);
        }

        return $settings;
    }

    /**
     * Configure proxy connection for MadelineProto.
     *
     * @param  mixed  $connection
     * @param  array<string, mixed>  $proxyConfig
     */
    private function configureProxy($connection, array $proxyConfig): void
    {
        $rawAddress = (string) ($proxyConfig['address'] ?? '');
        $cleanAddress = preg_replace('#^[a-z0-9]+://#i', '', $rawAddress);
        $cleanAddress = explode(':', (string) $cleanAddress)[0];

        $type = strtolower((string) ($proxyConfig['type'] ?? 'socks5'));
        $proxyClass = match ($type) {
            'http', 'https' => HttpProxy::class,
            default => SocksProxy::class,
        };

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
            $proxyClass => [$extra],
        ]);
    }
}
