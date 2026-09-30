# User administration

Only active platform administrators (`owner`) can create, edit or delete users.
The Users page edits name, email, role, club membership, activity and an optional
new password. Blank password on edit preserves the existing password. Creation,
editing and `cclub:admin` require at least 6 characters; longer unique passwords
are recommended. Client club owners use `representative`, not global `owner`.

Deletion requires explicit UI confirmation and is a soft deletion: authentication
is revoked, club membership removed and the account hidden from user lists.
Work history and foreign keys remain, including names on assigned tickets and
visits. The deleted email stays reserved. There is no public restore UI.

Administrators cannot delete, deactivate or demote themselves. Administrator
mutation transactions lock admin rows to prevent concurrent removal of the last
usable administrator.

Deploy the additive users.deleted_at migration before reopening the application.
Use a short maintenance window and back up the database and frontend assets.
Rollback can leave the column intact, but old code without SoftDeletes would show
deleted accounts again (they remain inactive); do not drop it without considering
this behavior.
