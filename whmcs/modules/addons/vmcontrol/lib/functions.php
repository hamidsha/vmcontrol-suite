<?php

use WHMCS\Database\Capsule;

require_once __DIR__ . '/GatewayClient.php';

function vmcontrol_ensure_schema()
{
    if (!Capsule::schema()->hasTable('mod_vmcontrol_services')) {
        return;
    }
    if (!Capsule::schema()->hasColumn('mod_vmcontrol_services', 'display_name')) {
        Capsule::schema()->table('mod_vmcontrol_services', function ($table) {
            $table->string('display_name', 100)->nullable()->after('instance_uuid');
        });
    }
    if (!Capsule::schema()->hasColumn('mod_vmcontrol_services', 'last_power_state')) {
        Capsule::schema()->table('mod_vmcontrol_services', function ($table) {
            $table->string('last_power_state', 32)->nullable()->after('display_name');
        });
    }
    if (!Capsule::schema()->hasColumn('mod_vmcontrol_services', 'power_state_updated_at')) {
        Capsule::schema()->table('mod_vmcontrol_services', function ($table) {
            $table->dateTime('power_state_updated_at')->nullable()->after('last_power_state');
        });
    }
    if (!Capsule::schema()->hasTable('mod_vmcontrol_events')) {
        Capsule::schema()->create('mod_vmcontrol_events', function ($table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('service_id')->index();
            $table->unsignedInteger('user_id')->index();
            $table->string('event_type', 40);
            $table->string('result', 16);
            $table->string('source_ip', 45)->nullable();
            $table->string('message', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(array('service_id', 'created_at'), 'mod_vmcontrol_event_service_time');
        });
    }
}

function vmcontrol_external_config()
{
    $path = getenv('VMCONTROL_WHMCS_CONFIG');
    if (!$path) {
        $path = '/etc/whmcs-vmcontrol.php';
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('VMControl configuration is not readable: ' . $path);
    }
    $config = require $path;
    if (!is_array($config)
        || empty($config['base_url'])
        || strpos($config['base_url'], 'https://') !== 0
        || empty($config['api_key'])
        || empty($config['api_secret'])
        || strlen($config['api_secret']) < 32
    ) {
        throw new RuntimeException('VMControl configuration is incomplete or invalid.');
    }
    return $config;
}

function vmcontrol_gateway()
{
    return new \VMControl\GatewayClient(vmcontrol_external_config());
}

function vmcontrol_current_client_id()
{
    $currentUser = new \WHMCS\Authentication\CurrentUser();
    $client = $currentUser->client();
    return $client ? (int) $client->id : 0;
}

function vmcontrol_csrf_token()
{
    if (empty($_SESSION['vmcontrol_csrf'])) {
        $_SESSION['vmcontrol_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['vmcontrol_csrf'];
}

function vmcontrol_verify_csrf($token)
{
    return is_string($token)
        && !empty($_SESSION['vmcontrol_csrf'])
        && hash_equals($_SESSION['vmcontrol_csrf'], $token);
}

function vmcontrol_user_services($userId)
{
    $services = Capsule::table('mod_vmcontrol_services as vmc')
        ->join('tblhosting as h', 'h.id', '=', 'vmc.service_id')
        ->join('tblproducts as p', 'p.id', '=', 'h.packageid')
        ->where('h.userid', (int) $userId)
        ->whereIn('h.domainstatus', array('Active', 'Suspended', 'Pending'))
        ->where('vmc.enabled', 1)
        ->select(
            'vmc.service_id',
            'vmc.vcenter_id',
            'vmc.instance_uuid',
            'vmc.display_name',
            'h.domain',
            'h.dedicatedip',
            'h.domainstatus',
            'p.name as product_name'
        )
        ->orderBy('vmc.service_id')
        ->get();

    foreach ($services as $service) {
        $displayName = trim((string) $service->display_name);
        $service->customer_name = $displayName !== ''
            ? $displayName
            : 'سرور مجازی #' . (int) $service->service_id;
    }
    return $services;
}

function vmcontrol_user_service($userId, $serviceId)
{
    foreach (vmcontrol_user_services($userId) as $service) {
        if ((int) $service->service_id === (int) $serviceId) {
            return $service;
        }
    }
    return null;
}

function vmcontrol_user_active_service($userId, $serviceId)
{
    $service = vmcontrol_user_service($userId, $serviceId);
    if (!$service || $service->domainstatus !== 'Active') {
        return null;
    }
    return $service;
}

function vmcontrol_normalize_display_name($value)
{
    $value = trim((string) $value);
    $value = preg_replace('/\s+/u', ' ', $value);
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length < 2 || $length > 60) {
        throw new InvalidArgumentException('نام سرور باید بین ۲ تا ۶۰ کاراکتر باشد.');
    }
    if (preg_match('/[\x00-\x1F\x7F<>]/u', $value)) {
        throw new InvalidArgumentException('نام سرور دارای کاراکتر غیرمجاز است.');
    }
    return $value;
}

function vmcontrol_update_display_name($userId, $serviceId, $displayName)
{
    $service = vmcontrol_user_active_service($userId, $serviceId);
    if (!$service) {
        throw new RuntimeException('سرویس فعال متعلق به این حساب پیدا نشد.');
    }
    Capsule::table('mod_vmcontrol_services')
        ->where('service_id', (int) $serviceId)
        ->where('enabled', 1)
        ->update(array(
            'display_name' => $displayName,
            'updated_at' => date('Y-m-d H:i:s'),
        ));
}

function vmcontrol_record_event($serviceId, $userId, $eventType, $result, $message)
{
    try {
        vmcontrol_ensure_schema();
        $sourceIp = isset($_SERVER['REMOTE_ADDR']) ? substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
        Capsule::table('mod_vmcontrol_events')->insert(array(
            'service_id' => (int) $serviceId,
            'user_id' => (int) $userId,
            'event_type' => substr((string) $eventType, 0, 40),
            'result' => substr((string) $result, 0, 16),
            'source_ip' => $sourceIp,
            'message' => substr((string) $message, 0, 255),
            'created_at' => date('Y-m-d H:i:s'),
        ));
    } catch (Exception $e) {
        vmcontrol_log_failure('audit event', $e);
    }
}

function vmcontrol_client_events($userId, $serviceId, $limit)
{
    return Capsule::table('mod_vmcontrol_events as event')
        ->join('tblhosting as h', 'h.id', '=', 'event.service_id')
        ->where('h.userid', (int) $userId)
        ->where('event.service_id', (int) $serviceId)
        ->select('event.event_type', 'event.result', 'event.source_ip', 'event.message', 'event.created_at')
        ->orderBy('event.id', 'desc')
        ->limit(max(1, min(50, (int) $limit)))
        ->get();
}

function vmcontrol_notify_client($userId, $subject, $message)
{
    if (!function_exists('localAPI')) {
        return false;
    }
    try {
        $result = localAPI('SendEmail', array(
            'customtype' => 'general',
            'customsubject' => (string) $subject,
            'custommessage' => (string) $message,
            'id' => (int) $userId,
        ));
        return isset($result['result']) && $result['result'] === 'success';
    } catch (Exception $e) {
        vmcontrol_log_failure('notification', $e);
        return false;
    }
}

function vmcontrol_module_setting($name, $fallback)
{
    $value = Capsule::table('tbladdonmodules')
        ->where('module', 'vmcontrol')
        ->where('setting', (string) $name)
        ->value('value');
    return $value === null ? $fallback : $value;
}

function vmcontrol_track_power_state($mapping, $newState, $sendNotification)
{
    $newState = substr(trim((string) $newState), 0, 32);
    if ($newState === '') {
        return;
    }
    $previous = trim((string) $mapping->last_power_state);
    Capsule::table('mod_vmcontrol_services')
        ->where('id', (int) $mapping->id)
        ->update(array(
            'last_power_state' => $newState,
            'power_state_updated_at' => date('Y-m-d H:i:s'),
        ));
    if ($previous === '' || $previous === $newState) {
        return;
    }
    $stateLabels = array(
        'poweredOn' => 'روشن',
        'poweredOff' => 'خاموش',
        'suspended' => 'تعلیق',
    );
    $stateLabel = isset($stateLabels[$newState]) ? $stateLabels[$newState] : 'نامشخص';
    $message = 'وضعیت سرور به «' . $stateLabel . '» تغییر کرد.';
    vmcontrol_record_event($mapping->service_id, $mapping->user_id, 'power_state', 'success', $message);
    if ($sendNotification) {
        $displayName = trim((string) $mapping->display_name);
        if ($displayName === '') {
            $displayName = 'سرور مجازی #' . (int) $mapping->service_id;
        }
        vmcontrol_notify_client(
            $mapping->user_id,
            'تغییر وضعیت سرور مجازی',
            $message . ' نام سرور: «' . $displayName . '»'
        );
    }
}

function vmcontrol_metric_polyline(array $values, $width, $height, $fixedMaximum = null)
{
    $clean = array();
    foreach ($values as $value) {
        if (is_numeric($value) && (float) $value >= 0) {
            $clean[] = (float) $value;
        }
    }
    if (count($clean) < 2) {
        return '';
    }
    $maximum = $fixedMaximum !== null ? (float) $fixedMaximum : max($clean);
    if ($maximum <= 0) {
        $maximum = 1;
    }
    $points = array();
    $lastIndex = count($clean) - 1;
    foreach ($clean as $index => $value) {
        $x = ($index / $lastIndex) * (float) $width;
        $y = (float) $height - (($value / $maximum) * ((float) $height - 4)) - 2;
        $points[] = number_format($x, 1, '.', '') . ',' . number_format($y, 1, '.', '');
    }
    return implode(' ', $points);
}

function vmcontrol_prepare_metrics(array $metrics)
{
    $series = isset($metrics['series']) && is_array($metrics['series']) ? $metrics['series'] : array();
    $names = array(
        'cpu' => 'cpu.usage.average',
        'memory' => 'mem.usage.average',
        'network_rx' => 'net.received.average',
        'network_tx' => 'net.transmitted.average',
        'disk_read' => 'disk.read.average',
        'disk_write' => 'disk.write.average',
    );
    $result = array('range' => isset($metrics['range']) ? $metrics['range'] : 'day');
    $hasData = false;
    foreach ($names as $key => $name) {
        $values = isset($series[$name]) && is_array($series[$name]) ? $series[$name] : array();
        $fixedMaximum = in_array($key, array('cpu', 'memory'), true) ? 100 : null;
        $result[$key . '_points'] = vmcontrol_metric_polyline($values, 300, 90, $fixedMaximum);
        $valid = array_values(array_filter($values, function ($value) {
            return is_numeric($value) && (float) $value >= 0;
        }));
        if ($valid) {
            $hasData = true;
        }
        $result[$key . '_latest'] = $valid ? number_format((float) end($valid), 1, '.', '') : null;
    }
    return $hasData ? $result : array();
}

function vmcontrol_log_failure($context, $exception)
{
    if (function_exists('logActivity')) {
        logActivity('VMControl ' . $context . ' failed: ' . $exception->getMessage());
    }
}

function vmcontrol_human_bytes($bytes)
{
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return '0 GB';
    }
    return number_format($bytes / 1073741824, 1) . ' GB';
}
