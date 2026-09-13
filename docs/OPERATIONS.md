# Operations, upgrades, and rollback

## Routine checks

```bash
cd /opt/vmcontrol
curl -fsS https://console.example.com/healthz
sudo docker compose ps
sudo docker compose logs --tail=100 gateway caddy
```

Review `/opt/vmcontrol/data/audit.jsonl` with access restricted to system
administrators. Monitor disk use and rotate or archive the JSONL log according
to your retention policy. Do not publish it in support issues.

WHMCS cron performs power-state monitoring at most once every four minutes. It
uses one inventory request per configured vCenter and sends state-change email
only after a prior baseline exists. Notification options are controlled in the
addon settings.

## Backups

Back up before every upgrade:

- `/opt/vmcontrol/.env`
- `/opt/vmcontrol/secrets/vcenters.json`
- `/opt/vmcontrol/Caddyfile`
- `/opt/vmcontrol/data/`
- `/etc/whmcs-vmcontrol.php`
- `modules/addons/vmcontrol/` under the WHMCS document root
- WHMCS database tables `mod_vmcontrol_services` and `mod_vmcontrol_events`

Treat every backup as sensitive because it can contain credentials, customer
mappings, addresses, and audit history.

## Upgrade order

1. Read the release notes and verify the downloaded artifact checksum.
2. Back up the files and database listed above.
3. Stage the release in a new directory and syntax-check its PHP files.
4. Build the new Gateway image without stopping the running container.
5. Deploy the Gateway and verify `/healthz` and logs.
6. Copy the new WHMCS addon files while preserving `/etc/whmcs-vmcontrol.php`.
7. Open **Addons -> VMControl** to run idempotent schema updates.
8. Test one Active and one Suspended service before general use.

Do not recreate a healthy running Gateway when a new image fails to build.

## Rollback

For a Gateway rollback, restore the previous source or Compose file, or reference
the previous image tag, then recreate only the `gateway` service. Running VMs
are not affected.

For a WHMCS rollback, disable the addon first if necessary, restore the prior
module directory, and leave the VMControl tables intact. No WHMCS core file,
theme, product, gateway, or service record needs restoration because VMControl
does not modify them.

## Credential and certificate rotation

- Rotate HMAC credentials in Gateway `.env` and `/etc/whmcs-vmcontrol.php`
  together during a controlled maintenance window.
- Rotate the vCenter password only in `secrets/vcenters.json`, then recreate the
  Gateway container and verify inventory before customer testing.
- When the vCenter TLS certificate changes, independently verify its new SHA-256
  fingerprint before updating the pin.
- Caddy-managed public certificates renew automatically when DNS and inbound
  validation traffic remain available.

## Troubleshooting order

1. Confirm synchronized time and DNS on WHMCS and Gateway.
2. Check Gateway health and both container logs.
3. Distinguish Caddy HTTP 403 (source IP restriction) from Gateway HTTP 401
   (HMAC, key, timestamp, or nonce authentication).
4. Confirm `.env` and vCenter JSON are readable by the expected container UID.
5. Verify the VM is inside an allowed inventory prefix.
6. Verify WebMKS assets exist and the vCenter account can open a console.
7. Confirm the ESXi address is inside `CONSOLE_ALLOWED_CIDRS` and reachable on
   TCP 443 from the Gateway.
