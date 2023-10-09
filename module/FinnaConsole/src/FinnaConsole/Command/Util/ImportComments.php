<?php

/**
 * Console service for importing record comments.
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2018-2023.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Service
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */

namespace FinnaConsole\Command\Util;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VuFind\Exception\RecordMissing as RecordMissingException;

use function count;

/**
 * Console service for importing record comments.
 *
 * @category VuFind
 * @package  Service
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     http://vufind.org/wiki/vufind2:developer_manual Wiki
 */
class ImportComments extends AbstractUtilCommand
{
    /**
     * The name of the command (the part after "public/index.php")
     *
     * @var string
     */
    protected static $defaultName = 'util/import_comments';

    /**
     * Comments table
     *
     * @var \Finna\Db\Table\Comments
     */
    protected $commentsTable;

    /**
     * CommentsRecord table
     *
     * @var \Finna\Db\Table\CommentsRecord
     */
    protected $commentsRecordTable;

    /**
     * Resource table
     *
     * @var \Finna\Db\Table\Comments
     */
    protected $resourceTable;

    /**
     * Ratings table
     *
     * @var \VuFind\Db\Table\Ratings
     */
    protected $ratingsTable;

    /**
     * Record loader
     *
     * @var \VuFind\Record\Loader
     */
    protected $recordLoader;

    /**
     * Search runner
     *
     * @var \VuFind\Search\SearchRunner
     */
    protected $searchRunner;

    /**
     * Log file
     *
     * @var string
     */
    protected $logFile;

    /**
     * Whether to output verbose messages
     *
     * @var bool
     */
    protected $verbose = false;

    /**
     * Constructor
     *
     * @param \Finna\Db\Table\Comments       $comments       Comments table
     * @param \Finna\Db\Table\CommentsRecord $commentsRecord CommentsRecord table
     * @param \Finna\Db\Table\Resource       $resource       Resource table
     * @param \VuFind\Db\Table\Ratings       $ratings        Ratings table
     * @param \VuFind\Record\Loader          $recordLoader   Record loader
     * @param \VuFind\Search\SearchRunner    $searchRunner   Search runner
     */
    public function __construct(
        \Finna\Db\Table\Comments $comments,
        \Finna\Db\Table\CommentsRecord $commentsRecord,
        \Finna\Db\Table\Resource $resource,
        \VuFind\Db\Table\Ratings $ratings,
        \VuFind\Record\Loader $recordLoader,
        \VuFind\Search\SearchRunner $searchRunner
    ) {
        $this->commentsTable = $comments;
        $this->commentsRecordTable = $commentsRecord;
        $this->resourceTable = $resource;
        $this->ratingsTable = $ratings;
        $this->recordLoader = $recordLoader;
        $this->searchRunner = $searchRunner;
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setDescription('Import comments and/or ratings from a CSV file.')
            ->addArgument(
                'source',
                InputArgument::REQUIRED,
                'Datasource ID in the index'
            )
            ->addArgument(
                'file',
                InputArgument::REQUIRED,
                'CSV file with record id, date, comment and/or rating'
            )
            ->addArgument(
                'log',
                InputArgument::REQUIRED,
                'Log file for results'
            )
            ->addOption(
                'default-date',
                null,
                InputOption::VALUE_REQUIRED,
                'Date to use for records without a valid timestamp (default is current date)'
            )
            ->addOption(
                'user-id',
                null,
                InputOption::VALUE_REQUIRED,
                'User id (id column in database) to associate with the comments (default is none)'
            )
            ->addOption(
                'separator',
                null,
                InputOption::VALUE_REQUIRED,
                'Separator character (default is ,)'
            )
            ->addOption(
                'enclosure',
                null,
                InputOption::VALUE_REQUIRED,
                'Enclosure character (default is ")'
            )
            ->addOption(
                'escape',
                null,
                InputOption::VALUE_REQUIRED,
                'Escape character (default is \\)'
            )
            ->addOption(
                'id-fields',
                null,
                InputOption::VALUE_REQUIRED,
                'A comma-separator list of index fields to use to search for records (default is only id)'
            )
            ->addOption(
                'rating-multiplier',
                null,
                InputOption::VALUE_REQUIRED,
                'Rating multiplier to result in range 0-100'
            )
            ->addOption(
                'id-column',
                null,
                InputOption::VALUE_REQUIRED,
                'ID column number (default is 1)'
            )
            ->addOption(
                'date-column',
                null,
                InputOption::VALUE_REQUIRED,
                'Date column number (default is 2, set to 0 to disable)'
            )
            ->addOption(
                'comment-column',
                null,
                InputOption::VALUE_REQUIRED,
                'Comment column number (default is 3, set to 0 to disable)'
            )
            ->addOption(
                'rating-column',
                null,
                InputOption::VALUE_REQUIRED,
                'Rating column number (default is 4, set to 0 to disable)'
            );
    }

    /**
     * Run the command.
     *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $sourceId = $input->getArgument('source');
        $importFile = $input->getArgument('file');
        $this->logFile = $input->getArgument('log');
        $defaultDate = $input->getOption('default-date');
        $userId = $input->getOption('user-id');
        $idFields = explode(',', $input->getOption('id-fields') ?? 'id');
        $ratingMultiplier = (float)($input->getOption('rating-multiplier') ?? 1);
        $separator = $input->getOption('separator') ?? ',';
        $enclosure = $input->getOption('enclosure') ?? '"';
        $escape = $input->getOption('escape') ?? '\\';
        $idColumn = (int)($input->getOption('id-column') ?? 1);
        $dateColumn = (int)($input->getOption('date-column') ?? 2);
        $commentColumn = (int)($input->getOption('comment-column') ?? 3);
        $ratingColumn = (int)($input->getOption('rating-column') ?? 4);
        $this->verbose = $output->isVerbose();

        if (!$idColumn || (!$commentColumn && !$ratingColumn)) {
            $this->log('ID column and at least one of comment or rating columns is required', true);
            return 1;
        }

        $defaultTimestamp = strtotime(
            date('Y-m-d', $defaultDate ? strtotime($defaultDate) : time())
        );

        $this->log("Started import of $importFile", true);
        $this->log('Default date is ' . date('Y-m-d', $defaultTimestamp), true);

        $count = 0;
        $commentCount = 0;
        $ratingCount = 0;

        if (($fh = fopen($importFile, 'r')) === false) {
            $this->log('Could not open import file for reading', true);
            return 1;
        }
        while (($data = fgetcsv($fh, null, $separator, $enclosure, $escape)) !== false) {
            ++$count;
            $num = count($data);
            if ($num < 2) {
                $this->log(
                    "Could not read CSV line $count (only $num elements found)",
                    true
                );
                return 1;
            }
            // Prepend an element to align column indexes with data:
            array_unshift($data, false);
            $id = $data[$idColumn] ?? null;
            if ($dateColumn) {
                $timestamp = $data[$dateColumn] === '\N'
                    ? $defaultTimestamp + $count
                    : strtotime($data[$dateColumn]);
            } else {
                $timestamp = $defaultTimestamp;
            }
            $timestampStr = date('Y-m-d H:i:s', $timestamp);
            $rating = $ratingColumn ? ($data[$ratingColumn] ?? null) : null;
            if (!$commentColumn) {
                $commentString = null;
            } else {
                $commentString = $data[$commentColumn];
                $commentString = preg_replace('/\\\\([^\\\\])/', '\1', $commentString);
            }
            if (null !== $rating) {
                $rating = round($ratingMultiplier * (float)$rating);
                if ($rating < 0 || $rating > 100) {
                    $this->log("Invalid rating '$rating' on row $count", true);
                    continue;
                }
                if ($rating < 10) {
                    // Minimum is a half star
                    $rating = 10;
                }
            }

            if (!($driver = $this->findRecord($sourceId, $id, $idFields))) {
                $this->log("Record $id ($sourceId.$id) not found");
                continue;
            }
            $recordId = $driver->getUniqueID();

            try {
                $resource = $this->resourceTable->findResource($driver->getUniqueID());
            } catch (RecordMissingException $e) {
                $this->log('Record ' . $driver->getUniqueID() . ' not found when trying to create a resource entry');
                continue;
            }

            $duplicate = false;
            if ($commentString) {
                // Check for duplicates
                $comments = $this->commentsTable->getForResource($recordId);
                foreach ($comments as $comment) {
                    if (
                        $comment->created === $timestampStr
                        && $comment->comment === $commentString
                    ) {
                        $this->log(
                            "Comment on row $count for $recordId already exists"
                        );
                        $duplicate = true;
                        break;
                    }
                }

                if (!$duplicate) {
                    $row = $this->commentsTable->createRow();
                    if ($userId) {
                        $row->user_id = $userId;
                    }
                    $row->resource_id = $resource->id;
                    $row->comment = $commentString;
                    $row->created = $timestampStr;
                    $row->save();
                    $cr = $this->commentsRecordTable->createRow();
                    $cr->record_id = $recordId;
                    $cr->comment_id = $row->id;
                    $cr->save();
                    $this->log("Added comment {$row->id} for record $recordId (row $count)");
                    ++$commentCount;
                }
            }
            if ($rating && !$duplicate) {
                $ratingRow = $this->ratingsTable->createRow();
                $ratingRow->resource_id = $resource->id;
                $ratingRow->created = $timestampStr;
                $ratingRow->rating = $rating;
                $ratingRow->save();
                $this->log("Added rating {$ratingRow->id} for record $recordId (row $count)");
                ++$ratingCount;
            }
            if ($count % 1000 === 0) {
                $this->log("$count rows processed", true);
            }
        }
        fclose($fh);
        $this->log(
            "Import completed with $count rows processed; $commentCount comments and $ratingCount ratings imported",
            true
        );

        return 0;
    }

    /**
     * Find a record
     *
     * @param string $sourceId Source ID
     * @param string $id       Raw ID
     * @param array  $idFields Fields to search
     *
     * @return ?\VuFind\RecordDriver\AbstractBase Record driver or null if not found
     */
    protected function findRecord(
        string $sourceId,
        string $id,
        array $idFields
    ): ?\VuFind\RecordDriver\AbstractBase {
        $recordId = $id;
        $idPrefix = $sourceId . '.';
        if (!str_starts_with($recordId, $idPrefix)) {
            $recordId = $idPrefix . $recordId;
        }

        foreach ($idFields as $field) {
            if ('id' === $field) {
                $driver = $this->recordLoader->load($recordId, DEFAULT_SEARCH_BACKEND, true);
                if (!($driver instanceof \VuFind\RecordDriver\Missing)) {
                    return $driver;
                }
            } else {
                $request = [
                    'lookfor' => "$field:\"" . addcslashes($id, '"') . '"',
                    'filter' => [
                        'source_str_mv:"' . addcslashes($sourceId, '"') . '"',
                        'finna.deduplication:0',
                    ],
                ];
                $results = $this->searchRunner->run($request);
                if ($results->getResultTotal() > 0) {
                    return $results->getResults()[0];
                }
            }
        }
        return null;
    }

    /**
     * Write a log message
     *
     * @param string $msg    Message
     * @param bool   $screen Whether to output the message on screen too
     *
     * @return void
     */
    protected function log($msg, $screen = false)
    {
        if ($this->logFile) {
            if (false === file_put_contents($this->logFile, date('Y-m-d H:i:s') . " $msg\n", FILE_APPEND)) {
                die("Failed to write to log file\n");
            }
        }
        if ($screen || $this->verbose) {
            $this->msg($msg);
        }
    }
}
