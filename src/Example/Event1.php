<?php

namespace Workflow\Example;

use Workflow\Event;

class Event1 extends Event
{
    /**
     * Event1 constructor.
     */
    public function __construct($context)
    {
        parent::__construct(EventListener::LISTENER_EVENT, $context);
    }
}