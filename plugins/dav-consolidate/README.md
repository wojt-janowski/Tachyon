# DAV consolidation

Once per account, folds every CardDAV and CalDAV collection other than
`default` into `default` and removes it. Runs at `login.success` for accounts
on an allow list.

It exists because a mail migration left every account with two address books
and two calendars. Stalwart creates `default` itself; the other one came across
in the migration. Tachyon's contact sync picks one address book, and with two
whose names both carry an email suffix the pick is not stable between syncs. A
sync that landed on the empty one deleted every local contact as
deleted-elsewhere. The autoconfig plugin pins contacts to `default` so that
cannot recur; this plugin removes the cause.

## Why a login hook

Stalwart offers no administrative path to another account's collections, and
refuses to mint credentials on a user's behalf. The only client authenticated as
the user is the one inside their own session. So the account is consolidated
at its owner's next login, using the password the session already holds.

## Settings

| Key | Meaning |
|---|---|
| `allow_list` | Addresses or domains, comma or whitespace separated. Empty touches nobody. |
| `carddav_url` | CardDAV **base** URL for discovery, e.g. `https://mail.example.com/dav/card`. Empty leaves address books alone. |
| `caldav_url` | CalDAV **base** URL for discovery. Empty leaves calendars alone. |
| `apply` | Off: log what would happen. On: do it. |

## Rollout

1. Put one account on the allow list with `apply` off. Have them log in, then
   read the log under `dav-consolidate` for what would be copied and removed.
2. Turn `apply` on. Their next login does it and writes a `dav_consolidated`
   marker into their config storage, after which the plugin ignores them.
3. Widen the allow list to a domain.

Until the marker exists the plugin re-runs on every login. That is what makes
a dry run readable and what retries a copy that failed. Two PROPFINDs per half
per login is the cost.

## What it does, per redundant collection

1. List both sides.
2. Copy each item `default` lacks, with `If-None-Match: *` so nothing already
   there is ever overwritten. An item both hold keeps `default`'s version.
3. Re-list `default` and confirm every item from the old collection is there.
4. Only then `DELETE` the old collection.

A failed copy is logged, the collection stays, and the account is not marked
done. Nothing ever throws into the login path.

## Effect on Tachyon's own state

Contacts are pinned to `default`, so Tachyon never saw the old address book and
its removal changes nothing locally. Migrated contacts arrive as new remote
items on the next sync. The old calendar had its own local row; calendar sync
now retires a calendar whose collection has left the server, and the copied
events are pulled into the `default` calendar. That sync fix must be deployed
with, or before, this plugin.

## Manual path

```
php test/dav-consolidate.php <card|cal> <base-url> <user> <password> [--apply]
```

Dry run by default, for an account you would rather do by hand.
