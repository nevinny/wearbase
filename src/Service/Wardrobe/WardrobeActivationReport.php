<?php

declare(strict_types=1);

namespace App\Service\Wardrobe;

use App\Entity\WardrobeActivationEvent;

final class WardrobeActivationReport
{
    /**
     * @param list<array{profileSubjectId:int,eventType:string,metadata:array<string,bool|string>,occurredAt:string}> $rows
     * @return array<string, mixed>
     */
    public function build(array $rows): array
    {
        $starts = [];
        $milestones = [
            WardrobeActivationEvent::FIRST_ITEM_ADDED => [],
            WardrobeActivationEvent::FIRST_OUTFIT_CREATED => [],
            WardrobeActivationEvent::REPEAT_WEAR_RECORDED => [],
        ];
        $counts = array_count_values(array_column($rows, 'eventType'));
        $drafts = array_values(array_filter($rows, static fn (array $row): bool => $row['eventType'] === WardrobeActivationEvent::DRAFT_ACCEPTED));

        foreach ($rows as $row) {
            $at = new \DateTimeImmutable($row['occurredAt']);
            $subject = $row['profileSubjectId'];
            if ($row['eventType'] === WardrobeActivationEvent::ONBOARDING_STARTED) {
                $starts[$subject] ??= $at;
            } elseif (isset($milestones[$row['eventType']])) {
                $milestones[$row['eventType']][$subject] ??= $at;
            }
        }

        $cohorts = [];
        foreach ($starts as $subject => $startedAt) {
            $day = $startedAt->format('Y-m-d');
            $cohorts[$day] ??= ['started' => 0, 'firstItem' => 0, 'firstOutfit' => 0, 'repeatWear' => 0];
            $cohorts[$day]['started']++;
            foreach ([WardrobeActivationEvent::FIRST_ITEM_ADDED => 'firstItem', WardrobeActivationEvent::FIRST_OUTFIT_CREATED => 'firstOutfit', WardrobeActivationEvent::REPEAT_WEAR_RECORDED => 'repeatWear'] as $event => $key) {
                if (isset($milestones[$event][$subject])) {
                    $cohorts[$day][$key]++;
                }
            }
        }
        ksort($cohorts);

        return [
            'subjectsStarted' => count($starts),
            'milestones' => [
                'firstItem' => $this->milestone($starts, $milestones[WardrobeActivationEvent::FIRST_ITEM_ADDED]),
                'firstOutfit' => $this->milestone($starts, $milestones[WardrobeActivationEvent::FIRST_OUTFIT_CREATED]),
                'repeatWear' => $this->milestone($starts, $milestones[WardrobeActivationEvent::REPEAT_WEAR_RECORDED]),
            ],
            'batch' => $this->ratio(
                $counts[WardrobeActivationEvent::BATCH_RECOGNITION_COMPLETED] ?? 0,
                $counts[WardrobeActivationEvent::BATCH_RECOGNITION_STARTED] ?? 0,
            ),
            'manualInput' => [
                'accepted' => count($drafts),
                'correction' => $this->flagRatio($drafts, 'correction'),
                'autofillAccepted' => $this->flagRatio($drafts, 'autofillAccepted'),
                'source' => array_count_values(array_map(static fn (array $row): string => (string) ($row['metadata']['source'] ?? 'unknown'), $drafts)),
                'durationBuckets' => array_count_values(array_map(static fn (array $row): string => (string) ($row['metadata']['durationBucket'] ?? 'unknown'), $drafts)),
            ],
            'cohorts' => $cohorts,
        ];
    }

    /** @param array<int,\DateTimeImmutable> $starts @param array<int,\DateTimeImmutable> $ends */
    private function milestone(array $starts, array $ends): array
    {
        $seconds = [];
        foreach ($starts as $subject => $start) {
            if (isset($ends[$subject]) && $ends[$subject] >= $start) {
                $seconds[] = $ends[$subject]->getTimestamp() - $start->getTimestamp();
            }
        }

        return $this->ratio(count($seconds), count($starts)) + [
            'averageSeconds' => $seconds === [] ? null : (int) round(array_sum($seconds) / count($seconds)),
        ];
    }

    /** @return array{count:int,total:int,rate:float} */
    private function ratio(int $count, int $total): array
    {
        return ['count' => $count, 'total' => $total, 'rate' => $total === 0 ? 0.0 : round($count / $total, 4)];
    }

    /** @param list<array{metadata:array<string,bool|string>}> $rows */
    private function flagRatio(array $rows, string $key): array
    {
        return $this->ratio(count(array_filter($rows, static fn (array $row): bool => ($row['metadata'][$key] ?? false) === true)), count($rows));
    }
}
