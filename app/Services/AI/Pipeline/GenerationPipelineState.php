<?php

namespace App\Services\AI\Pipeline;

use App\Models\ContentItem;
use App\Models\GenerationRun;

class GenerationPipelineState
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public ContentItem $item,
        public array $meta = [],
        public ?GenerationRun $run = null,
        public bool $strictAssetMode = true,
        public array $data = []
    ) {
    }

    public static function fromItem(ContentItem $item, bool $strictAssetMode = true): self
    {
        return new self(
            item: $item,
            meta: is_array($item->ai_meta) ? $item->ai_meta : [],
            run: null,
            strictAssetMode: $strictAssetMode,
            data: []
        );
    }

    public function put(string $key, mixed $value): self
    {
        data_set($this->data, $key, $value);

        return $this;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function syncMetaToItem(): void
    {
        $this->item->ai_meta = $this->meta;
    }
}
