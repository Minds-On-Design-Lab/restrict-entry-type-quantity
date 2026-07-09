<?php

namespace modlab\craftrestrictentrytypequantity;

use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Entry;
use craft\events\DefineEntryTypesEvent;
use craft\events\DefineRulesEvent;
use modlab\craftrestrictentrytypequantity\models\Settings;
use modlab\craftrestrictentrytypequantity\services\Restrictions;
use yii\base\Event;

/**
 * Restrict Entry Type Quantity plugin
 *
 * @method static RestrictEntryTypeQuantity getInstance()
 * @method Settings getSettings()
 */
class RestrictEntryTypeQuantity extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'restrictions' => Restrictions::class,
            ],
        ];
    }

    public function getRestrictions(): Restrictions
    {
        /** @var Restrictions */
        return $this->get('restrictions');
    }

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();
    }

    private function attachEventHandlers(): void
    {
        // Block saves that would exceed an entry type's limit. Registered as a
        // validation rule (rather than EVENT_BEFORE_SAVE) so the error attaches to
        // the typeId attribute and surfaces in the CP, GraphQL, and console output.
        // No 'on' scenario restriction: applying a draft validates the canonical
        // clone under SCENARIO_ESSENTIALS, and the rule must fire there too.
        Event::on(Entry::class, Model::EVENT_DEFINE_RULES, function(DefineRulesEvent $event) {
            $event->rules[] = [
                ['typeId'],
                function() use ($event) {
                    /** @var Entry $entry */
                    $entry = $event->sender;
                    $violation = $this->getRestrictions()->getViolation($entry);

                    if ($violation !== null) {
                        $entry->addError('typeId', $violation);
                    }
                },
                'skipOnEmpty' => false,
            ];
        });

        // Hide maxed-out entry types from the CP's entry type choices (edit-page
        // select, and the type entries/create picks for a new entry)
        Event::on(Entry::class, Entry::EVENT_DEFINE_ENTRY_TYPES, function(DefineEntryTypesEvent $event) {
            /** @var Entry $entry */
            $entry = $event->sender;
            $event->entryTypes = $this->getRestrictions()->filterAvailableEntryTypes($entry, $event->entryTypes);
        });
    }
}
