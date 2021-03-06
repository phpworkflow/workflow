<?php

namespace Workflow\Logger;

use Workflow\Storage\IStorage;

/**
 * Class Logger
 * @package Workflow\Logger
 */
class Logger implements ILogger
{
    const TIMESTAMP_FORMAT = 'Y-m-d H:i:s';

    /**
     * @var string
     */
    protected $log_channel;

    /** @var IStorage $storage */
    private static $storage = null;

    /**
     * @var Logger
     */
    private static $logger = null;

    /**
     * @param IStorage|null $storage
     * @return ILogger
     */
    public static function instance(IStorage $storage = null): ILogger
    {
        if (self::$logger === null) {
            self::$logger = new Logger();
        }

        if($storage) {
            self::$logger->set_storage($storage);
        }

        return self::$logger;
    }

    /**
     * Logger constructor.
     * @param int $log_channel
     */
    protected function __construct($log_channel = self::LOG_DATABASE)
    {
        $this->set_log_channel($log_channel);
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
    public function set_log_channel($log_channel)
    {
        $this->log_channel = $log_channel;
    }

    /**
     * @param $message
     * @param array $context
     */
    public function debug($message, array $context = array())
    {
        $this->write_log($message);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function info($message, array $context = array())
    {
        $this->write_log($message, self::INFO);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function warn($message, array $context = array())
    {
        $this->write_log($message, self::WARN);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function error($message, array $context = array())
    {
        $this->write_log($message, self::ERROR);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function emergency($message, array $context = array())
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function alert($message, array $context = array())
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function critical($message, array $context = array())
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function warning($message, array $context = array())
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $message
     * @param array $context
     */
    public function notice($message, array $context = array())
    {
        $this->log(__FUNCTION__, $message, $context);
    }

    /**
     * @param $level
     * @param $message
     * @param array $context
     */
    public function log($level, $message, array $context = array())
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
     * @param $message
     * @param int $workflowId
     */
    protected function store_log($message, int $workflowId = 0) {
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
    protected function write_log($message, $level = self::DEBUG)
    {

        if (!is_string($message)) {
            $message = var_export($message);
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