# 10 — Tax configuration

**Tax is off until you switch it on**, and nothing is charged before you do. Aziv AI knows no tax
name, no rate and no code — every one of those is data you enter, checked by a scan that reads the
source for them. That is what lets the same build run in any jurisdiction.

**This guide describes the screens. It is not tax advice.** The rates, the registration threshold
and what is taxable where are for your accountant. Aziv AI's job is to apply what you were told
correctly and to keep an unalterable record of it.

## 1. Your business details

**Admin → Tax and compliance.**

Your legal name, address, registration number and the label you want it to appear under on an
invoice. These are copied onto every invoice at the moment it is issued, so changing them later
never alters a document already sent.

## 2. The rates

**Admin → Tax rules.**

A rule says: for customers *here*, buying *this*, add a component named *that* at *this* rate,
effective from *this date*. Several components can apply at once and each is named separately on
the invoice, because that is how a customer's own accountant reads it.

Add the rates your accountant confirms. Nothing is guessed and no default is shipped.

## 3. Switch it on

Back on **Admin → Tax and compliance**. Until this moment invoices carry no tax at all.

## What happens to invoices already issued

Nothing. An issued invoice is a **copy of its own computation** — supplier identity, customer
identity, every component's name, rate and amount, frozen at issue. Editing a rate, deleting a
rule, or changing your registered address cannot change a document a customer already has.

Corrections are **credit notes**, never edits. That is a legal position as much as a technical one.

## Customer tax identifiers

Where a rule needs one (a business customer's registration number, say), the customer enters it on
their own billing page and it is validated for shape, stored, and printed on the invoice. Aziv AI
does not call any government service to verify it.

## Numbering

Invoice numbers are **gap-free** and issued under a lock, so two customers checking out in the
same millisecond cannot take the same number and cannot leave a hole. Credit notes have their own
sequence. Both formats are configurable before you issue your first document, and neither should
be changed after.

## Before you charge anybody

- [ ] Business details entered exactly as they should appear on a legal document.
- [ ] Every rate your accountant gave you entered, with the right effective-from date.
- [ ] One sandbox purchase made, and the resulting invoice PDF read line by line by whoever signs
      your returns.
- [ ] Tax switched on.
