<?php
namespace Concrete\Package\MergeSiteTreeCommand;

use Concrete\Core\Application\Application;
use Concrete\Core\Package\Package;
use Concrete5Cojp\MergeSiteTreeCommand\Console\Command\MergeSiteTreeCommand;

class Controller extends Package
{
    protected $pkgHandle = 'merge_site_tree_command';
    protected $appVersionRequired = '8.5.1';
    protected $pkgVersion = '0.0.1';
    protected $pkgAutoloaderRegistries = [
        'src/Console' => '\Concrete5Cojp\MergeSiteTreeCommand\Console',
    ];

    /**
     * {@inheritdoc}
     */
    public function getPackageName()
    {
        return t('Merge Site Tree Command');
    }

    /**
     * {@inheritdoc}
     */
    public function getPackageDescription()
    {
        return t('This is a package for developers. It contains a CLI Command only.');
    }

    public function on_start()
    {
        if ($this->app->isRunThroughCommandLineInterface()) {
            $console = $this->app->make('console');
            $console->add($this->app->make(MergeSiteTreeCommand::class));
        }
    }
}
