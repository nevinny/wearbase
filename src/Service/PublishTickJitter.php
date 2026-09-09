<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Джиттер дрип-публикации (app:brand:publish-tick), вынесенный ради тестируемости и чтобы
 * НЕ блокировать глобальный крон-лок диспетчера (RunScheduledCommandsCommand держит flock на
 * весь проход): раньше публикация ждала sleep(rand(0..45мин)) ПРЯМО ВНУТРИ команды, и всё
 * остальное расписание (в т.ч. ежеминутная доставка писем) стояло до конца сна.
 *
 * Публикация в часе решается ОДИН раз: на первом тике часа выбирается случайная секунда
 * публикации в пределах MAX_DELAY_SECONDS от НАЧАЛА часа (то же распределение :00–:45, что
 * раньше давал sleep — если первый тик часа опоздал из-за глобального лока, намеченное время
 * могло уже пройти, и публикация проходит сразу же, самокоррекция). $onNewHour() — дорогой
 * расчёт (target/health/n у вызывающей команды) вызывается только на этом тике, а не на
 * каждом последующем пятиминутном опросе состояния.
 *
 * Состояние (var/publish_tick_state.json у вызывающей команды) хранит 'publish_at' как unix
 * timestamp — сравнение строк формата 'Y-m-d H:i:s' зависело бы от таймзоны на момент парсинга
 * (проект уже горел на разъезде CLI/веб таймзон, см. php-cli-vs-fpm-timezone).
 */
final class PublishTickJitter
{
    /** 45 мин — тот же максимум, что раньше был у sleep(rand(0, MAX_SLEEP)). */
    private const MAX_DELAY_SECONDS = 2700;

    /**
     * @param array{hour:string,publish_at:int,done:bool}|array<string,mixed>|null $state состояние с прошлого тика (null — первый запуск вообще)
     * @param callable():array<string,mixed> $onNewHour вызывается один раз при смене часа —
     *        тяжёлые вычисления вызывающей команды (target/health/n); может вернуть ['done' => true],
     *        если публиковать в этом часе нечего (n=0) — тогда час пропускается без ожидания
     * @param bool $immediate публиковать сейчас же, игнорируя и джиттер, и то, что час уже
     *        отработан (ручной прогон/отладка — --now)
     * @return array{publish:bool,state:array<string,mixed>}
     */
    public function evaluate(\DateTimeInterface $now, ?array $state, callable $onNewHour, bool $immediate = false): array
    {
        $hourKey = $now->format('Y-m-d H');

        // $immediate пересчитывает план заново: ручной прогон должен публиковать по свежим
        // цифрам, а не по n, доставшемуся от уже отработанного часа (там он часто 0).
        if ($state === null || ($state['hour'] ?? null) !== $hourKey || $immediate) {
            $hourStart = (clone $now)->setTime((int) $now->format('G'), 0, 0)->getTimestamp();
            $state = array_merge(
                ['hour' => $hourKey, 'publish_at' => $hourStart + random_int(0, self::MAX_DELAY_SECONDS), 'done' => false],
                $onNewHour(),
            );
        }

        if ($immediate) {
            $state['done'] = true;

            return ['publish' => true, 'state' => $state];
        }

        if ($state['done']) {
            return ['publish' => false, 'state' => $state];
        }

        if ($now->getTimestamp() >= $state['publish_at']) {
            $state['done'] = true;

            return ['publish' => true, 'state' => $state];
        }

        return ['publish' => false, 'state' => $state];
    }
}
