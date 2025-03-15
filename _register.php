<?php

declare(strict_types=1);

use OpenTelemetry\Contrib\Instrumentation\Redis\CredisInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Redis\PredisInstrumentation;
use OpenTelemetry\Contrib\Instrumentation\Redis\RedisInstrumentation;
use OpenTelemetry\SDK\Sdk;

if (
    class_exists(Sdk::class) &&
    Sdk::isInstrumentationDisabled(CredisInstrumentation::NAME) === true &&
    Sdk::isInstrumentationDisabled(PredisInstrumentation::NAME) === true &&
    Sdk::isInstrumentationDisabled(RedisInstrumentation::NAME) === true
) {
    return;
}

if (extension_loaded('opentelemetry') === false) {
    trigger_error('The opentelemetry extension must be loaded in order to autoload the OpenTelemetry Redis auto-instrumentation', E_USER_WARNING);

    return;
}

if (Sdk::isInstrumentationDisabled(CredisInstrumentation::NAME) !== true) {
    CredisInstrumentation::register();
}
if (Sdk::isInstrumentationDisabled(PredisInstrumentation::NAME) !== true) {
    PredisInstrumentation::register();
}
if (Sdk::isInstrumentationDisabled(RedisInstrumentation::NAME) !== true) {
    RedisInstrumentation::register();
}
