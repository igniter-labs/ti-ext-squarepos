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
            'limit' => $this->syncTask->batchSize(),
            'cursor' => $this->syncTask->getConfigData('modifierCursor'),
            'beginTime' => $this->syncTask->getBeginTimeFor('modifier'),
        ];

        if (!$this->hasCursor)
            $this->syncTask->logBeginTimeFor('modifier');

        $response = $this->square->searchCatalogObjects($options, true);

        $this->syncTask->note('Importing menu options from square');

        Menu_options_model::squareImportItems($response->getObjects(), function (CatalogObject $catalogObject, $staticModel) {
            if ($catalogObject->getIsDeleted()) {
                $staticModel->newQuery()->where('square_id', $catalogObject->getId())->delete();
            }
            else {
                $menuOption = $staticModel->createFromSquareModifierList($catalogObject);

                $menuOption->squareAttachLocation($catalogObject);

                if ($menuOption->wasChanged()) {
                    $this->syncTask->noteUpdated($menuOption, $catalogObject->getModifierListData()->getName());
                }
            }
        });

        $this->handleResponseCursor('modifierCursor', $response->getResponse()->getCursor());
    }

    public function onComplete()
    {
        $this->syncTask->forgetConfigData('modifierCursor');
    }
}
