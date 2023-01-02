<?php

namespace IgniterLabs\SquarePOS\Actions;

use Admin\Models\Categories_model;
use Admin\Models\Menu_item_option_values_model;
use Admin\Models\Menu_item_options_model;
use Admin\Models\Menu_option_values_model;
use Admin\Models\Menu_options_model;
use Igniter\Flame\Traits\ExtensionTrait;
use IgniterLabs\SquarePOS\Models\SyncTask;
use IgniterLabs\SquarePOS\Traits\LocationHelpers;
use Illuminate\Support\Collection;
use Square\Models\CatalogItem;
use Square\Models\CatalogItemModifierListInfo;
use Square\Models\CatalogItemVariation;
use Square\Models\CatalogObject;
use Square\Models\ItemVariationLocationOverrides;
use Square\Models\Money;
use System\Actions\ModelAction;

class MenuItemAction extends ModelAction
{
    use ExtensionTrait;
    use LocationHelpers;

    public function squareExportItems(SyncTask $syncTask, callable $callback)
    {
        $this->model->newQuery()
            ->with(['stocks', 'categories', 'locations'])
            ->isEnabled()
            ->whereHasOrDoesntHaveLocation(array_keys($syncTask->mappedLocationIds()))
            ->chunkById($syncTask->batchSize(), $callback);
    }

    public function squareImportItems(Collection $catalogObjects, callable $callback)
    {
        $catalogObjects->filter(function (CatalogObject $catalogObject) {
            return strtoupper($catalogObject->getType()) === 'ITEM';
        })->each(function (CatalogObject $catalogObject, $squareId) use ($callback) {
            $callback($catalogObject, $this->model);
        });
    }

    public function getSquareId()
    {
        return $this->model->square_id ?: '#temp_menus_'.$this->model->getKey();
    }

    public function getSquareVariationId()
    {
        return $this->model->square_variation_id ?: '#temp_menu_variations_'.$this->model->getKey();
    }

    public function squareCategoryId()
    {
        return optional($this->model->categories->first())->square_id;
    }

    public function asSquareItem()
    {
        $itemPrice = new Money();
        $itemPrice->setAmount(number_format($this->model->menu_price, 2, '.', '') * 100);
        $itemPrice->setCurrency(currency()->getUserCurrency());

        $itemVariationData = new CatalogItemVariation();
        $itemVariationData->setItemId($this->getSquareId());
        $itemVariationData->setSku('ti_menu_item_'.$this->model->menu_id);
        $itemVariationData->setName($this->model->menu_name);
        $itemVariationData->setPricingType('FIXED_PRICING');
        $itemVariationData->setPriceMoney($itemPrice);
        $itemVariationData->setLocationOverrides($this->makeSquareLocationOverrideObjects());

        $itemVariationObject = new CatalogObject('ITEM_VARIATION', $this->getSquareVariationId());
        $itemVariationObject->setItemVariationData($itemVariationData);
        $itemVariationObject->setVersion($this->model->square_variation_version);
        $this->squareApplyLocationIds($itemVariationObject);

        $itemData = new CatalogItem();
        $itemData->setName($this->model->menu_name);
        $itemData->setDescription($this->model->menu_description);
        $itemData->setVariations([$itemVariationObject]);
        $itemData->setCategoryId($this->squareCategoryId());
        $itemData->setModifierListInfo($this->makeSquareModifierListInfoObjects());

        $itemObject = new CatalogObject('ITEM', $this->getSquareId());
        $itemObject->setItemData($itemData);
        $itemObject->setVersion($this->model->square_version);

        $this->squareApplyLocationIds($itemObject);

        return $itemObject;
    }

    public function createFromSquareItem(CatalogObject $catalogObject)
    {
        $variationObject = $catalogObject->getItemData()->getVariations()[0];

        $attributes = [
            'menu_name' => $catalogObject->getItemData()->getName(),
            'menu_description' => $catalogObject->getItemData()->getDescription(),
            'menu_price' => $variationObject->getItemVariationData()->getPriceMoney()->getAmount(),
            'square_version' => $catalogObject->getVersion(),
            'square_variation_id' => $variationObject->getId(),
            'square_variation_version' => $variationObject->getVersion(),
        ];

        return $this->model->updateOrCreate([
            'square_id' => $catalogObject->getId(),
        ], $attributes);
    }

    public function squareAttachCategories($squareId)
    {
        if ($category = Categories_model::firstWhere('square_id', $squareId)) {
            $category->menus()->sync($this->model->getKey());
        }
    }

    public function squareAttachModifierLists(Collection $modifierListObjects, Collection $modifierListInfo)
    {
        $menuItemOptions = $modifierListObjects->map(function (CatalogObject $modifierListObject) use ($modifierListInfo) {
            $modifierListInfoObject = $modifierListInfo->first(function ($object) use ($modifierListObject) {
                return $object->getModifierListId() === $modifierListObject->getId();
            });

            if (!$menuOption = Menu_options_model::firstWhere('square_id', $modifierListObject->getId())) {
                return false;
            }

            $menuOptionValues = Menu_option_values_model::whereIn(
                'square_id',
                collect($modifierListObject->getModifierListData()->getModifiers())->map(function ($modifier) {
                    return $modifier->getId();
                })->toArray()
            )->get()->map(function ($menuOptionValue) {
                return ['option_value_id' => $menuOptionValue->option_value_id];
            });

            if ($menuOptionValues->isEmpty()) {
                return false;
            }

            return [
                'option_id' => $menuOption->getKey(),
                'priority' => $modifierListObject->getModifierListData()->getOrdinal(),
                'min_selected' => $modifierListInfoObject->getMinSelectedModifiers(),
                'max_selected' => $modifierListInfoObject->getMaxSelectedModifiers(),
                'menu_option_values' => $menuOptionValues->all(),
            ];
        })->filter();

        Menu_item_option_values_model::unguard();

        Menu_item_options_model::unguarded(function () use ($menuItemOptions) {
            return $this->model->addMenuOption($menuItemOptions->all());
        });

        Menu_item_option_values_model::reguard();
    }

    protected function makeSquareLocationOverrideObjects()
    {
        return $this->model->getAvailableStocks()
            ->filter(function ($stock) {
                return (bool)$stock->squareLocationRemoteId();
            })
            ->map(function ($stock) {
                $itemVariationOverrides = new ItemVariationLocationOverrides();
                $itemVariationOverrides->setTrackInventory((bool)$stock->is_tracked);
                $itemVariationOverrides->setInventoryAlertType($stock->low_stock_alert ? 'LOW_QUANTITY' : 'NONE');
                $itemVariationOverrides->setInventoryAlertThreshold($stock->low_stock_threshold);
                $itemVariationOverrides->setLocationId($stock->squareLocationRemoteId());

                return $itemVariationOverrides;
            })->all();
    }

    protected function makeSquareModifierListInfoObjects()
    {
        $result = [];
        $itemOptions = $this->model->menu_options ?? [];
        foreach ($itemOptions as $itemOption) {
            if (!$itemOption->option->square_id) {
                continue;
            }

            $modifierListInfoObject = new CatalogItemModifierListInfo($itemOption->option->square_id);
            $modifierListInfoObject->setEnabled(true);
            $modifierListInfoObject->setMinSelectedModifiers($itemOption->min_selected ?: 0);
            $modifierListInfoObject->setMaxSelectedModifiers($itemOption->max_selected ?: $itemOption->menu_option_values->count() ?: 1);

            $result[] = $modifierListInfoObject;
        }

        return $result;
    }
}
