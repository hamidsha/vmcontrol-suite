# Installation guide

This guide deploys the Gateway separately from WHMCS. Replace every
`example.com`, documentation IP, placeholder, path, and credential with values
for your environment.

## 1. Prepare the Gateway host

Recommended baseline:

- Ubuntu 24.04 LTS, 4 vCPU, 4 GB RAM, and 40 GB disk.
- Docker Engine and the Docker Compose plugin.
- A dedicated DNS name such as `console.example.com` pointing to the Gateway.
- Inbound TCP 443 from customers and the WHMCS egress address.
- Outbound TCP 443 to vCenter and every ESXi address returned by WebMKS tickets.
- Working DNS and synchronized UTC time on both Gateway and WHMCS.

Only the reverse proxy publishes a host port. The Gateway application remains
on the private Compose network.

## 2. Create a least-privilege vCenter account

Create a dedicated service account and apply its role only to the folder or
resource scope containing customer VMs. Grant read access plus only the
interaction privileges needed by the features you enable:

- View VM configuration, guest state, devices, networks, and performance data.
- Power on.
- Guest shutdown and guest reboot.
- Console interaction and acquisition of a WebMKS ticket.

Do not grant VM deletion, cloning, reconfiguration, datastore browsing, host
administration, or global administrator privileges. VMware privilege labels can
vary between releases; validate the final role by testing inventory, metrics,
each permitted action, and console access with the service account itself.

## 3. Install the repository

Place the repository at `/opt/vmcontrol`, then create runtime configuration:

```bash
cd /opt/vmcontrol
cp .env.example .env
cp secrets/vcenters.example.json secrets/vcenters.json
sudo install -d -o 10001 -g 10001 -m 0700 data
sudo chown 10001:10001 secrets/vcenters.json
sudo chmod 0400 secrets/vcenters.json
chmod 0600 .env
```

Generate independent random values for the HMAC key identifier and secret. For
example, run `openssl rand -hex 16` for `API_KEY` and `openssl rand -hex 32` for
`API_SECRET`. Store their matching values later in the WHMCS external config.

Edit `.env`:

- `CONSOLE_DOMAIN`: public Gateway DNS name.
- `WHMCS_PUBLIC_IP`: the exact public source IP used by WHMCS.
- `PUBLIC_BASE_URL`: `https://` URL matching the console DNS name.
- `API_KEY` and `API_SECRET`: generated values.
- `ALLOWED_ACTIONS`: keep `power_on,shutdown,reboot` unless deliberately changed.
- `CONSOLE_ALLOWED_CIDRS`: only the vCenter/ESXi addresses that may receive
  proxied console connections. Prefer narrow CIDRs over whole private ranges.

Edit `secrets/vcenters.json`:

- Give each vCenter a stable, non-secret ID.
- Use its SDK URL, normally `https://vcenter.example.com/sdk`.
- Add the dedicated service-account credentials.
- Pin the SHA-256 TLS certificate fingerprint.
- Add only customer VM inventory folders to `allowed_inventory_prefixes`.

Multiple vCenters can be defined as separate objects in the JSON array.

## 4. Supply WebMKS assets

VMware HTML Console SDK is not redistributed by this project. Obtain version
2.2.1 through its official vendor channel and copy these files into `webmks/`:

```text
jquery.min.js
jquery-ui.min.js
wmks.min.js
wmks-all.css
```

Confirm that only those runtime assets and `README.md` exist there. They are
ignored by Git and mounted read-only into the container.

## 5. Start and verify the Gateway

```bash
cd /opt/vmcontrol
sudo docker compose config --quiet
sudo docker compose build --pull gateway
sudo docker compose up -d
sudo docker compose ps
curl -fsS https://console.example.com/healthz
```

The expected health response is:

```json
{"service":"vmcontrol-gateway","status":"ok"}
```

Also verify that an unauthenticated `/api/v1/...` request from the WHMCS host
returns HTTP 401, while the same path from an untrusted source returns HTTP 403.
Both results are intentional.

## 6. Install the WHMCS addon

Back up the WHMCS database and the existing module path first. Copy the addon:

```bash
sudo install -d -m 0750 -o root -g apache \
  /var/www/html/modules/addons/vmcontrol
sudo cp -a whmcs/modules/addons/vmcontrol/. \
  /var/www/html/modules/addons/vmcontrol/
sudo chown -R root:apache /var/www/html/modules/addons/vmcontrol
sudo find /var/www/html/modules/addons/vmcontrol -type d -exec chmod 0750 {} \;
sudo find /var/www/html/modules/addons/vmcontrol -type f -exec chmod 0640 {} \;
```

Adjust the document root and web-server group if your installation differs.
On SELinux systems, restore the normal web-content context for the copied path.

Create the external configuration outside the web root:

```bash
sudo cp whmcs/config/whmcs-vmcontrol.example.php /etc/whmcs-vmcontrol.php
sudo chown root:apache /etc/whmcs-vmcontrol.php
sudo chmod 0640 /etc/whmcs-vmcontrol.php
```

Set the real Gateway URL and exactly the same `API_KEY` and `API_SECRET` values
used by the Gateway. Never place these values in the module directory.

In WHMCS administration:

1. Open **System Settings -> Addon Modules**.
2. Activate **VMControl**.
3. Limit addon access to the required administrator roles.
4. Choose whether customer action and power-state notification emails are sent.
5. Open **Addons -> VMControl** to initialize or upgrade its schema.

The addon creates only `mod_vmcontrol_services` and `mod_vmcontrol_events`.
Deactivation intentionally preserves both tables for rollback.

## 7. Perform a controlled first mapping

1. Use an internal test customer with an `Active` service.
2. Search for the WHMCS service and its VM in **Addons -> VMControl**.
3. Review vCenter ID, VM identity, IP, and service owner before saving.
4. Log in as that customer and verify status/specs, display-name change, each
   safe action, performance charts, and console.
5. Suspend the WHMCS service and confirm every control is locked.
6. Confirm another customer cannot access the service by changing URL or form
   parameters.
7. Review Gateway and WHMCS logs before mapping additional services.

For bulk assignment, start from `docs/mapping-import-template.csv`. CSV import is
atomic: any invalid or conflicting row prevents the whole batch from committing.

## 8. Non-interference checks

Before general rollout, exercise client login, cart, checkout, invoice payment,
all active payment callbacks, support tickets, and the normal WHMCS cron. The
addon uses hooks and its own tables only, but these checks validate the complete
local installation and theme combination.
