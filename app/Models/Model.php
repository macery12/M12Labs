<?php

namespace Everest\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Contracts\Validation\DataAwareRule;
use Everest\Exceptions\Model\DataValidationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Database\Eloquent\Model as IlluminateModel;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;

abstract class Model extends IlluminateModel
{
    use HasFactory;

    /**
     * Set to true to return immutable Carbon date instances from the model.
     */
    protected bool $immutableDates = false;

    /**
     * Determines if the model should undergo data validation before it is saved
     * to the database.
     */
    protected bool $skipValidation = false;

    protected static ValidationFactory $validatorFactory;

    public static array $validationRules = [];

    /**
     * Listen for the model saving event and fire off the validation
     * function before it is saved.
     *
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    protected static function boot()
    {
        parent::boot();

        static::$validatorFactory = Container::getInstance()->make(ValidationFactory::class);

        static::saving(function (Model $model) {
            try {
                $model->validate();
            } catch (ValidationException $exception) {
                throw new DataValidationException($exception->validator, $model);
            }

            return true;
        });
    }

    /**
     * Returns the model key to use for route model binding. By default, we'll
     * assume every model uses a UUID field for this. If the model does not have
     * a UUID and is using a different key it should be specified on the model
     * itself.
     *
     * You may also optionally override this on a per-route basis by declaring
     * the key name in the URL definition, like "{user:id}".
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Set the model to skip validation when saving.
     */
    public function skipValidation(): self
    {
        $this->skipValidation = true;

        return $this;
    }

    /**
     * Rule names that read another field's value. A clean field carrying one of
     * these can become invalid because a *different* field changed.
     */
    private const CROSS_FIELD_RULES = [
        'accepted_if', 'after', 'after_or_equal', 'before', 'before_or_equal',
        'confirmed', 'declined_if', 'different', 'distinct', 'exclude_if',
        'exclude_unless', 'exclude_with', 'exclude_without', 'gt', 'gte',
        'in_array', 'lt', 'lte', 'missing_if', 'missing_unless', 'missing_with',
        'missing_with_all', 'present_if', 'present_unless', 'present_with',
        'present_with_all', 'prohibited_if', 'prohibited_if_accepted',
        'prohibited_if_declined', 'prohibited_unless', 'prohibits',
        'required_if', 'required_if_accepted', 'required_if_declined',
        'required_unless', 'required_with', 'required_with_all',
        'required_without', 'required_without_all', 'same',
    ];

    /**
     * Rule objects known to look only at their own field.
     */
    private const SINGLE_FIELD_RULE_OBJECTS = [
        \Illuminate\Validation\Rules\In::class,
        \Illuminate\Validation\Rules\NotIn::class,
        \Illuminate\Validation\Rules\Unique::class,
        \Illuminate\Validation\Rules\Exists::class,
        \Illuminate\Validation\Rules\Enum::class,
    ];

    /** @var array<class-string, bool> */
    private static array $crossFieldRules = [];

    /**
     * Returns the validator instance used by this model.
     *
     * An update validates only the attributes that changed. Every save used to
     * re-run the whole rule set, so touching one column of a Server re-ran a
     * dozen `exists:` / `unique:` SELECTs against columns nobody had edited --
     * and a row holding legacy data an older rule allowed could not be saved
     * at all. A model whose rules read other fields (`required_without:`,
     * `required_unless:` and the like, or a rule object that is not known to
     * be single-field) keeps full validation, since a change to one field can
     * invalidate another.
     */
    public function getValidator(): Validator
    {
        $rules = $this->exists ? static::getRulesForUpdate($this) : static::getRules();

        if ($this->exists && !static::hasCrossFieldRules($rules)) {
            $dirty = array_keys($this->getDirty());
            $rules = array_filter(
                $rules,
                fn (string $field): bool => in_array(Str::before($field, '.'), $dirty, true),
                ARRAY_FILTER_USE_KEY,
            );
        }

        return static::$validatorFactory->make([], $rules, [], []);
    }

    /**
     * @param array<string, array<int, mixed>> $rules
     */
    protected static function hasCrossFieldRules(array $rules): bool
    {
        return self::$crossFieldRules[static::class] ??= (function () use ($rules): bool {
            foreach ($rules as $fieldRules) {
                foreach ($fieldRules as $rule) {
                    if (is_string($rule)) {
                        if (in_array(strtolower(Str::before($rule, ':')), self::CROSS_FIELD_RULES, true)) {
                            return true;
                        }

                        continue;
                    }

                    if ($rule instanceof DataAwareRule || $rule instanceof ValidatorAwareRule || $rule instanceof \Closure) {
                        return true;
                    }

                    // App rules are single-field unless they ask for the data
                    // (checked above); anything else unknown is assumed not.
                    if (!in_array($rule::class, self::SINGLE_FIELD_RULE_OBJECTS, true)
                        && !str_starts_with($rule::class, 'Everest\\Rules\\')) {
                        return true;
                    }
                }
            }

            return false;
        })();
    }

    /**
     * Returns the rules associated with this model.
     */
    public static function getRules(): array
    {
        $rules = static::$validationRules;
        foreach ($rules as $key => &$rule) {
            $rule = is_array($rule) ? $rule : explode('|', $rule);
        }

        return $rules;
    }

    /**
     * Returns the rules for a specific field. If the field is not found an empty
     * array is returned.
     */
    public static function getRulesForField(string $field): array
    {
        return Arr::get(static::getRules(), $field) ?? [];
    }

    /**
     * Returns the rules associated with the model, specifically for updating the given model
     * rather than just creating it.
     */
    public static function getRulesForUpdate($model, string $column = 'id'): array
    {
        if ($model instanceof Model) {
            [$id, $column] = [$model->getKey(), $model->getKeyName()];
        }

        $rules = static::getRules();
        foreach ($rules as $key => &$data) {
            // For each rule in a given field, iterate over it and confirm if the rule
            // is one for a unique field. If that is the case, append the ID of the current
            // working model, so we don't run into errors due to the way that field validation
            // works.
            foreach ($data as &$datum) {
                if (!is_string($datum) || !Str::startsWith($datum, 'unique')) {
                    continue;
                }

                [, $args] = explode(':', $datum);
                $args = explode(',', $args);

                $datum = Rule::unique($args[0], $args[1] ?? $key)->ignore($id ?? $model, $column);
            }
        }

        return $rules;
    }

    /**
     * Determines if the model is in a valid state or not.
     *
     * @throws ValidationException
     */
    public function validate(): void
    {
        if ($this->skipValidation) {
            return;
        }

        /** @var \Illuminate\Validation\Validator $validator */
        $validator = $this->getValidator();
        $validator->setData(
            // Trying to do self::toArray() here will leave out keys based on the whitelist/blacklist
            // for that model. Doing this will return all the attributes in a format that can
            // properly be validated.
            $this->addCastAttributesToArray(
                $this->getAttributes(),
                $this->getMutatedAttributes()
            )
        );

        if (!$validator->passes()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Return a timestamp as DateTime object.
     */
    protected function asDateTime($value): Carbon|CarbonImmutable
    {
        if (!$this->immutableDates) {
            return parent::asDateTime($value);
        }

        return parent::asDateTime($value)->toImmutable();
    }
}
