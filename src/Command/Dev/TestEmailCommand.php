<?php

declare(strict_types=1);

namespace App\Command\Dev;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends a one-off test email through the configured mailer. Useful for
 * verifying SMTP wiring (Mailtrap, SES, etc.) without going through the
 * full user-lifecycle flow.
 *
 * Hard-guarded: refuses to run when APP_ENV=prod. Lives under
 * src/Command/Dev/ and the namespace App\Command\Dev so the directory
 * can be excluded from the autoload scan in prod if we ever want to
 * (via composer.json's autoload-dev exclude-from-classmap, or by
 * removing the dev autoload entry). For now the runtime guard is enough.
 */
#[AsCommand(name: 'app:dev:test-email', description: '[DEV ONLY] Send a one-off test email via the configured mailer.')]
final class TestEmailCommand extends Command
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly KernelInterface $kernel,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('to', InputArgument::REQUIRED, 'Recipient address');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->kernel->getEnvironment() === 'prod') {
            $output->writeln('<error>This command is dev-only. Refusing to run in prod.</error>');
            return Command::FAILURE;
        }

        $to = $input->getArgument('to');
        $email = (new Email())
            ->from(new Address($_ENV['MAILER_FROM'] ?? 'no-reply@ketten.local', $_ENV['MAILER_FROM_NAME'] ?? 'Ketten'))
            ->to($to)
            ->subject(sprintf('[%s] SMTP smoke test', $this->kernel->getEnvironment()))
            ->text(sprintf(
                "Hello!\n\nThis is a test email from the dev SMTP smoke command.\nSent at %s\nEnv: %s\n\n— the dev script",
                date('c'),
                $this->kernel->getEnvironment(),
            ));

        try {
            $this->mailer->send($email);
            $output->writeln(sprintf('<info>SENT</info> to <comment>%s</comment> (message-id: %s)', $to, $email->generateMessageId()));
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln('<error>SEND FAILED: ' . $e->getMessage() . '</error>');
            $output->writeln($e->getTraceAsString());
            return Command::FAILURE;
        }
    }
}