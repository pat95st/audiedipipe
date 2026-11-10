<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

interface Publisher
{
    /**
     * @throws PublishException
     */
    public function publish(Episode $episode): PublishResult;
}
