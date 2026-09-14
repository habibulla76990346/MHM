# 03 — Database setup

## Creating it

### cPanel

1. **MySQL® Databases**.
2. Create a database. cPanel prefixes it with your account name — note the **full** name.
3. Create a user with a long password. Note the full username too.
4. **Add User To Database** → **ALL PRIVILEGES**.

### Command line

```sql
CREATE DATABASE aziv CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'aziv'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON aziv.* TO 'aziv'@'localhost';
FLUSH PRIVILEGES;
```

`utf8mb4` is not optional: it is what stores emoji, accented characters and most of the world's
scripts. `utf8` in MySQL does not.

## Filling it

**With migrations** (either route in [02](02-installation.md)) — the normal way. It creates the
schema for the version you are installing and nothing else.

**With the SQL export** — for hosting where you have phpMyAdmin and nothing else. The release
package ships `aziv-ai-<version>-schema.sql`. Import it, then open `/install` to finish. The
export contains the schema only: no accounts, no customer data.

## What goes wrong

| Symptom | Cause |
|---|---|
| "Access denied" | The username or password is wrong, or the user was never added to the database. On cPanel both names carry your account prefix |
| "Unknown database" | It has not been created yet |
| "Connection refused" | The host is wrong. On most shared hosting it is `localhost`, not an IP |
| Odd characters in names | The database is not `utf8mb4` |

## The privileges it needs

`SELECT`, `INSERT`, `UPDATE`, `DELETE`, `CREATE`, `ALTER`, `INDEX`, `DROP`, `REFERENCES`.
`DROP` and `ALTER` are needed for updates, which change the schema. Nothing needs `SUPER`,
`FILE` or the ability to create other databases.
