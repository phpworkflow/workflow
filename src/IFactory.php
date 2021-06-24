<?php
namespace Workflow;

interface IFactory {
    public function new_workflow($type);
    public function new_event($type);
}