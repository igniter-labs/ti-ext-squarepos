<?php

namespace IgniterLabs\SquarePOS\SyncPipes;

use Admin\Models\Menu_options_model;
use Square\Models\CatalogObject;

class PullItemOptions extends AbstractSyncPipe
{
    public function execute()
    {
        $options = [
            'object_types' => ['MODIFIER_LIST'],
            'cursor' => $this->syncTask->getConfigData('modifierCursor'),
            'limit' => $this->syncTask->batchSize(),
            'beginTime' => $this->syncTask->getConfigData('modifierBeginTime'),
        ];

        $nextBeingTime = now()->toISOString();

        $response = $this->square->searchCatalogObjects($options, true);

        $this->syncTask->note('Importing menu options from square');

        Menu_options_model::squareImportItems($response->getObjects(), function (CatalogObject $catalogObject, $staticModel) {
            if ($catalogObject->getIsDeleted()) {
                $staticModel->newQuery()->where('square_id', $catalogObject->getId())->delete();
            } else {
                $menuOption = $staticModel->createFromSquareModifierList($catalogObject);

                $menuOption->squareAttachLocation($catalogObject);

                if ($menuOption->wasChanged()) {
                    $this->syncTask->noteUpdated($menuOption, $catalogObject->getModifierListData()->getName());
                }
            }
        });

        $this->handleResponseCursor($response, 'modifierCursor');

        $this->syncTask->setConfigData('modifierBeginTime', $nextBeingTime)->save();
    }
}
