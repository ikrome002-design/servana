import { describe, expect, it } from 'vitest';
import { ACCOUNT_HOSTS, ACCOUNT_KEYS } from '@/host/accountHosts.generated';
import { CONTENT_ACCOUNT_KEYS, loadGeneratedLanding } from '@/content/generated/index.generated';
import { LANDING_COMPOSITION_KEYS, loadLandingComposition } from '@/content/landing';
import { resolveCtas } from '@/content/landing/ctaResolver';
import {
  ctaSignature,
  navigationSignature,
  regionAnchorId,
  type LandingComposition,
} from '@/content/landing/landingContract';
import type { ContentAccountKey } from '@/content/generated/contentTypes.generated';

/**
 * Phase UI-06 — the eight landing compositions.
 *
 * §8.1 forbids "one generic content object with only the role title changed", which is a claim that
 * has to be PROVEN rather than asserted in a comment. Every account is therefore compared against
 * all seven others on each axis that is supposed to distinguish it.
 *
 * The trust-evidence and plan-access contracts are checked here too, because they are the two
 * places a landing page can publish something the product cannot support.
 */

async function all(): Promise<Map<ContentAccountKey, LandingComposition>> {
  const map = new Map<ContentAccountKey, LandingComposition>();
  for (const key of LANDING_COMPOSITION_KEYS) {
    map.set(key, await loadLandingComposition(key));
  }

  return map;
}

/** Every route the SPA registers, resolved the way the page resolves it. */
const KNOWN_ROUTES: Record<string, string> = {
  'auth.login': '/auth/login',
  'auth.register': '/auth/register',
  'staff.accept': '/staff/accept',
};

describe('the composition loader', () => {
  it('serves exactly the eight account keys the registry declares', () => {
    expect([...LANDING_COMPOSITION_KEYS].sort()).toEqual([...CONTENT_ACCOUNT_KEYS].sort());
    expect([...LANDING_COMPOSITION_KEYS].sort()).toEqual([...ACCOUNT_KEYS].sort());
  });

  it('fails closed on an unknown account key, never falling back to another account', async () => {
    await expect(
      loadLandingComposition('not_an_account' as unknown as ContentAccountKey),
    ).rejects.toThrow(/unknown account key/i);
  });

  it('returns a composition whose declared account matches the key requested', async () => {
    for (const key of LANDING_COMPOSITION_KEYS) {
      expect((await loadLandingComposition(key)).accountKey).toBe(key);
    }
  });
});

describe('account distinctness', () => {
  it('gives every account its own title, description, eyebrow and FAQ link label', async () => {
    const compositions = [...(await all()).values()];

    for (const field of ['documentTitle', 'metaDescription', 'heroEyebrow', 'faqLinkLabel'] as const) {
      const values = compositions.map((composition) => composition[field]);
      expect(new Set(values).size, `${field} is shared between accounts`).toBe(values.length);
    }
  });

  it('gives every account its own CTA contract', async () => {
    const ctas = [...(await all()).values()].map(ctaSignature);

    expect(new Set(ctas).size, 'two accounts share a CTA signature').toBe(ctas.length);
  });

  it('makes the COMBINATION of every distinguishing axis unique', async () => {
    /*
     * §14.2 requires a unique COMBINATION, not a unique value on every axis independently — and
     * that distinction is load-bearing here.
     *
     * Finance and Human Resource declare the same four navigation links (`Features`, `How It
     * Works`, `Security`, `FAQ`) because their approved source header sections declare the same
     * four. Forcing those apart would mean inventing navigation neither document asks for, which
     * §15.1 forbids. Recorded as UI06-NAV-001; every other axis differs.
     */
    const signatures = [...(await all()).values()].map((composition) =>
      [
        composition.documentTitle,
        composition.heroEyebrow,
        composition.trust.heading,
        composition.trust.items.map((item) => item.title).join(','),
        composition.planAccess.mode,
        composition.planAccess.heading,
        ctaSignature(composition),
        navigationSignature(composition),
      ].join('||'),
    );

    expect(new Set(signatures).size).toBe(signatures.length);
  });

  it('shares a navigation signature between no more than two accounts', async () => {
    // A guard on the recorded coincidence: one shared pair is the known source overlap, and a
    // third account joining it would mean the navigation had stopped following the sources.
    const counts = new Map<string, number>();
    for (const composition of (await all()).values()) {
      const signature = navigationSignature(composition);
      counts.set(signature, (counts.get(signature) ?? 0) + 1);
    }

    expect([...counts.values()].filter((count) => count > 2)).toEqual([]);
    expect([...counts.entries()].filter(([, count]) => count === 2)).toHaveLength(1);
  });

  it('gives every account its own trust evidence and plan-access treatment', async () => {
    const compositions = [...(await all()).values()];

    const headings = compositions.map((composition) => composition.trust.heading);
    expect(new Set(headings).size).toBe(headings.length);

    const evidence = compositions.map((composition) =>
      composition.trust.items.map((item) => item.title).join('|'),
    );
    expect(new Set(evidence).size).toBe(evidence.length);

    const planPoints = compositions.map((composition) => composition.planAccess.points.join('|'));
    expect(new Set(planPoints).size).toBe(planPoints.length);
  });

  it('gives every account a different compiled hero headline', async () => {
    // The strongest distinctness signal, and the one that comes from the approved source rather
    // than from anything this phase authored.
    const headlines: string[] = [];
    for (const account of CONTENT_ACCOUNT_KEYS) {
      const document = await loadGeneratedLanding(account);
      const hero = document.sections.find((section) => section.region === 'hero');
      headlines.push(hero?.markdown.split('\n')[0] ?? '');
    }

    expect(new Set(headlines).size).toBe(headlines.length);
  });
});

describe('trust evidence', () => {
  it('makes no customer claim and no metric claim anywhere', async () => {
    for (const [account, composition] of await all()) {
      for (const item of composition.trust.items) {
        expect(item.customerClaim, `${account}/${item.title}`).toBe(false);
        expect(item.metricClaim, `${account}/${item.title}`).toBe(false);
      }
    }
  });

  it('traces every item to a repository source', async () => {
    for (const [account, composition] of await all()) {
      for (const item of composition.trust.items) {
        expect(item.source, `${account}/${item.title}`).toMatch(/^(tests|docs|resources|app|config)\//);
        expect(item.sourceReference.length, `${account}/${item.title}`).toBeGreaterThan(8);
      }
    }
  });

  it('contains no quotation, attribution, rating or adoption figure', async () => {
    // §8.4: production must never display a fabricated quote, name, company, rating, user count,
    // adoption statistic or performance improvement. A factual statement dressed as a quotation
    // still reads as a customer saying it.
    const forbidden = [
      /[“”"]/, // any quotation mark
      /\b\d+\s*%/, // a percentage
      /\b\d[\d,]*\+?\s*(users|merchants|businesses|customers|salons)\b/i,
      /\b\d(\.\d)?\s*(\/\s*5|stars?|out of 5)\b/i,
      /\b(said|says|told us|according to)\b/i,
    ];

    for (const [account, composition] of await all()) {
      const text = [
        composition.trust.heading,
        composition.trust.intro,
        ...composition.trust.items.flatMap((item) => [item.title, item.detail]),
      ].join(' ');

      for (const pattern of forbidden) {
        expect(pattern.test(text), `${account}: trust evidence matched ${String(pattern)}`).toBe(false);
      }
    }
  });

  it('renders the compiled source section only where that section is already factual', async () => {
    // Human Resource's region-11 source is a factual trust statement UI-05 marked renderable.
    // Every other account's is either missing or an unverified customer quotation.
    for (const [account, composition] of await all()) {
      const document = await loadGeneratedLanding(account);
      const region = document.sections.find((section) => section.region === 'testimonials');

      if (composition.trust.mode === 'compiled_source_section') {
        expect(region?.renderPermitted, `${account} renders a section UI-05 forbade`).toBe(true);
      }
    }
  });

  it('never renders a section UI-05 marked non-renderable', async () => {
    for (const account of CONTENT_ACCOUNT_KEYS) {
      const document = await loadGeneratedLanding(account);
      const composition = await loadLandingComposition(account);
      const region = document.sections.find((section) => section.region === 'testimonials');

      if (region !== undefined && !region.renderPermitted) {
        expect(composition.trust.mode).toBe('approved_factual_alternative');
      }
    }
  });
});

describe('plan access', () => {
  it('states no amount and offers no purchase action on any account', async () => {
    for (const [account, composition] of await all()) {
      expect(composition.planAccess.showsAmount, account).toBe(false);
      expect(composition.planAccess.purchaseCta, account).toBe(false);

      const text = [composition.planAccess.heading, ...composition.planAccess.points].join(' ');
      expect(/\bKES\s*[\d,]/i.test(text), `${account} quotes an amount`).toBe(false);
      expect(/\b\d[\d,]*\s*(\/|per)\s*month\b/i.test(text), `${account} quotes a monthly rate`).toBe(false);
    }
  });

  it('never invents a generic pricing tier', async () => {
    // §8.5: no Free / Basic / Pro / Enterprise unless the approved current price authority has
    // exactly those tiers. It does not — the catalogue is configured at runtime.
    for (const [account, composition] of await all()) {
      const text = [composition.planAccess.heading, ...composition.planAccess.points].join(' ');
      for (const tier of ['Free tier', 'Basic plan', 'Pro plan', 'Enterprise plan']) {
        expect(text.includes(tier), `${account} invented the ${tier}`).toBe(false);
      }
    }
  });

  it('renders the compiled pricing section only when that section states no amount', async () => {
    for (const account of CONTENT_ACCOUNT_KEYS) {
      const composition = await loadLandingComposition(account);
      const document = await loadGeneratedLanding(account);
      const region = document.sections.find((section) => section.region === 'pricing');

      if (!composition.planAccess.renderCompiledSource) {
        continue;
      }

      expect(region?.presence, account).toBe('present_in_source');
      expect(region?.renderPermitted, account).toBe(true);
      expect(/\bKES\s*[\d,]/i.test(region?.markdown ?? ''), `${account} would render an amount`).toBe(false);
    }
  });

  it('records a reason for every piece of source content it withholds', async () => {
    for (const [account, composition] of await all()) {
      for (const entry of composition.planAccess.withheld) {
        expect(entry.what.length, account).toBeGreaterThan(10);
        expect(entry.reason.length, account).toBeGreaterThan(40);
      }
    }
  });

  it('withholds the Merchant Administrator amounts and explains why', async () => {
    const composition = await loadLandingComposition('merchant_administrator');

    expect(composition.planAccess.renderCompiledSource).toBe(false);
    expect(composition.planAccess.withheld).toHaveLength(1);
    expect(composition.planAccess.withheld[0].reason).toContain('subscription_plan_prices');
  });

  it('gives Super Administrator plan administration rather than pricing', async () => {
    const composition = await loadLandingComposition('super_administrator');

    expect(composition.planAccess.mode).toBe('platform_plan_administration');
    expect(composition.planAccess.heading).toBe('Plan access and administration');
  });

  it('offers merchant subscription plan access only to the account that buys one', async () => {
    for (const [account, composition] of await all()) {
      if (composition.planAccess.mode === 'merchant_subscription_plan_access') {
        expect(ACCOUNT_HOSTS[account].selfRegistration, account).toBe(true);
      }
    }
  });
});

describe('the CTA contract', () => {
  const renderedRegions = new Set([
    'hero', 'social_proof', 'problem', 'solution', 'features', 'how_it_works', 'benefits',
    'product_showcase', 'use_cases', 'testimonials', 'pricing', 'security', 'faq', 'final_cta',
  ]);

  it('resolves every declared CTA for every account, rejecting none', async () => {
    for (const [account, composition] of await all()) {
      const definition = ACCOUNT_HOSTS[account];
      const resolution = resolveCtas(
        composition.ctas,
        definition,
        (name) => KNOWN_ROUTES[name] ?? null,
        renderedRegions,
      );

      expect(resolution.rejected, `${account}: ${JSON.stringify(resolution.rejected)}`).toEqual([]);
      expect(resolution.resolved).toHaveLength(composition.ctas.length);
    }
  });

  it('exposes merchant self-registration on exactly one account', async () => {
    const registering: string[] = [];
    for (const [account, composition] of await all()) {
      if (composition.ctas.some((cta) => cta.kind === 'self_registration')) {
        registering.push(account);
      }
    }

    expect(registering).toEqual(['merchant_administrator']);
    expect(ACCOUNT_HOSTS.merchant_administrator.selfRegistration).toBe(true);
  });

  it('never offers invitation acceptance where the registry forbids it', async () => {
    for (const [account, composition] of await all()) {
      if (composition.ctas.some((cta) => cta.kind === 'invitation_acceptance')) {
        expect(ACCOUNT_HOSTS[account].invitationAcceptance, account).toBe(true);
      }
    }
  });

  it('never offers merchant registration on the Super Administrator page', async () => {
    const composition = await loadLandingComposition('super_administrator');

    expect(composition.ctas.some((cta) => cta.kind === 'self_registration')).toBe(false);
    expect(composition.ctas.some((cta) => cta.routeName === 'auth.register')).toBe(false);
  });

  it('gives every account a way in and a same-host destination for it', async () => {
    for (const [account, composition] of await all()) {
      expect(composition.ctas.some((cta) => cta.kind === 'sign_in'), account).toBe(true);
      expect(composition.ctas.filter((cta) => cta.emphasis === 'primary'), account).toHaveLength(1);

      for (const cta of composition.ctas) {
        if (cta.kind === 'in_page_anchor') {
          expect(cta.anchorRegion, `${account}/${cta.key}`).toBeDefined();
          expect(renderedRegions.has(cta.anchorRegion as string), `${account}/${cta.key}`).toBe(true);
          continue;
        }
        expect(KNOWN_ROUTES[cta.routeName as string], `${account}/${cta.key}`).toBeDefined();
      }
    }
  });

  it('records an eligibility reason and a source for every CTA', async () => {
    for (const [account, composition] of await all()) {
      for (const cta of composition.ctas) {
        expect(cta.eligibilityReason.length, `${account}/${cta.key}`).toBeGreaterThan(20);
        expect(cta.sourceSection.length, `${account}/${cta.key}`).toBeGreaterThan(5);
      }
    }
  });

  it('writes every CTA label in sentence case', async () => {
    // Brand Identity, "Buttons": "Use sentence case. Good: Create invoice. Avoid: CREATE INVOICE."
    // The sources write "GET STARTED"; that is the documented reason the label is restyled.
    for (const [account, composition] of await all()) {
      for (const cta of composition.ctas) {
        expect(cta.label, `${account}/${cta.key}`).not.toBe(cta.label.toUpperCase());
      }
    }
  });
});

describe('in-page navigation', () => {
  it('points every navigation item at a region that renders', async () => {
    const renderable = new Set([
      'hero', 'social_proof', 'problem', 'solution', 'features', 'how_it_works', 'benefits',
      'product_showcase', 'use_cases', 'testimonials', 'pricing', 'security', 'faq', 'final_cta',
    ]);

    for (const [account, composition] of await all()) {
      expect(composition.navigation.length, account).toBeGreaterThan(2);
      for (const item of composition.navigation) {
        expect(renderable.has(item.region), `${account}: ${item.label} -> ${item.region}`).toBe(true);
      }
    }
  });

  it('produces a unique anchor per item, so no link is ambiguous', async () => {
    for (const [account, composition] of await all()) {
      const anchors = composition.navigation.map((item) => regionAnchorId(item.region));
      expect(new Set(anchors).size, account).toBe(anchors.length);
    }
  });
});
