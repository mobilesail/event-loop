<?php

namespace React\EventLoop;

use React\EventLoop\Tick\FutureTickQueue;
use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\TimerInterface;
use React\EventLoop\Timer\Timers;

/**
 * A stream_select() based event-loop.
 */
class StreamSelectLoop implements LoopInterface
{
    const MICROSECONDS_PER_SECOND = 1000000;

    private $futureTickQueue;
    private $timers;
    private $readStreams = [];
    private $readListeners = [];
    private $writeStreams = [];
    private $writeListeners = [];
    private $enterIdleStreams = [];
    private $enterIdleListeners = [];
    private $enterIdleInitTime = 0;
    private $enterIdleTimeOut = 0;
    private $enterIdleStatus = false;
    private $signalInterruptStreams = [];
    private $signalInterruptListeners = [];
    private $onWakeStreams = [];
    private $onWakeListeners = [];
    
    private $running;

    public function __construct()
    {
        $this->futureTickQueue = new FutureTickQueue();
        $this->timers = new Timers();
    }

    /**
     * {@inheritdoc}
     */
    public function addReadStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readStreams[$key])) {
            $this->readStreams[$key] = $stream;
            $this->readListeners[$key] = $listener;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function addWriteStream($stream, callable $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeStreams[$key])) {
            $this->writeStreams[$key] = $stream;
            $this->writeListeners[$key] = $listener;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->readStreams[$key],
            $this->readListeners[$key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->writeStreams[$key],
            $this->writeListeners[$key]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function removeStream($stream)
    {
        $this->removeReadStream($stream);
        $this->removeWriteStream($stream);
    }

    /**
     * {@inheritdoc}
     */
    public function addTimer($interval, callable $callback)
    {
        $timer = new Timer($interval, $callback, false);

        $this->timers->add($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function addPeriodicTimer($interval, callable $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $this->timers->add($timer);

        return $timer;
    }

    /**
     * {@inheritdoc}
     */
    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers->cancel($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function isTimerActive(TimerInterface $timer)
    {
        return $this->timers->contains($timer);
    }

    /**
     * {@inheritdoc}
     */
    public function futureTick(callable $listener)
    {
        $this->futureTickQueue->add($listener);
    }
    
    public function setEnterIdleTimeOut($enterIdleTimeOut){
        $this->enterIdleTimeOut = $enterIdleTimeOut;
    }
    
    public function addEnterIdle($stream, $listener){
        $key = (int) $stream;

        if (!isset($this->enterIdleListeners[$key])) {
            $this->enterIdleStreams[$key] = $stream;
            $this->enterIdleListeners[$key] = $listener;
        }
    }
    
    public function removeEnterIdle($stream)
    {
        $key = (int) $stream;

        unset(
            $this->enterIdleStreams[$key],
            $this->enterIdleListeners[$key]
        );
    }
    
    public function addSignalInterrupted($stream, $listener){
        $key = (int) $stream;

        if (!isset($this->signalInterruptListeners[$key])) {
            $this->signalInterruptStreams[$key] = $stream;
            $this->signalInterruptListeners[$key] = $listener;
        }
    }
    
    public function removeSignalInterrupted($stream)
    {
        $key = (int) $stream;

        unset(
            $this->signalInterruptStreams[$key],
            $this->signalInterruptListeners[$key]
        );
    }
    
    public function addOnWake($stream, $listener){
        $key = (int) $stream;

        if (!isset($this->onWakeListeners[$key])) {
            $this->onWakeStreams[$key] = $stream;
            $this->onWakeListeners[$key] = $listener;
        }
    }
    
    public function removeOnWake($stream)
    {
        $key = (int) $stream;

        unset(
            $this->onWakeStreams[$key],
            $this->onWakeListeners[$key]
        );
    }
    
    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            $this->timers->tick();

            // Future-tick queue has pending callbacks ...
            if (!$this->running || !$this->futureTickQueue->isEmpty()) {
                $timeout = 0;

            // There is a pending timer, only block until it is due ...
            } elseif ($scheduledAt = $this->timers->getFirst()) {
                $timeout = $scheduledAt - $this->timers->getTime();
                if ($timeout < 0) {
                    $timeout = 0;
                } else {
                    /*
                     * round() needed to correct float error:
                     * https://github.com/reactphp/event-loop/issues/48
                     */
                    $timeout = round($timeout * self::MICROSECONDS_PER_SECOND);
                }

            // The only possible event is stream activity, so wait forever ...
            } elseif ($this->readStreams || $this->writeStreams) {
                $timeout = null;

            // There's nothing left to do ...
            } else {
                break;
            }
            
            if($timeout == 0 || $timeout == null && $this->enterIdleTimeOut > 0){
                $timeout = $this->enterIdleTimeOut;
            }
            
            $this->waitForStreamActivity($timeout);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
    }

    /**
     * Wait/check for stream activity, or until the next timer is due.
     */
    private function waitForStreamActivity($timeout)
    {
        $read  = $this->readStreams;
        $write = $this->writeStreams;
        
        $init_wait_mtime = (microtime(true) * self::MICROSECONDS_PER_SECOND);
        
        $available = $this->streamSelect($read, $write, $timeout);
        
        $end_wait_mtime = (microtime(true) * self::MICROSECONDS_PER_SECOND);
        
        if (false === $available) {
            // if a system call has been interrupted,
            // we cannot rely on it's outcome
            return;
        }
        
        //check if SignalInterrupted or EnterIdle 
        if (0 == $available && $timeout !== null) {
            
            $diff_wait_mtime = ($end_wait_mtime - $init_wait_mtime);
            $diff_wait_mstime = ($end_wait_mtime - $init_wait_mtime) / 1000;
            $this->_writeLog("SOSig: $end_wait_mtime - $init_wait_mtime = $diff_wait_mtime = $diff_wait_mstime ms");
            $this->_writeLog("SOSig: ($diff_wait_mtime >= $timeout)".($diff_wait_mtime >= $timeout)." ".(($diff_wait_mtime - $timeout) / 1000));
            
            
            $diff_idle_mtime = ($end_wait_mtime - $this->enterIdleInitTime);
            $diff_wait_mstime = ($end_wait_mtime - $this->enterIdleInitTime) / 1000;
            $this->_writeLog("SOIdle: $end_wait_mtime - $this->enterIdleInitTime = $diff_idle_mtime = $diff_wait_mstime ms");
            $this->_writeLog("SOIdle: ($diff_idle_mtime - $this->enterIdleTimeOut) ".($diff_idle_mtime >= $this->enterIdleTimeOut)." ".(($diff_idle_mtime - $this->enterIdleTimeOut) / 1000));
            
            if (($end_wait_mtime - $this->enterIdleInitTime) >= $this->enterIdleTimeOut) {
                //EnterIdling    
                $this->enterIdleStatus = true;
                $this->enterIdleInitTime = $end_wait_mtime;
                                
                foreach ($this->enterIdleStreams as $enterIdleStream) {
                    $key = (int) $enterIdleStream;

                    if (isset($this->enterIdleListeners[$key])) {
                        call_user_func($this->enterIdleListeners[$key], $enterIdleStream, $this);
                    }
                }
            }
            
            if (($end_wait_mtime - $init_wait_mtime) < $timeout){
                //SignalInterrupted
                foreach ($this->signalInterruptStreams as $signalInterruptStream) {
                    $key = (int) $signalInterruptStream;

                    if (isset($this->signalInterruptListeners[$key])) {
                        call_user_func($this->signalInterruptListeners[$key], $signalInterruptStream, $this);
                    }
                }
            } 
            
            
            // if a system call has been interrupted, or timeout,
            // we cannot rely on it's outcome
            return;
        }
        
        $this->enterIdleInitTime = $end_wait_mtime;
        //Check for WakeUp
        if($this->enterIdleStatus){
            $this->enterIdleStatus = false;
            
            foreach ($this->onWakeStreams as $onWakeStream) {
                $key = (int) $onWakeStream;

                if (isset($this->onWakeListeners[$key])) {
                    call_user_func($this->onWakeListeners[$key], $onWakeStream, $this);
                }
            }
        }
        
        foreach ($read as $stream) {
            $key = (int) $stream;

            if (isset($this->readListeners[$key])) {
                call_user_func($this->readListeners[$key], $stream, $this);
            }
        }

        foreach ($write as $stream) {
            $key = (int) $stream;

            if (isset($this->writeListeners[$key])) {
                call_user_func($this->writeListeners[$key], $stream, $this);
            }
        }
    }

    /**
     * Emulate a stream_select() implementation that does not break when passed
     * empty stream arrays.
     *
     * @param array        &$read   An array of read streams to select upon.
     * @param array        &$write  An array of write streams to select upon.
     * @param integer|null $timeout Activity timeout in microseconds, or null to wait forever.
     *
     * @return integer|false The total number of streams that are ready for read/write.
     * Can return false if stream_select() is interrupted by a signal.
     */
    protected function streamSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            $except = null;

            // suppress warnings that occur, when stream_select is interrupted by a signal
            return @stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);
        }

        $timeout && usleep($timeout);

        return 0;
    }
    
    function _writeLog($info_){
        if(defined('_SYSTEMDAEMON')){
            echo date("[H:i:s]: ")."{$info_}\n";
        }
        
        /*
        if(defined('_SYSTEMDAEMON')){
            System_Daemon::log(System_Daemon::LOG_INFO, $info_);
        }
        else {
            echo date("[H:i:s]: ")."{$info_}\n";
        }
         * 
         */
    }
}
