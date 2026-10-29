<?php

namespace App\Contracts;

interface EventParserInterface
{
    /**
     * @param  array<string, mixed>  $rawEvent
     * @return array<string, mixed>
     */
    public function parse(array $rawEvent): array;
}
