<?php

namespace App\Services\Trends;

interface TrendSourceAdapter
{
    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function collect(array $context = []): array;
}