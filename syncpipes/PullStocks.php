<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Stocks_model;
use Carbon\Carbon;
use Square\Models\InventoryCount;

class PullStocks extends AbstractSyncPipe
{
    public function execute()
    {
        $this->syncTask->note('Importing stock counts from square');

        $stockBeginTime = $this->syncTask->getConfigData('stockBeginTime');

        $this->syncTask->logBeginTimeFor('stock');

        Stocks_model::squareImportItems($this->syncTask, function ($stocks) use ($stockBeginTime) {
            $catalogObjectIds = $locationIds = [];
            $stocks->each(function ($stock) use (&$catalogObjectIds, &$locationIds) {
                if ($stock->stockable->square_variation_id) {
                    $catalogObjectIds[] = $stock->stockable->square_variation_id;
                    $locationIds[] = $stock->squareLocationRemoteId();
                }
            });

            $options = [
                'catalogObjectIds' => array_unique(array_filter($catalogObjectIds)),
                'locationIds' => array_unique(array_filter($locationIds)),
                'limit' => $this->syncTask->batchSize(),
                'updateAfter' => $stockBeginTime,
            ];

            $response = $this->square->batchRetrieveInventoryCounts($options);

            $response->getObjects()->each(function (InventoryCount $inventoryCount) use ($stocks) {
                $stock = $stocks->first(function ($stock) use ($inventoryCount) {
                    return $stock->stockable->square_variation_id === $inventoryCount->getCatalogObjectId()
                        && $stock->squareLocationRemoteId() === $inventoryCount->getLocationId();
                });

                if (!$stock)
                    return true;

                if (Carbon::parse($inventoryCount->getCalculatedAt())->isAfter($stock->square_calculated_at))
                    return true;

                $stock->updateStock($inventoryCount->getQuantity(), Stocks_model::STATE_RECOUNT);

                $stock->square_calculated_at = $inventoryCount->getCalculatedAt();
                $stock->save();

                $this->syncTask->note(sprintf('Updated stock count for %s to %s',
                    $stock->stockable->name, $inventoryCount->getQuantity()
                ));
            });
        });
    }
}
