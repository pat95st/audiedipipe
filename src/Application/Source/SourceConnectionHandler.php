<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source;

use AudiEdiPipe\Application\Source\Command\ConnectSourceAccount;
use AudiEdiPipe\Application\Source\Command\DisconnectSourceAccount;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudStorage;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;
use AudiEdiPipe\Infrastructure\Support\Json;
use Throwable;

/**
 * Connects cloud storage locations (Dropbox, OneDrive, Google Drive, Box) to an account via OAuth.
 */
final class SourceConnectionHandler implements HandlerProvider
{
    public function __construct(
        private readonly Database $db,
        private readonly StorageConnections $connections,
        private readonly CloudProviders $providers,
    ) {
    }

    public static function handledMessages(): array
    {
        return [
            ConnectSourceAccount::class => 'connect',
            DisconnectSourceAccount::class => 'disconnect',
        ];
    }

    /**
     * @return string the connected account (e-mail/login), may be empty
     */
    public function connect(ConnectSourceAccount $command): string
    {
        $type = $this->oauthType($command->id);
        if (!$this->providers->enabled($type)) {
            throw new ValidationException('sources.error.provider_disabled');
        }
        $tokens = $this->providers->client($type)->exchangeCode($command->code, $this->providers->redirectUri());
        $this->connections->storeTokens($command->id, $tokens);

        $account = '';

        try {
            $row = $this->db->fetchOne('SELECT config FROM sources WHERE id = ?', [$command->id]);
            $adapter = $this->connections->fromConfig($type, Json::decode($row['config'] ?? '{}'), $command->id);
            $account = $adapter instanceof CloudStorage ? $adapter->account() : '';
        } catch (Throwable) {
            // The account name is only informational.
        }
        $this->connections->storeTokens($command->id, $tokens, $account);

        return $account;
    }

    public function disconnect(DisconnectSourceAccount $command): void
    {
        $this->oauthType($command->id);
        $this->connections->clearTokens($command->id);
    }

    private function oauthType(int $sourceId): string
    {
        $type = $this->db->fetchValue('SELECT type FROM sources WHERE id = ?', [$sourceId]);
        if ($type === null) {
            throw new ValidationException('sources.error.not_found');
        }
        if (!CloudProviders::isOAuth((string) $type)) {
            throw new ValidationException('sources.error.invalid_type');
        }

        return (string) $type;
    }
}
