# Servana by Citrus

**Service made simple. Growth made visible.**

Servana by Citrus is a proprietary service-operations SaaS platform by **Citrus Labs Limited** for Kenyan service-based SMEs such as barbershops, salons, spas, massage parlours, grooming studios, beauty parlours, and similar appointment or walk-in businesses.

The platform helps service businesses manage merchant onboarding, front-office operations, client records, service delivery, invoices, offline payment records, personnel commissions, Citrus platform fees, merchant-to-platform payments, contact-download permissions, and audit-ready operational records from one secure web dashboard.

> Servana is not only a booking app, POS system, salon app, or invoicing tool. It is a service-operations platform for running the daily workflow of service-based SMEs.

---

## Table of Contents

1. [Product Summary](#product-summary)
2. [Target Users](#target-users)
3. [Core Business Model](#core-business-model)
4. [MVP Scope](#mvp-scope)
5. [User Roles](#user-roles)
6. [Core Modules](#core-modules)
7. [Main Workflows](#main-workflows)
8. [Dashboards](#dashboards)
9. [Recommended Technology Stack](#recommended-technology-stack)
10. [Architecture Overview](#architecture-overview)
11. [Data Model](#data-model)
12. [Permissions Model](#permissions-model)
13. [Security and Privacy Rules](#security-and-privacy-rules)
14. [Local Development Setup](#local-development-setup)
15. [Environment Variables](#environment-variables)
16. [Common Commands](#common-commands)
17. [Testing Strategy](#testing-strategy)
18. [Quality Standard](#quality-standard)
19. [Deployment Notes](#deployment-notes)
20. [Repository Structure](#repository-structure)
21. [Roadmap](#roadmap)
22. [MVP Exclusions](#mvp-exclusions)
23. [Commercial Risk Notes](#commercial-risk-notes)
24. [Brand Usage](#brand-usage)
25. [Contributing](#contributing)
26. [License](#license)
27. [Owner](#owner)

---

## Product Summary

Servana by Citrus digitises the operational flow of service-based SMEs.

It helps a business answer:

- Which client was served?
- Which service was performed?
- Which team member served the client?
- What invoice was generated?
- How did the customer pay the merchant?
- Was a receipt issued?
- What commission did the personnel earn?
- What platform fee is owed to Citrus Labs Limited?
- Has the merchant paid Citrus Labs?
- Which client contacts can personnel access after approved payment?
- Which sensitive actions were recorded in audit logs?

The strongest product position is:

> A lightweight operating system for service-based SMEs where every client visit, service rendered, invoice, staff commission, and platform fee is traceable in real time.

---

## Target Users

Servana is designed for businesses with repeat clients, front-office workflows, service personnel, commissions, and owner visibility needs.

Strong early target segments:

- Salons with 3–15 staff
- Multi-chair barbershops
- Massage parlours with multiple therapists
- Spas and grooming studios
- Beauty businesses where commissions are common
- Service SMEs where the owner is not always physically present

Less ideal early target segments:

- Solo operators with no staff
- Businesses unwilling to record invoices consistently
- Businesses that only need simple appointment scheduling
- Businesses that do not need commission tracking or operational visibility

---

## Core Business Model

Servana by Citrus uses a merchant-funded SaaS model.

| Revenue Stream | Description | MVP Treatment |
| --- | --- | --- |
| Merchant account-opening fee | One-time **KES 5,000** fee paid when a merchant account is opened | Required before merchant activation |
| Platform service fee | **10%** fee on each merchant invoice | Automatically calculated by the system |
| Personnel client-contact download fee | Fee paid by Merchant Personnel to download contacts of clients they personally served | Configured by Super Administrator |
| Future add-ons | SMS reminders, loyalty tools, advanced analytics, marketing tools, payroll tools | Not required for MVP |

### Payment Separation

The MVP separates customer payments from merchant-to-platform payments.

```text
Customer pays Merchant offline
Merchant records payment method in Servana
Servana calculates 10% Citrus platform service fee
Merchant pays Citrus Labs Limited through the platform gateway
```

### Customer-to-Merchant Payment

Customers pay the merchant offline using methods such as:

- Cash
- M-Pesa
- Bank transfer
- Card terminal
- Voucher
- Split payment
- Other

Servana records how the customer paid. It does not need to process customer-to-merchant payments in the MVP.

### Merchant-to-Citrus Payment

Merchants pay Citrus Labs Limited through the platform payment gateway for:

- Account-opening fees
- Accrued platform service fees
- Other configured platform charges

---

## MVP Scope

The MVP focuses on service delivery, financial traceability, staff accountability, and platform billing.

### Must-Have Features

| Feature | Priority |
| --- | --- |
| Magic Link login | Critical |
| Role-based access control | Critical |
| Tenant-based access control | Critical |
| Merchant onboarding | Critical |
| KES 5,000 account-opening fee tracking | Critical |
| Merchant profile management | Critical |
| Merchant activation, suspension, and deactivation | Critical |
| Front Office user creation | Critical |
| Personnel linking | Critical |
| Service catalogue | Critical |
| Client records | Critical |
| Service session tracking | Critical |
| Invoice generation | Critical |
| Offline payment method recording | Critical |
| Receipt generation | Critical |
| 10% platform fee ledger | Critical |
| Merchant-to-platform gateway payment | Critical |
| Personnel commission dashboard | High |
| Personnel client contact download | High |
| Super Administrator dashboard | High |
| Merchant Administrator dashboard | High |
| Audit logs | High |

---

## User Roles

The MVP uses exactly four operational account types.

Customers are stored as client records. They are not full login users in the MVP.

### 1. Super Administrator

The Super Administrator is the Citrus Labs Limited platform owner/operator role.

Responsibilities:

- Create merchants
- Activate, suspend, or deactivate merchants
- Confirm account-opening fee payment
- Configure the global platform service fee
- Configure merchant billing cycles
- Configure personnel contact-download fees
- View merchant payments made to Citrus Labs Limited
- View platform-wide reports
- Review audit logs
- Manage internal Super Administrator accounts where required

The Super Administrator should not directly manage daily bookings, client servicing, merchant staff scheduling, or merchant front-office activity.

### 2. Merchant Administrator

The Merchant Administrator is the business owner, manager, or authorised operator of a merchant business.

Responsibilities:

- Manage merchant profile
- Configure branch or location details
- Define operating hours
- Create and update the service catalogue
- Configure service pricing
- Link Merchant Personnel
- Create Merchant Front Office users
- Configure commission rules
- View all merchant invoices
- View revenue reports
- View platform fee reports
- Pay platform charges through the gateway
- View client records linked to the business
- View personnel performance

### 3. Merchant Front Office

The Merchant Front Office role is used by receptionists, cashiers, or desk operators.

Responsibilities:

- Add new clients
- Retrieve existing client records
- Handle walk-ins
- Record scheduled service sessions where applicable
- Select service rendered
- Assign service personnel
- Generate invoices
- Record offline customer payment method
- Mark invoices as paid
- Generate receipts
- View daily sales for the assigned merchant
- Initiate merchant-to-platform payments only where permission is granted

Access rule:

> An active Merchant Front Office user can only access the merchant account assigned by the Merchant Administrator unless explicitly assigned to multiple merchants through authorised logic.

Authentication:

> Merchant Front Office login uses Magic Link sent to email.

### 4. Merchant Personnel

Merchant Personnel are the actual service providers.

Examples:

- Barber
- Hairdresser
- Stylist
- Massage therapist
- Nail technician
- Beautician
- Facial therapist
- Grooming specialist

Responsibilities:

- View personal dashboard
- View clients personally served
- View service history
- Track commissions earned in real time
- View client count
- View revenue contribution
- Request download of contacts for clients personally served
- Pay configured contact-download fee before export
- Access only permitted client contact data

Strict data rule:

> Merchant Personnel must only access client contact details for clients they personally served. They must not export the merchant’s full customer list.

---

## Core Modules

### 1. Merchant Onboarding Module

Purpose:

> Create and activate service-based SME accounts.

Features:

- Merchant registration by Super Administrator
- Merchant business profile
- Account-opening fee status
- Merchant activation after KES 5,000 payment confirmation
- Settlement-cycle selection
- Merchant status management

Supported settlement cycles:

- Daily
- Weekly
- Bi-weekly
- Monthly

Merchant statuses:

- Pending
- Active
- Suspended
- Deactivated

Rule:

> A merchant should not generate live invoices until the account-opening fee is marked as paid.

### 2. Authentication and Access Control Module

Purpose:

> Allow secure login and enforce role-based and tenant-based permissions.

Authentication approach:

| User Type | Login Method |
| --- | --- |
| Super Administrator | Magic Link via email |
| Merchant Administrator | Magic Link via email |
| Merchant Front Office | Magic Link via email |
| Merchant Personnel | Magic Link via email recommended |

Access model:

```text
User role determines what actions the user can perform.
Merchant assignment determines which business data the user can access.
```

### 3. Service Catalogue Module

Purpose:

> Let merchants define what they sell.

Features:

- Create service
- Edit service
- Archive service
- Set price
- Set estimated duration
- Assign eligible personnel
- Mark service active or inactive

Example services:

| Business Type | Services |
| --- | --- |
| Barbershop | Haircut, shave, dye, beard trim |
| Salon | Braiding, blow-dry, hair treatment, styling |
| Massage parlour | Swedish massage, deep tissue massage, aromatherapy |
| Spa | Facial, manicure, pedicure, body scrub |

### 4. Client Records Module

Purpose:

> Maintain customer records for repeat service, personnel tracking, and business visibility.

Fields:

- Client name
- Phone number
- Email, optional
- Gender, optional
- Visit history
- Services consumed
- Personnel who served client
- Payment history
- Notes/preferences, optional

MVP position:

> Customers do not need full login accounts at MVP stage.

### 5. Service Session Module

Purpose:

> Record actual service delivery.

Statuses:

- Draft
- In waiting
- In progress
- Completed
- Cancelled
- Invoiced
- Paid

### 6. Invoice and Receipt Module

Purpose:

> Convert service delivery into traceable financial records.

Invoice fields:

| Field | Description |
| --- | --- |
| Invoice number | Unique system-generated invoice ID |
| Merchant | Business issuing the invoice |
| Client | Customer receiving service |
| Service | Service rendered |
| Personnel | Staff member who performed service |
| Amount | Service price |
| Discount | Optional |
| Final invoice amount | Amount payable by customer |
| Payment method | Cash, M-Pesa, card, bank transfer, other |
| Payment status | Paid, unpaid, or void |
| Platform fee | 10% Citrus Labs service fee |
| Created by | Front Office or Merchant Admin |
| Timestamp | Date and time of invoice creation |

Receipt rule:

> A receipt should only be generated after payment is marked as received.

### 7. Offline Payment Recording Module

Purpose:

> Let Front Office specify how the customer paid the merchant.

Supported payment method labels:

- Cash
- M-Pesa
- Bank transfer
- Card terminal
- Voucher
- Split payment
- Other

MVP distinction:

> Servana records offline customer-to-merchant payment methods. It does not process customer payments for merchants in the MVP.

### 8. Citrus Billing Engine

Purpose:

> Calculate and enforce what merchants owe Citrus Labs Limited.

Billing rules:

| Rule | MVP Behaviour |
| --- | --- |
| Account-opening fee | Merchant pays KES 5,000 before activation |
| Platform service fee | 10% of each invoice |
| Billing cycle | Daily, weekly, bi-weekly, or monthly |
| Payment channel | Merchant pays through platform gateway |
| Fee visibility | Merchant Admin can view amount due |
| Super Admin visibility | Super Admin can view all merchant receivables |
| Suspension logic | Optional MVP rule for overdue platform fees |

Calculation rule:

> The 10% platform fee should be calculated on the final invoice amount after discount.

Example:

```text
Customer invoice value: KES 1,000
Customer pays merchant offline: KES 1,000
Citrus Labs platform fee: 10% x 1,000 = KES 100
Merchant amount retained before other costs: KES 900
```

### 9. Merchant-to-Platform Payment Gateway Module

Purpose:

> Collect amounts owed by merchants to Citrus Labs Limited.

Features:

- Merchant Admin can pay outstanding platform fees
- Merchant Front Office can pay only where permission is granted
- Gateway payment reference is captured
- Payment status is tracked
- Super Admin can view payment status
- Merchant can download payment receipt

Payment statuses:

- Pending
- Successful
- Failed
- Reversed

Permission rule:

> Front Office may initiate payment, but Merchant Administrator controls whether Front Office has that permission.

### 10. Commission Tracking Module

Purpose:

> Let Merchant Personnel see what they are owed in real time.

Features:

- Commission rule per personnel
- Optional commission rule per service
- Commission calculated per paid invoice
- Personnel dashboard
- Commission earned view
- Commission pending view
- Optional commission paid tracking

Recognition rule:

> Commission should be recognised only when the invoice is marked as paid.

Example:

```text
Haircut invoice: KES 1,000
Personnel commission rate: 40%
Commission earned: KES 400
```

### 11. Personnel Client Contact Download Module

Purpose:

> Let Merchant Personnel access contacts of clients they personally served while protecting merchant-owned customer data.

Features:

- Personnel can view clients they personally served
- Personnel can request contact export
- System calculates download fee set by Super Administrator
- Personnel pays fee through platform payment gateway
- System allows export after successful payment
- Export includes only allowed client contacts
- Audit log records export action

Exportable fields:

- Client name
- Phone number
- Email, where available
- Last service date
- Service category

Non-exportable fields:

- Merchant revenue data
- Merchant-wide customer list
- Other personnel’s clients
- Internal notes marked private
- Payment details

---

## Main Workflows

### Merchant Onboarding

```text
Super Admin creates merchant
Merchant pays KES 5,000 account-opening fee
Super Admin activates merchant
Merchant Admin receives Magic Link login
Merchant Admin configures business profile
Merchant Admin creates Front Office user
Merchant Admin links Merchant Personnel
Merchant starts recording services and invoices
```

### Daily Business Operation

```text
Client arrives
Front Office creates or selects client
Front Office selects service
Client may request a specific Merchant Personnel at an extra fee set by the Super Admin, in which case the client shall be placed next in line for that specific Merchant Personnel
Alternatively, the client may be normally queued to the next available Merchant Personnel
Front Office assigns Merchant Personnel
Service is completed
Invoice is generated
Client pays merchant offline
Front Office records payment method
Receipt is issued
Personnel commission updates
Citrus Labs 10% fee updates
```

### Platform Fee Settlement

```text
Invoices accumulate during billing cycle
System calculates 10% Citrus Labs fee
Merchant Admin views amount due
Merchant pays Citrus Labs via platform gateway
Super Admin sees payment confirmation
Merchant balance updates
```

### Personnel Contact Download

```text
Personnel views own serviced clients
Personnel requests contact download
System calculates download fee
Personnel pays via platform gateway
System verifies payment
Personnel downloads allowed client contacts only
Audit log records export
```

---

## Dashboards

### Super Administrator Dashboard

Key metrics:

- Total merchants
- Active merchants
- Suspended merchants
- Total invoices generated across platform
- Gross merchant invoice value
- Citrus Labs 10% fees accrued
- Citrus Labs fees collected
- Outstanding merchant balances
- Account-opening fees collected
- Contact download fees collected
- Top merchants by invoice value
- Overdue merchants

### Merchant Administrator Dashboard

Key metrics:

- Today’s sales
- Weekly sales
- Monthly sales
- Number of clients served
- Number of invoices
- Amount owed to Citrus Labs
- Platform fees paid
- Staff performance
- Personnel commissions
- Payment method breakdown
- Repeat clients

### Merchant Front Office Dashboard

Key metrics:

- Today’s appointments or walk-ins
- Clients currently being served
- Completed services
- Paid invoices
- Unpaid invoices
- Payment methods used
- Receipts issued

### Merchant Personnel Dashboard

Key metrics:

- Clients personally served
- Today’s services
- Weekly services
- Monthly services
- Revenue generated
- Commission earned
- Commission pending
- Downloadable client contacts
- Contact download payment status

---

## Recommended Technology Stack

This README assumes the recommended Laravel SaaS stack from the product technical direction.

| Layer | Technology |
| --- | --- |
| Backend | Laravel / PHP |
| Frontend framework | Vue.js or React.js |
| Frontend language | JavaScript with TypeScript preferred |
| Styling | Tailwind CSS or Bootstrap 5 |
| Database | MySQL or PostgreSQL |
| API auth | Laravel Sanctum or Passport |
| API style | REST or GraphQL |
| Build tool | Vite |
| Authentication | Magic Link via email |
| Access control | Role-Based Access Control plus Tenant-Based Access Control |

Technology rules:

- Use modern JavaScript, preferably TypeScript.
- Avoid jQuery for the SaaS application UI.
- Use component-based frontend architecture.
- Keep configuration values in environment variables.
- Keep generated files, logs, exports, receipts, invoices, and database dumps out of Git.

---

## Architecture Overview

Recommended architecture:

```text
Browser / Client UI
        |
        v
Laravel Web Routes / API Routes
        |
        v
Authentication Layer
Magic Link + Session/Sanctum/Passport
        |
        v
Authorization Layer
RBAC + Tenant Access Control
        |
        v
Application Services
Merchant Onboarding
Service Sessions
Invoices and Receipts
Platform Fee Ledger
Commission Ledger
Contact Export Requests
Audit Logs
        |
        v
Database
MySQL or PostgreSQL
        |
        v
External Integrations
Email Provider
Payment Gateway
Storage
Monitoring
```

### Tenant Boundary

Every merchant-owned operational record must be scoped to a merchant account.

Tenant-scoped entities include:

- Merchant users
- Services
- Clients
- Service sessions
- Invoices
- Receipts
- Payment method records
- Platform fee ledger entries
- Commission ledger entries
- Contact export requests
- Audit logs

### Financial Boundary

The system must clearly separate:

1. Offline customer-to-merchant payments
2. Merchant-to-Citrus platform payments
3. Personnel commission calculations
4. Personnel contact-download fee payments

---

## Data Model

Minimum core entities:

| Entity | Purpose |
| --- | --- |
| User | Stores login identity and role |
| Merchant | Stores business account |
| MerchantUser | Links users to merchant accounts |
| Service | Stores merchant service catalogue |
| Client | Stores customer records |
| ServiceSession | Tracks actual service delivery |
| Invoice | Tracks billed service |
| Receipt | Tracks paid invoice confirmation |
| PaymentMethodRecord | Tracks offline customer payment method |
| PlatformFeeLedger | Tracks 10% Citrus Labs fee per invoice |
| PlatformPayment | Tracks merchant payments to Citrus Labs |
| CommissionRule | Defines personnel commission logic |
| CommissionLedger | Tracks commissions earned |
| ContactExportRequest | Tracks personnel contact download requests |
| AuditLog | Tracks sensitive system activity |

Recommended shared columns for tenant-scoped records:

```text
id
merchant_id
created_by
updated_by
created_at
updated_at
deleted_at, where soft deletes are needed
```

Recommended audit columns:

```text
action
actor_user_id
actor_role
merchant_id
entity_type
entity_id
ip_address
user_agent
old_values
new_values
created_at
```

---

## Permissions Model

| Action | Super Admin | Merchant Admin | Front Office | Personnel |
| --- | ---: | ---: | ---: | ---: |
| Create merchant | Yes | No | No | No |
| Activate/suspend merchant | Yes | No | No | No |
| Configure platform fee | Yes | No | No | No |
| Set contact download fee | Yes | No | No | No |
| Create Front Office user | No | Yes | No | No |
| Link Personnel | No | Yes | No | No |
| Create service | No | Yes | No | No |
| Edit service pricing | No | Yes | No | No |
| Add client | No | Yes | Yes | Limited |
| Create invoice | No | Yes | Yes | No |
| Record customer payment method | No | Yes | Yes | No |
| View all merchant invoices | Yes | Yes | Limited | No |
| View own commissions | No | No | No | Yes |
| Download own client contacts | No | No | No | Yes, after fee |
| Pay platform fees | No | Yes | Optional | No |
| View platform-wide reports | Yes | No | No | No |

---

## Security and Privacy Rules

Security is a core product requirement because Servana handles merchant operations, client records, invoices, commissions, payment references, contact downloads, and audit logs.

### Non-Negotiable Rules

- Never commit `.env` files.
- Never commit payment gateway credentials.
- Never commit real client data.
- Never commit real merchant data.
- Never commit generated invoices or receipts.
- Never commit contact exports.
- Never commit database dumps.
- Never expose merchant-wide client lists to personnel.
- Never recognise commission before invoice payment is marked as paid.
- Never generate receipt before invoice payment is marked as received.
- Never allow Front Office users to switch merchants without authorised assignment.
- Never allow personnel to export clients they did not personally serve.

### Recommended Controls

- Magic Link token expiry
- One-time-use login tokens
- RBAC policies
- Tenant-based query scopes
- Audit logging for sensitive actions
- Export limits for contact downloads
- Payment confirmation checks before downloads
- Payment gateway reference storage
- Server-side validation for all financial calculations
- Soft deletes for sensitive business records where auditability matters
- Rate limiting for login, exports, and payment callbacks

### Sensitive Actions to Audit

- Merchant creation
- Merchant activation, suspension, and deactivation
- Account-opening fee confirmation
- Platform fee configuration changes
- Settlement cycle changes
- Contact-download fee changes
- User role changes
- Personnel linking and unlinking
- Invoice creation, update, voiding, and payment marking
- Receipt generation
- Platform payment confirmation
- Commission rule changes
- Contact export request
- Contact export payment
- Contact export download

---

## Local Development Setup

### Prerequisites

Recommended local tools:

- PHP 8.2+
- Composer
- Node.js 20+
- npm, pnpm, yarn, or bun
- MySQL 8+ or PostgreSQL 14+
- Git
- Laravel CLI
- Mail testing tool such as Mailpit, Mailhog, or a sandbox email provider
- Payment gateway sandbox account

### Clone Repository

```bash
git clone git@github.com:<your-org>/<your-repository>.git
cd <your-repository>
```

### Install Backend Dependencies

```bash
composer install
```

### Install Frontend Dependencies

Using npm:

```bash
npm install
```

Using pnpm:

```bash
pnpm install
```

### Create Environment File

```bash
cp .env.example .env
php artisan key:generate
```

### Configure Database

Update `.env` with the local database connection.

MySQL example:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=servana_local
DB_USERNAME=root
DB_PASSWORD=
```

PostgreSQL example:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=servana_local
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

### Run Migrations

```bash
php artisan migrate
```

### Seed Development Data

```bash
php artisan db:seed
```

Recommended seed data:

- One Super Administrator
- One active merchant
- One pending merchant
- One suspended merchant
- One Merchant Administrator
- One Merchant Front Office user
- Two Merchant Personnel users
- Sample services
- Sample clients
- Sample paid and unpaid invoices
- Sample commission rules
- Sample platform fee ledger entries

### Start Backend Server

```bash
php artisan serve
```

### Start Frontend Development Server

```bash
npm run dev
```

### Access Application

```text
http://127.0.0.1:8000
```

---

## Environment Variables

Use `.env.example` as the safe template. Real values must stay outside Git.

Suggested environment groups:

```env
APP_NAME="Servana by Citrus"
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=servana_local
DB_USERNAME=root
DB_PASSWORD=

MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS=no-reply@servana.test
MAIL_FROM_NAME="Servana by Citrus"

AUTH_MAGIC_LINK_EXPIRY_MINUTES=15
AUTH_MAGIC_LINK_RATE_LIMIT_PER_MINUTE=3

PLATFORM_ACCOUNT_OPENING_FEE_KES=5000
PLATFORM_SERVICE_FEE_PERCENTAGE=10
DEFAULT_SETTLEMENT_CYCLE=monthly

CONTACT_DOWNLOAD_FEE_KES=0
CONTACT_EXPORT_MAX_ROWS=500
CONTACT_EXPORT_LINK_EXPIRY_MINUTES=30

PAYMENT_GATEWAY_PROVIDER=sandbox
PAYMENT_GATEWAY_PUBLIC_KEY=
PAYMENT_GATEWAY_SECRET_KEY=
PAYMENT_GATEWAY_WEBHOOK_SECRET=

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

---

## Common Commands

### Backend

```bash
php artisan serve
php artisan migrate
php artisan migrate:fresh --seed
php artisan queue:work
php artisan schedule:work
php artisan route:list
php artisan config:clear
php artisan cache:clear
php artisan view:clear
```

### Frontend

```bash
npm run dev
npm run build
npm run lint
npm run typecheck
```

### Tests

```bash
php artisan test
```

or:

```bash
vendor/bin/phpunit
```

---

## Testing Strategy

Testing must prove that business-critical workflows behave correctly.

### Authentication Tests

- Magic Link can be requested by valid users
- Magic Link expires after configured duration
- Magic Link cannot be reused
- Invalid Magic Link is rejected
- Rate limiting applies to repeated login attempts

### Authorization Tests

- Super Admin can manage merchants
- Merchant Admin cannot access other merchants
- Front Office cannot switch merchants without assignment
- Personnel cannot access merchant-wide client lists
- Personnel cannot download contacts for clients they did not serve

### Merchant Onboarding Tests

- Pending merchant cannot generate live invoices
- Merchant activates only after KES 5,000 fee confirmation
- Suspended merchant is blocked from restricted operations

### Service Session Tests

- Front Office can create a client visit
- Service can be selected
- Personnel can be assigned
- Completed service can generate invoice
- Cancelled session cannot produce a paid invoice

### Invoice and Receipt Tests

- Invoice number is unique
- Invoice amount is calculated correctly
- Discount affects final invoice amount
- Receipt cannot be generated before payment is marked received
- Void invoice does not produce commission

### Platform Fee Tests

- 10% Citrus fee is calculated on final invoice amount after discount
- Platform fee ledger is created for paid invoices
- Merchant balance updates after platform payment
- Billing cycle grouping works correctly

### Commission Tests

- Commission is calculated only after invoice is paid
- Personnel sees only own commission records
- Commission amount follows configured rule
- Cancelled or unpaid invoice does not create payable commission

### Contact Export Tests

- Personnel can request export only for personally served clients
- Export fee is calculated correctly
- Export is blocked before successful payment
- Export excludes merchant revenue data
- Export excludes other personnel’s clients
- Export action creates audit log

### Audit Tests

- Sensitive actions are logged
- Audit records include actor, merchant, entity, action, timestamp, and metadata
- Audit logs cannot be modified by merchant users

---

## Quality Standard

All product work must follow this engineering discipline:

1. Prove the problem with evidence.
2. Identify the root cause before changing code.
3. Fix the proven cause directly.
4. Test the affected workflow thoroughly.
5. Demonstrate that the issue is resolved.

No speculative fixes. No symptom-only patches. No untested financial or permission changes.

---

## Deployment Notes

Production deployment must protect tenant data, secrets, payment records, and generated operational files.

### Production Checklist

- `APP_ENV=production`
- `APP_DEBUG=false`
- Strong `APP_KEY` generated
- HTTPS enabled
- Secure cookies enabled
- Production database credentials configured
- Queue worker configured
- Scheduler configured
- Mail provider configured
- Payment gateway production credentials configured
- Payment webhook signature validation enabled
- Storage permissions configured
- Backups configured
- Error monitoring configured
- Audit logs retained
- `.env` not committed
- Real exports not committed
- Database dumps not committed

### Recommended Production Commands

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

---

## Repository Structure

Recommended Laravel structure:

```text
app/
  Actions/
  Console/
  Enums/
  Events/
  Exceptions/
  Http/
    Controllers/
    Middleware/
    Requests/
  Jobs/
  Mail/
  Models/
  Notifications/
  Policies/
  Providers/
  Services/
bootstrap/
config/
database/
  factories/
  migrations/
  seeders/
public/
resources/
  css/
  js/
  views/
routes/
  api.php
  web.php
storage/
tests/
  Feature/
  Unit/
```

Recommended domain service areas:

```text
app/Services/MerchantOnboarding/
app/Services/ServiceSessions/
app/Services/Invoicing/
app/Services/Receipts/
app/Services/Billing/
app/Services/Commissions/
app/Services/ContactExports/
app/Services/Audit/
app/Services/Auth/
```

---

## Roadmap

### MVP

- Merchant onboarding
- Magic Link login
- RBAC and tenant access control
- Merchant profile
- Front Office users
- Personnel linking
- Service catalogue
- Client records
- Service session tracking
- Invoice generation
- Offline payment recording
- Receipt generation
- Platform fee ledger
- Merchant-to-platform payment gateway
- Commission dashboard
- Personnel contact download
- Super Admin dashboard
- Merchant dashboard
- Audit logs

### Post-MVP

- SMS reminders
- Loyalty tools
- Advanced analytics
- Marketing tools
- Payroll tools
- Inventory management
- Advanced calendar booking
- Multi-branch support
- Customer portal
- Online customer-to-merchant payments

---

## MVP Exclusions

The following features should not delay the MVP:

| Feature | Reason to Defer |
| --- | --- |
| Full customer mobile app | Adds complexity before merchant value is proven |
| Online client-to-merchant payments | Requires deeper payment reconciliation and dispute handling |
| Advanced appointment calendar | Basic appointment/walk-in flow is enough for MVP |
| Inventory management | Important later, not necessary for core revenue validation |
| Payroll automation | Commission visibility is enough for MVP |
| Automated tax filing | High compliance complexity |
| Loyalty programmes | Growth feature, not operational core |
| AI recommendations | Non-essential |
| Multi-branch enterprise management | Can come after single-branch merchant workflow works |

---

## Commercial Risk Notes

The platform is commercially viable, but the 10% invoice service fee creates adoption and under-recording risk.

Key risk:

> Merchants may under-record invoices to avoid the 10% platform fee unless Servana gives enough operational value.

Value drivers that reduce leakage:

- Commission tracking
- Staff accountability
- Client history
- Daily sales reporting
- Receipt records
- Personnel performance tracking
- Client retention
- Owner visibility

Pricing model to test after MVP validation:

```text
KES 5,000 onboarding fee
+
lower transaction/service fee
+
monthly platform subscription
```

---

## Brand Usage

Correct usage:

- Servana
- Servana by Citrus
- Servana platform
- Servana dashboard
- Servana by Citrus Labs Limited, for legal or formal contexts

Avoid:

- Servanna
- Cervana
- ServAna
- Servana App
- Citrus Servana
- Servana SaaS Software Platform System

Product description:

> Servana by Citrus is a service-operations SaaS platform for service-based SMEs.

Primary tagline:

> Service made simple. Growth made visible.

Brand personality:

- Human
- Clear
- Trustworthy
- Practical
- Modern
- Encouraging
- Fair

---

## Contributing

This is a private commercial SaaS product owned by Citrus Labs Limited.

Internal contribution rules:

1. Create a branch from `main` or `develop`.
2. Use descriptive branch names.
3. Keep commits small and traceable.
4. Add or update tests for business logic changes.
5. Never commit secrets, exports, generated receipts, generated invoices, or database dumps.
6. Run tests before requesting review.
7. Document changes that affect billing, commissions, permissions, or data exports.

Recommended branch names:

```text
feature/merchant-onboarding
feature/magic-link-auth
feature/invoice-generation
feature/platform-fee-ledger
feature/personnel-commissions
fix/contact-export-permissions
fix/platform-fee-calculation
chore/update-readme
```

Recommended commit style:

```text
feat: add merchant onboarding flow
feat: calculate platform fee after invoice payment
fix: prevent personnel from exporting unrelated client contacts
test: cover commission calculation for paid invoices
chore: update environment example
```

---

## License

Copyright © 2026 **Citrus Labs Limited**.

All rights reserved.

This repository is proprietary. No permission is granted to copy, modify, distribute, sublicense, host, resell, or use this software except under written authorisation from Citrus Labs Limited.

No open-source license is granted.

---

## Owner

**Citrus Labs Limited**  
Product: **Servana by Citrus**  
Category: **Service Operations SaaS**  
Market Focus: **Kenyan service-based SMEs**

---

## Status

MVP planning and implementation.

This README defines the product, commercial model, architecture direction, security rules, and development expectations for building Servana by Citrus as a serious SaaS platform.
