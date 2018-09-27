<?php

namespace Friendsoft\Radiorecorder;

use Symfony\Component\Process\Process;

/**
 * Streamripper
 *
 * TODO check `which streamripper` etc. (tagging optional)
 * TODO retrieve extension (named automatically) to process file
 */
class Streamripper {

    /**
     * stream url
     *
     * @var string
     */
    protected $url;

    /**
     * duration (in seconds!) to record the stream
     *
     * @var int
     */
    protected $duration;

    /**
     * file path to write stream rip to
     *
     * @var string
     */
    protected $file;

    /**
     * the streamripper process to track
     *
     * @var Process
     */
    protected $process;

    /**
     * hash for current class instance, for debugging parallel processes
     *
     * @var string
     */
    protected $objectHash;

    public function __construct() {
        $this->objectHash = substr(md5(spl_object_hash($this)), 0,5);
    }


    public function setUrl($url) {
        $this->url = (string) $url;

        return $this;
    }

    public function getUrl() {
        return $this->url;
    }

    public function setDuration($minutes) {
        $this->duration = (float) $minutes;

        return $this;
    }

    public function getDuration() {
        return $this->duration;
    }

    public function setFile($file) {
        $this->file = (string) $file;

        return $this;
    }

    public function getFile() {
        return $this->file;
    }

    protected function setProcess (Process $process) {
        $this->process = $process;

        return $this;
    }

    protected function getProcess() {
        return $this->process;
    }

    public function rip() {
        $this->prepareDirectory($this->getFile());
        $command = sprintf(
            'timeout %s wget --ignore-length --read-timeout=15 --timeout=15 --retry-connrefused -O %s %s',
            $this->getDuration(),
            $this->getFile(),
            $this->getUrl()
            //'streamripper %s -l %s -s -t -a "%s" -A -i',
            //$this->getUrl(),
            //$this->getDuration(),
            //$this->getFile()
        );

        echo 'STREAMRIPPER: ' . $this->objectHash, PHP_EOL;
        echo 'COMMAND: ' . $command, PHP_EOL;
        echo 'START RIPPING IN OWN PROCESS – ' . date('r'), PHP_EOL;
        $this->setProcess(new Process($command));
        $this->getProcess()
            ->setTimeout($this->getDuration() + 120)
            ->start()
            ;
        echo 'PROCESS PID: ' . $this->getProcess()->getPid(), PHP_EOL;
        echo 'PROCESS TIMEOUT: ' . $this->getProcess()->getTimeout(), PHP_EOL;
        echo 'END RIP CALL – ' . date('r'), PHP_EOL;

        return $this;
    }

    public function waitAndProceed(Callable $success, Callable $failure, Callable $proceed) {
        echo 'WAIT AND PROCEED DEFINITION: ' . $this->getFile(), PHP_EOL;
        if (!$this->getProcess()) {
            throw new \BadMethodCallException('No process found, call ::rip() first');
        }
        echo 'STREAMRIPPER: ' . $this->objectHash, PHP_EOL;

        $processIsRunning = $this->getProcess()->isRunning();
        $this->getProcess()->wait(function ($type, $buffer) use ($success, $failure, $processIsRunning) {
            echo (Process::ERR === $type ? 'F' : ($processIsRunning ? '+' : '-'));
            call_user_func(Process::ERR === $type ? $failure : $success, $buffer);
        });

        echo PHP_EOL, 'PROCEED: ' . $this->getFile() . ' – ' . ($this->getProcess()->isSuccessful() ? 'SUCCESS' : 'ERROR'), PHP_EOL;
        call_user_func($proceed, $this->getFile(), $this->getProcess()->isSuccessful());
        echo 'PROCEED: ' . $this->getFile() . ' – END', PHP_EOL;
        echo 'OUTPUT: ' . $this->getProcess()->getOutput(), PHP_EOL;
        echo 'I. OUTPUT: ' . $this->getProcess()->getIncrementalOutput(), PHP_EOL;
        echo 'ERROR OUTPUT: ' . $this->getProcess()->getIncrementalErrorOutput(), PHP_EOL;
        echo 'I. ERROR OUTPUT: ' . $this->getProcess()->getIncrementalErrorOutput(), PHP_EOL;

        return $this;
    }

    protected function prepareDirectory($file) {
        $pathinfo = pathinfo($file);
        if (!is_dir($pathinfo['dirname'])) {
            mkdir($pathinfo['dirname'], 0755, true);
        }

        return $this;
    }
}
