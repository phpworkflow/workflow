<?php
namespace Workflow\Logger;

interface ILogger {
    // Logging channels
    public const LOG_OFF = 0;
    public const LOG_STDOUT = 1;
    public const LOG_CONSOLE = 2;
    public const LOG_FILE = 3;
    public const LOG_DATABASE = 4;
    public const LOG_LOGGER = 5;

    // Log levels
    public const DEBUG='debug';
    public const INFO='info';
    public const WARN='warn';
    public const ERROR='error';

    public function error($message, array $context = []);
    public function warn($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
}