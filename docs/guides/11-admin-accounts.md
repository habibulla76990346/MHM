# 11 — Admin accounts, passwords and recovery

**No administrator is ever seeded.** A shipped default account with a known password is the single
most common way a new installation is compromised in its first week, so Aziv AI has none. The first
administrator is created by you, either by the web installer's last step or by:

```sh
php artisan aziv:admin:create
```

The command prompts for name, email and password. The password is never echoed and never appears
in your shell history.

## Roles

Aziv AI ships four roles, and permissions are **deny by default** — a permission that is not
granted does not exist for that account.

| Role | For |
|---|---|
| **Super Admin** | You. Everything, including creating other administrators |
| **Admin** | Day-to-day operation: content, customers, providers, billing |
| **Support** | Reading customer records and helping; no configuration, no money |
| **Customer** | The people who use the product |

Give people the smallest role that lets them do their job, and give **Super Admin to as few people
as possible** — it bypasses individual permission checks by design.

## Two-factor authentication

Any administrator can turn it on from their own profile: scan the code with an authenticator app,
confirm one code, and save the **recovery codes** that are shown once and never again.

Recovery codes are stored hashed, not encrypted — nothing can read them back, including us. If you
lose both your phone and your codes, see *locked out* below.

**Admin → Settings → Security** has `Require two-factor for administrators`. Turn it on once every
administrator has enrolled, not before, or you lock out the ones who have not.

## Resetting a password when email does not work

This is the situation the panel cannot help with, because the panel is what you cannot reach.

**With SSH:**

```sh
php artisan aziv:admin:reset --email=you@yourdomain.com
```

It prompts for the new password twice, never echoes it, never accepts it as an option — an option
lands in your shell history and in `ps` — and never writes it to the audit log. It records **that**
a reset happened and to whom, which is the part worth keeping.

It refuses an address that is not an administrator's. Customers reset their own passwords from the
sign-in page.

**Without SSH,** the honest answer is that you need someone who can run a command. Most shared
hosts offer a "Terminal" entry in the control panel. Failing that, your host's support can run it.
A cron line will not do for this one — it prompts.

## Locked out completely

In order of preference:

1. **Another Super Admin** resets your password from Admin → Users.
2. **A recovery code**, if two-factor is what is blocking you.
3. **The command line.** All three recoveries are one command, and each one asks before it acts:

   ```sh
   php artisan aziv:admin:reset --email=you@yourdomain.com              # password
   php artisan aziv:admin:reset --email=you@yourdomain.com --clear-mfa  # lost your phone
   php artisan aziv:admin:reset --email=you@yourdomain.com --unlock     # suspended account
   ```

   The flags combine, and clearing two-factor does not silently reset the password as well — a
   lost phone and a forgotten password are different accidents.
4. **Direct database access as the last resort.** Blank the `mfa_secret` and `mfa_confirmed_at`
   columns for your row in `users`. Never edit the password column by hand — the hash format
   matters and a wrong value locks the account harder.

There is deliberately **no back door**, no master password and no support address that can let you
back in. That is a property, not an omission: anything that could let you in could let somebody
else in.

## What is recorded

Every administrator action that changes something records **who, what, when, and the before and
after values**. That includes creating and deleting accounts, changing roles, suspending a
customer, and every subscription change. The log cannot be edited from inside the application.
