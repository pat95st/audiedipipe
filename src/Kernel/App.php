<?php

declare(strict_types=1);

namespace AudiEdiPipe\Kernel;

use AudiEdiPipe\Application\Auth\AuthService;
use AudiEdiPipe\Application\Job\JobCommandHandler;
use AudiEdiPipe\Application\Job\JobQueryHandler;
use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Application\Pipeline\Engine\PipelineRunner;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\EffectStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\ExportStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\ManualEditStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\NotifyStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\PrepareStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\PublishStage;
use AudiEdiPipe\Application\Pipeline\Engine\Stage\ReviewStage;
use AudiEdiPipe\Application\Pipeline\PipelineCommandHandler;
use AudiEdiPipe\Application\Pipeline\PipelineQueryHandler;
use AudiEdiPipe\Application\Preview\PreviewService;
use AudiEdiPipe\Application\Publish\PublishTargetHandler;
use AudiEdiPipe\Application\Settings\SettingsHandler;
use AudiEdiPipe\Application\Source\SourceCommandHandler;
use AudiEdiPipe\Application\Source\SourceQueryHandler;
use AudiEdiPipe\Application\Source\SourceScanner;
use AudiEdiPipe\Application\Template\TemplateHandler;
use AudiEdiPipe\Application\Template\TemplateRenderer;
use AudiEdiPipe\Application\User\UserCommandHandler;
use AudiEdiPipe\Application\User\UserQueryHandler;
use AudiEdiPipe\Cqrs\Command;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Cqrs\QueryBus;
use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use AudiEdiPipe\Domain\Pipeline\StageCatalog;
use AudiEdiPipe\Infrastructure\Audio\Ffmpeg;
use AudiEdiPipe\Infrastructure\Http\CurlHttpClient;
use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Mail\ConfiguredMailer;
use AudiEdiPipe\Infrastructure\Mail\Mailer;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\Migrator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Infrastructure\Publishing\PublisherFactory;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;

/**
 * Composition root: wires all services.
 */
final class App
{
    public const VERSION = '0.1.0';

    /** Message handlers registered on the buses. */
    private const HANDLERS = [
        UserCommandHandler::class,
        UserQueryHandler::class,
        PipelineCommandHandler::class,
        PipelineQueryHandler::class,
        JobCommandHandler::class,
        JobQueryHandler::class,
        SourceCommandHandler::class,
        SourceQueryHandler::class,
        TemplateHandler::class,
        PublishTargetHandler::class,
        SettingsHandler::class,
    ];

    public readonly Container $container;

    public function __construct(public readonly Config $config, public readonly string $root = APP_ROOT)
    {
        $this->container = new Container();
        $this->register();
    }

    public static function fromConfigFile(?string $file = null): self
    {
        return new self(Config::load($file ?? APP_ROOT . '/config/config.php'));
    }

    public function isInstalled(): bool
    {
        return !$this->config->isEmpty() && is_file($this->root . '/config/installed.lock');
    }

    public function storagePath(string $sub = ''): string
    {
        $base = rtrim($this->config->string('paths.storage', $this->root . '/storage'), '/');

        return $sub === '' ? $base : $base . '/' . ltrim($sub, '/');
    }

    public function url(): string
    {
        return rtrim($this->config->string('app.url', 'http://localhost'), '/');
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    public function get(string $id): object
    {
        return $this->container->get($id);
    }

    public function dispatch(Command $command): mixed
    {
        return $this->get(CommandBus::class)->dispatch($command);
    }

    private function register(): void
    {
        $c = $this->container;
        $c->instance(self::class, $this);
        $c->instance(Config::class, $this->config);

        $c->set(Database::class, fn (): Database => Database::fromConfig((array) $this->config->get('database', [])));
        $c->set(Migrator::class, fn (Container $c): Migrator => new Migrator($c->get(Database::class), $this->root . '/migrations'));
        $c->set(SettingsRepository::class, static fn (Container $c): SettingsRepository => new SettingsRepository($c->get(Database::class)));
        $c->set(Crypto::class, fn (): Crypto => new Crypto($this->config->string('app.key', 'insecure-default-key')));
        $c->set(Translator::class, fn (): Translator => new Translator($this->root . '/lang', $this->config->string('app.default_locale', 'en')));
        $c->set(ProcessRunner::class, static fn (): ProcessRunner => new ProcessRunner());
        $c->set(HttpClient::class, static fn (): HttpClient => new CurlHttpClient());
        $c->set(Ffmpeg::class, fn (Container $c): Ffmpeg => new Ffmpeg(
            $c->get(ProcessRunner::class),
            $this->config->string('paths.ffmpeg', 'ffmpeg'),
            $this->config->string('paths.ffprobe', 'ffprobe'),
        ));
        $c->set(EffectRegistry::class, static fn (): EffectRegistry => EffectRegistry::default());
        $c->set(StageCatalog::class, static fn (Container $c): StageCatalog => new StageCatalog($c->get(EffectRegistry::class)));
        $c->set(Mailer::class, fn (Container $c): Mailer => new ConfiguredMailer(
            $c->get(SettingsRepository::class),
            $c->get(Crypto::class),
            $this->storagePath('logs/mail.log'),
        ));
        $c->set(Notifier::class, fn (Container $c): Notifier => new Notifier(
            $c->get(Database::class),
            $c->get(Mailer::class),
            $c->get(Translator::class),
            $c->get(SettingsRepository::class),
            $this->url(),
        ));
        $c->set(AuthService::class, static fn (Container $c): AuthService => new AuthService(
            $c->get(Database::class),
            $c->get(Crypto::class),
            $c->get(Notifier::class),
            $c->get(Translator::class),
        ));
        $c->set(StorageFactory::class, fn (Container $c): StorageFactory => new StorageFactory(
            $c->get(HttpClient::class),
            $c->get(ProcessRunner::class),
            $this->config->string('paths.smbclient', 'smbclient'),
        ));
        $c->set(StorageConnections::class, static fn (Container $c): StorageConnections => new StorageConnections(
            $c->get(Database::class),
            $c->get(Crypto::class),
            $c->get(StorageFactory::class),
        ));
        $c->set(PublisherFactory::class, fn (Container $c): PublisherFactory => new PublisherFactory(
            $c->get(HttpClient::class),
            $c->get(Database::class),
            $this->storagePath('media'),
            $this->url(),
            static fn (int $id) => $c->get(StorageConnections::class)->adapter($id),
        ));
        $c->set(PreviewService::class, fn (Container $c): PreviewService => new PreviewService(
            $c->get(Ffmpeg::class),
            $c->get(EffectRegistry::class),
            $this->storagePath('preview'),
        ));
        $c->set(TemplateRenderer::class, static fn (Container $c): TemplateRenderer => new TemplateRenderer($c->get(Database::class)));

        // CQRS handlers
        $c->set(UserCommandHandler::class, static fn (Container $c): UserCommandHandler => new UserCommandHandler($c->get(Database::class), $c->get(Crypto::class)));
        $c->set(UserQueryHandler::class, static fn (Container $c): UserQueryHandler => new UserQueryHandler($c->get(Database::class)));
        $c->set(PipelineCommandHandler::class, static fn (Container $c): PipelineCommandHandler => new PipelineCommandHandler($c->get(Database::class), $c->get(StageCatalog::class)));
        $c->set(PipelineQueryHandler::class, static fn (Container $c): PipelineQueryHandler => new PipelineQueryHandler($c->get(Database::class)));
        $c->set(JobCommandHandler::class, fn (Container $c): JobCommandHandler => new JobCommandHandler($c->get(Database::class), $this->storagePath('work'), $c->get(Notifier::class)));
        $c->set(JobQueryHandler::class, static fn (Container $c): JobQueryHandler => new JobQueryHandler($c->get(Database::class)));
        $c->set(SourceCommandHandler::class, static fn (Container $c): SourceCommandHandler => new SourceCommandHandler($c->get(Database::class), $c->get(Crypto::class)));
        $c->set(SourceQueryHandler::class, static fn (Container $c): SourceQueryHandler => new SourceQueryHandler($c->get(Database::class), $c->get(Crypto::class)));
        $c->set(TemplateHandler::class, static fn (Container $c): TemplateHandler => new TemplateHandler($c->get(Database::class), $c->get(EffectRegistry::class)));
        $c->set(PublishTargetHandler::class, static fn (Container $c): PublishTargetHandler => new PublishTargetHandler($c->get(Database::class), $c->get(Crypto::class)));
        $c->set(SettingsHandler::class, static fn (Container $c): SettingsHandler => new SettingsHandler($c->get(SettingsRepository::class), $c->get(Crypto::class)));

        $c->set(CommandBus::class, static function (Container $c): CommandBus {
            $bus = new CommandBus($c->get(Database::class));
            self::registerHandlers($c, $bus, null);

            return $bus;
        });
        $c->set(QueryBus::class, static function (Container $c): QueryBus {
            $bus = new QueryBus();
            self::registerHandlers($c, null, $bus);

            return $bus;
        });

        $c->set(PipelineRunner::class, fn (Container $c): PipelineRunner => new PipelineRunner(
            $c->get(Database::class),
            [
                new PrepareStage($c->get(Ffmpeg::class)),
                new EffectStage($c->get(EffectRegistry::class), $c->get(Ffmpeg::class)),
                new ManualEditStage($c->get(Ffmpeg::class), $c->get(TemplateRenderer::class)),
                new ReviewStage(),
                new NotifyStage($c->get(Notifier::class), $c->get(TemplateRenderer::class), $this->url()),
                new ExportStage(
                    $c->get(Ffmpeg::class),
                    $c->get(TemplateRenderer::class),
                    $c->get(StorageConnections::class),
                    $this->storagePath('output'),
                    $this->root . '/public',
                ),
                new PublishStage($c->get(Database::class), $c->get(Crypto::class), $c->get(PublisherFactory::class)),
            ],
            $this->storagePath('work'),
            $c->get(Notifier::class),
        ));
        $c->set(SourceScanner::class, fn (Container $c): SourceScanner => new SourceScanner(
            $c->get(Database::class),
            $c->get(StorageConnections::class),
            $c->get(CommandBus::class),
            $this->storagePath('uploads'),
            $c->get(Notifier::class),
        ));
    }

    private static function registerHandlers(Container $c, ?CommandBus $commands, ?QueryBus $queries): void
    {
        foreach (self::HANDLERS as $class) {
            /** @var HandlerProvider $handler */
            $handler = $c->get($class);
            foreach ($class::handledMessages() as $message => $method) {
                $callable = [$handler, $method];
                if (!is_callable($callable)) {
                    continue;
                }
                if (is_subclass_of($message, Command::class)) {
                    $commands?->register($message, $callable);
                } else {
                    /** @var class-string<\AudiEdiPipe\Cqrs\Query> $message */
                    $queries?->register($message, $callable);
                }
            }
        }
    }
}
