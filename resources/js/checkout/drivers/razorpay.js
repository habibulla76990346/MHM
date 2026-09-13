/**
 * Razorpay's modal.
 *
 * ONE OF TWO PLACES IN THE BROWSER THAT MAY NAME A GATEWAY — the mirror of
 * `app/Domains/Payments/Adapters/`. Everything above it speaks in outcomes.
 *
 * The handler is called on success and `ondismiss` when the customer closes
 * the window; a failure event arrives separately. All three are translated
 * into the vocabulary the page understands, and none of them is treated as
 * proof of anything: the page hands the result to the server, which asks
 * Razorpay directly.
 */
import { COMPLETED, DISMISSED, REFUSED } from '../outcomes.js';

export default {
  open(config) {
    return new Promise((resolve) => {
      const Checkout = window.Razorpay;

      if (typeof Checkout !== 'function') {
        resolve(REFUSED);
        return;
      }

      let settled = false;
      const once = (outcome) => {
        if (!settled) {
          settled = true;
          resolve(outcome);
        }
      };

      const instance = new Checkout({
        key: config.key,
        order_id: config.order_id,
        amount: config.amount,
        currency: config.currency,
        name: config.name,
        description: config.description,
        prefill: config.prefill || {},
        // Aziv AI's own page is what the customer came from and comes back
        // to; the modal opens over it.
        modal: { ondismiss: () => once(DISMISSED) },
        handler: () => once(COMPLETED),
      });

      instance.on?.('payment.failed', () => once(REFUSED));
      instance.open();
    });
  },
};
