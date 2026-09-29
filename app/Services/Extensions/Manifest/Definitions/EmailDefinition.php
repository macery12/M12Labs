<?php

namespace Everest\Services\Extensions\Manifest\Definitions;

/**
 * One kind of email a package may send through the panel's mailer.
 *
 * The package ships the body as `emails/<type>.twig` and names the variables
 * it passes; the panel owns everything else — the transport, the sender, the
 * per-type switch, the delivery log and the hourly ceiling. The subject lives
 * here rather than in the package's PHP so the operator sees it at install
 * and in the admin, the same way a built-in email's subject is fixed in core.
 */
final readonly class EmailDefinition implements \JsonSerializable
{
    /**
     * Variables core puts into every extension email's context itself, so a
     * package may not declare them: `userName` and `userEmail` describe the
     * recipient, the rest are the render context every template gets.
     */
    public const RESERVED_VARIABLES = [
        'userName', 'userEmail', 'appName', 'appUrl', 'currentYear', 'supportEmail', 'logoUrl', 'subject', 'preheader',
    ];

    /** Placeholders in a subject: `{{ name }}`, nothing else. */
    public const SUBJECT_PLACEHOLDER = '/\{\{\s*([A-Za-z][A-Za-z0-9_]*)\s*\}\}/';

    /**
     * @param array<int, EmailVariableDefinition> $variables
     */
    public function __construct(
        public string $type,
        public string $labelKey,
        public ?string $descriptionKey,
        public string $subject,
        public array $variables = [],
    ) {
    }

    /** How the delivery log and the switches name this type. */
    public function key(string $extensionId): string
    {
        return self::keyFor($extensionId, $this->type);
    }

    public static function keyFor(string $extensionId, string $type): string
    {
        return sprintf('ext:%s:%s', $extensionId, $type);
    }

    /** The shipped template, relative to the package's backend root. */
    public function templatePath(): string
    {
        return sprintf('emails/%s.twig', $this->type);
    }

    public function variable(string $name): ?EmailVariableDefinition
    {
        foreach ($this->variables as $variable) {
            if ($variable->name === $name) {
                return $variable;
            }
        }

        return null;
    }

    /**
     * The subject with its placeholders filled. Plain substitution rather than
     * Twig: a subject is a header, not HTML, so escaping would show up as
     * literal `&amp;`, and it needs nothing Twig adds.
     *
     * @param array<string, mixed> $values
     */
    public function subjectWith(array $values): string
    {
        $subject = (string) preg_replace_callback(
            self::SUBJECT_PLACEHOLDER,
            fn (array $match): string => is_scalar($values[$match[1]] ?? null) ? (string) $values[$match[1]] : '',
            $this->subject,
        );

        // A value carrying a line break must not reach the header, and the
        // delivery log's column holds 191 characters.
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));

        return mb_substr($subject, 0, 191);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return array_filter([
            'type' => $this->type,
            'labelKey' => $this->labelKey,
            'descriptionKey' => $this->descriptionKey,
            'subject' => $this->subject,
            'variables' => array_map(fn (EmailVariableDefinition $v): array => $v->jsonSerialize(), $this->variables),
        ], fn ($value) => $value !== null);
    }
}
