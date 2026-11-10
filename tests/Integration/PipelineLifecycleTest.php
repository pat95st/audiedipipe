<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Integration;

use AudiEdiPipe\Application\Pipeline\Command\DeletePipeline;
use AudiEdiPipe\Application\Pipeline\Command\SavePipeline;
use AudiEdiPipe\Application\Pipeline\Query\GetPipeline;
use AudiEdiPipe\Application\Pipeline\Query\ListPipelines;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Cqrs\QueryBus;
use AudiEdiPipe\Tests\Support\TestCase;

final class PipelineLifecycleTest extends TestCase
{
    public function test_save_read_and_delete_a_pipeline_with_effect_stages(): void
    {
        $app = $this->installedApp();
        $commands = $app->get(CommandBus::class);
        $queries = $app->get(QueryBus::class);

        $id = $commands->dispatch(new SavePipeline(
            id: null,
            name: 'Podcast master',
            description: 'noise + low cut + leveler',
            enabled: true,
            sourceId: null,
            metadataTemplateId: null,
            stages: [
                ['type' => 'low_cut', 'params' => ['frequency' => 90, 'slope' => 24]],
                ['type' => 'master_leveler', 'params' => []],
            ],
            notifications: [],
        ));
        self::assertGreaterThan(0, $id);

        $pipeline = $queries->ask(new GetPipeline($id));
        self::assertNotNull($pipeline);
        self::assertSame('Podcast master', $pipeline['name']);

        self::assertCount(1, $queries->ask(new ListPipelines()));

        $commands->dispatch(new DeletePipeline($id));
        self::assertCount(0, $queries->ask(new ListPipelines()));
    }

    public function test_pipeline_without_name_is_rejected(): void
    {
        $app = $this->installedApp();
        $commands = $app->get(CommandBus::class);

        $this->expectException(ValidationException::class);
        $commands->dispatch(new SavePipeline(
            id: null, name: '   ', description: '', enabled: true,
            sourceId: null, metadataTemplateId: null, stages: [], notifications: [],
        ));
    }
}
