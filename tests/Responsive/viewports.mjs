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
  { name: 'library',          path: '/library',   auth: true },

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
