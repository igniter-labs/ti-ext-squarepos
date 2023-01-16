<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menus_model;
use Square\Models\CatalogObject;

class PullItems extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['ITEM'],
            'limit' => $this->syncTask->batchSize(),
            'cursor' => $this->syncTask->getConfigData('itemCursor'),
            'beginTime' => $this->syncTask->getBeginTimeFor('item'),
        ];

        if (!$this->hasCursor) {
            $this->syncTask->logBeginTimeFor('item');
        }

        $response = $this->square->searchCatalogObjects($options, true);

        $this->syncTask->note('Importing menu items from square');

        Menus_model::squareImportItems($response->getObjects(), function (CatalogObject $catalogObject, $staticModel) {
            if ($catalogObject->getIsDeleted()) {
                if ($staticModel->newQuery()->where('square_id', $catalogObject->getId())->delete()) {
                    $this->syncTask->noteDeleted($catalogObject, $catalogObject->getCategoryData()->getName());
                }
            } else {
                $response = $this->square->retrieveCatalogObject($catalogObject->getId());
                $catalogObject = $response->getObject();

                $menuItem = $staticModel->createFromSquareItem($catalogObject);

                $menuItem->squareAttachLocation($catalogObject);

                $menuItem->squareAttachCategories($catalogObject->getItemData()->getCategoryId());

                $modifierListObjects = collect($response->getRelatedObjects())
                    ->filter(function (CatalogObject $catalogObject) {
                        return $catalogObject->getType() === 'MODIFIER_LIST';
                    });

                $menuItem->squareAttachModifierLists(
                    $modifierListObjects,
                    collect($catalogObject->getItemData()->getModifierListInfo())
                );

                if ($menuItem->wasChanged()) {
                    $this->syncTask->noteUpdated($menuItem, $catalogObject->getItemData()->getName());
                }
            }
        });

        $this->handleResponseCursor('itemCursor', $response->getResponse()->getCursor());
    }

    public function onComplete()
    {
        $this->syncTask->forgetConfigData('itemCursor');
    }
}
