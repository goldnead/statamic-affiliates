<?php

namespace Goldnead\Affiliates\Console\Commands;

use Illuminate\Console\Command;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Creates the collection partners find their promotional material in.
 * Existing collections and blueprints are kept as they are.
 */
class Install extends Command
{
    protected $signature = 'affiliates:install';

    protected $description = 'Create the promotional material collection for the partner area';

    public function handle(): int
    {
        $handle = (string) config('affiliates.materials.collection', 'affiliate_materials');

        if (Collection::find($handle)) {
            $this->line("Collection [{$handle}] exists, kept.");
        } else {
            Collection::make($handle)->title(__('affiliates::cp.materials'))->save();
            $this->info("Collection [{$handle}] created.");
        }

        $namespace = 'collections.'.$handle;
        $container = $this->container();

        if ($existing = Blueprint::find($namespace.'.'.$handle)) {
            $this->repairContainer($existing, $container);
            $this->line('Blueprint exists, kept.');

            return self::SUCCESS;
        }

        Blueprint::make($handle)->setNamespace($namespace)->setContents(['tabs' => ['main' => ['sections' => [[
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text', 'display' => __('affiliates::cp.material_title'), 'validate' => ['required']]],
                ['handle' => 'kind', 'field' => [
                    'type' => 'button_group',
                    'display' => __('affiliates::cp.material_kind'),
                    'options' => [
                        'text' => __('affiliates::cp.material_kind_text'),
                        'banner' => __('affiliates::cp.material_kind_banner'),
                        'mail' => __('affiliates::cp.material_kind_mail'),
                        'social' => __('affiliates::cp.material_kind_social'),
                    ],
                    'default' => 'text',
                ]],
                ['handle' => 'target_url', 'field' => ['type' => 'text', 'display' => __('affiliates::cp.material_target'), 'instructions' => __('affiliates::cp.material_target_instructions')]],
                ['handle' => 'copy', 'field' => ['type' => 'textarea', 'display' => __('affiliates::cp.material_copy'), 'instructions' => __('affiliates::cp.material_copy_instructions')]],
                ['handle' => 'image', 'field' => array_filter([
                    'type' => 'assets',
                    'display' => __('affiliates::cp.material_image'),
                    'max_files' => 1,
                    'container' => $container,
                ], fn ($v) => $v !== null)],
            ],
        ]]]]])->save();

        $this->info('Blueprint created.');

        return self::SUCCESS;
    }

    /**
     * The container for the image field: `affiliates.materials.container`,
     * or the site's first one. Without it an assets field cannot tell which
     * container to read once a site has more than one, and the partner area
     * answers 500 (UndefinedContainerException) as soon as a partner signs in.
     */
    protected function container(): ?string
    {
        $configured = config('affiliates.materials.container');

        if (is_string($configured) && $configured !== '' && AssetContainer::find($configured)) {
            return $configured;
        }

        $first = AssetContainer::all()->first();

        if ($first === null) {
            $this->warn('No asset container exists; the image field has none. Run affiliates:install again after creating one.');

            return null;
        }

        return $first->handle();
    }

    /** An existing blueprint whose image field has no container gets one. */
    protected function repairContainer(\Statamic\Fields\Blueprint $blueprint, ?string $container): void
    {
        if ($container === null) {
            return;
        }

        $changed = false;

        $walk = function (array $node) use (&$walk, $container, &$changed): array {
            if (($node['type'] ?? null) === 'assets' && empty($node['container'])) {
                $node['container'] = $container;
                $changed = true;
            }

            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };

        $contents = $walk($blueprint->contents());

        if ($changed) {
            $blueprint->setContents($contents)->save();
            $this->info("Image field set to container [{$container}].");
        }
    }
}
