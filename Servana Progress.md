# Courier by Citrus

**Every delivery, clearly handled.**

Courier by Citrus is a delivery management SaaS platform for courier and transport service providers. It helps delivery businesses receive delivery requests, quote and price jobs, dispatch drivers, track delivery status, capture proof of delivery, manage billing, generate reports, and maintain administrator oversight across multiple tenant accounts.

> **Repository status:** This README describes the intended production-grade architecture and operating standards for the SaaS application. Do not mark a module as production-ready until the feature is implemented, tested, reviewed, and documented.

---

## Table of Contents

- [Product Overview](#product-overview)
- [Core Users](#core-users)
- [Main Capabilities](#main-capabilities)
- [Delivery Lifecycle](#delivery-lifecycle)
- [Technology Stack](#technology-stack)
- [Architecture Principles](#architecture-principles)
- [Repository Structure](#repository-structure)
- [Local Development Requirements](#local-development-requirements)
- [Installation](#installation)
- [Environment Configuration](#environment-configuration)
- [Database Setup](#database-setup)
- [Running the Application](#running-the-application)
- [Queues, Scheduler, and Background Jobs](#queues-scheduler-and-background-jobs)
- [Storage and Uploads](#storage-and-uploads)
- [Maps, Routing, and Location Services](#maps-routing-and-location-services)
- [Authentication and Authorization](#authentication-and-authorization)
- [Multi-Tenancy and Data Isolation](#multi-tenancy-and-data-isolation)
- [API Standards](#api-standards)
- [Frontend Standards](#frontend-standards)
- [Security Requirements](#security-requirements)
- [Testing](#testing)
- [Quality Gates](#quality-gates)
- [Deployment](#deployment)
- [Operational Monitoring](#operational-monitoring)
- [Development Workflow](#development-workflow)
- [Manifesto](#manifesto)
- [Roadmap](#roadmap)
- [License](#license)

---

## Product Overview

Courier by Citrus is a secure, scalable, multi-user SaaS web application for managing goods delivery from pickup location **Point A** to destination **Point B**.

The platform is designed for courier businesses that need operational visibility and control over:

- Delivery requests
- Sender onboarding
- Recipient visibility
- Quotes and pricing
- Driver and vehicle assignment
- Dispatch workflows
- Route planning
- Status tracking
- Proof of delivery
- Invoices, payments, and receipts
- Reports and analytics
- Audit logs
- Platform-level administration

The primary business user is the **Transport Service Provider**. Senders and recipients interact with the platform mainly to create, track, confirm, and acknowledge deliveries.

---

## Core Users

### 1. Super Administrator

The SaaS owner/operator role. Responsible for platform-level control.

Key responsibilities:

- Manage transport provider accounts
- Approve, suspend, or deactivate providers
- Manage subscription plans
- View platform-wide analytics
- Configure global delivery settings
- Monitor system health, failed jobs, disputes, and suspicious activity
- Access audit logs
- Manage integrations and API settings
- Control platform-level permissions

### 2. Transport Service Provider

The primary operational business user.

Key responsibilities:

- Receive delivery requests
- Create manual delivery jobs
- Generate quotes
- Assign jobs to drivers or vehicles
- Track deliveries
- Confirm pickup and delivery
- Capture proof of delivery
- Manage pricing, invoices, payments, and reports
- Manage staff and permissions

Recommended internal provider roles:

- Provider Owner
- Dispatcher
- Driver / Rider
- Finance User
- Support User
- Viewer

### 3. Goods Sender

The person or business requesting goods transportation.

Key responsibilities:

- Create delivery requests
- Add pickup and recipient details
- Describe goods
- View pricing or quote
- Pay or request invoice
- Track delivery status
- View delivery history

### 4. Goods Recipient

The person receiving the goods at the destination.

Key responsibilities:

- Access delivery tracking page
- View ETA and status updates
- Confirm availability
- Confirm receipt using OTP, photo, signature, or name
- Report delivery issues

---

## Main Capabilities

### Account and Tenant Management

- Provider registration
- Provider approval workflow
- Provider business profiles
- Provider documents
- Provider service areas
- Provider operating hours
- Provider pricing settings
- Provider staff users
- Provider subscriptions
- Provider suspension and deactivation

### Delivery Request Management

- Create and edit delivery requests
- Add pickup and destination locations
- Add sender and recipient details
- Add goods category, description, weight, dimensions, value, and handling notes
- Upload goods photos or documents
- Generate delivery reference numbers
- Generate secure tracking links
- Cancel requests according to business rules

### Quotation and Pricing

Supported pricing patterns:

- Fixed base fee
- Distance-based pricing
- Weight-based pricing
- Vehicle-type pricing
- Urgency surcharge
- Time-window surcharge
- Zone-based pricing
- Minimum delivery fee
- Waiting-time fee
- Failed delivery fee
- Return fee
- Manual override quote

Recommended MVP pricing:

- Manual quotes
- Basic distance-based pricing

### Dispatch and Assignment

- View active delivery jobs
- Assign deliveries to drivers or vehicles
- Reassign deliveries
- Filter by area, urgency, status, and time window
- View driver and vehicle availability
- Display jobs on a map
- Support manual route planning
- Prepare for post-MVP route optimization

### Driver Portal

Drivers need a mobile-first web portal or PWA.

Core driver actions:

- View assigned jobs
- Accept or reject assignments, depending on provider rules
- Navigate to pickup
- Mark arrival at pickup
- Confirm pickup
- Upload pickup photo
- Update in-transit status
- Mark arrival at destination
- Capture delivery proof
- Report failed delivery or incident
- View job history

### Tracking

- Public tracking page
- Secure tracking link
- Delivery status timeline
- Pickup and destination details
- Estimated delivery time
- Map route display
- Optional live driver position where permitted
- Sender and recipient notifications
- Delivery proof after completion

### Proof of Delivery

Recommended MVP proof methods:

- OTP confirmation
- Delivery photo
- Timestamp
- GPS coordinates
- Recipient name

Post-MVP proof methods may include digital signatures and uploaded documents.

### Payments and Billing

- Online payments
- Cash-on-delivery marker, if allowed
- Invoice generation
- Receipt generation
- Refunds
- Payment status tracking
- Provider payout reports
- Tax/VAT handling
- Promo codes or discounts
- Failed payment handling

### Reports and Analytics

Provider reports:

- Total deliveries
- Completed deliveries
- Failed deliveries
- Cancelled deliveries
- Average delivery time
- Revenue
- Outstanding invoices
- Driver performance
- Vehicle utilization
- Pickup and delivery zones
- Failed delivery reasons

Super Admin reports:

- Total providers
- Active providers
- Total platform deliveries
- Platform revenue
- Subscription revenue
- API usage
- Failed jobs
- Suspicious activity
- System performance

---

## Delivery Lifecycle

Recommended status flow:

1. Draft
2. Pending Quote
3. Quoted
4. Awaiting Payment
5. Confirmed
6. Assigned
7. Driver En Route to Pickup
8. Arrived at Pickup
9. Picked Up
10. In Transit
11. Arrived at Destination
12. Delivered
13. Failed Delivery
14. Cancelled
15. Returned
16. Disputed
17. Refunded

Standard delivery flow:

1. Sender creates a delivery request.
2. The system validates pickup and destination addresses.
3. The system calculates route, distance, duration, and estimated price.
4. Sender confirms request.
5. Transport provider accepts or reviews the request.
6. Dispatcher assigns a driver or vehicle.
7. Driver proceeds to pickup.
8. Pickup is confirmed.
9. Delivery is marked in transit.
10. Recipient receives tracking updates.
11. Driver arrives at destination.
12. Recipient confirms delivery.
13. Proof of delivery is recorded.
14. Delivery is completed.
15. Invoice, receipt, and reporting records are generated.

Do not collapse the lifecycle into only `pending`, `in_progress`, and `completed`. Failed deliveries, cancellations, returns, disputes, and refunds must be first-class states.

---

## Technology Stack

| Layer | Technology |
| --- | --- |
| Backend | Laravel |
| Backend Language | PHP 8.2+ |
| Frontend | Vue.js or React.js |
| Frontend Language | TypeScript preferred |
| Styling | Tailwind CSS or Bootstrap 5 |
| Database | PostgreSQL preferred; MySQL acceptable |
| Authentication | Laravel Sanctum for SPA/API auth |
| API Style | REST by default |
| Build Tooling | Vite |
| Queue System | Redis-backed Laravel Queues |
| Cache | Redis |
| Storage | S3-compatible object storage |
| Search | Meilisearch, Typesense, or Elasticsearch, depending on scale |
| Deployment | Dockerized deployment with CI/CD |

### Non-negotiable stack rule

Do **not** use jQuery for this application. The frontend must use a component-based architecture through Vue, React, or an equivalent modern framework.

---

## Architecture Principles

Courier by Citrus must be built as a production-grade SaaS application with:

- Strong separation of concerns
- Secure authentication
- Predictable authorization
- Tenant-scoped data access
- Maintainable code organization
- Responsive UI behavior
- Background processing for slow work
- Structured logs and audit trails
- Repeatable deployment pipeline
- Automated testing across critical workflows

The system must be designed so one customer cannot access, infer, modify, or enumerate another customer's data.

---

## Repository Structure

Recommended Laravel + Vite structure:

```txt
courier-by-citrus/
├── app/
│   ├── Actions/
│   ├── Console/
│   ├── Domains/
│   │   ├── Accounts/
│   │   ├── Deliveries/
│   │   ├── Dispatch/
│   │   ├── Drivers/
│   │   ├── Fleet/
│   │   ├── Billing/
│   │   ├── Notifications/
│   │   ├── Reports/
│   │   └── Support/
│   ├── Http/
│   │   ├── Controllers/
│   │   ├── Middleware/
│   │   ├── Requests/
│   │   └── Resources/
│   ├── Jobs/
│   ├── Models/
│   ├── Notifications/
│   ├── Policies/
│   └── Services/
├── bootstrap/
├── config/
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
├── docker/
├── public/
├── resources/
│   ├── css/
│   ├── js/
│   │   ├── components/
│   │   ├── layouts/
│   │   ├── pages/
│   │   ├── router/
│   │   ├── stores/
│   │   ├── types/
│   │   └── api/
│   └── views/
├── routes/
│   ├── api.php
│   ├── web.php
│   └── console.php
├── storage/
├── tests/
│   ├── Feature/
│   ├── Unit/
│   ├── Browser/
│   └── Security/
├── .env.example
├── .gitignore
├── composer.json
├── package.json
├── vite.config.ts
├── docker-compose.yml
└── README.md
```

The exact structure may evolve, but domain boundaries must stay clear. Delivery workflows, billing, dispatch, tenant management, notifications, and reports should not become one large procedural module.

---

## Local Development Requirements

Install the following locally or through Docker:

- PHP 8.2+
- Composer
- Node.js 20+
- npm, pnpm, or yarn
- PostgreSQL 15+ or MySQL 8+
- Redis
- Docker and Docker Compose, recommended
- S3-compatible storage emulator, optional for local development

Recommended local services:

- PostgreSQL for relational data
- Redis for cache, queues, and rate limiting
- Mailpit or Mailhog for email testing
- MinIO for S3-compatible local storage
- Meilisearch or Typesense for search, when needed

---

## Installation

Clone the repository:

```bash
git clone git@github.com:<your-org>/courier-by-citrus.git
cd courier-by-citrus
```

Install backend dependencies:

```bash
composer install
```

Install frontend dependencies:

```bash
npm install
```

Create the environment file:

```bash
cp .env.example .env
```

Generate the Laravel application key:

```bash
php artisan key:generate
```

Create the storage symlink only for files that are intentionally public:

```bash
php artisan storage:link
```

Most operational files, proof-of-delivery images, KYC documents, invoices, receipts, and dispute attachments should use private object storage with signed URLs rather than public filesystem paths.

---

## Environment Configuration

Required `.env` categories:

```env
APP_NAME="Courier by Citrus"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=courier_by_citrus
DB_USERNAME=postgres
DB_PASSWORD=

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis

REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=
AWS_BUCKET=
AWS_ENDPOINT=
AWS_USE_PATH_STYLE_ENDPOINT=false

SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000
SESSION_DOMAIN=localhost

GOOGLE_MAPS_BROWSER_KEY=
GOOGLE_MAPS_SERVER_KEY=

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Courier by Citrus"

SMS_PROVIDER=
SMS_API_KEY=

PAYMENT_PROVIDER=
PAYMENT_PUBLIC_KEY=
PAYMENT_SECRET_KEY=
```

Never commit `.env` files, service account files, API keys, payment secrets, SMS credentials, Maps API keys, or private storage credentials.

---

## Database Setup

Run migrations:

```bash
php artisan migrate
```

Run seeders:

```bash
php artisan db:seed
```

Reset local database during development:

```bash
php artisan migrate:fresh --seed
```

Recommended core tables:

- `users`
- `roles`
- `permissions`
- `transport_providers`
- `provider_users`
- `senders`
- `recipients`
- `addresses`
- `delivery_requests`
- `delivery_items`
- `delivery_quotes`
- `delivery_assignments`
- `drivers`
- `vehicles`
- `driver_locations`
- `delivery_status_events`
- `proofs_of_delivery`
- `payments`
- `invoices`
- `refunds`
- `notifications`
- `support_tickets`
- `disputes`
- `audit_logs`
- `api_keys`
- `webhook_endpoints`
- `webhook_deliveries`
- `provider_subscriptions`
- `pricing_rules`
- `service_zones`

Every tenant-owned table must include `transport_provider_id`, `tenant_id`, `account_id`, or an equivalent ownership key.

---

## Running the Application

Start the backend server:

```bash
php artisan serve
```

Start the frontend development server:

```bash
npm run dev
```

Build frontend assets for production:

```bash
npm run build
```

Run both Laravel and Vite using Composer scripts, when configured:

```bash
composer run dev
```

---

## Queues, Scheduler, and Background Jobs

Background jobs should handle slow or retryable work:

- Email notifications
- SMS notifications
- WhatsApp notifications
- Delivery status events
- Payment webhook processing
- Invoice and receipt generation
- Report generation
- File processing
- Webhook delivery retries
- Import/export jobs

Run queue worker locally:

```bash
php artisan queue:work
```

Run Horizon, when installed:

```bash
php artisan horizon
```

Run the scheduler locally:

```bash
php artisan schedule:work
```

Production must run queue workers and the Laravel scheduler through the deployment supervisor/process manager.

---

## Storage and Uploads

Courier by Citrus handles sensitive operational files, including:

- Goods photos
- Proof-of-delivery photos
- Provider documents
- Vehicle documents
- KYC documents
- Invoices
- Receipts
- Dispute attachments
- Export files

Storage rules:

1. Private files must not be stored publicly by default.
2. Use S3-compatible object storage in production.
3. Use signed temporary URLs for private downloads.
4. Validate file type, size, and extension.
5. Scan or restrict uploads where needed.
6. Never log private file URLs containing temporary signatures.
7. Never expose tenant files across tenant boundaries.

---

## Maps, Routing, and Location Services

Courier by Citrus uses Google Maps Platform or an equivalent mapping provider for:

- Web map display
- Pickup and destination autocomplete
- Geocoding
- Reverse geocoding
- Route calculation
- Distance calculation
- ETA calculation
- Address validation
- Route visualization
- Post-MVP route optimization

Security rules for maps:

1. Use separate browser and server API keys.
2. Restrict browser keys by domain.
3. Restrict server keys by IP or runtime environment where supported.
4. Restrict each key to required APIs only.
5. Set billing alerts and quota limits.
6. Monitor API usage.
7. Rotate keys carefully.
8. Never expose unrestricted server-side API keys to frontend code.

---

## Authentication and Authorization

Authentication requirements:

- Email/password login at minimum
- Laravel-supported authentication flow
- Laravel Sanctum for SPA/API authentication
- Email verification
- Password reset where applicable
- Optional MFA
- Secure cookie configuration
- CSRF protection for browser flows
- Rate limiting for login, registration, password reset, invitations, and sensitive endpoints

Authorization requirements:

- Laravel Policies, Gates, or Spatie Laravel Permission
- Role-based access control
- Permission-based feature access
- Tenant ownership checks
- Authorization on controllers, API endpoints, form submissions, background jobs, exports, downloads, billing, settings, and admin screens

Minimum generic role structure:

- Owner
- Admin
- Manager
- Member
- Viewer

Provider-specific operational roles may include:

- Provider Owner
- Dispatcher
- Driver / Rider
- Finance User
- Support User
- Viewer

Frontend permission checks may improve user experience, but they are not security controls. Backend authorization is mandatory.

---

## Multi-Tenancy and Data Isolation

Courier by Citrus is a multi-tenant SaaS platform. Tenant isolation is a critical security boundary.

Rules:

1. Every tenant-owned database record must include a tenant ownership key.
2. All tenant-owned queries must be scoped by tenant.
3. Authorization must verify both user permission and tenant ownership.
4. Super Admin access must use controlled workflows.
5. Background jobs must preserve tenant context.
6. Notifications must preserve tenant context.
7. Exports must preserve tenant context.
8. Webhooks must preserve tenant context.
9. APIs must not expose sequential identifiers where public-safe identifiers are better.
10. Use UUIDs, ULIDs, or equivalent public-safe identifiers where appropriate.

High-risk areas:

- Delivery detail pages
- Tracking links
- Invoice downloads
- Proof-of-delivery files
- Driver assignment endpoints
- Provider reports
- Admin impersonation
- Background jobs
- Search endpoints
- Webhook payloads

Every pull request that touches tenant-owned data must include tenant isolation tests.

---

## API Standards

The API must be secure, predictable, and versioned.

Required standards:

- Use `/api/v1/...` routes
- Return consistent JSON response structures
- Use correct HTTP status codes
- Validate every request
- Authenticate protected routes
- Authorize every tenant-owned resource
- Rate-limit public and sensitive endpoints
- Paginate collection responses
- Avoid exposing internal IDs when public IDs are safer
- Return structured error responses
- Log API errors without exposing sensitive data

Example API route structure:

```txt
/api/v1/auth/login
/api/v1/auth/logout
/api/v1/me
/api/v1/providers
/api/v1/providers/{provider}
/api/v1/delivery-requests
/api/v1/delivery-requests/{deliveryRequest}
/api/v1/delivery-requests/{deliveryRequest}/quote
/api/v1/delivery-requests/{deliveryRequest}/assign
/api/v1/delivery-requests/{deliveryRequest}/status-events
/api/v1/delivery-requests/{deliveryRequest}/proof
/api/v1/tracking/{trackingReference}
/api/v1/drivers
/api/v1/vehicles
/api/v1/payments
/api/v1/invoices
/api/v1/reports/provider
/api/v1/admin/providers
/api/v1/admin/audit-logs
```

---

## Frontend Standards

Frontend requirements:

- Vue.js or React.js
- TypeScript preferred
- Vite
- Component-based architecture
- Centralized API client
- Reusable layout components
- Reusable form components
- Predictable state management where needed
- Strong loading, empty, success, and failure states
- Accessible labels and validation messages
- Safe rendering of user-generated content
- No jQuery

Responsive requirements:

| View Mode | Viewport Width |
| --- | --- |
| Desktop | `>= 1025px` |
| Tablet | `768px – 1024px` |
| Mobile | `<= 767px` |

Rules:

1. Use CSS media queries based on viewport width.
2. Do not use JavaScript device detection for responsive layout state.
3. Do not rely on minimized/maximized browser state.
4. Avoid horizontal scrolling on normal content.
5. Keep touch targets usable on tablet and mobile.
6. Preserve readable typography and spacing.

Accessibility requirements:

- Keyboard navigation
- Visible focus states
- WCAG-aligned contrast
- Proper form labels
- Associated validation errors
- Accessible names for buttons and links
- Touch targets of at least 44x44 points where practical
- Browser zoom support
- Reduced-motion support

Dark mode requirements:

- Light mode default
- Clear dark mode toggle
- Persisted preference per user where authentication exists
- Accessible contrast in both modes
- No hidden focus states, borders, or validation errors in dark mode

---

## Security Requirements

Courier by Citrus must defend against:

- SQL injection
- Cross-site scripting
- Cross-site request forgery
- Broken access control
- Insecure direct object references
- Mass assignment vulnerabilities
- File upload abuse
- Sensitive data exposure
- Session fixation
- Brute-force attacks
- API abuse
- Unsafe redirects
- Dependency vulnerabilities

Implementation rules:

1. Use Laravel validation for all incoming requests.
2. Use Form Request classes for complex validation.
3. Use guarded/fillable rules carefully.
4. Validate and sanitize uploaded files.
5. Store private files outside public paths.
6. Use signed URLs for private downloads.
7. Escape user-generated content by default.
8. Encrypt sensitive fields where appropriate.
9. Never log passwords, tokens, API keys, payment data, or secrets.
10. Use environment variables for secrets.
11. Enforce HTTPS in production.
12. Apply strict CORS rules.
13. Add rate limits to public and authenticated APIs.
14. Keep dependencies updated.
15. Run automated security scanning in CI/CD.

---

## Testing

Automated tests are mandatory.

Required test coverage:

- Authentication tests
- Authorization tests
- Tenant isolation tests
- API validation tests
- Form submission tests
- Role and permission tests
- Billing/plan enforcement tests where applicable
- File upload security tests
- Critical UI workflow tests
- Regression tests for important business logic

Minimum testing layers:

- Unit tests
- Feature tests
- API tests
- Browser or component tests for critical flows
- Security-focused tests for access control

Run backend tests:

```bash
php artisan test
```

Run frontend tests, when configured:

```bash
npm run test
```

Run static analysis, when configured:

```bash
vendor/bin/phpstan analyse
```

Run Laravel Pint, when configured:

```bash
vendor/bin/pint
```

Run frontend linting, when configured:

```bash
npm run lint
```

Run production build check:

```bash
npm run build
```

---

## Quality Gates

A pull request should not be merged unless it satisfies these gates:

- The problem is proven with evidence.
- The root cause is documented.
- The fix addresses the root cause directly.
- Tests prove the fix.
- Tenant isolation is preserved.
- Authorization checks are present.
- Sensitive data is not logged or exposed.
- Validation exists for user input.
- Large queries are paginated.
- Background work is queued where appropriate.
- Frontend behavior is accessible and responsive.
- The production build passes.
- The reviewer can verify the resolution.

---

## Deployment

Production deployment must be repeatable and automated.

Requirements:

- Environment-specific configuration
- No committed secrets
- CI/CD pipeline
- Tests before deployment
- Safe migration strategy
- Queue workers in production
- Laravel Scheduler in production
- Centralized logging
- Database backups
- Rollback procedure
- HTTPS
- Production-safe caching
- Health checks
- Uptime monitoring
- Dependency vulnerability scanning

Recommended deployment flow:

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan queue:restart
```

Production must run:

- Web server
- PHP-FPM
- Queue workers
- Scheduler
- Redis
- Database
- Object storage
- Monitoring and alerting

---

## Operational Monitoring

Production observability should include:

- Structured application logs
- Error tracking
- Performance monitoring
- Queue monitoring
- Failed job tracking
- Audit logs
- Login and security event logs
- Admin activity logs
- Billing/subscription event logs
- Critical failure alerts

Audit logs should capture:

- Actor
- Tenant/account
- Action
- Target resource
- Timestamp
- IP address where appropriate
- User agent where appropriate
- Safe before/after values where needed

Never log:

- Passwords
- Tokens
- API keys
- Payment secrets
- Full payment card data
- Private signed URLs
- Unredacted sensitive personal data

---

## Development Workflow

Recommended branch model:

```txt
main        production-ready code only
staging     pre-production validation
develop     active integration branch
feature/*   new features
fix/*       bug fixes
hotfix/*    urgent production fixes
```

Commit style:

```txt
feat: add delivery request creation
fix: enforce tenant scope on invoice download
test: add proof of delivery upload validation tests
refactor: extract dispatch assignment service
docs: update local setup instructions
chore: update dependencies
```

Pull request checklist:

- [ ] Problem is clearly described.
- [ ] Root cause is documented.
- [ ] Solution is scoped and precise.
- [ ] Tests were added or updated.
- [ ] Tenant isolation was verified.
- [ ] Authorization was verified.
- [ ] Inputs are validated.
- [ ] No secrets or private files were committed.
- [ ] Logs do not expose sensitive data.
- [ ] UI is responsive and accessible.
- [ ] Documentation was updated where needed.

---

## Manifesto

Courier by Citrus follows a strict engineering manifesto:

1. **Prove the problem**  
   Do not guess. Every issue must be backed by evidence.

2. **Root cause analysis**  
   Understand the actual cause before applying a fix.

3. **Fix with precision**  
   Address the proven root cause, not just the symptom.

4. **Test thoroughly**  
   Run tests that confirm the intended behavior.

5. **Demonstrate resolution**  
   Show proof that the issue is resolved and the system behaves correctly.

This applies to code changes, bug fixes, security fixes, performance work, infrastructure changes, and AI-assisted development.

---

## Roadmap

### Phase 1 — Foundation

- Authentication
- Roles and permissions
- Provider tenancy
- User management
- Basic dashboard
- Audit logs

### Phase 2 — Delivery Core

- Delivery request creation
- Sender and recipient records
- Address entry
- Goods details
- Delivery status lifecycle
- Tracking reference

### Phase 3 — Maps and Pricing

- Google Maps display
- Places autocomplete
- Geocoding
- Routes API integration
- ETA and distance calculation
- Basic price calculation

### Phase 4 — Dispatch

- Driver records
- Vehicle records
- Assignment
- Driver portal
- Pickup confirmation
- Delivery confirmation

### Phase 5 — Proof, Payments, and Notifications

- OTP proof
- Photo proof
- SMS/email notifications
- Payment integration
- Invoice and receipt generation

### Phase 6 — Scale Features

- Route optimization
- Webhooks/API
- Advanced analytics
- Accounting integrations
- Native mobile apps only after the web/PWA workflow proves demand

---

## License

Proprietary / All Rights Reserved.

Copyright © Citrus Labs Limited.

This repository and its contents are proprietary unless a separate written license states otherwise. Do not copy, modify, distribute, sublicense, or use this code outside the authorized project scope without explicit permission from Citrus Labs Limited.

---

## Maintainer Notes

Use the full product name **Courier by Citrus** in public-facing surfaces. Use **Courier** as the short UI name inside the application where space is limited.

Do not overpromise capabilities. Route optimization, native mobile apps, advanced analytics, AI ETA prediction, and accounting integrations are post-MVP unless implemented, tested, and released.
