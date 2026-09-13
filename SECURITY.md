# Security policy

## Supported version

Security fixes are applied to the latest published VMControl release. Upgrade to
the newest release before reporting a problem that may already be resolved.

## Private reporting

Do not report vulnerabilities, credentials, customer data, console URLs, audit
logs, or infrastructure details in a public issue. Use GitHub's private
vulnerability reporting feature on this repository. Include only sanitized
reproduction steps and the affected version. If private reporting is not shown,
contact the repository owner without disclosing details publicly.

## If a secret is exposed

Treat it as compromised even if it was quickly removed from Git history:

1. Rotate the Gateway HMAC key and secret on Gateway and WHMCS.
2. Rotate the vCenter service-account password.
3. Revoke active access where supported and inspect audit/application logs.
4. Remove the material from the repository history only after rotation.

## Deployment requirements

- Expose only TCP 443 on the Gateway.
- Restrict `/api/*` to the exact WHMCS egress IP at the reverse proxy.
- Restrict console destinations to the required vCenter/ESXi networks.
- Use a least-privilege vCenter account scoped to customer VM folders.
- Pin the vCenter TLS certificate fingerprint and verify public TLS normally.
- Synchronize Gateway and WHMCS clocks.
- Keep hard power-off and reset outside `ALLOWED_ACTIONS` unless a separate
  policy and confirmation flow has been reviewed.
- Protect backups and audit logs as customer/infrastructure data.

## Repository safety

The repository must never contain `.env`, `secrets/vcenters.json`,
`/etc/whmcs-vmcontrol.php`, certificates, private keys, API credentials, audit
logs, customer mappings, database exports, production screenshots, or VMware
HTML Console SDK files. Only placeholder examples and synthetic screenshots are
accepted.
