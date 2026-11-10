<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Publish\Query\GetFeed;
use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\Publishing\RssFeedRenderer;

/**
 * Public podcast feed and media files.
 */
final class FeedController extends Controller
{
    public function feed(): Response
    {
        $slug = preg_replace('/\.(xml|rss)$/', '', $this->request->attribute('slug')) ?? '';
        $feed = $this->query(new GetFeed($slug));
        if (!is_array($feed)) {
            return Response::notFound();
        }
        $xml = (new RssFeedRenderer())->render($feed['target']['config'], $feed['items'], $this->app->url() . '/feed/' . $slug);

        return Response::text($xml, 200, 'application/rss+xml; charset=utf-8')->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function media(): Response
    {
        $slug = basename($this->request->attribute('slug'));
        $file = basename($this->request->attribute('file'));
        $path = $this->app->storagePath('media/' . $slug . '/' . $file);
        if ($slug === '' || $file === '' || !is_file($path)) {
            return Response::notFound();
        }
        $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'ogg', 'opus' => 'audio/ogg',
            'flac' => 'audio/flac',
            'wav' => 'audio/wav',
            default => 'application/octet-stream',
        };

        return Response::file($path, $mime, $this->request->header('range') ?: null)->withHeader('Cache-Control', 'public, max-age=86400');
    }
}
