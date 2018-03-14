# cluster

This package provides tools to run multiple PHP processes that can listen on the same port, such as multiple [HTTP server](https://github.com/amphp/http-server) instances listening on port 80. Port-sharing is achieved with `SO_REUSEPORT` where available and socket transfers otherwise.

Additionally, this package provides a mechanism to gracefully restart such processes with zero downtime.

## Usage

Instead of starting the processes with `php server.php`, cluster-enabled applications are started with `php vendor/bin/cluster run server.php`.

A graceful restart can be initiated by sending a `USR1` signal to the cluster process or by executing `php vendor/bin/cluster restart server.php`.
