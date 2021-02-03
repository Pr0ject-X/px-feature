<?php

declare(strict_types=1);

namespace Pr0jectX\PxFeature\ProjectX\Plugin\CommandType;

use Pr0jectX\Px\ConfigTreeBuilder\ConfigTreeBuilder;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandRegisterInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginConfigurationBuilderInterface;
use Pr0jectX\Px\ProjectX\Plugin\PluginTasksBase;
use Pr0jectX\PxFeature\ProjectX\Plugin\CommandType\Commands\FeatureCommand;
use Symfony\Component\Console\Question\Question;

/**
 * Define the platformsh command type.
 */
class FeatureCommandType extends PluginTasksBase implements PluginConfigurationBuilderInterface, PluginCommandRegisterInterface
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

    /**
     * @inheritDoc
     */
    public function pluginConfiguration(): ConfigTreeBuilder
    {
        return (new ConfigTreeBuilder())
            ->setQuestionInput($this->input)
            ->setQuestionOutput($this->output)
            ->createNode('site')
                ->setValue((new Question(
                    $this->formatQuestion('Input the site machine name')
                ))->setValidator(function ($value) {
                    if (empty($value)) {
                        throw new \RuntimeException(
                            'The site machine name is required!'
                        );
                    }
                    if (!preg_match('/^[\w-]+$/', $value)) {
                        throw new \RuntimeException(
                            'The site machine name format is invalid!'
                        );
                    }
                    return $value;
                }))
            ->end();
    }
}
