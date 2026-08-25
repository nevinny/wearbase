<?php

declare(strict_types=1);

namespace App\Service\Referral;

/**
 * Статический список одноразовых email-доменов (спец «Реферальная программа» §3):
 * проверка на этапе квалификации награды, НЕ блокирует регистрацию. Данные не
 * собираем и внешние API не дёргаем — минимизация данных и нулевая задержка.
 */
final class DisposableEmailDomains
{
    private const DOMAINS = [
        '0-mail.com', '0clickemail.com', '20minutemail.com', '33mail.com', 'anonbox.net',
        'anonymbox.com', 'armyspy.com', 'banit.club', 'bccto.me', 'binkmail.com',
        'bobmail.info', 'bugmenot.com', 'burnermail.io', 'byom.de', 'cool.fr.nf',
        'correo.blogos.net', 'cuvox.de', 'dayrep.com', 'deadaddress.com', 'despam.it',
        'discard.email', 'discardmail.com', 'dispostable.com', 'dodgeit.com', 'dodgit.com',
        'dropmail.me', 'e4ward.com', 'email-fake.com', 'emailfake.com', 'emailsensei.com',
        'emailtemporanea.net', 'emailtemporar.ro', 'emltmp.com', 'fakeinbox.com', 'fakemail.net',
        'fakemailgenerator.com', 'fleckens.hu', 'gcmail.top', 'gedmail.win', 'getairmail.com',
        'getnada.com', 'grr.la', 'guerrillamail.biz', 'guerrillamail.com', 'guerrillamail.de',
        'guerrillamail.info', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com',
        'harakirimail.com', 'hidemail.de', 'inboxalias.com', 'incognitomail.com', 'jetable.org',
        'jourrapide.com', 'kurzepost.de', 'lroid.com', 'mail-temporaire.fr', 'mail.tm',
        'mail7.io', 'mailcatch.com', 'maildrop.cc', 'mailexpire.com', 'mailforspam.com',
        'mailinator.com', 'mailinator.net', 'mailismagic.com', 'mailmetrash.com', 'mailnesia.com',
        'mailsac.com', 'mailsiphon.com', 'mailtothis.com', 'meltmail.com', 'messagerie.fr.nf',
        'mintemail.com', 'moakt.com', 'moncourrier.fr.nf', 'monemail.fr.nf', 'monmail.fr.nf',
        'mt2015.com', 'mvrht.net', 'mytemp.email', 'mytrashmail.com', 'nada.email',
        'no-spam.ws', 'nomail.xl.cx', 'nospam.ze.tc', 'notmailinator.com', 'nowmymail.com',
        'objectmail.com', 'one-time.email', 'pokemail.net', 'proxymail.eu', 'rcpt.at',
        'reallymymail.com', 'rhyta.com', 'safetymail.info', 'shieldedmail.com', 'sharklasers.com',
        'spam4.me', 'spambog.com', 'spambox.us', 'spamfree24.org', 'spamgourmet.com',
        'spamhole.com', 'spaml.com', 'superrito.com', 'supermailer.jp', 'teleworm.us',
        'temp-mail.io', 'temp-mail.org', 'tempail.com', 'tempinbox.com', 'tempmail.plus',
        'tempmailo.com', 'tempomail.fr', 'temporaryemail.net', 'temporaryinbox.com', 'thankyou2010.com',
        'throwawaymail.com', 'tmail.ws', 'tmailinator.com', 'trbvm.com', 'trash-mail.at',
        'trash-mail.com', 'trashmail.at', 'trashmail.com', 'trashmail.de', 'trashmail.me',
        'trungtamtoeic.com', 'twinmail.de', 'undo.it', 'vanqtech.com', 'vomoto.com',
        'vpn.st', 'webm4il.info', 'wegwerfmail.de', 'wh4f.org', 'willselfdestruct.com',
        'yopmail.com', 'yopmail.fr', 'yopmail.net', 'zetmail.com',
    ];

    private static ?array $sorted = null;

    /** Одноразовый ли домен у этого адреса (регистронезависимо). */
    public static function isDisposable(?string $email): bool
    {
        if ($email === null || !str_contains($email, '@')) {
            return false;
        }
        $domain = mb_strtolower(substr($email, strrpos($email, '@') + 1));

        // Точный домен или поддомен одноразового (напр. foo.mail.mailinator.com).
        self::$sorted ??= self::DOMAINS;
        foreach (self::$sorted as $candidate) {
            if ($domain === $candidate || str_ends_with($domain, '.'.$candidate)) {
                return true;
            }
        }

        return false;
    }
}
