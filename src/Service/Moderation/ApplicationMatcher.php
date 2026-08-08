<?php

declare(strict_types=1);

namespace App\Service\Moderation;

use App\Service\Support\EmailDomain;

/**
 * Детерминированный (без LLM) матчинг заявленных контактов бренда при самрег/claim
 * против сайта-кандидата: подтверждает ЛИЧНОСТЬ бренда (identity_match) и ОТДЕЛЬНО —
 * что заявитель реально КОНТРОЛИРУЕТ сайт (control_proof). Анти-сквоттер: публичные
 * контакты с чужого сайта скопировать легко (identity), а зарегистрировать почту на
 * домене чужого сайта — нельзя (control).
 *
 * identity_match:
 *   confirmed   — ≥2 сильных сигнала {телефон, точный email, домен email == домен сайта}
 *                 ИЛИ 1 сильный сигнал + совпадение названия
 *   weak        — ровно 1 сильный сигнал (без названия)
 *   unconfirmed — 0 сильных сигналов (совпадение одного только названия не в счёт)
 *   no_trace    — сайта-кандидата нет вовсе ($pages === [])
 *
 * control_proof: confirmed ТОЛЬКО если домен email владельца аккаунта == домен сайта.
 */
final class ApplicationMatcher
{
    private const ADDRESS_JACCARD_THRESHOLD = 0.6;

    /**
     * @param array{title?:?string,email?:?string,phone?:?string,address?:?string} $brand заявленные контакты бренда
     * @param array<int,array{url:string,html:string}> $pages сырой HTML главной + подстраниц сайта-кандидата (пусто = кандидата нет)
     * @param string|null $officialDomain хост сайта-кандидата (lowercase, без www), null если кандидата нет
     * @param string|null $ownerEmail email владельца аккаунта, подавшего заявку
     * @return array{identity_match:string,control_proof:string,evidence:array<int,array{url:string,score:float,matched:array<string,bool>}>}
     */
    public function evaluate(array $brand, array $pages, ?string $officialDomain, ?string $ownerEmail): array
    {
        if ($pages === []) {
            return [
                'identity_match' => 'no_trace',
                'control_proof'  => 'unconfirmed',
                'evidence'       => [],
            ];
        }

        $brandTitle   = trim((string) ($brand['title'] ?? ''));
        $brandEmail   = trim((string) ($brand['email'] ?? ''));
        $brandPhone   = trim((string) ($brand['phone'] ?? ''));
        $brandAddress = trim((string) ($brand['address'] ?? ''));

        $phoneHit       = false;
        $emailExactHit  = false;
        $emailDomainHit = false;
        $titleHit       = false;
        $evidence       = [];

        foreach ($pages as $page) {
            $html = (string) ($page['html'] ?? '');
            $text = $this->stripTags($html);

            $pagePhoneHit   = $brandPhone !== '' && in_array($this->normalizePhone($brandPhone), $this->extractPhones($html, $text), true);
            $pageEmailHit   = $brandEmail !== '' && in_array(strtolower($brandEmail), $this->extractEmails($html, $text), true);
            $pageAddressHit = $brandAddress !== '' && $this->addressMatches($brandAddress, $text);
            $pageTitleHit   = $brandTitle !== '' && $this->titleFoundInText($brandTitle, $this->siteHeading($html) . ' ' . $text);

            $phoneHit = $phoneHit || $pagePhoneHit;
            $emailExactHit = $emailExactHit || $pageEmailHit;
            $titleHit = $titleHit || $pageTitleHit;

            if ($brandEmail !== '' && $officialDomain !== null && EmailDomain::ofEmail($brandEmail) === $officialDomain) {
                $emailDomainHit = true;
            }

            $matchedCount = ($pagePhoneHit ? 1 : 0) + ($pageEmailHit ? 1 : 0) + ($pageAddressHit ? 1 : 0) + ($pageTitleHit ? 1 : 0);
            $evidence[] = [
                'url'   => (string) ($page['url'] ?? ''),
                'score' => round($matchedCount / 4, 2),
                'matched' => [
                    'phone'   => $pagePhoneHit,
                    'email'   => $pageEmailHit,
                    'address' => $pageAddressHit,
                    'title'   => $pageTitleHit,
                ],
            ];
        }

        $strongCount = ($phoneHit ? 1 : 0) + ($emailExactHit ? 1 : 0) + ($emailDomainHit ? 1 : 0);

        $identityMatch = match (true) {
            $strongCount >= 2 => 'confirmed',
            $strongCount === 1 && $titleHit => 'confirmed',
            $strongCount === 1 => 'weak',
            default => 'unconfirmed',
        };

        $controlProof = ($ownerEmail !== null && $ownerEmail !== '' && $officialDomain !== null
            && EmailDomain::ofEmail($ownerEmail) === $officialDomain) ? 'confirmed' : 'unconfirmed';

        return ['identity_match' => $identityMatch, 'control_proof' => $controlProof, 'evidence' => $evidence];
    }

    // ── Телефон ────────────────────────────────────────────────────────────────

    /** Последние 10 цифр, отбросив ведущие 7|8 (страновой код РФ). */
    public function normalizePhone(string $raw): string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if (strlen($digits) === 11 && ($digits[0] === '7' || $digits[0] === '8')) {
            $digits = substr($digits, 1);
        }

        return substr($digits, -10);
    }

    /** @return string[] нормализованные телефоны (10 цифр), найденные в HTML/тексте страницы */
    private function extractPhones(string $html, string $text): array
    {
        $found = [];
        if (preg_match_all('/tel:([+\d()\-.\s]{6,})/i', $html, $m)) {
            foreach ($m[1] as $p) {
                $found[] = $this->normalizePhone($p);
            }
        }
        if (preg_match_all('/(?:\+7|8|7)[\s\-(]*\d{3}[\s\-)]*\d{3}[\s\-]*\d{2}[\s\-]*\d{2}/u', $text, $m2)) {
            foreach ($m2[0] as $p) {
                $found[] = $this->normalizePhone($p);
            }
        }

        return array_values(array_unique(array_filter($found, static fn (string $p): bool => strlen($p) === 10)));
    }

    // ── Email ──────────────────────────────────────────────────────────────────

    /** @return string[] email'ы (lowercase), найденные в HTML/тексте страницы */
    private function extractEmails(string $html, string $text): array
    {
        $found = [];
        if (preg_match_all('/mailto:([^"\'?\s]+)/i', $html, $m)) {
            foreach ($m[1] as $e) {
                $found[] = strtolower(trim($e));
            }
        }
        if (preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu', $text, $m2)) {
            foreach ($m2[0] as $e) {
                $found[] = strtolower($e);
            }
        }

        return array_values(array_unique($found));
    }

    // ── Адрес ──────────────────────────────────────────────────────────────────

    /** token-set Jaccard ≥0.6 в пределах одной строки текста, И обязательное совпадение номера дома. */
    public function addressMatches(string $brandAddress, string $pageText): bool
    {
        $house = $this->houseNumber($brandAddress);
        if ($house === null) {
            return false; // нет номера дома в заявленном адресе — сверить нечем
        }

        $addrTokens = $this->addressTokens($brandAddress);
        if ($addrTokens === []) {
            return false;
        }
        $addrSet = array_flip($addrTokens);

        foreach (preg_split('/\R/u', $pageText) ?: [] as $line) {
            $lineTokens = $this->addressTokens($line);
            if ($lineTokens === [] || !in_array($house, $lineTokens, true)) {
                continue;
            }
            $lineSet = array_flip($lineTokens);
            $inter = count(array_intersect_key($addrSet, $lineSet));
            $union = count($addrSet) + count($lineSet) - $inter;
            if ($union > 0 && ($inter / $union) >= self::ADDRESS_JACCARD_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /** Первый числовой токен с опциональной буквой ("11а", "5") — номер дома. */
    private function houseNumber(string $address): ?string
    {
        if (preg_match('/\d+[a-zа-яё]?/iu', $address, $m)) {
            return mb_strtolower($m[0]);
        }

        return null;
    }

    /** lowercase, ё→е, снять г.|ул.|д.|стр.|оф. (только с точкой — иначе "оф." съедает "офис"), токены по пробелу. */
    private function addressTokens(string $s): array
    {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);
        $s = preg_replace('/\b(г|ул|д|стр|оф)\.\s*/u', ' ', $s) ?? $s;
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s) ?? $s;

        return preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    // ── Название ───────────────────────────────────────────────────────────────

    /** <title>/<h1> сайта (сырой HTML → голый текст, без разметки). */
    private function siteHeading(string $html): string
    {
        $out = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
            $out .= ' ' . $m[1];
        }
        if (preg_match_all('/<h1[^>]*>(.*?)<\/h1>/is', $html, $m2)) {
            $out .= ' ' . implode(' ', $m2[1]);
        }

        return html_entity_decode(strip_tags($out), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Название бренда встречается в тексте — устойчиво к пробелам/пунктуации/регистру (кириллица и латиница). */
    public function titleFoundInText(string $brandTitle, string $haystack): bool
    {
        $needle = $this->normalizeForTitle($brandTitle);
        $hay    = $this->normalizeForTitle($haystack);

        return $needle !== '' && str_contains($hay, $needle);
    }

    private function normalizeForTitle(string $s): string
    {
        $s = mb_strtolower($s);
        $s = str_replace('ё', 'е', $s);

        return preg_replace('/[^\p{L}\p{N}]+/u', '', $s) ?? '';
    }

    private function stripTags(string $html): string
    {
        $html = preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
        // Перевод строки на границах блочных тегов — иначе на минифицированном HTML (без
        // исходных переносов) addressMatches() схлопывает всю страницу в одну "строку".
        $html = preg_replace('/<\/?(p|div|li|tr|br|h[1-6]|td|section|article|footer|header)\b[^>]*>/i', "\n", $html) ?? $html;
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
    }
}
