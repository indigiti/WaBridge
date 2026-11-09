# WaBridge vNext — W2 member authentication and channel authorization

**Scope:** Additive, default-off, read-only tenant access. Implemented in draft PR #10; NOT deployed and NOT ready for live customer messaging.

## Why this is separate

The legacy WaBridge \`admin_authenticated()\` login is a platform super-admin capable of accessing gateway settings and the existing messaging queue. **Never** use that login to impersonate a tenant agent, and never expose a tenant member's session to \`public/index.php\` admin operations.

The new \`VNextMemberAuth\` uses a distinct session claim (\`vnext_member\`) inside the isolated WaBridge session cookie. A successful tenant login clears the legacy admin claim. If a platform admin claim is present, \`current()\` returns no tenant identity. The new tenant portal must not include or invoke the legacy admin UI shell.

## Implemented

- \`src/vnext/member-policy.php\`: tenant and user must both be active; membership must be active, with a recognized role. Channel read/reply/manage requires an **explicit** membership channel UUID grant and matching channel-to-tenant ownership in the private identity store. An empty channel scope denies all.
- \`src/vnext/member-auth.php\`: credential lookup by email in a specific tenant, per-IP+email+tenant rolling failure throttling (hashed keys, no plaintext addresses), password verification, session-ID regeneration and per-request authorization recheck.
- \`public/tenant-sign-in.php\`: separate tenant sign-in form with CSRF, generic failure messages, noindex/nocache; feature flag required.
- \`public/tenant-workspace.php\`: read-only organization, role and authorized-channel summary. No real contacts, customer messages or provider credentials are loaded. No send/campaign/AI operations are available.
- \`config.example.php\`: \`enable_vnext_tenant_login=false\` by default.
- \`tests/vnext-member-security.php\`: activation gating, role checks, foreign channel denial, rate limiting, immediate access revocation and privacy of attempts.
- \`tests/vnext-member-http-flow.sh\`: isolated PHP HTTP sign-in, form-CSRF, user/admin separation, assigned channel metadata, revoke-after-login behavior.

## Activation rule

Creating an organization or accepting an invitation does **not** authorize access. All three states must be active: \`organizations[tenant].status\`, \`users[user].status\`, and \`memberships[tenant:user].status\`. Every page request re-checks them.

Only an already trusted, audited administrative provisioning workflow should activate a tenant and its member grants. **No production activation endpoint is exposed in this increment.** Test scripts activate fictional local fixtures in their own disposable runtime, never in live WaBridge data.

The channel policy deliberately does not grant wildcard access, even to \`owner\` when no channel has been provisioned. Channel authorization does not itself authorize sending: only the legacy queue may dispatch real WhatsApp messages.

## Browser route behavior

- Default (\`enable_vnext_tenant_login=false\`): both new routes return 404.
- Enabled, unauthenticated: tenant workspace redirects to tenant sign-in.
- Enabled, authenticated: portal displays metadata only for the signed-in tenant and individually authorized channels.
- Suspended/disabled tenant or member: session is rejected at its next request, even if the browser retains a valid cookie.
- Sign-out clears only member session identity and rotates the cookie ID.

The current portal is not a replacement for a complete enterprise authentication service. MFA, secure invitations and delivery, forgot-password flow, audited activation, cross-worker policy integration, sessions on encrypted volumes and production penetration testing remain open gates.

## Staging restrictions

If the user only deployed the merged PR #9 foundation (main \`8f1f266\`), **these routes are not present there**. PR #10 must pass exact-head PHP and visual checks, be intentionally reviewed/merged, packaged into a DigiOps artifact and deployed before the new pages can be verified on Cloudways.

**Do not enable the login flag on the live Cloudways application until the activation and security gates are reviewed.** Never configure real WhatsApp channels as \`fixture_only\` to bypass provider verification.

## Next production integrations

1. Verified canonical inbound WhatsApp event envelope from the actual installed Rust/official Cloud provider, including message body minimization and receipt/job correlation.
2. Audited tenant activation, per-channel grants, account lifecycle, MFA and route-by-route agent authorization for the real Inbox.
3. Durable transactional send orchestration and provider uncertainty/reconciliation rules.
4. Production tenant storage/backup/retention, disaster-recovery testing, compliance and full Cloudways certification.
