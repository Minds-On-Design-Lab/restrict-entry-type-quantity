# Restrict Entry Type Quantity

Restricts how many entries of a given entry type may exist per site. Useful when entry types represent page designs and some pages (e.g. a Homepage) should only ever be built once.

Restrictions are controlled entirely from a config file — there is no control panel settings UI.

## Requirements

This plugin requires Craft CMS 5.10.0 or later, and PHP 8.2 or later.

## Installation

```bash
composer require mod-lab/craft-restrict-entry-type-quantity
php craft plugin/install restrict-entry-type-quantity
```

## Configuration

Create `config/restrict-entry-type-quantity.php` in your Craft project and map entry type handles to the maximum number of entries allowed:

```php
<?php

return [
    'restrictions' => [
        'homepage' => 1,
        'contactPage' => 1,
    ],
];
```

Multi-environment configs work like any Craft plugin config file:

```php
<?php

return [
    '*' => [
        'restrictions' => [
            'homepage' => 1,
        ],
    ],
    'dev' => [
        'restrictions' => [],
    ],
];
```

## How it works

When an entry type is at its limit, the plugin:

- removes it from the entry type options for new entries in the control panel, and
- blocks any save that would exceed the limit with a validation error on the entry — including saves via GraphQL, the console, or `Craft::$app->elements->saveElement()`.

### Semantics

- **Per site.** On multi-site installs, each site gets its own count — e.g. every site can have its own Homepage.
- **Only enabled entries count.** A disabled entry doesn't occupy a slot. To swap out a restricted page, disable (or delete) the existing one first — disabling is always allowed.
- **Drafts are free.** Editors can create and work on drafts of a restricted type without errors; the limit is enforced when the draft is applied/published. Revisions never count.
- **Editing the existing entry always works.** An entry never counts against itself, so re-saving the one allowed Homepage is fine.
- **Unlisted entry types are unlimited.**
- **Nested entries are ignored.** Entry types used inside Matrix/CKEditor fields aren't counted or restricted, even if their handle appears in the config.
- **Unknown handles are ignored silently**, since an entry type may only exist in some environments.
- **A limit of `0` effectively retires an entry type** — it's hidden from type options and can never be saved enabled again.

### Known limitations

- The "New entry" button on the entry index is per-section and can't be filtered per entry type, so in a section where every entry type is maxed out the button still appears. The editor can open a fresh draft, but publishing it is blocked with a clear validation error.
- Add limits **after** reconciling existing content. If a site already has more enabled entries of a type than the new limit, those extras keep working through bulk resaves, but re-saving one from the control panel will fail until the count is brought under the limit (by disabling or deleting extras).
