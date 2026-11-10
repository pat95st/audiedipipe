<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Kernel\Worker;

/**
 * Web cron endpoint for hosts without shell cron: /cron?token=...
 */
final class CronController extends Controller
{
    public function run(): Response
    {
        $expected = $this->app->config->string('worker.cron_token');
        $given = $this->request->string('token') ?: $this->request->header('x-cron-token');
        if ($expected === '' || $expected === 'CHANGE_ME' || !hash_equals($expected, $given)) {
            return Response::json(['error' => 'forbidden'], 403);
        }
        $result = (new Worker($this->app))->run();

        return Response::json(['ok' => true] + $result);
    }
}
