<?php

declare(strict_types=1);

namespace Pr0jectX\PxFeature\ProjectX\Plugin\CommandType;

use Pr0jectX\Px\ProjectX\Plugin\PluginCommandRegisterInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginTasksBase;
use Pr0jectX\PxFeature\ProjectX\Plugin\CommandType\Commands\FeatureCommand;

/**
 * Define the feature command type.
 */
class FeatureCommandType extends PluginTasksBase implements PluginCommandRegisterInterface
{
    /**
     * @inheritDoc
     */
    public static function pluginId(): string
    {
        return 'feature';
    }

    /**
     * @inheritDoc
     */
    public static function pluginLabel(): string
    {
        return 'Feature';
    }

    /**
     * @inheritDoc
     */
    public function registeredCommands(): array
    {
        return [
            FeatureCommand::class,
        ];
    }
}
