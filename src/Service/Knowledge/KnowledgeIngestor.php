<?php

namespace App\Service\Knowledge;

use App\Service\EmbeddingService;
use App\Service\TextChunker;
use App\Service\VectorStoreService;
use Symfony\Component\Uid\Uuid;

/**
 * Общая логика ингеста документа базы знаний (транскрипт видео / пост канала)
 * в Qdrant `topic_chunks`: чанкинг → эмбеддинг → upsert. Вынесена из
 * IngestKnowledgeChannelsCommand, чтобы role-карта и UUID-namespace не расходились
 * между разовым ингестом транскриптов (app:kb:ingest-channels) и регулярным
 * инкрементом TG-каналов (app:kb:sync-tg).
 *
 * ID точки детерминирован (UUIDv5 от channel:doc_id:chunk_index) → повторный
 * прогон делает upsert, а не дубли.
 *
 * $vectors — инстанс app.vector_store.topic (коллекция topic_chunks), см. services.yaml.
 */
class KnowledgeIngestor
{
    private const EMBED_BATCH = 32;

    // Своё namespace для UUIDv5 точек topic_chunks (стабильный, не пересекается с brand_chunks).
    private const ID_NAMESPACE = 'b3d5c1a2-7e4f-5c9a-9b21-8f0e1d2c3a4b';

    /** Роль чанка в payload — определяется каналом-источником. */
    private const ROLE_MAP = [
        'grebenukm'          => 'idea',
        'dolgov_alexandr'    => 'idea',
        'mtokovinin'         => 'framing',
        'AlexanderSokolovskiy' => 'case',
        'FedotovM'           => 'tone',
        'drmaxseo'           => 'seo',
        'freychu'            => 'seo',
        'big_bad_coach'      => 'seo',
    ];

    public function __construct(
        private readonly EmbeddingService   $embedder,
        private readonly VectorStoreService $vectors,   // инстанс, привязанный к topic_chunks (services.yaml)
        private readonly TextChunker        $chunker,
    ) {
    }

    /** @return string[] каналы, известные role-карте */
    public function channels(): array
    {
        return array_keys(self::ROLE_MAP);
    }

    public function roleFor(string $channel): ?string
    {
        return self::ROLE_MAP[$channel] ?? null;
    }

    public function ensureCollection(): void
    {
        $this->vectors->ensureCollection();
    }

    public function dropCollection(): void
    {
        $this->vectors->dropCollection();
    }

    /**
     * Режет документ на чанки, эмбеддит по одному (устойчиво к сбою на мусорном
     * чанке) и грузит батчами в Qdrant. Канал обязан быть в role-карте.
     *
     * @return array{chunks:int,points:int,skipped:int}
     */
    public function ingestDocument(string $channel, string $docId, string $text): array
    {
        $role = $this->roleFor($channel);
        if ($role === null) {
            throw new \InvalidArgumentException("Неизвестный канал «{$channel}», нет в role-карте");
        }

        $pieces = $this->chunker->chunk($text);
        if ($pieces === []) {
            return ['chunks' => 0, 'points' => 0, 'skipped' => 0];
        }

        $points  = 0;
        $skipped = 0;
        $batch   = [];

        foreach ($pieces as $idx => $piece) {
            try {
                $vec = $this->embedder->embed($piece);
            } catch (\Throwable) {
                $skipped++;
                continue;
            }
            $batch[] = [
                'id'      => $this->pointId($channel, $docId, $idx),
                'vector'  => $vec,
                'payload' => [
                    'channel'     => $channel,
                    'video_id'    => $docId,
                    'role'        => $role,
                    'chunk_index' => $idx,
                    'text'        => $piece,
                ],
            ];
            if (count($batch) >= self::EMBED_BATCH) {
                $this->vectors->upsertPoints($batch);
                $points += count($batch);
                $batch = [];
            }
        }
        if ($batch !== []) {
            $this->vectors->upsertPoints($batch);
            $points += count($batch);
        }

        return ['chunks' => count($pieces), 'points' => $points, 'skipped' => $skipped];
    }

    private function pointId(string $channel, string $docId, int $chunkIndex): string
    {
        return (string) Uuid::v5(
            Uuid::fromString(self::ID_NAMESPACE),
            "{$channel}:{$docId}:{$chunkIndex}",
        );
    }
}
