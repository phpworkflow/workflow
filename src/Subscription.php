<?php
/**
 * Created by PhpStorm.
 * User: Rus
 * Description: PhpWorkflow TODO: add description
 */

namespace Workflow;

class Subscription {
    public const EMPTY = 'NULL';

    public $event_type;
    public $context_key;
    public $context_value;

    public function __construct($event_type, $context_key=self::EMPTY, $context_value=self::EMPTY) {
        $this->event_type=$event_type;
        $this->context_key=$context_key;
        $this->context_value=$context_value;
    }
}