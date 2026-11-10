<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Template;

use AudiEdiPipe\Application\Job\JobCommandHandler;
use AudiEdiPipe\Application\Template\Command\DeletePreset;
use AudiEdiPipe\Application\Template\Command\DeleteTemplate;
use AudiEdiPipe\Application\Template\Command\SavePreset;
use AudiEdiPipe\Application\Template\Command\SaveTemplate;
use AudiEdiPipe\Application\Template\Query\GetTemplate;
use AudiEdiPipe\Application\Template\Query\ListPresets;
use AudiEdiPipe\Application\Template\Query\ListTemplates;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Domain\Audio\Effect\EffectRegistry;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Support\Json;

/**
 * Metadata templates and effect presets (write and read side).
 */
final class TemplateHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db, private readonly EffectRegistry $effects)
    {
    }

    public static function handledMessages(): array
    {
        return [
            SaveTemplate::class => 'saveTemplate',
            DeleteTemplate::class => 'deleteTemplate',
            SavePreset::class => 'savePreset',
            DeletePreset::class => 'deletePreset',
            ListTemplates::class => 'listTemplates',
            GetTemplate::class => 'getTemplate',
            ListPresets::class => 'listPresets',
        ];
    }

    public function saveTemplate(SaveTemplate $command): int
    {
        if (trim($command->name) === '') {
            throw new ValidationException('templates.error.name_required');
        }
        $fields = [];
        foreach (JobCommandHandler::METADATA_FIELDS as $field) {
            $fields[$field] = mb_substr(trim((string) ($command->fields[$field] ?? '')), 0, 5000);
        }
        $fields['cover'] = mb_substr(trim((string) ($command->fields['cover'] ?? '')), 0, 1000);
        $data = ['name' => mb_substr(trim($command->name), 0, 190), 'fields' => Json::encode($fields), 'updated_at' => Database::now()];
        if ($command->id !== null) {
            $this->db->update('metadata_templates', $data, ['id' => $command->id]);

            return $command->id;
        }
        $data['created_at'] = Database::now();

        return $this->db->insert('metadata_templates', $data);
    }

    public function deleteTemplate(DeleteTemplate $command): void
    {
        $this->db->execute('UPDATE pipelines SET metadata_template_id = NULL WHERE metadata_template_id = ?', [$command->id]);
        $this->db->delete('metadata_templates', ['id' => $command->id]);
    }

    public function savePreset(SavePreset $command): int
    {
        if (!$this->effects->has($command->effectType)) {
            throw new ValidationException('presets.error.invalid_effect');
        }
        if (trim($command->name) === '') {
            throw new ValidationException('presets.error.name_required');
        }
        $data = [
            'effect_type' => $command->effectType,
            'name' => mb_substr(trim($command->name), 0, 190),
            'params' => Json::encode($this->effects->get($command->effectType)->normalize($command->params)),
            'updated_at' => Database::now(),
        ];
        if ($command->id !== null) {
            $this->db->update('effect_presets', $data, ['id' => $command->id, 'builtin' => 0]);

            return $command->id;
        }
        $data['builtin'] = false;
        $data['created_at'] = Database::now();

        return $this->db->insert('effect_presets', $data);
    }

    public function deletePreset(DeletePreset $command): void
    {
        $this->db->delete('effect_presets', ['id' => $command->id, 'builtin' => 0]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTemplates(ListTemplates $query): array
    {
        return array_map(static function (array $row): array {
            $row['fields'] = Json::decode($row['fields']);

            return $row;
        }, $this->db->fetchAll('SELECT * FROM metadata_templates ORDER BY name'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTemplate(GetTemplate $query): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM metadata_templates WHERE id = ?', [$query->id]);
        if ($row !== null) {
            $row['fields'] = Json::decode($row['fields']);
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPresets(ListPresets $query): array
    {
        $rows = $query->effectType === null
            ? $this->db->fetchAll('SELECT * FROM effect_presets ORDER BY effect_type, builtin DESC, name')
            : $this->db->fetchAll('SELECT * FROM effect_presets WHERE effect_type = ? ORDER BY builtin DESC, name', [$query->effectType]);

        return array_map(static function (array $row): array {
            $row['params'] = Json::decode($row['params']);
            $row['builtin'] = (bool) $row['builtin'];

            return $row;
        }, $rows);
    }
}
