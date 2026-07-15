# Release Notes for Restrict Entry Type Quantity

## [v1.0.0] - 2026-07-15

### Added

- Config-file-driven entry type quantity limits via `config/restrict-entry-type-quantity.php` (`restrictions` map of entry type handle => max entries per site)
- Validation that blocks saving an entry when its type is at the limit — enforced across control panel saves, draft publishing, duplication, GraphQL, and console saves
- Maxed-out entry types are hidden from the control panel's entry type options for new entries
- Per-site limits on multi-site installs; only enabled entries count, and drafts/revisions are always exempt
