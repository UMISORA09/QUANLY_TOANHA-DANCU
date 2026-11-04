<?php

namespace App\Services\Search;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

class ElasticsearchIndex
{
    public function __construct(private array $config, private string $name)
    {
        if (! preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $name)) {
            throw new LogicException('Invalid Elasticsearch index name.');
        }
    }

    public function configure(array $properties): void
    {
        $existing = $this->client()->head('/'.$this->name);
        if (! $existing->notFound()) {
            $existing->throw();

            return;
        }

        $created = $this->client()->put('/'.$this->name, [
            'settings' => [
                'number_of_replicas' => 0,
                'analysis' => ['analyzer' => ['cassavas_text' => [
                    'type' => 'custom', 'tokenizer' => 'standard', 'filter' => ['lowercase', 'asciifolding'],
                ]]],
            ],
            'mappings' => ['dynamic' => 'strict', 'properties' => $properties],
        ]);
        if ($created->json('error.type') !== 'resource_already_exists_exception') {
            $created->throw();
        }
    }

    public function search(array $query): Response
    {
        return $this->client()->post('/'.$this->name.'/_search', $query);
    }

    /**
     * @param  list<string>  $allowedIds  IDs selected by the authorized MySQL query.
     * @param  list<string>  $fields  Searchable fields selected by the caller.
     * @return list<string>|null Null means the caller must use its MySQL search.
     */
    public function matchingIds(array $allowedIds, string $keyword, array $fields): ?array
    {
        if ($allowedIds === []) {
            return [];
        }

        // Never use a truncated match set for MySQL pagination or totals.
        if (count($allowedIds) > 10000) {
            return null;
        }

        try {
            $response = $this->search([
                'size' => count($allowedIds),
                'track_total_hits' => true,
                '_source' => false,
                'query' => ['bool' => [
                    'filter' => [
                        ['terms' => ['_id' => $allowedIds]],
                        ['term' => ['is_deleted' => false]],
                    ],
                    'must' => [['multi_match' => ['query' => $keyword, 'fields' => $fields, 'fuzziness' => 'AUTO']]],
                ]],
            ]);
        } catch (ConnectionException) {
            return null;
        }

        $hits = $response->json('hits.hits');
        if (! $response->successful() || $response->json('timed_out') || $response->json('_shards.failed')
            || ! is_array($hits) || $response->json('hits.total.value') !== count($hits)) {
            return null;
        }

        $ids = array_column($hits, '_id');
        if (count($ids) !== count($hits) || array_diff($ids, $allowedIds) !== []) {
            return null;
        }

        return $ids;
    }

    public function write(string $id, array $document, ?int $version = null): void
    {
        $parameters = $version === null ? '' : '&version_type=external_gte&version='.$version;
        $response = $this->client()->put('/'.$this->name.'/_doc/'.rawurlencode($id).'?refresh=wait_for'.$parameters, $document);
        if ($version === null || $response->status() !== 409 || $response->json('error.type') !== 'version_conflict_engine_exception') {
            $response->throw();
        }
    }

    public function delete(string $id): void
    {
        $response = $this->client()->delete('/'.$this->name.'/_doc/'.rawurlencode($id).'?refresh=wait_for');
        if (! $response->notFound()) {
            $response->throw();
        }
    }

    private function client(): PendingRequest
    {
        $request = Http::baseUrl(rtrim($this->config['hosts'][0] ?? 'http://127.0.0.1:9200', '/'))
            ->acceptJson()->connectTimeout(1)->timeout(5);

        return empty($this->config['username']) ? $request
            : $request->withBasicAuth($this->config['username'], $this->config['password'] ?? '');
    }
}
