<?php

namespace Streamripper;

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
            'streamripper %s -l %s -s -t -a "%s" -A -i',
            $this->getUrl(),
            $this->getDuration(),
            $this->getFile()
        );

        $this->setProcess(new Process($command));
        $this->getProcess()
            ->setTimeout($this->getDuration() + 60)
            ->start()
            ;

        return $this;
    }

    public function waitAndProceed(Callable $success, Callable $failure, Callable $proceed) {
        if (!$this->getProcess()) {
            throw new \BadMethodCallException('No process found, call ::rip() first');
        }
        $this->getProcess()->wait(function ($type, $buffer) use ($success, $failure) {
            call_user_func(Process::ERR === $type ? $failure : $success, $buffer);
        });
        call_user_func($proceed, $this->getFile(), $this->getProcess()->isSuccessful());

        return $this;
    }

    protected function prepareDirectory($file) {
        $pathinfo = pathinfo($file);
        @mkdir($pathinfo['dirname'], 0755, true);

        return $this;
    }
}

