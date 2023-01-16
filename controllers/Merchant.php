<?php

namespace IgniterLabs\SquarePOS\Controllers;

use Admin\Classes\AdminController;
use Exception;
use Igniter\Flame\Exception\ApplicationException;
use IgniterLabs\SquarePOS\Jobs\SyncCatalogJob;
use IgniterLabs\SquarePOS\Models\Settings;
use IgniterLabs\SquarePOS\Models\SyncTask;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Str;
use System\Traits\SessionMaker;

class Merchant extends AdminController
{
    use SessionMaker;

    protected $requiredPermissions = ['IgniterLabs.SquarePOS.Manage'];

    protected $settingsUri = 'extensions/edit/igniterlabs/squarepos/settings';

    public function authorize($context)
    {
        $this->putSession('state', $state = str_random(8));
        $this->putSession('redirect', admin_url($this->settingsUri));

        $params['redirect'] = admin_url('igniterlabs/squarepos/merchant/token?state=').$state;

        return Redirect::to(Settings::getAuthorizeUrl($params));
    }

    public function revoke($context)
    {
        Settings::clearConnectionData();

        return Redirect::to(admin_url($this->settingsUri));
    }

    public function token($context)
    {
        try {
            if (strlen(input('error_message'))) {
                flash()->error(input('error_message'));
            }
            else {
                if ($this->getSession('state') !== input('state')) {
                    throw new ApplicationException('Access denied, invalid state');
                }

                Settings::storeConnectionData(input());
            }
        }
        catch (Exception $ex) {
            flash()->error($ex->getMessage());
        }

        return Redirect::to($this->getSession('redirect'));
    }

    public function sync($context, $action = null)
    {
        rescue(function () {
            if (!Settings::isConnected()) {
                throw new ApplicationException(lang('igniterlabs.squarepos::default.alert_not_connected'));
            }

            if (Settings::locationMapping()->isEmpty()) {
                throw new ApplicationException(lang('igniterlabs.squarepos::default.alert_no_location_mapping'));
            }

            $syncTask = SyncTask::startSync(Str::uuid(), Settings::getSystemOfRecord());

            dispatch((new SyncCatalogJob())->uuid($syncTask->uuid));

            flash()->success(lang('igniterlabs.squarepos::default.alert_sync_started'));
        }, function ($ex) {
            flash()->error($ex->getMessage());
        });

        return Redirect::to(admin_url($this->settingsUri));
    }
}
