<?php

namespace Absolute\DataCleaner\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Input\InputOption;
use Magento\Framework\App\Filesystem\DirectoryList;

class RemoveUnusedMediaCommand extends Command
{
    /**
     * @var array
     */
    protected $_mediaAreaPatterns = [
        'product' => '\/product\/.\/',
        'cache' => '\/cache\/',
    ];

    /**
     * @var int
     */
    protected $_removedFileSize = 0;

    /**
     * @var int
     */
    protected $_totalFileSize = 0;

    /**
     * @var int
     */
    protected $_removedFileCount = 0;

    /**
     * @var int
     */
    protected $_totalFiles = 0;

    /**
     * @var
     */
    protected $_output;

    /**
     * @var bool
     */
    protected $_isDryRun = true;

    /**
     * @var array
     */
    protected $_databaseImages = [];

    /**
     * @var array
     */
    protected $_productImages = [];

    /**
     * @var array
     */
    protected $_productCacheImages = [];

    /**
     * @var
     */
    protected $_questionHelper;

    /**
     * @var string
     */
    protected $_imageDir = '';

    /**
     * @var \Magento\Framework\Filesystem
     */
    protected $_filesystem;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $_resourceConnection;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $_coreRead;

    /**
     * @var \Magento\Framework\Filesystem\Directory\ReadInterface
     */
    protected $_mediaDirectory;

    /**
     * @var int
     */
    protected $_limit;

    /**
     * @var bool
     */
    protected $_showPaths = FALSE;

    /**
     * @var bool
     */
    protected $_caseInsensitive = FALSE;

    /**
     * @var int
     */
    protected $_count = 1;

    /**
     * @var array
     */
    protected $_include = [];

    /**
     * @var ProgressBar
     */
    protected $_progressBar;

    /**
     * @var array
     */
    protected $_filesToRemove = [];

    /**
     * RemoveUnusedMediaCommand constructor.
     *
     * @param \Magento\Framework\Filesystem             $filesystem
     * @param \Magento\Framework\App\ResourceConnection $resourceConnection
     * @param string|null                               $name
     */
    public function __construct(
        \Magento\Framework\Filesystem $filesystem,
        \Magento\Framework\App\ResourceConnection $resourceConnection,
        string $name = null
    )
    {
        $this->_filesystem = $filesystem;
        $this->_resourceConnection = $resourceConnection;
        $this->_mediaDirectory = $this->_filesystem->getDirectoryRead(DirectoryList::MEDIA);
        $this->_imageDir = $this->_mediaDirectory->getAbsolutePath() . DIRECTORY_SEPARATOR . 'catalog' . DIRECTORY_SEPARATOR . 'product';
        $this->_coreRead = $this->_resourceConnection->getConnection('core_read');
        parent::__construct($name);
    }

    /**
     * Init command
     */
    protected function configure()
    {
        $this->setName('absolute:clean:unused-media');
        $this->setDescription('Remove product catalog and cached images that are not assigned in the database');
        $this->addOption('dry-run', '-d', InputOption::VALUE_NONE, 'Report what will be removed without actually removing anything');
        $this->addOption('limit', '-l', InputOption::VALUE_OPTIONAL, 'Limit the amount of images to check', -1);
        $this->addOption('include', '-i', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Choose the areas to include in the process. EG `-i product -i cache`', ['product', 'cache']);
        $this->addOption('show-paths', '-p', InputOption::VALUE_NONE, 'Show the file paths that will be removed');
        $this->addOption('case-insensitive', '-c', InputOption::VALUE_NONE, 'Treat the file paths as Case Insensitive');
    }

    /**
     * Execute Command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return void;
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->_output = $output;
        $this->_limit = $input->getOption('limit');
        $this->_isDryRun = $input->getOption('dry-run');
        $this->_include = $input->getOption('include');
        $this->_showPaths = $input->getOption('show-paths');
        $this->_caseInsensitive = $input->getOption('case-insensitive');
        $this->_progressBar = new ProgressBar($output);

        /**
         * Get the images form the database
         */
        $mediaGalleryTable = $this->_resourceConnection->getConnection()->getTableName('catalog_product_entity_media_gallery');
        $this->_databaseImages = $this->_coreRead->fetchCol('SELECT value FROM ' . $mediaGalleryTable, array());
        $this->_databaseImages = array_unique($this->_databaseImages);

        /**
         * Convert to lowercase if we are testing case insensitive file paths
         */
        if($this->_caseInsensitive)
        {
            $this->_databaseImages = array_map('strtolower', $this->_databaseImages);
        }

        /**
         * Gather images into groups to iterate over
         */
        $directoryIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $this->_imageDir,
                \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
            )
        );

        /**
         * Get all the image to check
         */
        foreach ($directoryIterator as $file)
        {
            if(is_dir($file))
            {
                continue;
            }

            if($this->_limit > 0 && $this->_count > $this->_limit)
            {
                break;
            }

            foreach ($this->_include as $include)
            {
                $match = preg_match("/{$this->_mediaAreaPatterns[$include]}/", $file->getPath(), $match);
                if($match)
                {
                    if($include == 'product')
                    {
                        $this->_productImages[] = $file;
                    }
                    elseif($include == 'cache')
                    {
                        $this->_productCacheImages[] = $file;
                    }

                    $this->_count++;
                    break;
                }
            }
        }

        /**
         * Check image paths against the database images to determine which should be removed
         */
        $output->writeln("--------------------------------------------------");
        $this->_progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% [Files to remove: %removed_files%, Size %removed_file_size% MB]');
        $count = count($this->_productImages) + count($this->_productCacheImages);
        $this->_progressBar->start($count);
        $this->checkImages($this->_productImages);
        $this->checkImages($this->_productCacheImages);
        $this->_progressBar->finish();
        $output->writeln(PHP_EOL."--------------------------------------------------");

        /**
         * If not dry run remove the images
         */
        if ( ! $this->_isDryRun)
        {
            $this->renderTable(
                ['WARNING!'],
                [
                    ['About to remove images. This cannot be undone.'],
                    ['This is not a dry run. If you want to do a dry-run, add --dry-run.']
                ],
                'box-double'
            );
            $question = new ConfirmationQuestion('Are you sure you want to continue? [No] ', false);
            $this->_questionHelper = $this->getHelper('question');
            if (!$this->_questionHelper->ask($input, $output, $question)) {
                return;
            }
            $output->writeln(PHP_EOL."--------------------------------------------------");
            $this->_progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% [Removing %removed_files% files, Size %removed_file_size% MB]');
            $this->_progressBar->start(count($this->_filesToRemove));
            $this->removeImages($this->_filesToRemove);
            $this->_progressBar->finish();
            $output->writeln(PHP_EOL."--------------------------------------------------");
        }

        /**
         * Final information
         */
        $removedMb = round(floatval(str_replace(',', '', $this->_removedFileSize / 1024 / 1024)), 2);
        $totalMb = round(floatval(str_replace(',', '', $this->_totalFileSize / 1024 / 1024)), 2);
        $removalPercentage = number_format(($removedMb / $totalMb) * 100, 2);

        if($this->_isDryRun)
        {
            $this->renderTable(
                ['NOTE!'],
                [
                    ['This was a DRY RUN, no files were removed']
                ],
                'box-double'
            );
        }

        /**
         * Render statistics
         */
        $this->renderTable(
            ['Files', 'Amount', 'Size MB', 'Size %'],
            [
                ['Total', $this->_totalFiles, "{$totalMb} MB", '100.00%'],
                ['Removed', $this->_removedFileCount, "{$removedMb} MB", "{$removalPercentage}%"],
            ]
        );
    }

    /**
     * @param array  $headers
     * @param array  $rows
     * @param string $style
     */
    protected function renderTable($headers = [], $rows = [], $style = 'box')
    {
        $table = new Table($this->_output);
        $table->setStyle($style);
        $table->setHeaders($headers);
        $table->setRows($rows);
        $table->render();
    }

    /**
     * @return \Magento\Framework\Filesystem
     */
    public function getFilesystem(): \Magento\Framework\Filesystem
    {
        return $this->_filesystem;
    }

    /**
     * @param array $files
     */
    protected function checkImages($files = [], $pattern = '/\/.\/.\/.*$/')
    {
        foreach ($files as $file)
        {
            $filePath = str_replace($this->_imageDir, "", $file);
            $this->_totalFileSize += filesize($file);
            $this->_totalFiles++;
            if (empty($filePath))
            {
                continue;
            }

            /**
             * We want to check a specific pattern of the file path
             * For product images we want the 2 single character directories in the path
             * As that will match the format of the values in the database
             * EG: '/a/b/example.jpg'
             */
            $matches = [];
            preg_match($pattern, $file->getPathname(), $matches);
            if( ! empty($matches))
            {
                $checkFilePath = $matches[0];
            }
            else
            {
                // Pattern not matched, we should skip this file and not tag it for removal
                continue;
            }

            if($this->_caseInsensitive)
            {
                $checkFilePath = strtolower($checkFilePath);
            }

            if ( ! in_array($checkFilePath, $this->_databaseImages))
            {
                $this->_filesToRemove[] = $file;
                $this->_removedFileSize += filesize($file);
                $this->_removedFileCount++;

                if($this->_showPaths)
                {
                    $this->_output->writeln("Tagged for removal: {$checkFilePath}");
                }
            }
            $this->_progressBar->setMessage($this->_removedFileCount, 'removed_files');
            $this->_progressBar->setMessage(number_format($this->_removedFileSize / 1024 / 1024, 2), 'removed_file_size');
            $this->_progressBar->advance();
        }
    }

    /**
     * @param array $files
     */
    private function removeImages($files = [])
    {
        foreach ($files as $file)
        {
            $this->_progressBar->advance();
            unlink($file);
        }
    }
}