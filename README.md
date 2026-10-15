# MailTech — Email Integration & Diagnostics for GLPI

<img src="pics/mailtech.svg" alt="MailTech logo" width="72">

*Leia em português: [README.pt-BR.md](README.pt-BR.md).*

## MailTech Documentation

### 1. Overview

MailTech is a GLPI plugin that puts everything about outgoing e-mail on one screen, in plain language:

- **Sending**: GLPI's mail server, port, security, user and password, sender addresses and signature.
- **Testing**: "Test connection" and "Send test email" show the result step by step (connect, secure connection, sign-in, send) and say what to try when a step fails.
- **Rules and templates**: who is told about what on a ticket (assigned technicians and groups, requesters, observers, fixed addresses), with editable messages per language.
- **Queue and history**: what is waiting, what was sent, what failed and why; resend, cancel, delete.
- **Diagnostics**: checks with a suggested action, who changed what, export/import of the configuration.

MailTech has **no mail transport of its own**. Messages go into GLPI's native notification queue and leave through the server configured in GLPI. The interface is in Brazilian Portuguese, with English (en_GB, en_US) translations. This document quotes the labels as they appear with GLPI in English; the Portuguese labels are in [README.pt-BR.md](README.pt-BR.md).

MailTech 1.0 is the successor of **Techmail** (versions 1.0.0 and 1.1.0), and was briefly published as **MailBridge**. See [section 5](#5-upgrading-from-mailbridge-or-techmail) if you have either installed.

### 2. Supported GLPI version

| | |
|---|---|
| Tested | **GLPI 10.0.18** |
| Declared in `setup.php` | 10.0.0 up to (not including) 12.0.0 |
| Not tested | GLPI 11 (the step-by-step connection test relies on the mail library bundled with GLPI 10) |

### 3. Requirements

- PHP 7.4 or later declared; **only run on PHP 8.3** (8.3.35).
- No extra PHP extensions or libraries beyond what GLPI itself needs. Nothing to install with Composer or npm.
- GLPI's automatic action `queuednotification` must be running (a system cron calling `front/cron.php` is recommended). The Overview warns when it stops.
- "Log in files" switched on in GLPI, so the reason of each failed message can be recorded.

### 4. Installation

1. Copy the `mailtech` folder into GLPI's `plugins/` directory. The folder name must be exactly `mailtech`.
   From GitHub, inside `plugins/`: `git clone https://github.com/mateusoliveiraw/glpi-mailtech.git mailtech` (the repository is called `glpi-mailtech`, but GLPI needs the folder to be `mailtech`).
2. In **Setup > Plugins**, click **Install** and then **Enable** on MailTech.
   Command line: `php bin/console plugin:install mailtech --username=glpi` then `php bin/console plugin:activate mailtech`.
3. Log out and in again so the menu shows **Setup > MailTech**.

A new installation creates three example rules (one switched on). Installing a second time changes nothing.

### 5. Upgrading from MailBridge or Techmail

GLPI identifies a plugin by its folder name, so MailTech (`mailtech`) is a different plugin from MailBridge (`mailbridge`) and Techmail (`techmail`) as far as GLPI is concerned. The installer therefore **copies** the earlier plugin's data; it never moves or deletes it.

1. Back up the GLPI database and the `plugins/` folder.
2. Copy the `mailtech` folder into `plugins/`. **Leave the `mailbridge` or `techmail` folder where it is.**
3. In **Setup > Plugins**, click **Install** on MailTech. This:
   - copies rules, template translations, failure reasons, settings, per-profile permissions and the rules' change history from the earlier plugin;
   - **deactivates** the earlier plugin (it does not uninstall it), so each notice is not sent twice.
4. Click **Enable** on MailTech, then log out and in again.
5. Open **Setup > MailTech > Diagnostics**. The last card says what was brought over. Check your rules and templates, and send a test e-mail.
6. When you are satisfied, uninstall the earlier plugin in **Setup > Plugins** and remove its folder.

What to know:

- Works from MailBridge 1.0 and from Techmail 1.0.0 and 1.1.0.
- If a GLPI has data from both, the MailBridge data is used: it already contains what MailBridge brought over from Techmail.
- The copy runs once. Changes made in MailTech afterwards are not written back to the earlier plugin.
- One setting is deliberately not copied: "delete the data when uninstalling". In MailTech it always starts switched off.
- GLPI's mail server settings and the notification queue belong to GLPI and are not touched. E-mails sent under an earlier name are still recognised as MailTech's and stay in the "MailTech only" filter of Queue and history.
- Bookmarks to `/plugins/mailbridge/...` or `/plugins/techmail/...` stop working once the old folder is removed; the new address is `/plugins/mailtech/front/index.php`.
- The detailed record now goes to `files/_log/mailtech.log`; an earlier `mailbridge.log` or `techmail.log` is left as it is.
- Configuration files exported by MailBridge or Techmail can still be imported.
- If an earlier plugin is activated again while MailTech is active, MailTech pauses its own notices and the Overview says so.

### 6. Configuration

Everything is under **Setup > MailTech**.

1. **Sending**: fill in the server, port, connection security, user, password and the sender address, then **Save**. Tests always use the *saved* settings.
2. **Test connection**, then **Send test email**.
3. **Events and recipients**: switch each rule on or off; create rules; choose per entity; see GLPI's native notifications.
4. **Templates**: edit subject and message, insert variables with a click, preview, restore the default, send a test of that template.
5. **Queue and history** and **Diagnostics** for day-to-day follow-up.

Permissions: whoever can change GLPI's general configuration has full access. Other profiles get the **MailTech** tab on the profile form (*View*, *Manage*, *Test sending*, *Operate the queue*).

### 7. Supported e-mail transport methods

MailTech configures and tests what **GLPI's own mailer** offers:

| Method | Where it is configured | Status |
|---|---|---|
| SMTP without encryption | MailTech > Sending | Tested (local capture mailbox) |
| SMTP + STARTTLS, with or without user/password | MailTech > Sending | Tested (see section 8) |
| SMTP + SSL/TLS (implicit TLS) | MailTech > Sending | Implemented; a successful send was not tested |
| PHP `mail()` | MailTech > Sending | Implemented; not tested |
| SMTP + OAuth 2.0 (Google, Microsoft) | GLPI's native e-mail screen; MailTech shows the status and a shortcut | Not tested |

### 8. Microsoft 365 and Gmail limitations

Nothing below is a guarantee for your account: providers and organisations change their rules.

**Gmail**
- Server `smtp.gmail.com`, port 587, STARTTLS, the full address as user, sender equal to the account.
- Needs a Google **app password** (2-Step Verification on). The normal account password is refused. Some work, school and Advanced Protection accounts cannot create app passwords.
- Verified on 2026-10-13 with the same code under its previous name: two test messages accepted by Gmail's server. Not re-tested after the rename.
- Google sign-in (OAuth) through GLPI requires GLPI to have a public https address.

**Microsoft 365 (organisational accounts)**
- Server `smtp.office365.com`, port 587, STARTTLS. Port 465 is not offered by Microsoft.
- Verified from the test server: name lookup, connection, encryption and certificate. **Sign-in and delivery were not tested.**
- User-and-password sign-in only works if the organisation allows "Authenticated SMTP" for that mailbox and does not enforce security defaults; Microsoft has scheduled it for retirement. Otherwise use Microsoft sign-in (OAuth) on GLPI's native screen, which needs an app registered in the organisation.
- Sending as an address different from the account needs "Send As" permission.

**Outlook.com / Hotmail (personal accounts)**: not tested. User-and-password sign-in is reported as no longer accepted.

### 9. Troubleshooting

| What you see | What it means | What to do |
|---|---|---|
| "Gmail requires sign-in: the user and password are missing." (or Microsoft 365 / Outlook.com) | That provider always needs an account; none is saved | Enter the full account address as user, its password, the same address as sender; **Save**; test |
| "The sender "…" is not a real e-mail address." | With Gmail or Microsoft, the sender is a placeholder such as `admsys@localhost` | Enter the account address in "Sender email" |
| "The sending settings changed after the last successful test." | The server, account, password or sender changed after the last good test | Send a new test |
| "The mail server password has not been saved." | A user is saved but no password | Type the password and click **Save**, then test |
| "You changed the settings and have not saved them yet." | Tests use saved settings only | Click **Save**, test again |
| "The mail server did not accept the user name and password." | The provider refused the sign-in | Open "Technical detail": it carries the provider's own reason |
| Test says sent, nothing arrives | The server accepted the message | Check spam; check that the server is not a capture or relay-only server |
| Overview says "Needs attention" | One of the checks failed | Diagnostics lists each check with the action |
| "The old plugin (MailBridge) is active together with MailTech." (or Techmail) | Both plugins active | Deactivate the plugin it names |
| A person receives nothing | No e-mail on the user record, inactive user, or blocked by a list | Check Queue and history; in Sending, switch on "Record the reason for each notice created or skipped" |

### 10. Logging and diagnostics

- **Queue and history** shows the reason of each failed attempt (kept in the plugin's own table, because GLPI stores no per-message reason).
- `files/_log/mailtech.log`: why each notice was generated or skipped, when "Record the reason for each notice created or skipped" is on (in Sending).
- GLPI's own `files/_log/mail-error.log`, `mail.log` and `php-errors.log`.
- **Diagnostics > Who changed what** reads GLPI's change history.

No log, export, screen or error message contains passwords or tokens.

### 11. Security

- The server password is stored encrypted by GLPI, is never sent back to the browser, and is absent from exports, logs and history ("leave blank to keep").
- Tests run only against the saved server, need their own permission and are limited to 6 per minute per person.
- Every change goes through GLPI's CSRF protection and a permission check.
- Templates are cleaned when saved and when sent; only a fixed list of variables is replaced; line breaks are stripped from subject and address fields.
- Uninstalling does not delete data unless the option in Diagnostics is switched on.

### 12. Backup and rollback

- **Before installing or upgrading**: back up the database and `plugins/`.
- **Settings backup**: Diagnostics > Export (a JSON file without passwords).
- **Roll back from MailTech to MailBridge (or Techmail)**: deactivate MailTech, activate the earlier plugin. Its data was never changed by the upgrade; changes made in MailTech afterwards are not carried back.
- **Remove MailTech completely**: switch on "Delete MailTech data when the plugin is uninstalled" in Diagnostics, then uninstall. E-mails already in GLPI's queue are never touched.

### 13. Known issues

- GLPI 11 is not tested.
- OAuth is configured on GLPI's native screen, not inside MailTech.
- If you change the server and leave the password blank, the previously saved password is kept and would be offered to the new server. Type the new password, or tick "Delete the stored password".
- The Overview judges the settings; it cannot know whether the provider will accept the sign-in. Use the test buttons. It recognises Gmail and Microsoft by server name only (`smtp.gmail.com`, `smtp.googlemail.com`, `smtp.office365.com`, `smtp-mail.outlook.com`); other servers are not required to have a sign-in or a public sender, so local test mailboxes keep working.
- After installing, users must log in again to see the menu entry and new permissions.
- Not included: sending limits per period, digest e-mails, provider presets, bounce handling.

### 14. Version history

See [CHANGELOG.md](CHANGELOG.md). In short: MailTech 1.0 is the first release under the new name and makes the Overview and the tests stricter about sign-in, sender and stale tests. Before that the plugin was called Techmail: Techmail 1.1.0 added the single-screen manager and Techmail 1.0.0 was the first version. The 1.0 code was briefly published as MailBridge before taking the name MailTech.

## Author and license

MailTech is created and maintained by **Mateus Oliveira** ([@mateusoliveiraw](https://github.com/mateusoliveiraw)). It is free software, distributed under the GNU General Public License, version 2 or (at your option) any later version; see [LICENSE](LICENSE).

Questions, problems and suggestions: [open an issue on GitHub](https://github.com/mateusoliveiraw/glpi-mailtech/issues).

## For maintainers

```
mailtech/
├── setup.php, hook.php   registration, hooks, install/upgrade (incl. import from MailBridge or Techmail)
├── front/, ajax/         single screen, actions, export
├── src/                  business rules (pure classes) and GLPI access; Screen/ per section
├── templates/            screens (Twig)
├── css/, js/, pics/      loaded only on the plugin's screens
└── locales/              pt_BR, en_GB, en_US
```

- Translations: source texts are Portuguese. After changing a text, update it in `locales/mailtech.pot` and the `.po` files, compile each `.po` into its `.mo` (for example with gettext's `msgfmt`), then clear GLPI's cache.
- Internal names: folder and identifier `mailtech`, namespace `GlpiPlugin\Mailtech`, tables `glpi_plugin_mailtech_*`, settings context `plugin:mailtech`, permission `plugin_mailtech`. The CSS prefix inside the plugin is `tm-` (not `mb-`, which Bootstrap uses for margins).
- The earlier names (`mailbridge`, `techmail`) appear only where an earlier installation is read: `src/LegacyImport.php` (the list of earlier names lives there), old export files (`src/Porter.php`), old queue entries (`src/Queue.php`), old change history (`src/Audit.php`) and the texts that name the earlier plugin.
