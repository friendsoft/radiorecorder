<?php

namespace Friendsoft\Radiorecorder\Console;

use Friendsoft\Radiorecorder\Radiorecorder;
use Symfony\Component\Console\Output\OutputInterface;
use DateTime;

class RecordConsoleCommand {

    public function __invoke($target, $now, radiorecorder $radiorecorder, OutputInterface $output)
    {
        if (!file_exists($radiorecorder->recordsFile)) {
            throw new \InvalidArgumentException('Record file "' . $radiorecorder->recordsFile . '" not found – please create and refer a file like https://github.com/friendsoft/radiobroadcasts/blob/master/record.dist/record.dist, with un-commented lines of stations and broadcasts.');
        }

        $radiorecorder->setNow($now instanceOf DateTime ? $now : new DateTime($now));
        $radiorecorder->parse();
        $radiorecorder->processRecords(); // generate program, record broadcasts that are due
        $radiorecorder->printProgram();
    }
}
