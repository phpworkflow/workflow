<?php
declare(ticks=1);

namespace Workflow\Engine;

use Workflow\Logger\ILogger;
use Workflow\Storage\IStorage;

abstract class AbstractEngine
{
    /** @var AbstractEngine $instance */
    static protected AbstractEngine $instance;

    protected IStorage $storage;

    protected ILogger $logger;

    protected bool $exit;

    protected function __construct(IStorage $storage, ILogger $logger)
    {
        $this->storage = $storage;
        $this->logger = $logger;
        $this->exit = false;
    }

    public static function instance(IStorage $storage, ILogger $logger):self
    {
        if (empty(self::$instance)) {
            self::$instance = new static($storage, $logger);
        }
        return self::$instance;
    }

    abstract public function run(array $workflows=[]);

}