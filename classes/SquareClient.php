<?php

namespace IgniterLabs\SquarePOS\Classes;

use Igniter\Flame\Support\Facades\Str;
use IgniterLabs\SquarePOS\Exceptions\ClientException;
use Square\Environment;
use Square\Models\BatchChangeInventoryRequest;
use Square\Models\BatchDeleteCatalogObjectsRequest;
use Square\Models\BatchRetrieveCatalogObjectsRequest;
use Square\Models\BatchRetrieveInventoryCountsRequest;
use Square\Models\BatchUpsertCatalogObjectsRequest;
use Square\Models\Location;
use Square\Models\SearchCatalogObjectsRequest;

class SquareClient
{
    protected $config;

    protected $client;

    public function __construct($config)
    {
        $this->config = $config;

        $this->client = new \Square\SquareClient([
            'accessToken' => array_get($this->config, 'access_token'),
            'environment' => array_get($this->config, 'environment', Environment::PRODUCTION),
        ]);
    }

    public function listLocations()
    {
        $response = $this->client->getLocationsApi()->listLocations();

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        $result = $response->getResult()->getLocations();

        return collect($result)
            ->filter(function (Location $location) {
                return ($location->getStatus() === 'ACTIVE'
                    && in_array('CREDIT_CARD_PROCESSING', (array)$location->getCapabilities(), true));
            })->values();
    }

    //
    // Catalog
    //

    public function searchCatalogObjects(array $options, bool $related = false, bool $deleted = false)
    {
        $body = new SearchCatalogObjectsRequest();
        $body->setIncludeRelatedObjects($related);
        $body->setIncludeDeletedObjects($deleted);

        if ($types = array_get($options, 'types')) {
            $body->setObjectTypes($types);
        }

        if ($cursor = array_get($options, 'cursor')) {
            $body->setCursor($cursor);
        }

        if ($limit = array_get($options, 'limit')) {
            $body->setLimit($limit);
        }

        if ($beginTime = array_get($options, 'beginTime')) {
            $body->setBeginTime($beginTime);
        }

        $response = $this->client->getCatalogApi()->searchCatalogObjects($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    public function listCatalogObjects(string $types, ?string $cursor = null)
    {
        $response = $this->client->getCatalogApi()->listCatalog($cursor, $types);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    public function retrieveCatalogObject(string $id)
    {
        $response = $this->client->getCatalogApi()->retrieveCatalogObject($id, true);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return $response->getResult();
    }

    public function batchRetrieveCatalogObjects(array $ids, bool $include = false)
    {
        $body = new BatchRetrieveCatalogObjectsRequest($ids);
        $body->setIncludeRelatedObjects($include);

        $response = $this->client->getCatalogApi()->batchRetrieveCatalogObjects($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    public function batchUpsertCatalogObjects(array $batches)
    {
        $body = new BatchUpsertCatalogObjectsRequest(Str::uuid(), $batches);

        $response = $this->client->getCatalogApi()->batchUpsertCatalogObjects($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    public function batchDeleteCatalogObjects(array $ids)
    {
        $body = new BatchDeleteCatalogObjectsRequest();
        $body->setObjectIds($ids);

        $response = $this->client->getCatalogApi()->batchDeleteCatalogObjects($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    //
    //  Inventory
    //

    public function batchRetrieveInventoryCounts(array $options)
    {
        $body = new BatchRetrieveInventoryCountsRequest();
        $body->setCatalogObjectIds(array_get($options, 'catalogObjectIds'));
        $body->setLocationIds(array_get($options, 'locationIds'));

        if ($states = array_get($options, 'states')) {
            $body->setStates($states);
        }

        if ($cursor = array_get($options, 'cursor')) {
            $body->setCursor($cursor);
        }

        if ($limit = array_get($options, 'limit')) {
            $body->setLimit($limit);
        }

        if ($updatedAfter = array_get($options, 'updatedAfter')) {
            $body->setUpdatedAfter($updatedAfter);
        }

        $response = $this->client->getInventoryApi()->batchRetrieveInventoryCounts($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }

    public function batchChangeInventory(array $changes, bool $ignoreUnchanged = true)
    {
        $body = new BatchChangeInventoryRequest(Str::uuid());
        $body->setChanges($changes);
        $body->setIgnoreUnchangedCounts(true);

        $response = $this->client->getInventoryApi()->batchChangeInventory($body);

        if (!$response->isSuccess()) {
            throw new ClientException($response);
        }

        return new CatalogResponse($response->getResult());
    }
}
