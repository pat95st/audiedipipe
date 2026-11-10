<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Publish\Command\DeletePublishTarget;
use AudiEdiPipe\Application\Publish\Command\SavePublishTarget;
use AudiEdiPipe\Application\Publish\Query\GetPublishTarget;
use AudiEdiPipe\Application\Publish\Query\ListPublishTargets;
use AudiEdiPipe\Application\Source\Query\ListSources;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\Publishing\PublisherFactory;

final class PublishController extends Controller
{
    public function index(): Response
    {
        return $this->render('publish/index', [
            'title' => $this->view->t('nav.publish'),
            'targets' => $this->query(new ListPublishTargets()),
            'appUrl' => $this->app->url(),
        ]);
    }

    public function form(): Response
    {
        $id = $this->request->intAttribute('id');
        $target = $id > 0 ? $this->query(new GetPublishTarget($id)) : null;
        if ($id > 0 && !is_array($target)) {
            return Response::notFound();
        }

        return $this->render('publish/form', [
            'title' => $target === null ? $this->view->t('publish.new') : (string) $target['name'],
            'target' => $target ?? ['id' => null, 'name' => '', 'type' => 'rss', 'config' => ['language' => $this->view->locale(), 'explicit' => '0'], 'enabled' => true],
            'types' => PublisherFactory::TYPES,
            'fields' => PublisherFactory::FIELDS,
            'secrets' => PublisherFactory::SECRET_FIELDS,
            'sources' => $this->query(new ListSources()),
            'appUrl' => $this->app->url(),
        ]);
    }

    public function save(): Response
    {
        $id = (int) $this->command(new SavePublishTarget(
            $this->nullableInt('id'),
            $this->request->string('name'),
            $this->request->string('type'),
            $this->arrayInput('config'),
            $this->request->bool('enabled'),
        ));
        $this->flash('success', 'common.saved');

        return $this->redirect('/publish/' . $id);
    }

    public function delete(): Response
    {
        $this->command(new DeletePublishTarget($this->request->intAttribute('id')));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/publish');
    }
}
