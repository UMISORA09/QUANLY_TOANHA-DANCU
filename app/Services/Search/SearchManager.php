<?php

namespace App\Services\Search;

use App\Services\Search\Contracts\SearchDriverInterface;
use App\Services\Search\DTOs\SearchResult;
use App\Services\Search\Factories\SearchDriverFactory;

class SearchManager
{
    /**
     * @var array<string, SearchDriverInterface>
     */
    protected array $drivers = [];

    public function __construct(
        protected ?SearchDriverFactory $factory = null
    ) {
        $this->factory = $this->factory ?: new SearchDriverFactory;
    }

    public function driver(?string $name = null): SearchDriverInterface
    {
        $name = $name ?: config('search.default', 'smart');

        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->resolve($name);
        }

        return $this->drivers[$name];
    }

    protected function resolve(string $name): SearchDriverInterface
    {
        $config = config("search.drivers.{$name}", []);

        return $this->factory->make($name, $config);
    }

    public function search(string $index, string $query, array $filters = [], array $options = []): SearchResult
    {
        return $this->driver()->search($index, $query, $filters, $options);
    }

    public function suggest(string $index, string $prefix, int $limit = 5): array
    {
        return $this->driver()->suggest($index, $prefix, $limit);
    }

    public function index(string $index, string $id, array $document): void
    {
        $this->driver()->index($index, $id, $document);
    }

    public function delete(string $index, string $id): void
    {
        $this->driver()->delete($index, $id);
    }

    public function flush(string $index): void
    {
        $this->driver()->flush($index);
    }
}
