<?php

require_once dirname(__DIR__, 3) . '/init.php';
require_once __DIR__ . '/lib/functions.php';

header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
$userId = vmcontrol_current_client_id();
$serviceId = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
if (!$userId || !vmcontrol_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
    http_response_code(403);
    exit('Invalid session.');
}
$service = vmcontrol_user_active_service($userId, $serviceId);
if (!$service) {
    http_response_code(403);
    exit('Service is not active or is not available for this account.');
}
try {
    $result = vmcontrol_gateway()->console(
        $service->vcenter_id,
        $service->instance_uuid,
        $service->service_id,
        $userId,
        $service->customer_name
    );
    $gatewayBase = rtrim(vmcontrol_external_config()['base_url'], '/');
    if (empty($result['launch_url'])
        || strpos($result['launch_url'], $gatewayBase . '/console/launch/') !== 0
    ) {
        throw new RuntimeException('Gateway did not return a valid launch URL.');
    }
    vmcontrol_record_event($service->service_id, $userId, 'console', 'success', 'کنسول تحت وب باز شد.');
    header('Location: ' . $result['launch_url'], true, 303);
    exit;
} catch (Exception $e) {
    vmcontrol_log_failure('console', $e);
    vmcontrol_record_event($serviceId, $userId, 'console', 'failed', 'باز کردن کنسول ناموفق بود.');
    http_response_code(502);
    exit('Console is temporarily unavailable. Return to your service page and try again.');
}
