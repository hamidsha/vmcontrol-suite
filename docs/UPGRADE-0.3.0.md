# Upgrade to VMControl 0.3.0

Version 0.3.0 adds the administrator mapping workspace. It does not change the
WHMCS database schema and does not modify WHMCS core, themes, products, payment
gateways, vCenter VMs, or WHMCS services.

## Order

1. Upgrade and verify the Gateway.
2. Upgrade the WHMCS addon.
3. Open **Addons → VMControl** and refresh the inventory.

## Administrator workflow

- Search WHMCS services by Service ID, customer, product, domain, or IP.
- Search scoped vCenter inventory by VM name, IP, UUID, path, or vCenter ID.
- Select one service and one VM to create a mapping.
- Use **Edit/Transfer** to change either side of an existing mapping.
- Use **Disable** to immediately hide the mapping from the customer without
  deleting it.
- Use **Unlink** to delete only the VMControl mapping record.
- Import multiple mappings from a UTF-8 CSV file with these headers:

  ```csv
  service_id,vcenter_id,instance_uuid,enabled
  123,primary,11111111-2222-3333-4444-555555555555,1
  ```

The CSV import validates every row before commit. If one row is invalid or
conflicts with another mapping, the entire import rolls back.

## Authorization behavior

- Active: status, power actions, and console are available to the owning client.
- Pending or Suspended: administrators can retain the mapping, but customer
  status calls, power actions, and console remain blocked.
- Cancelled, Terminated, or Fraud: cannot receive a new mapping through the
  administrator page or CSV import.

The Gateway inventory endpoint remains HMAC authenticated and returns only VMs
visible to the vCenter service account and inside `allowed_inventory_prefixes`.
