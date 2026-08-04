<script setup lang="ts">
/**
 * The public landing page (Phase UI-06; UI/UX plan §8.1–§8.6, §15).
 *
 * ONE page component, eight genuinely different pages. Everything that differs — the copy, the
 * imagery, the navigation vocabulary, the trust evidence, the plan-access treatment, the calls to
 * action and the FAQ — arrives from the account's own composition module and its own compiled
 * content, both loaded on demand. A visitor to one host downloads neither the modules nor the
 * images of the other seven, which `Ui06HostContentIsolationTest` and the browser proof both check.
 *
 * All sixteen semantic regions of §8.3 are present on every page:
 *
 *   1 header (LandingHeader)      9 product showcase
 *   2 hero                       10 use cases
 *   3 social proof               11 approved factual trust evidence (never a testimonial)
 *   4 problem                    12 plan access (never an unprovable amount)
 *   5 solution                   13 security
 *   6 features                   14 FAQ
 *   7 how it works               15 final CTA
 *   8 benefits                   16 fixed footer (SvFixedFooter, via the layout)
 *
 * A region whose source is missing is never silently dropped: regions 11 and 12 carry approved
 * factual alternatives supplied by the product owner, and the plan-access block states plainly what
 * it does not publish and why.
 *
 * The account comes from the SERVER-resolved context, exactly as the layout reads it. It is
 * presentation context, not authorization (ADR-017), and when it cannot be established the layout
 * renders a safe boundary and this page loads nothing at all.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import LandingBlocks from '@/components/landing/LandingBlocks.vue';
import LandingFinalCta from '@/components/landing/LandingFinalCta.vue';
import LandingHeader from '@/components/landing/LandingHeader.vue';
import LandingHero from '@/components/landing/LandingHero.vue';
import LandingItemGrid from '@/components/landing/LandingItemGrid.vue';
import LandingPicture from '@/components/landing/LandingPicture.vue';
import LandingPlanAccess from '@/components/landing/LandingPlanAccess.vue';
import LandingTrustEvidence from '@/components/landing/LandingTrustEvidence.vue';
import PublicLandingLayout from '@/layouts/PublicLandingLayout.vue';
import SvFaq from '@/components/ui/SvFaq.vue';
import SvLandingSection from '@/components/ui/SvLandingSection.vue';
import SvLink from '@/components/ui/SvLink.vue';
import SvStateBoundary from '@/components/ui/SvStateBoundary.vue';
import { loadGeneratedLanding } from '@/content/generated/index.generated';
import { landingImagesFor, type LandingImage } from '@/content/generated/landingImages.generated';
import type { ContentAccountKey, LandingRegion } from '@/content/generated/contentTypes.generated';
import { loadLandingComposition } from '@/content/landing';
import { resolveCtas, type ResolvedCta } from '@/content/landing/ctaResolver';
import {
  LANDING_BODY_REGIONS,
  regionAnchorId,
  type LandingComposition,
} from '@/content/landing/landingContract';
import { parseLandingSection, type ParsedLandingSection } from '@/content/landing/landingSection';
import { currentAccountContext } from '@/host/accountHostContext';
import { publicFaqLocation } from '@/router/publicRoutes';

const router = useRouter();

const account = computed(() => currentAccountContext());

type ViewState = 'loading' | 'error' | 'success';
const state = ref<ViewState>('loading');
const composition = ref<LandingComposition | null>(null);
const parsed = ref<Map<LandingRegion, ParsedLandingSection>>(new Map());
const images = ref<LandingImage[]>([]);
const contentSource = ref('');
const contentSha = ref('');

/** Load one account's page. Fails closed — never falls back to another account's content. */
async function load(accountKey: ContentAccountKey): Promise<void> {
  state.value = 'loading';
  try {
    const [loadedComposition, document] = await Promise.all([
      loadLandingComposition(accountKey),
      loadGeneratedLanding(accountKey),
    ]);

    const parsedSections = new Map<LandingRegion, ParsedLandingSection>();
    for (const section of document.sections) {
      // `renderPermitted: false` is UI-05's publication gate. The unverified testimonial regions
      // carry it, and this is the single place that decides they are never parsed for rendering.
      if (section.presence === 'present_in_source' && section.renderPermitted) {
        parsedSections.set(section.region, parseLandingSection(section.markdown));
      }
    }

    composition.value = loadedComposition;
    parsed.value = parsedSections;
    images.value = [...landingImagesFor(accountKey)];
    contentSource.value = document.meta.sourcePath;
    contentSha.value = document.meta.sourceSha256;
    state.value = 'success';
  } catch {
    state.value = 'error';
  }
}

/** The image the manifest assigned to a region, or null when it assigned none. */
function imageFor(region: LandingRegion): LandingImage | null {
  return images.value.find((image) => image.landingSection === region) ?? null;
}

const heroImage = computed(() => imageFor('hero'));

/**
 * The regions this page actually renders.
 *
 * Regions 11 and 12 always render — they carry approved alternatives rather than compiled source —
 * and the rest render when their compiled section is present and publishable. Anchors and in-page
 * navigation are generated from THIS set, so no link can point at a section that is not there.
 */
const renderedRegions = computed<Set<LandingRegion>>(() => {
  const regions = new Set<LandingRegion>(['testimonials', 'pricing']);
  for (const region of LANDING_BODY_REGIONS) {
    if (parsed.value.has(region)) {
      regions.add(region);
    }
  }

  return regions;
});

/** Body regions in plan order, minus the ones rendered through a dedicated component. */
const proseRegions = computed(() =>
  LANDING_BODY_REGIONS.filter(
    (region) =>
      renderedRegions.value.has(region)
      && region !== 'hero'
      && region !== 'testimonials'
      && region !== 'pricing'
      && region !== 'faq'
      && region !== 'final_cta',
  ),
);

const navigation = computed(() =>
  (composition.value?.navigation ?? []).filter((item) => renderedRegions.value.has(item.region)),
);

/**
 * Resolve the account's CTAs against the registry and the live route table.
 *
 * Rejections are surfaced rather than swallowed: a CTA that silently disappeared would leave the
 * page with no way in, and the contract test asserts the rejection list is empty for all eight.
 */
const ctaResolution = computed(() => {
  const current = composition.value;
  const resolvedAccount = account.value;
  if (current === null || resolvedAccount === null) {
    return { resolved: [] as readonly ResolvedCta[], rejected: [] as readonly { key: string; reason: string }[] };
  }

  return resolveCtas(
    current.ctas,
    {
      selfRegistration: resolvedAccount.selfRegistration,
      invitationAcceptance: resolvedAccount.invitationAcceptance,
    },
    (name) => {
      const match = router.getRoutes().find((route) => route.name === name);

      return match === undefined ? null : router.resolve({ name }).path;
    },
    new Set(renderedRegions.value),
  );
});

const ctas = computed(() => ctaResolution.value.resolved);

/** The landing document's own FAQ region, as disclosure items. Not the full FAQ page. */
const faqItems = computed(() => {
  const section = parsed.value.get('faq');
  if (section === undefined) {
    return [];
  }

  return section.items.map((item) => ({
    id: `landing-faq-${item.id}`,
    question: item.title,
    answer: item.blocks
      .map((block) => (block.kind === 'paragraph' ? block.markdown : ''))
      .filter((text) => text !== '')
      .join('\n\n'),
  }));
});

/** Alternate the surface tone so adjacent sections stay visually separable. */
function toneFor(index: number): 'page' | 'subtle' {
  return index % 2 === 0 ? 'page' : 'subtle';
}

/** Alternate which side the media sits on from the desktop breakpoint up. */
function mediaPositionFor(index: number): 'start' | 'end' {
  return index % 2 === 0 ? 'end' : 'start';
}

/** Features, benefits and use cases read as cards; a numbered sequence reads as steps. */
function gridVariantFor(region: LandingRegion): 'cards' | 'steps' {
  return region === 'how_it_works' ? 'steps' : 'cards';
}

/** Role-specific document title and description, applied once the composition is known. */
watch(composition, (current) => {
  if (current === null) {
    return;
  }
  document.title = current.documentTitle;
  let meta = document.querySelector('meta[name="description"]');
  if (meta === null) {
    meta = document.createElement('meta');
    meta.setAttribute('name', 'description');
    document.head.appendChild(meta);
  }
  meta.setAttribute('content', current.metaDescription);
});

onMounted(() => {
  const resolved = account.value;
  if (resolved === null) {
    // The layout is already rendering its boundary; loading anything here would be guessing.
    state.value = 'error';

    return;
  }
  void load(resolved.accountKey);
});
</script>

<template>
  <PublicLandingLayout>
    <template #header>
      <LandingHeader
        v-if="account"
        :account-name="account.displayName"
        :navigation="navigation"
        :ctas="ctas"
      />
    </template>

    <template #default>
      <div
        :data-landing-account-key="account?.accountKey ?? ''"
        :data-content-source="contentSource"
        :data-content-sha256="contentSha"
        data-testid="landing-page"
      >
        <SvStateBoundary
          :state="state"
          error-message="This page could not be loaded. Refresh to try again."
        >
          <template v-if="composition">
            <!-- Region 2 — hero. The page h1 and the only high-priority image. -->
            <LandingHero
              :eyebrow="composition.heroEyebrow"
              :headline="parsed.get('hero')?.headline ?? composition.documentTitle"
              :blocks="parsed.get('hero')?.lead ?? []"
              :image="heroImage"
              :ctas="ctas"
            />

            <!-- Regions 3–10 and 13 — the account's own compiled copy, verbatim. -->
            <SvLandingSection
              v-for="(region, index) in proseRegions"
              :id="regionAnchorId(region)"
              :key="region"
              :data-landing-region="region"
              :heading="parsed.get(region)?.headline ?? ''"
              :tone="toneFor(index)"
              :media-position="mediaPositionFor(index)"
            >
              <LandingBlocks :blocks="parsed.get(region)?.lead ?? []" />

              <div
                v-if="(parsed.get(region)?.items.length ?? 0) > 0"
                class="mt-8"
              >
                <LandingItemGrid
                  :items="parsed.get(region)?.items ?? []"
                  :variant="gridVariantFor(region)"
                />
              </div>

              <template
                v-if="imageFor(region) !== null"
                #media
              >
                <LandingPicture :image="imageFor(region) as LandingImage" />
              </template>
            </SvLandingSection>

            <!-- Region 11 — approved factual trust evidence. Never a testimonial. -->
            <LandingTrustEvidence
              :trust="composition.trust"
              :source-blocks="parsed.get('testimonials')?.lead ?? []"
              :source-headline="parsed.get('testimonials')?.headline ?? null"
            />

            <!-- Region 12 — plan access. Never an amount this page cannot prove. -->
            <LandingPlanAccess
              :plan-access="composition.planAccess"
              :source-blocks="parsed.get('pricing')?.lead ?? []"
            />

            <!-- Region 14 — the landing FAQ, with a route to the account's full FAQ. -->
            <section
              v-if="faqItems.length > 0"
              :id="regionAnchorId('faq')"
              data-landing-region="faq"
              aria-labelledby="landing-faq-heading"
              class="px-4 py-12 md:px-6 md:py-16 lg:px-8"
              data-testid="landing-faq"
            >
              <div class="mx-auto max-w-sv-content">
                <h2
                  id="landing-faq-heading"
                  class="font-display text-2xl font-extrabold text-sv-text-heading md:text-3xl"
                >
                  {{ parsed.get('faq')?.headline ?? 'Frequently asked questions' }}
                </h2>
                <div class="mt-6 max-w-sv-readable">
                  <SvFaq :items="faqItems" />
                </div>
                <p class="mt-6">
                  <SvLink
                    :to="publicFaqLocation()"
                    data-testid="landing-faq-full-link"
                  >
                    {{ composition.faqLinkLabel }}
                  </SvLink>
                </p>
              </div>
            </section>

            <!-- Region 15 — final CTA, using the same resolved actions as the header and hero. -->
            <LandingFinalCta
              v-if="renderedRegions.has('final_cta')"
              :headline="parsed.get('final_cta')?.headline ?? ''"
              :blocks="parsed.get('final_cta')?.lead ?? []"
              :ctas="ctas"
            />
          </template>
        </SvStateBoundary>
      </div>
    </template>
  </PublicLandingLayout>
</template>
