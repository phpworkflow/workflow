<?php

namespace Workflow\Storage\Redis;

use Redis;
use RedisException;

class Connection
{
    /**
     * @var Config|null
     */
    protected ?Config $cfg;

    /**
     * @var Redis|null
     */
    protected ?Redis $connection = null;

    public function __construct(?Config $cfg = null)
    {
        $this->cfg = $cfg ?: new Config();
    }

    /**
     * @return Redis
     * @throws RedisException
     */
    public function connection(): Redis
    {
        if ($this->connection === null) {
            $this->connection = new Redis();
        }

        try {
            $res = $this->connection->ping();
            if (!$res) {
                $this->connection->close();
                throw new RedisException("PING failed");
            }
        } catch (RedisException $e) {
            $this->reconnect();
        }

        return $this->connection;
    }

    public function isConnected(): bool
    {
        return !empty($this->connection()->ping());
    }

    /**
     * @return void
     * @throws RedisException
     */
    protected function reconnect(): void
    {
        try {
            $this->connection->pconnect($this->cfg->host(), $this->cfg->port());
            $pass = $this->cfg->pass();
            if (!empty($pass)) {
                $this->connection->auth($pass);
            }
        }
        catch (RedisException $e) {
            ;
        }
    }
}
