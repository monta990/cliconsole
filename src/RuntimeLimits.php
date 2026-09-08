<?php

declare(strict_types=1);

namespace GlpiPlugin\Cliconsole;

final class RuntimeLimits
{
    public const MAX_COMMAND_LENGTH = 4096;
    public const MAX_ARGUMENTS = 64;
    public const MAX_ARGUMENT_LENGTH = 1024;
    public const MAX_INPUT_LENGTH = 8192;
    public const MAX_QUEUE_SIZE = 65536;
    public const MAX_OUTPUT_SIZE = 5 * 1024 * 1024;
    public const MAX_OUTPUT_CHUNK = 256 * 1024;
    public const MAX_EXECUTION_TIME = 900;
    public const MAX_ACTIVE_SESSIONS = 3;
    public const SESSION_RETENTION = 21600;
    public const READY_SESSION_RETENTION = 900;
    public const STALE_RUNNING_SESSION = 1200;
    public const AUDIT_MAX_SIZE = 5 * 1024 * 1024;
}
