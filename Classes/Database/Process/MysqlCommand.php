<?php
declare(strict_types=1);
namespace Helhum\Typo3Console\Database\Process;

/*
 * This file is part of the TYPO3 Console project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read
 * LICENSE file that was distributed with this source code.
 *
 */

use Helhum\Typo3Console\Mvc\Cli\InteractiveProcess;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\ProcessBuilder;

class MysqlCommand
{
    /**
     * @var ProcessBuilder
     */
    private $processBuilder;

    /**
     * @var array
     */
    private $dbConfig = [];

    private static $mysqlTempFile;

    /**
     * MysqlCommand constructor.
     *
     * @param array $dbConfig
     * @param ProcessBuilder $processBuilder
     */
    public function __construct(array $dbConfig, ProcessBuilder $processBuilder)
    {
        $this->dbConfig = $dbConfig;
        $this->processBuilder = $processBuilder;
        $this->processBuilder->setTimeout(null);
    }

    public function __destruct()
    {
        if (self::$mysqlTempFile !== null && file_exists(self::$mysqlTempFile)) {
            unlink(self::$mysqlTempFile);
        }
    }

    /**
     * @param array $additionalArguments
     * @param resource $inputStream
     * @param null $outputCallback
     * @param bool $interactive
     * @return int
     */
    public function mysql(array $additionalArguments = [], $inputStream = STDIN, $outputCallback = null, $interactive = false)
    {
        $this->processBuilder->setPrefix('mysql');
        $this->processBuilder->setArguments(array_merge($this->buildConnectionArguments(), $additionalArguments));
        if ($interactive) {
            // I did not figure out how to change pipes with symfony/process
            $interactiveProcess = new InteractiveProcess();
            return $interactiveProcess->run($this->processBuilder->getProcess()->getCommandLine());
        }
        $process = $this->processBuilder->getProcess();
        $process->setInput($inputStream);
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param array $additionalArguments
     * @param null $outputCallback
     * @return int
     */
    public function mysqldump(array $additionalArguments = [], $outputCallback = null): int
    {
        $this->processBuilder->setPrefix('mysqldump');
        $this->processBuilder->setArguments(array_merge($this->buildConnectionArguments(), $additionalArguments));
        $process = $this->processBuilder->getProcess();
        return $process->run($this->buildDefaultOutputCallback($outputCallback));
    }

    /**
     * @param callable $outputCallback
     * @return callable
     */
    private function buildDefaultOutputCallback($outputCallback): callable
    {
        if (!is_callable($outputCallback)) {
            $outputCallback = function ($type, $output) {
                if (Process::OUT === $type) {
                    // Explicitly just echo out for now (avoid symfony console formatting)
                    echo $output;
                }
            };
        }
        return $outputCallback;
    }

    private function buildConnectionArguments(): array
    {
        if ($configFile = $this->createTemporaryMysqlConfigurationFile()) {
            $arguments[] = '--defaults-extra-file=' . $configFile;
        }
        if (!empty($this->dbConfig['host'])) {
            $arguments[] = '-h';
            $arguments[] = $this->dbConfig['host'];
        }
        if (!empty($this->dbConfig['port'])) {
            $arguments[] = '-P';
            $arguments[] = $this->dbConfig['port'];
        }
        if (!empty($this->dbConfig['unix_socket'])) {
            $arguments[] = '-S';
            $arguments[] = $this->dbConfig['unix_socket'];
        }
        $arguments[] = $this->dbConfig['dbname'];
        return $arguments;
    }

    private function createTemporaryMysqlConfigurationFile()
    {
        if (empty($this->dbConfig['user']) && !isset($this->dbConfig['password'])) {
            return null;
        }
        if (self::$mysqlTempFile !== null && file_exists(self::$mysqlTempFile)) {
            return self::$mysqlTempFile;
        }
        $userDefinition = '';
        $passwordDefinition = '';
        if (!empty($this->dbConfig['user'])) {
            $userDefinition = sprintf('user="%s"', $this->dbConfig['user']);
        }
        if (!empty($this->dbConfig['password'])) {
            $passwordDefinition = sprintf('password="%s"', $this->dbConfig['password']);
        }
        $confFileContent = <<<EOF
[mysqldump]
$userDefinition
$passwordDefinition

[client]
$userDefinition
$passwordDefinition
EOF;
        self::$mysqlTempFile = tempnam(sys_get_temp_dir(), 'typo3_console_my_cnf_');
        file_put_contents(self::$mysqlTempFile, $confFileContent);

        return self::$mysqlTempFile;
    }
}
