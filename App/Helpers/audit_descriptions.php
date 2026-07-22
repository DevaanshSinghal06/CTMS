<?php

function audit_compare_value($value): string
{
    if ($value === null || $value === "") {
        return "";
    }

    return (string) $value;
}

function audit_display_value($value): string
{
    if ($value === null || $value === "") {
        return "(blank)";
    }

    return (string) $value;
}

function build_changed_fields_summary(array $oldValues, array $newValues, array $fieldLabels): string
{
    $changes = [];

    foreach ($fieldLabels as $fieldName => $fieldLabel) {
        $oldValue = $oldValues[$fieldName] ?? null;
        $newValue = $newValues[$fieldName] ?? null;

        if (audit_compare_value($oldValue) !== audit_compare_value($newValue)) {
            $changes[] = $fieldLabel
                . ' changed from "'
                . audit_display_value($oldValue)
                . '" to "'
                . audit_display_value($newValue)
                . '"';
        }
    }

    return implode("; ", $changes);
}