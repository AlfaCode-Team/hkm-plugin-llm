<?php

declare(strict_types=1);

/**
 * Test bootstrap.
 *
 * The kernel's `vendor/autoload.php` is required, not just its `src/`: the
 * integration suite boots this plugin on a real kernel through
 * `AlfacodeTeam\Ground\PluginGround`, which only the kernel's composer
 * autoloader provides. A phpunit.xml that pointed at a `vendor/autoload.php`
 * absent from the checkout would otherwise fail on the first test.
 *
 * Order, most specific first:
 *   1. HKM_KERNEL_PATH          — an explicit checkout
 *   2. vendor/autoload.php      — composer-installed (CI)
 *   3. ../../../PHP             — the conventional workspace layout
 *
 * The sibling `hkm-plugin-http-client` is mapped so the integration suite can
 * boot the REAL `http.client` provider that this plugin requires. Its cURL
 * client is then replaced by a recording fake through `->port()`, so no
 * request leaves the process.
 */

$pluginRoot = \dirname(__DIR__);

spl_autoload_register(static function (string $class) use ($pluginRoot): void {
    $map = [
        'Tests\\Plugins\\Llm\\' => $pluginRoot . '/tests/',
        'Plugins\\Llm\\'        => $pluginRoot . '/',
        'Plugins\\HttpClient\\' => \dirname($pluginRoot) . '/hkm-plugin-http-client/',
    ];

    foreach ($map as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $path = $base . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});

$kernelRoot = getenv('HKM_KERNEL_PATH') ?: null;

if ($kernelRoot === null && is_file($pluginRoot . '/vendor/autoload.php')) {
    require_once $pluginRoot . '/vendor/autoload.php';
    $kernelRoot = false; // composer-installed: everything is already on the path
}

if ($kernelRoot !== false) {
    $kernelRoot ??= \dirname($pluginRoot, 3) . '/PHP';

    if (!is_dir($kernelRoot . '/src/Kernel')) {
        fwrite(STDERR, \sprintf(
            "\nCannot find the kernel. Looked in %s.\nSet HKM_KERNEL_PATH to a checkout, or run `composer install`.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    if (!is_file($kernelRoot . '/vendor/autoload.php')) {
        fwrite(STDERR, \sprintf(
            "\nFound the kernel at %s but it has no vendor/autoload.php. Run `composer install` there.\n\n",
            $kernelRoot,
        ));
        exit(1);
    }

    require_once $kernelRoot . '/vendor/autoload.php';

    if (is_file($kernelRoot . '/src/Kernel/Support/helpers.php')) {
        require_once $kernelRoot . '/src/Kernel/Support/helpers.php';
    }
}
