<?php declare (strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('UTC');


function run(array $argv)
{
    $input = Input::parseFromArgv($argv);
    $output = new Output();

    $app = new Application();

    $app->addCommand(new DumpCommand());
    $app->addCommand(new HelpCommand());
    $app->addCommand(new VersionCommand());

    $method = $input->getArgument(0);
    if ($input->getOption('help') === true) {
        $input->setOption('method', $method);
        $method = 'help';
    }

    $command = $app->getCommand($method, 'help');

    if (!$command) {
        $output->error("Unknown hl-backup command: $method");
        $output->error("did you mean:");
        foreach ($app->getCommands() as $command) {
            $output->error('  ' . $command->getName());
        }
        return ResultCode::ERROR;
    }

    try {
        $input->parseAgainstOptions($command->getOptions());

        return $command->execute($input, $output, $app);

    } catch (AppException $e) {
        $output->error($e->getMessage());
        return ResultCode::ERROR;
    }
}


class DumpCommand extends Command
{
    public function getName(): string
    {
        return 'dump';
    }

    public function getOptions(): array
    {
        return [
            new InputOption('db-host',              required: false, cast: InputOptionCast::STRING, default: 'localhost', description: 'The database host e.g. localhost, 127.0.0.1, /var/socket, db.host.tld'),
            new InputOption('db-port',              required: false, cast: InputOptionCast::INT,    default: 3306,        description: 'The database port'),
            new InputOption('db-username',          required: false, cast: InputOptionCast::STRING, default: 'root',      description: 'The database username'),
            new InputOption('db-password',          required: false, cast: InputOptionCast::STRING, default: '',          description: 'The database password'),
            new InputOption('db-database',          required: true,  cast: InputOptionCast::STRING, default: null,        description: 'The database to export'),
            new InputOption('db-tables-allowlist',  required: false, cast: InputOptionCast::ARRAY,  default: [],          description: 'A comma-separated list of tables to export'),
            new InputOption('db-tables-blocklist',  required: false, cast: InputOptionCast::ARRAY,  default: [],          description: 'A comma-separated list of tables to skip'),
            new InputOption('compression-level',    required: true,  cast: InputOptionCast::INT,    default: 6,           description: 'Compression level from 0 to 9'),
            new InputOption('encryption-key',       required: true,  cast: InputOptionCast::STRING, default: null,        description: 'The age public key to encrypt the file with'),
            new InputOption('heartbeat-start',      required: false, cast: InputOptionCast::STRING, default: null,        description: 'A URL to POST to when starting an export'),
            new InputOption('heartbeat-finish',     required: false, cast: InputOptionCast::STRING, default: null,        description: 'A URL to POST to when an export finishes'),
            new InputOption('heartbeat-fail',       required: false, cast: InputOptionCast::STRING, default: null,        description: 'A URL to POST to when an export fails'),
            new InputOption('s3-access-key',        required: true,  cast: InputOptionCast::STRING, default: null,        description: 'S3 access key'),
            new InputOption('s3-secret-key',        required: true,  cast: InputOptionCast::STRING, default: null,        description: 'S3 secret key'),
            new InputOption('s3-endpoint',          required: false, cast: InputOptionCast::STRING, default: null,        description: 'S3 endpoint e.g. https://s3.us-west-001.backblazeb2.com to use Backblaze B2'),
            new InputOption('s3-region',            required: false, cast: InputOptionCast::STRING, default: null,        description: 'S3 region'),
            new InputOption('s3-bucket',            required: true,  cast: InputOptionCast::STRING, default: null,        description: 'S3 bucket'),
            new InputOption('s3-file-name',         required: false, cast: InputOptionCast::STRING, default: 'export-{{database}}-{{YYYY}}-{{MM}}-{{DD}}-{{hh}}{{mm}}{{ss}}.sql.xz.age', description: 'The destination filename for S3. Can include directories and substitutions.'),
            new InputOption('debug',                required: false, cast: InputOptionCast::BOOL,   default: false,       description: 'If set, output debug information'),
            new InputOption('dry-run',              required: false, cast: InputOptionCast::BOOL,   default: false,       description: 'If set, perform the export but don\'t upload'),
            new InputOption('help', description: 'Get help about this method'),
        ];
    }

    public function execute(Input $input, Output $output, Application $app): ResultCode
    {
        $dumper = new Dumper(
            $app,
            new Logger($output, $input->getOption('debug')),
        );

        try {
            $uploadUrl = $dumper->dump(new DumperOptions(
                dbHost:            $input->getOption('db-host'),
                dbPort:            $input->getOption('db-port'),
                dbUsername:        $input->getOption('db-username'),
                dbPassword:        $input->getOption('db-password'),
                dbDatabase:        $input->getOption('db-database'),
                dbTablesAllowlist: $input->getOption('db-tables-allowlist'),
                dbTablesBlocklist: $input->getOption('db-tables-blocklist'),
                compressionLevel:  $input->getOption('compression-level'),
                encryptionKey:     $input->getOption('encryption-key'),
                heartbeatStart:    $input->getOption('heartbeat-start'),
                heartbeatFinish:   $input->getOption('heartbeat-finish'),
                heartbeatFail:     $input->getOption('heartbeat-fail'),
                s3AccessKey:       $input->getOption('s3-access-key'),
                s3SecretKey:       $input->getOption('s3-secret-key'),
                s3Endpoint:        $input->getOption('s3-endpoint'),
                s3Region:          $input->getOption('s3-region'),
                s3Bucket:          $input->getOption('s3-bucket'),
                s3FileName:        $input->getOption('s3-file-name'),
                dryRun:            $input->getOption('dry-run'),
            ));
            $output->line($uploadUrl);
            return ResultCode::SUCCESS;
        } catch (\DumperException $e) {
            $output->error('Dump failed to complete: ' . $e->getMessage());
            return ResultCode::ERROR;
        }
    }
}


class HelpCommand extends Command
{
    public function getName(): string
    {
        return 'help';
    }

    public function getOptions(): array
    {
        return [
            new InputOption('method', description: 'Provide help for the given method.'),
            new InputOption('help', description: 'Get help about this method'),
        ];
    }

    public function execute(Input $input, Output $output, Application $app): ResultCode
    {
        $method = $input->getOption('method');
        if ($method !== null) {
            return $this->helpMethod($input, $output, $app, $method);
        } else {
            return $this->helpApp($input, $output, $app);
        }
    }

    public function helpMethod(Input $input, Output $output, Application $app, string $method): ResultCode
    {
        $command = $app->getCommand($method);
        if (!$command) {
            $output->error("Unknown hl-backup command to help with: $method");
            return ResultCode::ERROR;
        }

        $output->line('hl-backup v' . $app->getVersion());
        $output->line('  usage: hl-backup ' . $command->getName() . ' [options]');

        $options = $command->getOptions();
        if (count($options) > 0) {
            $output->line('options:');

            $longestName = 0;

            foreach ($command->getOptions() as $option) {
                $len = mb_strlen($option->getName());
                if ($len > $longestName) {
                    $longestName = $len;
                }
            }

            foreach ($command->getOptions() as $option) {
                $name = $option->getName();
                $line = [
                    '  ',
                    $name,
                    '  ',
                    str_repeat(' ', $longestName - mb_strlen($name)),
                    ($option->isRequired() ? '[REQUIRED] ' : ''),
                    $option->getDescription(),
                    ($option->getDefault() ? ' [default = ' . $option->getDefault() . ']' : ''),
                ];
                $output->line(implode('', $line));
            }
        }

        return ResultCode::SUCCESS;
    }

    public function helpApp(Input $input, Output $output, Application $app): ResultCode
    {
        $output->line('hl-backup v' . $app->getVersion());
        $output->line('  usage: hl-backup [method] [options]');
        $output->line('methods:');
        foreach ($app->getCommands() as $command) {
            $output->line('  ' . $command->getName());
        }

        return ResultCode::SUCCESS;
    }
}


class VersionCommand extends Command
{
    public function getName(): string
    {
        return 'version';
    }

    public function getOptions(): array
    {
        return [
            new InputOption('help', description: 'Get help about this method'),
        ];
    }

    public function execute(Input $input, Output $output, Application $app): ResultCode
    {
        $output->line('hl-backup');
        $output->line('  version ' . $app->getVersion());
        $output->line('  created by Luke Lanchester <hlbackup@lukelanchester.com>');

        $output->line('dependencies:');
        $deps = $app->getDependencies();
        foreach ($deps as $name => $dep) {
            if ($dep['found'] !== true) {
                $output->line('  ' . $name . ' WARNING: dependency not found');
            } else {
                $output->line('  ' . $name . ' @ ' . $dep['bin'] . ' [' . $dep['version'] . ']');
            }
        }

        return ResultCode::SUCCESS;
    }
}


class AppException extends \Exception {}


class Application
{
    const VERSION = '1.2.0';

    private ?array $deps = null;
    private array $commands = [];

    public function getVersion(): string
    {
        return self::VERSION;
    }

    public function getDependencies(): array
    {
        if ($this->deps !== null) {
            return $this->deps;
        }

        $checks = [
            'age'       => ['bin' => 'age',       'version_flag' => '--version'],
            'mysqldump' => ['bin' => 'mysqldump', 'version_flag' => '--version'],
            'php'       => ['bin' => 'php',       'version_flag' => '--version'],
            's5cmd'     => ['bin' => 's5cmd',     'version_flag' => 'version'],
            'xz'        => ['bin' => 'xz',        'version_flag' => '--version'],
        ];

        $output = [];
        foreach ($checks as $name => $check) {
            $bin = exec('which ' . $check['bin']);
            if ($bin === '') {
                $output[$name] = ['bin' => null, 'found' => false];
                continue;
            }

            exec("$bin {$check['version_flag']} 2>&1", $version);
            $version = $version && count($version) > 0 ? $version[0] : null;
            $output[$name] = ['bin' => $bin, 'found' => true, 'version' => $version];
        }

        return $this->deps = $output;
    }

    public function addCommand(Command $command): void
    {
        $this->commands[$command->getName()] = $command;
    }

    public function getCommands(): array
    {
        $commands = $this->commands;
        ksort($commands);
        return $commands;
    }

    public function getCommand(?string $method, ?string $defaultMethod = null): ?Command
    {
        if ($method === null) {
            $method = $defaultMethod;
        }
        return array_key_exists($method, $this->commands) ? $this->commands[$method] : null;
    }
}


class Input
{
    public static function parseFromArgv($argv)
    {
        $args = [];
        $opts = [];
        array_shift($argv); // first arg is filename
        foreach ($argv as $rawArg) {
            if (str_starts_with($rawArg, '--')) {
                $rawArg = mb_substr($rawArg, 2);
                if (str_contains($rawArg, '=')) {
                    [$key, $value] = explode('=', $rawArg, 2);
                    $opts[$key] = $value;
                } else {
                    $opts[$rawArg] = true;
                }
            } else {
                $args[] = $rawArg;
            }
        }
        return new self($args, $opts);
    }

    public function __construct(
        private array $arguments,
        private array $options,
    ) {}

    public function getArguments(): array
    {
        return $this->arguments;
    }

    public function getArgument(int $idx, mixed $default = null): mixed
    {
        return array_key_exists($idx, $this->arguments) ? $this->arguments[$idx] : $default;
    }

    public function setArgument(int $idx, mixed $value): void
    {
        $this->arguments[$idx] = $value;
    }

    public function getOptions(): array
    {
        return $this->options;
    }

    public function getOption(string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->options) ? $this->options[$key] : $default;
    }

    public function setOption(string $key, mixed $value): void
    {
        $this->options[$key] = $value;
    }

    public function parseAgainstOptions(array $options): void
    {
        $rawInputs = $this->options;

        $keyedOptions = [];
        foreach ($options as $option) {
            $keyedOptions[$option->getName()] = $option;
        }

        foreach (array_keys($rawInputs) as $key) {
            if (!array_key_exists($key, $keyedOptions)) {
                throw new AppException("The --$key option does not exist.");
            }
        }

        foreach ($keyedOptions as $key => $option) {
            if (!array_key_exists($key, $rawInputs)) {
                if ($option->isRequired() && $option->getDefault() === null) {
                    throw new AppException("The --$key option is required.");
                }
                $value = $option->getDefault();
            } else {
                $value = $rawInputs[$key];
            }

            $cast = $option->getCast();
            $castAsArrayFn = function ($val) {
                if (is_string($val)) {
                    $val = explode(',', $val);
                }
                return array_unique(array_filter($val));
            };
            $value = match ($cast) {
                InputOptionCast::INT    => intval($value),
                InputOptionCast::ARRAY  => $castAsArrayFn($value),
                InputOptionCast::STRING => strval($value),
                InputOptionCast::BOOL   => (bool) $value,
                default                 => $value,
            };

            if ($option->isRequired() && $value === '') {
                throw new AppException("The --$key option must have a value.");
            }

            $this->setOption($key, $value);
        }
    }
}


enum InputOptionCast : string
{
    case ARRAY  = 'array';
    case BOOL   = 'bool';
    case INT    = 'int';
    case STRING = 'string';
}


class InputOption
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?InputOptionCast $cast = null,
        public readonly mixed $default = null,
        public readonly bool $required = false,
    )
    {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getCast(): ?InputOptionCast
    {
        return $this->cast;
    }

    public function getDefault(): mixed
    {
        return $this->default;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }
}


class Output
{
    public function error(string $msg): void
    {
        fwrite(STDERR, $msg . "\n");
    }

    public function line(string $msg): void
    {
        fwrite(STDOUT, $msg . "\n");
    }

    public function debug(string $msg)
    {
        $this->line('[debug] ' . DateTime::createFromFormat('U.u', (string) microtime(true))->format('H:i:s.u') . ' ' . $msg);
    }

    public function info(string $msg)
    {
        $this->line('[info] ' . DateTime::createFromFormat('U.u', (string) microtime(true))->format('H:i:s.u') . ' ' . $msg);
    }
}


enum ResultCode : int
{
    case SUCCESS = 0;
    case ERROR   = 1;

}


abstract class Command
{
    abstract function getName(): string;

    abstract function getOptions(): array;

    abstract function execute(Input $input, Output $output, Application $app): ResultCode;
}


interface DumperLogger
{
    public function info(string $msg): void;
    public function error(string $msg): void;
    public function debug(string $msg): void;
}


class Logger implements DumperLogger
{
    public function __construct(
        private Output $output,
        private bool $enableDebug,
    )
    {}

    public function info(string $msg): void
    {
        $this->output->line('[info] ' . DateTime::createFromFormat('U.u', (string) microtime(true))->format('H:i:s.u') . ' ' . $msg);
    }

    public function error(string $msg): void
    {
        $this->output->error('[error] ' . DateTime::createFromFormat('U.u', (string) microtime(true))->format('H:i:s.u') . ' ' . $msg);
    }

    public function debug(string $msg): void
    {
        if ($this->enableDebug) {
            $this->output->line('[debug] ' . DateTime::createFromFormat('U.u', (string) microtime(true))->format('H:i:s.u') . ' ' . $msg);
        }
    }
}


class DumperOptions
{
    public function __construct(
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbUsername,
        public readonly string $dbPassword,
        public readonly string $dbDatabase,
        public readonly array $dbTablesAllowlist,
        public readonly array $dbTablesBlocklist,
        public readonly int $compressionLevel,
        public readonly ?string $encryptionKey,
        public readonly string $heartbeatStart,
        public readonly string $heartbeatFinish,
        public readonly string $heartbeatFail,
        public readonly string $s3AccessKey,
        public readonly string $s3SecretKey,
        public readonly string $s3Endpoint,
        public readonly string $s3Region,
        public readonly string $s3Bucket,
        public readonly string $s3FileName,
        public readonly bool $dryRun,
    )
    {}
}


class DumperException extends \Exception
{}


class Dumper
{
    const DEFAULT_COMPRESSION = 6;

    public function __construct(
        private Application $app,
        private DumperLogger $logger,
        private array $filesToCleanup = [],
    )
    {}

    public function dump(DumperOptions $opts): string
    {
        $this->logger->debug('starting');
        $this->logger->debug('');

        try {
            if (!$opts->dryRun) {
                $this->sendHeartbeat($opts->heartbeatStart);
            }

            $timerStart = microtime(true);

            $this->checkDependencies();

            $db = $this->connectToDatabase($opts);

            $tables = $this->scanTables($db, $opts);

            $localDumpFile = $this->dumpTables($tables, $opts);

            $localCompressedFile = $this->compressDump($localDumpFile, $opts);

            $localEncryptedFile = $this->encryptDump($localCompressedFile, $opts);

            if ($opts->dryRun) {
                $this->logger->debug('skipping upload due to dry-run mode');
                $this->logger->debug('');
                $uploadUrl = '';
            } else {
                $uploadUrl = $this->uploadDump($localEncryptedFile, $opts);
            }

            $timerEnd = microtime(true);

            if (!$opts->dryRun) {
                $this->sendHeartbeat($opts->heartbeatFinish, ['time_taken' => round($timerEnd-$timerStart, 4), 'uploaded_url' => $uploadUrl]);
            }

            return $uploadUrl;

        } catch (DumperException $e) {
            if (!$opts->dryRun) {
                $this->sendHeartbeat($opts->heartbeatFail, ['error' => $e->getMessage()]);
            }
            throw $e;
        }
    }

    private function sendHeartbeat(?string $url, ?array $data = null): void
    {
        if ($url === null || $url === '') {
            return;
        }
        $queryString = $data ? http_build_query($data) : '';
        $this->logger->debug("sending heartbeat: $url " . $queryString);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        if ($data && count($data) > 0) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $queryString);
        }
        curl_exec($ch);
        curl_close($ch);
    }

    private function checkDependencies(): void
    {
        $this->logger->debug('initializing...');
        $deps = $this->app->getDependencies();
        $missingDeps = [];
        foreach ($deps as $dep => $info) {
            if ($info['found'] !== true) {
                $missingDeps[] = $dep;
            } else {
                $this->logger->debug(" dependency found: $dep @ {$info['version']}");
            }
        }
        if (count($missingDeps) > 0) {
            throw new DumperException('Missing dependencies: ' . implode(', ', $missingDeps));
        }
        $this->logger->debug('all dependencies found');
        $this->logger->debug('');
    }

    private function connectToDatabase(DumperOptions $opts): \PDO
    {
        $this->logger->debug('connecting...');
        $this->logger->debug('  host: ' . $opts->dbHost);
        $this->logger->debug('  port: ' . $opts->dbPort);
        $this->logger->debug('  database: ' . $opts->dbDatabase);
        $this->logger->debug('  username: ' . $opts->dbUsername);
        $this->logger->debug('  password: ' . str_repeat('*', mb_strlen($opts->dbPassword)));

        try {
            $pdo = new PDO("mysql:host={$opts->dbHost};port={$opts->dbPort};dbname={$opts->dbDatabase}", $opts->dbUsername, $opts->dbPassword);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $e) {
            throw new DumperException('Unable to connect to database: ' . $e->getMessage(), 0, $e);
        }

        $this->logger->debug('connected to database');
        $this->logger->debug('');

        return $pdo;
    }

    private function scanTables(\PDO $db, DumperOptions $opts): array
    {
        $this->logger->debug('scanning...');


        $query = $db->query('show tables');
        $tables = $query->fetchAll(PDO::FETCH_COLUMN);
        $totalTables = $numTables = count($tables);

        if ($totalTables === 0) {
            throw new DumperException('No tables found to export.');
        }
        $this->logger->debug('  found ' . $totalTables . ' table(s) in scan');

        if ($opts->dbTablesAllowlist) {
            $newTables = [];
            $allTables = array_fill_keys($tables, true);
            $missingTables = [];
            foreach ($opts->dbTablesAllowlist as $table) {
                if (!array_key_exists($table, $allTables)) {
                    $missingTables[] = $table;
                    $this->logger->error('  allowlist table not found: ' . $table);
                    continue;
                }
                $this->logger->debug('  including allowlist table: ' . $table);
                $newTables[] = $table;
            }
            if (count($missingTables) > 0) {
                throw new DumperException('Required allowlist tables not found: ' . implode(', ', $missingTables));
            }
            $tables = $newTables;
            $numTables = count($tables);
        }

        if ($opts->dbTablesBlocklist) {
            $newTables = [];
            $skipTables = array_fill_keys($opts->dbTablesBlocklist, true);
            foreach ($tables as $table) {
                if (array_key_exists($table, $skipTables)) {
                    $this->logger->debug('  skipping blocklist table: ' . $table);
                    continue;
                }
                $newTables[] = $table;
            }
            $tables = $newTables;
            $numTables = count($tables);
        }

        if ($tables === 0) {
            throw new DumperException('No tables found to export after filtering.');
        }
        if ($numTables !== $totalTables) {
            $this->logger->debug('  reduced to ' . $numTables . ' table(s) after filtering');
            $this->logger->debug('');
        }

        return $tables;
    }

    private function dumpTables(array $tables, DumperOptions $opts): string
    {
        try {
            $this->logger->debug('dumping...');

            $outputFile = tempnam(sys_get_temp_dir(), 'hl-mysql-export');
            $this->filesToCleanup[] = $outputFile;
            $this->logger->debug('  created output file: ' . $outputFile);

            $args = [];
            $args[] = 'mysqldump';
            $args[] = '--add-drop-table';
            $args[] = '--add-locks';
            $args[] = '--allow-keywords';
            $args[] = '--compress';
            $args[] = '--create-options';
            $args[] = '--disable-keys';
            $args[] = '--extended-insert';
            $args[] = '--max_allowed_packet=512M';
            $args[] = '--no-tablespaces';
            $args[] = '--quick';
            $args[] = '--set-charset';
            $args[] = '--single-transaction'; // dont lock all tables, but ensure consistent export
            $args[] = '--host=' . escapeshellarg($opts->dbHost);
            $args[] = '--port=' . escapeshellarg((string) $opts->dbPort);
            $args[] = '--user=' . escapeshellarg($opts->dbUsername);
            $args[] = '--password=' . escapeshellarg($opts->dbPassword);
            $args[] = escapeshellarg($opts->dbDatabase);
            foreach ($tables as $table) {
                $args[] = escapeshellarg($table);
            }
            $args[] = '> ' . $outputFile;

            [$result, $output] = $this->executeCmd($args, debugReplace: [escapeshellarg($opts->dbPassword)]);

            if ($result !== 0) {
                throw new DumperException('Unable to dump database: ' . implode("\n", $output));
            }
            $filesize = filesize($outputFile);
            if ($filesize < 50) {
                throw new DumperException('Dumped database file has no content.');
            }

            $this->logger->debug('  database dumped successfully');
            $this->logger->debug('  dump size = ' . $this->bytesToHuman($filesize));
            $this->logger->debug('');

            return $outputFile;

        } catch (\Exception $e) {
            $this->cleanupFiles();
            throw $e;
        }
    }

    private function compressDump(string $inputFile, DumperOptions $opts): string
    {
        $outputFile = "$inputFile.xz";
        $this->filesToCleanup[] = $outputFile;

        try {

            $this->logger->debug('compressing...');

            [$result, $output] = $this->executeCmd([
                'xz',
                '--compress', // compress the file
                '--keep', // keep the original file
                '--force', // overwrite any existing file in output location
                '-' . escapeshellarg($opts->compressionLevel >= 0 && $opts->compressionLevel <= 9 ? (string) $opts->compressionLevel : self::DEFAULT_COMPRESSION),
                escapeshellarg($inputFile), // input file
                '> ' . escapeshellarg($outputFile), // output file
            ]);

            if ($result !== 0) {
                throw new DumperException('Unable to compress file: ' . implode("\n", $output));
            }
            if (!is_file($outputFile)) {
                throw new DumperException('Compressed output file not created.');
            }
            $filesize = filesize($outputFile);
            if ($filesize < 50) {
                throw new DumperException('Compressed output file too small.');
            }

            $this->logger->debug('  file compressed successfully');
            $this->logger->debug('  compressed size = ' . $this->bytesToHuman($filesize));
            $this->logger->debug('');

            return $outputFile;

        } catch (\Exception $e) {
            $this->cleanupFiles();
            throw $e;
        }
    }

    private function encryptDump(string $inputFile, DumperOptions $opts): string
    {
        $outputFile = "$inputFile.age";
        $this->filesToCleanup[] = $outputFile;

        try {

            $this->logger->debug('encrypting...');

            [$result, $output] = $this->executeCmd([
                'age',
                '--encrypt', // encrypt the file
                '-r ' . escapeshellarg($opts->encryptionKey), // the given public key to encrypt against
                '-o ' . escapeshellarg($outputFile), // the output file
                escapeshellarg($inputFile), // input file
            ], debugReplace: [escapeshellarg($opts->encryptionKey)]);

            if ($result !== 0) {
                throw new DumperException('Unable to encrypt file: ' . implode("\n", $output));
            }
            if (!is_file($outputFile)) {
                throw new DumperException('Encrypted output file not created.');
            }
            $filesize = filesize($outputFile);
            if ($filesize < 50) {
                throw new DumperException('Encrypted output file too small.');
            }

            $this->logger->debug('  file encrypted successfully');
            $this->logger->debug('  encrypted size = ' . $this->bytesToHuman($filesize));
            $this->logger->debug('');

            return $outputFile;

        } catch (\Exception $e) {
            $this->cleanupFiles();
            throw $e;
        }
    }

    private function uploadDump(string $inputFile, DumperOptions $opts): string
    {
        $now = new \DateTimeImmutable();

        $filename = $opts->s3FileName;
        $filename = strtr($filename, [
            '{{db-database}}' => $opts->dbDatabase,
            '{{db-user}}'     => $opts->dbUsername,
            '{{db-host}}'     => $opts->dbHost,
            '{{db-port}}'     => (string) $opts->dbPort,
            '{{YYYY}}'        => $now->format('Y'),
            '{{MM}}'          => $now->format('m'),
            '{{DD}}'          => $now->format('d'),
            '{{hh}}'          => $now->format('H'),
            '{{mm}}'          => $now->format('i'),
            '{{ss}}'          => $now->format('s'),
        ]);

        $outputUrl = 's3://' . $opts->s3Bucket . '/' . ltrim($filename, '/');

        try {

            $this->logger->debug('uploading...');

            $this->logger->debug('  destination: ' . $outputUrl);

            [$result, $output] = $this->executeCmd([
                'AWS_ACCESS_KEY_ID=' . escapeshellarg($opts->s3AccessKey),
                'AWS_SECRET_ACCESS_KEY=' . escapeshellarg($opts->s3SecretKey),
                'AWS_REGION=' . escapeshellarg($opts->s3Region),
                's5cmd',
                '--endpoint-url=' . escapeshellarg($opts->s3Endpoint),
                'cp',
                escapeshellarg($inputFile),
                escapeshellarg($outputUrl),
            ], debugReplace: [
                escapeshellarg($opts->s3AccessKey),
                escapeshellarg($opts->s3SecretKey),
            ]);

            if ($result !== 0) {
                throw new DumperException('Unable to upload file: ' . implode("\n", $output));
            }
            $this->logger->debug('  file uploaded');
            $this->logger->debug('');

            return $outputUrl;

        } catch (\Exception $e) {
            $this->cleanupFiles();
            throw $e;
        }
    }

    private function executeCmd(array $args, array $debugReplace = []): array
    {
        $cmd = implode(' ', $args);

        $replacePairs = [];
        if (count($debugReplace) > 0) {
            foreach ($debugReplace as $str) {
                $replacePairs[$str] = str_repeat('*', mb_strlen($str));
            }
        }
        $this->logger->debug('  running command: ' . strtr($cmd, $replacePairs));

        exec($cmd, $output, $result);
        $this->logger->debug('  result: ' . $result);
        return [$result, $output];
    }

    private function cleanupFiles(): void
    {
        foreach ($this->filesToCleanup as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function bytesToHuman(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];

        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}


run($argv);
