<?php

namespace Webkul\DataTransfer\Helpers\Importers\Category;

use Webkul\Category\Repositories\CategoryRepository;

class Storage
{
    /**
     * Items contains slug as key and category id as value
     */
    protected array $items = [];

    /**
     * Columns which will be selected from database
     */
    protected array $selectColumns = [
        'id',
        'slug',
        'name',
        'parent_id',
    ];

    /**
     * Create a new helper instance.
     */
    public function __construct(protected CategoryRepository $categoryRepository) {}

    /**
     * Initialize storage
     */
    public function init(): void
    {
        $this->items = [];
        $this->load();
    }

    /**
     * Load the categories by slug (and optionally by name)
     */
    public function load(array $slugs = []): void
    {
        if (empty($slugs)) {
            $categories = $this->categoryRepository->getModel()->select($this->selectColumns)->get();
        } else {
            $categories = $this->categoryRepository->getModel()->whereIn('slug', $slugs)->select($this->selectColumns)->get();
        }

        foreach ($categories as $category) {
            $this->set($category->slug, $category->id, $category->name, $category->parent_id);
        }
    }

    /**
     * Set category info in storage
     */
    public function set(string $slug, int $id, string $name = null, $parentId = null): self
    {
        $this->items[$slug] = [
            'id'        => $id,
            'name'      => $name,
            'parent_id' => $parentId,
        ];
        return $this;
    }

    /**
     * Check if slug exists
     */
    public function has(string $slug): bool
    {
        return isset($this->items[$slug]);
    }

    /**
     * Get category info by slug
     */
    public function get(string $slug): ?array
    {
        if (! $this->has($slug)) {
            return null;
        }
        return $this->items[$slug];
    }

    /**
     * Find category by name (optional, for parent lookup by name)
     */
    public function findByName(string $name): ?array
    {
        foreach ($this->items as $item) {
            if ($item['name'] === $name) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Is storage empty
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }
}
