<?php

/**
 * Search Index plugin for Craft CMS -- Twig extension helpers.
 */

namespace cogapp\searchindex\web\twig;

use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * Provides convenience Twig helpers for Sprig component usage.
 *
 * @author cogapp
 * @since 1.0.0
 */
class SearchIndexTwigExtension extends AbstractExtension
{
    /**
     * @var array<string, string> Short aliases => Sprig component identifiers.
     */
    private const SPRIG_COMPONENT_ALIASES = [
        'cp.test-connection' => 'cogapp\\searchindex\\sprig\\components\\TestConnection',
        'cp.validation-results' => 'cogapp\\searchindex\\sprig\\components\\ValidationResults',
        'cp.index-structure' => 'cogapp\\searchindex\\sprig\\components\\IndexStructure',
        'cp.index-health' => 'cogapp\\searchindex\\sprig\\components\\IndexHealth',
        'cp.search-single' => 'cogapp\\searchindex\\sprig\\components\\SearchSingle',
        'cp.search-compare' => 'cogapp\\searchindex\\sprig\\components\\SearchCompare',
        'cp.search-document-picker' => 'cogapp\\searchindex\\sprig\\components\\SearchDocumentPicker',
        'frontend.search-box' => 'cogapp\\searchindex\\sprig\\components\\frontend\\SearchBox',
        'frontend.search-facets' => 'cogapp\\searchindex\\sprig\\components\\frontend\\SearchFacets',
        'frontend.search-pagination' => 'cogapp\\searchindex\\sprig\\components\\frontend\\SearchPagination',
    ];

    /**
     * @inheritdoc
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction(
                'searchIndexSprig',
                [$this, 'renderSearchIndexSprig'],
                ['needs_environment' => true, 'is_safe' => ['html']]
            ),
            new TwigFunction(
                'searchIndexSprigComponent',
                [$this, 'resolveSearchIndexSprigComponent']
            ),
            new TwigFunction(
                'siToBool',
                [self::class, 'toBool']
            ),
        ];
    }

    /**
     * Render a Search Index Sprig component by short alias or explicit identifier.
     *
     * Example: {{ searchIndexSprig('cp.search-single', { indexOptions: indexOptions }) }}
     *
     * @param Environment $environment
     * @param string      $component  Alias or explicit class/template identifier.
     * @param array<string, mixed>       $variables  Sprig component variables.
     * @param array<string, mixed>       $attributes Sprig wrapper attributes.
     * @return Markup
     */
    public function renderSearchIndexSprig(Environment $environment, string $component, array $variables = [], array $attributes = []): Markup
    {
        $resolvedComponent = $this->resolveSearchIndexSprigComponent($component);
        $html = $environment
            ->createTemplate('{{ sprig(component, variables, attributes) }}')
            ->render([
                'component' => $resolvedComponent,
                'variables' => $variables,
                'attributes' => $attributes,
            ]);

        return new Markup($html, 'UTF-8');
    }

    /**
     * Resolve a short alias to a Sprig component identifier.
     *
     * If no alias exists, the input value is returned unchanged.
     */
    public function resolveSearchIndexSprigComponent(string $component): string
    {
        return self::SPRIG_COMPONENT_ALIASES[$component] ?? $component;
    }

    /**
     * Coerce a Sprig property value to a strict boolean.
     *
     * Use in templates as `siToBool(value)` to replace the verbose
     * `value is same as(true) or value in [1, '1', 'true', 'yes', 'on']` pattern.
     */
    public static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
    }
}
