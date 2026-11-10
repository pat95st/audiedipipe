<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Job\JobCommandHandler;
use AudiEdiPipe\Application\Template\Command\DeletePreset;
use AudiEdiPipe\Application\Template\Command\DeleteTemplate;
use AudiEdiPipe\Application\Template\Command\SavePreset;
use AudiEdiPipe\Application\Template\Command\SaveTemplate;
use AudiEdiPipe\Application\Template\Query\ListPresets;
use AudiEdiPipe\Application\Template\Query\ListTemplates;
use AudiEdiPipe\Application\Template\TemplateRenderer;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Http\Response;

final class TemplateController extends Controller
{
    public function index(): Response
    {
        return $this->render('templates/index', [
            'title' => $this->view->t('nav.templates'),
            'templates' => $this->query(new ListTemplates()),
            'fields' => JobCommandHandler::METADATA_FIELDS,
            'placeholders' => TemplateRenderer::PLACEHOLDERS,
        ]);
    }

    public function save(): Response
    {
        $this->command(new SaveTemplate($this->nullableInt('id'), $this->request->string('name'), $this->arrayInput('fields')));
        $this->flash('success', 'common.saved');

        return $this->redirect('/templates');
    }

    public function delete(): Response
    {
        $this->command(new DeleteTemplate($this->request->intAttribute('id')));
        $this->flash('success', 'common.deleted');

        return $this->redirect('/templates');
    }

    public function uploadCover(): Response
    {
        $file = $this->request->file('cover');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('upload.error.failed', ['code' => $file['error'] ?? UPLOAD_ERR_NO_FILE]);
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true)) {
            throw new ValidationException('upload.error.image');
        }
        $ext = $info[2] === IMAGETYPE_PNG ? 'png' : 'jpg';
        $dir = $this->app->root . '/public/uploads/covers';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $name = bin2hex(random_bytes(8)) . '.' . $ext;
        $moved = is_uploaded_file($file['tmp_name']) ? move_uploaded_file($file['tmp_name'], $dir . '/' . $name) : @rename($file['tmp_name'], $dir . '/' . $name);
        if (!$moved) {
            throw new ValidationException('upload.error.failed', ['code' => 'move']);
        }

        return Response::json(['path' => 'uploads/covers/' . $name, 'url' => $this->view->url('uploads/covers/' . $name)]);
    }

    public function presets(): Response
    {
        $type = $this->request->string('type');

        return Response::json($this->query(new ListPresets($type !== '' ? $type : null)));
    }

    public function savePreset(): Response
    {
        $data = $this->request->json();
        $id = $this->command(new SavePreset(
            is_numeric($data['id'] ?? null) ? (int) $data['id'] : null,
            (string) ($data['effect_type'] ?? ''),
            (string) ($data['name'] ?? ''),
            is_array($data['params'] ?? null) ? $data['params'] : [],
        ));

        return Response::json(['id' => $id]);
    }

    public function deletePreset(): Response
    {
        $this->command(new DeletePreset($this->request->intAttribute('id')));

        return Response::json(['ok' => true]);
    }
}
