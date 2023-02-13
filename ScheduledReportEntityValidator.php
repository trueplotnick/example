<?php

namespace Database\Reflected\Validators;

use Database\Reflected\EnumSources\EndpointEnumSource;

/**
 * Class ScheduledReportEntityValidator
 * @package Database\Reflected\Validators
 */
class ScheduledReportEntityValidator extends EntityValidator
{
    /**
     * Just the prefix for field names
     */
    const SCHEDULER_OPTIONS = "scheduler_options";


    /**
     * Special handling required for "Weekly" frequency
     * See checkValues
     */
    const FREQ_WEEKLY = "Weekly";

    /**
     * Cached codes
     * @var array
     */
    private $codes = [];

    /**
     * ScheduledReportEntityValidator constructor.
     */
    public function __construct()
    {
        $this->registerValidationFn(self::SCHEDULER_OPTIONS, [$this, "validateSchedulerOptions"]);
    }

    /**
     * @param $value
     * @return bool
     */
    public function validateSchedulerOptions($value): bool
    {
        $options = json_decode($value);
        if (!$options) {
            $this->addError(self::EM_CANT_DECODE, self::SCHEDULER_OPTIONS);
            return false;
        }

        // Hourly, Daily, Weekly, Bi-Monthly, Monthly, Quarterly, Semi-Annually, Yearly
        $frequency = property_get($options, "frequency");
        $valid_frequencies = $this->lookupCachedCodes("codes/srofrequency");
        if (!$this->isValueInSet($frequency, $valid_frequencies, self::SCHEDULER_OPTIONS . ".frequency")) {
            return false;
        }

        $frequency_option = property_get($options, "frequency_option");
        if (!$this->validateFrequencyOption($frequency, $frequency_option)) {
            return false;
        }

        $period = property_get($options, "period");
        $period_option = property_get($options, "period_option");

        if (!$this->validatePeriodAndOptions($frequency, $period, $period_option)) {
            return false;
        }

        return true;
    }

    /**
     * @param $frequency
     * @param $frequency_option
     * @return bool
     */
    private function validateFrequencyOption($frequency, $frequency_option): bool
    {
        $field_name = self::SCHEDULER_OPTIONS . ".frequency_option";

        $rules = [
            "Hourly" => [
                ["hour_of_day" => "endpoint:codes/srohourofday"]
            ],
            "Daily" => [
                ["weekdays_only" => [true, false]]
            ],
            self::FREQ_WEEKLY => [
                ["day_of_week" => "endpoint:codes/srodow"]
            ],
            "Bi-Monthly" => [],
            "Monthly" => [
                ["day_of_month" => range(1, 31)],
                [
                    "week_of_month" => "endpoint:codes/srowom",
                    "day_of_week" => "endpoint:codes/srodow"
                ]
            ],
            "Quarterly" => [
                ["day_of_month" => range(1, 31)]
            ],
            "Semi-Annually" => [
                [
                    "day_of_month" => range(1, 31),
                    "month_of_year" => "endpoint:codes/sromoy"
                ]
            ],
            "Yearly" => [
                [
                    "day_of_month" => range(1, 31),
                    "month_of_year" => "endpoint:codes/sromoy"
                ]
            ]
        ];

        $rule = array_get($rules, $frequency);

        // frequency_option should be null if rule is empty
        // for example when frequency is "Bi-Monthly"
        if (empty($rule)) {
            if ($frequency_option) {
                $this->addError(self::EM_EMPTY, $field_name);
                return false;
            } else {
                return true;
            }
        }

        if (!is_object($frequency_option)) {
            $this->addError(self::EM_OBJECT, $field_name);
            return false;
        }

        $frequency_option = (array)$frequency_option;

        // check is all frequency options (day_of_month, day_of_week...) are exists.
        // user shouldn't pass unknown options
        if (!$this->isValueInSet(
            array_keys($frequency_option),
            $this->lookupCachedCodes("codes/srofrequencyopt"),
            self::SCHEDULER_OPTIONS . ".frequency_option"
        )) {
            return false;
        }

        // check if specified option corresponds to specified frequency
        // for example if frequency is Monthly option can be only "day_of_month"
        // or set of "week_of_month" and "day_of_week"
        $set = null;
        foreach ($rule as $rule_options) {
            // rule option is, for example, set of 1 option [day_of_month]
            // or set of 2 options [week_of_month,day_of_week]
            // check is specified option is the one set of the same possible sets
            if (empty(array_diff_key($rule_options, $frequency_option)) &&
                empty(array_diff_key($frequency_option, $rule_options))) {
                $set = $rule_options;
                break;
            }
        }

        if (!$set) {
            $this->addError(self::EM_INVALID, $field_name);
            return false;
        }

        // check option values
        foreach ($frequency_option as $option_name => $option_value) {
            $rule_option_values = $this->getAvailableOptionValues($set, $option_name);
            if ($rule_option_values) {
                if (!$this->checkValues($frequency, $rule_option_values, $option_value, $field_name)) {
                    return false;
                }
            } else {
                $this->addError(self::EM_INVALID, $field_name);
                return false;
            }
        }

        return true;
    }

    /**
     * @param $frequency
     * @param $period
     * @param $period_option
     * @return bool
     */
    private function validatePeriodAndOptions($frequency, $period, $period_option): bool
    {
        $rules = [
            "Hourly" => [
                "period" => [],
                "period_option" => []
            ],
            "Daily" => [
                "period" => ["Today", "\d+ Days", "\d+ Weeks"],
                "period_option" => []
            ],
            "Weekly" => [
                "period" => ["\d+ Weeks", "MTD", "YTD"],
                "period_option" => ["CAL", "CAL F", "LST", "LSD \d+"]
            ],
            "Bi-Monthly" => [
                "period" => [],
                "period_option" => []
            ],
            "Monthly" => [
                "period" => ["\d+ Months", "YTD"],
                "period_option" => ["CAL", "CAL F", "LST", "LSD \d+"]
            ],
            "Quarterly" => [
                "period" => ["\d+ Quarters", "YTD"],
                "period_option" => ["CAL", "CAL F", "LST", "LSD \d+"]
            ],
            "Semi-Annually" => [
                "period" => [],
                "period_option" => []
            ],
            "Yearly" => [
                "period" => [],
                "period_option" => ["CAL", "CAL F"]
            ]
        ];

        $period_rule = array_get($rules, [$frequency, "period"]);
        if (empty($period_rule)) {
            if ($period) {
                $this->addError(self::EM_EMPTY, self::SCHEDULER_OPTIONS . ".period");
                return false;
            }
        }

        $period_option_rule = array_get($rules, [$frequency, "period_option"]);
        if (empty($period_option_rule)) {
            if ($period_option) {
                $this->addError(self::EM_EMPTY, self::SCHEDULER_OPTIONS . ".period_option");
                return false;
            }
        }

        if (!empty($period_rule) && !$this->isRegExpValueInSet(
                $period,
                $period_rule,
                self::SCHEDULER_OPTIONS . ".period"
            )) {
            return false;
        }

        if ($frequency == "Weekly" && $period == "MTD") {
            if (empty($period_option)) {
                return true;
            } else {
                $this->addError(self::EM_EMPTY, self::SCHEDULER_OPTIONS . ".period_option");
                return false;
            }
        }

        if (!empty($period_option_rule) && !$this->isRegExpValueInSet(
            $period_option,
            $period_option_rule,
            self::SCHEDULER_OPTIONS . ".period_option"
        )) {
            return false;
        }

        return true;
    }

    /**
     * @param $frequency
     * @param $possible_values
     * @param $option_value
     * @param $field_name
     * @return bool
     * @link https://portal.intelligentaudit.com/wiki/pages/viewpage.action?spaceKey=DevDoc&title=New+Scheduler+Design
     */
    private function checkValues($frequency, $possible_values, $option_value, $field_name): bool
    {
        if ($frequency === self::FREQ_WEEKLY) {
            $option_values = explode(",", $option_value);
            if (!empty(array_diff($option_values, $possible_values))) {
                $this->addError(self::EM_INVALID, $field_name);
                return false;
            }
        } else {
            if (!$this->isValueInSet($option_value, $possible_values, $field_name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $set
     * @param string $option_name
     * @return ?array
     */
    private function getAvailableOptionValues(mixed $set, string $option_name): ?array
    {
        $values = array_get($set, $option_name);
        if (is_string($values) && (strpos($values, "endpoint:")) !== false) {
            $endpoint = substr($values, strlen("endpoint:"));
            return $this->lookupCachedCodes($endpoint);
        }

        return $values;
    }

    /**
     * Return cached codes
     * @param $endpoint
     * @return array
     */
    private function lookupCachedCodes($endpoint): array
    {
        $result = array_get($this->codes, $endpoint);

        if (!$result) {
            $result = array_filter(array_keys(EndpointEnumSource::getMap($endpoint)), "strlen");
            $this->codes[$endpoint] = $result;
        }

        return $result;
    }
}
