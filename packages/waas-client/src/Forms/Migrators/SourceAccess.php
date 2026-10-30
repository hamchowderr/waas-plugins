<?php

namespace WaaSClient\Forms\Migrators;

/**
 * Opens up the importer's own form list, id and name (protected in Fluent Forms), so the switch reads the source
 * forms exactly as the importer does.
 */
trait SourceAccess
{
    public function sourceForms(): array
    {
        $forms = $this->getForms();

        return is_array($forms) ? $forms : [];
    }

    public function sourceId($form)
    {
        return $this->getFormId($form);
    }

    public function sourceName($form): string
    {
        return (string) $this->getFormName($form);
    }

    /** The labels the importer itself reported as unsupported in its last run. */
    public function unsupported(): array
    {
        return array_values(array_unique(array_filter(array_map('strval', (array) $this->unSupportFields))));
    }
}
