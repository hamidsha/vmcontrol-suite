<?php

use WHMCS\Database\Capsule;
use WHMCS\Authentication\CurrentUser;

require_once __DIR__ . '/lib/functions.php';

add_hook('ClientAreaPrimaryNavbar', 1, function ($primaryNavbar) {
    try {
        $currentUser = new CurrentUser();
        $client = $currentUser->client();
        $userId = $client ? (int) $client->id : 0;
        if (!$userId || !Capsule::schema()->hasTable('mod_vmcontrol_services')) {
            return;
        }
        $hasService = Capsule::table('mod_vmcontrol_services as vmc')
            ->join('tblhosting as h', 'h.id', '=', 'vmc.service_id')
            ->where('h.userid', $userId)
            ->whereIn('h.domainstatus', array('Active', 'Suspended', 'Pending'))
            ->where('vmc.enabled', 1)
            ->exists();
        if ($hasService && !$primaryNavbar->getChild('VMControl')) {
            $primaryNavbar->addChild('VMControl', array(
                'label' => 'مدیریت VPS',
                'uri' => 'index.php?m=vmcontrol',
                'order' => 65,
                'icon' => 'fas fa-server',
            ));
        }
    } catch (Throwable $e) {
        // A navigation enhancement must never interrupt another WHMCS page.
    }
});

add_hook('AfterCronJob', 1, function () {
    try {
        vmcontrol_ensure_schema();
        $latestCheck = Capsule::table('mod_vmcontrol_services')->max('power_state_updated_at');
        if ($latestCheck && (time() - strtotime($latestCheck)) < 240) {
            return;
        }

        $mappings = Capsule::table('mod_vmcontrol_services as vmc')
            ->join('tblhosting as h', 'h.id', '=', 'vmc.service_id')
            ->where('vmc.enabled', 1)
            ->where('h.domainstatus', 'Active')
            ->select(
                'vmc.id', 'vmc.service_id', 'vmc.vcenter_id', 'vmc.instance_uuid',
                'vmc.display_name', 'vmc.last_power_state', 'h.userid as user_id'
            )
            ->get();
        if (!count($mappings)) {
            return;
        }

        $byVcenter = array();
        foreach ($mappings as $mapping) {
            $byVcenter[$mapping->vcenter_id][] = $mapping;
        }
        $gateway = vmcontrol_gateway();
        $sendNotifications = vmcontrol_module_setting('notify_state_changes', 'on') === 'on';
        foreach ($byVcenter as $vcenterId => $vcenterMappings) {
            try {
                $inventory = $gateway->inventory($vcenterId);
                $powerByUuid = array();
                foreach ($inventory as $vm) {
                    if (!empty($vm['instance_uuid'])) {
                        $powerByUuid[strtolower($vm['instance_uuid'])] = isset($vm['power_state']) ? $vm['power_state'] : '';
                    }
                }
                foreach ($vcenterMappings as $mapping) {
                    $key = strtolower($mapping->instance_uuid);
                    if (isset($powerByUuid[$key]) && $powerByUuid[$key] !== '') {
                        vmcontrol_track_power_state($mapping, $powerByUuid[$key], $sendNotifications);
                    }
                }
            } catch (Throwable $exception) {
                vmcontrol_log_failure('power monitor cron for ' . $vcenterId, $exception);
            }
        }
    } catch (Throwable $exception) {
        vmcontrol_log_failure('power monitor cron', $exception);
    }
});
