<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Application\Template\TemplateRenderer;

final class NotifyStage implements StageHandler
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly TemplateRenderer $templates,
        private readonly string $appUrl,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'notify';
    }

    public function run(StageContext $context): StageOutcome
    {
        $vars = $this->templates->variables($context->jobId(), $context->metadata);
        $vars['link'] = rtrim($this->appUrl, '/') . '/jobs/' . $context->jobId();
        $sent = $this->notifier->custom($context->params, $vars);
        $context->log(sprintf('%d notification(s) sent.', $sent));
        foreach ($this->notifier->errors() as $error) {
            $context->log('Mail error: ' . $error);
        }

        // Mail problems must not block the audio pipeline.
        return StageOutcome::success();
    }
}
