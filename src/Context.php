<?php
namespace Workflow;
use Exception;
use JsonException;

class Context {
    public const NAMESPACE_DEFAULT='default';
    public const NAMESPACE_USER='user';
    public const NAMESPACE_COUNTER='counter';
    public const NAMESPACE_SUBSCRIPTION='subscription';

    private array $data;

    public function __construct() {
        $this->data=[self::NAMESPACE_DEFAULT => []];
    }

    public function set($key, $value, $namespace=self::NAMESPACE_DEFAULT): self {
        $this->data[$namespace][$key]=$value;
        return $this;
    }

    public function get($key, $namespace=self::NAMESPACE_DEFAULT) {
        return $this->data[$namespace][$key] ?? null;
    }

    public function set_all(array $data, $namespace=self::NAMESPACE_DEFAULT): void {
        foreach($data as $k => $v) {
            $this->set($k, $v, $namespace);
        }
    }

    public function get_all($namespace=self::NAMESPACE_DEFAULT):array {
        return $this->data[$namespace] ?? [];
    }

    /**
     * @return string
     * @throws JsonException
     */
    public function serialize(): string {
        $str=json_encode($this->data, JSON_THROW_ON_ERROR);
        return $str;
    }

    /**
     * @param $str
     * @return void
     * @throws Exception
     */
    public function unserialize($str) {
        $buffer=json_decode($str, true, 512, JSON_THROW_ON_ERROR) ?: [];

        if(!isset($buffer[self::NAMESPACE_DEFAULT])) {
            $buffer[self::NAMESPACE_DEFAULT]=[];
        }

        if(!is_array($buffer[self::NAMESPACE_DEFAULT])) {
            throw new Exception("Wrong context format");
        }

        $this->data=$buffer;
    }

}