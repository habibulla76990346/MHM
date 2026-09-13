/**
 * The development gateway's "modal" (Addendum D).
 *
 * It takes no money, so it opens no window and reaches no network — it asks
 * which outcome to simulate and reports it. That is what makes the checkout
 * behaviour gate runnable offline and in CI: success, cancellation and refusal
 * are all reachable without a live merchant account.
 *
 * The outcome can be forced with `data-simulate` on the mount, which is how
 * the gate drives each path deterministically instead of clicking through a
 * third party's interface.
 */
import { COMPLETED, DISMISSED, REFUSED } from '../outcomes.js';

const OUTCOMES = { completed: COMPLETED, dismissed: DISMISSED, refused: REFUSED };

export default {
  open(config, mount) {
    return new Promise((resolve) => {
      const forced = OUTCOMES[mount?.dataset?.simulate];

      if (forced) {
        // A tick of delay, so the page's "opening…" state is real rather than
        // a frame nobody could observe.
        setTimeout(() => resolve(forced), 50);
        return;
      }

      resolve(window.confirm('Development gateway — simulate a successful payment?') ? COMPLETED : DISMISSED);
    });
  },
};
