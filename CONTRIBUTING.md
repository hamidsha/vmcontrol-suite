# Contributing

Contributions are welcome through focused pull requests.

1. Do not include customer data, runtime configuration, credentials, logs,
   screenshots from real installations, VMware SDK assets, or vendor binaries.
2. Keep the WHMCS addon compatible with PHP 7.2 unless a major-version change is
   explicitly proposed.
3. Run Go tests and vet from `gateway/`, and syntax-check every PHP file.
4. Add tests for authentication, authorization, console-session, and vSphere
   behavior changes.
5. Describe security impact, upgrade steps, and rollback behavior in the pull
   request.

For vulnerabilities, do not open a public issue; follow `SECURITY.md`.
