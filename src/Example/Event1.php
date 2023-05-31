<?php

namespace Workflow\Example;

use Workflow\Event;
use Exception;

class Event1 extends Event
{
    /**
     * @param $context
     * @throws Exception
     */
    public function __construct($context)
    {
        parent::__construct(EventListener::LISTENER_EVENT, $context);
    }
}