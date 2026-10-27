<?php

declare(strict_types=1);

// Run by tests/ParityTest.php in a plain PHP process, as standards:check runs the gate: no Pest test is running.
require __DIR__.'/vendor/autoload.php';

$report = (new Tey\Standards\Enforcement\Gate(require __DIR__.'/tests/Arch/configuration.php', new DateTimeImmutable('2026-10-27')))->run();

echo json_encode(['violations' => $report['violations'], 'skipped' => $report['skipped']], JSON_THROW_ON_ERROR);
