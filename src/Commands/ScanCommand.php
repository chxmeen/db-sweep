<?php

declare(strict_types=1);

namespace DbSweep\Commands;

use DbSweep\DTO\DatabaseTelemetry;
use DbSweep\Services\ProjectCrawler;
use PDO;
use PDOException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'scan', description: 'Scan for orphaned MySQL databases not linked to any local project')]
final class ScanCommand extends Command
{
    private const SYSTEM_DATABASES = [
        'information_schema',
        'performance_schema',
        'mysql',
        'sys',
        'phpmyadmin',
        'test',
        'ndbinfo',
    ];

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Root directory to scan for projects')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'MySQL host', '127.0.0.1')
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'MySQL port', '3306')
            ->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'MySQL user', 'root')
            ->addOption('pass', null, InputOption::VALUE_REQUIRED, 'MySQL password', '')
            ->addOption('ignore', 'i', InputOption::VALUE_REQUIRED, 'Comma-delimited databases to skip')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Output telemetry without prompting for deletion')
            ->addOption('force-kill', null, InputOption::VALUE_NONE, 'Allow purge even with active non-sleeping connections')
            ->addOption('no-backup', null, InputOption::VALUE_NONE, 'Skip pre-drop gzip snapshot');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $homeDir = $_SERVER['HOME'] ?? getenv('HOME') ?: '';
        $scanPath = $input->getOption('path') ?? ($homeDir !== '' ? "{$homeDir}/Projects" : getcwd() . '/Projects');
        $host = (string) $input->getOption('host');
        $port = (int) $input->getOption('port');
        $user = (string) $input->getOption('user');
        $pass = (string) $input->getOption('pass');
        $dryRun = (bool) $input->getOption('dry-run');
        $forceKill = (bool) $input->getOption('force-kill');
        $noBackup = (bool) $input->getOption('no-backup');

        $rawIgnore = $input->getOption('ignore');
        $ignoreList = [];
        if (is_string($rawIgnore) && $rawIgnore !== '') {
            foreach (explode(',', $rawIgnore) as $item) {
                $trimmed = trim($item, " \t\n\r\0\x0B\"'");
                if ($trimmed !== '') {
                    $ignoreList[] = $trimmed;
                }
            }
        }

        $output->writeln("==> Connecting to MySQL at <info>{$host}:{$port}</info>");

        try {
            $pdo = new PDO(
                "mysql:host={$host};port={$port}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $output->writeln("<error>Connection failed:</error> {$e->getMessage()}");
            return Command::FAILURE;
        }

        // Server-level case sensitivity affects how we compare database names
        $caseInsensitive = false;
        try {
            $lctn = $pdo->query("SHOW VARIABLES LIKE 'lower_case_table_names'")->fetch(PDO::FETCH_ASSOC);
            if ($lctn && in_array((int) $lctn['Value'], [1, 2], true)) {
                $caseInsensitive = true;
            }
        } catch (Throwable) {
            // Default to case-sensitive if variable check fails
        }

        $whitelist = self::SYSTEM_DATABASES;
        $whitelist = array_merge($whitelist, $ignoreList);
        $whitelist = array_merge($whitelist, $this->loadDbIgnoreFile($homeDir));

        if ($caseInsensitive) {
            $whitelist = array_map('mb_strtolower', $whitelist);
        }

        // System schemas must ALWAYS be shielded case-insensitively regardless of server mode
        $systemLower = array_map('mb_strtolower', self::SYSTEM_DATABASES);

        $output->writeln("==> Scanning <info>{$scanPath}</info> for project configs");

        $crawler = new ProjectCrawler();
        $projectDatabases = $crawler->discover($scanPath, $caseInsensitive);

        $output->writeln("    Found <info>" . count($projectDatabases) . "</info> database reference(s) in project files");

        $output->writeln('==> Inspecting server databases');

        $telemetry = $this->inspectDatabases($pdo);

        $orphans = array_filter($telemetry, function (DatabaseTelemetry $db) use ($whitelist, $systemLower, $projectDatabases, $caseInsensitive) {
            $dbLower = mb_strtolower($db->name);
            if (in_array($dbLower, $systemLower, true)) {
                return false;
            }

            $name = $caseInsensitive ? $dbLower : $db->name;
            return !in_array($name, $whitelist, true) && !in_array($name, $projectDatabases, true);
        });

        $orphans = array_values($orphans);

        if (count($orphans) === 0) {
            $output->writeln('');
            $output->writeln('  ✔ All databases are matched to active projects.');
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln("==> Found <comment>" . count($orphans) . "</comment> orphaned database(s)");
        $output->writeln('');

        $table = new Table($output);
        $table->setStyle('compact');
        $table->setHeaders(['Database', 'Tables', 'Size', 'Last Active', 'Locks']);

        $totalSize = 0.0;

        foreach ($orphans as $db) {
            $table->addRow([
                $db->name,
                $db->tableCount,
                sprintf('%.2f MB', $db->sizeMb),
                $db->lastActive,
                $db->activeLocks > 0 ? "<comment>{$db->activeLocks}</comment>" : '0',
            ]);
            $totalSize += $db->sizeMb;
        }

        $table->render();

        $output->writeln('');
        $output->writeln(sprintf('    Reclaimable: <info>%.2f MB</info>', $totalSize));
        $output->writeln('');

        if ($dryRun) {
            return Command::SUCCESS;
        }

        return $this->promptAndDrop($input, $output, $pdo, $orphans, $host, $port, $user, $pass, $homeDir, $caseInsensitive, $forceKill, $noBackup);
    }

    /** @return list<DatabaseTelemetry> */
    private function inspectDatabases(PDO $pdo): array
    {
        $databases = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);

        $telemetry = [];

        foreach ($databases as $dbName) {
            $escapedDb = $pdo->quote($dbName);

            $stats = $pdo->query(
                "SELECT COUNT(*) AS tables_count,
                        COALESCE(SUM(data_length + index_length) / 1024 / 1024, 0) AS size_mb,
                        COALESCE(MAX(update_time), 'unknown') AS last_active
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = {$escapedDb}"
            )->fetch(PDO::FETCH_ASSOC);

            if (!is_array($stats)) {
                $stats = ['tables_count' => 0, 'size_mb' => 0, 'last_active' => 'unknown'];
            }

            $activeLocks = 0;
            try {
                $locks = $pdo->query(
                    "SELECT COUNT(*) AS cnt
                     FROM information_schema.PROCESSLIST
                     WHERE DB = {$escapedDb} AND COMMAND != 'Sleep'"
                )->fetch(PDO::FETCH_ASSOC);

                if (is_array($locks) && isset($locks['cnt'])) {
                    $activeLocks = (int) $locks['cnt'];
                }
            } catch (Throwable) {
                // Safeguard against missing PROCESSLIST privileges or deprecation in newer engines
                $activeLocks = 0;
            }

            $telemetry[] = new DatabaseTelemetry(
                name: (string) $dbName,
                tableCount: (int) ($stats['tables_count'] ?? 0),
                sizeMb: round((float) ($stats['size_mb'] ?? 0), 2),
                lastActive: (string) ($stats['last_active'] ?? 'unknown'),
                activeLocks: $activeLocks,
            );
        }

        return $telemetry;
    }

    /** @param list<DatabaseTelemetry> $orphans */
    private function promptAndDrop(
        InputInterface $input,
        OutputInterface $output,
        PDO $pdo,
        array $orphans,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $homeDir,
        bool $caseInsensitive,
        bool $forceKill,
        bool $noBackup,
    ): int {
        /** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new \Symfony\Component\Console\Question\Question(
            '  Type database name to drop, <comment>all</comment> to drop all, or press Enter to exit: '
        );

        $rawAnswer = trim((string) $helper->ask($input, $output, $question));
        $answer = trim($rawAnswer, " \t\n\r\0\x0B\"'");

        if ($answer === '') {
            $output->writeln('  Aborted.');
            return Command::SUCCESS;
        }

        $targets = [];

        if (mb_strtolower($answer) === 'all') {
            $targets = $orphans;
        } else {
            foreach ($orphans as $db) {
                $match = $caseInsensitive
                    ? mb_strtolower($db->name) === mb_strtolower($answer)
                    : $db->name === $answer;

                if ($match) {
                    $targets[] = $db;
                    break;
                }
            }

            if (count($targets) === 0) {
                $output->writeln("<error>  Database '{$answer}' not found in orphan list.</error>");
                return Command::FAILURE;
            }
        }

        $names = implode(', ', array_map(fn(DatabaseTelemetry $d) => $d->name, $targets));
        $confirm = new \Symfony\Component\Console\Question\ConfirmationQuestion(
            "  Confirm drop [{$names}]? [y/N] ",
            false
        );

        if (!$helper->ask($input, $output, $confirm)) {
            $output->writeln('  Aborted.');
            return Command::SUCCESS;
        }

        $backupDir = ($homeDir !== '' ? $homeDir : sys_get_temp_dir()) . '/.db-sweep/backups';

        foreach ($targets as $db) {
            if ($db->activeLocks > 0 && !$forceKill) {
                $output->writeln("<comment>  Skipping {$db->name}: {$db->activeLocks} active connection(s). Use --force-kill to override.</comment>");
                continue;
            }

            if (!$noBackup) {
                if (!$this->backupDatabase($output, $db->name, $host, $port, $user, $pass, $backupDir)) {
                    continue;
                }
            }

            $escaped = '`' . str_replace('`', '``', $db->name) . '`';

            try {
                $pdo->exec("DROP DATABASE {$escaped}");
                $output->writeln("  ✔ Dropped <info>{$db->name}</info>");
            } catch (PDOException $e) {
                $output->writeln("<error>  Failed to drop {$db->name}:</error> {$e->getMessage()}");
            }
        }

        return Command::SUCCESS;
    }

    private function backupDatabase(
        OutputInterface $output,
        string $dbName,
        string $host,
        int $port,
        string $user,
        string $pass,
        string $backupDir,
    ): bool {
        if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
            $output->writeln("<error>  Cannot create backup directory: {$backupDir}</error>");
            return false;
        }

        $timestamp = date('Ymd_His');
        $safeName = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $dbName);
        $file = "{$backupDir}/{$safeName}_{$timestamp}.sql.gz";

        $passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';
        // pipefail ensures a mysqldump failure propagates through the pipe
        $cmd = sprintf(
            'bash -c %s',
            escapeshellarg(sprintf(
                'set -o pipefail && mysqldump -h %s -P %d -u %s %s --single-transaction --quick %s | gzip -9 > %s',
                escapeshellarg($host),
                $port,
                escapeshellarg($user),
                $passArg,
                escapeshellarg($dbName),
                escapeshellarg($file)
            ))
        );

        $output->writeln("  ==> Backing up <info>{$dbName}</info>");

        exec($cmd, $cmdOutput, $exitCode);

        // Valid gzip files containing data will exceed the empty gzip header size (~25 bytes)
        if ($exitCode !== 0 || !file_exists($file) || filesize($file) <= 25) {
            $output->writeln("<error>  Backup failed for {$dbName}, aborting drop.</error>");
            @unlink($file);
            return false;
        }

        $sizeMb = sprintf('%.2f MB', filesize($file) / 1024 / 1024);
        $output->writeln("    Saved {$sizeMb} → <info>{$file}</info>");

        return true;
    }

    /** @return list<string> */
    private function loadDbIgnoreFile(string $homeDir): array
    {
        if ($homeDir === '') {
            return [];
        }

        $path = "{$homeDir}/.dbignore";

        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $names = [];
        foreach ($lines as $line) {
            $trimmed = trim($line, " \t\n\r\0\x0B\"'");
            if ($trimmed !== '' && $trimmed[0] !== '#') {
                $names[] = $trimmed;
            }
        }

        return $names;
    }
}
