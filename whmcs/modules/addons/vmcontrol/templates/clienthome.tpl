{literal}
<style>
.vmc-shell{direction:rtl;text-align:right;color:#19324d;margin:8px 0 28px}.vmc-head{display:flex;justify-content:space-between;align-items:flex-end;gap:18px;margin-bottom:20px}.vmc-head h2{margin:0 0 6px;font-size:25px;font-weight:700;color:#0b5681}.vmc-subtitle{color:#6f7d8c;font-size:14px}.vmc-panel{overflow:hidden;background:#fff;border:1px solid #dce4eb;border-radius:8px}.vmc-table{width:100%;margin:0;border-collapse:collapse}.vmc-table th{padding:13px 18px;background:#eef3f7;color:#284762;font-size:13px;font-weight:700;border-bottom:1px solid #dce4eb;text-align:right}.vmc-table td{padding:14px 18px;border-bottom:1px solid #e6ebf0;vertical-align:middle}.vmc-table tr.vmc-selected>td{background:#edf7ff}.vmc-table tr:last-child td{border-bottom:0}.vmc-server-name{display:block;color:#143653;font-weight:700;line-height:1.6}.vmc-service-id{display:block;color:#81909e;font-size:12px}.vmc-ip{direction:ltr;display:inline-block;color:#24435f}.vmc-status{display:inline-flex;align-items:center;gap:7px;font-weight:700;white-space:nowrap}.vmc-status:before{content:"";width:10px;height:10px;border-radius:50%;background:#9aa5af}.vmc-status.active{color:#16843b}.vmc-status.active:before{background:#20a447}.vmc-status.suspended{color:#ae6a00}.vmc-status.suspended:before{background:#d68a13}.vmc-status.pending{color:#496579}.vmc-status.pending:before{background:#7d91a0}.vmc-manage{display:inline-flex;align-items:center;gap:7px;padding:8px 14px;border:1px solid #1688d4;border-radius:5px;background:#fff;color:#0876bd;font-weight:700;text-decoration:none}.vmc-manage:hover,.vmc-manage:focus{background:#087fc9;color:#fff;text-decoration:none}.vmc-locked-link{display:inline-flex;align-items:center;gap:7px;color:#1677b8;font-weight:700;text-decoration:none}.vmc-detail-row td{padding:0!important;background:#fff!important}.vmc-detail{display:grid;grid-template-columns:minmax(0,1fr) 300px;border-bottom:1px solid #e6ebf0}.vmc-detail-main{padding:26px}.vmc-detail-side{padding:26px;border-right:1px solid #e2e8ee}.vmc-detail-title{display:flex;align-items:center;gap:10px;margin-bottom:14px}.vmc-detail-title h3{margin:0;color:#173650;font-size:20px}.vmc-rename{display:flex;align-items:center;gap:8px;margin:0 0 18px;max-width:520px}.vmc-rename input{height:38px;flex:1;min-width:0;border:1px solid #ccd6df;border-radius:5px;padding:7px 11px}.vmc-rename button{width:auto;white-space:nowrap;padding:8px 14px}.vmc-specs{display:grid;grid-template-columns:repeat(2,minmax(180px,1fr));gap:0 34px}.vmc-spec{display:flex;justify-content:space-between;gap:15px;padding:12px 0;border-bottom:1px solid #edf0f3}.vmc-spec-label{color:#718090}.vmc-spec-value{font-weight:700;color:#213d57;direction:ltr;text-align:left}.vmc-tools{margin-top:18px;padding:13px 15px;background:#f5faf6;border-right:3px solid #28a745;color:#317043}.vmc-actions{display:flex;flex-direction:column;gap:10px}.vmc-actions form{margin:0}.vmc-btn{display:block;width:100%;padding:11px 14px;border:1px solid #ccd6df;border-radius:5px;background:#fff;color:#24435e;font-weight:700;text-align:center;cursor:pointer}.vmc-btn:hover,.vmc-btn:focus{background:#f3f6f8}.vmc-btn.primary{border-color:#087fc9;background:#087fc9;color:#fff}.vmc-btn.primary:hover,.vmc-btn.primary:focus{background:#056da9}.vmc-btn.danger{border-color:#d9a6a6;color:#b23a3a}.vmc-help{margin-top:13px;color:#7a8793;font-size:12px;line-height:1.8}.vmc-lockbox{margin:0 18px 18px;padding:13px 16px;border:1px solid #efd89b;border-radius:5px;background:#fff8e5;color:#805c12}.vmc-empty{padding:48px 24px;text-align:center;color:#6f7d8c}.vmc-alert{margin-bottom:16px}.vmc-mobile-label{display:none}@media(max-width:900px){.vmc-detail{grid-template-columns:1fr}.vmc-detail-side{border-right:0;border-top:1px solid #e2e8ee}.vmc-specs{grid-template-columns:1fr}.vmc-table thead{display:none}.vmc-table,.vmc-table tbody,.vmc-table tr,.vmc-table td{display:block;width:100%}.vmc-table tr{padding:12px 15px;border-bottom:1px solid #dfe6ec}.vmc-table td{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border:0}.vmc-table tr.vmc-detail-row{padding:0}.vmc-mobile-label{display:inline;color:#788694;font-size:12px}.vmc-detail-row td{display:block}.vmc-head{display:block}.vmc-head .vmc-subtitle{margin-top:5px}.vmc-rename{align-items:stretch;flex-direction:column}.vmc-rename button{width:100%}}
.vmc-metrics{margin-top:24px}.vmc-section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px}.vmc-section-head h4{margin:0;color:#173650;font-size:16px}.vmc-range{display:flex;gap:5px}.vmc-range a{padding:5px 9px;border:1px solid #d5dee6;border-radius:4px;color:#41647f;text-decoration:none;font-size:11px}.vmc-range a.active{background:#0b7fbe;border-color:#0b7fbe;color:#fff}.vmc-chart-grid{display:grid;grid-template-columns:repeat(2,minmax(220px,1fr));gap:12px}.vmc-chart{padding:13px;border:1px solid #e0e7ed;border-radius:6px;background:#fbfdfe}.vmc-chart-title{display:flex;justify-content:space-between;gap:10px;color:#526b7f;font-size:12px}.vmc-chart-title strong{direction:ltr;color:#143653}.vmc-chart svg{display:block;width:100%;height:90px;margin-top:8px;background:linear-gradient(to bottom,transparent 49%,#edf2f5 50%,transparent 51%)}.vmc-chart polyline{fill:none;stroke:#0b86c7;stroke-width:2}.vmc-chart polyline.alt{stroke:#e58b2a}.vmc-history{margin-top:18px}.vmc-history-list{margin:0;padding:0;list-style:none}.vmc-history-list li{display:grid;grid-template-columns:145px 1fr auto;gap:12px;padding:11px 15px;border-bottom:1px solid #edf1f4;align-items:center}.vmc-history-list li:last-child{border-bottom:0}.vmc-history-time{direction:ltr;color:#7a8997;font-size:11px}.vmc-history-result{font-size:11px;font-weight:700}.vmc-history-result.success{color:#16843b}.vmc-history-result.failed{color:#b23a3a}@media(max-width:900px){.vmc-chart-grid{grid-template-columns:1fr}.vmc-section-head{align-items:flex-start;flex-direction:column}.vmc-history-list li{grid-template-columns:1fr;gap:4px}}
</style>
{/literal}

<div class="vmc-shell">
  <div class="vmc-head">
    <div>
      <h2>سرورهای من</h2>
      <div class="vmc-subtitle">سرورهای مجازی خود را مشاهده و مدیریت کنید.</div>
    </div>
  </div>

  {if $notice}<div class="alert alert-success vmc-alert">{$notice|escape}</div>{/if}
  {if $error}<div class="alert alert-danger vmc-alert">{$error|escape}</div>{/if}

  {if !$selected}
    <div class="vmc-panel vmc-empty">هیچ سرور قابل نمایشی به حساب شما متصل نشده است.</div>
  {else}
    <div class="vmc-panel">
      <table class="vmc-table">
        <thead><tr><th>نام سرور</th><th>IP سرور</th><th>وضعیت سرویس</th><th>مدیریت</th></tr></thead>
        <tbody>
          {foreach $services as $service}
            <tr{if $selected->service_id eq $service->service_id} class="vmc-selected"{/if}>
              <td><span class="vmc-mobile-label">نام سرور</span><span><span class="vmc-server-name">{$service->customer_name|escape}</span><span class="vmc-service-id">سرویس #{$service->service_id|escape}</span></span></td>
              <td><span class="vmc-mobile-label">IP سرور</span><span class="vmc-ip">{$service->dedicatedip|default:'ثبت نشده'|escape}</span></td>
              <td><span class="vmc-mobile-label">وضعیت</span>{if $service->domainstatus eq 'Active'}<span class="vmc-status active">فعال</span>{elseif $service->domainstatus eq 'Suspended'}<span class="vmc-status suspended">تعلیق‌شده</span>{else}<span class="vmc-status pending">در انتظار راه‌اندازی</span>{/if}</td>
              <td><span class="vmc-mobile-label">عملیات</span>{if $service->domainstatus eq 'Active'}<a class="vmc-manage" href="{$modulelink|escape}&amp;service_id={$service->service_id|escape}"><i class="fas fa-cog" aria-hidden="true"></i> مدیریت</a>{else}<a class="vmc-locked-link" href="clientarea.php?action=productdetails&amp;id={$service->service_id|escape}"><i class="fas fa-lock" aria-hidden="true"></i> مشاهده سرویس و پرداخت</a>{/if}</td>
            </tr>

            {if $selected->service_id eq $service->service_id}
              <tr class="vmc-detail-row"><td colspan="4">
                {if $selected->domainstatus eq 'Active'}
                  <div class="vmc-detail">
                    <div class="vmc-detail-main">
                      <div class="vmc-detail-title">
                        <h3>{$selected->customer_name|escape}</h3>
                        {if $vm && $vm.power_state eq 'poweredOn'}<span class="vmc-status active">روشن</span>{elseif $vm && $vm.power_state eq 'poweredOff'}<span class="vmc-status suspended">خاموش</span>{else}<span class="vmc-status pending">در حال دریافت وضعیت</span>{/if}
                      </div>
                      <form method="post" class="vmc-rename">
                        <input type="hidden" name="service_id" value="{$selected->service_id|escape}">
                        <input type="hidden" name="csrf" value="{$csrf|escape}">
                        <input type="text" name="display_name" value="{$selected->customer_name|escape}" minlength="2" maxlength="60" required aria-label="نام نمایشی سرور" placeholder="مثلاً سرور دیتابیس">
                        <button class="vmc-btn" name="action" value="rename" type="submit"><i class="fas fa-pen" aria-hidden="true"></i> ذخیره نام</button>
                      </form>
                      {if $vm}
                        <div class="vmc-specs">
                          <div class="vmc-spec"><span class="vmc-spec-label">پردازنده</span><span class="vmc-spec-value">{$vm.cpu|escape} vCPU</span></div>
                          <div class="vmc-spec"><span class="vmc-spec-label">حافظه</span><span class="vmc-spec-value">{$vm.memory_gb|escape} GB</span></div>
                          <div class="vmc-spec"><span class="vmc-spec-label">ظرفیت دیسک</span><span class="vmc-spec-value">{$vm.disk_human|escape}</span></div>
                          <div class="vmc-spec"><span class="vmc-spec-label">سیستم‌عامل</span><span class="vmc-spec-value">{$vm.guest_os|default:'نامشخص'|escape}</span></div>
                          <div class="vmc-spec"><span class="vmc-spec-label">IP ثبت‌شده در WHMCS</span><span class="vmc-spec-value">{$selected->dedicatedip|default:'ثبت نشده'|escape}</span></div>
                          <div class="vmc-spec"><span class="vmc-spec-label">IP گزارش‌شده توسط VM</span><span class="vmc-spec-value">{$vm.ip_address|default:'نامشخص'|escape}</span></div>
                        </div>
                        <div class="vmc-metrics">
                          <div class="vmc-section-head">
                            <h4>نمودار مصرف منابع</h4>
                            <div class="vmc-range">
                              <a class="{if $metrics_range eq 'day'}active{/if}" href="{$modulelink|escape}&amp;service_id={$selected->service_id|escape}&amp;metrics_range=day">۲۴ ساعت</a>
                              <a class="{if $metrics_range eq 'week'}active{/if}" href="{$modulelink|escape}&amp;service_id={$selected->service_id|escape}&amp;metrics_range=week">۷ روز</a>
                              <a class="{if $metrics_range eq 'month'}active{/if}" href="{$modulelink|escape}&amp;service_id={$selected->service_id|escape}&amp;metrics_range=month">۳۰ روز</a>
                            </div>
                          </div>
                          {if $metrics}
                            <div class="vmc-chart-grid">
                              <div class="vmc-chart"><div class="vmc-chart-title"><span>CPU</span><strong>{$metrics.cpu_latest|default:'—'|escape}%</strong></div><svg viewBox="0 0 300 90" preserveAspectRatio="none"><polyline points="{$metrics.cpu_points|escape}"></polyline></svg></div>
                              <div class="vmc-chart"><div class="vmc-chart-title"><span>RAM</span><strong>{$metrics.memory_latest|default:'—'|escape}%</strong></div><svg viewBox="0 0 300 90" preserveAspectRatio="none"><polyline points="{$metrics.memory_points|escape}"></polyline></svg></div>
                              <div class="vmc-chart"><div class="vmc-chart-title"><span>شبکه RX / TX</span><strong>{$metrics.network_rx_latest|default:'—'|escape} / {$metrics.network_tx_latest|default:'—'|escape} KB/s</strong></div><svg viewBox="0 0 300 90" preserveAspectRatio="none"><polyline points="{$metrics.network_rx_points|escape}"></polyline><polyline class="alt" points="{$metrics.network_tx_points|escape}"></polyline></svg></div>
                              <div class="vmc-chart"><div class="vmc-chart-title"><span>دیسک Read / Write</span><strong>{$metrics.disk_read_latest|default:'—'|escape} / {$metrics.disk_write_latest|default:'—'|escape} KB/s</strong></div><svg viewBox="0 0 300 90" preserveAspectRatio="none"><polyline points="{$metrics.disk_read_points|escape}"></polyline><polyline class="alt" points="{$metrics.disk_write_points|escape}"></polyline></svg></div>
                            </div>
                          {else}
                            <div class="vmc-help">داده Performance در حال حاضر از vCenter دریافت نشد؛ سایر امکانات سرور بدون اختلال قابل استفاده است.</div>
                          {/if}
                        </div>
                      {/if}
                    </div>
                    <div class="vmc-detail-side">
                      <div class="vmc-actions">
                        {if $vm && $vm.power_state eq 'poweredOff'}
                          <form method="post"><input type="hidden" name="service_id" value="{$selected->service_id|escape}"><input type="hidden" name="csrf" value="{$csrf|escape}"><button class="vmc-btn primary" name="action" value="power_on"><i class="fas fa-power-off" aria-hidden="true"></i> روشن کردن سرور</button></form>
                        {elseif $vm}
                          <form method="post" target="_blank" action="{$console_endpoint|escape}"><input type="hidden" name="service_id" value="{$selected->service_id|escape}"><input type="hidden" name="csrf" value="{$csrf|escape}"><button class="vmc-btn primary" type="submit"><i class="fas fa-desktop" aria-hidden="true"></i> باز کردن کنسول</button></form>
                          {if $vm.tools_status eq 'guestToolsRunning'}
                            <form method="post" onsubmit="return confirm('سرور از داخل سیستم‌عامل راه‌اندازی مجدد شود؟')"><input type="hidden" name="service_id" value="{$selected->service_id|escape}"><input type="hidden" name="csrf" value="{$csrf|escape}"><button class="vmc-btn" name="action" value="reboot"><i class="fas fa-redo" aria-hidden="true"></i> راه‌اندازی مجدد</button></form>
                            <form method="post" onsubmit="return confirm('سرور به‌صورت امن خاموش شود؟')"><input type="hidden" name="service_id" value="{$selected->service_id|escape}"><input type="hidden" name="csrf" value="{$csrf|escape}"><button class="vmc-btn danger" name="action" value="shutdown"><i class="fas fa-power-off" aria-hidden="true"></i> خاموش کردن امن</button></form>
                          {else}<div class="vmc-help">ریبوت و خاموش‌کردن امن در حال حاضر برای این سرور در دسترس نیست.</div>{/if}
                        {/if}
                      </div>
                      <div class="vmc-help">پیش از عملیات، از اطلاعات مهم خود نسخه پشتیبان تهیه کنید.</div>
                    </div>
                  </div>
                {else}
                  <div class="vmc-lockbox"><i class="fas fa-lock" aria-hidden="true"></i> به‌دلیل وضعیت مالی یا آماده‌نبودن سرویس، عملیات و کنسول غیرفعال است.</div>
                {/if}
              </td></tr>
            {/if}
          {/foreach}
        </tbody>
      </table>
    </div>
    {if $events}
      <div class="vmc-panel vmc-history">
        <div class="vmc-section-head" style="padding:15px 15px 0"><h4>تاریخچه فعالیت این سرور</h4></div>
        <ul class="vmc-history-list">
          {foreach $events as $event}
            <li><span class="vmc-history-time">{$event->created_at|escape}</span><span>{$event->message|escape}</span><span class="vmc-history-result {$event->result|escape}">{if $event->result eq 'success'}موفق{else}ناموفق{/if}</span></li>
          {/foreach}
        </ul>
      </div>
    {/if}
  {/if}
</div>
