<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

use WHMCS\Database\Capsule;

require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/AdminPage.php';

function vmcontrol_config()
{
    return array(
        'name' => 'VMControl',
        'description' => 'Isolated vCenter VPS status, power controls, and browser console.',
        'version' => '0.5.0',
        'author' => 'hamidsha',
        'language' => 'english',
        'fields' => array(
            'notify_actions' => array(
                'FriendlyName' => 'Client action email notifications',
                'Type' => 'yesno',
                'Description' => 'Send an email after a customer power action is accepted.',
                'Default' => 'on',
            ),
            'notify_state_changes' => array(
                'FriendlyName' => 'Power state monitoring notifications',
                'Type' => 'yesno',
                'Description' => 'Use WHMCS cron to email clients after a real poweredOn/poweredOff transition.',
                'Default' => 'on',
            ),
        ),
    );
}

function vmcontrol_activate()
{
    try {
        if (!Capsule::schema()->hasTable('mod_vmcontrol_services')) {
            Capsule::schema()->create('mod_vmcontrol_services', function ($table) {
                $table->increments('id');
                $table->unsignedInteger('service_id')->unique();
                $table->string('vcenter_id', 40);
                $table->string('instance_uuid', 36);
                $table->string('display_name', 100)->nullable();
                $table->string('last_power_state', 32)->nullable();
                $table->dateTime('power_state_updated_at')->nullable();
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('admin_id')->nullable();
                $table->timestamps();
                $table->unique(array('vcenter_id', 'instance_uuid'), 'mod_vmcontrol_vcenter_uuid_unique');
            });
        }
        vmcontrol_ensure_schema();
        return array('status' => 'success', 'description' => 'VMControl table created. Existing WHMCS tables were not modified.');
    } catch (Exception $e) {
        return array('status' => 'error', 'description' => 'Activation failed: ' . $e->getMessage());
    }
}

function vmcontrol_upgrade($vars)
{
    try {
        vmcontrol_ensure_schema();
        return array('status' => 'success', 'description' => 'VMControl database schema upgraded.');
    } catch (Exception $e) {
        return array('status' => 'error', 'description' => 'Upgrade failed: ' . $e->getMessage());
    }
}

function vmcontrol_deactivate()
{
    return array(
        'status' => 'info',
        'description' => 'Module disabled. Mapping data was intentionally preserved for safe rollback.',
    );
}

function vmcontrol_output($vars)
{
    vmcontrol_ensure_schema();
    echo vmcontrol_admin_render($vars);
}

function vmcontrol_clientarea($vars)
{
    vmcontrol_ensure_schema();
    $userId = vmcontrol_current_client_id();
    $services = $userId ? vmcontrol_user_services($userId) : array();
    $serviceId = isset($_REQUEST['service_id']) ? (int) $_REQUEST['service_id'] : 0;
    if (!$serviceId) {
        foreach ($services as $candidate) {
            if ($candidate->domainstatus === 'Active') {
                $serviceId = (int) $candidate->service_id;
                break;
            }
        }
    }
    if (!$serviceId) {
        foreach ($services as $first) {
            $serviceId = (int) $first->service_id;
            break;
        }
    }
    $selected = $serviceId ? vmcontrol_user_service($userId, $serviceId) : null;
    $notice = '';
    $error = '';
    $vm = null;
    $metrics = null;
    $metricsRange = isset($_GET['metrics_range']) && in_array($_GET['metrics_range'], array('day', 'week', 'month'), true)
        ? $_GET['metrics_range']
        : 'day';

    if ($selected && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        if (!vmcontrol_verify_csrf(isset($_POST['csrf']) ? $_POST['csrf'] : '')) {
            $error = 'درخواست نامعتبر یا منقضی شده است.';
        } elseif ($selected->domainstatus !== 'Active') {
            $error = 'به دلیل غیرفعال بودن سرویس، انجام عملیات و استفاده از کنسول مجاز نیست.';
        } else {
            $action = (string) $_POST['action'];
            if ($action === 'rename') {
                try {
                    $displayName = vmcontrol_normalize_display_name(
                        isset($_POST['display_name']) ? $_POST['display_name'] : ''
                    );
                    vmcontrol_update_display_name($userId, $selected->service_id, $displayName);
                    vmcontrol_record_event($selected->service_id, $userId, 'display_name', 'success', 'نام نمایشی سرور تغییر کرد.');
                    $services = vmcontrol_user_services($userId);
                    $selected = vmcontrol_user_service($userId, $serviceId);
                    $notice = 'نام نمایشی سرور با موفقیت ذخیره شد.';
                } catch (Exception $e) {
                    $error = $e->getMessage();
                }
            } else {
                $allowed = array('power_on', 'shutdown', 'reboot');
                if (!in_array($action, $allowed, true)) {
                    $error = 'این عملیات مجاز نیست.';
                } else {
                try {
                    $authorized = vmcontrol_user_active_service($userId, $selected->service_id);
                    if (!$authorized) {
                        throw new RuntimeException('Service is no longer active.');
                    }
                    vmcontrol_gateway()->action($authorized->vcenter_id, $authorized->instance_uuid, $action, $authorized->service_id, $userId);
                    $notice = 'درخواست با موفقیت برای سرور ارسال شد.';
                    $actionLabels = array(
                        'power_on' => 'روشن کردن سرور',
                        'shutdown' => 'خاموش کردن امن سرور',
                        'reboot' => 'راه‌اندازی مجدد سرور',
                    );
                    $actionLabel = isset($actionLabels[$action]) ? $actionLabels[$action] : $action;
                    vmcontrol_record_event($authorized->service_id, $userId, $action, 'success', $actionLabel);
                    if (isset($vars['notify_actions']) && $vars['notify_actions'] === 'on') {
                        vmcontrol_notify_client(
                            $userId,
                            'عملیات سرور مجازی انجام شد',
                            $actionLabel . ' برای «' . $authorized->customer_name . '» با موفقیت در سامانه ثبت شد.'
                        );
                    }
                } catch (Exception $e) {
                    vmcontrol_log_failure('client action', $e);
                    vmcontrol_record_event($selected->service_id, $userId, $action, 'failed', 'اجرای عملیات ناموفق بود.');
                    $error = 'در حال حاضر اجرای عملیات ممکن نیست. لطفاً دوباره تلاش کنید.';
                }
                }
            }
        }
    }

    if ($selected && $selected->domainstatus === 'Active') {
        try {
            $vm = vmcontrol_gateway()->status($selected->vcenter_id, $selected->instance_uuid);
            $vm['memory_gb'] = number_format(((float) $vm['memory_mb']) / 1024, 1);
            $vm['disk_human'] = vmcontrol_human_bytes(
                isset($vm['disk_capacity_bytes']) ? $vm['disk_capacity_bytes'] : 0
            );
            try {
                $metrics = vmcontrol_prepare_metrics(
                    vmcontrol_gateway()->metrics($selected->vcenter_id, $selected->instance_uuid, $metricsRange)
                );
            } catch (Exception $metricsException) {
                vmcontrol_log_failure('metrics', $metricsException);
            }
        } catch (Exception $e) {
            vmcontrol_log_failure('status', $e);
            $error = $error ?: 'ارتباط با سامانه مدیریت سرور موقتاً برقرار نیست.';
        }
    }

    $events = array();
    if ($selected) {
        foreach (vmcontrol_client_events($userId, $selected->service_id, 15) as $event) {
            $events[] = $event;
        }
        try {
            $consoleEvents = vmcontrol_gateway()->consoleEvents($selected->service_id);
            $labels = array(
                'power_on' => 'روشن کردن سرور از صفحه کنسول',
                'shutdown' => 'خاموش کردن امن از صفحه کنسول',
                'reboot' => 'راه‌اندازی مجدد از صفحه کنسول',
            );
            foreach ($consoleEvents as $gatewayEvent) {
                $event = new stdClass();
                $action = isset($gatewayEvent['action']) ? $gatewayEvent['action'] : '';
                $event->event_type = $action;
                $event->result = !empty($gatewayEvent['success']) ? 'success' : 'failed';
                $event->source_ip = '';
                $event->message = isset($labels[$action]) ? $labels[$action] : 'عملیات از صفحه کنسول';
                $eventTimestamp = isset($gatewayEvent['time']) ? strtotime($gatewayEvent['time']) : false;
                $event->created_at = $eventTimestamp ? date('Y-m-d H:i:s', $eventTimestamp) : '';
                $events[] = $event;
            }
        } catch (Exception $gatewayEventsException) {
            vmcontrol_log_failure('console event history', $gatewayEventsException);
        }
        usort($events, function ($left, $right) {
            return strtotime($right->created_at) - strtotime($left->created_at);
        });
        $events = array_slice($events, 0, 15);
    }

    return array(
        'pagetitle' => 'مدیریت سرور مجازی',
        'breadcrumb' => array($vars['modulelink'] => 'مدیریت سرور مجازی'),
        'templatefile' => 'clienthome',
        'requirelogin' => true,
        'forcessl' => true,
        'vars' => array(
            'modulelink' => $vars['modulelink'],
            'services' => $services,
            'selected' => $selected,
            'vm' => $vm,
            'metrics' => $metrics,
            'metrics_range' => $metricsRange,
            'events' => $events,
            'notice' => $notice,
            'error' => $error,
            'csrf' => vmcontrol_csrf_token(),
            'console_endpoint' => 'modules/addons/vmcontrol/console.php',
        ),
    );
}
