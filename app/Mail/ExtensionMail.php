<?php

namespace Everest\Mail;

use Everest\Services\Email\Templating\EmailTemplateRenderer;
use Everest\Services\Extensions\Manifest\Definitions\EmailDefinition;

/**
 * An email an extension sent through Sdk\Services\PackageMail.
 *
 * Built-in types get one class each with their variables as constructor
 * parameters. An extension's types are declared in its manifest instead, so
 * this carries the variables as a map, checked against the declaration by
 * PackageMail before anything is queued.
 */
class ExtensionMail extends PanelMail
{
    /**
     * @param array<string, scalar|null> $variables
     */
    public function __construct(
        public string $extensionId,
        public string $type,
        public string $renderedSubject,
        public array $variables,
    ) {
    }

    public function key(): string
    {
        return EmailDefinition::keyFor($this->extensionId, $this->type);
    }

    protected function subjectLine(): string
    {
        return $this->renderedSubject;
    }

    public function templateData(): array
    {
        return $this->variables;
    }

    protected function templateView(): string
    {
        return EmailTemplateRenderer::extensionView($this->extensionId, $this->type);
    }
}
