<?php

namespace Workflow\Engine\Component;

interface IComponent
{
    public const PARAM_SEVRER = 'server';

    public const PARAM_STORAGE = 'storage';

    public const PARAM_TASK_ID = 'task_id';

    public function __construct(array $param);

    public function run();
}