<?php

declare(strict_types=1);

namespace Pr0jectX\PxFeature\ProjectX\Plugin\CommandType\Commands;

use Pr0jectX\PxFeature\GitRepository;
use Pr0jectX\Px\ProjectX\Plugin\PluginCommandTaskBase;
use Pr0jectX\Px\ProjectX\Plugin\PluginInterface;
use Pr0jectX\Px\PxApp;
use Pr0jectX\Px\Task\LoadTasks as PxTasks;
use Robo\Robo;
use Robo\Task\Base\loadTasks;
use Robo\Task\File\Write;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
  * FeatureCommand plugin class.
 */
class FeatureCommand extends PluginCommandTaskBase
{
    use PxTasks;
    use loadTasks;

    const CONFIG_DIR = '.project-x/features';

    const CONFIG_FILE = 'features.yml';

    /**
     * Current parsed config stored in features.yml.
     *
     * @var array
     */
    protected $config;

    /**
     * @var \Consolidation\Config\ConfigInterface|\Robo\Config\Config
     */
    protected $configLoader;

    /**
     * @var \Cz\Git\GitRepository
     */
    protected $git;

    /**
     * {@inheritdoc}
     */
    public function __construct(PluginInterface $plugin)
    {
        parent::__construct($plugin);
        $file = PxApp::projectRootPath() . '/' . self::CONFIG_DIR . '/' . self::CONFIG_FILE;
        $this->configLoader = Robo::createConfiguration([$file]);
        $this->config = $this->configLoader->get('features');
        $this->git = new GitRepository('./');
    }

    /**
     * List current saved features.
     */
    public function featureList(): void
    {
        $io = new SymfonyStyle($this->input(), $this->output());
        $top = reset($this->config);

        $io->table(array_keys($top), array_map(function($value) {
          return array_values($value);
        }, $this->config));
    }

    /**
     * Gets the feature info for the current branch.
     */
    public function featureInfo(): void
    {
        $name = $this->git->getCurrentBranchName();
        $io = new SymfonyStyle($this->input(), $this->output());

        if ($this->configExists($name)) {
            $config = $this->config($name);
            $rows = ["Current feature info"];
            foreach ($config as $key => $value) {
                $rows[] = [$key => $value];
            }
            $io->definitionList(...$rows);
        }
        else {
            $io->note('Feature not found.');
        }
    }

    private function _currentBranchIsFeature() {
        $branch_name = $this->git->getCurrentBranchName();
        return $this->configExists($branch_name);
    }

    /**
     * Checkout a new feature branch and db if they exist.
     *
     * @param string $name
     */
    public function featureCheckout(string $name): void
    {
      $current_name = $this->git->getCurrentBranchName();
        if ($name != $current_name) {
          if ($this->confirm("Save feature $current_name before switching?", FALSE)) {
            $this->featureSave($current_name);
          }
        }
        if ($this->configExists($name)) {

            try {
                $this->_gitCheckoutBranch($name);
                $this->_drupalImportDatabase($name);
                $this->say('Feature checkout complete.');
            }
            catch (\Exception $e) {
                $this->error($e->getMessage());
            }

            return;
        }

        if ($this->confirm("Feature $name has not been created. Would you like to create it?", FALSE)) {
            $this->featureCreate($name);
        }
    }

    private function _gitCommitChanges() {
      $this->git->addAllChanges();
      $this->git->commit('px feature save');

      $name = $this->git->getCurrentBranchName();
      $config = &$this->config($name);
      $config['temp_hash_id'] =  $this->git->getLastCommitId();
      $this->configSave();

    }

    private function _gitRevertChanges() {
      $name = $this->git->getCurrentBranchName();
      $config = &$this->config($name);

      $hash = $this->git->getLastCommitId();
      if (isset($config['temp_hash_id']) && $hash === $config['temp_hash_id']) {
        $this->git->reset($hash);
      }
    }

    public function featureSave($name = NULL): void
    {
        if (!$name) {
          $name = $this->git->getCurrentBranchName();
        }
        $this->taskSymfonyCommand($this->findCommand('db:export'))
            ->arg('export_dir', self::CONFIG_DIR)
            ->opt('filename', $name)
            ->run();

        $config = &$this->config($name);
        // TODO: Make this configurable.
        $config['branch'] = $name;
        // TODO: Figure out the name of the file from the command.
        $config['database'] = "{$name}.sql.gz";
        $this->configSave();
    }

    /**
     * Create a new feature branch and db optionally from remote?.
     */
    public function featureCreate($name): void
    {
        try {
            $this->_gitCheckoutBranch($name);

            $this->taskSymfonyCommand($this->findCommand('platformsh:sync'))
                ->arg('siteEnv', $name)
                ->run();

            $this->featureSave($name);
        }
        catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    private function _gitCheckoutBranch($name) {
        if ($name == $this->git->getCurrentBranchName()) {
            if (!$this->confirm("Branch $name already checkout out, would you like to continue?")) {
                throw new \Exception('Feature checkout canceled');
            }
        }
        if ($name != $this->git->getCurrentBranchName()) {
            $branches = $this->git->getBranches();
            if (!in_array($name, $branches)) {
                // TODO: Automatically create branch.
                throw new \Exception('Branch does not exist');
            }
            if ($this->git->hasChanges()) {
              if (!$this->confirm("Branch $name has uncommitted changes. Type yes to save to a temporariy commit and revert later, or no to abort and commit manually.")) {
                throw new \Exception('Feature checkout canceled');
              }
              // Current branch should be branch you're switching to.
              $this->_gitCommitChanges();
            }

            $this->git->checkout($name);

            // Current branch should be the newly checked out branch.
            $this->_gitRevertChanges();
        }
    }

    private function _drupalImportDatabase($name) {
        $config = $this->config($name);
        if (empty($config['database'])) {
            throw new \Exception('No database file set in configuration.');
        }

        $file = PxApp::projectRootPath() . '/' . self::CONFIG_DIR . '/' . $config['database'];
        if (!file_exists($file)) {
            throw new \Exception('Database file no longer exists.');
        }

        $this->taskSymfonyCommand($this->findCommand('db:import'))
            ->arg('source_file', $file)
            ->run();
    }

    private function configExists($name) {
        $exists = FALSE;
        foreach ($this->config as $feature) {
            if ($name == $feature['name']) {
                $exists = TRUE;
            }
        }
        return $exists;
    }

    private function &config($name) {
        foreach ($this->config as &$feature) {
            if ($name == $feature['name']) {
                return $feature;
            }
        }

        $config = ['name' => $name];
        $this->config[] = &$config;
        return $config;
    }

    private function configSave(): void
    {
        $this->configLoader->set('features', $this->config);
        /** @var Write $task */
        $task = $this->taskWriteToFile(self::CONFIG_DIR . '/' . self::CONFIG_FILE);
        $data = $this->configLoader->export();
        $yaml = Yaml::dump($data);
        $task->text($yaml);
        $task->run();
    }
}
