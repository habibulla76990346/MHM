// The six viewports every user-facing screen is checked at.
// Owner Addendum A: "Test important screens at mobile, tablet and desktop widths."
export const VIEWPORTS = [
  { name: 'xs-320',     width: 320,  height: 568,  class: 'mobile'  },
  { name: 'sm-375',     width: 375,  height: 812,  class: 'mobile'  },
  { name: 'sm-390',     width: 390,  height: 844,  class: 'mobile'  },
  { name: 'md-768',     width: 768,  height: 1024, class: 'tablet'  },
  { name: 'lg-1024',    width: 1024, height: 768,  class: 'desktop' },
  { name: 'xl-1440',    width: 1440, height: 900,  class: 'desktop' },
];

// Screens under test. Grows every phase as features land.
//
// `auth` signs in as the customer account; `admin` signs in as an
// administrator through the Admin Panel's own login form.
//
// Owner Addendum A applies to "EVERY user-facing page ... and the COMPLETE
// Admin Panel". Until Phase 2 the gate covered only customer screens, so the
// half of the requirement about the Admin Panel was asserted by nobody.
export const SCREENS = [
  { name: 'home',             path: '/' },
  { name: 'login',            path: '/login' },
  { name: 'register',         path: '/register' },
  { name: 'forgot-password',  path: '/forgot-password' },
  // A content page, rendered from every section type the seeder uses.
  { name: 'content-page',     path: '/p/home' },
  { name: 'dashboard',        path: '/dashboard', auth: true },
  { name: 'account',          path: '/account',   auth: true },
  { name: 'pricing',          path: '/pricing' },
  { name: 'billing',          path: '/billing',   auth: true },
  // Addendum A names this one explicitly: checkout has to work at 320px, or
  // the customers most likely to be on a phone cannot buy anything.
  { name: 'checkout',         path: '/checkout/__PLAN__', auth: true },
  // §17's customer side: collections, upload, and what each document's
  // status is. Seeded with a real indexed document so the list is not empty —
  // a gate run against an empty screen measures nothing.
  { name: 'library',          path: '/library',   auth: true },
  // §16's customer side: the studio form and the gallery. Seeded with a real
  // generation, because a gate run against an empty gallery never measures a
  // row action — the mistake that left every admin table unchecked for two
  // phases.
  { name: 'images',           path: '/images',    auth: true },
  // §22's in-app half. Everything the platform has told this customer.
  { name: 'notifications',    path: '/notifications', auth: true },
  // The renewal payment page (Addendum D §3). Its URL is SIGNED, so it cannot
  // be written here — it is found the way a customer finds it, from the link
  // on the billing page.
  { name: 'renewal',          path: '__RENEWAL__', auth: true },
  // Phase 9. The second-factor enrolment screen: a QR code, a code field and
  // a list of recovery codes — three things that are easy to make overflow at
  // 320px, on a page somebody uses exactly once and cannot skip.
  { name: 'mfa-setup',        path: '/two-factor/setup', auth: true },
  // The offline page. It is the one screen guaranteed to be seen on a phone
  // with a bad connection, and the one screen that cannot load a stylesheet
  // to fix itself if it is wrong.
  { name: 'offline',          path: '/offline' },

  // --- Admin Panel ---------------------------------------------------------
  { name: 'admin-login',      path: '/admin/login' },
  { name: 'admin-dashboard',  path: '/admin',               admin: true },
  { name: 'admin-health',     path: '/admin/system-health', admin: true },
  { name: 'admin-appearance', path: '/admin/appearance',     admin: true },
  { name: 'admin-branding',   path: '/admin/branding',       admin: true },
  { name: 'admin-pages',      path: '/admin/pages',          admin: true },
  // Addressed by SLUG, not id: Page overrides getRouteKeyName for the public
  // /p/{slug} route, and Filament resolves the admin record the same way.
  { name: 'admin-page-edit',  path: '/admin/pages/home/edit', admin: true },
  { name: 'admin-banners',    path: '/admin/banners',        admin: true },
  { name: 'admin-faqs',       path: '/admin/faqs',           admin: true },
  { name: 'admin-navigation', path: '/admin/navigation-items', admin: true },
  { name: 'admin-providers',  path: '/admin/ai-providers',    admin: true },
  // Phase 7 added two sections to this form, and the gate measures only what
  // is VISIBLE — so the mapping builder, which lives inside an empty repeater
  // behind an adapter choice, had to be opened before it could be checked.
  { name: 'admin-provider-new', path: '/admin/ai-providers/create', admin: true, prepare: 'customApiMapping' },
  { name: 'admin-models',     path: '/admin/ai-models',       admin: true },
  { name: 'admin-ai-console', path: '/admin/ai-test-console', admin: true },
  { name: 'admin-personas',   path: '/admin/personas',        admin: true },
  { name: 'admin-costs',      path: '/admin/usage-and-costs',  admin: true },
  { name: 'admin-routing',    path: '/admin/routing-and-health', admin: true },
  { name: 'admin-plans',      path: '/admin/plans',            admin: true },
  { name: 'admin-plan-edit',  path: '/admin/plans/create',     admin: true },
  { name: 'admin-coupons',    path: '/admin/coupons',          admin: true },
  { name: 'admin-invoices',   path: '/admin/invoices',         admin: true },
  { name: 'admin-tax',        path: '/admin/tax-and-compliance', admin: true },
  { name: 'admin-tax-rules',  path: '/admin/tax-jurisdictions', admin: true },
  { name: 'admin-currencies', path: '/admin/countries-and-currencies', admin: true },
  { name: 'admin-credits',    path: '/admin/customer-credits', admin: true },
  { name: 'admin-gateways',   path: '/admin/payment-gateways', admin: true },
  { name: 'admin-payments',   path: '/admin/payments',         admin: true },
  { name: 'admin-knowledge',  path: '/admin/knowledge-bases',           admin: true },
  { name: 'admin-knowledge-new', path: '/admin/knowledge-bases/create',  admin: true },
  { name: 'admin-announcements', path: '/admin/announcements',        admin: true },
  { name: 'admin-announcement-new', path: '/admin/announcements/create', admin: true },
  { name: 'admin-templates',  path: '/admin/notification-templates',    admin: true },
  { name: 'admin-template-new', path: '/admin/notification-templates/create', admin: true },
  { name: 'admin-notice-log', path: '/admin/notification-deliveries',   admin: true },
  { name: 'admin-media',      path: '/admin/images-and-voice',          admin: true },
  { name: 'admin-image-log',  path: '/admin/image-generations',         admin: true },
  { name: 'admin-voice-log',  path: '/admin/voice-jobs',                admin: true },
  // Phase 9. The screen an owner without SSH runs updates from — four task
  // cards and a log panel, all of which have to be reachable on a phone,
  // because "my site is broken" rarely happens while somebody is at a desk.
  { name: 'admin-maintenance', path: '/admin/maintenance',              admin: true },
];

/** Credentials for the seeded responsive-test account. */
export const TEST_USER = {
  email: process.env.RESPONSIVE_TEST_EMAIL || 'responsive@aziv.test',
  password: process.env.RESPONSIVE_TEST_PASSWORD || 'Responsive-Test-2026',
};

/** The seeded administrator the Admin Panel screens are checked as. */
export const TEST_ADMIN = {
  email: process.env.RESPONSIVE_TEST_ADMIN_EMAIL || 'responsive-admin@aziv.test',
  password: process.env.RESPONSIVE_TEST_ADMIN_PASSWORD || 'Responsive-Test-2026',
};
