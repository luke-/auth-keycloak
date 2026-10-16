# Manual

See [INSTALLATION.md](INSTALLATION.md) to set up the Keycloak client and the module.

## Sign-in

A "Keycloak" button (title configurable in "Advanced settings") is added to the HumHub login page.

On the first sign-in with Keycloak:

- If a HumHub account is already linked to the Keycloak user, the user is signed in to this account.
- Otherwise, if a HumHub account has the same email, the Keycloak user is linked to it (unless this account is already linked to another Keycloak user).
  If **Use the email sent by Keycloak only if it is verified on Keycloak** is enabled, an unverified Keycloak email is never used for this.
- Otherwise, a new HumHub account is created (with the HumHub registration form if required profile fields are missing).

Users signed in with Keycloak are managed by the Keycloak user source: depending on the settings, some of their profile fields (username, email, first name, last name, mapped profile fields) cannot be changed on HumHub, and the HumHub password page is hidden.

## Attribute mapping

**Attribute mapping** maps Keycloak user attributes to the HumHub username and profile fields, one mapping per line:

```
username=preferred_username
phone_work=phoneNumber
city=locality
firstname=given_name
```

The left side is `username` or the internal name of a HumHub profile field (`Administration -> Users -> Profiles`), the right side is the Keycloak attribute:

- On sign in, it is the claim name sent by Keycloak (userinfo). On Keycloak, add a mapper of type "User Attribute" to the client's dedicated scope (`Clients -> <client> -> Client scopes -> <client>-dedicated`), with the same name for "User Attribute" and "Token Claim Name", and "Add to userinfo" enabled. Do not use dots in the claim name: Keycloak would send a nested claim.
- For the users sync, it is the name of the Keycloak user attribute. The standard claims `given_name`, `family_name`, `email`, `preferred_username` and `sub` can also be used.

Default mappings, overridden by a mapping line: `username=sub` (Keycloak ID), `firstname=given_name`, `lastname=family_name`.

Mapped profile fields are filled when the account is created, updated on each sign in and by the users sync, and cannot be changed on HumHub anymore (for accounts of the Keycloak user source).

The `username` line only sets the claim used for the username, e.g. `username=preferred_username` for the Keycloak username. The username is set on account creation, and updated (and locked on HumHub) only if **Update user's username on HumHub when changed on Keycloak** is enabled. It replaces the former "Username mapper" setting, migrated automatically on update.
 If a Keycloak attribute has several values, only the first one is used. The users sync empties a mapped field if the attribute was removed on Keycloak.

To display all the attributes of a Keycloak user and the resulting HumHub values, run `php protected/yii auth-keycloak/show-user <user>` (see [console commands](#console-commands)).

## Users sync

If **Fetch/Update users automatically** is enabled (requires the API access, see [INSTALLATION.md](INSTALLATION.md)), the HumHub users are synchronized with the Keycloak users every hour and right after saving the module configuration:

- **Created**: a HumHub account is created for each enabled Keycloak user not yet linked to a HumHub account, without admin approval.
  If the username is already taken on HumHub, a numeric suffix is added (e.g. `john.doe_2`).
- **Updated**: first name and last name, the profile fields of the [attribute mapping](#attribute-mapping), and the username and email if **Update user's username/email on HumHub when changed on Keycloak** are enabled.
  These fields cannot be changed on HumHub anymore.
- **Deactivated**: HumHub accounts of Keycloak users disabled or removed on Keycloak.
- **Re-enabled**: deactivated HumHub accounts of Keycloak users enabled again on Keycloak.

Only HumHub accounts of the Keycloak user source are managed. The following Keycloak users are skipped:

- users whose email is already used by another HumHub account (e.g. a local account with a password): they are linked on their first sign-in with Keycloak
- the API admin user and the service accounts
- disabled Keycloak users having no HumHub account

Good to know:

- If the Keycloak API returns an error or no users, the sync is aborted, so that no HumHub account is deactivated by mistake.
- A HumHub account deactivated by a HumHub admin is re-enabled by the next sync if the user is enabled on Keycloak. Disable the user on Keycloak instead.
- A HumHub account deleted on HumHub is created again by the next sync if the user is enabled on Keycloak, unless **Deactivate user's account on Keycloak when deleted on HumHub** is enabled (requires write access).
- Profile fields that are required on HumHub but not provided by Keycloak prevent the account creation: such users are counted as "failed" and a warning is logged.
- If **Synchronize groups and their members** syncs Keycloak towards HumHub, new accounts are also added to their groups.

### Console commands

From the HumHub root folder:

```bash
# Run the users sync now and display the results (created, updated, unchanged, enabled, disabled, skipped, failed)
php protected/yii auth-keycloak/sync-users

# List the Keycloak users and their linked HumHub account
php protected/yii auth-keycloak/list-users

# Show all the attributes of a Keycloak user and the resulting HumHub attributes (attribute mapping)
# <user>: HumHub username or email, or Keycloak ID, username or email
php protected/yii auth-keycloak/show-user <user>
```

## Groups sync

**Groups sync** synchronizes the HumHub groups and their members with the Keycloak groups having the same name. It does not create or update user accounts (see [Users sync](#users-sync)), and only members connected with Keycloak are synced: local members of a HumHub group are never removed.

- **Sync HumHub towards Keycloak**: done in real time. Groups created, renamed, moved, or deleted on HumHub, and members added or removed, are updated on Keycloak (groups are created on Keycloak if they do not exist). On the first sync (after saving the module configuration), all HumHub groups and their members are added to Keycloak.
- **Sync Keycloak towards HumHub**: done once a day, and on each sign-in for the groups of the signed-in user (members are only added on sign-in). Groups created, renamed, moved, or deleted on Keycloak, and members added or removed, are updated on HumHub.
- **Sync both ways**: both of the above.

Variants "but no removal on Keycloak/HumHub" never remove groups or members on the given side.

Subgroups: the Keycloak top-level groups are HumHub groups, and their subgroups are HumHub subgroups. As HumHub supports only one level of subgroups, deeper Keycloak groups are ignored. A group is linked to the group having the same name and the same parent on the other side; once linked, renaming or moving it keeps the link.

Deleting groups:

- When a group is deleted on Keycloak, its HumHub group is deleted (if removal on HumHub is enabled). The administrators group and protected groups are never deleted, only unlinked from Keycloak; a warning is logged.
- When a group is deleted on HumHub, its Keycloak group is deleted (if removal on Keycloak is enabled). Its subgroups become top-level groups on Keycloak, as on HumHub.

The administrators group is synced like the other groups: users added to the Keycloak group having its name become HumHub administrators.

## Read-only API

If **The API user or service account has only read permissions** is enabled, the settings requiring write access to Keycloak are disabled, and the groups sync is limited to "Sync Keycloak towards HumHub". This allows using an API user or service account having only the `view-realm`, `view-users` and `query-groups` roles.

## Other settings

- **Remove user's Keycloak sessions after logout**: signing out from HumHub also signs the user out from Keycloak.
- **Update user's username/email on Keycloak when changed on HumHub**: changes made on HumHub are sent to Keycloak.
  The username can only be updated if "Email as username" is disabled and "Edit username" is enabled in the Keycloak realm settings.
- **Add a page in account settings allowing users to change their Keycloak password**: this page does not ask for the current password, so anyone with access to an open session of the user can change the user's Keycloak password.
- **Deactivate user's account on Keycloak when deleted on HumHub**: the Keycloak account is disabled (not deleted) and its Keycloak sessions are removed. The API admin user is never disabled.
- **In admin, hide password fields in edit user form**: for administrators allowed to manage users.
- **Hide username field in registration form**: the username sent by Keycloak is used without asking the user. If it is the user's email, it is replaced by a username generated from the first and last name.

## Troubleshooting

- **"Authentication to Keycloak API failed!"**: check the error log (`Administration -> Information -> Logging`) and test the token request with curl (see [INSTALLATION.md](INSTALLATION.md#test-the-api-access)).
- **`Client not allowed for direct access grants`**: the admin user method is used but `Direct access grants` is disabled on the client. Enable it, or switch to the service account method.
- **`unauthorized_client` with the service account**: `Service accounts roles` is disabled on the client, or the client secret is wrong.
- **HTTP 403 from the Keycloak API**: the user or service account is missing roles of the `realm-management` client.
- **Users are not created by the sync**: check required HumHub profile fields, existing accounts with the same email, and that the HumHub cron jobs are running.
