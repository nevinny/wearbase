<?php

namespace App\Entity;

use App\Repository\SocialChannelRepository;
use Doctrine\ORM\Mapping as ORM;
use Nevinny\AdminCoreBundle\Entity\Trait\Created;

/**
 * Подключённый канал автопостинга: Telegram-канал / VK-сообщество / Instagram (через Postiz).
 * Токен хранится зашифрованным (SecretCipher, как реквизиты платёжек).
 *
 * egressHost определяет, ГДЕ крутится publish-tick для этого канала: TG/IG недоступны с
 * РФ-прода → host=mac; VK работает с прода → host=prod. См. docs/marketing_instagram.md §0/§4.
 */
#[ORM\Entity(repositoryClass: SocialChannelRepository::class)]
#[ORM\Table(name: 'social_channel')]
class SocialChannel
{
    use Created;

    public const PLATFORM_TG = 'tg';
    public const PLATFORM_VK = 'vk';
    public const PLATFORM_IG = 'ig';

    public const HOST_MAC  = 'mac';   // egress к TG/IG (РФ-прод заблокирован)
    public const HOST_PROD = 'prod';  // VK ок с прода

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 8, options: ['default' => self::PLATFORM_TG])]
    private string $platform = self::PLATFORM_TG;

    /** Человекочитаемое имя канала (для админки). */
    #[ORM\Column(length: 120, options: ['default' => ''])]
    private string $name = '';

    /**
     * Целевой идентификатор площадки: TG — @handle или chat_id канала; VK — owner_id (со знаком,
     * для сообщества отрицательный); IG — id интеграции в Postiz.
     */
    #[ORM\Column(length: 190, options: ['default' => ''])]
    private string $target = '';

    /** Зашифрованный токен/ключ (SecretCipher). Для IG через Postiz может быть пустым. */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $tokenEnc = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $enabled = false;

    #[ORM\Column(length: 8, options: ['default' => self::HOST_MAC])]
    private string $egressHost = self::HOST_MAC;

    /** Дата старта рампа (null → берём из env SOCIAL_LAUNCH_DATE). */
    #[ORM\Column(type: 'date', nullable: true)]
    private ?\DateTimeInterface $launchDate = null;

    /** Старт рампа, постов/день (null → env SOCIAL_START_RATE). */
    #[ORM\Column(nullable: true)]
    private ?int $rateStart = null;

    /** Потолок рампа, постов/день (null → env SOCIAL_CAP). */
    #[ORM\Column(nullable: true)]
    private ?int $rateCap = null;

    /** Транзиентное поле для ввода токена в открытом виде в админке (шифруется при сохранении). */
    private ?string $plainToken = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** Для админки: задан ли токен (без раскрытия значения). */
    public function getHasToken(): bool
    {
        return $this->tokenEnc !== null && $this->tokenEnc !== '';
    }

    public function getPlainToken(): ?string
    {
        return $this->plainToken;
    }

    public function setPlainToken(?string $plainToken): self
    {
        $this->plainToken = $plainToken;
        return $this;
    }

    public function getPlatform(): string
    {
        return $this->platform;
    }

    public function setPlatform(string $platform): self
    {
        $this->platform = $platform;
        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getTarget(): string
    {
        return $this->target;
    }

    public function setTarget(string $target): self
    {
        $this->target = $target;
        return $this;
    }

    public function getTokenEnc(): ?string
    {
        return $this->tokenEnc;
    }

    public function setTokenEnc(?string $tokenEnc): self
    {
        $this->tokenEnc = $tokenEnc;
        return $this;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setEnabled(bool $enabled): self
    {
        $this->enabled = $enabled;
        return $this;
    }

    public function getEgressHost(): string
    {
        return $this->egressHost;
    }

    public function setEgressHost(string $egressHost): self
    {
        $this->egressHost = $egressHost;
        return $this;
    }

    public function getLaunchDate(): ?\DateTimeInterface
    {
        return $this->launchDate;
    }

    public function setLaunchDate(?\DateTimeInterface $launchDate): self
    {
        $this->launchDate = $launchDate;
        return $this;
    }

    public function getRateStart(): ?int
    {
        return $this->rateStart;
    }

    public function setRateStart(?int $rateStart): self
    {
        $this->rateStart = $rateStart;
        return $this;
    }

    public function getRateCap(): ?int
    {
        return $this->rateCap;
    }

    public function setRateCap(?int $rateCap): self
    {
        $this->rateCap = $rateCap;
        return $this;
    }
}
