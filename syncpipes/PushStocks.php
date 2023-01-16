<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Stocks_model;

class PushStocks extends AbstractSyncPipe
{
    public function execute()
    {
        $this->syncTask->note('Exporting stock counts into square');

        Stocks_model::squareExportItems($this->syncTask, function ($stocks) {
            $stocks = $this->removeCleanVersions($stocks);

            if ($stocks->isNotEmpty()) {
                $changes = $this->makeChangesBatch($stocks);

                $response = $this->square->batchChangeInventory($changes);

                $this->updateSquareAttributes($stocks, $response);
            }
        });
    }

    protected function removeCleanVersions($stocks)
    {
        return $stocks->filter(function ($stock) {
            $locations = $stock->stockable->getStockableLocations();

            return $locations->isNotEmpty()
                ? $locations->pluck('location_id')->contains($stock->location_id)
                : true;
        });
    }

    protected function makeChangesBatch($stocks)
    {
        return $stocks->map(function ($stock) {
            return $stock->asSquareInventoryChange();
        })->values()->all();
    }

    protected function updateSquareAttributes($stocks, $response)
    {
        $stocks->each(function ($stock) use ($response) {
            if (!$inventoryCount = $response->getObject($stock->stockable->square_variation_id))
                return true;

            $stock->square_calculated_at = $inventoryCount->getCalculatedAt();
            if ($stock->save()) {
                $this->syncTask->note(sprintf('Updated stock count for %s to %s at %s',
                    $stock->stockable->getStockableName(), $inventoryCount->getQuantity(), make_carbon($inventoryCount->getCalculatedAt())->toDateTimeString()
                ));
            }
        });
    }
}
