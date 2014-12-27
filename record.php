<?php

namespace Radiorecorder;

use Streamripper\Streamripper;

require_once(__DIR__.'/vendor/autoload.php');
require_once(__DIR__.'/Streamripper.php'); // TODO resolve via component (use as vendor with composer)

/**
 * Radiorecorder
 *
 * TODO add year to crons (by expression or by file names)
 * TODO each hour update symlink dirs:
 *  – by time: 'current week', 'last week', 'current month' etc., using separate component
 *  – by tags: 'tags', with subdirs for each tag
 *
 */
class Radiorecorder {

    /**
     * config (should be constants when used this way, but shall be replaced by injections)
     */
    protected $stationsFile;
    protected $stationsDistDir;
    protected $broadcastsFile;
    protected $broadcastsDistDir;
    public $recordsFile;
    protected $recordsDistDir;
    protected $recordFileNamePattern;

    protected $year; // current year
    protected $week; // current week
    protected $stations = array(); // stations by slug with name, url and optional comment
    protected $broadcasts = array();
    protected $records = array();
    protected $program; // array of records, associated by next due date: date => array(records)

    protected $currentTime;

    public function __construct() {
        $this->currentTime = new \DateTime('now'); // overwrite for testing (TODO make cli option)
        //$this->currentTime = new \DateTime('2014-12-27 19:00'); // overwrite for testing (TODO make cli option)
        $this->year = $this->currentTime->format('Y');
        $this->week = $this->currentTime->format('W');
        $this->stationsFile = __DIR__.'/config/stations';
        $this->stationsDistDir = __DIR__.'/vendor/friendsoft/radiobroadcasts/stations.dist';
        $this->broadcastsFile = __DIR__.'/config/broadcasts';
        $this->broadcastsDistDir = __DIR__.'/vendor/friendsoft/radiobroadcasts/broadcasts.dist';
        $this->recordsFile = __DIR__.'/config/record';
        $this->recordsDistDir = __DIR__.'/vendor/friendsoft/radiobroadcasts/record.dist';
        $this->recordFileNamePattern = '/data/media/Musik/radiorecorder/%STATION%/%STATION%--%BROADCASTNAME%--%YEAR%-%MONTH%-%DAY%.%FORMAT%'; // TODO resolve using config file
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

            /* CRON: CUSTOM PARTS */
            /* Weeks */
            /* TODO extend CronExpression to handle that */
            /* week of year (woy) */
            $weeks = array();
            if (strpos($preNameParts[8], '=')) {
                list($weekInterval, $weekRemainder) = sscanf($preNameParts[8], '%%%d=%d');
                $weeks = array(
                    'expression' => $preNameParts[8],
                    'interval' => $weekInterval,
                    'remainder' => $weekRemainder,
                    'matches' => $this->week % $weekInterval === $weekRemainder
                );
            }
            /* week of month (wom) */
            else {
                $dow_by_wom = array();
                $dows = explode(',', $cron['dow']);
                $woms = explode(',', $preNameParts[8]);
                foreach ($dows as $dow) {
                    foreach ($woms as $wom) {
                        $dow_by_wom[] = $dow . '#' . $wom;
                    }
                }
                $cron['dow'] = join(',', $dow_by_wom);
            }

            $cronExpression = \Cron\CronExpression::factory(join(' ', $cron));
            if ($cronExpression->isDue($this->currentTime) && array_key_exists('matches', $weeks) && $weeks['matches']) {
                $cron['due'] = true;
                $cron['date'] = $cronExpression->getPreviousRunDate($this->currentTime, 0, true);
            }
            else {
                $cron['due'] = false;
                if (isset($weekInterval)) {
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
                }
                else {
                    $date = new \DateTime();
                    $date->setISODate($this->year, $this->week);
                }
                $cron['date'] = $cronExpression->getNextRunDate($date);

                //echo $cron['date']->format('d. m. Y'), PHP_EOL;
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
                    'url' => isset($postNameParts[1]) ? $postNameParts[1] : ''
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
        // TODO bootstrap
        $success = function($buffer) { /*echo '.'; */ };
        $failure = function($buffer) { echo 'ERROR: ' . $buffer; };

        // TODO retrieve stream format, e. g. ogg/vorbis for mephisto976, tag accordingly
        // http://stackoverflow.com/questions/23287341/how-to-get-mime-type-of-a-file-in-php-5-5/23287361#23287361

        $writer = new \GetId3\Write\Tags();
        $writer->tagformats = array('id3v2.3');
        $writer->tag_encoding = 'UTF-8';

        $streamrippers = array();

        // TODO cache that stuff
        foreach ($this->records as $request) {
            $record = array(
                'station' => $this->stations[$request['station']],
                'broadcast' => $this->broadcasts[$request['station']][$request['broadcast']],
                'comment' => $request['comment']
            );
            /* BEGINN ALL DUE RECORDINGS, THEN WAIT ON FINISHED RECORDS TO TAG THEM */
            foreach ($record['broadcast']['cron'] as $cron) {
                if (/*'Radia' === $record['broadcast']['name'] ||*/ $cron['due']) {
                    // TODO make class property $this->streamrippers
                    echo 'DUE: ' . $record['broadcast']['name'], PHP_EOL;
                    //var_dump($cron);
                    $record['active-cron'] = $cron;
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
                    $streamripper = $this->record($record);
                    // index by duration to ensure shortest broadcast are proceeded first
                    $streamrippers[$streamripper->getDuration()][] = array(
                        'instance' => $streamripper,
                        'proceed' => $proceed
                    );
                    echo 'RECORDING STARTED', PHP_EOL;
                }
                $this->program[$record['station']['name']][$cron['date']->format('U')][] =
                    array('cron' => $cron, 'record' => $record);
            }
        }

        // call wait and proceedures only after each recording has been started
        ksort($streamrippers);
        foreach ($streamrippers as $duration => $streamrippers_dur) {
            foreach ($streamrippers_dur as $streamripper) {
                $streamripper['instance']->waitAndProceed($success, $failure, $streamripper['proceed']);
            }
        }
    }

    public function record($record) {
        $file = $this->generateBroadcastFileName($record);
        echo 'RECORD: ', $file, PHP_EOL;
        $streamripper = new Streamripper(); // TODO inject, implement
        $streamripper
            ->setUrl($record['station']['url'])
            ->setDuration($record['broadcast']['minutes'] * 60)
            //->setDuration(rand(7,14)) // for testing only, in seconds
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
        if (!$this->program) {
            $this->info('No program entries found.');
            return $this;
        }
        ksort($this->program);
        foreach ($this->program as $station => $records) {
            ksort($records);
            foreach ($records as $time => $timeRecords) {
                foreach ($timeRecords as $timeRecord) {
                    if ($timeRecord['cron']['date']->format('W') !== $week) {
                        continue;
                    }
                    $this->info(
                        $timeRecord['record']['station']['name'] . ' – ' .
                        $timeRecord['record']['broadcast']['name'] . ': ' . PHP_EOL .
                        $timeRecord['cron']['date']->format('D d/m/Y H:i') . ' (' .
                        $timeRecord['record']['broadcast']['minutes'] . ' min)' . PHP_EOL .
                        $timeRecord['record']['broadcast']['url'] . PHP_EOL .
                        '#' . join(' #', $timeRecord['record']['broadcast']['tags']) . PHP_EOL
                    );
                }
            }
            $this->info(''); // just for linebreak
        }
    }
}

$radiorecorder = new Radiorecorder();
if (!file_exists($radiorecorder->recordsFile)) {
    throw new \InvalidArgumentException('Record file "' . $radiorecorder->recordsFile . '" not found – please create and refer a file like https://github.com/friendsoft/radiobroadcasts/blob/master/record.dist/record.dist, with un-commented lines of stations and broadcasts.');
}
$radiorecorder->processRecords(); // generate program, record broadcasts that are due
$radiorecorder->printProgram();
