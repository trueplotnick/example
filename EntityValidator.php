<?php

namespace Database\Reflected\Validators;

use Database\AbstractSystemDatabase;
use System\Permissions\User\User;

/**
 * Class EntityValidator
 * @package Database\Reflected\Validators
 */
class EntityValidator implements IEntityValidator
{
    // error messages
    const EM_SPECIFY = "Value should be specified.";
    const EM_IN_SET = "Value should be in the set {%s}.";
    const EM_CANT_DECODE = "Can't decode value. Invalid format.";
    const EM_OBJECT = "The value should be an object.";
    const EM_EMPTY = "The value should be empty.";
    const EM_INVALID = "Invalid value.";

    /**
     * @var AbstractSystemDatabase
     */
    protected $db;

    /**
     * @var User
     */
    protected $user;

    /**
     * @var EntityValidationError[]
     */
    protected $errors = [];

    /**
     * @var array
     */
    protected $validationFns = [];

    /**
     * @var int
     */
    protected $validate_mode = IEntityValidator::VALIDATE_ON_CREATE;

    /**
     * @var array
     */
    protected $current_model;

    /**
     * @param int $mode
     * @return mixed|void
     */
    public function setValidateMode(int $mode)
    {
        $this->validate_mode = $mode;
    }

    /**
     * @param AbstractSystemDatabase $db
     * @return mixed|void
     */
    public function setDatabase(AbstractSystemDatabase $db)
    {
        $this->db = $db;
    }

    /**
     * @param User $user
     * @return mixed|void
     */
    public function setUser(User $user)
    {
        $this->user = $user;
    }

    /**
     * @param $model
     * @return bool
     */
    public function validate($model): bool
    {
        $result = true;
        $this->current_model = $model;

        foreach ($model as $field_name => $field_value) {
            $callback = array_get($this->validationFns, $field_name);
            if ($callback && !$callback($field_value)) {
                $result = false;
            }
        }

        return $result;
    }

    /**
     * @return EntityValidationError[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param $field_name
     * @param callable $callback
     */
    protected function registerValidationFn($field_name, callable $callback)
    {
        $this->validationFns[$field_name] = $callback;
    }

    /**
     * @param $message
     * @param $field_name
     * @param int $code
     */
    protected function addError($message, $field_name, $code = -1)
    {
        $this->errors[] = new EntityValidationError($message, $field_name, $code);
    }

    /**
     * @param $value
     * @param array $set
     * @param string $field_name
     * @return bool
     */
    protected function isValueInSet($value, array $set, string $field_name): bool
    {
        if (!is_array($value)) {
            $value = [$value];
        }

        if (!empty(array_diff($value, $set))) {
            $this->addError(
                sprintf(self::EM_IN_SET, implode(", ", $set)),
                $field_name
            );
            return false;
        }

        return true;
    }

    /**
     * @param $value
     * @param array $set
     * @param string $field_name
     * @return bool
     */
    protected function isRegExpValueInSet($value, array $set, string $field_name): bool
    {
        if (!is_array($value)) {
            $values = [$value];
        } else {
            $values = $value;
        }

        $result = false;
        foreach ($values as $value) {
            foreach ($set as $set_item) {
                if (preg_match("/$set_item/", (string)$value)) {
                    $result = true;
                }
            }
        }

        if (!$result) {
            $this->addError(
                sprintf(self::EM_IN_SET, implode(", ", $set)),
                $field_name
            );
        }

        return $result;
    }
}
