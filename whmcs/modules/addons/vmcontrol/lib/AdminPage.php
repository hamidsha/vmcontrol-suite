<?php

use WHMCS\Database\Capsule;

function vmcontrol_admin_h($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function vmcontrol_admin_allowed_service($serviceId)
{
    $service = Capsule::table('tblhosting as h')
        ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
        ->where('h.id', (int) $serviceId)
        ->select('h.id', 'h.domainstatus', 'h.userid', 'c.firstname', 'c.lastname')
        ->first();

    if (!$service) {
        throw new RuntimeException('سرویس WHMCS پیدا نشد.');
    }
    if (!in_array($service->domainstatus, array('Active', 'Pending', 'Suspended'), true)) {
        throw new RuntimeException(
            'سرویس #' . (int) $serviceId . ' با وضعیت ' . $service->domainstatus . ' قابل واگذاری نیست.'
        );
    }
    return $service;
}

function vmcontrol_admin_save_mapping(array $input, $mappingId)
{
    $serviceId = isset($input['service_id']) ? (int) $input['service_id'] : 0;
    $vcenterId = isset($input['vcenter_id']) ? trim($input['vcenter_id']) : '';
    $instanceUuid = isset($input['instance_uuid']) ? strtolower(trim($input['instance_uuid'])) : '';
    $enabled = !empty($input['enabled']) ? 1 : 0;

    if ($serviceId < 1
        || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $vcenterId)
        || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $instanceUuid)
    ) {
        throw new RuntimeException('Service ID، شناسه vCenter یا Instance UUID معتبر نیست.');
    }

    vmcontrol_admin_allowed_service($serviceId);

    $current = null;
    if ((int) $mappingId > 0) {
        $current = Capsule::table('mod_vmcontrol_services')->where('id', (int) $mappingId)->first();
        if (!$current) {
            throw new RuntimeException('نگاشت انتخاب‌شده دیگر وجود ندارد.');
        }
    }
    if (!$current) {
        $current = Capsule::table('mod_vmcontrol_services')->where('service_id', $serviceId)->first();
    }

    $serviceConflict = Capsule::table('mod_vmcontrol_services')
        ->where('service_id', $serviceId);
    if ($current) {
        $serviceConflict->where('id', '<>', (int) $current->id);
    }
    $serviceConflict = $serviceConflict->first();
    if ($serviceConflict) {
        throw new RuntimeException('سرویس #' . $serviceId . ' قبلاً به یک ماشین دیگر متصل شده است.');
    }

    $vmConflict = Capsule::table('mod_vmcontrol_services')
        ->where('vcenter_id', $vcenterId)
        ->where('instance_uuid', $instanceUuid);
    if ($current) {
        $vmConflict->where('id', '<>', (int) $current->id);
    }
    $vmConflict = $vmConflict->first();
    if ($vmConflict) {
        throw new RuntimeException(
            'این ماشین قبلاً به سرویس #' . (int) $vmConflict->service_id . ' متصل شده است.'
        );
    }

    $now = date('Y-m-d H:i:s');
    $values = array(
        'service_id' => $serviceId,
        'vcenter_id' => $vcenterId,
        'instance_uuid' => $instanceUuid,
        'enabled' => $enabled,
        'admin_id' => isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : null,
        'updated_at' => $now,
    );

    if ($current && (int) $current->service_id !== $serviceId) {
        // A customer-facing alias must never follow a VM when an administrator
        // transfers its mapping to a different WHMCS service/customer.
        $values['display_name'] = null;
    }

    if ($current) {
        Capsule::table('mod_vmcontrol_services')->where('id', (int) $current->id)->update($values);
        return (int) $current->id;
    }

    $values['created_at'] = $now;
    return (int) Capsule::table('mod_vmcontrol_services')->insertGetId($values);
}

function vmcontrol_admin_import_csv($temporaryPath)
{
    if (!is_file($temporaryPath) || filesize($temporaryPath) > 2097152) {
        throw new RuntimeException('فایل CSV معتبر نیست یا بیشتر از ۲ مگابایت است.');
    }

    $handle = fopen($temporaryPath, 'rb');
    if (!$handle) {
        throw new RuntimeException('امکان خواندن فایل CSV وجود ندارد.');
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        throw new RuntimeException('فایل CSV خالی است.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
    $header = array_map(function ($value) {
        return strtolower(trim((string) $value));
    }, $header);

    $required = array('service_id', 'vcenter_id', 'instance_uuid');
    foreach ($required as $column) {
        if (!in_array($column, $header, true)) {
            fclose($handle);
            throw new RuntimeException('ستون الزامی ' . $column . ' در فایل وجود ندارد.');
        }
    }

    $rows = array();
    $line = 1;
    while (($values = fgetcsv($handle)) !== false) {
        $line++;
        if (count($values) === 1 && trim((string) $values[0]) === '') {
            continue;
        }
        $row = array();
        foreach ($header as $index => $column) {
            $row[$column] = isset($values[$index]) ? trim((string) $values[$index]) : '';
        }
        $enabledValue = isset($row['enabled']) ? strtolower($row['enabled']) : '1';
        $row['enabled'] = !in_array($enabledValue, array('0', 'no', 'false', 'disabled'), true) ? 1 : 0;
        $row['_line'] = $line;
        $rows[] = $row;
    }
    fclose($handle);

    if (!$rows) {
        throw new RuntimeException('فایل CSV هیچ ردیف اطلاعاتی ندارد.');
    }

    Capsule::connection()->transaction(function () use ($rows) {
        foreach ($rows as $row) {
            try {
                vmcontrol_admin_save_mapping($row, 0);
            } catch (Exception $exception) {
                throw new RuntimeException('خط ' . $row['_line'] . ': ' . $exception->getMessage());
            }
        }
    });

    return count($rows);
}

function vmcontrol_admin_inventory($forceRefresh, &$warnings)
{
    $cacheKey = 'vmcontrol_admin_inventory_v2';
    if (!$forceRefresh
        && !empty($_SESSION[$cacheKey]['fetched_at'])
        && (time() - (int) $_SESSION[$cacheKey]['fetched_at']) < 120
        && isset($_SESSION[$cacheKey]['items'])
    ) {
        return $_SESSION[$cacheKey]['items'];
    }

    $items = array();
    try {
        $gateway = vmcontrol_gateway();
        $vcenters = $gateway->vcenters();
        foreach ($vcenters as $vcenterId) {
            try {
                foreach ($gateway->inventory($vcenterId) as $vm) {
                    $vm['vcenter_id'] = $vcenterId;
                    $items[] = $vm;
                }
            } catch (Exception $exception) {
                $warnings[] = 'خواندن موجودی vCenter «' . $vcenterId . '» ناموفق بود: ' . $exception->getMessage();
            }
        }
        $_SESSION[$cacheKey] = array('fetched_at' => time(), 'items' => $items);
    } catch (Exception $exception) {
        $warnings[] = 'فهرست ماشین‌های Gateway در دسترس نیست: ' . $exception->getMessage();
    }
    return $items;
}

function vmcontrol_admin_status_class($status)
{
    if ($status === 'Active') {
        return 'vmc-badge-success';
    }
    if ($status === 'Pending') {
        return 'vmc-badge-info';
    }
    if ($status === 'Suspended') {
        return 'vmc-badge-warning';
    }
    return 'vmc-badge-muted';
}

function vmcontrol_admin_ips($value)
{
    $result = array();
    foreach (preg_split('/[\s,;]+/', trim((string) $value)) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            $result[$candidate] = true;
        }
    }
    return array_keys($result);
}

function vmcontrol_admin_match_suggestions($services, $inventory, array $mappedServices, array $mappedVMs, &$ambiguous)
{
    $servicesByIp = array();
    $vmsByIp = array();
    foreach ($services as $service) {
        if (isset($mappedServices[(int) $service->service_id])) {
            continue;
        }
        foreach (array_unique(array_merge(
            vmcontrol_admin_ips($service->dedicatedip),
            vmcontrol_admin_ips($service->assignedips)
        )) as $ip) {
            $servicesByIp[$ip][] = $service;
        }
    }
    foreach ($inventory as $vm) {
        $uuid = isset($vm['instance_uuid']) ? strtolower(trim($vm['instance_uuid'])) : '';
        $key = strtolower($vm['vcenter_id'] . '|' . $uuid);
        if (!$uuid || isset($mappedVMs[$key])) {
            continue;
        }
        foreach (vmcontrol_admin_ips(isset($vm['ip_address']) ? $vm['ip_address'] : '') as $ip) {
            $vmsByIp[$ip][] = $vm;
        }
    }
    $suggestions = array();
    $ambiguous = array();
    foreach ($servicesByIp as $ip => $matchingServices) {
        if (empty($vmsByIp[$ip])) {
            continue;
        }
        if (count($matchingServices) === 1 && count($vmsByIp[$ip]) === 1) {
            $suggestions[] = array('ip' => $ip, 'service' => $matchingServices[0], 'vm' => $vmsByIp[$ip][0]);
        } else {
            $ambiguous[] = array(
                'ip' => $ip,
                'service_count' => count($matchingServices),
                'vm_count' => count($vmsByIp[$ip]),
            );
        }
    }
    usort($suggestions, function ($left, $right) {
        return (int) $right['service']->service_id - (int) $left['service']->service_id;
    });
    return $suggestions;
}

function vmcontrol_admin_confirm_suggestion($serviceId, $vcenterId, $instanceUuid)
{
    $services = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->whereIn('h.domainstatus', array('Active', 'Pending', 'Suspended'))
        ->select(
            'h.id as service_id', 'h.userid as client_id', 'h.domain', 'h.dedicatedip',
            'h.assignedips', 'h.domainstatus', 'p.name as product_name',
            'c.firstname', 'c.lastname', 'c.companyname'
        )
        ->get();
    $mappings = Capsule::table('mod_vmcontrol_services')->get();
    $mappedServices = array();
    $mappedVMs = array();
    foreach ($mappings as $mapping) {
        $mappedServices[(int) $mapping->service_id] = true;
        $mappedVMs[strtolower($mapping->vcenter_id . '|' . $mapping->instance_uuid)] = true;
    }
    $warnings = array();
    $inventory = vmcontrol_admin_inventory(true, $warnings);
    if ($warnings) {
        throw new RuntimeException('امکان بازبینی پیشنهاد با موجودی زنده vCenter وجود ندارد؛ هیچ اتصالی ثبت نشد.');
    }
    $ambiguous = array();
    $suggestions = vmcontrol_admin_match_suggestions(
        $services,
        $inventory,
        $mappedServices,
        $mappedVMs,
        $ambiguous
    );
    $target = strtolower(trim((string) $vcenterId) . '|' . trim((string) $instanceUuid));
    foreach ($suggestions as $suggestion) {
        $vm = $suggestion['vm'];
        $candidate = strtolower($vm['vcenter_id'] . '|' . $vm['instance_uuid']);
        if ((int) $suggestion['service']->service_id === (int) $serviceId && $candidate === $target) {
            return vmcontrol_admin_save_mapping(array(
                'service_id' => (int) $serviceId,
                'vcenter_id' => $vm['vcenter_id'],
                'instance_uuid' => $vm['instance_uuid'],
                'enabled' => 1,
            ), 0);
        }
    }
    throw new RuntimeException('این پیشنهاد دیگر یک تطبیق قطعی یک‌به‌یک نیست؛ هیچ اتصالی ثبت نشد.');
}

function vmcontrol_admin_render($vars)
{
    $moduleLink = $vars['modulelink'];
    $message = '';
    $error = '';
    $warnings = array();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!vmcontrol_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            $error = 'توکن فرم نامعتبر یا منقضی شده است.';
        } else {
            try {
                $action = isset($_POST['vmc_action']) ? (string) $_POST['vmc_action'] : 'save';
                if ($action === 'confirm_suggestion') {
                    $target = isset($_POST['inventory_target']) ? explode('|', $_POST['inventory_target'], 2) : array();
                    if (count($target) !== 2) {
                        throw new RuntimeException('ماشین پیشنهادی معتبر نیست.');
                    }
                    $serviceId = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
                    vmcontrol_admin_confirm_suggestion($serviceId, $target[0], $target[1]);
                    $ownerId = (int) Capsule::table('tblhosting')->where('id', $serviceId)->value('userid');
                    vmcontrol_record_event($serviceId, $ownerId, 'mapping', 'success', 'پیشنهاد تطبیق IP پس از تأیید مدیر ثبت شد.');
                    unset($_SESSION['vmcontrol_admin_inventory_v2']);
                    $message = 'پیشنهاد پس از بازبینی زنده و تأیید شما ثبت شد.';
                } elseif ($action === 'save') {
                    $target = isset($_POST['inventory_target']) ? explode('|', $_POST['inventory_target'], 2) : array();
                    $input = array(
                        'service_id' => isset($_POST['service_id']) ? $_POST['service_id'] : 0,
                        'vcenter_id' => count($target) === 2 ? $target[0] : (isset($_POST['vcenter_id']) ? $_POST['vcenter_id'] : ''),
                        'instance_uuid' => count($target) === 2 ? $target[1] : (isset($_POST['instance_uuid']) ? $_POST['instance_uuid'] : ''),
                        'enabled' => isset($_POST['enabled']) ? 1 : 0,
                    );
                    $mappingId = isset($_POST['mapping_id']) ? (int) $_POST['mapping_id'] : 0;
                    $savedMappingId = vmcontrol_admin_save_mapping($input, $mappingId);
                    $ownerId = (int) Capsule::table('tblhosting')->where('id', (int) $input['service_id'])->value('userid');
                    vmcontrol_record_event((int) $input['service_id'], $ownerId, 'mapping', 'success', 'اتصال سرور توسط مدیر ثبت یا ویرایش شد.');
                    unset($_SESSION['vmcontrol_admin_inventory_v2']);
                    $message = $mappingId ? 'نگاشت با موفقیت ویرایش شد.' : 'ماشین با موفقیت به سرویس متصل شد.';
                } elseif ($action === 'delete') {
                    $mappingId = isset($_POST['mapping_id']) ? (int) $_POST['mapping_id'] : 0;
                    $deletedMapping = Capsule::table('mod_vmcontrol_services')->where('id', $mappingId)->first();
                    $deleted = Capsule::table('mod_vmcontrol_services')->where('id', $mappingId)->delete();
                    if (!$deleted) {
                        throw new RuntimeException('نگاشت برای حذف پیدا نشد.');
                    }
                    if ($deletedMapping) {
                        $ownerId = (int) Capsule::table('tblhosting')->where('id', (int) $deletedMapping->service_id)->value('userid');
                        vmcontrol_record_event((int) $deletedMapping->service_id, $ownerId, 'mapping_removed', 'success', 'اتصال سرور توسط مدیر حذف شد.');
                    }
                    $message = 'نگاشت حذف شد. ماشین vCenter و سرویس WHMCS هیچ تغییری نکردند.';
                } elseif ($action === 'toggle') {
                    $mappingId = isset($_POST['mapping_id']) ? (int) $_POST['mapping_id'] : 0;
                    $enabled = isset($_POST['set_enabled']) && (int) $_POST['set_enabled'] === 1 ? 1 : 0;
                    $updated = Capsule::table('mod_vmcontrol_services')->where('id', $mappingId)->update(array(
                        'enabled' => $enabled,
                        'admin_id' => isset($_SESSION['adminid']) ? (int) $_SESSION['adminid'] : null,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ));
                    if (!$updated && !Capsule::table('mod_vmcontrol_services')->where('id', $mappingId)->exists()) {
                        throw new RuntimeException('نگاشت برای تغییر وضعیت پیدا نشد.');
                    }
                    $changedMapping = Capsule::table('mod_vmcontrol_services')->where('id', $mappingId)->first();
                    if ($changedMapping) {
                        $ownerId = (int) Capsule::table('tblhosting')->where('id', (int) $changedMapping->service_id)->value('userid');
                        vmcontrol_record_event((int) $changedMapping->service_id, $ownerId, 'access', 'success', $enabled ? 'دسترسی مشتری توسط مدیر فعال شد.' : 'دسترسی مشتری توسط مدیر غیرفعال شد.');
                    }
                    $message = $enabled ? 'دسترسی مشتری فعال شد.' : 'دسترسی مشتری غیرفعال شد.';
                } elseif ($action === 'import') {
                    if (empty($_FILES['mapping_csv']) || $_FILES['mapping_csv']['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('فایل CSV به‌درستی دریافت نشد.');
                    }
                    $count = vmcontrol_admin_import_csv($_FILES['mapping_csv']['tmp_name']);
                    unset($_SESSION['vmcontrol_admin_inventory_v2']);
                    $message = number_format($count) . ' نگاشت از فایل CSV ثبت یا به‌روزرسانی شد.';
                } else {
                    throw new RuntimeException('عملیات درخواستی معتبر نیست.');
                }
            } catch (Exception $exception) {
                $error = $exception->getMessage();
            }
        }
    }

    $services = Capsule::table('tblhosting as h')
        ->join('tblclients as c', 'c.id', '=', 'h.userid')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->whereIn('h.domainstatus', array('Active', 'Pending', 'Suspended'))
        ->select(
            'h.id as service_id', 'h.userid as client_id', 'h.domain', 'h.dedicatedip',
            'h.assignedips', 'h.domainstatus', 'p.name as product_name',
            'c.firstname', 'c.lastname', 'c.companyname'
        )
        ->orderBy('h.id', 'desc')
        ->get();

    $mappings = Capsule::table('mod_vmcontrol_services as vmc')
        ->leftJoin('tblhosting as h', 'h.id', '=', 'vmc.service_id')
        ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
        ->leftJoin('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->select(
            'vmc.*', 'h.domainstatus', 'h.domain', 'h.dedicatedip', 'h.assignedips', 'p.name as product_name',
            'c.id as client_id', 'c.firstname', 'c.lastname', 'c.companyname'
        )
        ->orderBy('vmc.id', 'desc')
        ->get();

    $inventory = vmcontrol_admin_inventory(!empty($_GET['refresh_inventory']), $warnings);
    $mappedVMs = array();
    $mappedServices = array();
    $inventoryByKey = array();
    foreach ($inventory as $vm) {
        if (!empty($vm['instance_uuid'])) {
            $inventoryByKey[strtolower($vm['vcenter_id'] . '|' . $vm['instance_uuid'])] = $vm;
        }
    }
    foreach ($mappings as $mapping) {
        $mappedVMs[strtolower($mapping->vcenter_id . '|' . $mapping->instance_uuid)] = (int) $mapping->service_id;
        $mappedServices[(int) $mapping->service_id] = true;
    }
    $availableVMs = 0;
    foreach ($inventory as $vm) {
        $key = strtolower($vm['vcenter_id'] . '|' . $vm['instance_uuid']);
        if (!empty($vm['instance_uuid']) && !isset($mappedVMs[$key])) {
            $availableVMs++;
        }
    }

    $ambiguousMatches = array();
    $matchSuggestions = vmcontrol_admin_match_suggestions(
        $services,
        $inventory,
        $mappedServices,
        $mappedVMs,
        $ambiguousMatches
    );
    $recentEvents = Capsule::table('mod_vmcontrol_events as event')
        ->leftJoin('tblhosting as h', 'h.id', '=', 'event.service_id')
        ->leftJoin('tblclients as c', 'c.id', '=', 'h.userid')
        ->select(
            'event.service_id', 'event.event_type', 'event.result', 'event.source_ip',
            'event.message', 'event.created_at', 'c.firstname', 'c.lastname'
        )
        ->orderBy('event.id', 'desc')
        ->limit(30)
        ->get();

    ob_start();
    ?>
    <div class="vmc-admin" dir="rtl">
        <style>
            .vmc-admin{font-family:Tahoma,Arial,sans-serif;color:#1f2937}.vmc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:18px;margin:4px 0 18px}.vmc-head h2{margin:0 0 7px;font-size:23px;color:#123c5a}.vmc-head p{margin:0;color:#64748b}.vmc-refresh{white-space:nowrap}.vmc-stats{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:12px;margin-bottom:18px}.vmc-stat{background:#fff;border:1px solid #dbe5ec;border-radius:8px;padding:14px 16px;box-shadow:0 1px 3px rgba(15,23,42,.05)}.vmc-stat strong{display:block;font-size:24px;color:#0f5f8f}.vmc-stat span{color:#64748b;font-size:12px}.vmc-card{background:#fff;border:1px solid #d6e2ea;border-radius:9px;margin-bottom:18px;overflow:hidden;box-shadow:0 2px 8px rgba(15,23,42,.05)}.vmc-card-head{padding:15px 18px;background:#f6f9fb;border-bottom:1px solid #dbe5ec}.vmc-card-head h3{font-size:16px;margin:0 0 5px;color:#123c5a}.vmc-card-head p{margin:0;color:#64748b;font-size:12px}.vmc-assign{display:grid;grid-template-columns:1fr 1fr;gap:18px;padding:18px}.vmc-picker label{display:block;font-weight:700;margin-bottom:7px}.vmc-search{margin-bottom:8px}.vmc-picker select{width:100%;height:250px}.vmc-option-note{font-size:11px;color:#64748b;margin-top:6px}.vmc-form-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:0 18px 18px}.vmc-manual{display:grid;grid-template-columns:180px 1fr;gap:10px;padding:0 18px 16px}.vmc-manual summary{cursor:pointer;color:#51697a}.vmc-actions{display:flex;gap:8px;flex-wrap:wrap}.vmc-badge{display:inline-block;padding:3px 8px;border-radius:999px;font-size:11px;font-weight:700}.vmc-badge-success{color:#087443;background:#e8f7ef}.vmc-badge-warning{color:#946200;background:#fff5d6}.vmc-badge-info{color:#075985;background:#e0f2fe}.vmc-badge-muted{color:#64748b;background:#eef2f6}.vmc-code{direction:ltr;text-align:left;font-family:monospace;font-size:12px}.vmc-table-wrap{overflow:auto}.vmc-table{margin:0;min-width:1050px}.vmc-table td,.vmc-table th{vertical-align:middle!important}.vmc-table-search{max-width:340px;margin:12px 18px}.vmc-empty{text-align:center;padding:35px;color:#64748b}.vmc-import{padding:16px 18px}.vmc-import-grid{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.vmc-import code{direction:ltr;display:inline-block}.vmc-danger-note{color:#b42318;font-size:11px}.vmc-ltr{direction:ltr;text-align:left}.vmc-hidden{display:none!important}@media(max-width:900px){.vmc-stats{grid-template-columns:repeat(2,1fr)}.vmc-assign{grid-template-columns:1fr}.vmc-form-foot{align-items:flex-start;flex-direction:column}.vmc-manual{grid-template-columns:1fr}}@media(max-width:520px){.vmc-stats{grid-template-columns:1fr}.vmc-head{flex-direction:column}}
        </style>

        <div class="vmc-head">
            <div>
                <h2>مدیریت اتصال سرورها</h2>
                <p>واگذاری، انتقال و حذف دسترسی مشتری بدون تغییر در ماشین vCenter یا سرویس WHMCS</p>
            </div>
            <a class="btn btn-default vmc-refresh" href="<?php echo vmcontrol_admin_h($moduleLink); ?>&amp;refresh_inventory=1">
                <i class="fas fa-sync-alt"></i> تازه‌سازی ماشین‌ها
            </a>
        </div>

        <?php if ($message): ?><div class="alert alert-success"><?php echo vmcontrol_admin_h($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-danger"><?php echo vmcontrol_admin_h($error); ?></div><?php endif; ?>
        <?php foreach ($warnings as $warning): ?><div class="alert alert-warning"><?php echo vmcontrol_admin_h($warning); ?></div><?php endforeach; ?>

        <div class="vmc-stats">
            <div class="vmc-stat"><strong><?php echo number_format(count($mappings)); ?></strong><span>نگاشت ثبت‌شده</span></div>
            <div class="vmc-stat"><strong><?php echo number_format(count($services)); ?></strong><span>سرویس قابل واگذاری</span></div>
            <div class="vmc-stat"><strong><?php echo number_format(count($inventory)); ?></strong><span>ماشین قابل‌مشاهده</span></div>
            <div class="vmc-stat"><strong><?php echo number_format(count($matchSuggestions)); ?></strong><span>پیشنهاد تطبیق قطعی IP</span></div>
        </div>

        <div class="vmc-card">
            <div class="vmc-card-head">
                <h3>پیشنهادهای تطبیق IP ـ فقط با تأیید ادمین</h3>
                <p>این بخش هیچ نگاشتی را خودکار ثبت نمی‌کند. فقط تطبیق‌های یک‌به‌یک بین IP سرویس WHMCS و IP ماشین آزاد را پیشنهاد می‌دهد.</p>
            </div>
            <div class="vmc-table-wrap">
                <table class="table table-striped vmc-table">
                    <thead><tr><th>IP مشترک</th><th>سرویس و مشتری</th><th>ماشین پیشنهادی</th><th>تأیید</th></tr></thead>
                    <tbody>
                    <?php foreach ($matchSuggestions as $suggestion):
                        $service = $suggestion['service'];
                        $vm = $suggestion['vm'];
                        $customer = trim($service->firstname . ' ' . $service->lastname);
                        if (trim((string) $service->companyname) !== '') { $customer .= ' / ' . $service->companyname; }
                    ?>
                        <tr>
                            <td class="vmc-ltr"><strong><?php echo vmcontrol_admin_h($suggestion['ip']); ?></strong></td>
                            <td><strong>#<?php echo (int) $service->service_id; ?></strong> — <?php echo vmcontrol_admin_h($customer); ?><br><small><?php echo vmcontrol_admin_h($service->product_name . ' / ' . $service->domainstatus); ?></small></td>
                            <td class="vmc-ltr"><strong><?php echo vmcontrol_admin_h($vm['name']); ?></strong><br><small><?php echo vmcontrol_admin_h($vm['vcenter_id']); ?></small></td>
                            <td>
                                <form method="post" action="<?php echo vmcontrol_admin_h($moduleLink); ?>" class="vmc-suggestion-form">
                                    <input type="hidden" name="csrf" value="<?php echo vmcontrol_admin_h(vmcontrol_csrf_token()); ?>">
                                    <input type="hidden" name="vmc_action" value="confirm_suggestion">
                                    <input type="hidden" name="service_id" value="<?php echo (int) $service->service_id; ?>">
                                    <input type="hidden" name="inventory_target" value="<?php echo vmcontrol_admin_h($vm['vcenter_id'] . '|' . $vm['instance_uuid']); ?>">
                                    <input type="hidden" name="enabled" value="1">
                                    <button class="btn btn-sm btn-success" type="submit"><i class="fas fa-check"></i> تأیید و ثبت</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!count($matchSuggestions)): ?><tr><td colspan="4" class="vmc-empty">در حال حاضر تطبیق یک‌به‌یک قابل‌اعتمادی پیدا نشد.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($ambiguousMatches)): ?>
                <div class="alert alert-warning" style="margin:12px 18px">برای <?php echo number_format(count($ambiguousMatches)); ?> IP تطبیق مبهم پیدا شد؛ به‌دلیل تکراری‌بودن IP، دکمه ثبت خودکار برای آن‌ها نمایش داده نمی‌شود.</div>
            <?php endif; ?>
        </div>

        <form method="post" action="<?php echo vmcontrol_admin_h($moduleLink); ?>" id="vmcMappingForm" class="vmc-card">
            <input type="hidden" name="csrf" value="<?php echo vmcontrol_admin_h(vmcontrol_csrf_token()); ?>">
            <input type="hidden" name="vmc_action" value="save">
            <input type="hidden" name="mapping_id" id="vmcMappingId" value="0">
            <div class="vmc-card-head">
                <h3 id="vmcFormTitle">واگذاری سریع ماشین</h3>
                <p>یک سرویس و یک ماشین را انتخاب کنید. Instance UUID به‌صورت خودکار ثبت می‌شود.</p>
            </div>
            <div class="vmc-assign">
                <div class="vmc-picker">
                    <label for="vmcService">۱. سرویس و مشتری</label>
                    <input class="form-control vmc-search" id="vmcServiceSearch" placeholder="جست‌وجو با Service ID، نام مشتری، IP یا محصول">
                    <select class="form-control" name="service_id" id="vmcService" size="10" required>
                        <?php foreach ($services as $service):
                            $clientName = trim($service->firstname . ' ' . $service->lastname);
                            $company = trim((string) $service->companyname);
                            $mapped = isset($mappedServices[(int) $service->service_id]);
                            $serviceIP = trim((string) $service->dedicatedip) !== '' ? $service->dedicatedip : trim((string) $service->assignedips);
                            $label = '#' . $service->service_id . ' — ' . ($company ?: $clientName)
                                . ' — ' . ($serviceIP ?: 'بدون IP') . ' — ' . $service->domainstatus
                                . ($mapped ? ' — متصل' : '');
                        ?>
                            <option value="<?php echo (int) $service->service_id; ?>" data-search="<?php echo vmcontrol_admin_h(strtolower($label . ' ' . $service->product_name . ' ' . $service->domain . ' ' . $service->assignedips)); ?>"><?php echo vmcontrol_admin_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="vmc-option-note">سرویس‌های Active، Pending و Suspended نمایش داده می‌شوند؛ مشتری فقط روی Active دسترسی عملیاتی دارد.</div>
                </div>
                <div class="vmc-picker">
                    <label for="vmcInventory">۲. ماشین vCenter</label>
                    <input class="form-control vmc-search" id="vmcInventorySearch" placeholder="جست‌وجو با نام VM، IP، UUID یا vCenter">
                    <select class="form-control vmc-ltr" name="inventory_target" id="vmcInventory" size="10">
                        <?php foreach ($inventory as $vm):
                            $uuid = isset($vm['instance_uuid']) ? strtolower(trim($vm['instance_uuid'])) : '';
                            $key = strtolower($vm['vcenter_id'] . '|' . $uuid);
                            $usedBy = isset($mappedVMs[$key]) ? $mappedVMs[$key] : 0;
                            $label = $vm['name'] . ' | ' . ($vm['ip_address'] ?: 'no IP') . ' | ' . $vm['vcenter_id']
                                . ($usedBy ? ' | mapped to #' . $usedBy : '')
                                . (!$uuid ? ' | UUID missing' : '');
                        ?>
                            <option value="<?php echo vmcontrol_admin_h($vm['vcenter_id'] . '|' . $uuid); ?>" data-search="<?php echo vmcontrol_admin_h(strtolower($label . ' ' . $uuid . ' ' . $vm['inventory_path'])); ?>" <?php echo !$uuid ? 'disabled' : ''; ?>><?php echo vmcontrol_admin_h($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="vmc-option-note">ماشین‌های متصل نیز مشخص شده‌اند تا واگذاری تکراری انجام نشود.</div>
                </div>
            </div>
            <details class="vmc-manual">
                <summary>ورود دستی در صورت در دسترس نبودن فهرست Gateway</summary>
                <div></div>
                <input class="form-control vmc-ltr" name="vcenter_id" id="vmcManualVCenter" placeholder="vCenter ID">
                <input class="form-control vmc-ltr" name="instance_uuid" id="vmcManualUuid" placeholder="Instance UUID">
            </details>
            <div class="vmc-form-foot">
                <label><input type="checkbox" name="enabled" id="vmcEnabled" value="1" checked> دسترسی مشتری فعال باشد</label>
                <div class="vmc-actions">
                    <button class="btn btn-primary" type="submit" id="vmcSaveButton"><i class="fas fa-link"></i> ثبت اتصال</button>
                    <button class="btn btn-default vmc-hidden" type="button" id="vmcCancelEdit">لغو ویرایش</button>
                </div>
            </div>
        </form>

        <div class="vmc-card">
            <div class="vmc-card-head"><h3>نگاشت‌های فعلی</h3><p>ویرایش برای انتقال ماشین یا مشتری؛ حذف فقط اتصال VMControl را پاک می‌کند.</p></div>
            <input class="form-control vmc-table-search" id="vmcMappingSearch" placeholder="جست‌وجو در نگاشت‌ها">
            <div class="vmc-table-wrap">
                <table class="table table-striped vmc-table" id="vmcMappingTable">
                    <thead><tr><th>سرویس و مشتری</th><th>IP در WHMCS</th><th>تطبیق IP</th><th>وضعیت سرویس</th><th>ماشین vCenter</th><th>دسترسی</th><th>عملیات</th></tr></thead>
                    <tbody>
                    <?php foreach ($mappings as $mapping):
                        $customer = trim($mapping->firstname . ' ' . $mapping->lastname);
                        if (trim((string) $mapping->companyname) !== '') { $customer .= ' / ' . $mapping->companyname; }
                        $mappingKey = strtolower($mapping->vcenter_id . '|' . $mapping->instance_uuid);
                        $mappedVM = isset($inventoryByKey[$mappingKey]) ? $inventoryByKey[$mappingKey] : null;
                        $whmcsIps = array_unique(array_merge(
                            vmcontrol_admin_ips($mapping->dedicatedip),
                            vmcontrol_admin_ips(isset($mapping->assignedips) ? $mapping->assignedips : '')
                        ));
                        $vmIp = $mappedVM && !empty($mappedVM['ip_address']) ? trim($mappedVM['ip_address']) : '';
                        $ipMatches = $vmIp !== '' && in_array($vmIp, $whmcsIps, true);
                    ?>
                        <tr data-search="<?php echo vmcontrol_admin_h(strtolower('#' . $mapping->service_id . ' ' . $customer . ' ' . $mapping->dedicatedip . ' ' . $mapping->instance_uuid . ' ' . $mapping->vcenter_id)); ?>">
                            <td><strong>#<?php echo (int) $mapping->service_id; ?></strong><br><small><?php echo vmcontrol_admin_h($customer); ?></small></td>
                            <td class="vmc-ltr"><?php echo vmcontrol_admin_h($mapping->dedicatedip ?: '—'); ?></td>
                            <td><?php if ($vmIp === ''): ?><span class="vmc-badge vmc-badge-muted">IP ماشین نامشخص</span><?php elseif ($ipMatches): ?><span class="vmc-badge vmc-badge-success">هماهنگ</span><?php else: ?><span class="vmc-badge vmc-badge-warning">مغایرت: <?php echo vmcontrol_admin_h($vmIp); ?></span><?php endif; ?></td>
                            <td><span class="vmc-badge <?php echo vmcontrol_admin_status_class($mapping->domainstatus); ?>"><?php echo vmcontrol_admin_h($mapping->domainstatus ?: 'Missing'); ?></span></td>
                            <td class="vmc-ltr"><strong><?php echo vmcontrol_admin_h($mappedVM ? $mappedVM['name'] : 'VM not found in inventory'); ?></strong><br><small><?php echo vmcontrol_admin_h($mapping->vcenter_id); ?></small><br><span class="vmc-code"><?php echo vmcontrol_admin_h($mapping->instance_uuid); ?></span></td>
                            <td><?php echo (int) $mapping->enabled ? '<span class="vmc-badge vmc-badge-success">فعال</span>' : '<span class="vmc-badge vmc-badge-muted">غیرفعال</span>'; ?></td>
                            <td>
                                <div class="vmc-actions">
                                    <button type="button" class="btn btn-xs btn-default vmc-edit" data-id="<?php echo (int) $mapping->id; ?>" data-service="<?php echo (int) $mapping->service_id; ?>" data-vcenter="<?php echo vmcontrol_admin_h($mapping->vcenter_id); ?>" data-uuid="<?php echo vmcontrol_admin_h($mapping->instance_uuid); ?>" data-enabled="<?php echo (int) $mapping->enabled; ?>"><i class="fas fa-edit"></i> ویرایش/انتقال</button>
                                    <form method="post" action="<?php echo vmcontrol_admin_h($moduleLink); ?>" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?php echo vmcontrol_admin_h(vmcontrol_csrf_token()); ?>"><input type="hidden" name="vmc_action" value="toggle"><input type="hidden" name="mapping_id" value="<?php echo (int) $mapping->id; ?>"><input type="hidden" name="set_enabled" value="<?php echo (int) $mapping->enabled ? 0 : 1; ?>">
                                        <button class="btn btn-xs <?php echo (int) $mapping->enabled ? 'btn-warning' : 'btn-success'; ?>" type="submit"><?php echo (int) $mapping->enabled ? 'غیرفعال‌کردن' : 'فعال‌کردن'; ?></button>
                                    </form>
                                    <form method="post" action="<?php echo vmcontrol_admin_h($moduleLink); ?>" class="vmc-delete-form" style="display:inline">
                                        <input type="hidden" name="csrf" value="<?php echo vmcontrol_admin_h(vmcontrol_csrf_token()); ?>"><input type="hidden" name="vmc_action" value="delete"><input type="hidden" name="mapping_id" value="<?php echo (int) $mapping->id; ?>">
                                        <button class="btn btn-xs btn-danger" type="submit"><i class="fas fa-unlink"></i> حذف اتصال</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!count($mappings)): ?><tr><td colspan="7" class="vmc-empty">هنوز هیچ نگاشتی ثبت نشده است.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="vmc-card">
            <div class="vmc-card-head"><h3>آخرین رویدادهای VMControl</h3><p>رویدادهای مشتری و تغییرات مدیریتی بدون نمایش اطلاعات ورود یا Console Ticket</p></div>
            <div class="vmc-table-wrap">
                <table class="table table-striped vmc-table">
                    <thead><tr><th>زمان</th><th>سرویس</th><th>مشتری</th><th>رویداد</th><th>نتیجه</th><th>IP درخواست</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentEvents as $event): ?>
                        <tr>
                            <td class="vmc-ltr"><?php echo vmcontrol_admin_h($event->created_at); ?></td>
                            <td>#<?php echo (int) $event->service_id; ?></td>
                            <td><?php echo vmcontrol_admin_h(trim($event->firstname . ' ' . $event->lastname)); ?></td>
                            <td><?php echo vmcontrol_admin_h($event->message ?: $event->event_type); ?></td>
                            <td><?php echo $event->result === 'success' ? '<span class="vmc-badge vmc-badge-success">موفق</span>' : '<span class="vmc-badge vmc-badge-warning">ناموفق</span>'; ?></td>
                            <td class="vmc-ltr"><?php echo vmcontrol_admin_h($event->source_ip ?: '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!count($recentEvents)): ?><tr><td colspan="6" class="vmc-empty">هنوز رویدادی ثبت نشده است.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <details class="vmc-card">
            <summary class="vmc-card-head"><strong>ثبت گروهی از CSV</strong></summary>
            <div class="vmc-import">
                <p>ستون‌ها: <code>service_id,vcenter_id,instance_uuid,enabled</code>. قبل از ثبت، تمام ردیف‌ها بررسی می‌شوند؛ وجود یک خطا باعث لغو کل فایل می‌شود.</p>
                <form method="post" enctype="multipart/form-data" action="<?php echo vmcontrol_admin_h($moduleLink); ?>" class="vmc-import-grid">
                    <input type="hidden" name="csrf" value="<?php echo vmcontrol_admin_h(vmcontrol_csrf_token()); ?>"><input type="hidden" name="vmc_action" value="import">
                    <div><label>فایل CSV حداکثر ۲ مگابایت</label><input class="form-control" type="file" name="mapping_csv" accept=".csv,text/csv" required></div>
                    <button class="btn btn-primary" type="submit"><i class="fas fa-file-import"></i> بررسی و ثبت گروهی</button>
                </form>
            </div>
        </details>

        <script>
        (function(){
            function filterOptions(inputId, selectId){
                var input=document.getElementById(inputId), select=document.getElementById(selectId);
                if(!input||!select){return;}
                input.addEventListener('input',function(){
                    var q=this.value.toLowerCase().trim();
                    Array.prototype.forEach.call(select.options,function(option){
                        option.hidden=q && (option.getAttribute('data-search')||option.text.toLowerCase()).indexOf(q)===-1;
                    });
                });
            }
            function choose(select,value){
                var found=false;
                Array.prototype.forEach.call(select.options,function(option){option.selected=option.value===value;if(option.selected){found=true;}});
                return found;
            }
            function resetForm(){
                document.getElementById('vmcMappingForm').reset();
                document.getElementById('vmcMappingId').value='0';
                document.getElementById('vmcFormTitle').textContent='واگذاری سریع ماشین';
                document.getElementById('vmcSaveButton').innerHTML='<i class="fas fa-link"></i> ثبت اتصال';
                document.getElementById('vmcCancelEdit').classList.add('vmc-hidden');
            }
            filterOptions('vmcServiceSearch','vmcService');filterOptions('vmcInventorySearch','vmcInventory');
            var tableSearch=document.getElementById('vmcMappingSearch');
            if(tableSearch){tableSearch.addEventListener('input',function(){var q=this.value.toLowerCase().trim();Array.prototype.forEach.call(document.querySelectorAll('#vmcMappingTable tbody tr[data-search]'),function(row){row.style.display=!q||row.getAttribute('data-search').indexOf(q)!==-1?'':'none';});});}
            Array.prototype.forEach.call(document.querySelectorAll('.vmc-edit'),function(button){button.addEventListener('click',function(){
                var service=document.getElementById('vmcService'), inventory=document.getElementById('vmcInventory');
                document.getElementById('vmcMappingId').value=this.getAttribute('data-id');choose(service,this.getAttribute('data-service'));
                var target=this.getAttribute('data-vcenter')+'|'+this.getAttribute('data-uuid');
                if(!choose(inventory,target)){document.getElementById('vmcManualVCenter').value=this.getAttribute('data-vcenter');document.getElementById('vmcManualUuid').value=this.getAttribute('data-uuid');inventory.selectedIndex=-1;}
                document.getElementById('vmcEnabled').checked=this.getAttribute('data-enabled')==='1';document.getElementById('vmcFormTitle').textContent='ویرایش یا انتقال نگاشت';document.getElementById('vmcSaveButton').innerHTML='<i class="fas fa-save"></i> ذخیره تغییرات';document.getElementById('vmcCancelEdit').classList.remove('vmc-hidden');document.getElementById('vmcMappingForm').scrollIntoView({behavior:'smooth',block:'start'});
            });});
            document.getElementById('vmcCancelEdit').addEventListener('click',resetForm);
            Array.prototype.forEach.call(document.querySelectorAll('.vmc-delete-form'),function(form){form.addEventListener('submit',function(event){if(!window.confirm('فقط اتصال VMControl حذف می‌شود. خود ماشین و سرویس WHMCS حذف نمی‌شوند. ادامه می‌دهید؟')){event.preventDefault();}});});
            Array.prototype.forEach.call(document.querySelectorAll('.vmc-suggestion-form'),function(form){form.addEventListener('submit',function(event){if(!window.confirm('این پیشنهاد فقط براساس تطبیق IP ساخته شده است. اتصال پس از تأیید شما ثبت شود؟')){event.preventDefault();}});});
        })();
        </script>
    </div>
    <?php
    return ob_get_clean();
}
