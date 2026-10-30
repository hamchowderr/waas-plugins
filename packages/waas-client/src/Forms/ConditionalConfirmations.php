<?php

namespace WaaSClient\Forms;

/**
 * Keeps a converted form's conditional confirmations working while Fluent Forms Pro is off. WPForms can send a
 * visitor somewhere else depending on their answers (a redirect "if Topic is Support"); the switch keeps that as a
 * conditional confirmation, which free Fluent Forms stores but only Pro acts on. For the forms the switch converted,
 * this picks the first conditional confirmation whose rules match the submission, with Fluent Forms' own rule check.
 *
 * Costs nothing on page loads: it only runs when a form is sent, and only reads the database for converted forms.
 */
class ConditionalConfirmations
{
    public function __construct()
    {
        add_filter('fluentform/form_submission_confirmation', [$this, 'apply'], 20, 3);
    }

    /**
     * @param mixed $confirmation The form's main confirmation.
     * @param mixed $data         The submitted values.
     * @param mixed $form         The Fluent Forms form.
     * @return mixed
     */
    public function apply($confirmation, $data, $form)
    {
        if (defined('FLUENTFORMPRO') || !is_object($form) || empty($form->id) || !is_array($data)
            || !class_exists('FluentForm\App\Services\ConditionAssesor')) {
            return $confirmation;
        }
        $converted = get_option(FormsSwitch::CONDITIONAL_OPTION, []);
        if (!is_array($converted) || !in_array((int) $form->id, array_map('intval', $converted), true)) {
            return $confirmation;
        }

        global $wpdb;
        $values = $wpdb->get_col($wpdb->prepare(
            "SELECT value FROM {$wpdb->prefix}fluentform_form_meta WHERE form_id = %d AND meta_key = 'confirmations' ORDER BY id ASC",
            (int) $form->id
        ));
        foreach ($values as $value) {
            $candidate = json_decode((string) $value, true);
            if (!self::applies($candidate)) {
                continue;
            }
            $inputs = $data;
            if (\FluentForm\App\Services\ConditionAssesor::evaluate($candidate, $inputs, $form)) {
                return array_merge(is_array($confirmation) ? $confirmation : [], $candidate);
            }
        }

        return $confirmation;
    }

    /** A confirmation that is switched on and has rules to check. */
    public static function applies($confirmation): bool
    {
        return is_array($confirmation)
            && (!array_key_exists('active', $confirmation) || !empty($confirmation['active']))
            && !empty($confirmation['conditionals']['status'])
            && !empty($confirmation['conditionals']['conditions']);
    }
}
