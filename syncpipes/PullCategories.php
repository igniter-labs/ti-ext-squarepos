<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Categories_model;

class PullCategories extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['CATEGORY'],
            'limit' => $this->syncTask->batchSize(),
            'cursor' => $this->syncTask->getConfigData('categoryCursor'),
            'beginTime' => $this->syncTask->getBeginTimeFor('category'),
        ];

        if (!$this->hasCursor)
            $this->syncTask->logBeginTimeFor('category');

        $response = $this->square->searchCatalogObjects($options);

        $this->syncTask->note('Importing categories from square');

        Categories_model::squareImportItems($response->getObjects(), function ($catalogObject, $staticModel) {
            if ($catalogObject->getIsDeleted()) {
                if ($staticModel->newQuery()->where('square_id', $catalogObject->getId())->delete()) {
                    $this->syncTask->noteDeleted($catalogObject, $catalogObject->getCategoryData()->getName());
                }
            }
            else {
                $category = $staticModel->createFromSquareCategory($catalogObject);

                $category->squareAttachLocation($catalogObject);

                if ($category->wasChanged()) {
                    $this->syncTask->noteUpdated($category, $catalogObject->getCategoryData()->getName());
                }
            }
        });

        $this->handleResponseCursor('categoryCursor', $response->getResponse()->getCursor());
    }

    public function onComplete()
    {
        $this->syncTask->forgetConfigData('categoryCursor');
    }
}
