# Servana Help & FAQ

## Super Administrator Account

**Product:** Servana by Citrus
**Owner and Operator:** Citrus Labs Limited
**Effective Date:** 1 January 2026
**Last Updated:** 1 August 2026

---

## Introduction

Servana is a service-operations SaaS platform built to help African service-based SMEs manage daily business activity with more clarity, structure, and accountability.

This FAQ explains how the Super Administrator account works inside Servana. It is written as a practical help document. It is not a legal agreement, legal policy, or replacement for Servana’s formal platform documents.

For full details on platform use, data handling, privacy, customer responsibilities, subscriptions, acceptable use, and service terms, please refer to Servana’s Terms of Service, Data Policy, Privacy Policy, Cookie Policy, Acceptable Use Policy, Subscription Agreement, Support Terms, End User Terms, and any other applicable platform documents.

---

# 1. About Servana

## 1.1 What is Servana?

Servana is a service-operations SaaS platform for African service-based SMEs.

It helps businesses manage clients, services, invoices, offline payment records, staff commissions, platform fees, permissions, and audit-ready operational records from one secure web dashboard.

Servana is not only a booking tool, POS tool, invoicing tool, or staff-management tool. It is designed as an operating dashboard for daily service business activity.

---

## 1.2 Who owns and operates Servana?

Servana is owned and operated by Citrus Labs Limited.

Citrus Labs Limited provides, maintains, controls, and commercializes the Servana platform.

---

## 1.3 Who is Servana built for?

Servana is built for service-based SMEs that handle daily customer visits, appointments, walk-ins, service delivery, staff roles, payment records, commissions, and business accountability.

Typical examples include:

* Barbershops
* Salons
* Spas
* Massage parlours
* Grooming studios
* Beauty parlours
* Similar appointment-based or walk-in service businesses

Servana can also support other lawful service operations where the business needs structured records, permissions, invoices, payment tracking, and audit visibility.

---

## 1.4 What problem does Servana solve?

Many service SMEs still manage daily operations through notebooks, WhatsApp messages, verbal instructions, screenshots, manual payment confirmation, and informal commission tracking.

Servana brings those daily activities into one structured system so that businesses can:

* Serve clients with cleaner records.
* Track services and staff activity.
* Record offline payments properly.
* Generate invoices and receipts.
* Monitor commissions.
* Control who can access what.
* Keep audit-ready operational history.
* Reduce disputes caused by missing or unclear records.

---

## 1.5 What does “service operations” mean in Servana?

Service operations means the practical day-to-day work of running a service business.

In Servana, this includes:

* Merchant onboarding
* Branch management
* Staff access
* Client records
* Walk-ins
* Appointments
* Queue management
* Service sessions
* Invoices
* Offline payment records
* Payment validation
* Receipts
* Staff commissions
* Platform fees
* Reports
* Permissions
* Audit logs

The goal is simple: help businesses run with better order, trust, and accountability.

---

## 1.6 Does Servana process client payments inside the platform?

No. Servana records offline or off-platform payments. It does not process client-to-merchant payments inside the platform.

Servana can record details such as:

* Payment method
* Amount
* Payment reference
* Payment status
* Validation status
* Related invoice
* Person who recorded the payment
* Person who validated the payment
* Timestamp
* Notes or supporting information

Supported offline payment methods may include cash, M-Pesa, bank transfer, card terminal, voucher, split payment, and merchant-defined offline methods.

---

## 1.7 What is the difference between recording a payment and processing a payment?

Processing a payment means moving money through the platform.

Recording a payment means saving information about a payment that happened outside the platform.

Servana records payment information so that merchants can keep clear financial records, validate payments, issue receipts after validation, and maintain audit visibility. The actual money movement happens outside Servana.

---

## 1.8 What is a tenant in Servana?

A tenant is a separate merchant environment inside Servana.

Each merchant operates as its own isolated tenant. This means one merchant’s data should not be visible, editable, searchable, exportable, or inferable by another merchant.

Tenant separation is a core part of how Servana protects merchant data and keeps platform operations organized.

---

## 1.9 What makes Servana different from a generic admin system?

Servana is built around the realities of African service SMEs.

It supports:

* Walk-ins and appointments
* Offline payments
* M-Pesa-style payment references
* Staff commissions
* Branch-level operations
* Merchant-to-platform fees
* Client records
* Permission-controlled roles
* Audit-ready activity logs

It is designed to be warm and simple for daily users, while still structured and trustworthy for administrators.

---

# 2. Accounts and Login

## 2.1 What is a Super Administrator account?

The Super Administrator account is the platform-owner account used by Citrus Labs Limited to oversee the Servana ecosystem at platform level.

The Super Administrator does not run a merchant’s daily business operations. The role exists to manage platform-wide settings, merchant visibility, platform fees, internal platform roles, feature controls, billing enforcement, suspicious usage monitoring, and platform-level audit visibility.

---

## 2.2 Who should use the Super Administrator account?

The Super Administrator account should only be used by authorized Citrus Labs Limited personnel or authorized platform-owner representatives.

It is a high-control role. It should not be assigned to merchant users, branch users, front-office users, personnel users, finance users, HR users, audit users, or general end users.

---

## 2.3 How do Servana users log in?

Servana uses Magic Link login.

A user receives a login link through their email address. The link must be valid, unused, and unexpired.

Before access is granted, the platform checks that:

* The email exists in the correct account context.
* The user belongs to the correct tenant or platform scope.
* The user account is active.
* The user role is active.
* The user has not been suspended.
* The user has the correct branch access, where branch access applies.
* The Magic Link is still valid.

---

## 2.4 Can someone log in just because their email exists?

No. An email address alone is not enough.

Servana checks the user’s account status, role status, tenant relationship, branch scope where applicable, suspension status, and Magic Link validity before allowing access.

This helps prevent accidental or unauthorized access.

---

## 2.5 What should a Super Administrator do if a Magic Link does not work?

The Super Administrator should check whether:

* The link has expired.
* The link has already been used.
* The email address is correct.
* The account is active.
* The role is active.
* The user is suspended.
* The user is trying to access the correct environment.
* The browser or email client is blocking the link.
* The login request was made from the correct email account.

If the issue continues, the authorized internal support contact should follow the Help & Support workflow.

---

## 2.6 Can Super Administrators share login links or credentials?

No. Magic Links and account access are personal to the authorized user.

Sharing login links, email access, sessions, or credentials creates security risk and weakens audit accountability. Every user should access Servana through their own authorized account.

---

## 2.7 Why does Servana use email-based Magic Links?

Magic Links help simplify login for non-technical users while still allowing controlled authentication.

They reduce password-management friction and support a cleaner user experience for merchants, staff, and administrators. For high-privilege roles such as Super Administrator, additional security controls may also be used where configured.

---

## 2.8 What should a Super Administrator do after suspected unauthorized access?

The Super Administrator should act quickly.

Recommended steps:

1. Suspend or restrict the affected account where needed.
2. Review recent login and activity logs.
3. Check whether the affected email account may be compromised.
4. Invalidate active sessions where needed.
5. Review permission changes, exports, billing changes, and merchant-impacting actions.
6. Preserve relevant audit records.
7. Escalate through the approved internal support or security workflow.

---

# 3. Super Administrator Dashboard

## 3.1 What is the purpose of the Super Administrator dashboard?

The Super Administrator dashboard gives Citrus Labs Limited platform-level visibility and control over Servana.

It helps the platform owner oversee merchants, platform fees, billing cycles, feature flags, audit logs, suspicious usage, internal roles, and platform-level operating settings.

---

## 3.2 What can the Super Administrator manage?

The Super Administrator may manage or view:

* Platform-wide settings
* Platform service fee rules
* Billing cycles
* Preferred personnel waiting fee rules
* Merchant list and merchant status
* Platform-wide reports
* Platform fee ledgers
* Platform-level audit logs
* Suspicious usage indicators
* Internal Citrus Labs Limited platform roles
* Platform-level feature flags
* Merchant suspension and deactivation controls
* Billing enforcement controls
* Abuse response controls
* Platform-policy enforcement controls

---

## 3.3 What should the Super Administrator not do?

The Super Administrator should not perform ordinary merchant operations.

The role should not be used to:

* Create merchant tenants on behalf of merchants.
* Create the first Merchant Administrator.
* Approve Merchant Administrator self-registration before dashboard access.
* Require KYC or compliance documents as part of Merchant Administrator self-registration.
* Review merchant compliance submissions as a Super Administrator workflow.
* Serve clients.
* Assign service sessions.
* Manipulate branch queues.
* Configure branch services.
* Edit merchant invoices outside controlled governance workflows.
* Validate merchant payments outside controlled governance workflows.
* Edit merchant receipts outside controlled governance workflows.

This boundary keeps the platform-owner role separate from merchant business operations.

---

## 3.4 Does the Super Administrator approve new merchant registration?

No. Merchant Administrators self-register their business account.

After registration, the system creates the merchant tenant and assigns the registering user as Merchant Owner / Merchant Administrator.

The Super Administrator governs platform-level rules, billing enforcement, abuse controls, and status controls. The Super Administrator does not manually approve Merchant Administrator self-registration before dashboard access.

---

## 3.5 Why is merchant self-registration important?

Merchant self-registration keeps onboarding simple and scalable.

It allows a business owner, manager, or authorized operator to register the merchant account directly, complete first-time setup, create a branch, add initial staff emails, and begin using Servana without waiting for manual platform-owner approval.

This supports Servana’s goal of being practical, simple, and usable for growing service SMEs.

---

## 3.6 What platform settings can the Super Administrator configure?

Depending on the enabled modules, platform settings may include:

* Platform service fee rules
* Billing cycle rules
* Preferred personnel waiting fee rules
* Feature availability
* Account status rules
* Internal role permissions
* Platform-level notifications
* Security settings
* Reporting settings
* Audit visibility rules
* Suspension and deactivation controls

Changes to sensitive settings should be clear, intentional, and captured in audit records.

---

## 3.7 What are feature flags?

Feature flags are controls that allow Citrus Labs Limited to enable, disable, test, restrict, or roll out platform features.

They are useful when:

* A feature is being piloted.
* A feature should be available only to selected merchants.
* A feature is being rolled out gradually.
* A feature needs to be temporarily disabled for security, maintenance, or operational reasons.
* Different plans or modules have different available functions.

---

## 3.8 Can the Super Administrator see all merchant data?

The Super Administrator may have platform-level visibility needed to operate, secure, support, monitor, and govern Servana.

However, Super Administrator access should be used carefully and only for proper platform-owner purposes such as billing review, support investigation, security monitoring, audit review, abuse response, reporting, or platform administration.

Servana should avoid casual or unnecessary access to merchant data.

---

## 3.9 What should the Super Administrator dashboard prioritize?

The dashboard should prioritize clear platform oversight.

Useful dashboard areas include:

* Merchant status summary
* Platform fee summary
* Overdue merchant accounts
* Suspicious activity alerts
* Recent platform audit activity
* Feature flag status
* Billing cycle status
* Support or operational flags
* Internal role activity
* High-risk events
* Platform usage trends

The dashboard should feel structured, trustworthy, and easy to scan.

---

# 4. Merchant Management

## 4.1 What is a merchant in Servana?

A merchant is a business account operating inside Servana.

Each merchant has its own tenant, users, branches, services, client records, invoices, payment records, receipts, commissions, reports, and audit logs.

---

## 4.2 Who creates a merchant account?

The Merchant Administrator creates the merchant account through self-registration.

The system then creates the merchant tenant and assigns the registering user as the Merchant Owner / Merchant Administrator.

---

## 4.3 What is a Merchant Administrator?

The Merchant Administrator is the business owner, manager, or authorized operator responsible for the merchant account.

The Merchant Administrator manages merchant setup, merchant profile details, service fee tier selection, branch creation, branch visibility, staff visibility, merchant-level reports, platform fee records, and high-level merchant control.

---

## 4.4 Can the Super Administrator create the first Merchant Administrator?

No. The first Merchant Administrator is created through self-registration.

This is an intentional product boundary. The Super Administrator governs the platform but does not manually create the first merchant owner account.

---

## 4.5 What can the Super Administrator view about merchants?

The Super Administrator may view merchant-level information needed for platform operation, including:

* Merchant name
* Merchant status
* Merchant branches
* Subscription or platform fee status
* Billing cycle information
* Platform fee ledger
* Account activity indicators
* Suspicious usage indicators
* Platform-policy enforcement status
* Audit records
* Reports and summaries

The exact visibility depends on platform configuration and internal access rules.

---

## 4.6 Can the Super Administrator suspend a merchant?

Yes, where platform rules allow it.

A merchant may be suspended for reasons such as:

* Platform fee non-payment
* Abuse
* Security concern
* Suspicious usage
* Serious platform-policy issue
* Operational risk
* Legal or regulatory concern
* Internal investigation
* Repeated misuse
* Required platform maintenance or protection

Suspension should be used carefully because it affects merchant operations.

---

## 4.7 What happens when a merchant is suspended?

A suspended merchant may lose the ability to perform new live operations, depending on configuration.

For example, suspension may restrict:

* New walk-ins
* New appointments
* New invoices
* New payment records
* New branch activity
* Staff access
* Selected feature access

Historical records should remain preserved according to Servana’s data, record, and retention settings.

---

## 4.8 Can a merchant be deactivated?

Yes. Merchant deactivation may be used when a merchant should no longer operate actively on Servana.

Deactivation should preserve necessary historical records, audit logs, invoices, receipts, payment records, and other important operational history according to configured retention rules.

---

## 4.9 Can the Super Administrator delete merchant records?

Deletion should be treated carefully.

Some records may need to be retained for operational, audit, billing, tax, accounting, security, support, or platform integrity reasons. Servana should not silently destroy important historical records that are needed to explain past activity.

Where deletion is available, it should be permission-controlled, logged, and aligned with Servana’s formal data and retention policies.

---

## 4.10 What should the Super Administrator do when there is a merchant ownership dispute?

The Super Administrator should avoid taking informal instructions from competing parties.

Practical steps include:

* Review the account’s registered administrator details.
* Check recent role and permission changes.
* Restrict high-risk actions where needed.
* Preserve audit records.
* Require the issue to be handled through the approved support and verification workflow.
* Avoid editing merchant business records unless required for platform protection.
* Refer the parties to their own internal governance process where appropriate.

Servana is a platform system, not a decision-maker for merchant ownership, employment, shareholder, management, or internal business disputes.

---

# 5. Platform Fees and Billing Records

## 5.1 What are platform fees?

Platform fees are the fees owed by merchants to Citrus Labs Limited for use of Servana or for platform-defined service fee rules.

They are separate from the money paid by clients to merchants for services.

---

## 5.2 Does Servana collect client-to-merchant payments?

No. Servana records offline payments but does not process client-to-merchant payments inside the platform.

Client payments may happen through cash, M-Pesa, bank transfer, card terminal, voucher, split payment, or another merchant-defined offline method. Servana stores the related record so the merchant can manage validation, receipts, and reconciliation.

---

## 5.3 What is the difference between merchant-client invoices and platform fee invoices?

A merchant-client invoice is the invoice a merchant gives to its client for services delivered.

A platform fee invoice or platform fee record relates to amounts owed by the merchant to Citrus Labs Limited for use of the platform or platform-defined fees.

These are different financial records and should not be confused.

---

## 5.4 What are service fee tiers?

Service fee tiers affect how the merchant-client invoice is calculated.

The available tiers may include:

* Customer Centric
* Split Tier
* Business Centric

The selected tier affects the amount shown to the merchant’s client. It does not remove the merchant’s responsibility to settle the full platform fee owed to Citrus Labs Limited.

---

## 5.5 What does Customer Centric mean?

Customer Centric means the client pays only the merchant’s service price.

The merchant absorbs the Citrus platform service fee.

Example:
If the service price is KES 500 and the platform fee is KES 70, the client invoice remains KES 500. The merchant still owes the platform fee separately.

---

## 5.6 What does Split Tier mean?

Split Tier means the client invoice includes part of the platform fee.

Example:
If the service price is KES 500 and the platform fee is KES 70, the client invoice may include 50% of the platform fee, making the client invoice KES 535. The merchant still remains responsible for the full platform fee owed to Citrus Labs Limited.

---

## 5.7 What does Business Centric mean?

Business Centric means the client invoice includes the full platform fee.

Example:
If the service price is KES 500 and the platform fee is KES 70, the client invoice becomes KES 570. The merchant still remains responsible for the full platform fee owed to Citrus Labs Limited.

---

## 5.8 Who configures platform service fee rules?

The Super Administrator configures platform service fee rules.

This includes platform-level fee settings that affect how fees are calculated, displayed, tracked, and reported.

---

## 5.9 Who configures the extra fee for preferred personnel waiting?

The Super Administrator configures the extra fee rules for preferred personnel waiting.

This feature allows a client to choose between waiting for the next available personnel or waiting for a specific preferred personnel at an extra cost.

---

## 5.10 How should preferred personnel fees appear?

Preferred personnel fees should be visible before confirmation and should appear clearly as a separate invoice line item.

Servana should show whether the fee affects:

* Merchant revenue
* Citrus platform fee
* Personnel commission
* Receipt display
* Audit records

This helps reduce confusion and disputes.

---

## 5.11 What is a platform fee ledger?

A platform fee ledger is a record of platform-related charges, payments, adjustments, due dates, overdue amounts, and outstanding balances.

For the Super Administrator, the platform fee ledger helps track merchant-to-platform billing activity and fee accountability.

---

## 5.12 What should the Super Administrator review in platform fee records?

The Super Administrator should be able to review:

* Merchant name
* Billing cycle
* Platform fee amount
* Due date
* Payment status
* Overdue status
* Adjustments
* Partial payments
* Outstanding balance
* Related branches, where relevant
* Related invoices or statements
* Audit history
* Dispute or review notes, where applicable

---

## 5.13 Can the Super Administrator enforce billing restrictions?

Yes, where enabled by platform rules.

Billing enforcement may include reminders, restricted access, suspension, deactivation, or other status controls.

These controls should be used consistently and should be recorded in audit logs.

---

## 5.14 Why is billing visibility important?

Billing visibility reduces disputes between merchants and Citrus Labs Limited.

Clear fee records help merchants understand what was charged, why it was charged, when it became due, what has been paid, and what remains outstanding.

---

# 6. Permissions and User Roles

## 6.1 Why does Servana use roles and permissions?

Servana handles sensitive operational areas such as client records, invoices, payments, commissions, platform fees, branch access, and audit logs.

Roles and permissions help each user see and do only what is appropriate for their responsibilities.

---

## 6.2 What are the main account roles in Servana?

Servana may include the following roles:

* Super Administrator
* Merchant Administrator / Merchant Owner
* Merchant Branch account user
* Merchant Human Resource account user
* Merchant Finance account user
* Merchant Front Office account user
* Merchant Personnel account user
* Merchant Audit account user
* Client record or General End User, where enabled

Each role has a different purpose and access level.

---

## 6.3 What is the Super Administrator role?

The Super Administrator is the Citrus Labs Limited platform-owner role.

It oversees platform settings, merchants, platform fees, platform-level audit logs, suspicious usage, internal roles, feature flags, and platform-policy controls.

It is not a merchant operations role.

---

## 6.4 What is the Merchant Administrator role?

The Merchant Administrator is the merchant owner, manager, or authorized operator.

This role self-registers the business, completes first-time setup, creates branches, manages the merchant profile, views merchant-level performance, views platform fee records, and manages merchant-level controls within permitted boundaries.

---

## 6.5 What is the Merchant Branch account user role?

The Merchant Branch account user manages a specific branch.

This role may manage branch profile details, operating hours, services, pricing, queue settings, appointment controls, branch reports, invoices, receipts, payment records, branch day opening and closing, and branch audit logs.

This role should not create other branches or manage unrelated branches.

---

## 6.6 What is the Merchant Human Resource account user role?

The Merchant Human Resource account user manages staff within the assigned branch scope.

This role may invite staff, create staff profiles, manage employment details, assign roles, assign service eligibility, manage availability, suspend or deactivate staff where permitted, and maintain staff history.

This role should not manage staff in other branches unless explicitly assigned.

---

## 6.7 What is the Merchant Finance account user role?

The Merchant Finance account user manages offline payment validation and financial control within assigned scope.

This role may review invoices, validate or reject payment records, generate receipts after validation, review cash-up records, monitor payment disputes, view platform fee records where permitted, and export finance reports where allowed.

---

## 6.8 What is the Merchant Front Office account user role?

The Merchant Front Office account user handles client-facing daily operations.

This role may register clients, create walk-ins, manage appointments, select services, assign next available or preferred personnel, generate invoices, record offline payment details, and monitor daily branch activity.

Front Office should not validate payments unless the platform specifically grants that permission.

---

## 6.9 What is the Merchant Personnel account user role?

Merchant Personnel are the service providers.

They may view their own assignments, own queue, own appointments, own service history, own commission visibility, preferred personnel requests, and allowed client information needed for service delivery.

Merchant Personnel should not export client contact data.

---

## 6.10 What is the Merchant Audit account user role?

The Merchant Audit account user provides read-only oversight.

This role may view audit logs, role changes, branch changes, invoice history, payment validation logs, receipt logs, queue reassignment logs, preferred personnel fee logs, and suspicious activity flags.

Merchant Audit should not create, edit, or delete operational records.

---

## 6.11 Can one person have more than one role?

Yes, where the platform allows it and the merchant’s internal policy supports it.

However, combined roles should be assigned carefully. Giving one person too many permissions increases the risk of mistakes, fraud, weak accountability, and unclear audit responsibility.

---

## 6.12 Who assigns merchant-side roles?

Merchant-side roles are assigned by authorized merchant users according to the account structure.

For example:

* Merchant Administrator manages permitted merchant-level setup.
* Merchant Human Resource manages staff invitations and role assignment within branch scope.
* Super Administrator manages internal Citrus Labs Limited platform roles and platform-level controls.

---

## 6.13 Can users assign themselves higher permissions?

No. Users should not be able to self-escalate into higher-risk roles.

High-risk permission changes should be restricted, controlled, and audit-logged.

---

## 6.14 Why does branch scope matter?

Branch scope keeps access limited to the right business location.

A user assigned to one branch should not automatically access another branch’s clients, invoices, payments, staff, queues, appointments, reports, or audit records.

This supports data separation, operational accountability, and practical business control.

---

## 6.15 What should the Super Administrator check when reviewing permission issues?

The Super Administrator should check:

* User email
* User role
* Account status
* Role status
* Tenant assignment
* Branch assignment
* Suspension status
* Recent permission changes
* Login activity
* Audit logs
* Whether the user is trying to access the correct module

Most permission issues are caused by wrong role assignment, missing branch scope, inactive status, or attempting to access a module outside the user’s role.

---

# 7. Audit Records and Activity Logs

## 7.1 What are audit records?

Audit records are system records showing important actions performed inside Servana.

They help explain who did what, when it happened, what changed, and which account, branch, invoice, payment, user, or record was affected.

---

## 7.2 Why are audit records important?

Audit records help Servana and merchants maintain accountability.

They are useful for:

* Investigating mistakes
* Reviewing suspicious activity
* Tracking permission changes
* Understanding payment validation history
* Resolving invoice or receipt questions
* Reviewing branch activity
* Monitoring user access
* Supporting internal controls
* Preserving operational history

---

## 7.3 What activity should be logged?

Servana should log important activity such as:

* Login events
* Failed login attempts
* Role changes
* Permission changes
* Merchant status changes
* Branch status changes
* Service changes
* Queue changes
* Appointment changes
* Service session changes
* Invoice creation or voiding
* Payment recording
* Payment validation or rejection
* Receipt generation
* Refund records, where applicable
* Platform fee changes
* Feature flag changes
* Support-related actions
* Data export activity
* Unauthorized access attempts

---

## 7.4 What should an audit record contain?

A useful audit record should include:

* Actor
* Role
* Action
* Date and time
* Affected account
* Affected merchant
* Affected branch, where relevant
* Affected module
* Affected record
* Old value, where relevant
* New value, where relevant
* Status
* Severity
* IP address or technical context, where available
* Reason or note, where required

---

## 7.5 Can merchants edit audit logs?

No. Audit logs should not be editable by merchant users.

Audit records should be protected from ordinary user editing so they remain useful for accountability and investigation.

---

## 7.6 What are severity levels?

Severity levels help users understand the importance of an audit event.

Examples include:

* Info
* Low
* Medium
* High
* Critical

High or critical events may include role changes, payment validation changes, receipt generation, voids, unauthorized access attempts, branch access changes, suspicious export activity, or platform fee enforcement actions.

---

## 7.7 What should the Super Administrator monitor in audit records?

The Super Administrator should monitor:

* Suspicious login attempts
* Repeated failed access attempts
* High-risk permission changes
* Merchant status changes
* Payment validation irregularities
* Duplicate payment reference alerts
* Feature flag changes
* Platform fee adjustments
* Large or unusual exports
* Attempts to access unauthorized records
* Branch suspension or deactivation events
* Internal platform role changes

---

## 7.8 Can audit records help with support?

Yes. Audit records can help the support team understand what happened before, during, and after a reported issue.

For example, if a user reports that an invoice changed, support can review who changed it, when it changed, and what values were changed.

---

## 7.9 Can audit records help reduce staff disputes?

Yes. Clear activity records can reduce disputes over:

* Who served a client
* Who recorded a payment
* Who validated a payment
* Who generated a receipt
* Who changed a role
* Who reassigned a queue entry
* Who marked an appointment as cancelled or no-show
* Which personnel earned a commission

This is one of Servana’s core practical benefits.

---

## 7.10 What should happen when suspicious activity is found?

The Super Administrator should:

1. Review the affected user, merchant, branch, and module.
2. Check related audit records.
3. Restrict or suspend access where needed.
4. Preserve relevant records.
5. Contact the appropriate Authorized Support Contact or internal owner.
6. Escalate internally where security, billing, or platform integrity may be affected.

---

# 8. Support and Help Workflow

## 8.1 How can support be contacted?

Servana uses a lean email-based support workflow.

The Authorized Support Contact goes to the Help & Support page, clicks “Contact Support,” completes a structured support form, and submits it. The system then generates a pre-filled email to the support team with the submitted details.

---

## 8.2 Who is allowed to contact support?

Account-specific support is limited to the Authorized Support Contact for the customer or merchant account.

Unless otherwise configured or approved by Citrus Labs Limited, the Authorized Support Contact may be a Primary Administrator, Merchant Administrator, Human Resource Administrator, Organization Administrator, Tenant Administrator, Customer Administrator, or another designated account role.

---

## 8.3 Can every account user contact support directly?

No. Not every user should send account-specific support requests directly.

Restricting support to the Authorized Support Contact helps Servana confirm the affected account, avoid confusion, protect account information, and respond to the right person.

---

## 8.4 What can non-authorized users access on the Help & Support page?

Non-authorized users may access general help content where available, such as:

* Basic FAQs
* Self-service guidance
* General help information
* Practical explanations of common workflows

The structured Contact Support form for account-specific help may only be visible to the Authorized Support Contact.

---

## 8.5 What is the Help & Support page?

The Help & Support page is the in-platform area where users can find basic help information and where the Authorized Support Contact can submit structured support requests.

It is intentionally simple.

---

## 8.6 What does the Help & Support page contain?

The Help & Support page may contain:

* Basic FAQs
* Help information
* Self-service guidance
* A structured support request form visible only to the Authorized Support Contact

It does not need to include a full ticketing system, escalation dashboard, live chat, telephone support, or real-time support channel.

---

## 8.7 Is there a ticketing system?

There is no mandatory ticketing system.

Servana’s support workflow is designed around a simple form-to-email process. Citrus Labs Limited may add a ticketing system later, but the Help workflow does not depend on one.

---

## 8.8 Is there an escalation dashboard?

There is no mandatory escalation dashboard.

Support follow-up is handled by email unless Citrus Labs Limited configures another approved workflow.

---

## 8.9 Is there live chat?

There is no mandatory live chat.

Support is handled through the Help & Support workflow and email-based follow-up.

---

## 8.10 Is there telephone support?

There is no mandatory telephone support.

Citrus Labs Limited may choose to provide telephone support in specific cases, but the standard support workflow is email-based.

---

## 8.11 Is support real-time?

No real-time response is guaranteed by the basic Help & Support workflow.

The workflow is designed to collect the right details, identify the affected account, and allow the support team to respond by email.

---

## 8.12 How does the support form work?

The workflow is:

1. The Authorized Support Contact opens the Help & Support page.
2. The Authorized Support Contact clicks “Contact Support.”
3. A structured support form appears.
4. The Authorized Support Contact completes the form.
5. The system generates a pre-filled email addressed to the support team.
6. The email includes the submitted details.
7. The support team receives the email.
8. The support team follows up by email with the Authorized Support Contact and/or the affected user.

---

## 8.13 What information should the support form request?

The support form should request at least:

* The affected customer, merchant, tenant, organization, or account.
* The affected user’s direct email address, or confirmation that the issue concerns the Authorized Support Contact’s own account.
* A clear description of the issue.
* The affected feature, workflow, module, transaction, record, integration, or error message, where relevant.
* Timestamp, browser, device, screenshot, or reference information, where helpful.
* Confirmation that the Authorized Support Contact is authorized to disclose the submitted information.

---

## 8.14 Who does the support team follow up with?

The support team follows up with the Authorized Support Contact and/or the specified affected user by email.

This allows support to help the person experiencing the issue while keeping the account’s authorized contact informed where needed.

---

## 8.15 Can support contact the affected user directly?

Yes. The support team may contact the affected user directly by email where the support request identifies that user and the issue requires direct follow-up.

---

## 8.16 Why does Servana restrict account-specific support to Authorized Support Contacts?

This protects customers and Citrus Labs Limited.

It helps ensure that:

* Account information is shared only through approved channels.
* Support requests come from authorized people.
* Sensitive operational or payment-related information is not disclosed casually.
* The support team can identify the affected account faster.
* Merchants keep internal control over who raises support matters.

---

## 8.17 Can support requests from unauthorized users be refused?

Yes. Citrus Labs Limited may require the request to come from the Authorized Support Contact before handling account-specific support.

General help content may still be available to users through the Help & Support page.

---

## 8.18 Can Citrus Labs Limited ask for verification before responding?

Yes. The support team may ask for verification where the issue involves account access, permissions, payment records, user changes, data, exports, billing records, or other sensitive areas.

Verification helps protect account integrity.

---

## 8.19 What causes support delays?

Support may be delayed when the request is:

* Incomplete
* Unclear
* Submitted by an unauthorized user
* Missing the affected account
* Missing the affected user email
* Missing screenshots or timestamps where needed
* Missing invoice, receipt, payment, or reference details
* Describing several unrelated issues in one request
* Based on inaccurate or misleading information

Clear support submissions lead to faster handling.

---

# 9. Data and Privacy Basics

## 9.1 What types of data may Servana handle?

Servana may handle different kinds of data, including:

* Account Data
* Customer Data
* Business Data
* Personal Data
* Operational Data
* Transactional Data
* Usage Data
* Technical Data
* Support Data
* Audit Data
* Billing Data

The exact data depends on the features used by each merchant and the information submitted by users.

---

## 9.2 What is Account Data?

Account Data is information used to create, identify, manage, and secure a user or organization account.

Examples include:

* Name
* Email address
* Phone number
* Role
* Account status
* Merchant or branch assignment
* Login history
* Support contact designation

---

## 9.3 What is Customer Data?

Customer Data is information submitted to Servana by or on behalf of a merchant, customer, organization, administrator, authorized user, or end user.

It may include client records, service history, invoices, payment records, staff records, commissions, branch activity, notes, reports, and operational records.

---

## 9.4 What is Business Data?

Business Data is information about a merchant’s operations.

Examples include:

* Merchant profile
* Branch profile
* Service list
* Service pricing
* Operating hours
* Staff roster
* Invoice records
* Payment records
* Commission records
* Branch reports
* Platform fee records

---

## 9.5 What is Personal Data?

Personal Data is information that identifies or can reasonably identify a person.

Examples may include:

* Name
* Email address
* Phone number
* Staff profile details
* Client profile details
* Login details
* Payment references linked to a person
* Service history linked to a person

---

## 9.6 What is Support Data?

Support Data is information submitted or created during support handling.

It may include:

* Support request details
* User email
* Affected account
* Issue description
* Screenshots
* Error messages
* Device or browser details
* Follow-up emails
* Troubleshooting notes

Support Data helps Citrus Labs Limited understand, diagnose, and respond to support issues.

---

## 9.7 What data does the Super Administrator see?

The Super Administrator may see platform-level data needed for Servana administration, security, support, billing, monitoring, reporting, and governance.

This may include merchant details, platform fee records, usage indicators, status records, audit logs, suspicious activity alerts, and selected operational records where required for platform administration.

Super Administrator access should be used responsibly and only for proper platform purposes.

---

## 9.8 Who is responsible for data entered by merchants and users?

The merchant or customer is responsible for the data its users enter into Servana.

This includes making sure the data is accurate, lawful, relevant, authorized, and properly managed according to the merchant’s own business and regulatory obligations.

---

## 9.9 Does Citrus Labs Limited verify every record entered by merchants?

No. Citrus Labs Limited does not manually verify every client record, invoice, payment record, staff record, branch record, service entry, or note entered by merchants.

Servana provides tools for structured operations and audit visibility. Merchants remain responsible for their own entries, workflows, users, and business records.

---

## 9.10 Can data be used for security and service improvement?

Yes. Servana may use relevant data to support:

* Platform security
* Fraud prevention
* Abuse detection
* Troubleshooting
* Support handling
* Billing operations
* Analytics
* Service improvement
* Audit review
* Platform reliability
* Compliance with applicable platform policies

For full details, users should refer to Servana’s Privacy Policy and Data Policy.

---

## 9.11 Can data be processed across borders?

Yes. Because Servana is built for African service SMEs and may use technology providers, support tools, infrastructure, or integrations in different locations, data may be stored or processed across borders where permitted and where appropriate safeguards are used.

Customers using Servana across different countries should understand their own local data protection, employment, consumer, tax, records, and sector-specific obligations.

---

## 9.12 What privacy rights may users have?

Depending on applicable law and context, users or data subjects may have rights such as:

* Accessing their data
* Correcting inaccurate data
* Requesting deletion
* Restricting certain processing
* Objecting to certain processing
* Requesting portability
* Withdrawing consent where consent applies

The correct request route may depend on whether Citrus Labs Limited or the merchant is responsible for the relevant data in that context.

---

## 9.13 When should a user contact the merchant instead of Citrus Labs Limited?

A user should contact the merchant where the question concerns merchant-controlled records, such as:

* Client profile details
* Service history
* Appointment records
* Invoice records
* Receipt records
* Staff information
* Commission records
* Merchant-specific notes
* Branch-specific business records

Citrus Labs Limited may direct the user to the merchant where the merchant controls the relevant information.

---

## 9.14 Where can users find full privacy details?

Users should refer to Servana’s Privacy Policy and Data Policy for full details on data handling, privacy rights, data retention, customer responsibilities, and processing practices.

---

# 10. Security Basics

## 10.1 How does Servana protect accounts?

Servana uses a combination of access controls, authentication checks, role-based permissions, tenant separation, branch scope, activity logs, and platform monitoring.

Security is shared. Citrus Labs Limited protects the platform environment, while merchants and users must protect their own devices, emails, access decisions, and internal processes.

---

## 10.2 What is tenant isolation?

Tenant isolation means each merchant’s data is kept separate from other merchants.

A merchant should not be able to access, infer, edit, export, or enumerate another merchant’s data.

This is one of the most important security principles in Servana.

---

## 10.3 What is role-based access control?

Role-based access control means users receive access based on their assigned role.

For example:

* A Front Office user should manage daily client-facing work.
* A Finance user should validate payments.
* A Personnel user should view their own assignments.
* An Audit user should review records without editing them.
* A Super Administrator should manage platform-level controls.

---

## 10.4 What is permission-based access control?

Permission-based access control means specific actions are controlled separately.

For example, a user may be allowed to view invoices but not void invoices. Another user may be allowed to record payment details but not validate payments. Another may view reports but not export them.

This helps Servana avoid overly broad access.

---

## 10.5 Why does Servana restrict exports?

Exports can move data outside the platform.

Once data is exported, it may be saved, forwarded, printed, copied, or stored in places that Servana cannot control.

For this reason, exports should be permission-controlled, logged, scoped, and limited to authorized users.

---

## 10.6 What should Super Administrators protect?

Super Administrators should protect:

* Their email accounts
* Login sessions
* Devices
* Browsers
* Internal access procedures
* Platform role assignments
* Feature flag controls
* Billing settings
* Platform fee rules
* Merchant suspension controls
* Audit records
* Export permissions

A compromised Super Administrator account creates serious platform risk.

---

## 10.7 Are users allowed to share accounts?

No. Users should not share accounts.

Shared accounts make audit records unreliable because the platform can no longer clearly show which person performed an action.

Every user should have their own assigned account and role.

---

## 10.8 What should be done when a user leaves a merchant?

The merchant should remove, suspend, or deactivate that user’s access promptly.

Where the user had active sessions, pending Magic Links, branch access, or sensitive permissions, those should be invalidated or reviewed.

Historical records linked to the user should remain preserved for accountability.

---

## 10.9 What should be done when a Citrus Labs internal user leaves?

The Super Administrator or authorized internal administrator should promptly remove or disable the user’s internal platform access.

The review should include:

* Super Administrator access
* Internal support access
* Feature flag access
* Billing access
* Audit access
* Reporting access
* Infrastructure-related access, where applicable
* Active sessions
* API or integration credentials, where applicable

---

## 10.10 Can Servana guarantee that no security incident will ever happen?

No platform can honestly guarantee that.

Servana should use practical, commercially reasonable, and technically appropriate security measures. Users and merchants must also follow good security practices, including protecting email accounts, devices, browsers, passwords where used, and internal access controls.

---

# 11. Updates and Platform Changes

## 11.1 Can Servana change over time?

Yes. Servana may change as the product improves, as security needs evolve, as merchant needs become clearer, and as operational requirements change.

Updates may include new features, improved workflows, changed screens, new controls, security improvements, billing improvements, reporting changes, or performance upgrades.

---

## 11.2 Can Citrus Labs Limited add new features?

Yes. Citrus Labs Limited may add new features to improve Servana.

Examples include:

* Improved dashboards
* New reports
* Better audit views
* Additional payment record fields
* New branch controls
* Improved support workflows
* New permission controls
* Enhanced security checks
* Additional export governance
* Better commission visibility

---

## 11.3 Can features be changed or removed?

Yes. Features may be updated, restricted, replaced, redesigned, or removed where needed.

This may happen because of product improvements, security needs, operational concerns, merchant feedback, technical constraints, billing controls, supportability, or platform reliability.

---

## 11.4 Can features be temporarily unavailable?

Yes. Features may be temporarily unavailable during maintenance, upgrades, emergency fixes, infrastructure issues, security response, third-party service interruptions, or platform changes.

Where possible, Servana should keep communication clear and practical.

---

## 11.5 How should Super Administrators handle platform changes?

Super Administrators should:

* Understand what is changing.
* Review which merchants or users may be affected.
* Check feature flags and access rules.
* Review billing or fee impact.
* Monitor support requests after rollout.
* Watch audit logs for unusual activity.
* Keep internal teams aligned.
* Communicate clearly where merchant-facing impact is expected.

---

## 11.6 Can platform changes affect merchant workflows?

Yes. Changes may affect how merchants manage clients, services, invoices, payments, permissions, reports, commissions, or support requests.

For this reason, major workflow changes should be planned carefully and communicated in simple language.

---

## 11.7 How should Help content be updated?

Help content should be updated when:

* A feature changes.
* A workflow changes.
* A role gains or loses access.
* A support process changes.
* A security rule changes.
* A billing or platform fee explanation changes.
* A common support issue needs clearer guidance.

The Help content should stay warm, organized, professional, and easy to understand.

---

## 11.8 How should legal documents be referenced in Help content?

Help content should not repeat full legal terms.

A simple reference is enough:

“For full details on platform use, data handling, privacy, and customer responsibilities, please refer to Servana’s Terms of Service, Data Policy, and Privacy Policy.”

This keeps the Help page useful without making it feel like a contract.

---

# 12. Contact Information

## 12.1 Who should users contact for support?

Account-specific support should be submitted through the Help & Support workflow by the Authorized Support Contact for the relevant customer, merchant, tenant, organization, or account.

This helps Citrus Labs Limited identify the affected account and respond through the correct email channel.

---

## 12.2 What is Citrus Labs Limited’s contact information?

Citrus Labs Limited
P.O. Box 23983 - 00100
Nairobi, Kenya
Email: [support@citruslabs.co.ke](mailto:support@citruslabs.co.ke)

---

## 12.3 Can users email Citrus Labs Limited directly?

Direct email may be used for general communication, but account-specific support may be restricted to the Authorized Support Contact and the approved Help & Support workflow.

This protects account information and helps the support team respond accurately.

---

## 12.4 What should a support email include?

A useful support email should include:

* Merchant or account name
* Branch, where relevant
* Affected user email
* Clear issue description
* Feature or module affected
* Invoice, receipt, payment, queue, appointment, or record reference, where relevant
* Error message, where applicable
* Screenshot, where helpful
* Date and time of the issue
* Browser and device details, where relevant
* Confirmation that the sender is authorized to submit the request

---

## 12.5 What should users do before contacting support?

Users should first check:

* Whether they are using the correct email.
* Whether their Magic Link has expired.
* Whether their account is active.
* Whether their role has access to the feature.
* Whether they are assigned to the correct branch.
* Whether the issue affects one user or many users.
* Whether the issue is caused by missing information, wrong status, or incomplete workflow.
* Whether the Help & Support page already answers the question.

Clear details help the support team respond faster and more accurately.

---

# Closing Note

Servana is built to help service businesses bring order, trust, and accountability into daily operations.

The Super Administrator role supports that mission at platform level. It gives Citrus Labs Limited the tools to manage platform settings, merchant visibility, platform fees, permissions, security signals, support context, and audit-ready records without interfering unnecessarily in merchant day-to-day operations.

For full details on platform use, data handling, privacy, and customer responsibilities, please refer to Servana’s Terms of Service, Data Policy, Privacy Policy, and other applicable platform documents.
