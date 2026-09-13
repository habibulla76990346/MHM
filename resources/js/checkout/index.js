/**
 * The checkout page's behaviour (Addendum D §2).
 *
 * NOTHING HERE NAMES A GATEWAY. The server tells the page which DRIVER opens
 * this gateway's payment step, and the registry below maps that name to a
 * module — the browser's mirror of `app/Domains/Payments/Adapters/`, and the
 * reason the "no gateway name in checkout code" rule survives crossing into
 * JavaScript. Adding a gateway is an adapter and a driver file.
 *
 * WHY THIS FILE HAD TO EXIST. Until now the markup carried a Pay button, an
 * order id and a return URL, and nothing read any of it: the button did
 * nothing at all, on every gateway that opens a modal. The server side was
 * complete and tested; the last twenty lines were missing.
 *
 * THE PAGE NEVER DECIDES WHETHER A PAYMENT SUCCEEDED. A driver reports what
 * the customer's browser saw — completed, dismissed, refused — and the page
 * then asks the SERVER, which asks the gateway. A browser saying "paid" is a
 * claim, not evidence, and it is the one claim worth forging.
 */
import razorpay from './drivers/razorpay.js';
import fixture from './drivers/fixture.js';
import { COMPLETED, DISMISSED, REFUSED } from './outcomes.js';

const DRIVERS = { razorpay, fixture };

export { COMPLETED, DISMISSED, REFUSED };

/**
 * Load a gateway's script once, on the page that needs it.
 *
 * Not in the bundle and not on every page: a payment SDK loaded globally is a
 * third party present on the chat screen, watching a conversation it has no
 * part in.
 */
function loadScript(src) {
  return new Promise((resolve, reject) => {
    if (!src) {
      resolve();
      return;
    }

    const existing = document.querySelector(`script[data-checkout-sdk="${src}"]`);

    if (existing) {
      existing.dataset.loaded === 'yes'
        ? resolve()
        : existing.addEventListener('load', () => resolve(), { once: true });
      return;
    }

    const tag = document.createElement('script');
    tag.src = src;
    tag.async = true;
    tag.dataset.checkoutSdk = src;
    tag.addEventListener('load', () => {
      tag.dataset.loaded = 'yes';
      resolve();
    }, { once: true });
    tag.addEventListener('error', () => reject(new Error('sdk-unavailable')), { once: true });
    document.head.appendChild(tag);
  });
}

export function initCheckout(root = document) {
  const mount = root.querySelector('#checkout');

  if (!mount || mount.dataset.ready === 'yes') {
    return;
  }

  mount.dataset.ready = 'yes';

  const button = mount.querySelector('#pay-now');
  const status = mount.querySelector('[data-checkout-status]');
  const driver = DRIVERS[mount.dataset.driver];

  const say = (message, tone = 'info') => {
    if (!status) return;
    status.textContent = message;
    status.dataset.tone = tone;
    status.hidden = false;
  };

  if (!driver) {
    // A gateway whose driver is missing is a configuration fault, not
    // something to fail silently on: the customer sees a way to pay that
    // cannot work.
    say(mount.dataset.unsupportedMessage || 'This payment method is unavailable.', 'danger');
    button && (button.disabled = true);
    return;
  }

  const finish = (outcome) => {
    if (outcome === COMPLETED) {
      // The browser only reports what it saw. Settlement is the server's,
      // reached by handing it back the same return URL the gateway would use.
      window.location.assign(mount.dataset.return);
      return;
    }

    button.disabled = false;

    say(
      outcome === REFUSED
        ? mount.dataset.refusedMessage || 'The payment was not completed. Nothing has been charged.'
        : mount.dataset.dismissedMessage || 'Payment cancelled. Nothing has been charged.',
      outcome === REFUSED ? 'danger' : 'info',
    );
  };

  button?.addEventListener('click', async () => {
    button.disabled = true;
    say(mount.dataset.openingMessage || 'Opening the payment window…');

    let config = {};

    try {
      config = JSON.parse(mount.dataset.config || '{}');
    } catch {
      config = {};
    }

    try {
      await loadScript(mount.dataset.sdk || '');
    } catch {
      button.disabled = false;
      // The customer's network blocked the gateway's script, or the gateway
      // is down. Either way they can retry, and nothing was charged.
      say(mount.dataset.sdkErrorMessage || 'The payment window could not be opened. Please try again.', 'danger');
      return;
    }

    try {
      finish(await driver.open(config, mount));
    } catch {
      finish(REFUSED);
    }
  });
}
