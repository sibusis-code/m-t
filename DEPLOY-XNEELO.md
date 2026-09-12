# Putting M&T Academy on centenarynetworks.com/mtacademy

M&T Academy is the same application as SPS Academy, in a second folder on the same
Xneelo account, sharing one MySQL database. What keeps the two companies' learners apart
is a single line — `'tenant' => 'mt'` — in a configuration file that lives **outside
the web root** and is not in this repository.

The full reference, including how the hosting behaves and what to do when it does not,
is `DEPLOY-XNEELO.md` in the **SPS repository**. This file covers only what is different
about M&T.

---

## The one rule

`centenarynetworks.com` is a live client site, and `public_html/spsacademy/` is a
different company's learner records. We write to `public_html/mtacademy/` and nowhere
else. The deploy workflow refuses any other target, refuses the SPS directory by name,
and checks both neighbours are still answering afterwards.

---

## Where the code comes from

Do not edit the back end here. Every `.php` file except `lib/brand.php`, plus `lib/`,
`schema/`, `tools/`, `.htaccess`, `profile.js`, `pm-progress.js` and the marked block of
`styles.css`, is **written by the SPS repository**:

```
cd ../sps
php tools/sync-backend.php --check ../M-T     # what has drifted
php tools/sync-backend.php --apply ../M-T     # write it
```

An edit made here to any of those files is overwritten by the next sync, without warning
and without a merge. If M&T needs something SPS does not, it becomes a key in
`lib/brand.php` and a `brand('key')` in the shared page — added in SPS, synced back out.

**What is genuinely M&T's**, and is never touched by the sync:

- `lib/brand.php` — the company name, logo, contact details, form placeholders
- every `.html` page, `images/`, `resources/`
- `styles.css` outside the `SHARED ACADEMY STYLES` markers — the indigo palette
- this file, `README.md`, and `.github/`

---

## Three things that have to be done by hand

The code deploys itself; these do not. None of them can be done from a repository,
and none of the values may be pasted into a chat window or committed.

### 1. Repository secrets

GitHub → this repository → Settings → Secrets and variables → Actions:

| Secret | Value |
|---|---|
| `MT_FTP_SERVER` | `centenarynetworks.com` |
| `MT_FTP_USERNAME` | the Xneelo SFTP user |
| `MT_FTP_PASSWORD` | its password |

The same Xneelo account as SPS today. If Xneelo's panel will give a second FTP user whose
home is `public_html/mtacademy`, use that instead — then this repository's credential
physically cannot reach the client's site or SPS's academy, which is worth more than every
check in the workflow.

### 2. The configuration file on the server

Over SFTP, create `~/private/mtacademy-config.php` — **outside** `public_html`, and
`chmod 600`. Start from `lib/config.sample.php`.

The filename is not a convention; it is derived from the folder the site is installed in.
`public_html/mtacademy/` looks for `mtacademy-config.php` and will accept nothing
else. If it is missing the site refuses to start and says which file it wanted.

```php
'tenant'    => 'mt',               // NOT another academy's slug. This line is the entire separation.
'db'        => [ ... ],          // the SAME database, user and password as SPS
'ip_pepper' => '<its own>',      // NOT the same as SPS's
```

- **Same database.** One database, one row per company, a `tenant_id` on every table.
- **Different pepper.** Generate a fresh one with
  `php -r "echo bin2hex(random_bytes(32));"`. Sharing SPS's would make the same hashed
  address comparable across two companies' records, which is exactly what hashing it is
  meant to prevent.

### 3. Run the installer

Set `setup_token` in that file to a long random string, open
`https://centenarynetworks.com/mtacademy/setup`, run it — then **come back and empty
the token**. While it is empty, `/setup` and `/phpcheck.php` are plain 404s.

---

## Deploying

GitHub → Actions → **Deploy M&T Academy** → Run workflow. Pushing does not deploy.

Leave **dry run on** the first time and read the file list. Then run it again with dry run
off. Leave *delete removed* off.

After a live run the job checks, and fails the build on any of them:

- the client's homepage is byte-identical to before
- `/spsacademy/` still answers 200
- `/`, `/login`, `/forgot`, `/reset`, `/contact`, `/privacy`, `/styles.css` return 200
- `/my` and `/admin-users` **redirect** when signed out — a 200 there would mean the
  access control is not running
- `/account.php` answers with a session probe
- `/contact` says "M&T Academy" and does **not** carry SPS branding — this is the one
  that catches a wrong or missing configuration file, because that failure renders
  perfectly and simply shows the wrong company's data
- `/lib/*.php` and `/schema/*.sql` are not readable over HTTP

---

## Check before telling anyone

1. `https://centenarynetworks.com/mtacademy/` — the M&T homepage: indigo, with M&T's square logo in the nav.
2. `/mtacademy/contact` — send a real registration through it.
3. `/mtacademy/admin` — sign in, and confirm that registration is the **only** thing
   listed. If SPS's registrations appear, stop: the configuration file is wrong.
4. `/mtacademy/phpcheck.php` — should be a 404 once `setup_token` is empty.

---

## Known gaps

- **Password reset emails will not arrive yet.** `centenarynetworks.com`'s SPF record
  authorises Google, not Xneelo, so mail sent from the server is likely to be filtered.
  This affects a learner-facing feature, not just notifications. The fix is a DNS change
  at GoDaddy and it is outstanding for SPS too.
- **The phone number in `lib/brand.php` is a placeholder** — the same dummy number every
  academy started with. Nobody has supplied M&T's switchboard, and it must be replaced
  before anyone is told the site is finished, or a learner will dial it.
- **The company's registered name needs confirming.** `lib/brand.php` uses
  "M&T Development", which is how the company names itself on its own website. A copyright
  line and a privacy notice need the registered legal entity (a "(Pty) Ltd" form, say), and
  nobody has supplied it. One line to change once it is known.
- **The privacy notice is a draft** and names SPS's outstanding items too: each company's
  Information Officer, registration with the Information Regulator, and the QCTO retention
  period. It should be read by M&T before the site is announced.
