<?php

// Loads each class named on stdin, one per line, after requiring the autoloader
// in argv[1]. Prints "BEGIN <class>" before and "OK <class>" or
// "ERR <class> <message>" after. A fatal error ends the process mid-class; the
// caller sees BEGIN without OK and restarts after it.

declare(strict_types=1);

require $argv[1];

foreach (file('php://stdin', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $class) {
    fwrite(STDOUT, "BEGIN {$class}\n");

    try {
        $loaded = class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
        fwrite(STDOUT, $loaded ? "OK {$class}\n" : "ERR {$class} is declared in a class root but cannot be autoloaded; check its PSR-4 placement.\n");
    } catch (Throwable $error) {
        fwrite(STDOUT, "ERR {$class} ".str_replace(["\r", "\n"], ' ', get_class($error).': '.$error->getMessage())."\n");
    }
}
