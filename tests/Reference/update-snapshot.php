<?php declare(strict_types = 1);

use ShipMonk\RRuleTests\Reference\ReferenceCase;

require __DIR__ . '/../../vendor/autoload.php';

$cases = [];

for ($seed = 1; $seed <= ReferenceCase::SEEDS; $seed++) {
    $cases[] = ReferenceCase::expandNaively($seed);
}

ReferenceCase::saveSnapshot($cases);
