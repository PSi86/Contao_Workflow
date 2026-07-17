<?php

declare(strict_types=1);

/*
 * Test bootstrap.
 *
 * In CI the bundle is installed standalone, so its own vendor/autoload.php exists and
 * already maps the Psimandl\WorkflowBundle\Tests\ namespace via autoload-dev. In local
 * DDEV development the bundle is a Composer path repository symlinked into the Managed
 * Edition, whose autoloader resolves the bundle source plus every runtime dependency
 * (Doctrine DBAL, Notification Center); we fall back to it and register the test
 * namespace by hand.
 */

(static function (): void {
    $bundleAutoload = dirname(__DIR__).'/vendor/autoload.php';
    $appAutoload = dirname(__DIR__, 2).'/contao-app/vendor/autoload.php';

    if (is_file($bundleAutoload)) {
        require $bundleAutoload;
    } elseif (is_file($appAutoload)) {
        require $appAutoload;
    } else {
        fwrite(STDERR, "No autoloader found. Run 'composer install' in the bundle.\n");
        exit(1);
    }

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Psimandl\\WorkflowBundle\\Tests\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $file = __DIR__.'/'.str_replace('\\', '/', substr($class, \strlen($prefix))).'.php';

        if (is_file($file)) {
            require $file;
        }
    });
})();
