import AxeBuilder from "@axe-core/playwright";
import { expect, test, type Page, type Route } from "@playwright/test";

/*
 | Phase 21R-A — referral capture at merchant self-registration
 | (Plan §58A.1, §12.1 item 5, §58B.5 R-01/R-02; ADR-013).
 |
 | The SPA preview has no live backend, so /api/v1 is stubbed and the REQUEST BODY
 | is captured — which is precisely what matters here: the frontend's only job is
 | to hand the server a code and an honest capture channel, and never to display a
 | referrer identity or block a registration.
 |
 | Covered:
 |   - `?ref=` pre-fills the field and shows a dismissible "applied" notice;
 |   - the URL journey submits channel `query_param`, typing submits `manual_entry`;
 |   - a badly shaped code never blocks submission (server keeps invalid_format);
 |   - the code survives a server validation error;
 |   - no referrer identity is rendered anywhere;
 |   - the referred registration page passes axe (light + dark) at 360 / 768 / 1280,
 |     scrolls without a horizontal overflow, and is fully keyboard reachable.
 */

const CSRF_ROUTE = "**/sanctum/csrf-cookie";
const CURRENT_USER_ROUTE = "**/api/v1/me";
const SELF_REGISTER_ROUTE = "**/api/v1/merchant-registration/self-register";

const REFERRAL_CODE = "SERVANA-X8T2K";

const UNAUTHENTICATED_ERROR = {
    error: {
        code: "unauthenticated",
        message: "Unauthenticated.",
        fields: {},
        meta: {},
    },
} as const;

type CapturedBody = Record<string, unknown>;

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

async function stubLoggedOut(page: Page): Promise<void> {
    await page.route(CSRF_ROUTE, async (route) => {
        await route.fulfill({ status: 204, body: "" });
    });

    await page.route(CURRENT_USER_ROUTE, async (route) => {
        await fulfillJson(route, UNAUTHENTICATED_ERROR, 401);
    });
}

/** Stubs self-register and records the exact body the SPA sent. */
async function captureRegistration(
    page: Page,
    respond: (route: Route) => Promise<void> = async (route) =>
        fulfillJson(route, { message: "ok" }, 202),
): Promise<{ bodies: CapturedBody[] }> {
    const bodies: CapturedBody[] = [];

    await page.route(SELF_REGISTER_ROUTE, async (route) => {
        const raw = route.request().postData();
        bodies.push(raw === null ? {} : (JSON.parse(raw) as CapturedBody));
        await respond(route);
    });

    return { bodies };
}

async function fillRequiredFields(page: Page): Promise<void> {
    await page.locator("#owner_name").fill("Paul Nderitu");
    await page.locator("#business_name").fill("Servana Demo Salon");
    await page.locator("#email").fill("owner@example.com");
}

async function submitAndAwait(page: Page): Promise<void> {
    const response = page.waitForResponse(
        (r) =>
            r.request().method() === "POST" &&
            new URL(r.url()).pathname ===
                "/api/v1/merchant-registration/self-register",
    );

    await page.locator('button[type="submit"]').click();
    await response;
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

test.describe("Phase 21R-A referral capture", () => {
    test.beforeEach(async ({ page }) => {
        await stubLoggedOut(page);
    });

    test("?ref= pre-fills the code and shows a dismissible applied notice", async ({
        page,
    }) => {
        await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);

        await expect(page.locator("#referral_code")).toHaveValue(REFERRAL_CODE);

        const notice = page.getByTestId("referral-applied-notice");
        await expect(notice).toContainText(REFERRAL_CODE);

        await page.getByTestId("referral-dismiss").click();

        await expect(page.getByTestId("referral-applied-notice")).toHaveCount(0);
        // Dismissing the notice must not discard the referral itself.
        await expect(page.locator("#referral_code")).toHaveValue(REFERRAL_CODE);
    });

    test("a URL referral is submitted as the query_param channel", async ({
        page,
    }) => {
        const captured = await captureRegistration(page);

        await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);
        await fillRequiredFields(page);
        await submitAndAwait(page);

        await expect(page.getByTestId("register-success")).toBeVisible();

        expect(captured.bodies).toHaveLength(1);
        expect(captured.bodies[0]).toMatchObject({
            referral_code: REFERRAL_CODE,
            referral_channel: "query_param",
        });
    });

    test("a typed referral is submitted as the manual_entry channel", async ({
        page,
    }) => {
        const captured = await captureRegistration(page);

        await page.goto("/auth/register");
        await fillRequiredFields(page);
        await page.locator("#referral_code").fill("servana-x8t2k");
        await submitAndAwait(page);

        expect(captured.bodies[0]).toMatchObject({
            referral_code: "servana-x8t2k",
            referral_channel: "manual_entry",
        });
    });

    test("editing a pre-filled code downgrades the channel to manual_entry", async ({
        page,
    }) => {
        const captured = await captureRegistration(page);

        await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);
        await fillRequiredFields(page);
        await page.locator("#referral_code").fill("SERVANA-OTHER1");
        await submitAndAwait(page);

        expect(captured.bodies[0]).toMatchObject({
            referral_code: "SERVANA-OTHER1",
            referral_channel: "manual_entry",
        });
    });

    test("an unreferred registration sends no referral keys at all", async ({
        page,
    }) => {
        const captured = await captureRegistration(page);

        await page.goto("/auth/register");
        await fillRequiredFields(page);
        await submitAndAwait(page);

        expect(captured.bodies[0]).not.toHaveProperty("referral_code");
        expect(captured.bodies[0]).not.toHaveProperty("referral_channel");
    });

    test("a badly shaped code never blocks registration (R-02)", async ({
        page,
    }) => {
        const captured = await captureRegistration(page);

        await page.goto("/auth/register");
        await fillRequiredFields(page);
        await page.locator("#referral_code").fill("not-a-code");

        // Advisory hint, never a blocking error.
        await expect(page.getByTestId("referral-format-hint")).toBeVisible();
        await expect(page.locator('button[type="submit"]')).toBeEnabled();

        await submitAndAwait(page);

        await expect(page.getByTestId("register-success")).toBeVisible();
        expect(captured.bodies[0]).toMatchObject({ referral_code: "not-a-code" });
    });

    test("the referral code survives a server validation error", async ({
        page,
    }) => {
        await captureRegistration(page, async (route) =>
            fulfillJson(
                route,
                {
                    error: {
                        code: "validation_failed",
                        message: "The given data was invalid.",
                        fields: { email: ["The email field is required."] },
                        meta: {},
                    },
                },
                422,
            ),
        );

        await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);
        await page.locator("#owner_name").fill("Paul Nderitu");
        await page.locator("#business_name").fill("Servana Demo Salon");
        await submitAndAwait(page);

        await expect(page.getByTestId("register-success")).toHaveCount(0);
        await expect(page.locator("#referral_code")).toHaveValue(REFERRAL_CODE);
    });

    test("no referrer identity is displayed anywhere on the page", async ({
        page,
    }) => {
        await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);

        const body = ((await page.locator("body").innerText()) ?? "").toLowerCase();

        // Servana holds only the code and R&E's opaque attribution id, so there is
        // no referrer to name — and implying one would be a data-minimization defect.
        for (const forbidden of [
            "referred by",
            "referrer name",
            "your referrer",
            "reward",
        ]) {
            expect(body).not.toContain(forbidden);
        }
    });

    test("the referral field is keyboard reachable and labelled", async ({
        page,
    }) => {
        await page.goto("/auth/register");

        const input = page.locator("#referral_code");

        await expect(input).toHaveAttribute("id", "referral_code");
        await expect(
            page.locator('label[for="referral_code"]'),
        ).toContainText("Referral code");

        await input.focus();
        await expect(input).toBeFocused();
        await input.type("SERVANA-KEY01");
        await expect(input).toHaveValue("SERVANA-KEY01");

        // Tabbing forward from the referral field reaches the submit control.
        await page.keyboard.press("Tab");
        await expect(page.locator('button[type="submit"]')).toBeFocused();
    });

    for (const scheme of ["light", "dark"] as const) {
        for (const [label, width, height] of [
            ["mobile", 360, 740],
            ["tablet", 768, 1024],
            ["desktop", 1280, 900],
        ] as const) {
            test(`referred registration passes axe at ${label} in ${scheme} mode`, async ({
                page,
            }) => {
                await page.emulateMedia({ colorScheme: scheme });
                await page.setViewportSize({ width, height });
                await page.goto(`/auth/register?ref=${REFERRAL_CODE}`);

                await expect(page.getByTestId("referral-applied-notice")).toBeVisible();

                // No horizontal overflow at any breakpoint (Plan §§28-30).
                const overflows = await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth >
                        document.documentElement.clientWidth + 1,
                );
                expect(overflows).toBe(false);

                await assertNoWcagViolations(page);
            });
        }
    }

    test("the invalid-format hint passes axe in both themes", async ({ page }) => {
        for (const scheme of ["light", "dark"] as const) {
            await page.emulateMedia({ colorScheme: scheme });
            await page.goto("/auth/register");
            await page.locator("#referral_code").fill("not-a-code");
            await expect(page.getByTestId("referral-format-hint")).toBeVisible();
            await assertNoWcagViolations(page);
        }
    });
});
