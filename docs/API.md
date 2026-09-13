# Gateway API v1

The API is intended only for the WHMCS backend. It must not be called directly
from client browsers.

## Authentication

Every `/api/v1/*` request requires:

- `X-VMControl-Key`
- `X-VMControl-Timestamp` as Unix seconds
- `X-VMControl-Nonce` with at least 16 characters
- `X-VMControl-Signature` as lowercase hexadecimal HMAC-SHA256

The signed canonical string is:

```text
UPPERCASE_METHOD
ESCAPED_PATH
TIMESTAMP
NONCE
LOWERCASE_HEX_SHA256_OF_EXACT_BODY
```

The Gateway rejects stale timestamps and reused nonces.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/v1/vcenters` | Configured vCenter identifiers for the WHMCS administrator |
| GET | `/api/v1/vcenters/{vcenter}/vms` | VMs visible inside the configured inventory prefixes |
| GET | `/api/v1/vcenters/{vcenter}/vms/{instanceUuid}/status` | VM status and resources |
| GET | `/api/v1/vcenters/{vcenter}/vms/{instanceUuid}/metrics/{day|week|month}` | Historical vCenter performance series |
| POST | `/api/v1/vcenters/{vcenter}/vms/{instanceUuid}/actions` | Approved power action |
| POST | `/api/v1/vcenters/{vcenter}/vms/{instanceUuid}/console` | One-time public launch URL |
| GET | `/api/v1/services/{serviceId}/console-events` | Sanitized recent console actions; no UUID or ticket data |

Action body:

```json
{"action":"reboot","service_id":123,"user_id":45}
```

Console body:

```json
{"service_id":123,"user_id":45,"display_name":"Database Server"}
```

`service_id` and `user_id` are audit metadata. Authorization remains enforced
by WHMCS ownership checks, the HMAC boundary, Gateway inventory policy, and
vCenter permissions.

Inventory endpoints return only objects visible to the vCenter service account
and inside `allowed_inventory_prefixes`. They are consumed only by the WHMCS
administrator page and are never exposed to the customer browser.

After consuming the one-time launch URL, the browser uses only cookie-bound
same-origin `/console/status`, `/console/action`, and `/console/ws` routes. VM
UUIDs and raw vCenter console URLs are not included in customer HTML or browser
request paths.
