<?php
namespace Concrete5Cojp\MergeSiteTreeCommand\Console\Command;

use Concrete\Core\Console\Command;
use Concrete\Core\Page\Cloner;
use Concrete\Core\Page\ClonerOptions;
use Concrete\Core\Page\Collection\Version\Version;
use Concrete\Core\Page\Page;
use Concrete\Core\Page\PageList;
use Concrete\Core\Support\Facade\Facade;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;

class MergeSiteTreeCommand extends Command
{
    /**
     * @var string
     */
    protected $sourceTreePath = '';
    /**
     * @var string
     */
    protected $targetTreePath = '';
    /**
     * @var bool
     */
    protected $includeChildPages = false;
    /**
     * @var string
     */
    protected $copyOption = 'skip';
    /**
     * @var bool
     */
    protected $forceUnapproved = false;
    /**
     * @var bool
     */
    protected $includeAllSites = false;
    /**
     * @var array
     */
    protected $merged = [];

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('c5jp:merge-site-tree')
            ->setDescription('Merge Two Site Tree')
            ->addEnvOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function interact(InputInterface $input, OutputInterface $output)
    {
        $pagePathNormalizer = function ($answer) {
            if (strpos($answer, '/') !== 0) {
                $answer = '/' . $answer;
            }

            return $answer;
        };
        $pagePathValidator = function ($answer) {
            if ($answer === '/') {
                $p = Page::getByID(Page::getHomePageID());
            } else {
                $p = Page::getByPath($answer);
            }
            if (!is_object($p) || $p->isError()) {
                throw new \RuntimeException(sprintf('The page %s is not existed: ', $answer));
            }

            return $answer;
        };

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');

        for (;;) {
            $table = new Table($output);
            $table->setHeaders(['Question', 'Answer']);

            // Ask source
            $question = new Question('Please enter the path of the source tree: ', $this->sourceTreePath);
            $question->setNormalizer($pagePathNormalizer);
            $question->setValidator($pagePathValidator);
            $this->sourceTreePath = $helper->ask($input, $output, $question);
            $table->addRow(['Source Sitemap Tree', $this->sourceTreePath]);

            // Ask target
            $question = new Question('Please enter the path of the target tree: ', $this->targetTreePath);
            $question->setNormalizer($pagePathNormalizer);
            $question->setValidator($pagePathValidator);
            $this->targetTreePath = $helper->ask($input, $output, $question);
            $table->addRow(['Target Sitemap Tree', $this->targetTreePath]);

            if ($this->sourceTreePath === $this->targetTreePath) {
                $output->writeln(sprintf('Please enter different paths for source and target. %s and %s', $this->sourceTreePath, $this->targetTreePath));
                continue;
            }

            // Include child pages
            $question = new ConfirmationQuestion('Do you want to copy all child pages? If you choose No, copy pages only in same folder. [Y]es / [N]o: ', $this->includeChildPages);
            $this->includeChildPages = $helper->ask($input, $output, $question);
            $table->addRow(['Include Child Pages', ($this->includeChildPages) ? 'Yes' : 'No']);

            // Include all sites
            $question = new ConfirmationQuestion('Do you want to get pages from all sites? [Y]es / [N]o: ', $this->includeChildPages);
            $this->includeAllSites = $helper->ask($input, $output, $question);
            $table->addRow(['Include All Sites', ($this->includeAllSites) ? 'Yes' : 'No']);

            // Options override, copy
            $question = new ChoiceQuestion(
                'What do you wish to do when a page with same handle name already exists? (defaults to replace)',
                ['replace', 'skip', 'stop'],
                $this->copyOption
            );
            $this->copyOption = $helper->ask($input, $output, $question);
            $table->addRow(['Copy Option', $this->copyOption]);

            // Force Unapproved
            $question = new ConfirmationQuestion('Do you want to keep the copied version unapproved? [Y]es / [N]o: ', $this->forceUnapproved);
            $this->forceUnapproved = (bool) $helper->ask($input, $output, $question);
            $table->addRow(['Force Unapproved', ($this->forceUnapproved) ? 'Yes' : 'No']);

            $table->render();

            $question = new ConfirmationQuestion('Would you like to run with these settings? [Y]es / [N]o: ');
            if ($helper->ask($input, $output, $question)) {
                break;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourcePages = [];

        if ($this->sourceTreePath === '/' || empty($this->sourceTreePath)) {
            $sourceParent = Page::getByID(Page::getHomePageID());
        } else {
            $sourceParent = Page::getByPath($this->sourceTreePath);
        }
        if (!is_object($sourceParent) || $sourceParent->isError()) {
            throw new \RuntimeException('Invalid Sitemap Tree.');
        }

        $sourcePageList = new PageList();
        if ($this->includeAllSites) {
            $sourcePageList->setSiteTreeToAll();
        }
        $sourcePageList->ignorePermissions();
        $sourcePageList->includeSystemPages();
        if ($this->sourceTreePath !== '/' && !empty($this->sourceTreePath)) {
            if ($this->includeChildPages) {
                $sourcePageList->filterByPath($this->sourceTreePath, true);
            } else {
                $sourcePageList->filterByParentID($sourceParent->getCollectionID());
            }
        }
        $results = $sourcePageList->getResults();

        /** @var Page $result */
        foreach ($results as $result) {
            if (!$result->isHomePage() && !$result->isError()) {
                if ($this->sourceTreePath === '/' || empty($this->sourceTreePath)) {
                    $relativePath = $result->getCollectionPath();
                } else {
                    $relativePath = substr($result->getCollectionPath(), strlen($this->sourceTreePath));
                }
                $sourcePages[$relativePath] = $result;
            }
        }

        uksort($sourcePages, function ($a, $b) {
            return strlen($a) - strlen($b);
        });

        $targetPages = [];

        if ($this->targetTreePath === '/' || empty($this->targetTreePath)) {
            $targetParent = Page::getByID(Page::getHomePageID());
        } else {
            $targetParent = Page::getByPath($this->targetTreePath);
        }
        if (!is_object($targetParent) || $targetParent->isError()) {
            throw new \RuntimeException('Invalid Sitemap Tree.');
        }

        $targetPageList = new PageList();
        if ($this->includeAllSites) {
            $targetPageList->setSiteTreeToAll();
        }
        $targetPageList->ignorePermissions();
        $targetPageList->includeSystemPages();
        if ($this->targetTreePath !== '/' && !empty($this->targetTreePath)) {
            if ($this->includeChildPages) {
                $targetPageList->filterByPath($this->targetTreePath, true);
            } else {
                $targetPageList->filterByParentID($targetParent->getCollectionID());
            }
        }
        $results = $targetPageList->getResults();

        /** @var Page $result */
        foreach ($results as $result) {
            if ($this->targetTreePath === '/' || empty($this->targetTreePath)) {
                $relativePath = $result->getCollectionPath();
            } else {
                $relativePath = substr($result->getCollectionPath(), strlen($this->targetTreePath));
            }
            $targetPages[$relativePath] = $result;
        }

        $output->writeln('Merge Process Started...');
        $progressBar = new ProgressBar($output, count($sourcePages));

        /** @var Page $source */
        foreach ($sourcePages as $path => $source) {
            if (isset($targetPages[$path])) {
                $this->mergePages($source, $targetPages[$path]);
            } else {
                $this->movePage($source, $path);
            }
            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');

        if (count($this->merged) > 0) {
            $output->writeln('Cleanup Process Started...');
            $progressBar = new ProgressBar($output, count($this->merged));

            uksort($this->merged, function ($a, $b) {
                return strlen($b) - strlen($a);
            });

            /** @var Page $merged */
            foreach ($this->merged as $merged) {
                if ($merged->getCollectionID() !== Page::getHomePageID() && !$merged->isMasterCollection() && count($merged->getCollectionChildrenArray(true)) === 0) {
                    $merged->moveToTrash();
                }
                $progressBar->advance();
            }

            $progressBar->finish();
            $output->writeln('');
        }

        $output->writeln('Finished.');
    }

    /**
     * @param Page $source
     * @param Page $target
     */
    protected function mergePages(Page $source, Page $target)
    {
        switch ($this->copyOption) {
            case 'replace':
                /** @var Version $originalVersion */
                $originalVersion = $source->getVersionObject();
                $app = Facade::getFacadeApplication();
                /** @var Cloner $cloner */
                $cloner = $app->make(Cloner::class);
                /** @var ClonerOptions $clonerOptions */
                $clonerOptions = $app->make(ClonerOptions::class);
                $clonerOptions
                    ->setForceUnapproved($this->forceUnapproved)
                    ->setVersionComments(t('Copied from %s', $source->getCollectionPath()));
                $cloner->cloneCollectionVersion($originalVersion, $target, $clonerOptions);
                if ($source) {
                    $this->merged[$source->getCollectionPath()] = $source;
                }
                break;
            case 'stop':
                throw new \RuntimeException(sprintf('The page %s is already existed in %s. Aborted.', $source->getCollectionPath(), $target->getCollectionPath()));
                break;
            default:
                // Skip
                break;
        }
    }

    /**
     * @param Page $source
     * @param $path
     */
    protected function movePage(Page $source, $path)
    {
        if ($this->targetTreePath !== '/' && !empty($this->targetTreePath)) {
            $path = $this->targetTreePath . $path;
        }
        $c = Page::getByPath($path);
        if (!is_object($c) || $c->isError()) {
            if (dirname($path) === '/' || empty(dirname($path))) {
                $parent = Page::getByID(Page::getHomePageID());
            } else {
                $parent = Page::getByPath(dirname($path));
            }
            if (is_object($parent) && !$parent->isError()) {
                $source->move($parent);
            }
        }
    }
}
