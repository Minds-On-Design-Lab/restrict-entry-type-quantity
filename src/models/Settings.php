<?php

namespace modlab\craftrestrictentrytypequantity\models;

use craft\base\Model;

/**
 * Restrict Entry Type Quantity settings
 */
class Settings extends Model
{
    /**
     * @var array<string,int> Entry type handle => max number of enabled entries allowed per site.
     * Entry types not listed here are unlimited.
     */
    public array $restrictions = [];

    protected function defineRules(): array
    {
        return [
            ['restrictions', function(string $attribute) {
                foreach ($this->restrictions as $handle => $limit) {
                    // The config file can supply anything, despite the property's PHPDoc type
                    // @phpstan-ignore booleanOr.alwaysFalse
                    if (!is_string($handle) || !is_int($limit) || $limit < 0) {
                        $this->addError($attribute, 'Restrictions must map entry type handles to non-negative integers.');
                        return;
                    }
                }
            }],
        ];
    }

    /**
     * Returns the limit for an entry type handle, or null if it's unrestricted.
     */
    public function getLimit(string $entryTypeHandle): ?int
    {
        return $this->restrictions[$entryTypeHandle] ?? null;
    }
}
