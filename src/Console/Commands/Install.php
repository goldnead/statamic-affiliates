<?php

namespace Goldnead\Affiliates\Console\Commands;

use Illuminate\Console\Command;
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

        if (Blueprint::find($namespace.'.'.$handle)) {
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
                ['handle' => 'image', 'field' => ['type' => 'assets', 'display' => __('affiliates::cp.material_image'), 'max_files' => 1]],
            ],
        ]]]]])->save();

        $this->info('Blueprint created.');

        return self::SUCCESS;
    }
}
