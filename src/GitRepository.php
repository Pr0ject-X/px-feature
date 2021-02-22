<?php

namespace Pr0jectX\PxFeature;

use Cz\Git\GitRepository as GitRepositoryBase;

class GitRepository extends GitRepositoryBase {

    public function reset($commit_id, $options = NULL)
    {
        return $this->begin()
            ->run("git reset {$commit_id}~", $options)
            ->end();
    }
}
