<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Часовой пояс приложения фиксируем в коде, а не в php.ini.
     *
     * На проде (и на Mac) php-fpm идёт в Europe/Moscow, а CLI — в UTC
     * (`date.timezone=UTC`). MySQL живёт по системному времени, то есть по МСК.
     * Из-за расхождения веб писал в БД московское время, а консольные команды
     * сравнивали его с UTC-«сейчас» и отставали на три часа: письма из
     * `external_notification_outbox` (`available_at` ставит веб) забирались
     * воркером только через 3 часа после события. Поймано 28.08.2026 на письме
     * владельцу бренда по решению премодерации.
     */
    public function __construct(string $environment, bool $debug)
    {
        date_default_timezone_set('Europe/Moscow');

        parent::__construct($environment, $debug);
    }
}
