<?php

declare(strict_types=1);

namespace OpenTelemetry\Contrib\Instrumentation\Redis;

enum CommandGroupEnum : string
{
    case ADMIN = '@admin';
    case OTHER = '@other';
    case READ_ONLY = '@readonly';
    case WRITE = '@write';
    case BLOCKING = '@blocking';
    case PUBSUB = '@pubsub';

    case ALL = '@all';
}
