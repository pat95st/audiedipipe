<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Preview\PreviewService;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Http\Response;
use Throwable;

/**
 * "Try it with your own file": upload a test file once and render effect
 * previews directly from the effect GUIs.
 */
final class PreviewController extends Controller
{
    public function upload(): Response
    {
        $file = $this->request->file('file');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('upload.error.failed', ['code' => $file['error'] ?? UPLOAD_ERR_NO_FILE]);
        }
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, JobController::ALLOWED_EXTENSIONS, true)) {
            throw new ValidationException('upload.error.type');
        }
        $offset = max(0.0, (float) $this->request->input('offset', 0));
        $result = $this->app->get(PreviewService::class)->import($file['tmp_name'], $offset);

        return Response::json($result + [
            'name' => $file['name'],
            'source' => $this->view->url('/preview/' . $result['token'] . '/source.mp3'),
        ]);
    }

    public function render(): Response
    {
        $data = $this->request->json();
        $chain = is_array($data['chain'] ?? null) ? array_values(array_filter($data['chain'], 'is_array')) : [];
        $token = (string) ($data['token'] ?? '');
        try {
            $result = $this->app->get(PreviewService::class)->render($token, $chain);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return Response::json(['error' => $e->getMessage()], 500);
        }

        return Response::json([
            'url' => $this->view->url('/preview/' . $token . '/' . $result['file']),
            'log' => $result['log'],
        ]);
    }

    public function file(): Response
    {
        $path = $this->app->get(PreviewService::class)->path($this->request->attribute('token'), $this->request->attribute('file'));
        if ($path === null) {
            return Response::notFound();
        }

        return Response::file($path, 'audio/mpeg', $this->request->header('range') ?: null);
    }
}
