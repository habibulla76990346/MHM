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
export const SCREENS = [
  { name: 'home', path: '/' },
];
