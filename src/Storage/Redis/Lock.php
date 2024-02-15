<?php

namespace Workflow\Storage\Redis;
use Redis;

class Lock
{
    public const DEFAULT_KEY_EXPIRE_TIME = 30;

    protected Connection $connection;

    protected string $name;

    protected string $value;

    protected int $expire;

    protected bool $isConnected;

    public function __construct(string $name, string $value, int $expire = self::DEFAULT_KEY_EXPIRE_TIME)
    {
        $this->name = $name;
        $this->value = $value;
        $this->expire = $expire;

        if(empty((new Config())->host())) {
            $this->isConnected = false;
        }
    }

    public function isRedisConnected(): bool
    {
        if(empty($this->isConnected)) {
            $this->isConnected = $this->redis()->isConnected();
        }

        return $this->isConnected;
    }

    public function lock(): bool
    {
        if(!$this->isRedisConnected()) {
            return false;
        }

        if (!$this->redis()->setnx($this->name, $this->value)) {
            return false;
        }

        if (!$this->redis()->expire($this->name, $this->expire)) {
            $this->redis()->del($this->name);
            return false;
        }

        return true;
    }

    public function isLocked(): bool
    {
        if(!$this->isRedisConnected()) {
            return false;
        }

        $lockValue = $this->redis()->get($this->name);

        if ($lockValue !== $this->value) {
            return false;
        }

        if (!$this->redis()->expire($this->name, $this->expire)) {
            return false;
        }

        $lockValue = $this->redis()->get($this->name);

        return ($lockValue === $this->value);
    }

    public function unlock(): bool
    {
        if(!$this->isRedisConnected()) {
            return false;
        }

        return ($this->redis()->del($this->name) > 0);
    }

    public function stop(): bool
    {
        return $this->connection->close();
    }

    protected function redis(): Redis
    {
        if(empty($this->connection)) {
            $this->connection = new Connection(new Config());
        }

        return $this->connection->connection();
    }
}
