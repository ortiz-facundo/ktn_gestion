<?php

declare(strict_types=1);

namespace App\Mailer\Security;

use App\Entity\Administration\User;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Composes and sends the lifecycle emails. Rendered via Twig so the
 * content lives in `templates/emails/` and can be translated via the
 * `trans()` filter — the Twig integration already wires the translator.
 *
 * Subject lines are translated via the TranslatorInterface (so we don't
 * depend on Twig for the subject); body lines go through Twig's `trans`
 * so they're translatable alongside the HTML.
 */
final class AccountMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function sendConfirmation(User $user, string $confirmationUrl, int $expiresInHours = 48): void
    {
        $locale = $this->localeFor($user);
        $email = (new TemplatedEmail())
            ->from(new Address($_ENV['MAILER_FROM'] ?? 'no-reply@example.com', $_ENV['MAILER_FROM_NAME'] ?? 'Ketten'))
            ->to(new Address($user->getEmail(), $user->getName() ?? $user->getEmail()))
            ->subject($this->translator->trans('email.confirmation.subject', [], null, $locale))
            ->locale($locale)
            ->htmlTemplate('security/emails/confirmation.html.twig')
            ->textTemplate('security/emails/confirmation.txt.twig')
            ->context([
                'user'             => $user,
                'email_locale'     => $locale,
                'confirmation_url' => $confirmationUrl,
                'expires_in_hours' => $expiresInHours,
            ]);

        $this->mailer->send($email);
    }

    public function sendActivated(User $user): void
    {
        $locale = $this->localeFor($user);
        $email = (new TemplatedEmail())
            ->from(new Address($_ENV['MAILER_FROM'] ?? 'no-reply@example.com', $_ENV['MAILER_FROM_NAME'] ?? 'Ketten'))
            ->to(new Address($user->getEmail(), $user->getName() ?? $user->getEmail()))
            ->subject($this->translator->trans('email.activated.subject', [], null, $locale))
            ->locale($locale)
            ->htmlTemplate('security/emails/activated.html.twig')
            ->textTemplate('security/emails/activated.txt.twig')
            ->context(['user' => $user, 'email_locale' => $locale]);

        $this->mailer->send($email);
    }

    public function sendDisabled(User $user, ?string $reason): void
    {
        $locale = $this->localeFor($user);
        $email = (new TemplatedEmail())
            ->from(new Address($_ENV['MAILER_FROM'] ?? 'no-reply@example.com', $_ENV['MAILER_FROM_NAME'] ?? 'Ketten'))
            ->to(new Address($user->getEmail(), $user->getName() ?? $user->getEmail()))
            ->subject($this->translator->trans('email.disabled.subject', [], null, $locale))
            ->locale($locale)
            ->htmlTemplate('security/emails/disabled.html.twig')
            ->textTemplate('security/emails/disabled.txt.twig')
            ->context([
                'user'   => $user,
                'reason' => $reason,
                'email_locale' => $locale,
            ]);

        $this->mailer->send($email);
    }

    private function localeFor(User $user): string
    {
        return $user->getLocale() ?? 'es';
    }
}
