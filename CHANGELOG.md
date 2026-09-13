# Changelog

## 0.5.0 - 2026-09-12

- Add administrator-only, one-to-one IP mapping suggestions that require explicit confirmation.
- Flag mapped services whose WHMCS IP differs from the VM-reported IP and suppress ambiguous suggestions.
- Add customer and administrator activity history plus optional action and power-state email notifications.
- Monitor real power-state transitions efficiently from one inventory request per vCenter during WHMCS cron.
- Add vCenter-backed day, week, and month CPU, memory, network, and disk performance charts.
- Remove the VM UUID from console HTML and browser request paths by using the customer display name.
- Add cookie-bound, same-origin console controls for power on, safe shutdown, reboot, CAD, and fullscreen.
- Expose sanitized console-action history to WHMCS without returning UUIDs or console tickets.

## 0.4.0 - 2026-09-12

- Add a customer-controlled display name for each mapped VPS without renaming or exposing the vCenter VM name.
- Restrict display-name changes to the owning customer while the WHMCS service and mapping are active.
- Clear the previous customer's display name when an administrator transfers a mapping to another service.
- Add an automatic, backward-compatible database migration for existing installations.

## 0.3.2 - 2026-09-12

- Prevent inventory and status requests from crashing when vCenter omits the optional VM guest information object.
- Return an empty guest IP and tools status for affected VMs while preserving all other inventory fields.

## 0.3.1 - 2026-09-12

- Resolve scoped VM inventory paths concurrently to avoid long administrator requests.
- Add inventory duration and result-count audit events without logging VM details.

## 0.3.0 - 2026-09-12

- Add HMAC-protected vCenter and scoped VM inventory endpoints for administrators.
- Replace manual-only mapping entry with searchable WHMCS service and vCenter VM selectors.
- Add safe mapping edit, transfer, enable/disable, and unlink operations.
- Add atomic CSV bulk mapping import with validation and conflict checks.
- Keep VM deletion and WHMCS service mutation outside the addon's scope.
- Preserve customer-side Active-service authorization for status, actions, and console access.

## 0.1.0-rc1 - 2026-09-09

- Add isolated Dockerized vCenter Gateway.
- Add HMAC request authentication and replay protection.
- Add VM status and safe power actions.
- Add one-time WebMKS browser-console sessions and WebSocket proxy.
- Add PHP 7.2-compatible WHMCS addon with ownership and Active-service checks.
- Add a scoped client navigation item and RTL service-management UI.
- Add deployment, rollback, CI, and public-repository safety files.
