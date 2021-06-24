<?php
namespace Workflow;
use RuntimeException;

class Factory implements IFactory {
    /**
     * @param string $type Type of the workflow
     * @return Workflow
     * @throws RuntimeException
     */
    public function new_workflow($type) {
        if(class_exists($type)) {
            return new $type();
        }

        throw new RuntimeException("Class $type not exists");
    }

    /**
     * @param string $type Type of the event
     * @return Event
     * @throws RuntimeException
     */
    public function new_event($type) {
        if(class_exists($type)) {
            return new $type();
        }
        throw new RuntimeException("Class $type not exists");
    }
}