<?php

declare(strict_types=1);

namespace App\Service\Advisor;

use App\Entity\AdvisorIdea;
use App\Repository\AdvisorIdeaRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Исполнительный контур советника, шаг 4 «Выбор» (docs/advisor.md §Цикл идей, §Таксономия).
 * Детерминированно (БЕЗ LLM) классифицирует proposed-идеи по риску a|b|c и решает их судьбу:
 *   - класс a (контент)  → approved (уходит контент-исполнителю Фазы B);
 *   - класс b (код)      → approved под WIP-cap (в очередь код-воркеру Фазы B), иначе ждёт цикла;
 *   - класс c (человек)  → остаётся proposed + needs_human (fail-closed: деньги/юр/security/…);
 *   - ICE < ICE_FLOOR    → rejected (не засоряем очередь мелочью).
 *
 * Fail-closed: пустой/неоднозначный текст → класс c. HALT-файл var/agent/HALT → no-op
 * (docs/advisor.md §Kill-switch: тишина = стоп, не работа).
 */
class DecisionMaker
{
    /** Ниже этого ICE идея отклоняется как недостаточно ценная. */
    public const ICE_FLOOR = 60;

    /** Максимум идей одновременно «в работе» (in_progress + approved класса b). */
    public const MAX_IN_WORK = 1;

    /**
     * Класс c (НИКОГДА без человека): платежи/заказы/подписки/оферты/юр, security/firewall/env/секреты,
     * миграции/удаление, массовые рассылки, sitemap/hreflang/robots/.htaccess/редиректы.
     */
    private const RE_CLASS_C = '/плат[её]ж|payment|заказ|order|подписк|subscription|оферт|offer|юр\w*лиц|security|firewall|\.env|секрет|secret|миграци|migration|удал|delete|рассылк|email.?blast|массов\w+ письм|sitemap|hreflang|robots|\.htaccess|редирект|redirect/iu';

    /** Класс a (контент/SEO, авто): статьи/блог/FAQ/ключевики/мета/описания/лендинги. */
    private const RE_CLASS_A = '/контент|content|стать|blog|блог|faq|ключевик|keyword|мета|meta|seo.?текст|описани|description|лендинг|посадочн/iu';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AdvisorIdeaRepository $ideas,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * Прогнать все proposed-идеи (ICE DESC) через классификатор и пороги.
     * При $dryRun изменения проставляются в managed-сущности, но НЕ флашатся (откат — конец процесса).
     *
     * @return array{
     *   halted: bool,
     *   processed: int,
     *   wip_used: int,
     *   decisions: list<array{id:int|null, title:string, class:string, status:string, reason:string}>
     * }
     */
    public function decide(bool $dryRun = false): array
    {
        if ($this->isHalted()) {
            return ['halted' => true, 'processed' => 0, 'wip_used' => 0, 'decisions' => []];
        }

        $proposed = $this->ideas->createQueryBuilder('i')
            ->andWhere('i.status = :s')
            ->setParameter('s', AdvisorIdea::STATUS_PROPOSED)
            ->orderBy('i.iceScore', 'DESC')
            ->getQuery()
            ->getResult();

        $wipUsed    = $this->countInWork();
        $decisions  = [];

        foreach ($proposed as $idea) {
            /** @var AdvisorIdea $idea */
            $class = $this->classify(
                (string) $idea->getHypothesis() . ' ' . (string) $idea->getSourceSignal(),
            );
            $idea->setActionClass($class);
            $idea->setNeedsHuman($class === AdvisorIdea::CLASS_HUMAN);

            $reason = '';

            if ($idea->getIceScore() < self::ICE_FLOOR) {
                $idea->setStatus(AdvisorIdea::STATUS_REJECTED);
                $idea->setRejectedReason('ICE ниже порога');
                $reason = sprintf('ICE %d < %d', $idea->getIceScore(), self::ICE_FLOOR);
            } elseif ($class === AdvisorIdea::CLASS_CONTENT) {
                $idea->setStatus(AdvisorIdea::STATUS_APPROVED);
                $reason = 'контент → в авто-исполнение';
            } elseif ($class === AdvisorIdea::CLASS_HUMAN) {
                // остаётся proposed + needs_human — на ревью владельцу
                $reason = 'класс c → ревью человека';
            } else { // CLASS_CODE
                if ($wipUsed < self::MAX_IN_WORK) {
                    $idea->setStatus(AdvisorIdea::STATUS_APPROVED);
                    ++$wipUsed;
                    $reason = 'код → в очередь воркеру';
                } else {
                    // остаётся proposed — подождёт следующего цикла
                    $reason = sprintf('WIP-cap %d занят → ждёт цикла', self::MAX_IN_WORK);
                }
            }

            $decisions[] = [
                'id'     => $idea->getId(),
                'title'  => (string) $idea->getTitle(),
                'class'  => $class,
                'status' => $idea->getStatus(),
                'reason' => $reason,
            ];
        }

        if (!$dryRun) {
            $this->em->flush();
        }

        return [
            'halted'    => false,
            'processed' => count($proposed),
            'wip_used'  => $wipUsed,
            'decisions' => $decisions,
        ];
    }

    /**
     * Детерминированная классификация по тексту гипотезы+сигнала. Порядок каскада важен:
     * сперва высокорисковый c (fail-closed), затем контентный a, иначе код b.
     * Пустой текст → c.
     */
    public function classify(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return AdvisorIdea::CLASS_HUMAN;
        }
        if (preg_match(self::RE_CLASS_C, $text) === 1) {
            return AdvisorIdea::CLASS_HUMAN;
        }
        if (preg_match(self::RE_CLASS_A, $text) === 1) {
            return AdvisorIdea::CLASS_CONTENT;
        }

        return AdvisorIdea::CLASS_CODE;
    }

    /** Сколько идей сейчас «в работе»: in_progress + approved класса b. */
    private function countInWork(): int
    {
        return (int) $this->ideas->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.status = :ip OR (i.status = :ap AND i.actionClass = :b)')
            ->setParameter('ip', AdvisorIdea::STATUS_IN_PROGRESS)
            ->setParameter('ap', AdvisorIdea::STATUS_APPROVED)
            ->setParameter('b', AdvisorIdea::CLASS_CODE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function isHalted(): bool
    {
        return is_file($this->projectDir . '/var/agent/HALT');
    }
}
