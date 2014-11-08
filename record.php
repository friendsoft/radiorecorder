<?php

namespace Radiorecorder;

use Streamripper\Streamripper;

require_once(__DIR__.'/vendor/autoload.php');
require_once(__DIR__.'/Streamripper.php'); // TODO resolve via component (use as vendor with composer)

/**
 * Radiorecorder
 *
 * TODO add year to crons (by expression or by file names)
 *
 */
class Radiorecorder {

    /**
     * config (should be constants when used this way, but shall be replaced by injections)
     */
    protected $stationsFile = __DIR__.'/config/stations';
    protected $stationsDistDir = __DIR__.'/config/stations.dist';
    protected $broadcastsFile = __DIR__.'/config/broadcasts';
    protected $broadcastsDistDir = __DIR__.'/config/broadcasts.dist';
    protected $recordsFile = __DIR__.'/config/record';
    protected $recordsDistDir = __DIR__.'/config/record.dist';
    protected $recordFileNamePattern = '/data/media/Musik/radiorecorder_dev/%STATION%/%STATION%--%BROADCASTNAME%--%YEAR%-%MONTH%-%DAY%.%FORMAT%';

    protected $year; // current year
    protected $week; // current week
    protected $stations = array(); // stations by slug with name, url and optional comment
    protected $broadcasts = array();
    protected $records = array();
    protected $program; // array of records, associated by next due date: date => array(records)

    public function __construct() {
        $this->year = date('Y');
        $this->week = date('W');
        $this->parseStations($this->stationsDistDir);
        $this->parseStations($this->stationsFile);
        $this->parseBroadcasts($this->broadcastsDistDir);
        $this->parseBroadcasts($this->broadcastsFile);
        $this->parseRecords($this->recordsDistDir);
        $this->parseRecords($this->recordsFile);
    }

    public function info($message) {
        echo $message, PHP_EOL;
    }

    protected function getFileEntries($file) {
        if (!is_file($file)) {
                //$this->info('File "' . $file . '" does not exist, please check your config');
                return array();
        }
        return explode("\n", file_get_contents($file));
    }

    /**
     * call a method recursively on each (sub)directory found
     *
     * @param string $method method name (of this class)
     * @param string $fileOrDir path to file or directory
     */
    protected function callDirsRecursively($method, $fileOrDir) {
        if (!is_dir($fileOrDir)) {
            return;
        }
        $dirIterator = new \DirectoryIterator($fileOrDir);
        foreach ($dirIterator as $file) {
            if ($file->isDot() || $file->getExtension() /* ignore *.swp etc. */) {
                continue;
            }
            $this->$method($file->getPathname());
        }
    }

    /**
     * parse station details from stations file
     * (can be done several times; with global files read in first and local
     *  ones last to overwrite URLs)
     *
     * @param string $stationsFile (path, may be a directory, too)
     * @return Radiorecorder
     */
    protected function parseStations($stationsFile) {
        $this->callDirsRecursively('parseStations', $stationsFile);
        $entries = $this->getFileEntries($stationsFile);
        foreach ($entries as $entry) {
            if (!$entry || 0 === strpos($entry, '#')) {
                continue;
            }
            $parts = explode('"', $entry);
            $slug = trim($parts[0]);
            $name = trim($parts[1]);
            $rest = explode('#', $parts[2]);
            $url = trim($rest[0]);
            $comment = isset($rest[1]) ? trim($rest[1]) : '';
            $this->stations[$slug] = array('name' => $name, 'url' => $url, 'comment' => $comment);
        }
        ksort($this->stations);

        return $this;
    }

    /**
     * parse broadcasts from broadcasts file
     * (can be done several times; with global files read in first and local
     *  ones last to overwrite details)
     *
     * @param string $broadcastsFile (path, may be a directory, too)
     * @return Radiorecorder
     */
    protected function parseBroadcasts($broadcastsFile) {
        $this->callDirsRecursively('parseBroadcasts', $broadcastsFile);
        $entries = $this->getFileEntries($broadcastsFile);
        foreach ($entries as $entry) {
            if (!$entry || 0 === strpos($entry, '#')) {
                continue;
            }
            $parts = explode('"', $entry);
            $preNameParts = explode(' ', preg_replace('/\s+/', ' ', trim($parts[0])));
            $postNameParts = explode(' ', preg_replace('/\s+/', ' ', trim($parts[2])));

            $station = $preNameParts[0];
            $broadcast = $preNameParts[1];

            /* CRON: REGULAR PARTS */
            $cron = array(
                    'm' => $preNameParts[2],
                    'h' => $preNameParts[3],
                    'dom' => $preNameParts[4],
                    'mon' => $preNameParts[5],
                    'dow' => $preNameParts[6],
                    'year' => '*' //$preNameParts[7],
            );
            $cronExpression = \Cron\CronExpression::factory(join(' ', $cron));

            /* CRON: CUSTOM PARTS */
            /* Weeks */
            /* TODO extend CronExpression to handle that */
            list($weekInterval, $weekRemainder) = sscanf($preNameParts[8], '%%%d=%d');
            $weeks = array(
                'expression' => $preNameParts[8],
                'interval' => $weekInterval,
                'remainder' => $weekRemainder,
                'matches' => $this->week % $weekInterval === $weekRemainder
            );

            if ($cronExpression->isDue() && $weeks['matches']) {
                $cron['due'] = true;
                $cron['date'] = $cronExpression->getPreviousRunDate('now', 0, true);
            }
            else {
                $cron['due'] = false;
                $nextValidWeek = $weekInterval*(floor($this->week / $weekInterval)) + $weekRemainder;
                if ($nextValidWeek < $this->week) {
                    $nextValidWeek += $weekInterval; // e. g. %4=0 in week 45 would return 44
                }
                if ($nextValidWeek <= 52) {
                    $date = new \DateTime();
                    $date->setISODate($this->year, $nextValidWeek);
                }
                else {
                    $date = new \DateTime();
                    $date->setISODate($this->year + 1, $nextValidWeek - 52);

                }
                $cron['date'] = $cronExpression->getNextRunDate($date);
            }

            $cron['weeks'] = $weeks;

            // BROADCAST: ADD CRON DETAILS
            if (isset($this->broadcasts[$station][$broadcast])) {
                $this->broadcasts[$station][$broadcast]['cron'][] = $cron;
            }
            else {
                $this->broadcasts[$station][$broadcast] = array( // create new broadcast
                    'name' => $parts[1],
                    'cron' => array($cron),
                    'minutes' => $preNameParts[9],
                    'tags' => explode(',', $postNameParts[0]),
                    'url' => $postNameParts[1]
                );
            }

        }


        return $this;
    }

    /**
     * parse records to be scheduled
     *
     * @param string $recordsFile (path to dir or file containing record selections)
     * @return Radiorecorder
     */
    protected function parseRecords($recordsFile) {
        $this->callDirsRecursively('parseRecords', $recordsFile);
        $entries = $this->getFileEntries($recordsFile);
        foreach ($entries as $entry) {
            if (!$entry || 0 === strpos($entry, '#')) {
                continue;
            }
            $parts = explode('#', $entry);
            list($station, $broadcast) = explode(' ', preg_replace('/\s+/', ' ', trim($parts[0])));
            $comment = isset($parts[1]) ? trim($parts[1]) : '';

            $record = array(
                'station' => $station,
                'broadcast' => $broadcast,
                'comment' => $comment
            );

            $this->records[] = $record;

        }

        return $this;

    }

    protected function generateBroadcastFileName($record) {
        $replacements = array(
            '%STATION%' => $record['station']['name'],
            '%BROADCASTNAME%' => $record['broadcast']['name'],
            '%YEAR%' => $record['active-cron']['date']->format('Y'),
            '%MONTH%' => $record['active-cron']['date']->format('m'),
            '%DAY%' => $record['active-cron']['date']->format('d'),
            '%FORMAT%' => 'mp3' // TODO
        );

        $file = str_replace(array_keys($replacements), $replacements, $this->recordFileNamePattern);
        $file = str_replace(' ', '_', $file);
        $file = preg_replace("/[^A-Za-z0-9_+.-]\/~/", '_', $file);

        return $file;
    }


    public function processRecords() {
        // TODO cache that stuff
        foreach ($this->records as $request) {
            $record = array(
                'station' => $this->stations[$request['station']],
                'broadcast' => $this->broadcasts[$request['station']][$request['broadcast']],
                'comment' => $request['comment']
            );
            /* BEGINN ALL DUE RECORDINGS, THEN WAIT ON FINISHED RECORDS TO TAG THEM */
            $streamrippers = array();
            foreach ($record['broadcast']['cron'] as $cron) {
                if (/*'Radia' === $record['broadcast']['name'] ||*/ $cron['due']) {
                    // TODO make class property $this->streamrippers
                    $record['active-cron'] = $cron;
                    $streamrippers[] = $this->record($record);
                }
                $this->program[$record['station']['name']][$cron['date']->format('U')][] =
                    array('cron' => $cron, 'record' => $record);
            }

            $success = function($buffer) { /*echo '.'; */ };
            $failure = function($buffer) { echo 'ERROR: ' . $buffer; };

            $writer = new \GetId3\Write\Tags();
            $writer->tagformats = array('id3v2.3');
            $writer->tag_encoding = 'UTF-8';

            $proceed = function($file, $suceeded) use ($writer, $record) {
                // TODO mp3 conversion of aac files
                //echo PHP_EOL, $suceeded ? 'DONE' : 'FAIL', ': ', $file, PHP_EOL;
                $writer->filename = $file;
                $writer->tag_data = array(
                    'ARTIST' => array($record['station']['name']),
                    'TITLE' => array(sprintf('%s: %s/%s/%s',
                        $record['broadcast']['name'],
                        $record['active-cron']['date']->format('d'),
                        $record['active-cron']['date']->format('m'),
                        $record['active-cron']['date']->format('Y')
                    )),
                    'ALBUM' => array($record['broadcast']['name']),
                    'YEAR' => array($record['active-cron']['date']->format('Y')),
                    'COMMENT' => array('' ?: 'Radiorecorder 2014'),
                    'GENRE' => array(isset($record['broadcast']['tags'][0]) ? ucfirst(strtolower($record['broadcast']['tags'][0])) : '')
                );
                if (!$writer->WriteTags()) {
                    echo 'Tags not written!', PHP_EOL; // TODO handle failure
                };
                if ($writer->errors) {
                    var_dump('errors: ', $writer->errors); // TODO handle failure
                };
                if ($writer->warnings) {
                    var_dump('warnings: ', $writer->warnings); // TODO handle failure
                };
            };

            foreach ($streamrippers as $streamripper) {
                $streamripper->waitAndProceed($success, $failure, $proceed);
            }


        }
    }

    public function record($record) {
        $file = $this->generateBroadcastFileName($record);
        echo $file, PHP_EOL;
        $streamripper = new Streamripper(); // TODO inject, implement
        $streamripper
            ->setUrl($record['station']['url'])
            ->setDuration($record['broadcast']['minutes'] * 60)
            //->setDuration(2) // for testing only
            ->setFile($file)
            ->rip()
        ;

        return $streamripper;

        // TODO log
        // TODO allow tokens in broadcast URLs, e. g. http://byte.fm/sendung/tiefenschaerfe/2014-10-02 => http://byte.fm/sendung/tiefenschaerfe/%Y%-%m%-%d%
        // TODO add teaser to logfile :) (use separate tool) => Waschzettel für jede Sendung anlegen, einheitliches Format (ähnlich newsbeuter/wm3), daraus Logfile anfüllen
        //      ... selbe slugs verwenden für config, ggf. yaml (Parameter können stärker abweichen je nach Sender und Sendung)
        //      ... Waschzettel sollte Status enthalten (ob vollständig oder noch zu erstellen/aktualisieren)
        //      ... Gesamtübersichten nach Sendung, Sender und alle Sendersendungen gleichzeitig füllen (nur Sendungstitel/Tags)
        //      ... Playlists ggf. aber ebenfalls in einheitliches Format konvertieren und durchsuchbar machen ;)
    }

    public function printProgram($week = null) {
        if ((int) date('i') !== 0) {
            return;
        }
        $week = $week ?: $this->week;
        ksort($this->program);
        foreach ($this->program as $station => $records) {
            ksort($records);
            foreach ($records as $time => $timeRecords) {
                foreach ($timeRecords as $timeRecord) {
                    if ($timeRecord['cron']['date']->format('W') !== $week) {
                        continue;
                    }
                    echo
                        $timeRecord['record']['station']['name'], ' – ',
                        $timeRecord['record']['broadcast']['name'], ': ', PHP_EOL,
                        $timeRecord['cron']['date']->format('D d/m/Y H:i'), ' (',
                        $timeRecord['record']['broadcast']['minutes'], ' min)', PHP_EOL,
                        $timeRecord['record']['broadcast']['url'], PHP_EOL,
                        '#' . join(' #', $timeRecord['record']['broadcast']['tags']), PHP_EOL,
                        PHP_EOL
                    ;
                }
            }
            echo PHP_EOL;
        }
    }
}

$radiorecorder = new Radiorecorder();
$radiorecorder->processRecords(); // generate program, record broadcasts that are due
$radiorecorder->printProgram();
