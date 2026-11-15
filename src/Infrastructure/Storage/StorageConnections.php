<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthTokens;
use AudiEdiPipe\Infrastructure\Support\Json;

/**
 * Builds storage adapters from the stored (encrypted) connection definitions.
 */
final class StorageConnections
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly StorageFactory $factory,
    ) {
    }

    public function adapter(int $sourceId): ?StorageAdapter
    {
        $row = $this->db->fetchOne('SELECT type, config FROM sources WHERE id = ?', [$sourceId]);
        if ($row === null) {
            return null;
        }

        return $this->fromConfig((string) $row['type'], Json::decode($row['config']), $sourceId);
    }

    /**
     * @param array<mixed> $config
     * @param int|null     $sourceId when given, refreshed cloud tokens are saved to this source
     */
    public function fromConfig(string $type, array $config, ?int $sourceId = null): StorageAdapter
    {
        $persist = $sourceId !== null && CloudProviders::isOAuth($type)
            ? function (OAuthTokens $tokens) use ($sourceId): void {
                $this->storeTokens($sourceId, $tokens);
            }
            : null;

        /** @var array<string, mixed> $config */
        return $this->factory->create($type, $this->crypto->decryptFields($config, StorageFactory::SECRET_FIELDS), $persist);
    }

    /**
     * Save (rotated) OAuth tokens of a cloud source, encrypted.
     */
    public function storeTokens(int $sourceId, OAuthTokens $tokens, ?string $account = null): void
    {
        $row = $this->db->fetchOne('SELECT config FROM sources WHERE id = ?', [$sourceId]);
        if ($row === null) {
            return;
        }
        $config = Json::decode($row['config']);
        $config['refresh_token'] = $this->crypto->encrypt($tokens->refreshToken);
        $config['access_token'] = $this->crypto->encrypt($tokens->accessToken);
        $config['token_expires'] = $tokens->expiresAt;
        if ($account !== null) {
            $config['account'] = mb_substr($account, 0, 190);
        }
        $this->db->update('sources', ['config' => Json::encode($config)], ['id' => $sourceId]);
    }

    public function clearTokens(int $sourceId): void
    {
        $row = $this->db->fetchOne('SELECT config FROM sources WHERE id = ?', [$sourceId]);
        if ($row === null) {
            return;
        }
        $config = array_diff_key(Json::decode($row['config']), array_flip(StorageFactory::TOKEN_FIELDS));
        $this->db->update('sources', ['config' => Json::encode($config)], ['id' => $sourceId]);
    }
}
