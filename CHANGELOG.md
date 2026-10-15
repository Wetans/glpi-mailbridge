# Changelog

## 1.0 — MailTech

First release as **MailTech** ("Email Integration & Diagnostics for GLPI"). It continues the **Techmail** plugin (Techmail 1.0.0 and 1.1.0, below) under a new name. It does the same job as Techmail 1.1.0, with the Overview and the tests stricter about sign-in, sender and stale tests.

Before this release, the same 1.0 code was published for a short time as **MailBridge** (folder `mailbridge`). MailTech treats a MailBridge installation like a Techmail one and imports its data.

### Changed

- New name everywhere a person can see it: plugin list, menu (Setup > MailTech), page titles, messages, test e-mails, profile tab, documentation.
- New technical identity, because GLPI derives it from the folder name: folder and identifier `mailtech`, namespace `GlpiPlugin\Mailtech`, tables `glpi_plugin_mailtech_*`, settings context `plugin:mailtech`, permission `plugin_mailtech`, translation domain `mailtech`, log file `mailtech.log`, assets `css/mailtech.css` and `js/mailtech.js`, queue event codes `plugin_mailtech_*`.
- A small mark (envelope and connection) and the tagline in the page header.

### Added

- **Import from an earlier installation** when MailTech is installed: from MailBridge 1.0 or, if there is none, from Techmail 1.0.0 or 1.1.0. Rules, template translations, failure reasons, settings, per-profile permissions and the rules' change history are copied. The earlier plugin is deactivated, not uninstalled, and none of its data is changed or deleted. The copy runs once and installing again changes nothing. The "delete the data when uninstalling" option is not inherited: in MailTech it starts switched off.
- If MailBridge or Techmail is active together with MailTech, MailTech pauses its own notices (so nothing is sent twice) and the Overview names the plugin to deactivate.
- Configuration files exported by MailBridge or Techmail are still accepted by Import.
- Queue history sent under an earlier name is still recognised and stays in the "MailTech only" filter.
- English documentation (`README.md`) next to the Portuguese one (`README.pt-BR.md`); logo in `pics/mailtech.svg`.

### Fixed

- A successful test only counts for the settings it was made with. Changing the server, port, security, account, password or sender (here or on GLPI's own e-mail screen) makes the Overview ask for a new test, instead of still saying that e-mails are going out normally.
- With Gmail or Microsoft (smtp.gmail.com, smtp.office365.com, smtp-mail.outlook.com) and no user, the Overview and the Sending screen show an error, and "Testar conexão" stops at the sign-in step without sending anything. The provider's own refusal ("Client not authenticated", "Authentication Required") is now explained as a missing sign-in, not as a wrong password.
- With Gmail or Microsoft, a sender that cannot exist on the internet (for example GLPI's default `admsys@localhost`) is an error on both screens, and the test e-mail is not sent.
- Opening a MailTech address while the plugin is deactivated shows GLPI's "item not found" page instead of failing with an error in `php-errors.log`.
- Installing a second time no longer logs "duplicate entry" database errors for the permission rows (GLPI's own helper inserts without checking; this was also present in Techmail 1.1.0).
- Uninstalling with the delete option also removes the change history of the deleted rules.

### Not changed

- GLPI's mail server settings, the notification queue and the automatic actions belong to GLPI and are not touched by the rename.
- The internal CSS prefix stays `tm-` (`mb-` is used by Bootstrap).

### Upgrade notes

- Addresses under `/plugins/mailbridge/` and `/plugins/techmail/` stop working when the old folder is removed.
- Users must log out and in again to see the new menu entry and permissions.
- See "Upgrading from MailBridge or Techmail" in the README, including how to roll back.

## Techmail 1.1.0

The plugin starts managing GLPI's whole e-mail notification mode on one screen.

- Single entry with six sections: Overview, Sending, Events and recipients, Templates, Queue and history, Diagnostics.
- Sending: on/off switch, server, port, security, user and password (stored encrypted by GLPI, never shown again), senders and signature.
- "Test connection" and "Send test e-mail" with a step-by-step result and advice; at most 6 tests per minute per person. When a user is saved without a password, or the screen has unsaved changes, the test says so instead of failing at sign-in.
- Rules: on/off switch per rule, "who receives what" summary, requesters and observers as recipients, per-entity on/off with inheritance, list of GLPI's native notifications.
- Templates: rich-text editor, one-click variables, preview, restore default, template test, plain-text version, translations per language.
- Queue and history: filters and search, reason of each failure, resend, cancel, delete, bulk actions, retry and clean-up policy, state of the automatic action, "process the queue now".
- Diagnostics: checks with a suggested action, who changed what, export/import without passwords, restore defaults.
- Fine-grained permissions on the profile form; block and allow lists; interface in pt_BR, en_GB and en_US; automated tests of the rule classes.
- Uninstalling no longer deletes data unless the option in Diagnostics is switched on.

## Techmail 1.0.0

First version: notification rules for technicians with editable messages, sent through GLPI's queue.
