<?php
namespace Workflow\Logger;

interface ILogger {
    // Logging channels
    const LOG_OFF = 0;
    const LOG_STDOUT = 1;
    const LOG_CONSOLE = 2;
    const LOG_FILE = 3;
    const LOG_DATABASE = 4;
    const LOG_LOGGER = 5;

    // Log levels
    const DEBUG='debug';
    const INFO='info';
    const WARN='warn';
    const ERROR='error';

    public function error($message, array $context = []);
    public function warn($message, array $context = []);
    public function info($message, array $context = []);
    public function debug($message, array $context = []);
}