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
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
  * FeatureCommand plugin class.
 */
class FeatureCommand extends PluginCommandTaskBase
{
    use PxTasks;
    use loadTasks;

    const STORAGE_DIR = '.project-x/features';

    const STORAGE_FILE = 'features.yml';

    /**
     * Data storage parsed from yml file self::STORAGE_FILE.
     *
     * @var array
     */
    protected $storage;

    /**
     * @var \Consolidation\Config\ConfigInterface|\Robo\Config\Config
     */
    protected $storageLoader;

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
        $file = PxApp::projectRootPath() . '/' . self::STORAGE_DIR . '/' . self::STORAGE_FILE;
        $this->storageLoader = Robo::createConfiguration([$file]);
        $this->storage = $this->storageLoader->get('features') ?? [];
        $this->git = new GitRepository('./');
    }

    /**
     * List current saved features.
     */
    public function featureList(): void
    {
        $io = new SymfonyStyle($this->input(), $this->output());
        $header = ['Name', 'Branch', 'Database file', 'Stash changes'];

        $io->table($header, array_map(function($value) {
          return array_values($value);
        }, $this->storage));
    }

    /**
     * Gets the feature info for the current branch.
     */
    public function featureInfo(): void
    {
        $name = $this->_getCurrentBranchName();
        $io = new SymfonyStyle($this->input(), $this->output());

        if ($this->featureExists($name)) {
            $storage = $this->storage($name);
            $rows = ["Current feature info"];
            foreach ($storage as $key => $value) {
                $rows[] = [$key => $value];
            }
            $io->definitionList(...$rows);
        }
        else {
            $io->note('Feature not found.');
        }
    }

    private function _currentBranchIsFeature() {
        $branch_name = $this->_getCurrentBranchName();
        return $this->featureExists($branch_name);
    }

    /**
     * Checkout a new feature branch and db if they exist.
     *
     * @param string $name
     */
    public function featureCheckout(string $name): void
    {
        $current_name = $this->_getCurrentBranchName();

        if ($name != $current_name) {
            if ($this->confirm("Save feature $current_name before switching?", FALSE)) {
                $this->featureSave($current_name);
            }
        }
        if ($this->featureExists($name)) {

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

      $name = $this->_getCurrentBranchName();
      $storage = &$this->storage($name);
      $storage['temp_hash_id'] =  $this->git->getLastCommitId();
      $this->storageSave();
    }

    private function _gitRevertChanges() {
      $name = $this->_getCurrentBranchName();
      $storage = &$this->storage($name);

      $hash = $this->git->getLastCommitId();
      if (isset($storage['temp_hash_id']) && $hash === $storage['temp_hash_id']) {
        $this->git->reset($hash);
      }
    }

    public function featureSave($name = NULL): void
    {
        if (!$name) {
          $name = $this->_getCurrentBranchName();
        }
        $this->taskSymfonyCommand($this->findCommand('db:export'))
            ->arg('exportDir', self::STORAGE_DIR)
            ->opt('filename', $name)
            ->run();

        $storage = &$this->storage($name);
        // TODO: Make this overridable.
        $storage['branch'] = $name;
        // TODO: Figure out the name of the file from the command.
        $storage['database'] = "{$name}.sql.gz";
        $this->storageSave();
    }

    public function featureCreate($name): void
    {
        try {
            $this->_gitCheckoutBranch($name);

            // TODO: Add to plugin configuration for using pantheon.
            try {
                if ($this->findCommand('platformsh:sync')) {
                    $this->taskSymfonyCommand($this->findCommand('platformsh:sync'))
                        ->arg('siteEnv', $name)
                        ->run();
                }
            }
            catch (CommandNotFoundException $e) {
                // Just skip, this needs to be improved on.
            }
            $this->featureSave($name);
        }
        catch (\Exception $e) {
            $this->error($e->getMessage());
        }
    }

    private function _gitCheckoutBranch($name) {
        if ($name == $this->_getCurrentBranchName()) {
            if (!$this->confirm("Branch $name already checkout out, would you like to continue?")) {
                throw new \Exception('Feature checkout canceled');
            }
        }
        if ($name != $this->_getCurrentBranchName()) {
            // Don't encode to compare with other branch names.
            $current_name = $this->_getCurrentBranchName(FALSE);
            $branches = $this->git->getBranches();
            if (!in_array($name, $branches)) {
                // TODO: Automatically create branch.
                throw new \Exception('Branch does not exist');
            }
            if ($this->git->hasChanges()) {
              if (!$this->confirm("Store uncommitted changes to a commit for $current_name? Enter [n] to cancel checkout and commit manually.", 'y')) {
                throw new \Exception('Feature checkout canceled');
              }
              // Current branch should be branch you're switching to.
              $this->_gitCommitChanges();
            }

            // Decode to real branch name.
            // TODO: Move this to another function.
            $name = str_replace('%2F', '/', $name);
            $this->git->checkout($name);

            // Current branch should be the newly checked out branch.
            $this->_gitRevertChanges();
        }
    }

    private function _drupalImportDatabase($name) {
        $storage = $this->storage($name);
        if (empty($storage['database'])) {
            throw new \Exception('No database file set in storageuration.');
        }

        $file = PxApp::projectRootPath() . '/' . self::STORAGE_DIR . '/' . $storage['database'];
        if (!file_exists($file)) {
            throw new \Exception('Database file no longer exists.');
        }

        $this->taskSymfonyCommand($this->findCommand('db:import'))
            ->arg('source_file', $file)
            ->run();
    }

    private function _getCurrentBranchName($encode = TRUE) {
      $name = $this->git->getCurrentBranchName();
      return $encode ? str_replace('/', '%2F', $name) : str_replace('%2F', '/', $name);
    }

    private function featureExists($name) {
        $exists = FALSE;
        foreach ($this->storage as $feature) {
            if ($name == $feature['name']) {
                $exists = TRUE;
            }
        }
        return $exists;
    }

    private function &storage($name) {
        foreach ($this->storage as &$feature) {
            if ($name == $feature['name']) {
                return $feature;
            }
        }

        $storage = ['name' => $name];
        $this->storage[] = &$storage;
        return $storage;
    }

    private function storageSave(): void
    {
        $this->storageLoader->set('features', $this->storage);
        /** @var Write $task */
        $task = $this->taskWriteToFile(self::STORAGE_DIR . '/' . self::STORAGE_FILE);
        $data = $this->storageLoader->export();
        $yaml = Yaml::dump($data);
        $task->text($yaml);
        $task->run();
    }
}
