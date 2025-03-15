# OpenTelemetry redis|predis|credis auto-instrumentation

Please read https://opentelemetry.io/docs/instrumentation/php/automatic/ for instructions on how to
install and configure the extension and SDK.

## Overview
Auto-instrumentation hooks are registered via Composer, and spans will automatically be created for
selected `redis` operations (by default: all Redis commands).

## Configuration

You can disable the extension using [runtime configuration](https://opentelemetry.io/docs/instrumentation/php/sdk/#configuration):

```shell
OTEL_PHP_DISABLED_INSTRUMENTATIONS=redis
OTEL_PHP_DISABLED_INSTRUMENTATIONS=predis
OTEL_PHP_DISABLED_INSTRUMENTATIONS=credis
```

### Module specific configuration
                  
#### Disable instrumentation for specific commands
You can disable instrumentation for specific commands. This can be useful if you have some commands that are executed very often and you don't want to create spans for them. This option is disabled by default. Use environment variables to set this option. You can set multiple commands separated by a comma. You can also specify "group" of commands using @<group_name>. Groups are defined in the `src/CommandGroupEnum.php` file. List of commands and groups can be found in the `src/AbstractInstrumentation.php` file. You can define commands individually for each supported extension.     

```shell
OTEL_PHP_INSTRUMENTATION_REDIS_PREDIS_FUNCTIONS=@all,-@readonly
OTEL_PHP_INSTRUMENTATION_REDIS_CREDIS_FUNCTIONS=@write
OTEL_PHP_INSTRUMENTATION_REDIS_REDIS_FUNCTIONS=get,mget
```

                                               
#### Tracking connection details for clustered connection
In Predis you can configure a clustered connection (for replication, sharding, etc.). In this case, there is no clear way to see which server executed a command. Changing this setting to true will use Predis methods to get information about which connection executed the command. Optionally, when cluster mode is set to redis, it will make a second call to Redis to determine this information. This will make your code slower. This option is disabled by default.
```shell
OTEL_PHP_INSTRUMENTATION_PREDIS_TRACK_AGGREGATED_CONNECTIONS=true
```