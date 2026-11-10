<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Source\Command\DeleteSource;
use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\Source\Query\GetSource;
use AudiEdiPipe\Application\Source\Query\ListSources;
use AudiEdiPipe\Application\Source\SourceScanner;
use AudiEdiPipe\Http\Response;
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

        return $this->render('sources/form', [
            'title' => $source === null ? $this->view->t('sources.new') : (string) $source['name'],
            'source' => $source ?? [
                'id' => null, 'name' => '', 'type' => 'local', 'config' => [], 'path' => '/',
                'filter' => ['extensions' => 'wav,mp3,flac,m4a,aac,ogg,opus,aiff', 'min_age_seconds' => 60],
                'poll_interval' => 300, 'after_import' => 'keep', 'enabled' => true,
            ],
            'types' => StorageFactory::TYPES,
            'fields' => StorageFactory::FIELDS,
            'secrets' => StorageFactory::SECRET_FIELDS,
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
            $adapter = $this->app->get(StorageConnections::class)->fromConfig((string) $source['type'], $source['config']);
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
