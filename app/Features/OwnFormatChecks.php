<?php

namespace App\Features;

use App\Scaffolding\FieldType;

/**
 * Checks a change writes for itself on a field whose format is already
 * decided (§9 Formats): a regex: rule or a preg_match on a phone number,
 * a postal code or the like. The scaffold wrote the field's rule and cast
 * from the type table, and a check of the change's own makes the two
 * disagree about what people may type.
 *
 * Only the lines a change adds to the app's code count, so a check the
 * app already had is never held against a change. The app's own codes
 * (a pattern field) are checked by a regex: on purpose, so they are left
 * out.
 *
 * @phpstan-import-type Record from \App\Scaffolding\Scaffold
 */
class OwnFormatChecks
{
    /**
     * The files read: the app's PHP code, not its tests.
     */
    protected const FILES = '/^(?!tests\/).*\.php$/';

    /**
     * A check written by hand.
     */
    protected const CHECK = '/(["\']regex:|\bpreg_match(_all)?\s*\()/';

    /**
     * The kinds of field whose format is decided by the type table.
     */
    protected const FORMATTED = [
        FieldType::Email,
        FieldType::Phone,
        FieldType::PostalCode,
        FieldType::Url,
        FieldType::Isbn,
        FieldType::Country,
        FieldType::Money,
        FieldType::Percentage,
    ];

    /**
     * Find the lines the patch adds that check a formatted field by hand,
     * numbered as in the new file.
     *
     * @param  list<Record>  $records  The new records the change planned
     * @return list<array{path: string, line: int, field: string, type: string}>
     */
    public static function found(?string $patch, array $records): array
    {
        $fields = [];

        foreach ($records as $record) {
            foreach ($record['fields'] as $field) {
                if (in_array(FieldType::tryFrom($field['type']), self::FORMATTED, true)) {
                    $fields[$field['name']] = $field['type'];
                }
            }
        }

        if ($fields === []) {
            return [];
        }

        $found = [];

        foreach (PatchSummary::files($patch) as $file) {
            if (preg_match(self::FILES, $file['path']) !== 1 || str_contains($file['diff'], "\ndeleted file mode ")) {
                continue;
            }

            foreach (PatchSummary::addedLines($file['diff']) as $added) {
                if (preg_match(self::CHECK, $added['text']) !== 1) {
                    continue;
                }

                foreach ($fields as $name => $type) {
                    if (preg_match('/\b'.preg_quote($name, '/').'\b/', $added['text']) === 1) {
                        $found[] = ['path' => $file['path'], 'line' => $added['line'], 'field' => $name, 'type' => $type];

                        break;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Say what is wrong with the check and how to fix it, for the coder
     * that must fix it.
     *
     * @param  array{path: string, line: int, field: string, type: string}  $found
     */
    public static function finding(array $found): string
    {
        return __('Line :line of :path writes its own check for :field, a :kind field. The generated rule and cast already decide what it accepts and how it is stored, and a second check can disagree with them. Remove this check and use the generated rule.', [
            'line' => $found['line'],
            'path' => $found['path'],
            'field' => $found['field'],
            'kind' => str_replace('_', ' ', $found['type']),
        ]);
    }
}
