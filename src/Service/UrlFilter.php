<?php

namespace App\Service;

/**
 * Единственная точка исключения URL из скрейпинга. Жёсткий constraint:
 * НИКОГДА не парсить wearbase.ru (иначе скармливаем модели свой же,
 * возможно AI-сгенерированный, контент).
 *
 * Маркетплейсы (Ozon/WB/Lamoda) НЕ исключаются — у них часто реальные
 * описания/материалы брендов (особенно для брендов с пустым сайтом).
 * Доп. исключения — через env SCRAPE_EXCLUDED_DOMAINS (comma-separated).
 *
 * Матчинг по суффиксу хоста (домен и любые поддомены), fail-closed:
 * пустой/нечитаемый host → исключаем.
 */
class UrlFilter
{
    /**
     * Собственные домены и источники-ОСНОВЫ нашего каталога — НИКОГДА не скрейпим
     * (иначе скармливаем модели свои же данные → самозагрязнение).
     * russianstreetwear.club — каталог, на котором строился наш (сейчас разросся).
     * @var string[]
     */
    private const SELF = ['wearbase.ru', 'russianstreetwear.club'];

    /**
     * Job-/рекрутинг-агрегаторы: упоминают бренд как РАБОТОДАТЕЛЯ (вакансии, зарплаты,
     * отзывы сотрудников), а не как fashion-бренд. Проходят co-occurrence фильтр
     * ("{бренд} магазин одежды" в тексте вакансии) → шум в корпусе. Не парсим.
     * @var string[]
     */
    private const JOB_NOISE = [
        'hh.ru', 'headhunter.ru', 'superjob.ru', 'rabota.ru', 'zarplata.ru',
        'trudvsem.ru', 'jobfilter.ru', 'dreamjob.ru', 'gorodrabot.ru',
        'jooble.org', 'trud.com', 'joblab.ru', 'gorabota.ru', 'rabotavgorode.ru',
    ];

    /** @var string[] */
    private array $excluded;

    public function __construct(string $extraExcludedDomains = '')
    {
        $extra = array_filter(array_map('trim', explode(',', strtolower($extraExcludedDomains))));
        $this->excluded = array_merge(self::SELF, self::JOB_NOISE, $extra);
    }

    public function isExcluded(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return true; // fail-closed
        }

        foreach ($this->excluded as $bad) {
            if ($host === $bad || str_ends_with($host, '.' . $bad)) {
                return true;
            }
        }

        return false;
    }

    /** Только для аудита/логов: это наш собственный домен? */
    public function isSelfDomain(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::SELF as $self) {
            if ($host === $self || str_ends_with($host, '.' . $self)) {
                return true;
            }
        }

        return false;
    }
}
