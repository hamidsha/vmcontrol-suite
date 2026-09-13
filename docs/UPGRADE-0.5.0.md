# Upgrade to VMControl 0.5.0

Version 0.5.0 updates both the Gateway and WHMCS addon. Deploy the Gateway first;
the older WHMCS addon remains compatible while the new Gateway image is being
verified.

## Included changes

- Administrator-confirmed, one-to-one IP mapping suggestions with a fresh
  server-side inventory check before saving.
- IP mismatch flags on existing mappings.
- Customer and administrator activity history.
- Optional action and real power-state change email notifications.
- vCenter performance charts for day, week, and month ranges.
- Customer display names in the browser console without exposing VM UUIDs.
- Cookie-bound, same-origin safe power controls in the console.

## Database migration

The addon adds `last_power_state` and `power_state_updated_at` to
`mod_vmcontrol_services` and creates `mod_vmcontrol_events`. Existing mappings
are preserved. The first monitoring pass establishes a baseline and does not
send a state-change email.

## Compatibility and rollback

The release remains compatible with PHP 7.2 and WHMCS 8.13. The Gateway build
runs all Go tests before producing an image. If a build fails, do not recreate
the running Gateway container. Rolling back the module files does not require
dropping either VMControl table.
