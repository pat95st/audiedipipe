<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Application\Job\Query\DashboardStats;
use AudiEdiPipe\Application\Job\Query\ListJobs;
use AudiEdiPipe\Http\Response;

final class DashboardController extends Controller
{
    public function index(): Response
    {
        $myStatuses = [];
        if ($this->can('jobs.edit')) {
            $myStatuses[] = 'waiting_manual';
        }
        if ($this->can('jobs.review')) {
            $myStatuses[] = 'waiting_review';
        }

        return $this->render('dashboard/index', [
            'title' => $this->view->t('nav.dashboard'),
            'stats' => $this->query(new DashboardStats()),
            'recent' => $this->query(new ListJobs(null, null, 10))['items'],
            'todo' => $myStatuses === [] ? [] : $this->query(new ListJobs(null, null, 20, 0, $myStatuses))['items'],
        ]);
    }
}
