<?php

namespace React\EventLoop;

class Factory
{
    public static function create()
    {
        // @codeCoverageIgnoreStart
        if (class_exists('libev\EventLoop', false)) {
            return new LibEvLoop;
        } elseif (class_exists('EventBase', false)) {
            return new ExtEventLoop;
        } elseif (function_exists('event_base_new') && version_compare(PHP_VERSION, '7.0.0', '<')) {
            return new LibEventLoop();
        }

        return new StreamSelectLoop();
        // @codeCoverageIgnoreEnd
    }
}
