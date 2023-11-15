<?php

namespace Workflow\Storage\Redis;

use Redis;
use Throwable;

class Queue
{
    protected const LAST_ID_KEY_PREFIX = 'wf_queue_last_id_';

    protected const DEFAULT_LAST_ID = '0-0';

    protected array $queues = [];

    protected Connection $connection;

    protected int $blockTimeMs;

    protected int $maxLength;
    /**
     * @var string
     */

    protected bool $configNotExists;

    public function __construct(array $queues, int $maxLength = 10000, int $timeLimitSec = 0, int $blockTimeMs = 1000)
    {
        $this->maxLength = $maxLength;
        $this->blockTimeMs = $blockTimeMs;

        foreach ($queues as $queue) {
            $this->queues[$queue] = $timeLimitSec > 0
                ? ((time() - $timeLimitSec) * 1000) . '-0'
                : self::DEFAULT_LAST_ID;
        }

        $this->configNotExists = empty((new Config())->host());
    }

    public function isRedisConnected()
    {
        return !$this->configNotExists && $this->redis()->isConnected();
    }

    protected function redis(): Redis
    {
        if(empty($this->connection)) {
            $this->connection = new Connection(new Config());
        }

        return $this->connection->connection();
    }

    protected function getFirstQueueName(): string
    {
        return array_keys($this->queues)[0];
    }

    protected function getQueues(): array
    {
        foreach ($this->queues as $queue => $lastId) {
            if($lastId === self::DEFAULT_LAST_ID) {
                $this->queues[$queue] = $this->getLastId($queue);
            }
        }

        return $this->queues;
    }

    public function len(?string $queue = null): int
    {
        if($this->configNotExists) {
            return 0;
        }
        $queue = $queue ?: $this->getFirstQueueName();

        return $this->redis()->xLen($queue);
    }

    /**
     * @param array $message
     */
    public function push(Event $event, string $id = '*'): string
    {
        if($this->configNotExists) {
            return '';
        }

        $messageId = $this->redis()->xAdd(
            $this->getFirstQueueName(),
            $id,
            $event->toArray(),
            $this->maxLength
        );

        return $messageId;
    }

    /**
     * @param string $id
     * @param int $count
     * @return Event[]
     */
    public function pop(?int $count = null): array
    {
        if($this->configNotExists) {
            return [];
        }

        $result = $this->redis()->xRead($this->queues, $count);

        return $this->procResult($result);
    }

    /**
     * Read messages BLOCKED mode
     * @param int $count
     * @return Event[]
     */
    public function blPop(int $count = 1): array
    {
        if($this->configNotExists) {
            return [];
        }

        try {
            $result = $this->redis()->xRead($this->queues, $count, $this->blockTimeMs);
            return $this->procResult($result);
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * Trim streams of messages
     */
    public function trim(int $maxLen = 0, ?string $queue = null): int
    {
        if($this->configNotExists) {
            return 0;
        }

        $queue = $queue ?: $this->getFirstQueueName();

        return $this->redis()->xTrim($queue, $maxLen, false);
    }

    public function getLastId(?string $queue = null): string
    {
        if($this->configNotExists) {
            return self::DEFAULT_LAST_ID;
        }

        $queue = $queue ?: $this->getFirstQueueName();
        $keyLastId = self::LAST_ID_KEY_PREFIX . $queue;

        return $this->redis()->get($keyLastId) ?: self::DEFAULT_LAST_ID;
    }

    /**
     * Returns array of workflow ids
     * @param array $messages
     * @return array|mixed
     */
    private function procResult(array $messages): array
    {
        $result = [];

        foreach ($messages as $queue => $queueMessages) {
            if(empty($queueMessages)) {
                continue;
            }

            $lastId = self::DEFAULT_LAST_ID;
            ksort($queueMessages);
            foreach ($queueMessages as $id => $message) {
                $evt = new Event(0);
                $evt->fromArray($message);
                $result[] = $evt;
                $lastId = $id;
            }

            $this->queues[$queue] = $lastId;
            $keyLastId = self::LAST_ID_KEY_PREFIX . $queue;
            $this->redis()->set($keyLastId, $lastId);
        }

        return $result;
    }
}
