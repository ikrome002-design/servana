import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

/*
 | UI E2E coverage for branches and staff invitations
 | (Scope §3.3/§3.4, Plan §27 Phase 7).
 |
 | The SPA preview has no live backend, so the Sanctum and /api/v1 endpoints are
 | stubbed. These tests exercise the real frontend behavior for:
 |
 | - listing branches;
 | - creating a branch;
 | - updating branch operating hours;
 | - creating a staff invitation;
 | - accepting a public staff invitation;
 | - presenting a uniform invalid/revoked invitation error; and
 | - meeting WCAG 2.0 A/AA automated accessibility checks.
 |
 | The genuine backend behavior remains covered by the backend feature suite and
 | docs/proof/phase-7.md, including atomic invitation acceptance, membership and
 | profile creation, branch assignment, suspension-driven session revocation,
 | cross-merchant isolation, Magic Link integration, and Mailpit delivery.
 */

const CSRF_ROUTE = "**/sanctum/csrf-cookie";
const CURRENT_USER_ROUTE = "**/api/v1/me";
const BRANCHES_ROUTE = "**/api/v1/branches";
const OPERATING_HOURS_ROUTE = "**/api/v1/branches/*/operating-hours";
const STAFF_INVITATIONS_ROUTE = "**/api/v1/staff-invitations";
const STAFF_INVITATION_ACCEPT_ROUTE = "**/api/v1/staff-invitations/accept";

const OWNER = {
    id: "01J0000000000000000000USER",
    email: "owner@example.com",
    name: "Paul Nderitu",
    status: "active",
    email_verified_at: "2026-06-15T00:00:00+00:00",
    is_platform_staff: false,
} as const;

const MERCHANT = {
    id: "01J000000000000000000MERCH",
    name: "Servana Demo Salon",
    slug: "servana-demo-salon",
    status: "active",
    service_fee_tier: "split_tier",
    setup_completed_at: "2026-06-14T00:00:00+00:00",
} as const;

const MEMBERSHIP = {
    id: "01J00000000000000000MEMBER",
    role: "merchant_admin",
    status: "active",
} as const;

const BRANCH = {
    id: "01J0000000000000000BRANCH",
    name: "Kilimani Branch",
    code: "KIL001",
    address: null,
    town: "Nairobi",
    phone: null,
    email: null,
    business_category: null,
    status: "active",
    status_reason: null,
    archived_at: null,
} as const;

const ADMIN_PERMISSIONS = [
    "merchant.profile.manage",
    "merchant.tier.update",
    "branches.create",
    "branches.manage_users_lifecycle",
    "receipt.view",
    "periods.lock",
    "commissions.view",
    "platform_fees.view",
    "reports.view",
] as const;

const OPERATING_HOURS = [
    {
        weekday: 1,
        opens_at: "08:00",
        closes_at: "18:00",
        is_closed: false,
        break_start: null,
        break_end: null,
    },
] as const;

const CREATED_INVITATION = {
    id: "01J0000000000000000INVITE",
    email: "manager@salon.co.ke",
    role: "branch_manager",
    role_title: null,
    branch_id: BRANCH.id,
    status: "pending",
    resend_count: 0,
    expires_at: "2026-06-18T00:00:00Z",
    last_sent_at: null,
} as const;

const UNAUTHENTICATED_ERROR = {
    error: {
        code: "unauthenticated",
        message: "Unauthenticated.",
        fields: {},
        meta: {},
    },
} as const;

function adminBootstrap(): unknown {
    return {
        data: {
            user: OWNER,
            merchant: MERCHANT,
            membership: MEMBERSHIP,
            memberships: [MEMBERSHIP],
            permissions: ADMIN_PERMISSIONS,
            branch_ids: [],
            setup: {
                required: false,
                current_step: "done",
                completed_at: "2026-06-14T00:00:00+00:00",
            },
        },
    };
}

async function fulfillJson(
    route: Route,
    body: unknown,
    status = 200,
): Promise<void> {
    await route.fulfill({
        status,
        contentType: "application/json",
        body: JSON.stringify(body),
    });
}

/**
 * Removes previously registered bootstrap handlers before installing a new
 * authentication state. This is mainly used by the accessibility test, which
 * scans authenticated pages first and then scans the public acceptance page.
 */
async function clearBootstrapRoutes(page: Page): Promise<void> {
    await page.unroute(CSRF_ROUTE);
    await page.unroute(CURRENT_USER_ROUTE);
}

async function stubCsrfCookie(page: Page): Promise<void> {
    await page.route(CSRF_ROUTE, async (route) => {
        await route.fulfill({
            status: 204,
            body: "",
        });
    });
}

async function stubAdmin(page: Page): Promise<void> {
    await clearBootstrapRoutes(page);
    await stubCsrfCookie(page);

    await page.route(CURRENT_USER_ROUTE, async (route) => {
        await fulfillJson(route, adminBootstrap());
    });
}

async function stubLoggedOut(page: Page): Promise<void> {
    await clearBootstrapRoutes(page);
    await stubCsrfCookie(page);

    await page.route(CURRENT_USER_ROUTE, async (route) => {
        await fulfillJson(route, UNAUTHENTICATED_ERROR, 401);
    });
}

async function assertNoWcagViolations(page: Page): Promise<void> {
    const results = await new AxeBuilder({ page })
        .withTags(["wcag2a", "wcag2aa"])
        .analyze();

    expect(
        results.violations,
        results.violations
            .map(
                (violation) =>
                    `${violation.id}: ${violation.help} (${violation.nodes.length} node(s))`,
            )
            .join("\n"),
    ).toEqual([]);
}

test.describe("Branches + staff invitations UI", () => {
    test("merchant admin sees the branch list and the add-branch action", async ({
        page,
    }) => {
        await stubAdmin(page);

        await page.route(BRANCHES_ROUTE, async (route) => {
            await fulfillJson(route, {
                data: [BRANCH],
            });
        });

        await page.goto("/branch/list");

        await expect(
            page.getByRole("heading", {
                name: "Branches",
            }),
        ).toBeVisible();

        await expect(
            page
                .getByText("Kilimani Branch", {
                    exact: true,
                })
                .first(),
        ).toBeVisible();

        await expect(
            page
                .getByText("Add branch", {
                    exact: true,
                })
                .first(),
        ).toBeVisible();
    });

    test("merchant admin creates a branch", async ({ page }) => {
        await stubAdmin(page);

        await page.route(BRANCHES_ROUTE, async (route) => {
            if (route.request().method() === "POST") {
                await fulfillJson(
                    route,
                    {
                        data: BRANCH,
                    },
                    201,
                );

                return;
            }

            await fulfillJson(route, {
                data: [],
            });
        });

        await page.goto("/branch/create");

        await page.locator("#name").fill("Kilimani Branch");
        await page.locator("#code").fill("KIL001");
        await page.locator('button[type="submit"]').click();

        await expect(page).toHaveURL(/\/branch\/list\/?$/);
    });

    test("merchant admin updates branch operating hours", async ({ page }) => {
        await stubAdmin(page);

        await page.route(OPERATING_HOURS_ROUTE, async (route) => {
            await fulfillJson(route, {
                data: OPERATING_HOURS,
            });
        });

        await page.goto(`/branch/${BRANCH.id}/operating-hours`);

        await expect(
            page.getByRole("heading", {
                name: "Operating hours",
            }),
        ).toBeVisible();

        const saveRequest = page.waitForRequest((request) => {
            const pathname = new URL(request.url()).pathname;

            return (
                request.method() === "PUT" &&
                pathname === `/api/v1/branches/${BRANCH.id}/operating-hours`
            );
        });

        await page
            .getByRole("button", {
                name: "Save hours",
            })
            .click();

        await saveRequest;

        await expect(page).toHaveURL(
            new RegExp(`/branch/${BRANCH.id}/operating-hours/?$`),
        );
    });

    test("merchant admin invites a staff member", async ({ page }) => {
        await stubAdmin(page);

        await page.route(BRANCHES_ROUTE, async (route) => {
            await fulfillJson(route, {
                data: [BRANCH],
            });
        });

        await page.route(STAFF_INVITATIONS_ROUTE, async (route) => {
            if (route.request().method() === "POST") {
                await fulfillJson(
                    route,
                    {
                        data: CREATED_INVITATION,
                    },
                    201,
                );

                return;
            }

            await fulfillJson(route, {
                data: [],
            });
        });

        await page.goto("/hr/invitations");

        await page.locator("#email").fill("manager@salon.co.ke");
        await page.locator("#branch_id").selectOption(BRANCH.id);
        await page.locator("#role").selectOption("branch_manager");

        await page
            .getByRole("button", {
                name: "Send invitation",
            })
            .click();

        await expect(
            page
                .getByText("manager@salon.co.ke", {
                    exact: true,
                })
                .first(),
        ).toBeVisible();
    });

    test("an invitee accepts their invitation", async ({ page }) => {
        await stubLoggedOut(page);

        await page.route(STAFF_INVITATION_ACCEPT_ROUTE, async (route) => {
            await fulfillJson(
                route,
                {
                    message: "Your account is ready.",
                },
                201,
            );
        });

        await page.goto("/staff/accept?token=good-token");

        await page.locator("#first_name").fill("Amina");
        await page.locator("#last_name").fill("Mwangi");
        await page.locator("#phone").fill("+254700111222");

        await page
            .getByRole("button", {
                name: "Accept invitation",
            })
            .click();

        await expect(page.getByTestId("accept-success")).toBeVisible();
    });

    test("a revoked or invalid invitation token shows a uniform error", async ({
        page,
    }) => {
        await stubLoggedOut(page);

        await page.route(STAFF_INVITATION_ACCEPT_ROUTE, async (route) => {
            await fulfillJson(
                route,
                {
                    error: {
                        code: "invalid_or_expired_invitation",
                        message:
                            "This invitation is invalid, expired, or no longer available.",
                        fields: {},
                        meta: {},
                    },
                },
                422,
            );
        });

        await page.goto("/staff/accept?token=dead-token");

        await page.locator("#first_name").fill("Amina");
        await page.locator("#last_name").fill("Mwangi");
        await page.locator("#phone").fill("+254700111222");

        await page
            .getByRole("button", {
                name: "Accept invitation",
            })
            .click();

        await expect(page.getByTestId("accept-error")).toBeVisible();
    });

    test("branch list, branch create, and invitation accept pages have no WCAG A/AA violations", async ({
        page,
    }) => {
        await stubAdmin(page);

        await page.route(BRANCHES_ROUTE, async (route) => {
            await fulfillJson(route, {
                data: [BRANCH],
            });
        });

        for (const path of ["/branch/list", "/branch/create"]) {
            await test.step(`axe scan: ${path}`, async () => {
                await page.goto(path);
                await expect(page.locator("body")).toBeVisible();
                await assertNoWcagViolations(page);
            });
        }

        await stubLoggedOut(page);

        await test.step("axe scan: /staff/accept?token=good-token", async () => {
            await page.goto("/staff/accept?token=good-token");
            await expect(page.locator("body")).toBeVisible();
            await assertNoWcagViolations(page);
        });
    });
});
