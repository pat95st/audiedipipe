<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Database;

use AudiEdiPipe\Application\Settings\Command\SaveSettings;
use AudiEdiPipe\Application\Settings\Query\GetSettings;
use AudiEdiPipe\Application\Settings\SettingsHandler;
use AudiEdiPipe\Application\Source\Command\ConnectSourceAccount;
use AudiEdiPipe\Application\Source\Command\DisconnectSourceAccount;
use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\Source\SourceCommandHandler;
use AudiEdiPipe\Application\Source\SourceConnectionHandler;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\Cloud\DropboxStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthTokens;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;
use AudiEdiPipe\Infrastructure\Support\Json;
use AudiEdiPipe\Tests\Support\FakeHttpClient;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * Cloud storage settings, the OAuth connection of a source and token persistence.
 */
final class CloudStorageTest extends TestCase
{
    private Database $db;

    private Crypto $crypto;

    private FakeHttpClient $http;

    private SettingsRepository $settings;

    private CloudProviders $providers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->migratedDatabase();
        $this->crypto = new Crypto(Crypto::generateKey());
        $this->http = new FakeHttpClient();
        $this->settings = new SettingsRepository($this->db);
        $this->providers = new CloudProviders($this->http, $this->settings, $this->crypto, 'https://aep.example/');
    }

    public function test_settings_store_encrypted_secret_and_mask_it(): void
    {
        $handler = new SettingsHandler($this->settings, $this->crypto);
        $handler->save(new SaveSettings([CloudProviders::SETTINGS_KEY => [
            'dropbox' => ['enabled' => '1', 'client_id' => ' key ', 'client_secret' => 'top-secret'],
            'onedrive' => ['enabled' => '0', 'tenant' => 'bad tenant!'],
            'icloud' => ['enabled' => '1', 'base_path' => '/Users/pod/iCloud'],
        ]]));

        $stored = $this->settings->get(CloudProviders::SETTINGS_KEY);
        self::assertIsArray($stored);
        self::assertTrue(Crypto::isEncrypted($stored['dropbox']['client_secret']));
        self::assertSame('common', $stored['onedrive']['tenant']);

        $view = $handler->get(new GetSettings())[CloudProviders::SETTINGS_KEY];
        self::assertSame('', $view['dropbox']['client_secret']);
        self::assertTrue($view['dropbox']['client_secret_set']);

        self::assertTrue($this->providers->enabled('dropbox'));
        self::assertFalse($this->providers->enabled('onedrive'));
        self::assertFalse($this->providers->enabled('gdrive'));
        self::assertTrue($this->providers->enabled('icloud'));
        self::assertSame(['dropbox', 'icloud'], $this->providers->enabledTypes());
        self::assertSame('top-secret', $this->providers->config('dropbox')['client_secret']);
        self::assertSame('/Users/pod/iCloud', $this->providers->icloudBasePath());
        self::assertSame('https://aep.example/oauth/callback', $this->providers->redirectUri());

        // An empty secret keeps the stored one.
        $handler->save(new SaveSettings([CloudProviders::SETTINGS_KEY => ['dropbox' => ['enabled' => '1', 'client_id' => 'key', 'client_secret' => '']]]));
        self::assertSame('top-secret', $this->providers->config('dropbox')['client_secret']);
    }

    public function test_disabled_provider_cannot_be_chosen_for_a_new_source(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('sources.error.provider_disabled');
        $this->sources()->save($this->saveCommand(null));
    }

    public function test_connect_saves_tokens_and_account_and_saving_the_form_keeps_them(): void
    {
        $this->enableDropbox();
        $id = $this->sources()->save($this->saveCommand(null));

        $this->http
            ->queue(200, ['access_token' => 'AT', 'refresh_token' => 'RT', 'expires_in' => 14400])
            ->queue(200, ['email' => 'pod@example.com']);
        $account = $this->connectionHandler()->connect(new ConnectSourceAccount($id, 'auth-code'));

        self::assertSame('pod@example.com', $account);
        parse_str((string) $this->http->requests[0]['body'], $form);
        self::assertSame('https://aep.example/oauth/callback', $form['redirect_uri']);
        $config = $this->config($id);
        self::assertTrue(Crypto::isEncrypted($config['refresh_token']));
        self::assertSame('RT', $this->crypto->decrypt($config['refresh_token']));
        self::assertSame('pod@example.com', $config['account']);

        // Saving the form again (tokens are never part of the form) keeps the connection,
        // and tokens injected through the form are ignored.
        $this->sources()->save($this->saveCommand($id, ['refresh_token' => 'evil']));
        self::assertSame('RT', $this->crypto->decrypt($this->config($id)['refresh_token']));

        // The adapter built from the stored config uses the token.
        $adapter = $this->connections()->adapter($id);
        self::assertInstanceOf(DropboxStorage::class, $adapter);
        $this->http->queue(200, ['entries' => [], 'has_more' => false]);
        $adapter->listFiles('/');
        self::assertSame('Bearer AT', $this->http->requests[2]['headers']['Authorization']);

        $this->connectionHandler()->disconnect(new DisconnectSourceAccount($id));
        self::assertArrayNotHasKey('refresh_token', $this->config($id));
        self::assertArrayNotHasKey('account', $this->config($id));
    }

    public function test_refreshed_tokens_are_persisted_to_the_source(): void
    {
        $this->enableDropbox();
        $id = $this->sources()->save($this->saveCommand(null));
        $this->db->update('sources', ['config' => Json::encode([
            'refresh_token' => $this->crypto->encrypt('RT1'),
            'access_token' => $this->crypto->encrypt('expired'),
            'token_expires' => time() - 10,
        ])], ['id' => $id]);

        $this->http
            ->queue(200, ['access_token' => 'AT2', 'refresh_token' => 'RT2', 'expires_in' => 3600])
            ->queue(200, ['entries' => [], 'has_more' => false]);
        $this->connections()->adapter($id)?->listFiles('/');

        $config = $this->config($id);
        self::assertSame('RT2', $this->crypto->decrypt($config['refresh_token']));
        self::assertSame('AT2', $this->crypto->decrypt($config['access_token']));
        self::assertGreaterThan(time(), $config['token_expires']);
    }

    public function test_changing_the_type_drops_the_connection(): void
    {
        $this->enableDropbox();
        $id = $this->sources()->save($this->saveCommand(null));
        $this->connections()->storeTokens($id, new OAuthTokens('a', 'r', time() + 100), 'x@example.com');

        $this->sources()->save(new SaveSource($id, 'Inbox', 'local', [], '/tmp', [], 300, 'keep', true));

        self::assertArrayNotHasKey('refresh_token', $this->config($id));
    }

    private function enableDropbox(): void
    {
        (new SettingsHandler($this->settings, $this->crypto))->save(new SaveSettings([CloudProviders::SETTINGS_KEY => [
            'dropbox' => ['enabled' => '1', 'client_id' => 'key', 'client_secret' => 'secret'],
        ]]));
    }

    /**
     * @param array<string, string> $config
     */
    private function saveCommand(?int $id, array $config = []): SaveSource
    {
        return new SaveSource($id, 'Inbox', 'dropbox', $config, '/Podcast', [], 300, 'keep', true);
    }

    private function sources(): SourceCommandHandler
    {
        return new SourceCommandHandler($this->db, $this->crypto, $this->providers);
    }

    private function connections(): StorageConnections
    {
        return new StorageConnections($this->db, $this->crypto, new StorageFactory($this->http, new ProcessRunner(), 'smbclient', $this->providers));
    }

    private function connectionHandler(): SourceConnectionHandler
    {
        return new SourceConnectionHandler($this->db, $this->connections(), $this->providers);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(int $id): array
    {
        /** @var array<string, mixed> */
        return Json::decode($this->db->fetchValue('SELECT config FROM sources WHERE id = ?', [$id]));
    }
}
