<?php
/**
 * Small HTML helpers for the booking form. Every value is escaped with h().
 * $ctx is ['values' => [...], 'errors' => [...], 'readonly' => bool].
 */
declare(strict_types=1);

function fv(array $ctx, string $name): string
{
    $v = $ctx['values'][$name] ?? '';
    return $v === null ? '' : (string) $v;
}

function field_error(array $ctx, string $name): string
{
    return isset($ctx['errors'][$name]) ? '<span class="field-error">' . h($ctx['errors'][$name]) . '</span>' : '';
}

function ro(array $ctx): string
{
    return $ctx['readonly'] ? ' disabled' : '';
}

/** <div class="field"> with a label and an input. $attrs: extra attributes (already safe). */
function input_field(array $ctx, string $name, string $label, string $type = 'text', string $class = '', string $attrs = ''): string
{
    $value = fv($ctx, $name);
    if ($type === 'time' && strlen($value) === 8) {
        $value = substr($value, 0, 5);
    }
    return '<div class="field ' . $class . '"><label for="f_' . $name . '">' . h($label) . '</label>'
        . '<input type="' . $type . '" id="f_' . $name . '" name="' . $name . '" value="' . h($value) . '"' . $attrs . ro($ctx) . '>'
        . field_error($ctx, $name) . '</div>';
}

function textarea_field(array $ctx, string $name, string $label, string $class = 'span2', string $attrs = ''): string
{
    return '<div class="field ' . $class . '"><label for="f_' . $name . '">' . h($label) . '</label>'
        . '<textarea id="f_' . $name . '" name="' . $name . '"' . $attrs . ro($ctx) . '>' . h(fv($ctx, $name)) . '</textarea>'
        . field_error($ctx, $name) . '</div>';
}

/** A select from a list of options; with $otherField, also the "If Other, specify" input (shown only for Other). */
function select_field(array $ctx, string $name, string $label, array $options, ?string $otherField = null, bool $allowEmpty = true): string
{
    $current = fv($ctx, $name);
    $html = '<div class="field"><label for="f_' . $name . '">' . h($label) . '</label>'
        . '<select id="f_' . $name . '" name="' . $name . '"' . ($otherField ? ' data-other="f_' . $otherField . '"' : '') . ro($ctx) . '>';
    if ($allowEmpty) {
        $html .= '<option value="">—</option>';
    }
    foreach ($options as $opt) {
        $html .= '<option' . ($opt === $current ? ' selected' : '') . '>' . h($opt) . '</option>';
    }
    $html .= '</select>' . field_error($ctx, $name) . '</div>';
    if ($otherField) {
        $html .= input_field($ctx, $otherField, 'If Other, specify', 'text', 'other-field' . ($current === 'Other' ? '' : ' hidden'));
    }
    return $html;
}

/** Money input: text (so "1,00,000" is accepted), right-aligned. */
function money_field(array $ctx, string $name, string $label, string $class = ''): string
{
    return input_field($ctx, $name, $label, 'text', 'money ' . $class, ' inputmode="decimal" autocomplete="off"');
}

function count_field(array $ctx, string $name, string $label, string $class = ''): string
{
    return input_field($ctx, $name, $label, 'text', 'count ' . $class, ' inputmode="numeric" autocomplete="off"');
}

/** Money for display: stored DECIMAL string or paisa int → "Rs. 12,34,567". */
function rs($amount): string
{
    return format_rs(is_int($amount) ? $amount : decimal_to_paisa($amount ?? '0'));
}
