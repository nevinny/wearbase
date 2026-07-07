<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use App\Service\EmbeddingService;
use App\Service\VectorStoreService;

/**
 * Ретрив бизнес-принципов из базы знаний каналов (Qdrant `topic_chunks`) под сигнал
 * советника (docs/advisor.md §«Роли каналов», шаг 2 цикла идей). Эмбеддит запрос тем же
 * эмбеддером, что ингест, и достаёт топ-k чанков нужных ролей с provenance (канал/видео).
 *
 * Роли (по каналу-источнику, см. IngestKnowledgeChannelsCommand::ROLE_MAP):
 *   idea/framing/case — подмешиваются в генерацию идей;
 *   tone (Федотов) — только стиль подачи, в идеи НЕ идёт (toneExemplars() — на будущее).
 *
 * $vectors — инстанс app.vector_store.topic (коллекция topic_chunks), внедряется в services.yaml.
 */
final class AdvisorRag
{
    /** Роли-источники идей (tone намеренно исключён). */
    public const IDEA_ROLES = ['idea', 'framing', 'case'];

    /** Человекочитаемые имена каналов для провенанс-пометки в дайджесте. */
    private const CHANNEL_NAMES = [
        'grebenukm'            => 'Гребенюк',
        'dolgov_alexandr'      => 'Долгов',
        'mtokovinin'           => 'Токовинин',
        'AlexanderSokolovskiy' => 'Соколовский',
        'FedotovM'             => 'Федотов',
    ];

    public function __construct(
        private readonly EmbeddingService $embedder,
        private readonly VectorStoreService $vectors,
    ) {
    }

    /**
     * @param string[] $roles фильтр по роли чанка (пусто → все роли)
     * @return list<array{channel:string,role:string,video_id:string,text:string,score:float}>
     */
    public function retrieve(string $query, array $roles = [], int $k = 6): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $qvec = $this->embedder->embed($query);
        $hits = $this->vectors->searchByRoles($qvec, $roles, $k);

        $out = [];
        foreach ($hits as $hit) {
            $p    = $hit['payload'] ?? [];
            $text = trim((string) ($p['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $out[] = [
                'channel'  => (string) ($p['channel'] ?? ''),
                'role'     => (string) ($p['role'] ?? ''),
                'video_id' => (string) ($p['video_id'] ?? ''),
                'text'     => $text,
                'score'    => (float) ($hit['score'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Нумерованный контекст для промпта: «#N [Канал · роль]: текст». Метка #N — то, чем
     * модель цитирует принцип в rag_citations (провенанс в дайджесте).
     *
     * @param list<array{channel:string,role:string,video_id:string,text:string,score:float}> $chunks
     */
    public function formatContext(array $chunks, int $maxChars = 5000): string
    {
        $blocks = [];
        $total  = 0;
        foreach ($chunks as $i => $c) {
            $label = self::CHANNEL_NAMES[$c['channel']] ?? ($c['channel'] ?: '—');
            $block = sprintf('#%d [%s · %s]: %s', $i + 1, $label, $c['role'], trim($c['text']));
            if ($total + mb_strlen($block) > $maxChars) {
                break;
            }
            $blocks[] = $block;
            $total   += mb_strlen($block);
        }

        return implode("\n\n", $blocks);
    }

    public static function channelName(string $channel): string
    {
        return self::CHANNEL_NAMES[$channel] ?? $channel;
    }
}
