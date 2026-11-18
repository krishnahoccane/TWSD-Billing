# Roles and permissions

Source of truth in code: `Ability` enum and `RolePermissionMatrix` (defaults). After seeding, the
`role_permissions` table is authoritative, so Super Admins can adjust non-Super-Admin roles without a deploy.
Keep this file in step with `RolePermissionMatrix`.

## How access is decided (`AccessResolver`)

1. Inactive users hold no abilities.
2. **Platform assignment** (`user_roles`) grants the role's abilities at platform level.
3. A platform assignment reaches inside organisations **only** if the user also holds `platform.global_access`.
   There is no implicit admin bypass (`Gate::before` is not used).
4. **Organisation assignment** (`organisation_user_roles`) grants the role's abilities in that organisation only.
5. Platform-only abilities (settings, integrations, sync, user management…) are never evaluated per organisation.
6. Assignments to a soft-deleted or **inactive** organisation grant nothing.
7. In a **suspended** organisation, organisation assignments grant only read abilities (`*.view`),
   `invoices.download`, `payments.initiate` and `support.create`. Users with global access are unaffected.

A user may hold different roles in different organisations and may manage several organisations.

| Role | May be assigned | Global access by default |
| --- | --- | --- |
| Super Admin | Platform only | Yes |
| Operations Admin | Platform and/or organisation | No (decision D-03) |
| Organisation Manager | Organisation only | No |
| Billing Manager | Organisation only | No |

Holders of any platform role are "privileged" and must use TOTP two-factor authentication.

## Default matrix

● granted · ○ not granted (grantable by a Super Admin) · **S** = sensitive (financial or destructive)

| Ability | Super Admin | Ops Admin | Org Manager | Billing Manager |
| --- | :-: | :-: | :-: | :-: |
| `platform.global_access` **S** | ● | ○ | ○ | ○ |
| `users.manage` **S** | ● | ○ | ○ | ○ |
| `settings.manage` **S** | ● | ○ | ○ | ○ |
| `system.health.view` | ● | ● | ○ | ○ |
| `queues.monitor` | ● | ● | ○ | ○ |
| `organisations.view` | ● | ● | ● | ● |
| `organisations.create` | ● | ○ | ○ | ○ |
| `organisations.update` | ● | ○ | ○ | ○ |
| `organisations.change_status` **S** | ● | ○ | ○ | ○ |
| `organisations.manage_managers` | ● | ○ | ○ | ○ |
| `organisations.manage_contacts` | ● | ○ | ● | ○ |
| `accounts.view` | ● | ● | ● | ○ |
| `accounts.create` | ● | ● | ● | ○ |
| `accounts.update` | ● | ● | ● | ○ |
| `accounts.suspend` | ● | ● | ● | ○ |
| `accounts.reactivate` | ● | ● | ● | ○ |
| `accounts.request_deletion` | ● | ● | ● | ○ |
| `accounts.soft_delete` **S** | ● | ○ | ○ | ○ |
| `accounts.restore` | ● | ○ | ○ | ○ |
| `accounts.permanent_delete.approve` **S** | ● | ○ | ○ | ○ |
| `accounts.permanent_delete.execute` **S** | ● | ○ | ○ | ○ |
| `accounts.reassign_organisation` **S** | ● | ○ | ○ | ○ |
| `accounts.sync` | ● | ● | ○ | ○ |
| `accounts.reset_password` | ● | ● | ● | ○ |
| `accounts.manage_security` (turn off 2SV) | ● | ● | ● | ○ |
| `accounts.reactivate_payment_suspended` **S** | ● | ○ | ○ | ○ |
| `domains.view` | ● | ● | ● | ○ |
| `domains.request` | ● | ○ | ● | ○ |
| `domains.approve` **S** | ● | ○ | ○ | ○ |
| `domains.process` | ● | ● | ○ | ○ |
| `domains.remove` **S** | ● | ○ | ○ | ○ |
| `domains.manage` **S** | ● | ○ | ○ | ○ |
| `billing.view` | ● | ○ | ● | ● |
| `billing.manage_contacts` | ● | ○ | ○ | ● |
| `billing.adjustments.manage` **S** | ● | ○ | ○ | ○ |
| `billing.cycles.run` **S** | ● | ○ | ○ | ○ |
| `pricing.view` | ● | ○ | ● | ● |
| `pricing.manage` **S** | ● | ○ | ○ | ○ |
| `invoices.view` | ● | ○ | ● | ● |
| `invoices.download` | ● | ○ | ● | ● |
| `invoices.manage` **S** | ● | ○ | ○ | ○ |
| `payments.view` | ● | ○ | ● | ● |
| `payments.initiate` **S** | ● | ○ | ● | ● |
| `payments.reconcile` **S** | ● | ○ | ○ | ○ |
| `integrations.google.manage` **S** | ● | ○ | ○ | ○ |
| `integrations.zoho.manage` **S** | ● | ○ | ○ | ○ |
| `integrations.whatsapp.manage` **S** | ● | ○ | ○ | ○ |
| `integrations.logs.view` | ● | ● | ○ | ○ |
| `audit.view` | ● | ○ | ○ | ○ |
| `audit.view_technical` | ● | ● | ○ | ○ |
| `support.create` | ● | ○ | ● | ● |
| `support.manage` | ● | ○ | ○ | ○ |

Notes

- Organisation Managers cannot approve their own domain requests (no `domains.approve`), cannot permanently delete,
  cannot change pricing, cannot move an account between organisations, and have no platform abilities.
- Billing Managers have no account or domain abilities.
- Operations Admins have **no** sensitive ability by default; each can be granted individually.
- Super Admin is re-synchronised to every ability on each seeder run; other roles keep any customisation.
- From Phase G a Super Admin edits the other roles' permissions at **Settings → Roles** (audited as
  `access.role_permissions_changed`). The Super Admin role itself cannot be edited there.

## Using it in code

```php
$user->hasAbility(Ability::AccountsSuspend, $organisation);      // organisation check
$user->hasAbility(Ability::SettingsManage);                      // platform check
$user->can(Ability::AccountsView->value, $organisation);         // same, via Gate / @can / middleware
GoogleAccount::query()->visibleTo($user, Ability::AccountsView); // tenant-filtered listing
```
