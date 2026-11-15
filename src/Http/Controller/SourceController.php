<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Source\Command\ConnectSourceAccount;
use AudiEdiPipe\Application\Source\Command\DeleteSource;
use AudiEdiPipe\Application\Source\Command\DisconnectSourceAccount;
use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\Source\Query\GetSource;
use AudiEdiPipe\Application\Source\Query\ListSources;
use AudiEdiPipe\Application\Source\SourceScanner;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\FileFilter;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;
use AudiEdiPipe\Kernel\WorkerTrigger;
use Throwable;

final class SourceController extends Controller
{
    public function index(): Response
    {
        return $this->render('sources/index', [
            'title' => $this->view->t('nav.sources'),
            'sources' => $this->query(new ListSources()),
        ]);
    }

    public function form(): Response
    {
        $id = $this->request->intAttribute('id');
        $source = $id > 0 ? $this->query(new GetSource($id)) : null;
        if ($id > 0 && !is_array($source)) {
            return Response::notFound();
        }
        $cloud = $this->app->get(CloudProviders::class);
        $enabledCloud = $cloud->enabledTypes();
        // All storage types are always offered; cloud providers that are not yet set up under
        // Settings > Cloud storage are marked and explain what is missing.
        $types = StorageFactory::TYPES;

        return $this->render('sources/form', [
            'title' => $source === null ? $this->view->t('sources.new') : (string) $source['name'],
            'source' => $source ?? [
                'id' => null, 'name' => '', 'type' => 'local', 'config' => [], 'path' => '/',
                'filter' => ['extensions' => 'wav,mp3,flac,m4a,aac,ogg,opus,aiff', 'min_age_seconds' => 60],
                'poll_interval' => 300, 'after_import' => 'keep', 'enabled' => true,
            ],
            'types' => $types,
            'fields' => StorageFactory::FIELDS,
            'secrets' => StorageFactory::SECRET_FIELDS,
            'cloud' => [
                'oauth' => CloudProviders::OAUTH_TYPES,
                'enabled' => $enabledCloud,
                'unconfigured' => array_values(array_diff(CloudProviders::TYPES, $enabledCloud)),
                'icloud_base' => $cloud->icloudBasePath(),
                'can_configure' => $this->can('settings.manage'),
            ],
        ]);
    }

    public function save(): Response
    {
        $id = $this->nullableInt('id');
        $id = (int) $this->command(new SaveSource(
            $id,
            $this->request->string('name'),
            $this->request->string('type'),
            $this->arrayInput('config'),
            $this->request->string('path', '/'),
            $this->arrayInput('filter'),
            $this->request->int('poll_interval', 300),
            $this->request->string('after_import', 'keep'),
            $this->request->bool('enabled'),
        ));
        $this->flash('success', 'common.saved');
        $saved = $this->query(new GetSource($id));
        if (is_array($saved) && CloudProviders::isOAuth((string) $saved['type']) && ($saved['config']['refresh_token_set'] ?? false) !== true) {
            $this->flash('info', 'sources.cloud.connect_next');
        }

        return $this->redirect('/sources/' . $id);
    }

    /**
     * Start the OAuth flow: redirect to the provider's consent page.
     */
    public function connect(): Response
    {
        $id = $this->request->intAttribute('id');
        $source = $this->query(new GetSource($id));
        if (!is_array($source)) {
            return Response::notFound();
        }
        $type = (string) $source['type'];
        $cloud = $this->app->get(CloudProviders::class);
        if (!CloudProviders::isOAuth($type) || !$cloud->enabled($type)) {
            $this->flash('danger', 'sources.error.provider_disabled');

            return $this->redirect('/sources/' . $id);
        }
        $state = bin2hex(random_bytes(24));
        $this->session->set('oauth_connect', ['state' => $state, 'source' => $id, 'time' => time()]);

        return Response::redirect($cloud->client($type)->authorizationUrl($cloud->redirectUri(), $state));
    }

    /**
     * OAuth redirect target for all cloud providers.
     */
    public function oauthCallback(): Response
    {
        $pending = $this->session->get('oauth_connect');
        $this->session->remove('oauth_connect');
        $param = fn (string $key): string => is_string($this->request->query[$key] ?? null) ? $this->request->query[$key] : '';
        $state = $param('state');
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || $state === '' || !hash_equals($pending['state'], $state)
            || (int) ($pending['time'] ?? 0) < time() - 900) {
            $this->flash('danger', 'sources.cloud.invalid_state');

            return $this->redirect('/sources');
        }
        $id = (int) ($pending['source'] ?? 0);
        $error = $param('error_description') ?: $param('error');
        $code = $param('code');
        if ($error !== '' || $code === '') {
            $this->flash('danger', 'sources.cloud.denied', ['error' => $error !== '' ? mb_substr($error, 0, 200) : '-']);

            return $this->redirect('/sources/' . $id);
        }

        try {
            $account = (string) $this->command(new ConnectSourceAccount($id, $code));
            $this->flash('success', 'sources.cloud.connected', ['account' => $account !== '' ? $account : '-']);
        } catch (ValidationException $e) {
            $this->flash('danger', $e->getMessage(), $e->params);
        } catch (Throwable $e) {
            $this->session->flash('danger', $e->getMessage());
        }

        return $this->redirect('/sources/' . $id);
    }

    public function disconnect(): Response
    {
        $id = $this->request->intAttribute('id');
        $this->command(new DisconnectSourceAccount($id));
        $this->flash('success', 'sources.cloud.disconnected');

        return $this->redirect('/sources/' . $id);
    }

    public function delete(): Response
    {
        $this->command(new DeleteSource($this->request->intAttribute('id')));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/sources');
    }

    public function test(): Response
    {
        $source = $this->query(new GetSource($this->request->intAttribute('id'), true));
        if (!is_array($source)) {
            return Response::json(['error' => 'not_found'], 404);
        }

        try {
            $adapter = $this->app->get(StorageConnections::class)->fromConfig((string) $source['type'], $source['config'], (int) $source['id']);
            $adapter->test((string) $source['path']);
            $files = $adapter->listFiles((string) $source['path'], ($source['filter']['recursive'] ?? false) === true);
            $filter = new FileFilter($source['filter']);
            $matching = array_values(array_filter($files, static fn ($f): bool => $filter->accepts($f)));

            return Response::json([
                'ok' => true,
                'message' => $this->view->t('sources.test_ok', ['total' => count($files), 'matching' => count($matching)]),
                'files' => array_map(static fn ($f): array => ['path' => $f->path, 'size' => $f->size], array_slice($matching, 0, 20)),
            ]);
        } catch (Throwable $e) {
            return Response::json(['ok' => false, 'message' => $e->getMessage()]);
        }
    }

    public function scan(): Response
    {
        $id = $this->request->intAttribute('id');
        $results = $this->app->get(SourceScanner::class)->scanDue(true, $id);
        $result = $results[$id] ?? ['imported' => 0, 'error' => null];
        if ($result['error'] !== null) {
            $this->session->flash('danger', $result['error']);
        } else {
            $this->flash('success', 'sources.scan_done', ['count' => $result['imported']]);
            if ($result['imported'] > 0) {
                WorkerTrigger::request();
            }
        }

        return $this->redirect('/sources');
    }
}
