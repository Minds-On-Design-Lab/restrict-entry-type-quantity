<?php

namespace modlab\craftrestrictentrytypequantity\services;

use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\models\EntryType;
use modlab\craftrestrictentrytypequantity\RestrictEntryTypeQuantity;
use ReflectionProperty;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Counting, filtering, and validation logic for entry type quantity limits.
 */
class Restrictions extends Component
{
    /**
     * Returns the limit for an entry type, or null if it's unrestricted.
     */
    public function getLimit(EntryType $type): ?int
    {
        /** @var \modlab\craftrestrictentrytypequantity\models\Settings $settings */
        $settings = RestrictEntryTypeQuantity::getInstance()->getSettings();
        return $settings->getLimit($type->handle);
    }

    /**
     * Counts the enabled, canonical, non-nested entries of a type in a section + site,
     * optionally excluding one canonical entry ID (so an entry doesn't count against itself).
     */
    public function getUsedCount(EntryType $type, int $sectionId, int $siteId, ?int $excludeCanonicalId = null): int
    {
        // Not memoized: saves within a single process (queue workers, console
        // scripts, GraphQL batches) must see each other's effect on the counts
        $query = Entry::find()
            ->sectionId($sectionId)
            ->typeId($type->id)
            ->siteId($siteId)
            ->status('enabled');
        // Drafts, revisions, provisional drafts, and trashed entries are
        // already excluded by element query defaults.

        if ($excludeCanonicalId !== null) {
            $query->id("not $excludeCanonicalId");
        }

        return (int)$query->count();
    }

    /**
     * Returns an error message if saving the entry would exceed its type's limit, or null if it's fine.
     */
    public function getViolation(Entry $entry): ?string
    {
        // Only section entries are restricted; nested (Matrix-owned) entries have no sectionId
        if (!$entry->sectionId) {
            return null;
        }

        // Drafts and revisions save freely; enforcement happens when a draft is
        // applied, because the canonical clone validated at that point isn't a draft
        if ($entry->getIsDraft() || $entry->getIsRevision()) {
            return null;
        }

        // Bulk resaves and site propagation must not fail on pre-existing data
        if ($entry->resaving || $entry->propagating) {
            return null;
        }

        // Disabled entries don't count toward the limit, so saving one is always allowed
        if (!$entry->enabled || !($entry->getEnabledForSite() ?? true)) {
            return null;
        }

        try {
            $type = $entry->getType();
        } catch (InvalidConfigException) {
            // No resolvable type; core validation handles that
            return null;
        }

        $limit = $this->getLimit($type);

        if ($limit === null) {
            return null;
        }

        foreach ($this->siteIdsToCheck($entry) as $siteId) {
            $count = $this->getUsedCount($type, $entry->sectionId, $siteId, $entry->getCanonicalId());

            if ($count >= $limit) {
                return Craft::t('restrict-entry-type-quantity', 'Only {limit,number} {limit,plural,=1{entry} other{entries}} of the type “{type}” {limit,plural,=1{is} other{are}} allowed per site.', [
                    'limit' => $limit,
                    'type' => $type->name,
                ]);
            }
        }

        return null;
    }

    /**
     * Filters the entry types offered for an entry down to the ones that haven't hit their limit.
     *
     * @param EntryType[] $entryTypes
     * @return EntryType[]
     */
    public function filterAvailableEntryTypes(Entry $entry, array $entryTypes): array
    {
        // Leave nested entries' type options alone
        if (!$entry->sectionId) {
            return $entryTypes;
        }

        $currentTypeId = $this->currentTypeId($entry);

        $filtered = array_values(array_filter($entryTypes, function(EntryType $type) use ($entry, $currentTypeId) {
            // The entry's current type must stay available so editing keeps working
            if ($currentTypeId !== null && $type->id === $currentTypeId) {
                return true;
            }

            $limit = $this->getLimit($type);

            if ($limit === null) {
                return true;
            }

            return $this->getUsedCount($type, $entry->sectionId, $entry->siteId, $entry->getCanonicalId()) < $limit;
        }));

        // Never return an empty list — EntriesController::actionCreate() reads
        // getAvailableEntryTypes()[0] and would 500. Validation backstops at publish.
        return $filtered ?: $entryTypes;
    }

    /**
     * Returns the entry's assigned type ID without resolving a default.
     *
     * Entry::$typeId is magic (getTypeId() → getType()), and when no type is assigned
     * yet, getType() resolves a default via getAvailableEntryTypes() — the method that
     * fires EVENT_DEFINE_ENTRY_TYPES. Reading $entry->typeId from inside that event's
     * handler would recurse infinitely, so read the raw value instead.
     */
    private function currentTypeId(Entry $entry): ?int
    {
        static $prop = null;
        $prop ??= new ReflectionProperty(Entry::class, '_typeId');

        return $prop->getValue($entry);
    }

    /**
     * Returns the site IDs whose counts a save needs to be checked against: the entry's
     * own site, plus any other supported site the save will enable it for. CP saves are
     * cross-site-validated per site copy by Craft, so this mostly matters for
     * programmatic saves that propagate without validation.
     *
     * @return int[]
     */
    private function siteIdsToCheck(Entry $entry): array
    {
        $siteIds = [$entry->siteId];

        foreach (ElementHelper::supportedSitesForElement($entry) as $siteInfo) {
            $siteId = (int)$siteInfo['siteId'];

            if ($siteId === (int)$entry->siteId) {
                continue;
            }

            $enabledForSite = $entry->getEnabledForSite($siteId);

            // For new entries, an unset site status falls back to the section's
            // "enabled by default" setting. For existing entries, only an explicit
            // enable is worth checking — the stored per-site status isn't loaded
            // here, and guessing would produce false positives.
            if ($enabledForSite === null && !$entry->id) {
                $enabledForSite = (bool)($siteInfo['enabledByDefault'] ?? true);
            }

            if ($enabledForSite === true) {
                $siteIds[] = $siteId;
            }
        }

        return $siteIds;
    }
}
