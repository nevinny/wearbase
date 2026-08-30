<?php

declare(strict_types=1);

namespace App\ValueObject;

final readonly class ExternalProductUrl
{
    private function __construct(private string $value) {}

    public static function fromString(string $value): self
    {
        $value = trim($value);
        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (strlen($value) > 2048
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || $parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || $host === 'localhost'
            || str_ends_with($host, '.local')
            || filter_var($host, FILTER_VALIDATE_IP) !== false
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || preg_match('/[\x00-\x20\x7F]/', $value)
            || str_contains($value, '\\')
        ) {
            throw new \InvalidArgumentException('Допустима только безопасная HTTPS-ссылка');
        }

        $normalized = 'https://'.$host;
        $normalized .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            $normalized .= '?'.$parts['query'];
        }

        return new self($normalized);
    }

    public function host(): string
    {
        return (string) parse_url($this->value, PHP_URL_HOST);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
