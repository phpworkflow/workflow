<?php

namespace Workflow\Logger;

use Workflow\Storage\IStorage;

/**
 * Class Logger
 * @package Workflow\Logger
 */
class Logger implements ILogger
{
    public const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    static protected array $levels = [
        self::ERROR => 0,
        self::WARN => 1,
        self::INFO => 2,
        self::DEBUG => 3
    ];
    /**
     * @var string
     */
    protected $log_channel;

    private static ?IStorage $storage = null;

    private static ?Logger $logger = null;

    /**
     * @var int
     */
    protected int $log_level;

    /**
     * @param IStorage|null $storage
     * @param string $log_level
     * @return ILogger
     */
    public static function instance(IStorage $storage = null, string $log_level = self::WARN): ILogger
    {
        if (self::$logger === null) {
            self::$logger = new Logger();
        }

        self::$logger->log_level = self::$levels[$log_level];

        if($storage) {
            self::$logger->set_storage($storage);
        }

        return self::$logger;
    }

    /**
     * @param string $log_level
     * @param int $log_channel
     */
    protected function __construct(string $log_level = self::WARN, int $log_channel = self::LOG_DATABASE)
    {
        $this->log_level = $log_level;
        $this->log_channel = $log_channel;
    }

    /**
     * @param IStorage|null $storage
     */
    protected function set_storage(IStorage $storage = null)
    {
        self::$storage = $storage;
    }

    /**
     * @param $log_channel
     */
    public function set_log_channel($log_channel): void
    {
        $this->log_channel = $log_channel;
    }

    /**
     * @param string $log_level
     * @return void
     */
    public function set_log_level(string $log_level = self::WARN): void
    {
        $this->log_level = self::$levels[$log_level] ?? 0;
    }

    /**
     * @param $message
     * @param array $context
     */
    public function debug($message, array $context = []): void
    {
        if($this->log_level >= self::$levels[self::DEBUG]) {
            $this->write_log($message);
        }
    }

    /**
     * @param $message
     * @param array $context
     */
    public function info($message, array $context = []): void
    {
        if($this->log_level >= self::$levels[self::INFO]) {
            $this->write_log($message, self::INFO);
        }
    }

    /**
     * @param $message
     * @param array $context
     */
    public function warn($message, array $context = []): void
    {
        if($this->log_level >= self::$levels[self::WARN]) {
            $this->write_log($message, self::WARN);
        }
    }

    /**
     * @param $message
     * @param array $context
     */
    public function error($message, array $context = []): void
    {
        if($this->log_level >= self::$levels[self::ERROR]) {
            $this->write_log($message, self::ERROR);
        }
    }

    /**
     * @param $message
     * @param array $context
     */
    public function emergency($message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function alert($message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function critical($message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function warning($message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function notice($message, array $context = []): void
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $level
     * @param $message
     * @param array $context
     */
    public function log($level, $message, array $context = []): void
    {
        $logContext = empty($context) ? '' : "\nContext: ".json_encode($context);
        $this->debug($level.' '.$message.$logContext);
    }

    /**
     * @return int|string
     */
    private function getLogChannel()
    {
        if ($this->log_channel === self::LOG_DATABASE && self::$storage === null) {
            return self::LOG_CONSOLE;
        }
        return $this->log_channel;
    }

    /**
     * @param string $message
     * @param int $workflowId
     */
    protected function store_log(string $message, int $workflowId = 0) {
        if(self::$storage) {
            self::$storage->store_log($message, $workflowId);
            return;
        }
        error_log("$workflowId: $message");
    }

    /**
     * @param $message
     * @param string $level
     */
    protected function write_log($message, string $level = self::DEBUG)
    {

        if (!is_string($message)) {
            $message = var_export($message, true);
        }

        $timestamp = date(self::TIMESTAMP_FORMAT);

        $log_channel = $this->getLogChannel();

        switch ($log_channel) {
            case self::LOG_STDOUT:
            {
                print_r("$timestamp $level: $message\n");
                break;
            }
            case self::LOG_CONSOLE:
            {
                error_log("$timestamp $level: $message");
                break;
            }
            case self::LOG_FILE:
            {
                // TODO implement log to file
                break;
            }
            case self::LOG_DATABASE:
            {
                $this->store_log($message);
                break;
            }

        }
    }
}