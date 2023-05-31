<?php
namespace Workflow;
use Exception;

class Context {
    const NAMESPACE_DEFAULT='default';
    const NAMESPACE_USER='user';
    const NAMESPACE_COUNTER='counter';
    const NAMESPACE_SUBSCRIPTION='subscription';

    private $data;

    public function __construct() {
        $this->data=[self::NAMESPACE_DEFAULT => []];
    }

    public function set($key, $value, $namespace=self::NAMESPACE_DEFAULT) {
        $this->data[$namespace][$key]=$value;
        return $this;
    }

    public function get($key, $namespace=self::NAMESPACE_DEFAULT) {
        return $this->data[$namespace][$key] ?? null;
    }

    public function set_all(array $data, $namespace=self::NAMESPACE_DEFAULT) {
        foreach($data as $k => $v) {
            $this->set($k, $v, $namespace);
        }
    }

    public function get_all($namespace=self::NAMESPACE_DEFAULT) {
        return $this->data[$namespace] ?? [];
    }

    public function serialize() {
        $str=json_encode($this->data);
        return $str;
    }

    /**
     * @param $str
     * @return void
     * @throws Exception
     */
    public function unserialize($str) {
        $buffer=json_decode($str, true) ?: [];

        if(!isset($buffer[self::NAMESPACE_DEFAULT])) {
            $buffer[self::NAMESPACE_DEFAULT]=[];
        }

        if(!is_array($buffer[self::NAMESPACE_DEFAULT])) {
            throw new Exception("Wrong context format");
        }

        $this->data=$buffer;
    }

}