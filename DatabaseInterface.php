<?php

namespace FpDbTest;

interface DatabaseInterface
{
    public function buildQuery(string $query, array $args = [], bool $handleConditionBlocks = true): string;

    public function skip();
}
