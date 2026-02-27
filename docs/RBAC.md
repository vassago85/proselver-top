# Role-Based Access Control

## Role Tiers

### Internal (Proselver Staff)
| Role | Slug | Capabilities |
|------|------|-------------|
| Super Admin | `super_admin` | Full system control, pricing config, overrides, margin dashboards, user management |
| Ops Manager | `ops_manager` | Approve bookings, assign drivers, override delays, scheduling, user management |
| Dispatcher | `dispatcher` | Assign drivers, update job status. No pricing access. |
| Accounts | `accounts` | Generate invoices, view financial dashboards, apply credit notes |

### Dealer (Customer)
| Role | Slug | Capabilities |
|------|------|-------------|
| Dealer Admin | `dealer_admin` | Full company access, book transport/yard, performance dashboard, manage dealership users |
| Dealer Scheduler | `dealer_scheduler` | Create/edit bookings, adjust scheduled ready time |
| Dealer Accounts | `dealer_accounts` | View invoices, download POD |
| Dealer Viewer | `dealer_viewer` | Read-only job and performance view |

### Driver
| Role | Slug | Capabilities |
|------|------|-------------|
| Driver | `driver` | View assigned jobs, log events, upload documents, scan POD, complete jobs |

## Multi-Role Support
- Users can hold multiple roles (many-to-many via `user_roles` pivot table).
- Permissions are cumulative: a user with both `ops_manager` and `accounts` roles gets all capabilities of both.
- The `HasRoles` trait on the User model provides helper methods: `hasRole()`, `hasAnyRole()`, `isInternal()`, `isDealer()`, `isDriver()`.

## Route Protection
- `/admin/*` routes: requires `internal` middleware (any internal role)
- `/dealer/*` routes: requires `dealer` middleware (any dealer role)
- `/driver/*` routes: requires `driver.access` middleware
- `/api/driver/*` routes: requires Sanctum token authentication

## Policy Enforcement
- `JobPolicy` controls view/create/update/verify/approve/cancel/invoice actions per role.
- `CompanyPolicy` controls company management and user assignment.
