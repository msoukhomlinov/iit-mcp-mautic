<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Command;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\IntellectItMcpBundle\Service\SetupStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * One-step setup: creates the OAuth keys, stores them and the redirect hosts
 * in config/local.php, then prints the status and how to connect a client.
 * Existing keys are kept unless --force (rotating them signs everyone out).
 */
#[AsCommand(name: 'mautic:mcp:setup', description: 'Create the OAuth keys, allow client redirect hosts and show how to connect an MCP client')]
class SetupCommand extends Command
{
    public function __construct(
        private readonly PathsHelper $paths,
        private readonly CoreParametersHelper $parameters,
        private readonly SetupStatus $status,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', null, InputOption::VALUE_NONE, 'Replace existing keys (signs every client out)')
            ->addOption('redirect-host', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Add a host clients may redirect to (repeatable)')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Only report, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$input->getOption('check')) {
            $changes = [];
            $has     = static fn (mixed $v): bool => '' !== trim((string) $v);
            $force   = (bool) $input->getOption('force');

            if ($force || !$has($this->parameters->get('kopexa_oauth_private_key'))) {
                $key = openssl_pkey_new(['private_key_bits' => 3072, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                if (false === $key || !openssl_pkey_export($key, $pem)) {
                    $output->writeln('<error>Could not create an RSA key (is the openssl extension available?).</error>');

                    return Command::FAILURE;
                }
                $changes['kopexa_oauth_private_key'] = $pem;
            }
            if ($force || !$has($this->parameters->get('kopexa_oauth_encryption_key'))) {
                $changes['kopexa_oauth_encryption_key'] = bin2hex(random_bytes(32));
            }

            $extra = array_map('strtolower', array_map('trim', (array) $input->getOption('redirect-host')));
            if ([] !== $extra) {
                $current = $this->parameters->get('kopexa_oauth_redirect_hosts');
                $current = is_array($current) ? $current : (preg_split('/[\s,]+/', (string) $current) ?: []);
                $changes['kopexa_oauth_redirect_hosts'] = array_values(array_unique(array_filter(array_merge($current, $extra))));
            }

            if ([] !== $changes) {
                $this->writeLocal($changes);
                $output->writeln('<info>Wrote '.implode(', ', array_keys($changes)).' to config/local.php (secrets not shown).</info>');
                $output->writeln('Next: php bin/console cache:clear, then php bin/console mautic:mcp:setup --check');
                $output->writeln('Then connect a client to <info>'.rtrim((string) $this->parameters->get('site_url'), '/').'/mcp</info>.');

                // The running process still holds the old values, so a status report here would be wrong.
                return Command::SUCCESS;
            }
            $output->writeln('Keys already set, nothing changed (use --force to replace them).');
        }

        $report = $this->status->report();
        foreach ($report['checks'] as $c) {
            $output->writeln(sprintf('%-5s %-22s %s%s', strtoupper($c['status']), $c['check'], $c['detail'], isset($c['fix']) ? "  -> {$c['fix']}" : ''));
        }
        $output->writeln('');
        $output->writeln('Connect a client to: <info>'.$report['endpoint'].'</info> (sign in with a Mautic user; it acts with that user\'s permissions).');
        $output->writeln('Writes, sending and deletes are off until enabled in config/local.php (see README, "Operator settings").');

        return $report['ok'] ? Command::SUCCESS : Command::FAILURE;
    }

    /** @param array<string, mixed> $changes */
    private function writeLocal(array $changes): void
    {
        $file       = $this->paths->getLocalConfigurationFile();
        $parameters = [];
        if (is_file($file)) {
            include $file;
        }
        $parameters = array_replace($parameters, $changes);
        $content = "<?php\n\$parameters = ".var_export($parameters, true).";\n";
        $tmp     = tempnam(dirname($file), 'local.php.');
        if (false !== $tmp && dirname($tmp) === dirname($file)) {
            // Atomic: temp file next to the original (created 0600), mode 0640 (holds the keys, never world-readable), same owner, then rename over it.
            $ok = false !== file_put_contents($tmp, $content) && chmod($tmp, 0640);
            if ($ok && is_file($file)) {
                @chown($tmp, (int) fileowner($file));
                @chgrp($tmp, (int) filegroup($file));
            }
            if (!$ok || !rename($tmp, $file)) {
                @unlink($tmp);
                throw new \RuntimeException('Cannot write '.$file);
            }
        } else {
            // config/ is not writable (tempnam fell back to the system temp dir): write in place as before.
            if (false !== $tmp) {
                @unlink($tmp);
            }
            if (false === file_put_contents($file, $content, LOCK_EX) || !chmod($file, 0640)) {
                throw new \RuntimeException('Cannot write '.$file);
            }
        }
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }
    }
}
