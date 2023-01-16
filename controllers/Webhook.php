<?php

namespace IgniterLabs\SquarePOS\Controllers;

use Admin\Models\Categories_model;
use Admin\Models\Menu_option_values_model;
use Admin\Models\Menu_options_model;
use Admin\Models\Menus_model;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use SquareConnect\Model\CatalogObject;

class Webhook extends Controller
{
    public function __invoke(Request $request, $token)
    {
        $payload = json_decode($request->getContent(), true);

        $eventType = $payload['type'] ?? null;
        $eventName = Str::studly(str_replace('.', '_', $eventType));
        $methodName = 'handle'.$eventName;

        if (!Settings::validateWebhook($payload, $token)) {
            return response()->json(['status' => 'Webhook validation failed'], 401);
        }

        if (method_exists($this, $methodName)) {
            $this->$methodName($payload);
        }

        return response()->json(['status' => 'ok']);
    }

    protected function handleCatalogVersionUpdated(array $payload)
    {
        $syncTask = SyncTask::firstOrCreate([
            'uuid' => Str::uuid(),
        ]);

        $syncTask->type = Settings::SQUARE_SOR;

        $catalogId = $payload['data']['id'];

        $catalogObject = $this->client->retrieveCatalogObject($catalogId);

        $model = $this->getModelByCatalog($catalogObject);

        $this->createOrUpdateModel($model, $catalogObject, $syncTask);

        $syncTask->save();
    }

    protected function handleInventoryCountUpdated(array $payload)
    {
        collect($payload['data']['object']['inventory_counts'] ?? [])
            ->each(function ($inventoryCount) {
                $catalogObject = $this->client->retrieveCatalogObject($inventoryCount['catalog_object_id']);

                $model = $this->getModelByCatalog($catalogObject);

                if ($catalogObject->getType() == 'ITEM_VARIATION') {
                    $location = Settings::locationMapping()
                        ->whereIn('remote_id', $inventoryCount['location_id'])
                        ->first();

                    $model->getAvailableStocks($location['client_id'] ?? null)
                        ->each(function ($stock) use ($inventoryCount) {
                            $stock->updateStock((int)$inventoryCount['quantity'], $inventoryCount['state']);
                        });
                }
            });
    }

    protected function getModelByCatalog(CatalogObject $catalogObject)
    {
        switch ($catalogObject->getType()) {
            case 'CATEGORY':
                return Categories_model::where('square_id', $catalogObject->getId())->first();
            case 'ITEM':
                return Menus_model::where('square_id', $catalogObject->getId())->first();
            case 'ITEM_VARIATION':
                return Menus_model::where('square_variation_id', $catalogObject->getId())->first();
            case 'MODIFIER_LIST':
                return Menu_options_model::where('square_id', $catalogObject->getId())->first();
            case 'MODIFIER':
                return Menu_option_values_model::where('square_id', $catalogObject->getId())->first();
            default:
                return null;
        }
    }

    protected function createOrUpdateModel($model, $catalogObject)
    {
        switch ($catalogObject->getType()) {
            case 'CATEGORY':
            case 'ITEM':
            case 'MODIFIER_LIST':
            case 'MODIFIER':
                return $model->createOrUpdateFromSquareCatalogObject($catalogObject);
            default:
                return null;
        }
    }
}
