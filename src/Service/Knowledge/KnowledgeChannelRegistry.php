<?php

namespace App\Service\Knowledge;

use Symfony\Component\Yaml\Yaml;

/**
 * Единый реестр каналов базы знаний советника — конфиг config/knowledge/channels.yaml
 * (role для чанкинга/фильтра ретрива + человекочитаемое name для провенанса в дайджесте).
 * Добавление канала = правка YAML + крон-строка, без трогания PHP
 * (KnowledgeIngestor, AdvisorRag читают отсюда).
 */
class KnowledgeChannelRegistry
{
    /** @var array<string,array{role:string,name:string}>|null */
    private ?array $channels = null;

    public function __construct(
        private readonly string $yamlPath,
    ) {
    }

    public function roleFor(string $handle): ?string
    {
        return $this->all()[$handle]['role'] ?? null;
    }

    public function nameFor(string $handle): ?string
    {
        return $this->all()[$handle]['name'] ?? null;
    }

    /** @return string[] */
    public function channels(): array
    {
        return array_keys($this->all());
    }

    /** @return array<string,array{role:string,name:string}> */
    public function all(): array
    {
        if ($this->channels === null) {
            $parsed = Yaml::parseFile($this->yamlPath);
            $this->channels = is_array($parsed['channels'] ?? null) ? $parsed['channels'] : [];
        }

        return $this->channels;
    }
}
