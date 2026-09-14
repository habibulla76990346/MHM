# 09 — Payment gateway configuration

You do not need a gateway to run Aziv AI. You need one to charge for it.

## Adding one

**Admin → Payment gateways → *(a gateway)* → Credentials.**

Sandbox and live are **separate sets of credentials**, held separately, and switching between them
is a deliberate act rather than a checkbox somebody flips by accident. Fill in sandbox first.

## The webhook — the step people forget

A customer's browser coming back from a payment page is not proof of payment; it is a browser.
The gateway tells you what really happened by calling your server.

1. Open **Admin → Payment gateways → *(a gateway)* → Webhook**. It shows the exact URL to paste.
2. Paste it into the gateway's own dashboard, and copy the signing secret it gives you back into
   Aziv AI.
3. Send a test event from the gateway's dashboard and check **Admin → Payment gateways → Events**.

Aziv AI settles a payment through **one handler reached three ways** — the browser returning, the
webhook, and a scheduled sweep that catches anything both of those missed. A replayed webhook
cannot grant a second month: four separate guards stop it. But if the webhook is never configured,
you are relying on the browser and the sweep alone, and a customer who closes the tab waits until
the next sweep.

**The webhook URL must be reachable from the public internet over HTTPS.** A gateway cannot call
`localhost`, and it will not call a self-signed certificate.

## Going live

Work through this in order:

1. Take a real payment in sandbox, end to end, from the customer's side.
2. Check the invoice, the credit ledger entry and the delivery log all recorded it.
3. Refund it from Admin, and check the credit note.
4. Enter the live credentials.
5. Paste the **live** webhook URL into the gateway's live dashboard — it is a different dashboard
   with a different secret.
6. Take one real payment, of the smallest amount your gateway allows, with your own card.

Step 6 is not optional. Everything before it proves the code; only step 6 proves the account.

## Currencies

A gateway accepts the currencies its own account is enabled for. Admin → Countries and currencies
is where you say what you present to customers; the gateway is where you find out what it will
actually take. A mismatch fails at the payment step, which is the worst place to find it.

## Refunds and reconciliation

- **Refunds** are issued from the payment record in Admin, and produce a credit note. An issued
  invoice is never edited — corrections are always a second document.
- **Reconciliation** runs on the schedule and asks the gateway directly about anything that looks
  unfinished. It needs the cron job from [06 — Queue and cron](06-queue-and-cron.md); without it,
  a payment that lost its webhook stays pending for ever.

## What is never stored

Card numbers never reach your server. The payment step happens on the gateway's own page or in
its own frame, and what comes back is a reference. Nothing in the billing code names any gateway
either — that is a rule with a test behind it, so a second gateway is an adapter and a row, not a
rewrite.
