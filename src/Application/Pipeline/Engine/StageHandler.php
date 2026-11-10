<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine;

interface StageHandler
{
    public function supports(string $type): bool;

    public function run(StageContext $context): StageOutcome;
}
