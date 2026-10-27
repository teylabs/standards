<?php

use Tests\TestCase;

$argv = $_SERVER['argv'] ?? [];
if (getenv('CI') === false && getenv('PARATEST') === false && getenv('PEST_TIA') === false && ! in_array('--tia', $argv, true) && ! in_array('--no-tia', $argv, true)) {
    fwrite(STDERR, "Choose --tia for local tests or --no-tia for an explicit full run.\n");
    exit(1);
}

pest()->extend(TestCase::class)->in('Feature');
