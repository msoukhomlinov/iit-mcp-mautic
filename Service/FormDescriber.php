<?php

declare(strict_types=1);

namespace MauticPlugin\IntellectItMcpBundle\Service;

use Symfony\Component\Form\FormInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Describes the fields of a Mautic settings form (point actions, trigger
 * events) for the assistant: name, label, kind, required, choices.
 */
class FormDescriber
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @return list<array<string, mixed>> */
    public function fields(FormInterface $form): array
    {
        $out = [];
        foreach ($form as $name => $child) {
            $out[] = $this->field((string) $name, $child);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function field(string $name, FormInterface $field): array
    {
        $config = $field->getConfig();
        $out    = [
            'name'     => $name,
            'label'    => is_string($label = $config->getOption('label')) ? $this->translator->trans($label) : null,
            'kind'     => $config->getType()->getBlockPrefix(),
            'required' => (bool) $config->getOption('required'),
        ];
        if ($config->hasOption('multiple') && $config->getOption('multiple')) {
            $out['multiple'] = true;
        }
        if ('lead_tag' === $out['kind']) {
            $out['hint'] = 'Tag names, e.g. ["customer"]; missing tags are created.';
        }
        if ($config->hasOption('choices') && is_array($choices = $config->getOption('choices'))) {
            $flat = [];
            array_walk_recursive($choices, function ($value, $label) use (&$flat): void {
                $flat[] = ['value' => $value, 'label' => is_string($label) ? $this->translator->trans($label) : (string) $label];
            });
            $out['choices'] = array_slice($flat, 0, 50);
            if (count($flat) > 50) {
                $out['choices_truncated'] = count($flat);
            }
        }
        if (count($field) > 0) {
            $out['fields'] = $this->fields($field);
        }

        return $out;
    }

    /** @param FormInterface<mixed> $form */
    public function errors(FormInterface $form): string
    {
        $messages = [];
        foreach ($form->getErrors(true) as $error) {
            $origin     = $error->getOrigin();
            $messages[] = ($origin ? $origin->getName().': ' : '').$error->getMessage();
        }

        return implode('; ', array_unique($messages)) ?: 'invalid data';
    }
}
